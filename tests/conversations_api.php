<?php

declare(strict_types=1);

require dirname(__DIR__) . '/assets/php/db.php';

if (!$dbReady || !$pdo) {
    fwrite(STDERR, 'Database unavailable; conversation API tests cannot run.' . PHP_EOL);
    exit(2);
}

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$origin = 'http://localhost:8080';
$marker = 'phase4-' . bin2hex(random_bytes(8));
$email = $marker . '@example.com';
$password = 'Phase4-password-' . bin2hex(random_bytes(8));
$userAgent = $marker . '-agent';
$adminId = null;
$tokenHash = null;
$conversationIds = [];
$messageIds = [];
$auditIds = [];

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

function jsonRequest(string $url, string $method, array $payload, string $userAgent, ?string $token = null, string $origin = 'http://localhost:8080'): array
{
    return request($url, $method, json_encode($payload, JSON_THROW_ON_ERROR), $userAgent, $token, $origin);
}

function decode(string $body): array
{
    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : [];
}

function createConversation(PDO $pdo, string $marker, string $name, string $status, bool $read, bool $archived, int $createdOffset): array
{
    $timestamp = date('Y-m-d H:i:s', time() + $createdOffset);
    $conversation = $pdo->prepare(
        'INSERT INTO conversations
            (contact_name, contact_email, subject, status, read_at, archived_at, created_at, updated_at, last_message_at)
         VALUES (:name, :email, :subject, :status, :read_at, :archived_at, :created_at, :updated_at, :last_message_at)'
    );
    $conversation->execute([
        ':name' => $name,
        ':email' => $marker . '-' . strtolower($name) . '@example.com',
        ':subject' => 'Phase 4 test',
        ':status' => $status,
        ':read_at' => $read ? $timestamp : null,
        ':archived_at' => $archived ? $timestamp : null,
        ':created_at' => $timestamp,
        ':updated_at' => $timestamp,
        ':last_message_at' => $timestamp,
    ]);
    $conversationId = (int) $pdo->lastInsertId();

    $message = $pdo->prepare(
        'INSERT INTO conversation_messages
            (conversation_id, sender_type, sender_name, sender_email, body, read_at, created_at)
         VALUES (:conversation_id, :sender_type, :sender_name, :sender_email, :body, :read_at, :created_at)'
    );
    $message->execute([
        ':conversation_id' => $conversationId,
        ':sender_type' => 'visitor',
        ':sender_name' => $name,
        ':sender_email' => $marker . '-' . strtolower($name) . '@example.com',
        ':body' => $marker . ' message for ' . $name,
        ':read_at' => $read ? $timestamp : null,
        ':created_at' => $timestamp,
    ]);
    $messageId = (int) $pdo->lastInsertId();

    return [$conversationId, $messageId];
}

function bodyContainsSecret(array $payload): bool
{
    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

    return str_contains($encoded, 'password_hash') || str_contains($encoded, 'token_hash') || str_contains($encoded, 'Bearer ');
}

$listUrl = $baseUrl . '/api/conversations.php';
$detailUrl = $baseUrl . '/api/conversation.php';

