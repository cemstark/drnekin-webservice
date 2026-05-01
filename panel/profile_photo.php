<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

function profile_photo_cache_dir(): string
{
    $dir = __DIR__ . '/storage/profile_photos/cache';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return __DIR__ . '/storage/profile_photos';
    }

    $deny = $dir . '/.htaccess';
    if (!is_file($deny)) {
        @file_put_contents($deny, "Require all denied\n");
    }

    return $dir;
}

function profile_photo_make_avatar(string $sourcePath, string $file, int $maxSize = 128): ?string
{
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($sourcePath);
    $create = match ($mime) {
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        default => null,
    };
    if ($create === null || !function_exists($create)) {
        if (!class_exists('Imagick')) {
            return null;
        }

        try {
            $image = new Imagick($sourcePath);
            if ($image->getNumberImages() > 1) {
                $image->setIteratorIndex(0);
            }
            $image->cropThumbnailImage($maxSize, $maxSize);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(78);
            $ok = $image->writeImage($cacheFile);
            $image->clear();
            $image->destroy();
            return $ok ? $cacheFile : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    $stamp = (string)(filemtime($sourcePath) ?: time());
    $cacheFile = profile_photo_cache_dir() . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $file) . '-' . $maxSize . '-' . $stamp . '.jpg';
    if (is_file($cacheFile)) {
        return $cacheFile;
    }

    $source = @$create($sourcePath);
    if (!$source) {
        return null;
    }

    $width = imagesx($source);
    $height = imagesy($source);
    if ($width <= 0 || $height <= 0) {
        imagedestroy($source);
        return null;
    }

    $side = min($width, $height);
    $srcX = (int)(($width - $side) / 2);
    $srcY = (int)(($height - $side) / 2);
    $target = imagecreatetruecolor($maxSize, $maxSize);
    if (!$target) {
        imagedestroy($source);
        return null;
    }

    imagecopyresampled($target, $source, 0, 0, $srcX, $srcY, $maxSize, $maxSize, $side, $side);
    $ok = imagejpeg($target, $cacheFile, 78);
    imagedestroy($source);
    imagedestroy($target);

    return $ok ? $cacheFile : null;
}

$file = current_user_profile_photo_file();
if ($file === null) {
    http_response_code(404);
    exit;
}

$path = __DIR__ . '/storage/profile_photos/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$servePath = profile_photo_make_avatar($path, $file) ?? $path;
$mtime = filemtime($servePath) ?: time();
$size = filesize($servePath) ?: 0;
$etag = '"' . sha1($file . ':' . $size . ':' . $mtime) . '"';

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    header('Cache-Control: private, max-age=86400, stale-while-revalidate=604800');
    header('ETag: ' . $etag);
    http_response_code(304);
    exit;
}

$mime = $servePath === $path ? ((new finfo(FILEINFO_MIME_TYPE))->file($servePath) ?: 'image/jpeg') : 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)$size);
header('Cache-Control: private, max-age=86400, stale-while-revalidate=604800');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('ETag: ' . $etag);
readfile($servePath);
