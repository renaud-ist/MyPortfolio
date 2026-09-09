<?php

declare(strict_types=1);

require __DIR__ . '/common.php';

authApplyCors('POST');
authRequireMethod('POST');
authRequireJsonContentType();
$pdo = authDatabaseRequired();
$payload = authReadJson();
$email = isset($payload['email']) && is_string($payload['email']) ? trim($payload['email']) : '';
$password = isset($payload['password']) && is_string($payload['password']) ? $payload['password'] : '';

if ($email === '' || $password === '' || strlen($email) > API_AUTH_MAX_EMAIL_LENGTH || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    authResponse(['ok' => false, 'error' => 'Invalid credentials.'], 401);
}

$rateKey = hash('sha256', 'auth|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
$rateLimitSeconds = max(5, (int) (getenv('API_AUTH_LOGIN_RATE_LIMIT_SECONDS') ?: 10));
$dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llCzT8sH7qQ7N6P5Q5Q5O';

try {
    $pdo->beginTransaction();

    $rate = $pdo->prepare('SELECT last_submission FROM contact_rate_limits WHERE rate_key = :rate_key FOR UPDATE');
    $rate->execute([':rate_key' => $rateKey]);
    $lastAttempt = (int) ($rate->fetchColumn() ?: 0);
    if ($lastAttempt > 0 && (time() - $lastAttempt) < $rateLimitSeconds) {
        $pdo->rollBack();
        authResponse(['ok' => false, 'error' => 'Too many authentication attempts. Please try again later.'], 429);
    }

    $account = $pdo->prepare(
        'SELECT id, email, username, password_hash, role, is_active
         FROM admin_users
         WHERE email = :email
         LIMIT 1'
    );
    $account->execute([':email' => $email]);
    $admin = $account->fetch();
    $passwordValid = password_verify($password, $admin['password_hash'] ?? $dummyHash);

    $saveAttempt = $pdo->prepare(
        'INSERT INTO contact_rate_limits (rate_key, last_submission) VALUES (:rate_key, :last_submission)
         ON DUPLICATE KEY UPDATE last_submission = VALUES(last_submission)'
    );
    $saveAttempt->execute([':rate_key' => $rateKey, ':last_submission' => time()]);

    if (!$admin || (int) $admin['is_active'] !== 1 || !$passwordValid) {
        $pdo->commit();
        authResponse(['ok' => false, 'error' => 'Invalid credentials.'], 401);
    }

    if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
        $rehash = $pdo->prepare('UPDATE admin_users SET password_hash = :password_hash WHERE id = :id');
        $rehash->execute([
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':id' => $admin['id'],
        ]);
    }

    $rawToken = bin2hex(random_bytes(API_AUTH_TOKEN_BYTES));
    $tokenHash = hash('sha256', $rawToken);
    $ttl = max(60, (int) (getenv('API_AUTH_TOKEN_TTL_SECONDS') ?: 1800));
    $expiresAt = time() + $ttl;
    $expiresIso = gmdate('c', $expiresAt);

    $token = $pdo->prepare(
        'INSERT INTO admin_tokens
            (admin_user_id, token_hash, expires_at, ip_address, user_agent, created_at)
         VALUES (:admin_user_id, :token_hash, FROM_UNIXTIME(:expires_at), :ip_address, :user_agent, CURRENT_TIMESTAMP)'
    );
    $token->execute([
        ':admin_user_id' => $admin['id'],
        ':token_hash' => $tokenHash,
        ':expires_at' => $expiresAt,
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512) ?: null,
    ]);

    $login = $pdo->prepare('UPDATE admin_users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id');
    $login->execute([':id' => $admin['id']]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('API login failed: ' . $error->getMessage());
    authResponse(['ok' => false, 'error' => 'Authentication service is temporarily unavailable.'], 503);
}

authResponse([
    'ok' => true,
    'token' => $rawToken,
    'expires_at' => $expiresIso,
    'admin' => [
        'id' => (string) $admin['id'],
        'email' => $admin['email'],
        'username' => $admin['username'],
        'role' => $admin['role'],
    ],
]);
