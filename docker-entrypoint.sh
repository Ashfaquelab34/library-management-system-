#!/bin/sh
set -eu

echo "[LMS] Waiting for MySQL and preparing database..."

attempt=0
max_attempts=90

while :; do
    attempt=$((attempt + 1))

    if php /app/database/install.php; then
        echo "[LMS] Database is ready."
        break
    fi

    if [ "$attempt" -ge "$max_attempts" ]; then
        echo "[LMS] MySQL/database was not ready after ${max_attempts} attempts."
        exit 1
    fi

    echo "[LMS] MySQL not ready yet; retry ${attempt}/${max_attempts}..."
    sleep 2
done

echo "[LMS] Starting PHP application on 0.0.0.0:${PORT:-8080}"
exec php -d opcache.enable_cli=1 -S "0.0.0.0:${PORT:-8080}" -t /app/public /app/public/router.php
