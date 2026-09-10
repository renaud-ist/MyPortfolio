<?php

declare(strict_types=1);

require dirname(__DIR__) . '/auth/common.php';
require dirname(__DIR__) . '/mail.php';

authApplyCors('POST');
authRequireMethod('POST');
authRequireJsonContentType();
$pdo = authDatabaseRequired();
$token = authRequireBearer($pdo);
$admin = authAdminIdentity($token);

$rawId = $_GET['id'] ?? null;
if (!is_string($rawId) && !is_int($rawId)) {
    authResponse(['ok' => false, 'error' => 'A valid conversation id is required.'], 400);
}
$conversationId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($conversationId === false) {
    authResponse(['ok' => false, 'error' => 'A valid conversation id is required.'], 400);
}
$conversationId = (int) $conversationId;

$payload = authReadJson();
$unsupported = array_diff(array_keys($payload), ['body']);
if ($unsupported !== []) {
    authResponse(['ok' => false, 'error' => 'Unsupported reply field.'], 400);
}

$body = $payload['body'] ?? null;
if (!is_string($body)) {
    authResponse(['ok' => false, 'error' => 'Reply body must be a string.'], 422);
}
$body = trim($body);
if ($body === '') {
    authResponse(['ok' => false, 'error' => 'Reply body is required.'], 422);
}
if (strlen($body) > 65535) {
    authResponse(['ok' => false, 'error' => 'Reply body is too long.'], 422);
}

try {
    $pdo->beginTransaction();

    $conversation = $pdo->prepare(
        'SELECT id, contact_name, contact_email, subject, status, read_at, archived_at,
                created_at, updated_at, last_message_at
         FROM conversations
         WHERE id = :id
         FOR UPDATE'
    );
    $conversation->execute([':id' => $conversationId]);
    $conversationRow = $conversation->fetch();
    if (!$conversationRow) {
        $pdo->rollBack();
        authResponse(['ok' => false, 'error' => 'Conversation not found.'], 404);
    }

    $message = $pdo->prepare(
        'INSERT INTO conversation_messages
            (conversation_id, sender_type, sender_admin_id, sender_name, sender_email, body, created_at)
         VALUES (:conversation_id, :sender_type, :sender_admin_id, :sender_name, :sender_email, :body, CURRENT_TIMESTAMP)'
    );
    $message->execute([
        ':conversation_id' => $conversationId,
        ':sender_type' => 'admin',
        ':sender_admin_id' => $admin['id'],
        ':sender_name' => $admin['username'],
        ':sender_email' => $admin['email'],
        ':body' => $body,
    ]);
    $messageId = (int) $pdo->lastInsertId();

    $update = $pdo->prepare(
        'UPDATE conversations
         SET last_message_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $update->execute([':id' => $conversationId]);

    $audit = $pdo->prepare(
        'INSERT INTO audit_logs
            (admin_user_id, action, entity_type, entity_id, metadata_json, ip_address, user_agent, created_at)
         VALUES (:admin_user_id, :action, :entity_type, :entity_id, :metadata_json, :ip_address, :user_agent, CURRENT_TIMESTAMP)'
    );
    $audit->execute([
        ':admin_user_id' => $admin['id'],
        ':action' => 'conversation.reply',
        ':entity_type' => 'conversation_message',
        ':entity_id' => $messageId,
        ':metadata_json' => json_encode(['conversation_id' => $conversationId], JSON_THROW_ON_ERROR),
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512) ?: null,
    ]);

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Conversation reply failed: ' . $error->getMessage());
    authResponse(['ok' => false, 'error' => 'The reply could not be saved. Please try again later.'], 503);
}

$mail = replyMailStatus($conversationRow, $body);
$updatedConversation = $pdo->prepare('SELECT last_message_at FROM conversations WHERE id = :id');
$updatedConversation->execute([':id' => $conversationId]);
$lastMessageAt = $updatedConversation->fetchColumn();
$messageData = [
    'id' => (string) $messageId,
    'conversation_id' => (string) $conversationId,
    'sender_type' => 'admin',
    'sender_admin_id' => (string) $admin['id'],
    'sender_name' => $admin['username'],
    'sender_email' => $admin['email'],
    'body' => $body,
];

http_response_code(201);
echo json_encode([
    'ok' => true,
    'data' => [
        'message' => $messageData,
        'conversation' => [
            'id' => (string) $conversationId,
            'last_message_at' => $lastMessageAt,
        ],
        'email' => $mail,
    ],
], JSON_UNESCAPED_SLASHES);
