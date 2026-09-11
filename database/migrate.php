<?php

declare(strict_types=1);

require dirname(__DIR__) . '/assets/php/db.php';

const MIGRATIONS_TABLE = 'schema_migrations';

function migrationError(string $message, ?Throwable $previous = null): never
{
    $detail = $previous ? ': ' . $previous->getMessage() : '';
    fwrite(STDERR, 'Migration error' . $detail . PHP_EOL . $message . PHP_EOL);
    exit(1);
}

function migrationFiles(): array
{
    return array_map(
        static fn (string $file): string => __DIR__ . '/migrations/' . $file . '.php',
        [
            'create_backend_foundation',
            'migrate_contact_messages',
            'seed_default_admin',
        ]
    );
}

function migrationId(string $file): string
{
    return match (basename($file, '.php')) {
        'create_backend_foundation' => '001_create_backend_foundation',
        'migrate_contact_messages' => '002_migrate_contact_messages',
        'seed_default_admin' => '003_seed_default_admin',
        default => basename($file, '.php'),
    };
}

function loadMigration(string $file): array
{
    $migration = require $file;
    $id = migrationId($file);

    if (!is_callable($migration)) {
        migrationError('Migration ' . $id . ' must return a callable.');
    }

    return ['id' => $id, 'description' => $id, 'up' => $migration];
}

function ensureTrackingTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            migration_id VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            description VARCHAR(255) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function appliedMigrations(PDO $pdo): array
{
    return $pdo->query('SELECT migration_id FROM schema_migrations ORDER BY migration_id')->fetchAll(PDO::FETCH_COLUMN);
}

function verifyMigration(PDO $pdo): void
{
    $oldCount = (int) $pdo->query('SELECT COUNT(*) FROM contact_messages')->fetchColumn();
    $conversationCount = (int) $pdo->query('SELECT COUNT(*) FROM conversations WHERE legacy_contact_message_id IS NOT NULL')->fetchColumn();
    $visitorMessageCount = (int) $pdo->query('SELECT COUNT(*) FROM conversation_messages WHERE legacy_contact_message_id IS NOT NULL')->fetchColumn();

    if ($oldCount !== $conversationCount || $oldCount !== $visitorMessageCount) {
        migrationError(
            'Legacy verification failed: contact_messages=' . $oldCount
            . ', migrated_conversations=' . $conversationCount
            . ', migrated_visitor_messages=' . $visitorMessageCount . '.'
        );
    }

    $mismatches = (int) $pdo->query(
        'SELECT COUNT(*)
         FROM contact_messages AS legacy
         LEFT JOIN conversations AS conversations
            ON conversations.legacy_contact_message_id = legacy.id
         LEFT JOIN conversation_messages AS messages
            ON messages.legacy_contact_message_id = legacy.id
         WHERE conversations.id IS NULL
            OR messages.id IS NULL
            OR conversations.contact_name <> legacy.name
            OR conversations.contact_email <> legacy.email
            OR messages.sender_email <> legacy.email
            OR messages.body <> legacy.message
            OR NOT (conversations.created_at <=> legacy.created_at)
            OR NOT (messages.created_at <=> legacy.created_at)
            OR NOT (conversations.read_at <=> legacy.read_at)
            OR NOT (messages.read_at <=> legacy.read_at)
            OR NOT (conversations.archived_at <=> legacy.archived_at)'
    )->fetchColumn();

    if ($mismatches !== 0) {
        migrationError('Legacy verification found ' . $mismatches . ' mismatched migrated records.');
    }

    echo 'Verified legacy counts and preserved contact fields: ' . $oldCount . ' record(s).' . PHP_EOL;
}

if (PHP_SAPI !== 'cli') {
    migrationError('This script must be run from the command line.');
}

if (!$dbReady || !$pdo) {
    migrationError('Database connection is unavailable. Check DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASS.');
}

$command = $argv[1] ?? 'migrate';
if (!in_array($command, ['migrate', 'status', 'verify'], true)) {
    migrationError('Usage: php database/migrate.php [migrate|status|verify]');
}

try {
    ensureTrackingTable($pdo);
    $migrations = array_map('loadMigration', migrationFiles());
    $applied = array_flip(appliedMigrations($pdo));

    if ($command === 'status') {
        foreach ($migrations as $migration) {
            echo (isset($applied[$migration['id']]) ? 'APPLIED ' : 'PENDING ') . $migration['id'] . PHP_EOL;
        }
        exit(0);
    }

    if ($command === 'verify') {
        verifyMigration($pdo);
        exit(0);
    }

    foreach ($migrations as $migration) {
        if (isset($applied[$migration['id']])) {
            continue;
        }

        echo 'Applying ' . $migration['id'] . '...' . PHP_EOL;
        try {
            ($migration['up'])($pdo);
            $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration_id, description) VALUES (:migration_id, :description)');
            $stmt->execute([':migration_id' => $migration['id'], ':description' => $migration['description']]);
        } catch (Throwable $error) {
            migrationError('Migration ' . $migration['id'] . ' was not marked complete.', $error);
        }
        echo 'Applied ' . $migration['id'] . '.' . PHP_EOL;
    }

    verifyMigration($pdo);
    echo 'Migration complete.' . PHP_EOL;
} catch (Throwable $error) {
    migrationError('No migration was marked complete after the failure.', $error);
}
