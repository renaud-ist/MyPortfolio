<?php

declare(strict_types=1);

require dirname(__DIR__) . '/assets/php/db.php';

if (!$dbReady || !$pdo) {
    fwrite(STDERR, 'Database unavailable; reply API tests cannot run.' . PHP_EOL);
    exit(2);
}

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$origin = 'http://localhost:8080';
$marker = 'stage6-' . bin2hex(random_bytes(8));
$email = $marker . '@example.com';
$passwordHash = password_hash('unused-test-password', PASSWORD_DEFAULT);
$userAgent = $marker . '-agent';
$adminId = null;
$inactiveAdminId = null;
$conversationId = null;
$otherConversationId = null;
$tokenIds = [];
$triggerName = 'reply_api_test_failure_' . bin2hex(random_bytes(5));

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $message);
    }

    echo 'PASS ' . $message . PHP_EOL;
}

function request(string $url, string $method, ?string $body, string $userAgent, ?string $token = null, string $origin = 'http://localhost:8080', string $contentType = 'application/json'): array
{
    $headers = "Accept: application/json\r\nOrigin: {$origin}\r\nUser-Agent: {$userAgent}\r\n";
    if ($body !== null) {
        $headers .= "Content-Type: {$contentType}\r\n";
    }
    if ($token !== null) {
        $headers .= "Authorization: Bearer {$token}\r\n";
    }

    $options = ['http' => [
        'ignore_errors' => true,
        'method' => $method,
        'header' => $headers,
    ]];
    if ($body !== null) {
        $options['http']['content'] = $body;
    }

    $context = stream_context_create($options);
    $responseBody = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);

    return [(int) ($matches[1] ?? 0), $responseBody ?: '', $http_response_header ?? []];
}

function jsonRequest(string $url, array $payload, string $userAgent, ?string $token = null, string $origin = 'http://localhost:8080'): array
{
    return request($url, 'POST', json_encode($payload, JSON_THROW_ON_ERROR), $userAgent, $token, $origin);
}

function decode(string $body): array
{
    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : [];
}

function countReplies(PDO $pdo, int $conversationId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM conversation_messages WHERE conversation_id = :id AND sender_type = 'admin'");
    $stmt->execute([':id' => $conversationId]);

    return (int) $stmt->fetchColumn();
}

function countAuditReplies(PDO $pdo, int $messageId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'conversation.reply' AND entity_type = 'conversation_message' AND entity_id = :id");
    $stmt->execute([':id' => $messageId]);

    return (int) $stmt->fetchColumn();
}

$endpoint = $baseUrl . '/api/conversation/reply.php';

