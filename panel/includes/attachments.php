<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024; // 10 MB

const ATTACHMENT_MIME_WHITELIST = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/heic',
    'image/heif',
    'image/gif',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain',
];

function attachment_categories(): array
{
    return [
        'avukat'   => 'Avukat',
        'ruhsat'   => 'Ruhsat',
        'kaza'     => 'Kaza',
        'police'   => 'Police',
        'fotograf' => 'Fotograf',
        'diger'    => 'Diger',
    ];
}

function attachment_category_label(string $key): string
{
    $map = attachment_categories();
    return $map[$key] ?? 'Diger';
}

function attachment_category_valid(string $key): bool
{
    return array_key_exists($key, attachment_categories());
}

function attachment_format_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return number_format($bytes / (1024 * 1024), 2) . ' MB';
}

function attachment_is_image(string $mime): bool
{
    return strncmp($mime, 'image/', 6) === 0;
}

function attachment_validate_upload(array $file): array
{
    $errors = [];
    $errCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errCode === UPLOAD_ERR_NO_FILE) {
        return ['Dosya secilmedi.'];
    }
    if ($errCode !== UPLOAD_ERR_OK) {
        return ['Dosya yuklenemedi (kod ' . $errCode . ').'];
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        $errors[] = 'Bos dosya.';
    }
    if ($size > ATTACHMENT_MAX_BYTES) {
        $errors[] = 'Dosya boyutu 10 MB ustunde olamaz.';
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $errors[] = 'Gecersiz upload kaynagi.';
        return $errors;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    if (!in_array($mime, ATTACHMENT_MIME_WHITELIST, true)) {
        $errors[] = 'Dosya turu desteklenmiyor (' . $mime . ').';
    }
    return $errors;
}

function attachment_save(int $recordId, array $file, string $category, ?int $userId): int
{
    if (!attachment_category_valid($category)) {
        $category = 'diger';
    }
    $tmp = (string)$file['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    $data = file_get_contents($tmp);
    if ($data === false) {
        throw new RuntimeException('Dosya okunamadi.');
    }
    $original = (string)($file['name'] ?? 'dosya');
    $original = mb_substr($original, 0, 250);

    $stmt = db()->prepare(
        'INSERT INTO service_attachments
         (record_id, category, original_name, mime_type, file_size, file_data, uploaded_by)
         VALUES (:record_id, :category, :original_name, :mime_type, :file_size, :file_data, :uploaded_by)'
    );
    $stmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
    $stmt->bindValue(':category', $category);
    $stmt->bindValue(':original_name', $original);
    $stmt->bindValue(':mime_type', $mime);
    $stmt->bindValue(':file_size', strlen($data), PDO::PARAM_INT);
    $stmt->bindValue(':file_data', $data, PDO::PARAM_LOB);
    if ($userId === null) {
        $stmt->bindValue(':uploaded_by', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':uploaded_by', $userId, PDO::PARAM_INT);
    }
    $stmt->execute();
    return (int)db()->lastInsertId();
}

function attachment_fetch_for_record(int $recordId, ?string $category = null): array
{
    $sql = 'SELECT a.id, a.record_id, a.category, a.original_name, a.mime_type, a.file_size, a.uploaded_at,
                   u.username AS uploaded_by_username, u.full_name AS uploaded_by_name
            FROM service_attachments a
            LEFT JOIN users u ON u.id = a.uploaded_by
            WHERE a.record_id = :rid';
    $params = [':rid' => $recordId];
    if ($category !== null && $category !== '' && attachment_category_valid($category)) {
        $sql .= ' AND a.category = :cat';
        $params[':cat'] = $category;
    }
    $sql .= ' ORDER BY a.uploaded_at DESC, a.id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function attachment_fetch_meta(int $attachmentId): ?array
{
    $stmt = db()->prepare('SELECT id, record_id, category, original_name, mime_type, file_size FROM service_attachments WHERE id = ?');
    $stmt->execute([$attachmentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function attachment_stream(int $attachmentId, bool $forceDownload = true): void
{
    $stmt = db()->prepare('SELECT original_name, mime_type, file_size, file_data FROM service_attachments WHERE id = ?');
    $stmt->execute([$attachmentId]);
    $stmt->bindColumn(1, $name);
    $stmt->bindColumn(2, $mime);
    $stmt->bindColumn(3, $size, PDO::PARAM_INT);
    $stmt->bindColumn(4, $data, PDO::PARAM_LOB);
    if (!$stmt->fetch(PDO::FETCH_BOUND)) {
        http_response_code(404);
        exit('Dosya bulunamadi.');
    }
    if (is_resource($data)) {
        $data = stream_get_contents($data);
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
    header('Content-Length: ' . strlen((string)$data));
    $disposition = $forceDownload ? 'attachment' : 'inline';
    $safeName = preg_replace('/[\r\n"]+/', '_', (string)$name);
    header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $data;
    exit;
}

function attachment_delete(int $attachmentId): bool
{
    $stmt = db()->prepare('DELETE FROM service_attachments WHERE id = ?');
    $stmt->execute([$attachmentId]);
    return $stmt->rowCount() > 0;
}
