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

$dir = sys_get_temp_dir() . '/promos_test_' . bin2hex(random_bytes(4));
mkdir($dir . '/backups', 0755, true);
$file = $dir . '/akcii.json';
$backups = $dir . '/backups';

$one = ['version' => 1, 'promos' => [['id' => 'a'] + promos_defaults()]];
t_true(promos_write($file, $one, $backups), 'первая запись удалась');
t_eq(promos_read($file)['promos'][0]['id'], 'a', 'записанное читается обратно');
t_eq(count(glob($backups . '/*.json')), 0, 'первая запись бэкап не создаёт');

$two = ['version' => 1, 'promos' => [['id' => 'b'] + promos_defaults()]];
promos_write($file, $two, $backups);
t_eq(count(glob($backups . '/*.json')), 1, 'вторая запись сохранила прежнюю версию');

for ($i = 0; $i < 14; $i++) {
    promos_write($file, ['version' => 1, 'promos' => [['id' => 'x' . $i] + promos_defaults()]], $backups);
}
t_eq(count(glob($backups . '/*.json')), 10, 'бэкапов не больше десяти');

promos_write($file, ['version' => 1, 'promos' => [['id' => 'c', 'title' => 'Египет'] + promos_defaults()]], $backups);
t_true(str_contains(file_get_contents($file), 'Египет'), 'кириллица пишется как есть, без \\u');

file_put_contents($file, '{битый');
t_eq(promos_load($file, $backups)['promos'][0]['id'], 'x13', 'битый файл — поднимается свежий бэкап');
t_eq(promos_load($dir . '/nope.json', $dir . '/nope')['promos'], [], 'нет ни файла, ни бэкапов — пустой список');

array_map('unlink', glob($backups . '/*.json'));
@unlink($file); @rmdir($backups); @rmdir($dir);

$live = promos_read(__DIR__ . '/../data/akcii.json');
t_true($live !== null, 'боевой JSON читается');
t_eq(count($live['promos']), 10, 'перенесены все десять акций');
$ids = array_column($live['promos'], 'id');
t_eq(count(array_unique($ids)), 10, 'все id уникальны');
t_eq($ids[0], 'early', 'первой идёт раннее бронирование');
foreach ($live['promos'] as $p) {
    $others = array_values(array_diff($ids, [$p['id']]));
    t_eq(promos_validate($p, $others), [], 'акция ' . $p['id'] . ' проходит валидацию');
}

t_report();
