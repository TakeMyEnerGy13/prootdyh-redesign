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
      <link rel="stylesheet" href="../style.css?v=20260903b">
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

$data       = promos_load($dataFile, $backupDir);
$action     = (string)($_GET['action'] ?? '');
$formErrors = [];
$formPromo  = promos_defaults();
$editId     = '';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'save') {
    if (!admin_csrf_ok($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('Форма устарела. Обновите страницу и повторите.');
    }
    $editId = (string)($_POST['edit_id'] ?? '');
    $promo  = admin_promo_from_post($_POST);
    $ids    = array_column($data['promos'], 'id');

    if ($editId !== '' && admin_find_index($data['promos'], $editId) !== null) {
        $promo['id'] = $editId;                       // адрес не меняем: на него могут вести ссылки
        $others = array_values(array_diff($ids, [$editId]));
    } else {
        $promo['id'] = promos_unique_id(promos_slug($promo['title']), $ids);
        $others = $ids;
    }

    $errors = promos_validate($promo, $others);
    if ($errors === []) {
        $i = admin_find_index($data['promos'], $promo['id']);
        if ($i === null) {
            $data['promos'][] = $promo;
        } else {
            $data['promos'][$i] = $promo;
        }
        promos_write($dataFile, $data, $backupDir);
        header('Location: index.php?saved=1');
        exit;
    }
    $formPromo  = $promo;
    $formErrors = $errors;
    $action     = 'form';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'delete') {
    if (!admin_csrf_ok($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('Форма устарела. Обновите страницу и повторите.');
    }
    $data['promos'] = admin_delete($data['promos'], (string)($_POST['id'] ?? ''));
    promos_write($dataFile, $data, $backupDir);
    header('Location: index.php?saved=1');
    exit;
}

$victim = null;
if ($action === 'delete') {
    $i = admin_find_index($data['promos'], (string)($_GET['id'] ?? ''));
    if ($i === null) {
        header('Location: index.php');
        exit;
    }
    $victim = $data['promos'][$i];
}

if ($action === 'edit') {
    $i = admin_find_index($data['promos'], (string)($_GET['id'] ?? ''));
    if ($i === null) {
        header('Location: index.php');
        exit;
    }
    $formPromo = $data['promos'][$i];
    $editId    = $formPromo['id'];
    $action    = 'form';
} elseif ($action === 'new') {
    $action = 'form';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $action === 'form' ? 'Правка акции' : ($action === 'delete' ? 'Удаление акции' : 'Акции') ?> — управление</title>
  <link rel="stylesheet" href="../style.css?v=20260903b">
</head>
<body>
<main class="wrap adm-wrap<?= in_array($action, ['form', 'delete'], true) ? '' : ' adm-wide' ?>">

<?php if ($action === 'delete'): ?>

  <h1>Удалить акцию?</h1>
  <p>Будет удалена акция «<b><?= promos_e($victim['title']) ?></b>»
     с бейджем «<?= promos_e($victim['badge']) ?>». Отменить это в интерфейсе нельзя.</p>
  <form method="post" class="adm-form">
    <input type="hidden" name="form" value="delete">
    <input type="hidden" name="csrf" value="<?= admin_csrf_token() ?>">
    <input type="hidden" name="id" value="<?= promos_e($victim['id']) ?>">
    <button class="btn" type="submit">Удалить</button>
    <a href="index.php">Отмена</a>
  </form>

<?php elseif ($action === 'form'): ?>

  <h1><?= $editId === '' ? 'Новая акция' : 'Правка акции' ?></h1>

  <?php if ($formErrors): ?>
    <ul class="adm-error">
      <?php foreach ($formErrors as $e): ?>
        <li><?= promos_e($e) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <form method="post" class="adm-form">
    <input type="hidden" name="form" value="save">
    <input type="hidden" name="csrf" value="<?= admin_csrf_token() ?>">
    <input type="hidden" name="edit_id" value="<?= promos_e($editId) ?>">

    <label>Бейдж <span>левый верхний угол плитки, например −35%</span>
      <input type="text" name="badge" maxlength="20" required value="<?= promos_e($formPromo['badge']) ?>"></label>

    <label>Заголовок плитки
      <input type="text" name="title" maxlength="60" required value="<?= promos_e($formPromo['title']) ?>"></label>

    <label>Подпись <span>необязательно</span>
      <input type="text" name="note" maxlength="90" value="<?= promos_e($formPromo['note']) ?>"></label>

    <label>Цвет плитки
      <select name="theme">
        <?php foreach (promos_themes() as $t): ?>
          <option value="<?= $t ?>" <?= $formPromo['theme'] === $t ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select></label>
    <div class="adm-swatches">
      <?php foreach (promos_themes() as $t): ?><span class="adm-dot <?= $t ?>" title="<?= $t ?>"></span><?php endforeach; ?>
    </div>

    <label class="adm-check"><input type="checkbox" name="big" <?= $formPromo['big'] ? 'checked' : '' ?>>
      Широкая плитка (занимает две колонки)</label>

    <label class="adm-check"><input type="checkbox" name="enabled" <?= $formPromo['enabled'] ? 'checked' : '' ?>>
      Показывать на сайте</label>

    <label>Заголовок окна <span>виден, когда посетитель нажал на плитку</span>
      <input type="text" name="modal_title" maxlength="90" required value="<?= promos_e($formPromo['modal_title']) ?>"></label>

    <label>Подзаголовок окна <span>необязательно</span>
      <input type="text" name="modal_sub" maxlength="160" value="<?= promos_e($formPromo['modal_sub']) ?>"></label>

    <label>Текст условий
      <textarea name="modal_text" rows="6" maxlength="1200" required><?= promos_e($formPromo['modal_text']) ?></textarea></label>

    <button class="btn" type="submit">Сохранить</button>
    <a href="index.php">Отмена</a>
  </form>

<?php else: ?>

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
      <a class="adm-del" href="?action=delete&amp;id=<?= promos_e($p['id']) ?>">Удалить</a>
    </article>
  <?php endforeach; ?>

<?php endif; ?>

</main>
</body>
</html>
