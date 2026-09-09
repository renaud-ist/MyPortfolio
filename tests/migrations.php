<?php

declare(strict_types=1);

require dirname(__DIR__) . '/assets/php/db.php';

if (!$dbReady || !$pdo) {
    fwrite(STDERR, 'Database unavailable; migration tests cannot run.' . PHP_EOL);
    exit(2);
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL ' . $message . PHP_EOL);
        exit(1);
    }

    echo 'PASS ' . $message . PHP_EOL;
}

function legacySnapshot(PDO $pdo): string
{
    return json_encode(
        $pdo->query('SELECT id, name, email, message, created_at, read_at, archived_at FROM contact_messages ORDER BY id')->fetchAll(),
        JSON_THROW_ON_ERROR
    );
}

$tracking = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'")->fetchColumn();
check((int) $tracking === 1, 'migration tracking table exists');

$migrationCount = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
check($migrationCount >= 2, 'foundation migrations are tracked');

$oldCount = (int) $pdo->query('SELECT COUNT(*) FROM contact_messages')->fetchColumn();
$beforeSnapshot = legacySnapshot($pdo);
$conversationCount = (int) $pdo->query('SELECT COUNT(*) FROM conversations WHERE legacy_contact_message_id IS NOT NULL')->fetchColumn();
$visitorCount = (int) $pdo->query('SELECT COUNT(*) FROM conversation_messages WHERE legacy_contact_message_id IS NOT NULL')->fetchColumn();
check($oldCount === $conversationCount, 'legacy conversation count matches contact count');
check($oldCount === $visitorCount, 'legacy visitor-message count matches contact count');

$mismatchCount = (int) $pdo->query(
    'SELECT COUNT(*)
     FROM contact_messages AS legacy
     INNER JOIN conversations ON conversations.legacy_contact_message_id = legacy.id
     INNER JOIN conversation_messages AS messages ON messages.legacy_contact_message_id = legacy.id
     WHERE NOT (conversations.read_at <=> legacy.read_at)
        OR NOT (conversations.archived_at <=> legacy.archived_at)
        OR NOT (conversations.created_at <=> legacy.created_at)
        OR NOT (messages.created_at <=> legacy.created_at)'
)->fetchColumn();
check($mismatchCount === 0, 'read, archive, and timestamp values are preserved');

$before = $pdo->query('SELECT COUNT(*), COALESCE(MAX(id), 0) FROM contact_messages')->fetch(PDO::FETCH_NUM);
$output = [];
$exitCode = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/database/migrate.php') . ' migrate', $output, $exitCode);
check($exitCode === 0, 'migration re-run succeeds');

$after = $pdo->query('SELECT COUNT(*), COALESCE(MAX(id), 0) FROM contact_messages')->fetch(PDO::FETCH_NUM);
check($before === $after, 'existing contact_messages are unchanged after re-run');
check($beforeSnapshot === legacySnapshot($pdo), 'existing contact_messages fields are unchanged after re-run');

$afterConversationCount = (int) $pdo->query('SELECT COUNT(*) FROM conversations WHERE legacy_contact_message_id IS NOT NULL')->fetchColumn();
$afterVisitorCount = (int) $pdo->query('SELECT COUNT(*) FROM conversation_messages WHERE legacy_contact_message_id IS NOT NULL')->fetchColumn();
check($afterConversationCount === $conversationCount, 're-run does not duplicate conversations');
check($afterVisitorCount === $visitorCount, 're-run does not duplicate visitor messages');
