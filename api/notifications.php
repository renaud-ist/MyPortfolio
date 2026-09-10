<?php

declare(strict_types=1);

require __DIR__ . '/auth/common.php';

authApplyCors('GET');
authRequireMethod('GET');
$pdo = authDatabaseRequired();
authRequireBearer($pdo);

function notificationResponse(array $payload, int $status = 200): never
{
    authResponse($payload, $status);
}

function notificationInteger(string $name, int $default, int $maximum): int
{
    if (!isset($_GET[$name])) {
        return $default;
    }
    if (!is_string($_GET[$name]) && !is_int($_GET[$name])) {
        notificationResponse(['ok' => false, 'error' => 'Invalid ' . $name . '.'], 400);
    }

    $value = filter_var($_GET[$name], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximum]]);
    if ($value === false) {
        notificationResponse(['ok' => false, 'error' => 'Invalid ' . $name . '.'], 400);
    }

    return (int) $value;
}

function notificationFilter(string $name, PDO $pdo): ?string
{
    if (!isset($_GET[$name])) {
        return null;
    }
    if (!is_string($_GET[$name]) || trim($_GET[$name]) === '') {
        notificationResponse(['ok' => false, 'error' => 'Invalid ' . $name . '.'], 400);
    }

    $value = trim($_GET[$name]);
    $column = $name === 'notification_type' ? 'notification_type' : 'status';
    $values = $pdo->query("SELECT DISTINCT {$column} FROM notifications WHERE {$column} IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($value, $values, true)) {
        notificationResponse(['ok' => false, 'error' => 'Invalid ' . $name . '.'], 400);
    }

    return $value;
}

try {
    $page = notificationInteger('page', 1, PHP_INT_MAX);
    $perPage = notificationInteger('per_page', 20, 100);
    $status = notificationFilter('status', $pdo);
    $type = notificationFilter('notification_type', $pdo);
    $where = ['notifications.recipient_type = :recipient_type'];
    $params = [':recipient_type' => 'admin'];

    if ($status !== null) {
        $where[] = 'notifications.status = :status';
        $params[':status'] = $status;
    }
    if ($type !== null) {
        $where[] = 'notifications.notification_type = :notification_type';
        $params[':notification_type'] = $type;
    }

    $whereSql = ' WHERE ' . implode(' AND ', $where);
    $count = $pdo->prepare('SELECT COUNT(*) FROM notifications' . $whereSql);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $totalPages = $total === 0 ? 0 : (int) ceil($total / $perPage);
    if ($page > max(1, $totalPages)) {
        notificationResponse(['ok' => false, 'error' => 'Page is out of range.'], 400);
    }

    $list = $pdo->prepare(
        'SELECT notifications.id, notifications.conversation_id, notifications.conversation_message_id,
                notifications.recipient_type, notifications.notification_type, notifications.status,
                notifications.attempt_count, notifications.sent_at, notifications.created_at, notifications.updated_at,
                conversations.contact_name, conversations.contact_email, conversations.subject,
                conversation_messages.sender_type
         FROM notifications
         LEFT JOIN conversations ON conversations.id = notifications.conversation_id
         LEFT JOIN conversation_messages ON conversation_messages.id = notifications.conversation_message_id'
        . $whereSql
        . ' ORDER BY notifications.created_at DESC, notifications.id DESC LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $list->bindValue($key, $value, PDO::PARAM_STR);
    }
    $list->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $list->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $list->execute();

    $items = [];
    foreach ($list->fetchAll() as $row) {
        $items[] = [
            'id' => (string) $row['id'],
            'conversation_id' => $row['conversation_id'] === null ? null : (string) $row['conversation_id'],
            'conversation_message_id' => $row['conversation_message_id'] === null ? null : (string) $row['conversation_message_id'],
            'recipient_type' => $row['recipient_type'],
            'notification_type' => $row['notification_type'],
            'status' => $row['status'],
            'attempt_count' => (int) $row['attempt_count'],
            'sent_at' => $row['sent_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'contact_name' => $row['contact_name'],
            'contact_email' => $row['contact_email'],
            'subject' => $row['subject'],
            'message_sender_type' => $row['sender_type'],
        ];
    }

    authResponse([
        'ok' => true,
        'data' => $items,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ],
        'read_state_supported' => false,
    ]);
} catch (Throwable $error) {
    error_log('Notification list failed: ' . $error->getMessage());
    notificationResponse(['ok' => false, 'error' => 'Notification service is temporarily unavailable.'], 503);
}
