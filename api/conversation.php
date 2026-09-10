<?php

declare(strict_types=1);

require __DIR__ . '/auth/common.php';

authApplyCors('GET, PATCH');
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'PATCH'], true)) {
    header('Allow: GET, PATCH, OPTIONS');
    authResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$pdo = authDatabaseRequired();
$admin = authRequireBearer($pdo);

function conversationId(): int
{
    $rawId = $_GET['id'] ?? null;
    if (!is_string($rawId) && !is_int($rawId)) {
        authResponse(['ok' => false, 'error' => 'A valid conversation id is required.'], 400);
    }
    $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        authResponse(['ok' => false, 'error' => 'A valid conversation id is required.'], 400);
    }

    return (int) $id;
}

function conversationData(PDO $pdo, int $id): ?array
{
    $conversation = $pdo->prepare(
        'SELECT id, contact_name, contact_email, subject, status, read_at, archived_at,
                created_at, updated_at, last_message_at
         FROM conversations
         WHERE id = :id
         LIMIT 1'
    );
    $conversation->execute([':id' => $id]);
    $row = $conversation->fetch();
    if (!$row) {
        return null;
    }

    $messages = $pdo->prepare(
        'SELECT id, sender_type, sender_admin_id, sender_name, sender_email, body, read_at, created_at
         FROM conversation_messages
         WHERE conversation_id = :conversation_id
         ORDER BY created_at ASC, id ASC'
    );
    $messages->execute([':conversation_id' => $id]);

    $messageData = [];
    foreach ($messages->fetchAll() as $message) {
        $messageData[] = [
            'id' => (string) $message['id'],
            'sender_type' => $message['sender_type'],
            'sender_admin_id' => $message['sender_admin_id'] === null ? null : (string) $message['sender_admin_id'],
            'sender_name' => $message['sender_name'],
            'sender_email' => $message['sender_email'],
            'body' => $message['body'],
            'is_read' => $message['read_at'] !== null,
            'read_at' => $message['read_at'],
            'created_at' => $message['created_at'],
        ];
    }

    return [
        'id' => (string) $row['id'],
        'contact_name' => $row['contact_name'],
        'contact_email' => $row['contact_email'],
        'subject' => $row['subject'],
        'status' => $row['status'],
        'is_read' => $row['read_at'] !== null,
        'read_at' => $row['read_at'],
        'is_archived' => $row['archived_at'] !== null,
        'archived_at' => $row['archived_at'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'last_message_at' => $row['last_message_at'],
        'messages' => $messageData,
    ];
}

function conversationStatusValues(PDO $pdo): array
{
    return $pdo->query('SELECT DISTINCT status FROM conversations WHERE status IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
}

function auditConversationChange(PDO $pdo, array $admin, int $id, array $changes): void
{
    $audit = $pdo->prepare(
        'INSERT INTO audit_logs
            (admin_user_id, action, entity_type, entity_id, metadata_json, ip_address, user_agent, created_at)
         VALUES (:admin_user_id, :action, :entity_type, :entity_id, :metadata_json, :ip_address, :user_agent, CURRENT_TIMESTAMP)'
    );
    $audit->execute([
        ':admin_user_id' => $admin['admin_user_id'],
        ':action' => 'conversation.update',
        ':entity_type' => 'conversation',
        ':entity_id' => $id,
        ':metadata_json' => json_encode(['changes' => $changes], JSON_THROW_ON_ERROR),
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512) ?: null,
    ]);
}

$id = conversationId();

if ($method === 'GET') {
    try {
        $data = conversationData($pdo, $id);
        if ($data === null) {
            authResponse(['ok' => false, 'error' => 'Conversation not found.'], 404);
        }
        authResponse(['ok' => true, 'data' => $data]);
    } catch (Throwable $error) {
        error_log('Conversation detail failed: ' . $error->getMessage());
        authResponse(['ok' => false, 'error' => 'Conversation service is temporarily unavailable.'], 503);
    }
}

authRequireJsonContentType();
$payload = authReadJson();
$allowedFields = ['is_read', 'is_archived', 'status'];
$unknownFields = array_diff(array_keys($payload), $allowedFields);
if ($unknownFields !== []) {
    authResponse(['ok' => false, 'error' => 'Unsupported conversation field.'], 400);
}
if ($payload === []) {
    authResponse(['ok' => false, 'error' => 'At least one conversation field is required.'], 400);
}

$changes = [];
if (array_key_exists('is_read', $payload)) {
    if (!is_bool($payload['is_read'])) {
        authResponse(['ok' => false, 'error' => 'is_read must be boolean.'], 422);
    }
    $changes['is_read'] = $payload['is_read'];
}
if (array_key_exists('is_archived', $payload)) {
    if (!is_bool($payload['is_archived'])) {
        authResponse(['ok' => false, 'error' => 'is_archived must be boolean.'], 422);
    }
    $changes['is_archived'] = $payload['is_archived'];
}
if (array_key_exists('status', $payload)) {
    try {
        $statusValues = conversationStatusValues($pdo);
    } catch (Throwable $error) {
        error_log('Conversation status lookup failed: ' . $error->getMessage());
        authResponse(['ok' => false, 'error' => 'Conversation service is temporarily unavailable.'], 503);
    }
    if (!is_string($payload['status']) || !in_array($payload['status'], $statusValues, true)) {
        authResponse(['ok' => false, 'error' => 'Invalid status.'], 422);
    }
    $changes['status'] = $payload['status'];
}

try {
    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT id FROM conversations WHERE id = :id FOR UPDATE');
    $lock->execute([':id' => $id]);
    if ($lock->fetchColumn() === false) {
        $pdo->rollBack();
        authResponse(['ok' => false, 'error' => 'Conversation not found.'], 404);
    }

    $assignments = [];
    $params = [':id' => $id];
    if (array_key_exists('is_read', $changes)) {
        $assignments[] = 'read_at = ' . ($changes['is_read'] ? 'CURRENT_TIMESTAMP' : 'NULL');
    }
    if (array_key_exists('is_archived', $changes)) {
        $assignments[] = 'archived_at = ' . ($changes['is_archived'] ? 'CURRENT_TIMESTAMP' : 'NULL');
    }
    if (array_key_exists('status', $changes)) {
        $assignments[] = 'status = :status';
        $params[':status'] = $changes['status'];
    }

    $update = $pdo->prepare('UPDATE conversations SET ' . implode(', ', $assignments) . ' WHERE id = :id');
    $update->execute($params);
    auditConversationChange($pdo, $admin, $id, $changes);
    $updated = conversationData($pdo, $id);
    $pdo->commit();

    authResponse(['ok' => true, 'data' => $updated]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Conversation update failed: ' . $error->getMessage());
    authResponse(['ok' => false, 'error' => 'Conversation service is temporarily unavailable.'], 503);
}
