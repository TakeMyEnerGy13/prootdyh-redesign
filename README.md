# Проотдых — редизайн 2026

Редизайн сайта турагентства «Проотдых». Статичный HTML+CSS без сборки.
Точка входа — `glavnaya.html` (на проде отдаётся по адресу `/` через `DirectoryIndex`).

Live: https://takemyenergy13.github.io/prootdyh-redesign/

Оригинальная версия (вёрстка по PSD): https://github.com/TakeMyEnerGy13/prootdyh

## Акции: данные и админка

Раздел «Акции» рендерится PHP-скриптом `akcii.php` из `data/akcii.json`.
Заказчик правит акции сам на `/upravlenie/` (пароль хранится хешем в
`data/config.php`, в репозиторий не попадает — образец рядом,
`data/config.sample.php`).

Каталоги `data/` и `lib/` закрыты `Require all denied`: там конфиг с хешем
пароля и служебный код. Перед каждой записью прежняя версия JSON уезжает в
`data/backups/`, хранятся десять последних.

### Тесты

Локального PHP в проекте нет — тесты гоняются на хостинге:

    bash tools/deploy-dev.sh _dev/tests.php _dev/.htaccess
    curl -s https://prootdyhspb.ru/_dev/tests.php

Ожидается `79 passed, 0 failed`. После прогона каталог `_dev/` с сервера
удаляется — на проде его быть не должно.
