<?php

declare(strict_types=1);

require __DIR__ . '/auth/common.php';

authApplyCors('GET');
authRequireMethod('GET');
$pdo = authDatabaseRequired();
authRequireBearer($pdo);

function conversationListResponse(array $payload, int $status = 200): never
{
    authResponse($payload, $status);
}

function positiveQueryInteger(string $name, int $default, int $maximum): int
{
    if (!isset($_GET[$name])) {
        return $default;
    }

    if (!is_string($_GET[$name]) && !is_int($_GET[$name])) {
        conversationListResponse(['ok' => false, 'error' => 'Invalid ' . $name . '.'], 400);
    }

    $value = filter_var($_GET[$name], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximum]]);
    if ($value === false) {
        conversationListResponse(['ok' => false, 'error' => 'Invalid ' . $name . '.'], 400);
    }

    return (int) $value;
}

function queryBoolean(string $name): ?int
{
    if (!isset($_GET[$name])) {
        return null;
    }

    if (!is_string($_GET[$name]) && !is_int($_GET[$name])) {
        conversationListResponse(['ok' => false, 'error' => 'Invalid ' . $name . '.'], 400);
    }

    $value = filter_var($_GET[$name], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]);
    if ($value === false) {
        conversationListResponse(['ok' => false, 'error' => 'Invalid ' . $name . '.'], 400);
    }

    return (int) $value;
}

try {
    $page = positiveQueryInteger('page', 1, PHP_INT_MAX);
    $perPage = positiveQueryInteger('per_page', 20, 100);
    if (isset($_GET['status']) && !is_string($_GET['status'])) {
        conversationListResponse(['ok' => false, 'error' => 'Invalid status.'], 400);
    }
    $status = isset($_GET['status']) ? trim($_GET['status']) : null;
    $isRead = queryBoolean('is_read');
    $isArchived = queryBoolean('is_archived');
    $where = [];
    $params = [];

    if ($status !== null && $status === '') {
        conversationListResponse(['ok' => false, 'error' => 'Invalid status.'], 400);
    }
    if ($status !== null) {
        $statusValues = $pdo->query('SELECT DISTINCT status FROM conversations WHERE status IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array($status, $statusValues, true)) {
            conversationListResponse(['ok' => false, 'error' => 'Invalid status.'], 400);
        }
        $where[] = 'conversations.status = :status';
        $params[':status'] = $status;
    }
    if ($isRead !== null) {
        $where[] = $isRead === 1 ? 'conversations.read_at IS NOT NULL' : 'conversations.read_at IS NULL';
    }
    if ($isArchived !== null) {
        $where[] = $isArchived === 1 ? 'conversations.archived_at IS NOT NULL' : 'conversations.archived_at IS NULL';
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $count = $pdo->prepare('SELECT COUNT(*) FROM conversations' . $whereSql);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $totalPages = $total === 0 ? 0 : (int) ceil($total / $perPage);

    if ($page > max(1, $totalPages)) {
        conversationListResponse(['ok' => false, 'error' => 'Page is out of range.'], 400);
    }

    $offset = ($page - 1) * $perPage;
    $list = $pdo->prepare(
        'SELECT conversations.id, conversations.contact_name, conversations.contact_email, conversations.subject,
                conversations.status, conversations.read_at, conversations.archived_at, conversations.created_at,
                conversations.updated_at, conversations.last_message_at,
                latest.body AS latest_message_preview, latest.sender_type AS latest_sender_type
         FROM conversations
         LEFT JOIN conversation_messages AS latest
            ON latest.id = (
                SELECT newest.id
                FROM conversation_messages AS newest
                WHERE newest.conversation_id = conversations.id
                ORDER BY newest.created_at DESC, newest.id DESC
                LIMIT 1
            )'
        . $whereSql
        . ' ORDER BY conversations.updated_at DESC, conversations.id DESC LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $list->bindValue($key, $value, PDO::PARAM_STR);
    }
    $list->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $list->bindValue(':offset', $offset, PDO::PARAM_INT);
    $list->execute();

    $items = [];
    foreach ($list->fetchAll() as $row) {
        $items[] = [
            'id' => (string) $row['id'],
            'contact_name' => $row['contact_name'],
            'contact_email' => $row['contact_email'],
            'subject' => $row['subject'],
            'status' => $row['status'],
            'is_read' => $row['read_at'] !== null,
            'is_archived' => $row['archived_at'] !== null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'last_message_at' => $row['last_message_at'],
            'latest_message_preview' => $row['latest_message_preview'],
            'latest_sender_type' => $row['latest_sender_type'],
        ];
    }

    conversationListResponse([
        'ok' => true,
        'data' => $items,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ],
    ]);
} catch (Throwable $error) {
    error_log('Conversation list failed: ' . $error->getMessage());
    conversationListResponse(['ok' => false, 'error' => 'Conversation service is temporarily unavailable.'], 503);
}
