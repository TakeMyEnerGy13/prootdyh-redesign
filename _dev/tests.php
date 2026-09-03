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

require __DIR__ . '/../lib/render.php';

$p = [
    'id' => 'egypt', 'enabled' => true, 'badge' => '−35%', 'title' => 'Египет',
    'note' => 'Хургада', 'theme' => 't-red', 'big' => false,
    'modal_title' => 'Египет', 'modal_sub' => 'Хургада', 'modal_text' => "Первая строка\nВторая строка",
];

$tile = promos_render_tile($p);
t_true(str_contains($tile, 'href="#p-egypt"'), 'плитка ссылается на якорь модалки');
t_true(str_contains($tile, 'class="ptile t-red"'), 'тема попала в класс');
t_true(!str_contains($tile, 'is-big'), 'обычная плитка без is-big');
t_true(str_contains(promos_render_tile(['big' => true] + $p), 'ptile is-big t-red'), 'широкая плитка получает is-big');

$modal = promos_render_modal($p);
t_true(str_contains($modal, 'id="p-egypt"'), 'у модалки нужный якорь');
t_true(str_contains($modal, '<br />') || str_contains($modal, '<br>'), 'перенос строки стал <br>');
t_true(str_contains($modal, 'href="/#contact"'), 'кнопка ведёт на форму заявки');

$evil = ['title' => '<script>alert(1)</script>', 'note' => 'кавычка " и амперсанд &'] + $p;
$evilTile = promos_render_tile($evil);
t_true(!str_contains($evilTile, '<script>'), 'скрипт из заголовка экранирован');
t_true(str_contains($evilTile, '&amp;'), 'амперсанд экранирован');

$data = ['version' => 1, 'promos' => [
    ['id' => 'a', 'enabled' => true] + promos_defaults(),
    ['id' => 'b', 'enabled' => false] + promos_defaults(),
]];
t_eq(count(promos_visible($data)), 1, 'скрытая акция не выводится');

require __DIR__ . '/../upravlenie/auth.php';

$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 10.0.0.1';
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
t_eq(admin_client_ip(), '203.0.113.9', 'IP берётся из X-Forwarded-For');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
t_eq(admin_client_ip(), '10.0.0.1', 'без прокси — REMOTE_ADDR');

$att = sys_get_temp_dir() . '/att_' . bin2hex(random_bytes(4)) . '.json';
t_true(!admin_login_blocked($att, '1.2.3.4'), 'чистый IP не заблокирован');
for ($i = 0; $i < 4; $i++) { admin_note_failure($att, '1.2.3.4'); }
t_true(!admin_login_blocked($att, '1.2.3.4'), 'после четырёх попыток ещё можно');
admin_note_failure($att, '1.2.3.4');
t_true(admin_login_blocked($att, '1.2.3.4'), 'после пятой — блок');
t_true(!admin_login_blocked($att, '5.6.7.8'), 'блок только для своего IP');
admin_reset_failures($att, '1.2.3.4');
t_true(!admin_login_blocked($att, '1.2.3.4'), 'удачный вход снимает счётчик');
@unlink($att);

$hash = password_hash('pa$$w0rd', PASSWORD_DEFAULT);
t_true(password_verify('pa$$w0rd', $hash), 'хеш проверяется');
t_true(!password_verify('другой', $hash), 'чужой пароль не проходит');

require __DIR__ . '/../upravlenie/actions.php';

$list = [
    ['id' => 'a', 'enabled' => true] + promos_defaults(),
    ['id' => 'b', 'enabled' => true] + promos_defaults(),
    ['id' => 'c', 'enabled' => false] + promos_defaults(),
];

t_eq(admin_find_index($list, 'b'), 1, 'индекс по id');
t_eq(admin_find_index($list, 'нет'), null, 'неизвестный id — null');

t_eq(admin_toggle($list, 'a')[0]['enabled'], false, 'показ выключается');
t_eq(admin_toggle($list, 'c')[2]['enabled'], true, 'показ включается');

t_eq(array_column(admin_move($list, 'b', -1), 'id'), ['b', 'a', 'c'], 'вверх меняет местами с соседом');
t_eq(array_column(admin_move($list, 'b', 1), 'id'), ['a', 'c', 'b'], 'вниз меняет местами с соседом');
t_eq(array_column(admin_move($list, 'a', -1), 'id'), ['a', 'b', 'c'], 'первую вверх не двигаем');
t_eq(array_column(admin_move($list, 'c', 1), 'id'), ['a', 'b', 'c'], 'последнюю вниз не двигаем');

t_eq(array_column(admin_delete($list, 'b'), 'id'), ['a', 'c'], 'удаление вырезает акцию');
t_eq(count(admin_delete($list, 'нет')), 3, 'удаление неизвестного id ничего не меняет');

$post = [
    'badge' => '  −20% ', 'title' => ' Турция ', 'note' => '',
    'theme' => 't-blue', 'big' => 'on', 'enabled' => 'on',
    'modal_title' => 'Турция', 'modal_sub' => '', 'modal_text' => 'Текст',
];
$promo = admin_promo_from_post($post);
t_eq($promo['badge'], '−20%', 'пробелы по краям обрезаются');
t_eq($promo['big'], true, 'галка стала true');
t_eq($promo['enabled'], true, 'показ включён галкой');
$noBig = $post; unset($noBig['big']);
t_eq(admin_promo_from_post($noBig)['big'], false, 'снятая галка — false');
t_true(array_key_exists('modal_sub', $promo), 'пустое необязательное поле сохраняется');

$new = admin_promo_from_post($post);
$new['id'] = promos_unique_id(promos_slug($new['title']), ['turciya']);
t_eq($new['id'], 'turciya-2', 'id занят — берётся следующий');

t_report();
