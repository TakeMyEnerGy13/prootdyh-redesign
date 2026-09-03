# Мини-админка акций — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** заказчик «Проотдых» правит акции сам через закрытую паролем страницу `/upravlenie`, а `/akcii` рендерится из `data/akcii.json`.

**Architecture:** статический сайт получает три PHP-точки: `akcii.php` (публичный рендер из JSON), `upravlenie/index.php` (формы без JavaScript, POST/Redirect/GET) и общую библиотеку `lib/promos.php` (чтение, валидация, атомарная запись, бэкапы). Данные и конфиг лежат в `data/`, закрытом `deny from all`. Вся разработка идёт в каталоге `_dev/` на боевом хостинге, боевые файлы переключаются последней задачей.

**Tech Stack:** PHP 8.3 (cgi-fcgi) без composer и внешних зависимостей, Apache за nginx-прокси рег.ру, FTP-заливка через `curl --netrc-file`, свой мини-раннер тестов на PHP.

**Spec:** `docs/superpowers/specs/2026-09-03-akcii-admin-design.md`

## Global Constraints

- PHP 8.3.31, SAPI `cgi-fcgi`, пользователь `u3617178`, DOCUMENT_ROOT `/var/www/u3617178/data/www/prootdyhspb.ru`.
- Никаких внешних библиотек и composer. Только стандартная библиотека PHP.
- В админке **ноль JavaScript**. Только формы, POST и redirect.
- Кодировка везде UTF-8 без BOM. Все PHP-файлы начинаются с `<?php` в первом байте — ни пробела, ни пустой строки перед ним, иначе `header()` и сессии сломаются.
- Допустимые темы плиток — ровно восемь, из `style.css:394-401`: `t-red`, `t-blue`, `t-magenta`, `t-green`, `t-orange`, `t-cyan`, `t-grad`, `t-grad2`.
- IP посетителя берётся из `X-Forwarded-For`, а не из `REMOTE_ADDR`: сайт за nginx-прокси.
- Файлы `.htaccess` хранятся в LF — это уже прописано в `.gitattributes`. Не ломать.
- `data/config.php` в git не попадает.
- Деплой на хостинг только по FTP: `curl --netrc-file ~/.netrc-prootdyh -T <файл> ftp://server200.hosting.reg.ru/www/prootdyhspb.ru/<путь>`. Успех — код `226`. Git push прод не обновляет.
- Правя `style.css`, поднять `?v=ГГГГММДД` во **всех** HTML и PHP: хостинг отдаёт CSS с `max-age=3888000`.
- Боевой `.htaccess` трогается только в Task 14. Ошибка в правилах кладёт весь сайт.

---

### Task 1: Деплой-скрипт и раннер тестов

**Files:**
- Create: `tools/deploy-dev.sh`
- Create: `_dev/tests.php`
- Create: `_dev/.htaccess`

**Interfaces:**
- Produces: `t_eq($actual, $expected, $label)`, `t_true($cond, $label)`, `t_report()` — на них опираются тесты во всех задачах ниже. Раннер открывается по адресу `https://prootdyhspb.ru/_dev/tests.php`.

- [ ] **Step 1: Написать скрипт заливки**

`tools/deploy-dev.sh` — заливает перечисленные файлы пачкой в один заход, печатает код по каждому:

```bash
#!/usr/bin/env bash
# Заливает файлы на хостинг. Аргументы — пути относительно корня репозитория.
# Пример: tools/deploy-dev.sh _dev/tests.php lib/promos.php
set -u
HOST="ftp://server200.hosting.reg.ru/www/prootdyhspb.ru"
NETRC="$HOME/.netrc-prootdyh"
fail=0
for f in "$@"; do
  code=$(curl -s --netrc-file "$NETRC" -T "$f" "$HOST/$f" -w "%{http_code}")
  echo "$f -> $code"
  [ "$code" = "226" ] || fail=1
done
exit $fail
```

- [ ] **Step 2: Написать раннер тестов**

`_dev/tests.php` — без зависимостей, печатает текстом, чтобы читалось через `curl`:

```php
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
t_report();
```

- [ ] **Step 3: Закрыть каталог от индексации**

`_dev/.htaccess` (LF, без BOM):

```apache
Header set X-Robots-Tag "noindex, nofollow"
```

- [ ] **Step 4: Залить и проверить**

Run: `bash tools/deploy-dev.sh _dev/tests.php _dev/.htaccess tools/deploy-dev.sh`
Expected: три строки с `226`.

