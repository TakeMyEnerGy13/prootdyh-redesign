<?php

function promos_themes(): array {
    return ['t-red', 't-blue', 't-magenta', 't-green', 't-orange', 't-cyan', 't-grad', 't-grad2'];
}

function promos_defaults(): array {
    return [
        'id' => '', 'enabled' => true, 'badge' => '', 'title' => '', 'note' => '',
        'theme' => 't-blue', 'big' => false,
        'modal_title' => '', 'modal_sub' => '', 'modal_text' => '',
    ];
}

/** Длины полей из спеки: [минимум, максимум, человеческое название]. */
function promos_field_rules(): array {
    return [
        'badge'       => [1, 20,   'Бейдж'],
        'title'       => [1, 60,   'Заголовок плитки'],
        'note'        => [0, 90,   'Подпись'],
        'modal_title' => [1, 90,   'Заголовок окна'],
        'modal_sub'   => [0, 160,  'Подзаголовок окна'],
        'modal_text'  => [1, 1200, 'Текст условий'],
    ];
}

function promos_validate(array $promo, array $otherIds): array {
    $errors = [];

    foreach (promos_field_rules() as $field => [$min, $max, $label]) {
        $value = trim((string)($promo[$field] ?? ''));
        $len = mb_strlen($value, 'UTF-8');
        if ($len < $min) {
            $errors[] = "«{$label}»: поле обязательно";
        } elseif ($len > $max) {
            $errors[] = "«{$label}»: не длиннее {$max} символов, сейчас {$len}";
        }
    }

    if (!in_array($promo['theme'] ?? '', promos_themes(), true)) {
        $errors[] = 'Тема плитки: выберите вариант из списка';
    }

    $id = (string)($promo['id'] ?? '');
    if ($id === '' || !preg_match('/^[a-z0-9-]{1,40}$/', $id)) {
        $errors[] = 'Адрес акции: допустимы латиница, цифры и дефис, до 40 символов';
    } elseif (in_array($id, $otherIds, true)) {
        $errors[] = 'Адрес акции: такой уже занят другой акцией';
    }

    return $errors;
}

function promos_read(string $file): ?array {
    if (!is_file($file) || !is_readable($file)) {
        return null;
    }
    $raw = file_get_contents($file);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['promos']) || !is_array($data['promos'])) {
        return null;
    }
    $data['version'] = (int)($data['version'] ?? 1);
    return $data;
}
