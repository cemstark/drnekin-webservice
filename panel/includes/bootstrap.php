<?php
declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    $configFile = dirname(__DIR__) . '/config.example.php';
}

$config = require $configFile;
date_default_timezone_set($config['timezone'] ?? 'Europe/Istanbul');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['session_name'] ?? 'drn_panel_session');
    session_start();
}

function panel_config(?string $key = null, mixed $default = null): mixed
{
    global $config;
    if ($key === null) {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }

    return $value;
}

function panel_url(string $path = ''): string
{
    $configured = panel_config('base_path', null);
    if ($configured === null) {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = rtrim(dirname($script), '/');
        $base = str_ends_with($dir, '/api') ? dirname($dir) : $dir;
        $base = $base === '/' || $base === '.' ? '' : $base;
    } else {
        $base = rtrim((string)$configured, '/');
    }
    return $base . '/' . ltrim($path, '/');
}

function panel_asset_url(string $path): string
{
    $url = panel_url($path);
    $file = dirname(__DIR__) . '/' . ltrim($path, '/');
    if (is_file($file)) {
        $separator = str_contains($url, '?') ? '&' : '?';
        $url .= $separator . 'v=' . filemtime($file);
    }

    return $url;
}

function panel_font_url(): string
{
    return 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;600;700&display=swap';
}

function render_panel_head_assets(): void
{
    $fontUrl = panel_font_url();
    ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" as="style" href="<?= e($fontUrl) ?>">
  <link rel="stylesheet" href="<?= e($fontUrl) ?>" media="print" onload="this.media='all'">
  <noscript><link rel="stylesheet" href="<?= e($fontUrl) ?>"></noscript>
  <link rel="stylesheet" href="<?= e(panel_asset_url('assets/panel.css')) ?>">
    <?php
}

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect_to(string $path): never
{
    header('Location: ' . panel_url($path));
    exit;
}
