<?php

declare(strict_types=1);

require __DIR__ . '/common.php';

authApplyCors('POST');
authRequireMethod('POST');
$pdo = authDatabaseRequired();
$rawToken = authBearerToken();

if ($rawToken === null || $rawToken === '') {
    authResponse(['ok' => false, 'error' => 'Authentication required.'], 401);
}

try {
    $token = authFindToken($pdo, $rawToken);

    if ($token !== null) {
        $revoke = $pdo->prepare('UPDATE admin_tokens SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP) WHERE id = :id');
        $revoke->execute([':id' => $token['token_id']]);
        authResponse(['ok' => true]);
    }

    $tokenHash = hash('sha256', $rawToken);
    $revoked = $pdo->prepare(
        'SELECT admin_tokens.id
         FROM admin_tokens
         INNER JOIN admin_users ON admin_users.id = admin_tokens.admin_user_id
         WHERE admin_tokens.token_hash = :token_hash
           AND admin_tokens.revoked_at IS NOT NULL
           AND admin_tokens.expires_at > CURRENT_TIMESTAMP
           AND admin_users.is_active = 1
         LIMIT 1'
    );
    $revoked->execute([':token_hash' => $tokenHash]);

    if ($revoked->fetchColumn() !== false) {
        authResponse(['ok' => true]);
    }

    authResponse(['ok' => false, 'error' => 'Authentication required.'], 401);
} catch (Throwable $error) {
    error_log('API logout failed: ' . $error->getMessage());
    authResponse(['ok' => false, 'error' => 'Authentication service is temporarily unavailable.'], 503);
}
