<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

function installDatabase(): void
{
    static $done = false;
    if ($done) return;

    $server = db(false);
    $name = str_replace('`', '``', DB_NAME);
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo = db(true);
    $sql = file_get_contents(__DIR__ . '/install.sql');
    if ($sql === false) throw new RuntimeException('Could not read database/install.sql.');

    // Remove CREATE DATABASE / USE because PDO is already connected to DB_NAME.
    $sql = preg_replace('/^\s*CREATE\s+DATABASE\b.*?;\s*/ims', '', $sql) ?? $sql;
    $sql = preg_replace('/^\s*USE\s+[^;]+;\s*/im', '', $sql) ?? $sql;
    // Remove SQL comment-only lines before splitting statements.
    $sql = preg_replace('/^\s*--.*(?:\r?\n|$)/m', '', $sql) ?? $sql;

    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    foreach ($statements as $index => $statement) {
        $statement = trim($statement);
        if ($statement === '') continue;
        try {
            $pdo->exec($statement);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Database schema statement ' . ($index + 1) . ' failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
    $done = true;
}

if (PHP_SAPI === 'cli') {
    installDatabase();
    fwrite(STDOUT, "Database ready: " . DB_NAME . PHP_EOL);
}
