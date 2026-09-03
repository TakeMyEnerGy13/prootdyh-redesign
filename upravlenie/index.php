<?php
ini_set('display_errors', '0');
require __DIR__ . '/auth.php';
require __DIR__ . '/../lib/promos.php';

admin_start_session();

$attemptsFile = __DIR__ . '/../data/login_attempts.json';
$error = '';

if (($_GET['action'] ?? '') === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'login') {
    $ip = admin_client_ip();
    if (admin_login_blocked($attemptsFile, $ip)) {
        $error = 'Слишком много попыток. Попробуйте через 15 минут.';
    } elseif (password_verify((string)($_POST['password'] ?? ''), admin_config()['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        admin_reset_failures($attemptsFile, $ip);
        header('Location: index.php');
        exit;
    } else {
        admin_note_failure($attemptsFile, $ip);
        $error = 'Неверный пароль.';
    }
}

if (!admin_is_logged_in()) {
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Вход — управление акциями</title>
      <link rel="stylesheet" href="../style.css?v=20260903">
    </head>
    <body>
      <main class="wrap" style="max-width:420px;padding:80px 20px">
        <h1>Управление акциями</h1>
        <?php if ($error !== ''): ?>
          <p style="color:#c0184a"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="form" value="login">
          <label for="pw">Пароль</label>
          <input id="pw" type="password" name="password" autofocus required
                 style="display:block;width:100%;margin:8px 0 16px;padding:12px">
          <button class="btn" type="submit">Войти</button>
        </form>
      </main>
    </body>
    </html>
    <?php
    exit;
}

echo 'Вошли. Список акций появится в следующей задаче. ';
echo '<a href="?action=logout">Выйти</a>';
