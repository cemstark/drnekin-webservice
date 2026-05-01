<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $user = null;
    if ($user !== null) {
        return $user;
    }

    $stmt = db()->prepare('SELECT id, username, full_name, role FROM users WHERE id = ? AND active = 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    return $user;
}

function user_profile_key(string $username): string
{
    $key = trim($username);
    $key = function_exists('mb_strtolower') ? mb_strtolower($key, 'UTF-8') : strtolower($key);
    return strtr($key, [
        'ı' => 'i',
        'İ' => 'i',
        'ş' => 's',
        'Ş' => 's',
        'ğ' => 'g',
        'Ğ' => 'g',
        'ü' => 'u',
        'Ü' => 'u',
        'ö' => 'o',
        'Ö' => 'o',
        'ç' => 'c',
        'Ç' => 'c',
    ]);
}

function current_user_display_name(): string
{
    $user = current_user();
    if ($user === null) {
        return '';
    }

    return (string)($user['full_name'] ?: $user['username']);
}

function current_user_profile_photo_file(): ?string
{
    $user = current_user();
    if ($user === null) {
        return null;
    }

    $photos = [
        'ozlem' => 'ozlem.jpeg',
        'nursen' => 'nursen.jpeg',
        'emirhan' => 'emirhan.jpeg',
    ];

    return $photos[user_profile_key((string)$user['username'])] ?? null;
}

function current_user_profile_photo_url(): ?string
{
    $file = current_user_profile_photo_file();
    if ($file === null) {
        return null;
    }

    $user = current_user();
    $key = $user !== null ? user_profile_key((string)$user['username']) : '';
    return panel_url('profile_photo.php?u=' . rawurlencode($key));
}

function render_current_user_badge(): void
{
    $name = current_user_display_name();
    $photoUrl = current_user_profile_photo_url();
    if ($name === '') {
        return;
    }
    ?>
    <span class="user-badge">
      <?php if ($photoUrl !== null): ?>
        <img src="<?= e($photoUrl) ?>" alt="<?= e($name) ?>" class="user-avatar" width="42" height="42" style="width:42px;height:42px;max-width:42px;max-height:42px;border-radius:9999px;object-fit:cover;flex:0 0 auto;">
      <?php endif; ?>
      <span><?= e($name) ?></span>
    </span>
    <?php
}

function require_login(): void
{
    if (current_user() === null) {
        redirect_to('login.php');
    }
}

function login_user(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, (string)$user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

    $update = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    $update->execute([(int)$user['id']]);

    return true;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Oturum dogrulamasi basarisiz.');
    }
}
