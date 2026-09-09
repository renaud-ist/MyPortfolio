<?php

declare(strict_types=1);

require __DIR__ . '/common.php';

authApplyCors('GET');
authRequireMethod('GET');
$pdo = authDatabaseRequired();
$token = authRequireBearer($pdo);

authResponse([
    'ok' => true,
    'admin' => authAdminIdentity($token),
]);