Run: `curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: `1 passed, 0 failed`

- [ ] **Step 5: Коммит**

```bash
git add tools/deploy-dev.sh _dev/tests.php _dev/.htaccess
git commit -m "Каркас разработки на хостинге: деплой-скрипт и раннер тестов"
```

---

### Task 2: Чтение и валидация JSON

**Files:**
- Create: `lib/promos.php`
- Modify: `_dev/tests.php`

**Interfaces:**
- Consumes: `t_eq`, `t_true`, `t_report` из Task 1.
- Produces:
  - `promos_themes(): array` — восемь допустимых классов темы
  - `promos_defaults(): array` — акция со всеми полями по умолчанию
  - `promos_validate(array $promo, array $otherIds): array` — список текстов ошибок, пустой массив = всё в порядке
  - `promos_read(string $file): ?array` — `['version'=>int,'promos'=>array]` или `null`, если файла нет либо JSON битый

- [ ] **Step 1: Написать падающие тесты**

Дописать в `_dev/tests.php` перед `t_report()`:

```php
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
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `bash tools/deploy-dev.sh _dev/tests.php && curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: фатальная ошибка о ненайденном `lib/promos.php`.

- [ ] **Step 3: Реализовать**

`lib/promos.php`:

```php
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
            $errors[] = "«$label»: поле обязательно";
        } elseif ($len > $max) {
            $errors[] = "«$label»: не длиннее $max символов, сейчас $len";
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
```

- [ ] **Step 4: Убедиться, что тесты проходят**

Run: `bash tools/deploy-dev.sh lib/promos.php _dev/tests.php && curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: `10 passed, 0 failed`

- [ ] **Step 5: Коммит**

```bash
git add lib/promos.php _dev/tests.php
git commit -m "Чтение и валидация акций"
```

---

### Task 3: Адрес акции из заголовка

**Files:**
- Modify: `lib/promos.php`
- Modify: `_dev/tests.php`

**Interfaces:**
- Produces:
  - `promos_slug(string $title): string` — транслит кириллицы в `[a-z0-9-]`
  - `promos_unique_id(string $base, array $takenIds): string` — добавляет `-2`, `-3`, пока не станет уникальным

- [ ] **Step 1: Написать падающие тесты**

```php
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
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `bash tools/deploy-dev.sh _dev/tests.php && curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: фатальная ошибка «Call to undefined function promos_slug()».

- [ ] **Step 3: Реализовать**

Дописать в `lib/promos.php`:

```php
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
```

- [ ] **Step 4: Убедиться, что тесты проходят**

Run: `bash tools/deploy-dev.sh lib/promos.php _dev/tests.php && curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: `20 passed, 0 failed`

- [ ] **Step 5: Коммит**

```bash
git add lib/promos.php _dev/tests.php
git commit -m "Адрес акции из заголовка транслитом"
```

---

### Task 4: Атомарная запись и бэкапы

**Files:**
- Modify: `lib/promos.php`
- Modify: `_dev/tests.php`

**Interfaces:**
- Produces:
  - `promos_write(string $file, array $data, string $backupDir, int $keep = 10): bool` — бэкап текущей версии, затем атомарная запись
  - `promos_load(string $file, string $backupDir): array` — чтение с подъёмом свежайшего бэкапа, если основной файл битый

- [ ] **Step 1: Написать падающие тесты**

```php
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

$raw = file_get_contents($file);
t_true(str_contains($raw, 'Егип') === false, 'ASCII-эскейпов нет — проверка на кириллицу ниже');
promos_write($file, ['version' => 1, 'promos' => [['id' => 'c', 'title' => 'Египет'] + promos_defaults()]], $backups);
t_true(str_contains(file_get_contents($file), 'Египет'), 'кириллица пишется как есть, без \\u');

file_put_contents($file, '{битый');
t_eq(promos_load($file, $backups)['promos'][0]['id'], 'x13', 'битый файл — поднимается свежий бэкап');
t_eq(promos_load($dir . '/nope.json', $dir . '/nope')['promos'], [], 'нет ни файла, ни бэкапов — пустой список');

array_map('unlink', glob($backups . '/*.json'));
@unlink($file); @rmdir($backups); @rmdir($dir);
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `bash tools/deploy-dev.sh _dev/tests.php && curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: фатальная ошибка «Call to undefined function promos_write()».

- [ ] **Step 3: Реализовать**

Дописать в `lib/promos.php`:

```php
function promos_write(string $file, array $data, string $backupDir, int $keep = 10): bool {
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0755, true);
    }

    // Прежняя версия уезжает в бэкап до того, как мы её перезапишем.
    if (is_file($file) && promos_read($file) !== null) {
        $stamp = date('Ymd-His') . '-' . bin2hex(random_bytes(2));
        @copy($file, $backupDir . '/akcii-' . $stamp . '.json');
        promos_rotate_backups($backupDir, $keep);
    }

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($json === false) {
        return false;
    }

    // Пишем рядом и переносим поверх: обрыв скрипта не оставит обрезанный файл.
    $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    @chmod($file, 0644);
    return true;
}

function promos_rotate_backups(string $backupDir, int $keep = 10): void {
    $files = glob($backupDir . '/akcii-*.json') ?: [];
    if (count($files) <= $keep) {
        return;
    }
    sort($files); // имена начинаются с даты, поэтому сортировка по имени = по времени
    foreach (array_slice($files, 0, count($files) - $keep) as $old) {
        @unlink($old);
    }
}

function promos_load(string $file, string $backupDir): array {
    $data = promos_read($file);
    if ($data !== null) {
        return $data;
    }
    $files = glob($backupDir . '/akcii-*.json') ?: [];
    rsort($files);
    foreach ($files as $candidate) {
        $data = promos_read($candidate);
        if ($data !== null) {
            return $data;
        }
    }
    return ['version' => 1, 'promos' => []];
}
```

- [ ] **Step 4: Убедиться, что тесты проходят**

Run: `bash tools/deploy-dev.sh lib/promos.php _dev/tests.php && curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: `29 passed, 0 failed`

- [ ] **Step 5: Коммит**

```bash
git add lib/promos.php _dev/tests.php
git commit -m "Атомарная запись акций и ротация бэкапов"
```

---

### Task 5: Перенос десяти акций в JSON

**Files:**
- Create: `data/akcii.json`
- Create: `data/.htaccess`
- Create: `lib/.htaccess`
- Create: `data/backups/.gitkeep`
- Modify: `_dev/tests.php`

**Interfaces:**
- Consumes: `promos_read`, `promos_validate` из Task 2.
- Produces: `data/akcii.json` с десятью акциями в порядке из `akcii.html`.

- [ ] **Step 1: Написать падающий тест целостности данных**