try {
    $createAdmin = $pdo->prepare(
        'INSERT INTO admin_users (username, email, password_hash, role, is_active)
         VALUES (:username, :email, :password_hash, :role, :is_active)'
    );
    $createAdmin->execute([
        ':username' => $marker,
        ':email' => $email,
        ':password_hash' => $passwordHash,
        ':role' => 'admin',
        ':is_active' => 1,
    ]);
    $adminId = (int) $pdo->lastInsertId();

    $createAdmin->execute([
        ':username' => $marker . '-inactive',
        ':email' => $marker . '-inactive@example.com',
        ':password_hash' => $passwordHash,
        ':role' => 'admin',
        ':is_active' => 0,
    ]);
    $inactiveAdminId = (int) $pdo->lastInsertId();

    $createConversation = $pdo->prepare(
        'INSERT INTO conversations (contact_name, contact_email, subject, status, created_at, updated_at, last_message_at)
         VALUES (:name, :email, :subject, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    );
    $createConversation->execute([
        ':name' => 'Reply recipient',
        ':email' => $marker . '-recipient@example.com',
        ':subject' => 'Reply test subject',
        ':status' => 'open',
    ]);
    $conversationId = (int) $pdo->lastInsertId();
    $createConversation->execute([
        ':name' => 'Other recipient',
        ':email' => $marker . '-other@example.com',
        ':subject' => 'Other conversation',
        ':status' => 'open',
    ]);
    $otherConversationId = (int) $pdo->lastInsertId();

    $rawToken = bin2hex(random_bytes(32));
    $insertToken = $pdo->prepare(
        'INSERT INTO admin_tokens (admin_user_id, token_hash, expires_at) VALUES (:admin_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE))'
    );
    $insertToken->execute([':admin_id' => $adminId, ':token_hash' => hash('sha256', $rawToken)]);
    $tokenIds[] = (int) $pdo->lastInsertId();

    [$status] = jsonRequest($endpoint . '?id=' . $conversationId, ['body' => 'No auth'], $userAgent . '-missing');
    check($status === 401, 'unauthenticated reply is rejected');
    [$status] = jsonRequest($endpoint . '?id=' . $conversationId, ['body' => 'Malformed auth'], $userAgent . '-malformed', 'not-a-bearer-token');
    check($status === 401, 'malformed authentication is rejected');
    [$status] = jsonRequest($endpoint . '?id=' . $conversationId, ['body' => 'Unknown auth'], $userAgent . '-unknown', str_repeat('a', 64));
    check($status === 401, 'unknown authentication is rejected');

    $expiredToken = bin2hex(random_bytes(32));
    $insertToken->execute([':admin_id' => $adminId, ':token_hash' => hash('sha256', $expiredToken)]);
    $tokenIds[] = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE admin_tokens SET expires_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE) WHERE id = :id')->execute([':id' => end($tokenIds)]);
    [$status] = jsonRequest($endpoint . '?id=' . $conversationId, ['body' => 'Expired auth'], $userAgent . '-expired', $expiredToken);
    check($status === 401, 'expired authentication is rejected');

    $revokedToken = bin2hex(random_bytes(32));
    $insertToken->execute([':admin_id' => $adminId, ':token_hash' => hash('sha256', $revokedToken)]);
    $tokenIds[] = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE admin_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE id = :id')->execute([':id' => end($tokenIds)]);
    [$status] = jsonRequest($endpoint . '?id=' . $conversationId, ['body' => 'Revoked auth'], $userAgent . '-revoked', $revokedToken);
    check($status === 401, 'revoked authentication is rejected');

    $inactiveToken = bin2hex(random_bytes(32));
    $insertToken->execute([':admin_id' => $inactiveAdminId, ':token_hash' => hash('sha256', $inactiveToken)]);
    $tokenIds[] = (int) $pdo->lastInsertId();
    [$status] = jsonRequest($endpoint . '?id=' . $conversationId, ['body' => 'Inactive auth'], $userAgent . '-inactive', $inactiveToken);
    check($status === 401, 'inactive administrator authentication is rejected');

    [$status, $body] = request($endpoint . '?id=' . $conversationId, 'OPTIONS', null, $userAgent . '-preflight', null, $origin);
    check($status === 204 && $body === '', 'reply preflight succeeds without a body');

    [$status] = jsonRequest($endpoint, ['body' => 'Missing id'], $userAgent . '-missing-id', $rawToken);
    check($status === 400, 'missing conversation id is rejected');
    [$status] = jsonRequest($endpoint . '?id=0', ['body' => 'Zero id'], $userAgent . '-zero-id', $rawToken);
    check($status === 400, 'zero conversation id is rejected');
    [$status] = jsonRequest($endpoint . '?id=-1', ['body' => 'Negative id'], $userAgent . '-negative-id', $rawToken);
    check($status === 400, 'negative conversation id is rejected');
    [$status] = jsonRequest($endpoint . '?id[]=1', ['body' => 'Array id'], $userAgent . '-array-id', $rawToken);
    check($status === 400, 'array conversation id is rejected');
    [$status] = jsonRequest($endpoint . '?id=999999999', ['body' => 'Missing conversation'], $userAgent . '-missing-conversation', $rawToken);
    check($status === 404, 'nonexistent conversation is rejected');
    [$status] = jsonRequest($endpoint . '?id=' . rawurlencode("1 OR 1=1"), ['body' => 'Injection id'], $userAgent . '-injection-id', $rawToken);
    check($status === 400, 'SQL injection-shaped id is rejected');
    [$status] = request($endpoint . '?id=' . $conversationId, 'POST', '{invalid', $userAgent . '-bad-json', $rawToken);
    check($status === 400, 'malformed JSON is rejected');
    [$status] = request($endpoint . '?id=' . $conversationId, 'POST', json_encode(['body' => 'Wrong content'], JSON_THROW_ON_ERROR), $userAgent . '-bad-content', $rawToken, $origin, 'application/x-www-form-urlencoded');
    check($status === 415, 'wrong Content-Type is rejected');
    [$status] = jsonRequest($endpoint . '?id=' . $conversationId, [], $userAgent . '-missing-body', $rawToken);
    check($status === 422, 'missing reply body is rejected');
    [$status] = jsonRequest($endpoint . '?id=' . $conversationId, ['body' => '   '], $userAgent . '-empty-body', $rawToken);
    check($status === 422, 'empty reply body is rejected');
    [$status] = jsonRequest($endpoint . '?id=' . $conversationId, ['body' => ['nested' => 'bad']], $userAgent . '-non-string-body', $rawToken);
    check($status === 422, 'non-string reply body is rejected');
    [$status] = jsonRequest($endpoint . '?id=' . $conversationId, ['body' => str_repeat('x', 9000)], $userAgent . '-oversized-body', $rawToken);
    check($status === 413, 'oversized request is rejected');
    [$status] = jsonRequest($endpoint . '?id=' . $conversationId, ['body' => 'Reply', 'admin_id' => $adminId], $userAgent . '-unsupported-field', $rawToken);
    check($status === 400, 'unsupported reply fields are rejected');

    $replyBody = "  {$marker} safe reply body  ";
    [$status, $responseBody, $headers] = jsonRequest($endpoint . '?id=' . $conversationId, ['body' => $replyBody], $userAgent . '-valid', $rawToken);
    $response = decode($responseBody);
    check($status === 201 && ($response['ok'] ?? false) === true, 'valid authenticated reply succeeds');
    check(in_array('Access-Control-Allow-Origin: ' . $origin, $headers, true), 'reply returns allowed CORS header');
    check(($response['data']['email']['status'] ?? '') === 'disabled', 'email delivery is explicitly disabled by default');
    check(!str_contains($responseBody, $rawToken) && !str_contains($responseBody, 'password_hash'), 'reply response excludes secrets');
    $messageId = (int) ($response['data']['message']['id'] ?? 0);
    check($messageId > 0, 'reply response contains a safe message id');
    check(($response['data']['message']['conversation_id'] ?? '') === (string) $conversationId, 'reply uses the URL conversation id');
    check(($response['data']['message']['sender_type'] ?? '') === 'admin', 'reply uses the established admin sender convention');
    check(($response['data']['message']['sender_admin_id'] ?? '') === (string) $adminId, 'reply records authenticated admin identity');
    check(($response['data']['message']['body'] ?? '') === trim($replyBody), 'reply body is trimmed but otherwise preserved');
    check(countReplies($pdo, $conversationId) === 1, 'reply is stored in the target conversation');
    check(countReplies($pdo, $otherConversationId) === 0, 'reply is not stored in another conversation');
    check(countAuditReplies($pdo, $messageId) === 1, 'reply creates an audit event');

    $notificationCount = (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    check($notificationCount === 0, 'reply creates no unsupported notification record');

    $beforeReplies = countReplies($pdo, $conversationId);
    $beforeAudit = countAuditReplies($pdo, $messageId);
    $pdo->exec(
        "CREATE TRIGGER {$triggerName}
         BEFORE INSERT ON audit_logs
         FOR EACH ROW
         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'intentional reply audit failure'"
    );
    [$status] = jsonRequest($endpoint . '?id=' . $conversationId, ['body' => $marker . ' rollback reply'], $userAgent . '-rollback', $rawToken);
    check($status === 503, 'audit failure returns a generic service error');
    check(countReplies($pdo, $conversationId) === $beforeReplies, 'failed reply transaction rolls back the message');
    check(countAuditReplies($pdo, $messageId) === $beforeAudit, 'failed reply transaction rolls back audit state');
} finally {
    try {
        $pdo->exec("DROP TRIGGER IF EXISTS {$triggerName}");
    } catch (Throwable) {
    }

    if ($conversationId !== null || $otherConversationId !== null) {
        $ids = array_values(array_filter([$conversationId, $otherConversationId], static fn (?int $id): bool => $id !== null));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM audit_logs WHERE entity_type IN ('conversation', 'conversation_message') AND entity_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM conversation_messages WHERE conversation_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM conversations WHERE id IN ({$placeholders})")->execute($ids);
    }
    if ($adminId !== null) {
        $pdo->prepare('DELETE FROM admin_tokens WHERE admin_user_id = :admin_id')->execute([':admin_id' => $adminId]);
        $pdo->prepare('DELETE FROM admin_users WHERE id = :admin_id')->execute([':admin_id' => $adminId]);
    }
    if ($inactiveAdminId !== null) {
        $pdo->prepare('DELETE FROM admin_tokens WHERE admin_user_id = :admin_id')->execute([':admin_id' => $inactiveAdminId]);
        $pdo->prepare('DELETE FROM admin_users WHERE id = :admin_id')->execute([':admin_id' => $inactiveAdminId]);
    }
}
