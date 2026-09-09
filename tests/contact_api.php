<?php

declare(strict_types=1);

require dirname(__DIR__) . '/assets/php/db.php';

if (!$dbReady || !$pdo) {
    fwrite(STDERR, 'Database unavailable; contact API tests cannot run.' . PHP_EOL);
    exit(2);
}

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$marker = 'phase2-' . bin2hex(random_bytes(8));
$testEmail = $marker . '@example.com';
$testUserAgent = $marker . '-agent';
$triggerName = 'contact_api_test_failure_' . bin2hex(random_bytes(5));

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $message);
    }

    echo 'PASS ' . $message . PHP_EOL;
}

function requestJson(string $url, array $payload, string $origin, string $userAgent): array
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $options = [
        'http' => [
            'ignore_errors' => true,
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\nOrigin: {$origin}\r\nUser-Agent: {$userAgent}\r\n",
            'content' => $body,
        ],
    ];
    $context = stream_context_create($options);
    $responseBody = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);

    return [(int) ($matches[1] ?? 0), $responseBody ?: '', $http_response_header ?? []];
}

function requestRaw(string $url, string $body, string $contentType, string $origin, string $userAgent): array
{
    $options = [
        'http' => [
            'ignore_errors' => true,
            'method' => 'POST',
            'header' => "Content-Type: {$contentType}\r\nAccept: application/json\r\nOrigin: {$origin}\r\nUser-Agent: {$userAgent}\r\n",
            'content' => $body,
        ],
    ];
    $context = stream_context_create($options);
    $responseBody = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);

    return [(int) ($matches[1] ?? 0), $responseBody ?: ''];
}

function requestOptions(string $url, string $origin, string $userAgent): array
{
    $options = [
        'http' => [
            'ignore_errors' => true,
            'method' => 'OPTIONS',
            'header' => "Origin: {$origin}\r\nAccess-Control-Request-Method: POST\r\nAccess-Control-Request-Headers: Content-Type, Accept\r\nUser-Agent: {$userAgent}\r\n",
        ],
    ];
    $context = stream_context_create($options);
    $body = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);

    return [(int) ($matches[1] ?? 0), $body ?: ''];
}

function countTestConversations(PDO $pdo, string $email): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM conversations WHERE contact_email = :email');
    $stmt->execute([':email' => $email]);

    return (int) $stmt->fetchColumn();
}

function countTestMessages(PDO $pdo, string $marker): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM conversation_messages WHERE body LIKE :marker');
    $stmt->execute([':marker' => '%' . $marker . '%']);

    return (int) $stmt->fetchColumn();
}

function countTestNotifications(PDO $pdo, string $marker): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM notifications
         INNER JOIN conversation_messages ON conversation_messages.id = notifications.conversation_message_id
         WHERE conversation_messages.body LIKE :marker'
    );
    $stmt->execute([':marker' => '%' . $marker . '%']);

    return (int) $stmt->fetchColumn();
}

$endpoint = $baseUrl . '/api/contact.php';
$origin = 'http://localhost:8080';
$githubOrigin = 'https://renaud-ist.github.io';
$payload = [
    'name' => 'Phase 2 Test',
    'email' => $testEmail,
    'subject' => 'API foundation test',
    'message' => $marker . ' valid message',
    'website' => '',
];