```php
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
```

- [ ] **Step 2: Убедиться, что тест падает**

Run: `bash tools/deploy-dev.sh _dev/tests.php && curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: `FAIL: боевой JSON читается`.

- [ ] **Step 3: Перенести данные**

Взять содержимое из `akcii.html`: плитки — строки 63-113, модалки — строки 155-263. Каждой акции соответствуют якорь `href="#p-<id>"` и блок `id="p-<id>"`; в JSON поле `id` идёт **без** префикса `p-`, префикс добавит шаблон.

Соответствие полей: `.pt-badge` → `badge`, `.pt-city` → `title`, `.pt-note` → `note`, класс темы → `theme`, наличие `is-big` → `big`, `<h3>` → `modal_title`, `.pmodal__sub` → `modal_sub`, текст `.pmodal__txt` до `<br><a class="btn">` → `modal_text`. Хвост с кнопкой в JSON не переносится — её печатает шаблон.

Порядок и `id`: `early`, `egypt`, `turkey`, `ny`, `maldives`, `sochi`, `split`, `cyprus`, `uae`, `rus`. У всех `enabled: true`. `big: true` только у `early` и `ny` (в разметке у них `is-big`).

`data/akcii.json` начинается так — остальные восемь акций собираются тем же способом:

```json
{
  "version": 1,
  "promos": [
    {
      "id": "early",
      "enabled": true,
      "badge": "до −40%",
      "title": "Раннее бронирование лета",
      "note": "Забронируйте летний отпуск по ценам зимы",
      "theme": "t-grad",
      "big": true,
      "modal_title": "Раннее бронирование лета — до −40%",
      "modal_sub": "Забронируйте летний отпуск заранее и сэкономьте до 40%.",
      "modal_text": "Лучшие отели разбирают за полгода. Фиксируйте цену зимы на летний тур: полный выбор номеров и минимальная предоплата."
    },
    {
      "id": "egypt",
      "enabled": true,
      "badge": "−35%",
      "title": "Египет",
      "note": "Хургада, всё включено",
      "theme": "t-red",
      "big": false,
      "modal_title": "Египет со скидкой −35%",
      "modal_sub": "Хургада и Шарм-эль-Шейх, всё включено, вылеты каждую неделю.",
      "modal_text": "Отели 5★ с собственным пляжем и аквапарком. Скидка действует при бронировании тура от 7 ночей до конца месяца."
    }
  ]
}
```

Знак минуса в бейджах — это U+2212 (`−`), а не дефис. Копировать из `akcii.html` как есть, не заменять.

- [ ] **Step 4: Закрыть служебные каталоги**

`data/.htaccess` и `lib/.htaccess` — одинаковое содержимое (LF, без BOM):

```apache
Require all denied
```

Создать `data/backups/.gitkeep` пустым файлом, чтобы каталог существовал в репозитории.

- [ ] **Step 5: Убедиться, что тесты проходят**

Run: `bash tools/deploy-dev.sh data/akcii.json data/.htaccess lib/.htaccess _dev/tests.php && curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: `43 passed, 0 failed`

Run: `curl -s -o /dev/null -w "%{http_code}\n" https://prootdyhspb.ru/data/akcii.json`
Expected: `403`

- [ ] **Step 6: Коммит**

```bash
git add data lib/.htaccess _dev/tests.php
git commit -m "Десять акций перенесены в data/akcii.json, служебные каталоги закрыты"
```

---

### Task 6: Публичная страница из JSON

**Files:**
- Create: `akcii.php`
- Create: `lib/render.php`
- Modify: `_dev/tests.php`

**Interfaces:**
- Consumes: `promos_load` из Task 4.
- Produces:
  - `promos_render_tile(array $p): string` — HTML одной плитки
  - `promos_render_modal(array $p): string` — HTML одной модалки
  - `promos_visible(array $data): array` — только акции с `enabled === true`

- [ ] **Step 1: Написать падающие тесты**

```php
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
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `bash tools/deploy-dev.sh _dev/tests.php && curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: фатальная ошибка о ненайденном `lib/render.php`.

- [ ] **Step 3: Реализовать шаблоны**

`lib/render.php`:

```php
<?php

function promos_e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function promos_visible(array $data): array {
    return array_values(array_filter(
        $data['promos'] ?? [],
        static fn(array $p): bool => ($p['enabled'] ?? false) === true
    ));
}

function promos_render_tile(array $p): string {
    $cls = 'ptile' . (($p['big'] ?? false) ? ' is-big' : '') . ' ' . promos_e($p['theme'] ?? 't-blue');
    return '      <a class="' . $cls . '" href="#p-' . promos_e($p['id']) . '">' . "\n"
         . '        <span class="pt-badge">' . promos_e($p['badge']) . '</span>' . "\n"
         . '        <span class="pt-city">' . promos_e($p['title']) . '</span>' . "\n"
         . '        <span class="pt-note">' . promos_e($p['note']) . '</span>' . "\n"
         . '      </a>' . "\n";
}

function promos_render_modal(array $p): string {
    $x = '<a class="pmodal__x" href="#_" aria-label="Закрыть">'
       . '<svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></a>';
    return '<div class="pmodal" id="p-' . promos_e($p['id']) . '">' . "\n"
         . '  <a class="pmodal__bg" href="#_" aria-label="Закрыть"></a>' . "\n"
         . '  <div class="pmodal__win">' . "\n"
         . '    ' . $x . "\n"
         . '    <h3>' . promos_e($p['modal_title']) . '</h3>' . "\n"
         . '    <p class="pmodal__sub">' . promos_e($p['modal_sub']) . '</p>' . "\n"
         . '    <div class="pmodal__txt">' . nl2br(promos_e($p['modal_text'])) . "\n"
         . '      <br><a class="btn" href="/#contact">Узнать условия</a></div>' . "\n"
         . '  </div>' . "\n"
         . '</div>' . "\n";
}
```

