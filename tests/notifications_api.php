<?php

declare(strict_types=1);

require dirname(__DIR__) . '/assets/php/db.php';

if (!$dbReady || !$pdo) {
    fwrite(STDERR, 'Database unavailable; notification API tests cannot run.' . PHP_EOL);
    exit(2);
}

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$origin = 'http://localhost:8080';
$marker = 'stage7-' . bin2hex(random_bytes(8));
$email = $marker . '@example.com';
$adminId = null;
$inactiveAdminId = null;
$conversationId = null;
$messageId = null;
$notificationIds = [];

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $message);
    }

    echo 'PASS ' . $message . PHP_EOL;
}

function request(string $url, string $method, ?string $body, string $userAgent, ?string $token = null, string $origin = 'http://localhost:8080'): array
{
    $headers = "Accept: application/json\r\nOrigin: {$origin}\r\nUser-Agent: {$userAgent}\r\n";
    if ($token !== null) {
        $headers .= "Authorization: Bearer {$token}\r\n";
    }
    if ($body !== null) {
        $headers .= "Content-Type: application/json\r\n";
    }
    $options = ['http' => ['ignore_errors' => true, 'method' => $method, 'header' => $headers]];
    if ($body !== null) {
        $options['http']['content'] = $body;
    }
    $context = stream_context_create($options);
    $responseBody = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);

    return [(int) ($matches[1] ?? 0), $responseBody ?: '', $http_response_header ?? []];
}

function decode(string $body): array
{
    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : [];
}

$listUrl = $baseUrl . '/api/notifications.php';

