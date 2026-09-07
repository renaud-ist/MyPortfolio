<?php
$appEnv = strtolower((string) (getenv('APP_ENV') ?: 'production'));
$appDebug = filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN);

if ($appEnv === 'production' || !$appDebug) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
}

$envPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';

if (is_readable($envPath)) {
    $envValues = parse_ini_file($envPath, false, INI_SCANNER_RAW);

    if (is_array($envValues)) {
        foreach ($envValues as $key => $value) {
            if (is_string($key) && is_scalar($value) && getenv($key) === false) {
                putenv($key . '=' . (string) $value);
            }
        }
    }
}

$appEnv = strtolower((string) (getenv('APP_ENV') ?: $appEnv));
$appDebug = filter_var(getenv('APP_DEBUG') ?: ($appDebug ? '1' : '0'), FILTER_VALIDATE_BOOLEAN);

if ($appEnv === 'production' || !$appDebug) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DB_PORT') ?: 3306);
$dbName = getenv('DB_NAME') ?: 'portfolio_db';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

$dbReady = false;
$dbError = null;

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );
    $dbReady = true;
} catch (PDOException $e) {
    $dbError = $e->getMessage();
    $pdo = null;
}
?>