- [ ] **Step 4: Собрать страницу**

`akcii.php` — копия `akcii.html`, у которой:

1. В первой строке файла добавлена шапка (до `<!DOCTYPE html>`):

```php
<?php
ini_set('display_errors', '0');
require __DIR__ . '/lib/promos.php';
require __DIR__ . '/lib/render.php';
$data = promos_load(__DIR__ . '/data/akcii.json', __DIR__ . '/data/backups');
$promos = promos_visible($data);
?>
```

2. Содержимое `<div class="pgrid">` (строки 63-113 исходника) заменено на:

```php
<?php foreach ($promos as $p) { echo promos_render_tile($p); } ?>
```

3. Блок модалок (строки 155-263 исходника, от `<!-- ============ MODALS` до закрывающего `</div>` последней модалки) заменён на:

```php
<!-- ============ MODALS (:target, zero-JS) ============ -->
<?php foreach ($promos as $p) { echo promos_render_modal($p); } ?>
```

Шапка, hero, CTA-полоса, подвал и `<head>` остаются символ в символ как в `akcii.html`.

- [ ] **Step 5: Проверить в браузере**

Run: `bash tools/deploy-dev.sh akcii.php lib/render.php _dev/tests.php && curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: `53 passed, 0 failed`

Открыть `https://prootdyhspb.ru/akcii.php` и `https://prootdyhspb.ru/akcii` (пока отдаёт старый `.html`), сравнить отрисовку: число плиток, порядок, цвета, широкие карточки, открытие каждой из десяти модалок. Расхождений быть не должно.

- [ ] **Step 6: Коммит**

```bash
git add akcii.php lib/render.php _dev/tests.php
git commit -m "Страница акций рендерится из JSON"
```

---

### Task 7: Конфиг и вход в админку

**Files:**
- Create: `data/config.sample.php`
- Create: `upravlenie/index.php`
- Create: `upravlenie/auth.php`
- Modify: `.gitignore`
- Modify: `_dev/tests.php`

**Interfaces:**
- Produces:
  - `admin_config(): array` — `['password_hash' => string, 'session_name' => string]`
  - `admin_client_ip(): string` — первый адрес из `X-Forwarded-For`, иначе `REMOTE_ADDR`
  - `admin_login_blocked(string $file, string $ip, int $limit = 5, int $window = 900): bool`
  - `admin_note_failure(string $file, string $ip): void`
  - `admin_reset_failures(string $file, string $ip): void`
  - `admin_start_session(): void`, `admin_is_logged_in(): bool`, `admin_csrf_token(): string`, `admin_csrf_ok(?string $t): bool`

- [ ] **Step 1: Написать падающие тесты**

```php
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
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `bash tools/deploy-dev.sh _dev/tests.php && curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: фатальная ошибка о ненайденном `upravlenie/auth.php`.

- [ ] **Step 3: Реализовать вспомогательный слой**

`upravlenie/auth.php`:

```php
<?php

function admin_config(): array {
    $file = __DIR__ . '/../data/config.php';
    if (!is_file($file)) {
        return ['password_hash' => '', 'session_name' => 'proadm'];
    }
    $cfg = require $file;
    return [
        'password_hash' => (string)($cfg['password_hash'] ?? ''),
        'session_name'  => (string)($cfg['session_name'] ?? 'proadm'),
    ];
}

function admin_client_ip(): string {
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($fwd !== '') {
        $first = trim(explode(',', $fwd)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function admin_attempts_read(string $file): array {
    $raw = is_file($file) ? file_get_contents($file) : '';
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

function admin_login_blocked(string $file, string $ip, int $limit = 5, int $window = 900): bool {
    $data = admin_attempts_read($file);
    $rec = $data[$ip] ?? null;
    if (!is_array($rec)) {
        return false;
    }
    if (time() - (int)($rec['last'] ?? 0) > $window) {
        return false;
    }
    return (int)($rec['count'] ?? 0) >= $limit;
}

function admin_note_failure(string $file, string $ip, int $window = 900): void {
    $data = admin_attempts_read($file);
    $rec = $data[$ip] ?? ['count' => 0, 'last' => 0];
    if (time() - (int)$rec['last'] > $window) {
        $rec['count'] = 0;
    }
    $rec['count'] = (int)$rec['count'] + 1;
    $rec['last'] = time();
    $data[$ip] = $rec;
    // Чужие протухшие записи заодно выбрасываем, чтобы файл не пух.
    foreach ($data as $k => $v) {
        if (time() - (int)($v['last'] ?? 0) > $window * 4) {
            unset($data[$k]);
        }
    }
    file_put_contents($file, json_encode($data), LOCK_EX);
}

function admin_reset_failures(string $file, string $ip): void {
    $data = admin_attempts_read($file);
    unset($data[$ip]);
    file_put_contents($file, json_encode($data), LOCK_EX);
}

function admin_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $cfg = admin_config();
    session_name($cfg['session_name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function admin_is_logged_in(): bool {
    return ($_SESSION['auth'] ?? false) === true;
}

function admin_csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function admin_csrf_ok(?string $token): bool {
    return is_string($token)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}
```

- [ ] **Step 4: Образец конфига и .gitignore**

`data/config.sample.php`:

