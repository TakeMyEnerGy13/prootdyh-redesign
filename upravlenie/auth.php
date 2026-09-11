<?php

function admin_config(): array {
    $file = __DIR__ . '/../data/config.php';
    if (!is_file($file)) {
        return ['password_hash' => '', 'session_name' => 'proadm'];
    }
    $cfg = require $file;
    return [
        'password_hash' => (string)($cfg['password_hash'] ?? ''),
        'session_name'  => (string)($cfg['session_name'] ?? 'proadm'),
    ];
}

function admin_client_ip(): string {
    // X-Forwarded-For reaches PHP exactly as the client sent it (checked on the hosting),
    // so trusting it would let anyone bypass the login limit; REMOTE_ADDR is the real IP.
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function admin_attempts_read(string $file): array {
    $raw = is_file($file) ? file_get_contents($file) : '';
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

function admin_login_blocked(string $file, string $ip, int $limit = 5, int $window = 900): bool {
    $data = admin_attempts_read($file);
    $rec = $data[$ip] ?? null;
    if (!is_array($rec)) {
        return false;
    }
    if (time() - (int)($rec['last'] ?? 0) > $window) {
        return false;
    }
    return (int)($rec['count'] ?? 0) >= $limit;
}

function admin_note_failure(string $file, string $ip, int $window = 900): void {
    $data = admin_attempts_read($file);
    $rec = $data[$ip] ?? ['count' => 0, 'last' => 0];
    if (time() - (int)$rec['last'] > $window) {
        $rec['count'] = 0;
    }
    $rec['count'] = (int)$rec['count'] + 1;
    $rec['last'] = time();
    $data[$ip] = $rec;
    // Чужие протухшие записи заодно выбрасываем, чтобы файл не пух.
    foreach ($data as $k => $v) {
        if (time() - (int)($v['last'] ?? 0) > $window * 4) {
            unset($data[$k]);
        }
    }
    file_put_contents($file, json_encode($data), LOCK_EX);
}

function admin_reset_failures(string $file, string $ip): void {
    $data = admin_attempts_read($file);
    unset($data[$ip]);
    file_put_contents($file, json_encode($data), LOCK_EX);
}

function admin_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $cfg = admin_config();
    session_name($cfg['session_name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function admin_is_logged_in(): bool {
    return ($_SESSION['auth'] ?? false) === true;
}

function admin_csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function admin_csrf_ok(?string $token): bool {
    return is_string($token)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}
