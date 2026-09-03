<?php
header('Content-Type: text/plain; charset=utf-8');
$T = ['pass' => 0, 'fail' => 0, 'msgs' => []];

function t_true($cond, $label) {
    global $T;
    if ($cond) { $T['pass']++; } else { $T['fail']++; $T['msgs'][] = "FAIL: $label"; }
}

function t_eq($actual, $expected, $label) {
    global $T;
    if ($actual === $expected) {
        $T['pass']++;
    } else {
        $T['fail']++;
        $T['msgs'][] = "FAIL: $label\n  ожидалось: " . var_export($expected, true)
                     . "\n  получено:  " . var_export($actual, true);
    }
}

function t_report() {
    global $T;
    foreach ($T['msgs'] as $m) { echo $m . "\n"; }
    echo "\n{$T['pass']} passed, {$T['fail']} failed\n";
}

t_true(PHP_VERSION_ID >= 80300, 'PHP 8.3+');

require __DIR__ . '/../lib/promos.php';

t_eq(count(promos_themes()), 8, 'восемь тем');
t_true(in_array('t-grad2', promos_themes(), true), 't-grad2 в списке тем');

$ok = [
    'id' => 'egypt', 'enabled' => true, 'badge' => '−35%', 'title' => 'Египет',
    'note' => 'Хургада', 'theme' => 't-red', 'big' => false,
    'modal_title' => 'Египет −35%', 'modal_sub' => '', 'modal_text' => 'Условия',
];
t_eq(promos_validate($ok, []), [], 'корректная акция проходит');

$noTitle = $ok; $noTitle['title'] = '   ';
t_true(count(promos_validate($noTitle, [])) === 1, 'пустой заголовок — одна ошибка');

$badTheme = $ok; $badTheme['theme'] = 't-neon';
t_true(count(promos_validate($badTheme, [])) === 1, 'чужая тема отклоняется');

$longNote = $ok; $longNote['note'] = str_repeat('я', 91);
t_true(count(promos_validate($longNote, [])) === 1, 'note длиннее 90 отклоняется');

$dupe = $ok;
t_true(count(promos_validate($dupe, ['egypt'])) === 1, 'дубль id отклоняется');

t_eq(promos_read('/nope/missing.json'), null, 'нет файла — null');
$tmp = sys_get_temp_dir() . '/broken.json';
file_put_contents($tmp, '{"version":1,"promos":');
t_eq(promos_read($tmp), null, 'битый JSON — null');
unlink($tmp);

t_eq(promos_slug('Египет'), 'egipet', 'транслит одного слова');
t_eq(promos_slug('Новый год в Бангкоке'), 'novyy-god-v-bangkoke', 'пробелы в дефисы');
t_eq(promos_slug('Раннее бронирование — лета!'), 'rannee-bronirovanie-leta', 'знаки убираются');
t_eq(promos_slug('ОАЭ, Дубай'), 'oae-dubay', 'запятая не даёт двойной дефис');
t_eq(promos_slug('Сочи 2026'), 'sochi-2026', 'цифры сохраняются');
t_eq(promos_slug('!!!'), 'akciya', 'без букв — запасное слово');
t_true(mb_strlen(promos_slug(str_repeat('Кипр ', 20))) <= 40, 'длина ограничена 40');

t_eq(promos_unique_id('egipet', []), 'egipet', 'свободный id не меняется');
t_eq(promos_unique_id('egipet', ['egipet']), 'egipet-2', 'занятый получает -2');
t_eq(promos_unique_id('egipet', ['egipet', 'egipet-2']), 'egipet-3', 'дальше -3');

t_report();
