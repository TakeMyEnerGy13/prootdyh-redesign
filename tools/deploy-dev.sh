#!/usr/bin/env bash
# Заливает файлы на хостинг. Аргументы — пути относительно корня репозитория.
# Пример: tools/deploy-dev.sh _dev/tests.php lib/promos.php
set -u
HOST="ftp://server200.hosting.reg.ru/www/prootdyhspb.ru"
NETRC="$HOME/.netrc-prootdyh"
fail=0
for f in "$@"; do
  code=$(curl -s --netrc-file "$NETRC" --ftp-create-dirs -T "$f" "$HOST/$f" -w "%{http_code}")
  echo "$f -> $code"
  [ "$code" = "226" ] || fail=1
done
exit $fail
