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

function attachment_can_preview(string $mime): bool
{
    return attachment_is_image($mime) || $mime === 'application/pdf' || $mime === 'text/plain';
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

function attachment_storage_root(): string
{
    return dirname(__DIR__) . '/storage';
}

function attachment_storage_dir(int $recordId): string
{
    return attachment_storage_root() . '/attachments/' . $recordId;
}

function attachment_column_exists(string $column): bool
{
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    try {
        db()->query('SELECT ' . $column . ' FROM service_attachments LIMIT 0');
        $cache[$column] = true;
    } catch (Throwable $e) {
        $cache[$column] = false;
    }

    return $cache[$column];
}

function attachment_safe_original_name(string $name): string
{
    $name = trim($name) !== '' ? trim($name) : 'dosya';
    $name = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', $name) ?: 'dosya';
    return mb_substr($name, 0, 180);
}

function attachment_make_relative_path(int $recordId, string $originalName): string
{
    $safeName = attachment_safe_original_name($originalName);
    $ext = pathinfo($safeName, PATHINFO_EXTENSION);
    $ext = preg_replace('/[^A-Za-z0-9]+/', '', (string)$ext);
    $suffix = $ext !== '' ? '.' . strtolower($ext) : '';
    return 'attachments/' . $recordId . '/' . bin2hex(random_bytes(16)) . $suffix;
}

function attachment_absolute_path(?string $relativePath): ?string
{
    $relativePath = str_replace('\\', '/', trim((string)$relativePath));
    $relativePath = ltrim($relativePath, '/');
    if ($relativePath === '' || str_contains($relativePath, '..') || !str_starts_with($relativePath, 'attachments/')) {
        return null;
    }

    return attachment_storage_root() . '/' . $relativePath;
}

function attachment_ensure_storage(int $recordId): void
{
    $dir = attachment_storage_dir($recordId);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Dosya klasoru olusturulamadi.');
    }

    $deny = attachment_storage_root() . '/attachments/.htaccess';
    if (!is_file($deny)) {
        @mkdir(dirname($deny), 0775, true);
        @file_put_contents($deny, "Require all denied\n");
    }
}

function attachment_save(int $recordId, array $file, string $category, ?int $userId): int
{
    if (!attachment_category_valid($category)) {
        $category = 'diger';
    }

    $tmp = (string)$file['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    $original = attachment_safe_original_name((string)($file['name'] ?? 'dosya'));
    $size = (int)($file['size'] ?? filesize($tmp));

    if (!attachment_column_exists('file_path')) {
        throw new RuntimeException('Belge depolama migration gerektiriyor.');
    }

    attachment_ensure_storage($recordId);
    $relativePath = attachment_make_relative_path($recordId, $original);
    $absolutePath = attachment_absolute_path($relativePath);
    if ($absolutePath === null) {
        throw new RuntimeException('Gecersiz dosya yolu.');
    }
    if (!move_uploaded_file($tmp, $absolutePath)) {
        throw new RuntimeException('Dosya depoya tasinamadi.');
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO service_attachments
             (record_id, category, original_name, mime_type, file_size, file_path, uploaded_by)
             VALUES (:record_id, :category, :original_name, :mime_type, :file_size, :file_path, :uploaded_by)'
        );
        $stmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        $stmt->bindValue(':category', $category);
        $stmt->bindValue(':original_name', $original);
        $stmt->bindValue(':mime_type', $mime);
        $stmt->bindValue(':file_size', $size, PDO::PARAM_INT);
        $stmt->bindValue(':file_path', $relativePath);
        if ($userId === null) {
            $stmt->bindValue(':uploaded_by', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':uploaded_by', $userId, PDO::PARAM_INT);
        }
        $stmt->execute();
    } catch (Throwable $e) {
        @unlink($absolutePath);
        throw $e;
    }

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

function attachment_data_for_row(array $row): string
{
    $path = attachment_absolute_path($row['file_path'] ?? null);
    if ($path !== null && is_file($path)) {
        $data = file_get_contents($path);
        if ($data !== false) {
            return $data;
        }
    }

    if (array_key_exists('file_data', $row)) {
        $data = $row['file_data'];
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }
        return (string)$data;
    }

    throw new RuntimeException('Dosya depoda bulunamadi.');
}

function attachment_send_response_headers(array $row, int $length, bool $forceDownload, ?int $modifiedAt = null): void
{
    while (ob_get_level() > 0) { ob_end_clean(); }

    $disposition = $forceDownload ? 'attachment' : 'inline';
    $safeName = preg_replace('/[\r\n"]+/', '_', (string)$row['original_name']);
    $etag = $modifiedAt !== null ? '"' . sha1((string)$row['original_name'] . ':' . $length . ':' . $modifiedAt) . '"' : null;

    if ($etag !== null && trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        header('Cache-Control: private, max-age=86400');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int)$modifiedAt) . ' GMT');
        header('ETag: ' . $etag);
        http_response_code(304);
        exit;
    }

    header('Content-Type: ' . ($row['mime_type'] ?: 'application/octet-stream'));
    header('Content-Length: ' . (string)$length);
    header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');

    if ($modifiedAt !== null) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modifiedAt) . ' GMT');
        header('ETag: ' . $etag);
    }
}

function attachment_stream(int $attachmentId, bool $forceDownload = true): void
{
    $fields = 'original_name, mime_type, file_size';
    if (attachment_column_exists('file_path')) {
        $fields .= ', file_path';
    }
    if (attachment_column_exists('file_data')) {
        $fields .= ', file_data';
    }

    $stmt = db()->prepare("SELECT $fields FROM service_attachments WHERE id = ?");
    $stmt->execute([$attachmentId]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        exit('Dosya bulunamadi.');
    }

    $path = attachment_absolute_path($row['file_path'] ?? null);
    if ($path !== null && is_file($path)) {
        $size = filesize($path);
        if ($size === false) {
            http_response_code(404);
            exit('Dosya depoda bulunamadi.');
        }

        attachment_send_response_headers($row, $size, $forceDownload, filemtime($path) ?: null);
        readfile($path);
        exit;
    }

    try {
        $data = attachment_data_for_row($row);
    } catch (Throwable $e) {
        http_response_code(404);
        exit('Dosya depoda bulunamadi.');
    }

    attachment_send_response_headers($row, strlen($data), $forceDownload);
    echo $data;
    exit;
}

function attachment_delete(int $attachmentId): bool
{
    $path = null;
    if (attachment_column_exists('file_path')) {
        $stmt = db()->prepare('SELECT file_path FROM service_attachments WHERE id = ?');
        $stmt->execute([$attachmentId]);
        $path = attachment_absolute_path($stmt->fetchColumn() ?: null);
    }

    $stmt = db()->prepare('DELETE FROM service_attachments WHERE id = ?');
    $stmt->execute([$attachmentId]);
    $deleted = $stmt->rowCount() > 0;
    if ($deleted && $path !== null && is_file($path)) {
        @unlink($path);
    }

    return $deleted;
}
