<?php

declare(strict_types=1);

require dirname(__DIR__) . '/assets/php/db.php';

if (!$dbReady || !$pdo) {
    fwrite(STDERR, 'Database unavailable; authentication tests cannot run.' . PHP_EOL);
    exit(2);
}

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$origin = 'http://localhost:8080';
$marker = 'phase3-' . bin2hex(random_bytes(8));
$email = $marker . '@example.com';
$inactiveEmail = $marker . '-inactive@example.com';
$password = 'Phase3-test-password-' . bin2hex(random_bytes(8));
$inactivePassword = 'Phase3-inactive-password-' . bin2hex(random_bytes(8));
$headersToCheck = [];
$adminIds = [];
$tokenHashes = [];
$rateKeys = [];
$authTestRateKeys = [];

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $message);
    }

    echo 'PASS ' . $message . PHP_EOL;
}

function request(string $url, string $method, ?string $body, string $userAgent, ?string $token = null, string $origin = 'http://localhost:8080', string $contentType = 'application/json'): array
{
    if (str_contains($url, '/api/auth/login.php')) {
        $GLOBALS['authTestRateKeys'][] = hash('sha256', 'auth|127.0.0.1|' . $userAgent);
    }

    $headers = "Accept: application/json\r\nOrigin: {$origin}\r\nUser-Agent: {$userAgent}\r\n";
    if ($body !== null) {
        $headers .= "Content-Type: {$contentType}\r\n";
    }
    if ($token !== null) {
        $headers .= "Authorization: Bearer {$token}\r\n";
    }

    $options = [
        'http' => [
            'ignore_errors' => true,
            'method' => $method,
            'header' => $headers,
        ],
    ];
    if ($body !== null) {
        $options['http']['content'] = $body;
    }

    $context = stream_context_create($options);
    $responseBody = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);

    return [(int) ($matches[1] ?? 0), $responseBody ?: '', $http_response_header ?? []];
}

function jsonRequest(string $url, string $method, array $payload, string $userAgent, ?string $token = null): array
{
    return request($url, $method, json_encode($payload, JSON_THROW_ON_ERROR), $userAgent, $token);
}

function decode(string $body): array
{
    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : [];
}

function countTokens(PDO $pdo, int $adminId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM admin_tokens WHERE admin_user_id = :admin_id');
    $stmt->execute([':admin_id' => $adminId]);

    return (int) $stmt->fetchColumn();
}

function findToken(PDO $pdo, string $hash): array|false
{
    $stmt = $pdo->prepare('SELECT id, token_hash, expires_at, revoked_at FROM admin_tokens WHERE token_hash = :token_hash');
    $stmt->execute([':token_hash' => $hash]);

    return $stmt->fetch();
}

$loginUrl = $baseUrl . '/api/auth/login.php';
$logoutUrl = $baseUrl . '/api/auth/logout.php';
$meUrl = $baseUrl . '/api/auth/me.php';