try {
    $admin = $pdo->prepare(
        'INSERT INTO admin_users (username, email, password_hash, role, is_active)
         VALUES (:username, :email, :password_hash, :role, 1)'
    );
    $admin->execute([
        ':username' => $marker,
        ':email' => $email,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':role' => 'admin',
    ]);
    $adminId = (int) $pdo->lastInsertId();

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $token = $pdo->prepare(
        'INSERT INTO admin_tokens (admin_user_id, token_hash, expires_at, created_at)
         VALUES (:admin_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE), CURRENT_TIMESTAMP)'
    );
    $token->execute([':admin_id' => $adminId, ':token_hash' => $tokenHash]);

    [$conversationIds[0], $messageIds[0]] = createConversation($pdo, $marker, 'Alpha', 'open', false, false, 0);
    [$conversationIds[1], $messageIds[1]] = createConversation($pdo, $marker, 'Beta', 'phase4-test', true, false, -60);
    [$conversationIds[2], $messageIds[2]] = createConversation($pdo, $marker, 'Gamma', 'open', false, true, -120);
    $olderMessage = $pdo->prepare(
        'INSERT INTO conversation_messages
            (conversation_id, sender_type, sender_name, sender_email, body, created_at)
         VALUES (:conversation_id, :sender_type, :sender_name, :sender_email, :body, :created_at)'
    );
    $olderMessage->execute([
        ':conversation_id' => $conversationIds[0],
        ':sender_type' => 'visitor',
        ':sender_name' => 'Alpha',
        ':sender_email' => $marker . '-alpha@example.com',
        ':body' => $marker . ' older Alpha message',
        ':created_at' => date('Y-m-d H:i:s', time() - 1),
    ]);

    [$status] = request($listUrl, 'GET', null, $userAgent . '-no-auth');
    check($status === 401, 'list without Authorization is rejected');
    [$status] = request($detailUrl . '?id=' . $conversationIds[0], 'GET', null, $userAgent . '-no-auth-detail');
    check($status === 401, 'detail without Authorization is rejected');
    [$status] = jsonRequest($detailUrl . '?id=' . $conversationIds[0], 'PATCH', ['is_read' => true], $userAgent . '-no-auth-patch');
    check($status === 401, 'patch without Authorization is rejected');
    [$status] = request($listUrl, 'GET', null, $userAgent . '-malformed', 'not-a-bearer-token');
    check($status === 401, 'malformed bearer token is rejected');
    [$status] = request($listUrl, 'GET', null, $userAgent . '-invalid', str_repeat('a', 64));
    check($status === 401, 'invalid bearer token is rejected');

    $expiredRaw = bin2hex(random_bytes(32));
    $expiredHash = hash('sha256', $expiredRaw);
    $expired = $pdo->prepare(
        'INSERT INTO admin_tokens (admin_user_id, token_hash, expires_at) VALUES (:admin_id, :token_hash, DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE))'
    );
    $expired->execute([':admin_id' => $adminId, ':token_hash' => $expiredHash]);
    [$status] = request($listUrl, 'GET', null, $userAgent . '-expired', $expiredRaw);
    check($status === 401, 'expired bearer token is rejected');

    $revokedRaw = bin2hex(random_bytes(32));
    $revokedHash = hash('sha256', $revokedRaw);
    $revoked = $pdo->prepare(
        'INSERT INTO admin_tokens (admin_user_id, token_hash, expires_at, revoked_at) VALUES (:admin_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE), CURRENT_TIMESTAMP)'
    );
    $revoked->execute([':admin_id' => $adminId, ':token_hash' => $revokedHash]);
    [$status] = request($listUrl, 'GET', null, $userAgent . '-revoked', $revokedRaw);
    check($status === 401, 'revoked bearer token is rejected');

    [$status, $body, $headers] = request($listUrl, 'GET', null, $userAgent . '-list', $rawToken);
    $list = decode($body);
    check($status === 200 && ($list['ok'] ?? false) === true, 'authenticated conversation list succeeds');
    check(($list['pagination']['page'] ?? null) === 1 && ($list['pagination']['per_page'] ?? null) === 20, 'list defaults to page one and twenty items');
    check(count($list['data'] ?? []) >= 3, 'conversation list returns seeded conversations');
    check(in_array('Access-Control-Allow-Origin: ' . $origin, $headers, true), 'conversation list returns allowed CORS header');
    check(!bodyContainsSecret($list), 'conversation list excludes authentication secrets');
    check((string) $list['data'][0]['id'] === (string) $conversationIds[0], 'conversation list ordering is deterministic and recent-first');

    [$status, $body] = request($listUrl . '?per_page=1&page=2', 'GET', null, $userAgent . '-pagination', $rawToken);
    $pageTwo = decode($body);
    check($status === 200 && count($pageTwo['data'] ?? []) === 1, 'pagination boundary returns one item');
    [$status] = request($listUrl . '?per_page=101', 'GET', null, $userAgent . '-max', $rawToken);
    check($status === 400, 'per_page above maximum is rejected');
    [$status] = request($listUrl . '?page=0', 'GET', null, $userAgent . '-page-zero', $rawToken);
    check($status === 400, 'invalid page is rejected');
    [$status] = request($listUrl . '?per_page=not-number', 'GET', null, $userAgent . '-per-page-invalid', $rawToken);
    check($status === 400, 'invalid per_page is rejected');
    [$status, $body] = request($listUrl . '?status=phase4-test&is_read=1&is_archived=0', 'GET', null, $userAgent . '-filters', $rawToken);
    $filtered = decode($body);
    check($status === 200 && count($filtered['data'] ?? []) === 1 && (string) $filtered['data'][0]['id'] === (string) $conversationIds[1], 'schema-backed filters work');
    [$status, $body] = request($listUrl . '?status=phase4-test&is_archived=1', 'GET', null, $userAgent . '-empty', $rawToken);
    check($status === 200 && ($body !== '') && count(decode($body)['data'] ?? []) === 0, 'authenticated empty filtered list succeeds');
    [$status] = request($listUrl . '?status=not-a-real-status', 'GET', null, $userAgent . '-bad-filter', $rawToken);
    check($status === 400, 'invalid status filter is rejected');
    [$status] = request($listUrl . '?is_read=yes', 'GET', null, $userAgent . '-bad-read-filter', $rawToken);
    check($status === 400, 'invalid read filter is rejected');
    [$status] = request($listUrl . '?status=' . rawurlencode("1' OR '1'='1"), 'GET', null, $userAgent . '-injection-filter', $rawToken);
    check($status === 400, 'injection-style filter input is rejected safely');

    [$status, $body] = request($detailUrl . '?id=' . $conversationIds[0], 'GET', null, $userAgent . '-detail', $rawToken);
    $detail = decode($body);
    check($status === 200 && ($detail['ok'] ?? false) === true, 'conversation detail succeeds');
    check((string) ($detail['data']['id'] ?? '') === (string) $conversationIds[0], 'detail returns requested conversation');
    $detailMessages = $detail['data']['messages'] ?? [];
    check(count($detailMessages) === 2, 'detail returns conversation messages');
    check(($detailMessages[0]['created_at'] ?? '') <= ($detailMessages[1]['created_at'] ?? ''), 'conversation messages are chronological');
    check(str_contains((string) ($detailMessages[0]['body'] ?? ''), $marker) && !str_contains((string) ($detailMessages[0]['body'] ?? ''), 'Beta'), 'detail does not leak another conversation');
    check(!bodyContainsSecret($detail), 'conversation detail excludes authentication secrets');
    [$status] = request($detailUrl . '?id=999999999', 'GET', null, $userAgent . '-missing-detail', $rawToken);
    check($status === 404, 'nonexistent conversation returns 404');
    [$status] = request($detailUrl . '?id=1abc', 'GET', null, $userAgent . '-invalid-detail', $rawToken);
    check($status === 400, 'invalid conversation ID returns 400');
    [$status] = request($detailUrl . '?id=1%20OR%201%3D1', 'GET', null, $userAgent . '-injection-detail', $rawToken);
    check($status === 400, 'injection-style conversation ID returns 400');

    [$status, $body] = jsonRequest($detailUrl . '?id=' . $conversationIds[0], 'PATCH', ['is_read' => true], $userAgent . '-read', $rawToken);
    $patched = decode($body);
    check($status === 200 && ($patched['data']['is_read'] ?? false) === true, 'read state update succeeds');
    [$status] = jsonRequest($detailUrl . '?id=' . $conversationIds[0], 'PATCH', ['is_read' => false], $userAgent . '-unread', $rawToken);
    check($status === 200, 'unread state update succeeds');
    [$status, $body] = jsonRequest($detailUrl . '?id=' . $conversationIds[0], 'PATCH', ['is_archived' => true], $userAgent . '-archive', $rawToken);
    check($status === 200 && (decode($body)['data']['is_archived'] ?? false) === true, 'archive state update succeeds');
    [$status] = jsonRequest($detailUrl . '?id=' . $conversationIds[0], 'PATCH', ['is_archived' => false], $userAgent . '-restore', $rawToken);
    check($status === 200, 'restore state update succeeds');
    [$status, $body] = jsonRequest($detailUrl . '?id=' . $conversationIds[0], 'PATCH', ['status' => 'phase4-test'], $userAgent . '-status', $rawToken);
    check($status === 200 && (decode($body)['data']['status'] ?? '') === 'phase4-test', 'existing status update succeeds');
    [$status] = jsonRequest($detailUrl . '?id=' . $conversationIds[0], 'PATCH', ['status' => 'invented-status'], $userAgent . '-bad-status', $rawToken);
    check($status === 422, 'invalid status update is rejected');
    [$status] = jsonRequest($detailUrl . '?id=' . $conversationIds[0], 'PATCH', ['is_read' => 'true'], $userAgent . '-bad-type', $rawToken);
    check($status === 422, 'invalid state type is rejected');
    [$status] = jsonRequest($detailUrl . '?id=' . $conversationIds[0], 'PATCH', ['message' => 'changed'], $userAgent . '-bad-field', $rawToken);
    check($status === 400, 'unsupported field is rejected');
    [$status] = request($detailUrl . '?id=' . $conversationIds[0], 'PATCH', '{invalid', $userAgent . '-bad-json', $rawToken);
    check($status === 400, 'malformed patch JSON is rejected');
    [$status] = request($detailUrl . '?id=' . $conversationIds[0], 'PATCH', json_encode(['is_read' => true], JSON_THROW_ON_ERROR), $userAgent . '-bad-content', $rawToken, $origin, 'application/x-www-form-urlencoded');
    check($status === 415, 'wrong patch Content-Type is rejected');
    [$status] = jsonRequest($detailUrl . '?id=999999999', 'PATCH', ['is_read' => true], $userAgent . '-missing-patch', $rawToken);
    check($status === 404, 'patch nonexistent conversation returns 404');
    [$status, $body] = jsonRequest($detailUrl . '?id=' . $conversationIds[0], 'PATCH', ['is_read' => true], $userAgent . '-idempotent-one', $rawToken);
    $firstPatch = decode($body);
    [$status, $body] = jsonRequest($detailUrl . '?id=' . $conversationIds[0], 'PATCH', ['is_read' => true], $userAgent . '-idempotent-two', $rawToken);
    $secondPatch = decode($body);
    check($status === 200 && ($firstPatch['data']['is_read'] ?? false) === ($secondPatch['data']['is_read'] ?? false), 'repeated identical update is idempotent');

    $audit = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE admin_user_id = :admin_id AND entity_type = :entity_type AND entity_id = :entity_id');
    $audit->execute([':admin_id' => $adminId, ':entity_type' => 'conversation', ':entity_id' => $conversationIds[0]]);
    check((int) $audit->fetchColumn() >= 1, 'conversation mutation records an audit event');
} finally {
    if ($conversationIds !== []) {
        $placeholders = implode(',', array_fill(0, count($conversationIds), '?'));
        $deleteNotifications = $pdo->prepare("DELETE FROM notifications WHERE conversation_id IN ({$placeholders})");
        $deleteNotifications->execute($conversationIds);
        $deleteMessages = $pdo->prepare("DELETE FROM conversation_messages WHERE conversation_id IN ({$placeholders})");
        $deleteMessages->execute($conversationIds);
        $deleteAudit = $pdo->prepare("DELETE FROM audit_logs WHERE entity_type = 'conversation' AND entity_id IN ({$placeholders})");
        $deleteAudit->execute($conversationIds);
        $deleteConversations = $pdo->prepare("DELETE FROM conversations WHERE id IN ({$placeholders})");
        $deleteConversations->execute($conversationIds);
    }
    if ($adminId !== null) {
        $deleteTokens = $pdo->prepare('DELETE FROM admin_tokens WHERE admin_user_id = :admin_id');
        $deleteTokens->execute([':admin_id' => $adminId]);
        $deleteAdmin = $pdo->prepare('DELETE FROM admin_users WHERE id = :admin_id');
        $deleteAdmin->execute([':admin_id' => $adminId]);
    }
}
