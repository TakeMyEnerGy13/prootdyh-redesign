<?php
ini_set('display_errors', '0');
require __DIR__ . '/auth.php';
require __DIR__ . '/actions.php';
require __DIR__ . '/../lib/promos.php';
require __DIR__ . '/../lib/render.php';

admin_start_session();

$attemptsFile = __DIR__ . '/../data/login_attempts.json';
$dataFile     = __DIR__ . '/../data/akcii.json';
$backupDir    = __DIR__ . '/../data/backups';
$error        = '';

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
      <main class="wrap adm-wrap">
        <h1>Управление акциями</h1>
        <?php if ($error !== ''): ?>
          <p class="adm-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <form method="post" class="adm-form">
          <input type="hidden" name="form" value="login">
          <label for="pw">Пароль
            <input id="pw" type="password" name="password" autofocus required></label>
          <button class="btn" type="submit">Войти</button>
        </form>
      </main>
    </body>
    </html>
    <?php
    exit;
}

$data = promos_load($dataFile, $backupDir);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['form'] ?? '', ['toggle', 'move'], true)) {
    if (!admin_csrf_ok($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('Форма устарела. Обновите страницу и повторите.');
    }
    $id = (string)($_POST['id'] ?? '');
    if ($_POST['form'] === 'toggle') {
        $data['promos'] = admin_toggle($data['promos'], $id);
    } else {
        $data['promos'] = admin_move($data['promos'], $id, (int)($_POST['delta'] ?? 1) === -1 ? -1 : 1);
    }
    promos_write($dataFile, $data, $backupDir);
    header('Location: index.php?saved=1');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Акции — управление</title>
  <link rel="stylesheet" href="../style.css?v=20260903">
</head>
<body>
<main class="wrap adm-wrap adm-wide">
  <div class="adm-top">
    <h1>Акции</h1>
    <p class="adm-links">
      <a class="btn" href="?action=new">Добавить акцию</a>
      <a href="/akcii.php" target="_blank" rel="noopener">Открыть страницу акций</a>
      <a href="?action=logout">Выйти</a>
    </p>
  </div>

  <?php if (isset($_GET['saved'])): ?>
    <p class="adm-saved">Изменения сохранены.</p>
  <?php endif; ?>

  <?php if (!$data['promos']): ?>
    <p>Пока ни одной акции. Нажмите «Добавить акцию».</p>
  <?php endif; ?>

  <?php foreach ($data['promos'] as $p): $t = admin_csrf_token(); ?>
    <article class="adm-row<?= ($p['enabled'] ?? false) ? '' : ' is-off' ?>">
      <span class="adm-dot <?= promos_e($p['theme']) ?>"></span>
      <b><?= promos_e($p['title']) ?></b>
      <span class="adm-badge"><?= promos_e($p['badge']) ?></span>
      <span class="adm-state"><?= ($p['enabled'] ?? false) ? 'показывается' : 'скрыта' ?></span>
      <form method="post"><input type="hidden" name="form" value="move">
        <input type="hidden" name="csrf" value="<?= $t ?>">
        <input type="hidden" name="id" value="<?= promos_e($p['id']) ?>">
        <button name="delta" value="-1" title="Выше">Вверх</button>
        <button name="delta" value="1" title="Ниже">Вниз</button></form>
      <form method="post"><input type="hidden" name="form" value="toggle">
        <input type="hidden" name="csrf" value="<?= $t ?>">
        <input type="hidden" name="id" value="<?= promos_e($p['id']) ?>">
        <button><?= ($p['enabled'] ?? false) ? 'Скрыть' : 'Показать' ?></button></form>
      <a href="?action=edit&amp;id=<?= promos_e($p['id']) ?>">Изменить</a>
      <a href="?action=delete&amp;id=<?= promos_e($p['id']) ?>">Удалить</a>
    </article>
  <?php endforeach; ?>
</main>
</body>
</html>