```php
<?php
// Скопировать в data/config.php и подставить свой хеш.
// Хеш получить так:
//   php -r "echo password_hash('пароль', PASSWORD_DEFAULT);"
return [
    'password_hash' => '$2y$12$ЗАМЕНИТЬ',
    'session_name'  => 'proadm',
];
```

Дописать в `.gitignore`:

```
data/config.php
data/login_attempts.json
data/backups/*.json
```

- [ ] **Step 5: Экран входа**

`upravlenie/index.php` на этом шаге умеет только вход и выход. Разметка использует классы сайта, подключает `../style.css?v=20260903`:

```php
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

header('X-Robots-Tag: noindex, nofollow');

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
```

- [ ] **Step 6: Проверить**

Сгенерировать пароль и хеш, положить `data/config.php` на хостинг (в git не коммитить), пароль передать через файл для `curl --netrc-file`, не в переписку.

Run: `bash tools/deploy-dev.sh upravlenie/index.php upravlenie/auth.php data/config.sample.php _dev/tests.php && curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: `62 passed, 0 failed`

Открыть `https://prootdyhspb.ru/upravlenie/`, ввести неверный пароль пять раз — на пятой попытке текст меняется на сообщение о паузе. Войти верным паролем после снятия блокировки (удалить `data/login_attempts.json`), убедиться, что «Выйти» возвращает форму входа.

- [ ] **Step 7: Коммит**

```bash
git add upravlenie data/config.sample.php .gitignore _dev/tests.php
git commit -m "Вход в админку: сессия, хеш пароля, пауза после пяти попыток"
```

---

### Task 8: Список акций, переключение показа и порядок

**Files:**
- Modify: `upravlenie/index.php`
- Create: `upravlenie/actions.php`
- Modify: `_dev/tests.php`

**Interfaces:**
- Consumes: `promos_load`, `promos_write`, `admin_csrf_ok`.
- Produces:
  - `admin_find_index(array $promos, string $id): ?int`
  - `admin_toggle(array $promos, string $id): array`
  - `admin_move(array $promos, string $id, int $delta): array`
  - `admin_delete(array $promos, string $id): array`

- [ ] **Step 1: Написать падающие тесты**

```php
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
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `bash tools/deploy-dev.sh _dev/tests.php && curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: фатальная ошибка о ненайденном `upravlenie/actions.php`.

- [ ] **Step 3: Реализовать операции**

`upravlenie/actions.php`:

```php
<?php

function admin_find_index(array $promos, string $id): ?int {
    foreach ($promos as $i => $p) {
        if (($p['id'] ?? '') === $id) {
            return $i;
        }
    }
    return null;
}

function admin_toggle(array $promos, string $id): array {
    $i = admin_find_index($promos, $id);
    if ($i !== null) {
        $promos[$i]['enabled'] = !($promos[$i]['enabled'] ?? false);
    }
    return $promos;
}

function admin_move(array $promos, string $id, int $delta): array {
    $i = admin_find_index($promos, $id);
    if ($i === null) {
        return $promos;
    }
    $j = $i + $delta;
    if ($j < 0 || $j >= count($promos)) {
        return $promos;
    }
    [$promos[$i], $promos[$j]] = [$promos[$j], $promos[$i]];
    return $promos;
}

function admin_delete(array $promos, string $id): array {
    $i = admin_find_index($promos, $id);
    if ($i === null) {
        return $promos;
    }
    unset($promos[$i]);
    return array_values($promos);
}
```

- [ ] **Step 4: Вывести список и подключить кнопки**

В `upravlenie/index.php` заменить заглушку «Вошли…» на обработчик POST и разметку списка:

```php
require __DIR__ . '/actions.php';
require __DIR__ . '/../lib/render.php';

$dataFile   = __DIR__ . '/../data/akcii.json';
$backupDir  = __DIR__ . '/../data/backups';
$data       = promos_load($dataFile, $backupDir);
$notice     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['form'] ?? '', ['toggle', 'move'], true)) {
    if (!admin_csrf_ok($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('Форма устарела. Обновите страницу и повторите.');
    }
    $id = (string)($_POST['id'] ?? '');
    if ($_POST['form'] === 'toggle') {
        $data['promos'] = admin_toggle($data['promos'], $id);
    } else {
        $data['promos'] = admin_move($data['promos'], $id, (int)$_POST['delta'] === -1 ? -1 : 1);
    }
    promos_write($dataFile, $data, $backupDir);
    header('Location: index.php?saved=1');
    exit;
}
```

Разметка списка (после проверки входа):

```php
<?php foreach ($data['promos'] as $p): $t = admin_csrf_token(); ?>
  <article class="adm-row">
    <span class="adm-dot <?= promos_e($p['theme']) ?>"></span>
    <b><?= promos_e($p['title']) ?></b>
    <span class="adm-badge"><?= promos_e($p['badge']) ?></span>
    <span><?= ($p['enabled'] ?? false) ? 'показывается' : 'скрыта' ?></span>
    <form method="post"><input type="hidden" name="form" value="move">
      <input type="hidden" name="csrf" value="<?= $t ?>">
      <input type="hidden" name="id" value="<?= promos_e($p['id']) ?>">
      <button name="delta" value="-1">Вверх</button>
      <button name="delta" value="1">Вниз</button></form>
    <form method="post"><input type="hidden" name="form" value="toggle">
      <input type="hidden" name="csrf" value="<?= $t ?>">
      <input type="hidden" name="id" value="<?= promos_e($p['id']) ?>">
      <button><?= ($p['enabled'] ?? false) ? 'Скрыть' : 'Показать' ?></button></form>
    <a href="?action=edit&amp;id=<?= promos_e($p['id']) ?>">Изменить</a>
    <a href="?action=delete&amp;id=<?= promos_e($p['id']) ?>">Удалить</a>
  </article>
<?php endforeach; ?>
<p><a class="btn" href="?action=new">Добавить акцию</a>
   <a href="/akcii" target="_blank">Открыть страницу акций</a>
   <a href="?action=logout">Выйти</a></p>
```

