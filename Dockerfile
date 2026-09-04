FROM php:8.2-cli

RUN docker-php-ext-install pdo pdo_mysql     && docker-php-ext-enable opcache

WORKDIR /app

COPY . /app

RUN chmod +x /app/docker-entrypoint.sh     && printf '%s\n'        'opcache.enable_cli=1'        'opcache.validate_timestamps=0'        'opcache.memory_consumption=128'        'opcache.max_accelerated_files=10000'        > /usr/local/etc/php/conf.d/99-performance.ini

ENV PORT=8080

EXPOSE 8080

ENTRYPOINT ["/app/docker-entrypoint.sh"]
