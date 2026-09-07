<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/db.php';

if (!$dbReady || !$pdo) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'database' => 'unavailable']);
    exit;
}

try {
    $tableCheck = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
         AND table_name IN ('contact_messages', 'contact_rate_limits')"
    );

    $tablesReady = (int) $tableCheck->fetchColumn() === 2;
    if (!$tablesReady) {
        throw new RuntimeException('Required tables are missing.');
    }

    echo json_encode([
        'status' => 'ok',
        'database' => 'connected',
        'tables' => 'ready',
    ]);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'database' => 'schema_incomplete']);
}