Стили дописать в конец `style.css`. Переменные `--line`, `--ink`, `--faint` уже объявлены в файле, цвета не выдумывать:

```css
/* ===== админка акций ===== */
.adm-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;
  padding:14px 16px;border:1px solid var(--line);border-radius:12px;margin-bottom:10px}
.adm-row b{font-size:16px;color:var(--ink)}
.adm-row form{display:flex;gap:6px;margin:0}
.adm-row button{padding:7px 12px;border:1px solid var(--line);border-radius:8px;
  background:#fff;cursor:pointer;font:inherit;font-size:13.5px}
.adm-row button:hover{border-color:var(--accent);color:var(--accent)}
.adm-dot{width:16px;height:16px;border-radius:50%;flex:0 0 auto}
.adm-badge{font-size:12.5px;color:var(--faint)}
@media (max-width:520px){
  .adm-row{flex-direction:column;align-items:flex-start}
  .adm-row form{width:100%}
  .adm-row button{flex:1}
}
```

Класс темы (`t-red`, `t-grad`…) на `.adm-dot` подставляет градиент — правила уже есть в `style.css:394-401`, дублировать их не нужно.

- [ ] **Step 5: Проверить**

Run: `bash tools/deploy-dev.sh upravlenie/index.php upravlenie/actions.php style.css _dev/tests.php && curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: `72 passed, 0 failed`

В браузере: подвинуть акцию вверх и вниз, скрыть и показать — после каждого действия открыть `/akcii.php` и убедиться, что порядок и состав совпадают. Проверить, что в `data/backups/` появились копии.

- [ ] **Step 6: Коммит**

```bash
git add upravlenie style.css _dev/tests.php
git commit -m "Список акций: показ, скрытие и порядок"
```

---

### Task 9: Создание и редактирование акции

**Files:**
- Modify: `upravlenie/index.php`
- Modify: `upravlenie/actions.php`
- Modify: `_dev/tests.php`

**Interfaces:**
- Consumes: `promos_validate`, `promos_slug`, `promos_unique_id`, `promos_defaults`.
- Produces: `admin_promo_from_post(array $post): array` — приводит поля формы к структуре акции (обрезает пробелы, приводит галки к булеву).

- [ ] **Step 1: Написать падающие тесты**

```php
$post = [
    'badge' => '  −20% ', 'title' => ' Турция ', 'note' => '',
    'theme' => 't-blue', 'big' => 'on', 'enabled' => 'on',
    'modal_title' => 'Турция', 'modal_sub' => '', 'modal_text' => 'Текст',
];
$promo = admin_promo_from_post($post);
t_eq($promo['badge'], '−20%', 'пробелы по краям обрезаются');
t_eq($promo['big'], true, 'галка стала true');
t_eq($promo['enabled'], true, 'показ включён галкой');
t_eq(admin_promo_from_post(['theme' => 't-blue'] + $post + ['big' => null])['big'], false, 'снятая галка — false');
t_true(array_key_exists('modal_sub', $promo), 'пустое необязательное поле сохраняется');

$new = admin_promo_from_post($post);
$new['id'] = promos_unique_id(promos_slug($new['title']), ['turciya']);
t_eq($new['id'], 'turciya-2', 'id занят — берётся следующий');
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `bash tools/deploy-dev.sh _dev/tests.php && curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: фатальная ошибка «Call to undefined function admin_promo_from_post()».

- [ ] **Step 3: Реализовать**

Дописать в `upravlenie/actions.php`:

```php
function admin_promo_from_post(array $post): array {
    $out = promos_defaults();
    foreach (['badge', 'title', 'note', 'modal_title', 'modal_sub', 'modal_text'] as $f) {
        $out[$f] = trim((string)($post[$f] ?? ''));
    }
    $out['theme']   = (string)($post['theme'] ?? 't-blue');
    $out['big']     = !empty($post['big']);
    $out['enabled'] = !empty($post['enabled']);
    return $out;
}
```

- [ ] **Step 4: Добавить форму и обработчик**

В `upravlenie/index.php` — обработка `form=save`:

```php
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
    // с ошибками — показываем ту же форму с введёнными значениями
    $formPromo = $promo;
    $formErrors = $errors;
    $action = 'form';
}
```

Разметка формы — она же обслуживает `?action=new` и `?action=edit&id=…`. Перед выводом задаются `$formPromo` (из `$data['promos']` при правке, иначе `promos_defaults()`), `$formErrors` (пустой массив, если ошибок нет) и `$editId`:

```php
<h1><?= $editId === '' ? 'Новая акция' : 'Правка акции' ?></h1>

<?php if (!empty($formErrors)): ?>
  <ul style="color:#c0184a">
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
    <input name="badge" maxlength="20" required value="<?= promos_e($formPromo['badge']) ?>"></label>

  <label>Заголовок плитки
    <input name="title" maxlength="60" required value="<?= promos_e($formPromo['title']) ?>"></label>

  <label>Подпись <span>необязательно</span>
    <input name="note" maxlength="90" value="<?= promos_e($formPromo['note']) ?>"></label>

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
    <input name="modal_title" maxlength="90" required value="<?= promos_e($formPromo['modal_title']) ?>"></label>

  <label>Подзаголовок окна <span>необязательно</span>
    <input name="modal_sub" maxlength="160" value="<?= promos_e($formPromo['modal_sub']) ?>"></label>

  <label>Текст условий
    <textarea name="modal_text" rows="6" maxlength="1200" required><?= promos_e($formPromo['modal_text']) ?></textarea></label>

  <button class="btn" type="submit">Сохранить</button>
  <a href="index.php">Отмена</a>
