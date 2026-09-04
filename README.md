# Library Management System

A secure, responsive Library Management System built with PHP, MySQL, HTML, CSS and JavaScript.

## Production deployment

This repository is Railway-ready:

- Root-level `Dockerfile` uses PHP 8.2 CLI + PDO MySQL.
- `docker-entrypoint.sh` waits for Railway MySQL, installs the schema once at container startup, then starts PHP.
- Application requests do **not** run database installation or migrations.
- Railway credentials are read from environment variables (`MYSQL_URL` or `MYSQLHOST`/`MYSQLPORT`/`MYSQLDATABASE`/`MYSQLUSER`/`MYSQLPASSWORD`).
- No production database password is stored in source code.
- PHP sessions use secure, HttpOnly, SameSite cookies on HTTPS.
- API responses are not cached; static assets are cached for faster repeat loads.
- PDO prepared statements and password hashing are used for authentication.

## Railway variable linking

For the App service, link the MySQL service variables. If the MySQL service is named `MySQL`, use:

`MYSQLHOST=${{MySQL.MYSQLHOST}}`
`MYSQLPORT=${{MySQL.MYSQLPORT}}`
`MYSQLDATABASE=${{MySQL.MYSQLDATABASE}}`
`MYSQLUSER=${{MySQL.MYSQLUSER}}`
`MYSQLPASSWORD=${{MySQL.MYSQLPASSWORD}}`

Use your actual MySQL service name if it differs.

## Local run

Set local `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASS` environment variables, then run:

`php -S localhost:8000 -t public public/router.php`

First-time Librarian setup is available at `/setup`.

## Main workflows

- Librarian: books, authors, categories, members, librarians, issues, returns, fines, reservations and reports.
- Member: browse books, reservations and personal issue/return information.
- Fine: overdue days × configured fine per day.