try {
    $create = $pdo->prepare(
        'INSERT INTO admin_users (username, email, password_hash, role, is_active)
         VALUES (:username, :email, :password_hash, :role, :is_active)'
    );
    $create->execute([
        ':username' => $marker,
        ':email' => $email,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':role' => 'admin',
        ':is_active' => 1,
    ]);
    $adminIds[] = (int) $pdo->lastInsertId();
    $create->execute([
        ':username' => $marker . '-inactive',
        ':email' => $inactiveEmail,
        ':password_hash' => password_hash($inactivePassword, PASSWORD_DEFAULT),
        ':role' => 'admin',
        ':is_active' => 0,
    ]);
    $adminIds[] = (int) $pdo->lastInsertId();

    [$status, $body] = jsonRequest($loginUrl, 'POST', ['email' => $email, 'password' => $password], $marker . '-valid');
    $success = decode($body);
    check($status === 200 && ($success['ok'] ?? false) === true, 'valid credentials succeed');
    check(isset($success['token'], $success['expires_at'], $success['admin']), 'successful login returns required fields');
    $token = (string) $success['token'];
    $tokenHashes[] = hash('sha256', $token);
    check(strlen($token) >= 64, 'returned token has sufficient encoded entropy');
    check(countTokens($pdo, $adminIds[0]) === 1, 'successful login creates one token record');
    $stored = findToken($pdo, hash('sha256', $token));
    check(is_array($stored) && $stored['token_hash'] !== $token, 'raw token is not stored in plaintext');
    check(strtotime((string) $stored['expires_at']) > time(), 'token has a future expiration time');
    check(!isset($success['admin']['password_hash'], $success['admin']['token_hash']), 'login response excludes secrets');

    [$status, $body] = jsonRequest($meUrl, 'GET', [], $marker . '-me', $token);
    $me = decode($body);
    check($status === 200 && ($me['ok'] ?? false) === true, 'valid bearer token authenticates');
    check(($me['admin']['email'] ?? '') === $email, 'authenticated identity is returned');
    check(!isset($me['admin']['password_hash'], $me['admin']['token_hash']), 'me response excludes secrets');

    [$status, $body] = jsonRequest($loginUrl, 'POST', ['email' => $email, 'password' => 'wrong-password'], $marker . '-wrong');
    check($status === 401 && decode($body)['error'] === 'Invalid credentials.', 'invalid password fails generically');
    [$status, $body] = jsonRequest($loginUrl, 'POST', ['email' => $marker . '-unknown@example.com', 'password' => 'wrong-password'], $marker . '-unknown');
    check($status === 401 && decode($body)['error'] === 'Invalid credentials.', 'unknown email fails generically');

    [$status] = request($loginUrl, 'POST', '{invalid', $marker . '-malformed');
    check($status === 400, 'malformed login JSON fails');
    [$status] = jsonRequest($loginUrl, 'POST', ['password' => $password], $marker . '-missing-email');
    check($status === 401, 'missing email fails');
    [$status] = jsonRequest($loginUrl, 'POST', ['email' => $email], $marker . '-missing-password');
    check($status === 401, 'missing password fails');
    [$status] = jsonRequest($loginUrl, 'POST', ['email' => $inactiveEmail, 'password' => $inactivePassword], $marker . '-inactive');
    check($status === 401, 'inactive admin cannot log in');
    [$status] = request($loginUrl, 'POST', json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR), $marker . '-wrong-content', null, $origin, 'application/x-www-form-urlencoded');
    check($status === 415, 'wrong login Content-Type fails');

    [$status] = request($meUrl, 'GET', null, $marker . '-missing');
    check($status === 401, 'missing Authorization header fails');
    [$status] = request($meUrl, 'GET', null, $marker . '-malformed-auth', 'not-a-bearer-token');
    check($status === 401, 'malformed Authorization header fails');
    [$status] = request($meUrl, 'GET', null, $marker . '-invalid-auth', str_repeat('a', 64));
    check($status === 401, 'invalid token fails');

    [$status] = request($logoutUrl, 'POST', '{}', $marker . '-unknown-logout', str_repeat('b', 64));
    check($status === 401, 'unknown token logout fails');

    [$status, $body] = request($loginUrl, 'OPTIONS', null, $marker . '-login-preflight', null, $origin);
    check($status === 204 && $body === '', 'login OPTIONS preflight succeeds without a body');

    $expiredToken = bin2hex(random_bytes(32));
    $expiredHash = hash('sha256', $expiredToken);
    $tokenInsert = $pdo->prepare(
        'INSERT INTO admin_tokens (admin_user_id, token_hash, expires_at) VALUES (:admin_id, :token_hash, DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE))'
    );
    $tokenInsert->execute([':admin_id' => $adminIds[0], ':token_hash' => $expiredHash]);
    $tokenHashes[] = $expiredHash;
    [$status] = request($meUrl, 'GET', null, $marker . '-expired', $expiredToken);
    check($status === 401, 'expired token fails');
    [$status] = request($logoutUrl, 'POST', '{}', $marker . '-expired-logout', $expiredToken);
    check($status === 401, 'expired token logout fails');

    $logoutToken = null;
    [$status, $body] = jsonRequest($loginUrl, 'POST', ['email' => $email, 'password' => $password], $marker . '-logout-login');
    if ($status === 200) {
        $logoutToken = (string) decode($body)['token'];
        $tokenHashes[] = hash('sha256', $logoutToken);
    }
    check($logoutToken !== null, 'logout test obtains a fresh token');
    [$status] = request($logoutUrl, 'POST', '{}', $marker . '-logout', $logoutToken);
    check($status === 200, 'valid token can be revoked');
    [$status] = request($meUrl, 'GET', null, $marker . '-revoked', $logoutToken);
    check($status === 401, 'revoked token cannot access me');
    [$status] = request($logoutUrl, 'POST', '{}', $marker . '-logout-again', $logoutToken);
    check($status === 200, 'revoked token repeated logout is safe');

    [$status] = request($meUrl, 'POST', null, $marker . '-wrong-method', $token);
    check($status === 405, 'me rejects unsupported methods');

    [$status, $body, $headersToCheck] = request($meUrl, 'GET', null, $marker . '-cors', $token, 'https://untrusted.example');
    check($status === 403, 'authentication endpoints reject untrusted origins');
} finally {
    if ($adminIds !== []) {
        $placeholders = implode(',', array_fill(0, count($adminIds), '?'));
        $deleteTokens = $pdo->prepare("DELETE FROM admin_tokens WHERE admin_user_id IN ({$placeholders})");
        $deleteTokens->execute($adminIds);
        $deleteAdmins = $pdo->prepare("DELETE FROM admin_users WHERE id IN ({$placeholders})");
        $deleteAdmins->execute($adminIds);
    }

    foreach ($rateKeys as $rateKey) {
        $deleteRate = $pdo->prepare('DELETE FROM contact_rate_limits WHERE rate_key = :rate_key');
        $deleteRate->execute([':rate_key' => $rateKey]);
    }

    foreach (array_unique($authTestRateKeys) as $rateKey) {
        $deleteRate = $pdo->prepare('DELETE FROM contact_rate_limits WHERE rate_key = :rate_key');
        $deleteRate->execute([':rate_key' => $rateKey]);
    }
}
