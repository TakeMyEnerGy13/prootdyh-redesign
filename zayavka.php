<?php
require __DIR__ . '/lib/lead.php';

function zayavka_reply(bool $json, int $code, bool $ok, string $message): never {
    http_response_code($code);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Fallback for browsers without JS: a plain page with a way back to the form.
    header('Content-Type: text/html; charset=utf-8');
    $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex"><title>Заявка — ПроОтдых</title>'
       . '<link rel="stylesheet" href="style.css?v=20260911"></head>'
       . '<body><main class="wrap" style="padding-block:80px"><h2>' . $m . '</h2>'
       . '<p><a class="btn" href="/#contact">Вернуться на сайт</a></p></main></body></html>';
    exit;
}

$json = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /#contact', true, 303);
    exit;
}

$thanks = 'Спасибо! Заявка отправлена — скоро с вами свяжемся.';

// Honeypot: humans never see this field, bots fill it. Pretend success.
if (($_POST['leave_empty'] ?? '') !== '') {
    zayavka_reply($json, 200, true, $thanks);
}

$lead = lead_from_post($_POST);
$errors = lead_validate($lead);
if ($errors !== []) {
    zayavka_reply($json, 422, false, implode('. ', $errors));
}

if (!lead_rate_ok(__DIR__ . '/data/lead_attempts.json', (string)($_SERVER['REMOTE_ADDR'] ?? ''))) {
    zayavka_reply($json, 429, false, 'Слишком много заявок подряд. Позвоните нам: ' . LEAD_PHONE);
}

$when = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('d.m.Y H:i');
$msg = lead_message($lead, $when);
if (!mail(LEAD_TO, $msg['subject'], $msg['body'], $msg['headers'])) {
    zayavka_reply($json, 500, false, 'Не удалось отправить заявку. Позвоните нам: ' . LEAD_PHONE);
}

zayavka_reply($json, 200, true, $thanks);