</form>
```

Стили `.adm-form`, `.adm-swatches`, `.adm-check` дописать в конец `style.css`: поля на всю ширину колонки максимум 640 px, подпись `span` мельче и приглушённым цветом, восемь кружков образцов в строку.

- [ ] **Step 5: Проверить**

Run: `bash tools/deploy-dev.sh upravlenie/index.php upravlenie/actions.php _dev/tests.php && curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: `78 passed, 0 failed`

В браузере: создать акцию с заголовком «Тестовая акция», убедиться, что она появилась на `/akcii.php` с якорем `#p-testovaya-akciya`; отправить форму с пустым заголовком — вернулась та же форма с текстом ошибки и сохранёнными полями; отредактировать существующую акцию и проверить, что её адрес не изменился.

- [ ] **Step 6: Коммит**

```bash
git add upravlenie _dev/tests.php
git commit -m "Создание и редактирование акции"
```

---

### Task 10: Удаление с подтверждением

**Files:**
- Modify: `upravlenie/index.php`
- Modify: `_dev/tests.php`

**Interfaces:**
- Consumes: `admin_delete` из Task 8.

- [ ] **Step 1: Написать падающий тест**

```php
$list2 = [
    ['id' => 'keep', 'enabled' => true] + promos_defaults(),
    ['id' => 'drop', 'enabled' => true] + promos_defaults(),
];
$after = admin_delete($list2, 'drop');
t_eq(array_column($after, 'id'), ['keep'], 'после удаления остаётся одна акция');
t_eq(array_keys($after), [0], 'ключи массива переиндексированы');
```

- [ ] **Step 2: Убедиться в результате прогона**

Run: `bash tools/deploy-dev.sh _dev/tests.php && curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: `80 passed, 0 failed` (обе проверки проходят — `admin_delete` уже написана в Task 8; тест закрепляет поведение, на которое опирается экран удаления).

- [ ] **Step 3: Экран подтверждения**

В `upravlenie/index.php` по `?action=delete&id=…` выводится отдельная страница. Браузерный `confirm` не используется — JavaScript в админке не применяется:

```php
<?php
$i = admin_find_index($data['promos'], (string)($_GET['id'] ?? ''));
if ($i === null) {
    header('Location: index.php');
    exit;
}
$victim = $data['promos'][$i];
?>
<h1>Удалить акцию?</h1>
<p>Будет удалена акция «<b><?= promos_e($victim['title']) ?></b>»
   с бейджем «<?= promos_e($victim['badge']) ?>». Отменить это в интерфейсе нельзя.</p>
<form method="post">
  <input type="hidden" name="form" value="delete">
  <input type="hidden" name="csrf" value="<?= admin_csrf_token() ?>">
  <input type="hidden" name="id" value="<?= promos_e($victim['id']) ?>">
  <button class="btn" type="submit">Удалить</button>
  <a href="index.php">Отмена</a>
</form>
```

Обработчик:

```php
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
```

- [ ] **Step 4: Проверить**

Run: `bash tools/deploy-dev.sh upravlenie/index.php _dev/tests.php && curl -s https://prootdyhspb.ru/_dev/tests.php`
Expected: `80 passed, 0 failed`

В браузере: удалить созданную в Task 9 тестовую акцию, проверить, что она исчезла и с `/akcii.php`, и из списка, а в `data/backups/` осталась версия с ней.

- [ ] **Step 5: Коммит**

```bash
git add upravlenie _dev/tests.php
git commit -m "Удаление акции с подтверждением"
```

---

### Task 11: Проверки безопасности и адаптива

**Files:**
- Create: `robots.txt`
- Modify: `style.css`

- [ ] **Step 1: Закрыть админку от индексации**

`robots.txt` в корне:

```
User-agent: *
Disallow: /upravlenie/
Disallow: /_dev/

Sitemap: https://prootdyhspb.ru/sitemap.xml
```

Строку `Sitemap` оставить, только если файл `sitemap.xml` существует; если его нет — убрать строку, а не ссылаться на пустоту.

- [ ] **Step 2: Проверить доступы**

```bash
for u in data/akcii.json data/config.php data/login_attempts.json lib/promos.php lib/render.php; do
  echo -n "$u: "; curl -s -o /dev/null -w "%{http_code}\n" "https://prootdyhspb.ru/$u"
done
```

Expected: `403` по каждому адресу.

- [ ] **Step 3: Проверить CSRF**

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST \
  -d "form=toggle&id=egypt&csrf=подделка" https://prootdyhspb.ru/upravlenie/index.php
```

Expected: `400`, и состояние акции `egypt` в `data/akcii.json` не изменилось.

- [ ] **Step 4: Проверить экранирование**

Через админку создать акцию с заголовком `<script>alert(1)</script>` и подписью `кавычка " и амперсанд &`. Открыть `/akcii.php`: на странице должен быть виден текст, в консоли браузера — ни одной ошибки, alert не срабатывает. В исходном коде страницы — `&lt;script&gt;`. После проверки акцию удалить.

- [ ] **Step 5: Проверить поведение при битом JSON**

