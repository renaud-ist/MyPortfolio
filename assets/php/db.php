<?php
declare(strict_types=1);

$envPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';

$envValues = [];

if (is_readable($envPath)) {
    $parsedEnv = parse_ini_file($envPath, false, INI_SCANNER_RAW);

    if (is_array($parsedEnv)) {
        $envValues = $parsedEnv;

        // Populate the process environment only when a variable
        // has not already been provided by the server.
        foreach ($envValues as $key => $value) {
            if (
                is_string($key)
                && is_scalar($value)
                && getenv($key) === false
            ) {
                putenv($key . '=' . (string) $value);
            }
        }
    }
}

/*
 * Prefer values explicitly loaded from the application's .env file.
 * Fall back to server environment variables, then safe defaults.
 */
$getEnvValue = static function (string $key, string $default = '') use ($envValues): string {
    if (array_key_exists($key, $envValues) && is_scalar($envValues[$key])) {
        return (string) $envValues[$key];
    }

    $environmentValue = getenv($key);

    if ($environmentValue !== false) {
        return (string) $environmentValue;
    }

    return $default;
};

$appEnv = strtolower($getEnvValue('APP_ENV', 'production'));
$appDebug = filter_var(
    $getEnvValue('APP_DEBUG', '0'),
    FILTER_VALIDATE_BOOLEAN
);

if ($appEnv === 'production' || !$appDebug) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
}

$host = $getEnvValue('DB_HOST', '127.0.0.1');
$port = (int) $getEnvValue('DB_PORT', '3306');
$dbName = $getEnvValue('DB_NAME', 'portfolio_db');
$dbUser = $getEnvValue('DB_USER', 'root');
$dbPass = $getEnvValue('DB_PASS', '');

$dbReady = false;
$dbError = null;
$pdo = null;

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
}