try {
    [$status, $body, $headers] = requestJson($endpoint, $payload, $origin, $testUserAgent . '-valid');
    $decoded = json_decode($body, true);
    check($status === 200 && ($decoded['success'] ?? false) === true, 'valid JSON submission');
    check(in_array('Access-Control-Allow-Origin: ' . $origin, $headers, true), 'allowed origin receives CORS header');

    check(countTestConversations($pdo, $testEmail) === 1, 'valid submission creates one conversation');
    check(countTestMessages($pdo, $marker) === 1, 'valid submission creates one visitor message');
    check(countTestNotifications($pdo, $marker) === 1, 'valid submission creates one notification');

    [$status] = requestJson($endpoint, ['name' => 'Missing'], $origin, $testUserAgent . '-missing');
    check($status === 400, 'missing required fields are rejected');

    [$status] = requestJson($endpoint, [...$payload, 'email' => 'invalid'], $origin, $testUserAgent . '-email');
    check($status === 400, 'invalid email is rejected');

    [$status] = requestRaw($endpoint, '{invalid', 'application/json', $origin, $testUserAgent . '-malformed');
    check($status === 400, 'malformed JSON is rejected');

    [$status] = requestRaw($endpoint, json_encode($payload, JSON_THROW_ON_ERROR), 'application/x-www-form-urlencoded', $origin, $testUserAgent . '-content-type');
    check($status === 415, 'wrong Content-Type is rejected');

    [$status] = requestJson($endpoint, [...$payload, 'website' => 'https://spam.example'], $origin, $testUserAgent . '-honeypot');
    check($status === 200, 'honeypot submission is silently accepted');
    check(countTestConversations($pdo, $testEmail) === 1, 'honeypot submission creates no conversation');

    [$status] = requestJson($endpoint, [...$payload, 'message' => str_repeat('x', 12000)], $origin, $testUserAgent . '-oversized');
    check($status === 413, 'oversized request is rejected');

    [$status] = requestJson($endpoint, $payload, 'https://untrusted.example', $testUserAgent . '-cors');
    check($status === 403, 'rejected origin is denied');

    [$status, $body] = requestOptions($endpoint, $githubOrigin, $testUserAgent . '-preflight');
    check($status === 204 && $body === '', 'allowed OPTIONS preflight does not write');
    check(countTestConversations($pdo, $testEmail) === 1, 'OPTIONS preflight creates no conversation');

    $duplicatePayload = [...$payload, 'message' => $marker . ' duplicate message'];
    [$status] = requestJson($endpoint, $duplicatePayload, $origin, $testUserAgent . '-duplicate-one');
    check($status === 200, 'first repeated submission succeeds');
    [$status] = requestJson($endpoint, $duplicatePayload, $origin, $testUserAgent . '-duplicate-two');
    check($status === 200, 'second repeated submission succeeds');
    check(countTestConversations($pdo, $testEmail) === 3, 'repeated submissions create independent conversations');
    check(countTestMessages($pdo, $marker) === 3, 'repeated submissions create independent messages');
    check(countTestNotifications($pdo, $marker) === 3, 'repeated submissions create independent notifications');

    $pdo->exec(
        "CREATE TRIGGER {$triggerName}
         BEFORE INSERT ON notifications
         FOR EACH ROW
         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'intentional test failure'"
    );

    $beforeConversations = countTestConversations($pdo, $testEmail);
    $beforeMessages = countTestMessages($pdo, $marker);
    $beforeNotifications = countTestNotifications($pdo, $marker);
    [$status] = requestJson($endpoint, [...$payload, 'message' => $marker . ' rollback message'], $origin, $testUserAgent . '-rollback');
    check($status === 503, 'database failure returns a generic server error');
    check(countTestConversations($pdo, $testEmail) === $beforeConversations, 'failed transaction creates no conversation');
    check(countTestMessages($pdo, $marker) === $beforeMessages, 'failed transaction creates no visitor message');
    check(countTestNotifications($pdo, $marker) === $beforeNotifications, 'failed transaction creates no notification');
} finally {
    try {
        $pdo->exec("DROP TRIGGER IF EXISTS {$triggerName}");
    } catch (Throwable) {
    }

    $notificationDelete = $pdo->prepare(
        'DELETE notifications FROM notifications
         INNER JOIN conversation_messages ON conversation_messages.id = notifications.conversation_message_id
         WHERE conversation_messages.body LIKE :marker'
    );
    $notificationDelete->execute([':marker' => '%' . $marker . '%']);

    $messageDelete = $pdo->prepare('DELETE FROM conversation_messages WHERE body LIKE :marker');
    $messageDelete->execute([':marker' => '%' . $marker . '%']);

    $conversationDelete = $pdo->prepare('DELETE FROM conversations WHERE contact_email = :email');
    $conversationDelete->execute([':email' => $testEmail]);
}
