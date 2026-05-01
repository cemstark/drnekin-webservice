<?php
declare(strict_types=1);

/**
 * Bir aracin tum eklerini ZIP olarak indirir.
 * Kullanim: download_all.php?id=<record_id>[&cat=<category>]
 *
 * Kategoriye gore alt klasor; ayni isimde birden fazla varsa "(2)" gibi suffix.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attachments.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Gecersiz id.');
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('Sunucuda ZipArchive uzantisi yuklu degil.');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, plate, customer_name FROM service_records WHERE id = ?');
$stmt->execute([$id]);
$record = $stmt->fetch();
if (!$record) {
    http_response_code(404);
    exit('Kayit bulunamadi.');
}

$categoryFilter = trim((string)($_GET['cat'] ?? ''));
if ($categoryFilter !== '' && !attachment_category_valid($categoryFilter)) {
    $categoryFilter = '';
}

$fields = 'id, category, original_name, mime_type, file_size';
if (attachment_column_exists('file_path')) {
    $fields .= ', file_path';
}
if (attachment_column_exists('file_data')) {
    $fields .= ', file_data';
}

$sql = "SELECT $fields FROM service_attachments WHERE record_id = :rid";
$params = [':rid' => $id];
if ($categoryFilter !== '') {
    $sql .= ' AND category = :cat';
    $params[':cat'] = $categoryFilter;
}
$sql .= ' ORDER BY category, uploaded_at DESC, id DESC';
$q = $pdo->prepare($sql);
$q->execute($params);

$tmp = tempnam(sys_get_temp_dir(), 'drn_zip_');
if ($tmp === false) {
    http_response_code(500);
    exit('Gecici dosya olusturulamadi.');
}

$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    @unlink($tmp);
    http_response_code(500);
    exit('ZIP arsivi acilamadi.');
}

$used = [];
$count = 0;

while ($att = $q->fetch()) {
    try {
        $data = attachment_data_for_row($att);
    } catch (Throwable $e) {
        continue;
    }
    $count++;
    $name = (string)$att['original_name'];
    $name = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', $name) ?: 'dosya';
    $folder = attachment_category_label($att['category']);
    $entry = $folder . '/' . $name;

    // Cakisma onleme
    $base = $entry;
    $i = 2;
    while (isset($used[$entry])) {
        $dot = strrpos($base, '.');
        if ($dot !== false && $dot > strrpos($base, '/')) {
            $entry = substr($base, 0, $dot) . " ($i)" . substr($base, $dot);
        } else {
            $entry = $base . " ($i)";
        }
        $i++;
    }
    $used[$entry] = true;

    $zip->addFromString($entry, (string)$data);
}

if ($count === 0) {
    $zip->close();
    @unlink($tmp);
    http_response_code(404);
    exit('Bu kayit icin dosya bulunmuyor.');
}

$zip->close();

$plate = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$record['plate']) ?: 'arac';
$zipName = sprintf('%s_belgeler_%s.zip', $plate, date('Ymd_His'));

while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/zip');
header('Content-Length: ' . (string)filesize($tmp));
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($tmp);
@unlink($tmp);
exit;
