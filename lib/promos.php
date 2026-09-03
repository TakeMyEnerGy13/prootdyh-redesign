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

function promos_slug(string $title): string {
    $map = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh',
        'з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o',
        'п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c',
        'ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
    ];
    $s = mb_strtolower(trim($title), 'UTF-8');
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9]+/u', '-', $s);
    $s = trim($s, '-');
    if ($s === '') {
        return 'akciya';
    }
    if (strlen($s) > 40) {
        $s = trim(substr($s, 0, 40), '-');
    }
    return $s;
}

function promos_unique_id(string $base, array $takenIds): string {
    if (!in_array($base, $takenIds, true)) {
        return $base;
    }
    for ($n = 2; $n < 1000; $n++) {
        $candidate = $base . '-' . $n;
        if (strlen($candidate) > 40) {
            $candidate = trim(substr($base, 0, 40 - strlen((string)$n) - 1), '-') . '-' . $n;
        }
        if (!in_array($candidate, $takenIds, true)) {
            return $candidate;
        }
    }
    return $base . '-' . bin2hex(random_bytes(3));
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
