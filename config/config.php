<?php
declare(strict_types=1);

/*
 * Library Management System - production-safe configuration.
 *
 * Railway:
 *   MYSQL_URL or MYSQLHOST/MYSQLPORT/MYSQLDATABASE/MYSQLUSER/MYSQLPASSWORD
 * Local:
 *   DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS
 *
 * Secrets are read from environment variables only; never hard-code
 * production credentials in this file.
 */

function envFirst(array $names, ?string $default = null): ?string
{
    foreach ($names as $name) {
        $value = getenv($name);
        if ($value !== false && $value !== '') return $value;
    }
    return $default;
}

$dbHost = envFirst(['MYSQLHOST', 'MYSQL_HOST', 'DB_HOST'], '127.0.0.1');
$dbPort = (int)envFirst(['MYSQLPORT', 'MYSQL_PORT', 'DB_PORT'], '3306');
$dbName = envFirst(['MYSQLDATABASE', 'MYSQL_DATABASE', 'DB_NAME'], 'library_management_system_2026');
$dbUser = envFirst(['MYSQLUSER', 'MYSQL_USER', 'DB_USER'], 'root');
$dbPass = envFirst(['MYSQLPASSWORD', 'MYSQL_PASSWORD', 'DB_PASS'], '');

/*
 * Prefer a complete Railway connection URL when available.
 */
$dbUrl = envFirst(['MYSQL_URL', 'DATABASE_URL']);
if ($dbUrl) {
    $parts = parse_url($dbUrl);
    if (is_array($parts)) {
        $dbHost = isset($parts['host']) ? (string)$parts['host'] : $dbHost;
        $dbPort = isset($parts['port']) ? (int)$parts['port'] : $dbPort;
        $dbUser = isset($parts['user']) ? urldecode((string)$parts['user']) : $dbUser;
        $dbPass = isset($parts['pass']) ? urldecode((string)$parts['pass']) : $dbPass;
        $dbName = isset($parts['path']) ? ltrim(urldecode((string)$parts['path']), '/') : $dbName;
    }
}

define('DB_HOST', $dbHost);
define('DB_PORT', $dbPort);
define('DB_NAME', $dbName);
define('DB_USER', $dbUser);
define('DB_PASS', $dbPass);

define('APP_NAME', 'Library Management System');
define('APP_URL', envFirst(['APP_URL'], 'http://localhost:8000'));
define('SESSION_NAME', 'library_management_session');
define('LOAN_DAYS', 14);
define('FINE_PER_DAY', 5.00);

date_default_timezone_set('Asia/Kolkata');

/*
 * Secure PHP session cookie. HTTPS is detected automatically so local HTTP
 * development continues to work.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

function db(bool $withDatabase = true): PDO
{
    static $pdoWithDb = null;
    static $pdoServer = null;

    if ($withDatabase && $pdoWithDb instanceof PDO) return $pdoWithDb;
    if (!$withDatabase && $pdoServer instanceof PDO) return $pdoServer;

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT
        . ($withDatabase ? ';dbname=' . DB_NAME : '')
        . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]);
        if ($withDatabase) $pdoWithDb = $pdo;
        else $pdoServer = $pdo;
        return $pdo;
    } catch (PDOException $e) {
        if (PHP_SAPI === 'cli') throw $e;
        throw new RuntimeException('Database connection failed.', 0, $e);
    }
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestJson(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return $_POST ?: [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function clean(string $value): string { return trim($value); }

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function requireCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        jsonResponse(['ok' => false, 'message' => 'Security token expired. Refresh the page and try again.'], 419);
    }
}

function currentUser(): ?array { return $_SESSION['user'] ?? null; }

function requireLogin(?string $role = null): array
{
    $u = currentUser();
    if (!$u) jsonResponse(['ok' => false, 'message' => 'Please sign in first.'], 401);
    if ($role !== null && ($u['role'] ?? '') !== $role) {
        jsonResponse(['ok' => false, 'message' => 'You do not have permission for this action.'], 403);
    }
    return $u;
}

function logAction(int $userId, string $action, string $entity, ?int $entityId = null, ?string $details = null): void
{
    try {
        $s = db()->prepare('INSERT INTO audit_logs(user_id,action,entity,entity_id,details) VALUES(?,?,?,?,?)');
        $s->execute([$userId, $action, $entity, $entityId, $details]);
    } catch (Throwable $e) {
        // Audit failure must never break a core transaction.
    }
}