Испортить боевой файл и убедиться, что страница поднимает бэкап, а не показывает ошибку:

```bash
curl -s --netrc-file ~/.netrc-prootdyh "ftp://server200.hosting.reg.ru/www/prootdyhspb.ru/data/akcii.json" -o /tmp/akcii-good.json
printf '{битый' > /tmp/akcii-broken.json
curl -s --netrc-file ~/.netrc-prootdyh -T /tmp/akcii-broken.json "ftp://server200.hosting.reg.ru/www/prootdyhspb.ru/data/akcii.json" -w "%{http_code}\n"
curl -s https://prootdyhspb.ru/akcii.php | grep -c ptile
```

Expected: последняя команда печатает число плиток из бэкапа (не `0`), в выводе нет слов `Warning`, `Fatal error`, `Parse error`.

Затем вернуть исходный файл:

```bash
curl -s --netrc-file ~/.netrc-prootdyh -T /tmp/akcii-good.json "ftp://server200.hosting.reg.ru/www/prootdyhspb.ru/data/akcii.json" -w "%{http_code}\n"
curl -s https://prootdyhspb.ru/akcii.php | grep -c ptile
```

Expected: `226`, затем `10`.

- [ ] **Step 6: Проверить ширину 390 px**

Открыть `/akcii.php` и `/upravlenie/` на ширине 390 px. Сетка плиток и строки списка не должны вызывать горизонтальную прокрутку; кнопки «Вверх», «Вниз», «Скрыть» на этой ширине переносятся, а не сжимаются в нечитаемое. Расхождения править в `style.css`, подняв `?v=` во всех файлах.

- [ ] **Step 7: Коммит**

```bash
git add robots.txt style.css
git commit -m "robots.txt и правки адаптива админки"
```

---

### Task 12: Переключение прода

**Files:**
- Modify: `.htaccess`
- Delete: `akcii.html`
- Delete: `_dev/` (на хостинге и в репозитории)

- [ ] **Step 1: Сверить страницы до переключения**

Открыть рядом `https://prootdyhspb.ru/akcii` (старая статика) и `https://prootdyhspb.ru/akcii.php` (новая). Сверить: десять плиток, их порядок, цвета, две широкие карточки, тексты бейджей, открытие каждой модалки. Пока есть расхождения — не переключать.

- [ ] **Step 2: Дать чистому URL находить .php**

В `.htaccess` заменить блок «`/page` → отдаём `page.html`» на вариант, который сначала ищет `.php`, потом `.html`:

```apache
# /page -> page.php, если есть; иначе page.html (расширение скрыто от посетителя)
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{DOCUMENT_ROOT}/$1.php -f
RewriteRule ^([a-z-]+)/?$ $1.php [L]

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{DOCUMENT_ROOT}/$1.html -f
RewriteRule ^([a-z-]+)/?$ $1.html [L]
```

Правило `/page.html → /page` выше по файлу остаётся без изменений; добавить рядом такое же для `.php`, чтобы `/akcii.php` в адресной строке уводил на `/akcii`:

```apache
RewriteCond %{THE_REQUEST} \s/+([a-z-]+)\.php[\s?] [NC]
RewriteRule ^ /%1 [R=301,L]
```

- [ ] **Step 3: Залить и сразу проверить**

Run: `bash tools/deploy-dev.sh .htaccess`
Expected: `226`

```bash
for p in / /akcii /poisk-tura /kruizy /tury-po-rossii /kompaniya /kontakty; do
  echo -n "$p: "; curl -s -o /dev/null -w "%{http_code}\n" "https://prootdyhspb.ru$p"
done
```

Expected: `200` по каждому адресу. Если хоть один отвечает `500` — немедленно вернуть прежний `.htaccess` из git и разбираться.

- [ ] **Step 4: Убрать старую статику**

Удалить `akcii.html` с хостинга (иначе он выиграет у `.php`) и из репозитория:

```bash
curl -s --netrc-file ~/.netrc-prootdyh "ftp://server200.hosting.reg.ru/www/prootdyhspb.ru/" \
  -Q "DELE /www/prootdyhspb.ru/akcii.html" -o /dev/null
git rm akcii.html
```

Проверить: `curl -s https://prootdyhspb.ru/akcii | grep -c ptile` → `10`.

- [ ] **Step 5: Убрать каталог разработки**

Удалить с хостинга `_dev/tests.php` и `_dev/.htaccess`, затем сам каталог. Тесты остаются в репозитории только если переезжают в отдельный каталог с собственным раннером; иначе удалить и из git — мёртвый код на проде не нужен.

Проверить: `curl -s -o /dev/null -w "%{http_code}\n" https://prootdyhspb.ru/_dev/tests.php` → `404`.

- [ ] **Step 6: Финальная проверка**

Пройти цикл на боевом адресе: войти в `/upravlenie`, создать акцию, увидеть её на `/akcii`, скрыть, показать, переместить, отредактировать, удалить. Затем hard reload `/akcii` (`ignoreCache`) — убедиться, что отдаётся не кеш.

- [ ] **Step 7: Коммит**

```bash
git add .htaccess
git rm -r --cached _dev
git commit -m "Страница акций переведена на PHP, старая статика убрана"
```

---

## Что остаётся человеку

- Передать заказчику адрес `/upravlenie` и пароль — пароль через файл для `curl --netrc-file`, не в переписку.
- Решить, показывать ли Оксане экран правки лично: интерфейс без подсказок, а поле «текст условий» — единственное, где легко испортить вёрстку длинным текстом.
- Проверить через неделю после передачи, что в `data/backups/` копятся версии и заказчик действительно правит акции сам.