try {
    $admin = $pdo->prepare(
        'INSERT INTO admin_users (username, email, password_hash, role, is_active)
         VALUES (:username, :email, :password_hash, :role, :is_active)'
    );
    $admin->execute([
        ':username' => $marker,
        ':email' => $email,
        ':password_hash' => password_hash('unused', PASSWORD_DEFAULT),
        ':role' => 'admin',
        ':is_active' => 1,
    ]);
    $adminId = (int) $pdo->lastInsertId();
    $admin->execute([
        ':username' => $marker . '-inactive',
        ':email' => $marker . '-inactive@example.com',
        ':password_hash' => password_hash('unused', PASSWORD_DEFAULT),
        ':role' => 'admin',
        ':is_active' => 0,
    ]);
    $inactiveAdminId = (int) $pdo->lastInsertId();

    $conversation = $pdo->prepare(
        'INSERT INTO conversations (contact_name, contact_email, subject, status, created_at, updated_at, last_message_at)
         VALUES (:name, :email, :subject, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    );
    $conversation->execute([
        ':name' => 'Notification visitor',
        ':email' => $marker . '-visitor@example.com',
        ':subject' => 'Notification test',
        ':status' => 'open',
    ]);
    $conversationId = (int) $pdo->lastInsertId();

    $message = $pdo->prepare(
        'INSERT INTO conversation_messages (conversation_id, sender_type, sender_name, sender_email, body, created_at)
         VALUES (:conversation_id, :sender_type, :sender_name, :sender_email, :body, CURRENT_TIMESTAMP)'
    );
    $message->execute([
        ':conversation_id' => $conversationId,
        ':sender_type' => 'visitor',
        ':sender_name' => 'Notification visitor',
        ':sender_email' => $marker . '-visitor@example.com',
        ':body' => $marker . ' notification message',
    ]);
    $messageId = (int) $pdo->lastInsertId();

    $rawToken = bin2hex(random_bytes(32));
    $token = $pdo->prepare(
        'INSERT INTO admin_tokens (admin_user_id, token_hash, expires_at)
         VALUES (:admin_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE))'
    );
    $token->execute([':admin_id' => $adminId, ':token_hash' => hash('sha256', $rawToken)]);

    $notification = $pdo->prepare(
        'INSERT INTO notifications
            (conversation_id, conversation_message_id, recipient_type, notification_type, status, created_at, updated_at)
         VALUES (:conversation_id, :message_id, :recipient_type, :notification_type, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    );
    $notification->execute([
        ':conversation_id' => $conversationId,
        ':message_id' => $messageId,
        ':recipient_type' => 'admin',
        ':notification_type' => 'new_contact_message',
        ':status' => 'pending',
    ]);
    $notificationIds[] = (int) $pdo->lastInsertId();

    $notification->execute([
        ':conversation_id' => $conversationId,
        ':message_id' => $messageId,
        ':recipient_type' => 'other-recipient-convention',
        ':notification_type' => 'new_contact_message',
        ':status' => 'pending',
    ]);
    $notificationIds[] = (int) $pdo->lastInsertId();

    [$status] = request($listUrl, 'GET', null, $marker . '-missing');
    check($status === 401, 'unauthenticated notification access is rejected');
    [$status] = request($listUrl, 'GET', null, $marker . '-malformed', 'not-a-bearer-token');
    check($status === 401, 'malformed authentication is rejected');
    [$status] = request($listUrl, 'GET', null, $marker . '-invalid', str_repeat('a', 64));
    check($status === 401, 'invalid authentication is rejected');

    $expiredRaw = bin2hex(random_bytes(32));
    $insertExpired = $pdo->prepare('INSERT INTO admin_tokens (admin_user_id, token_hash, expires_at) VALUES (:admin_id, :token_hash, DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE))');
    $insertExpired->execute([':admin_id' => $adminId, ':token_hash' => hash('sha256', $expiredRaw)]);
    [$status] = request($listUrl, 'GET', null, $marker . '-expired', $expiredRaw);
    check($status === 401, 'expired authentication is rejected');

    $revokedRaw = bin2hex(random_bytes(32));
    $insertRevoked = $pdo->prepare('INSERT INTO admin_tokens (admin_user_id, token_hash, expires_at, revoked_at) VALUES (:admin_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE), CURRENT_TIMESTAMP)');
    $insertRevoked->execute([':admin_id' => $adminId, ':token_hash' => hash('sha256', $revokedRaw)]);
    [$status] = request($listUrl, 'GET', null, $marker . '-revoked', $revokedRaw);
    check($status === 401, 'revoked authentication is rejected');

    $inactiveRaw = bin2hex(random_bytes(32));
    $insertRevoked->execute([':admin_id' => $inactiveAdminId, ':token_hash' => hash('sha256', $inactiveRaw)]);
    [$status] = request($listUrl, 'GET', null, $marker . '-inactive', $inactiveRaw);
    check($status === 401, 'inactive administrator authentication is rejected');

    [$status, $body, $headers] = request($listUrl, 'GET', null, $marker . '-valid', $rawToken);
    $response = decode($body);
    check($status === 200 && ($response['ok'] ?? false) === true, 'authenticated notification listing succeeds');
    check(in_array('Access-Control-Allow-Origin: ' . $origin, $headers, true), 'trusted origin receives CORS header');
    check(count($response['data'] ?? []) === 1, 'listing is scoped to the existing admin recipient convention');
    check(($response['data'][0]['notification_type'] ?? '') === 'new_contact_message', 'existing notification type is preserved');
    check(($response['data'][0]['status'] ?? '') === 'pending', 'existing notification status is preserved');
    check(($response['data'][0]['conversation_id'] ?? '') === (string) $conversationId, 'conversation reference is returned safely');
    check(($response['read_state_supported'] ?? true) === false, 'read state limitation is explicit');
    check(!str_contains($body, 'token_hash') && !str_contains($body, 'password_hash') && !str_contains($body, $rawToken), 'notification response excludes secrets');

    [$status, $body] = request($listUrl . '?per_page=1&page=1', 'GET', null, $marker . '-page', $rawToken);
    $page = decode($body);
    check($status === 200 && ($page['pagination']['per_page'] ?? null) === 1, 'bounded pagination succeeds');
    [$status] = request($listUrl . '?per_page=101', 'GET', null, $marker . '-max', $rawToken);
    check($status === 400, 'per_page maximum is enforced');
    [$status] = request($listUrl . '?page=0', 'GET', null, $marker . '-page-zero', $rawToken);
    check($status === 400, 'invalid page is rejected');
    [$status] = request($listUrl . '?notification_type=unsupported', 'GET', null, $marker . '-type', $rawToken);
    check($status === 400, 'unsupported notification type is rejected');
    [$status] = request($listUrl . '?status=unsupported', 'GET', null, $marker . '-status', $rawToken);
    check($status === 400, 'unsupported notification status is rejected');
    [$status] = request($listUrl . '?id=' . rawurlencode("1 OR 1=1"), 'GET', null, $marker . '-injection', $rawToken);
    check($status === 200, 'unrecognized query fields do not alter notification SQL');

    [$status, $body] = request($listUrl, 'OPTIONS', null, $marker . '-preflight', null, $origin);
    check($status === 204 && $body === '', 'notification preflight succeeds without a body');
} finally {
    if ($notificationIds !== []) {
        $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
        $pdo->prepare("DELETE FROM notifications WHERE id IN ({$placeholders})")->execute($notificationIds);
    }
    if ($messageId !== null) {
        $pdo->prepare('DELETE FROM conversation_messages WHERE id = :id')->execute([':id' => $messageId]);
    }
    if ($conversationId !== null) {
        $pdo->prepare('DELETE FROM conversations WHERE id = :id')->execute([':id' => $conversationId]);
    }
    if ($adminId !== null) {
        $pdo->prepare('DELETE FROM admin_tokens WHERE admin_user_id = :id')->execute([':id' => $adminId]);
        $pdo->prepare('DELETE FROM admin_users WHERE id = :id')->execute([':id' => $adminId]);
    }
    if ($inactiveAdminId !== null) {
        $pdo->prepare('DELETE FROM admin_tokens WHERE admin_user_id = :id')->execute([':id' => $inactiveAdminId]);
        $pdo->prepare('DELETE FROM admin_users WHERE id = :id')->execute([':id' => $inactiveAdminId]);
    }
}
