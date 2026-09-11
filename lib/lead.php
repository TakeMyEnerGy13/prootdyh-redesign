<?php

const LEAD_TO = 'pro-otd@mail.ru';
// Same address the hosting puts into the envelope (sendmail_path -f), so SPF and From align.
const LEAD_FROM = 'info@prootdyhspb.ru';
const LEAD_PHONE = '8 (921) 883-24-24';

function lead_clean(string $v): string {
    if (!mb_check_encoding($v, 'UTF-8')) {
        return '';
    }
    // All fields are single-line: line breaks and control chars collapse into a space.
    return trim((string)preg_replace('/[\p{Cc}\s]+/u', ' ', $v));
}

function lead_from_post(array $post): array {
    $lead = [];
    foreach (['name', 'phone', 'wish'] as $field) {
        $value = $post[$field] ?? '';
        $lead[$field] = is_string($value) ? lead_clean($value) : '';
    }
    return $lead;
}

function lead_validate(array $lead): array {
    $errors = [];

    $nameLen = mb_strlen($lead['name'], 'UTF-8');
    if ($nameLen === 0) {
        $errors[] = 'Укажите, как к вам обращаться';
    } elseif ($nameLen > 80) {
        $errors[] = 'Имя — не длиннее 80 символов';
    }

    $digits = strlen((string)preg_replace('/\D+/', '', $lead['phone']));
    if ($digits < 10 || $digits > 15 || mb_strlen($lead['phone'], 'UTF-8') > 30) {
        $errors[] = 'Проверьте номер телефона';
    }

    if (mb_strlen($lead['wish'], 'UTF-8') > 500) {
        $errors[] = 'Пожелания — не длиннее 500 символов';
    }

    return $errors;
}

function lead_mime_word(string $v): string {
    return '=?UTF-8?B?' . base64_encode($v) . '?=';
}

function lead_message(array $lead, string $when): array {
    $wish = $lead['wish'] !== '' ? $lead['wish'] : '—';
    $body = "Новая заявка с формы «Бесплатный подбор» на prootdyhspb.ru\n\n"
          . "Имя: {$lead['name']}\n"
          . "Телефон: {$lead['phone']}\n"
          . "Куда хотите поехать: {$wish}\n\n"
          . "Отправлено: {$when}\n";

    return [
        'subject' => lead_mime_word('Заявка с сайта: ' . $lead['name']),
        'body'    => chunk_split(base64_encode($body)),
        'headers' => [
            'From'                      => lead_mime_word('Сайт ПроОтдых') . ' <' . LEAD_FROM . '>',
            'MIME-Version'              => '1.0',
            'Content-Type'              => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'base64',
        ],
    ];
}

function lead_rate_ok(string $file, string $ip, int $limit = 5, int $window = 600): bool {
    $data = json_decode((string)(is_file($file) ? file_get_contents($file) : ''), true);
    $data = is_array($data) ? $data : [];
    $now = time();

    foreach ($data as $key => $stamps) {
        $fresh = array_values(array_filter(
            is_array($stamps) ? $stamps : [],
            static fn($t): bool => $now - (int)$t < $window
        ));
        if ($fresh === []) {
            unset($data[$key]);
        } else {
            $data[$key] = $fresh;
        }
    }

    if (count($data[$ip] ?? []) >= $limit) {
        return false;
    }
    $data[$ip][] = $now;
    file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}
