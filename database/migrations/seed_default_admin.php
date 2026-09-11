<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $email = 'admin@portfolio.local';
    $username = 'admin';
    $passwordHash = '$2y$12$Tg69e68r/YLjsikCOGJO6umDELxgoWwRzkxhWShP6564WLNsqou8m';

    $pdo->prepare(
        'INSERT INTO admin_users (username, email, password_hash, role, is_active)
         VALUES (:username, :email, :password_hash, :role, :is_active)
         ON DUPLICATE KEY UPDATE
           email = VALUES(email),
           password_hash = VALUES(password_hash),
           role = VALUES(role),
           is_active = VALUES(is_active),
           username = VALUES(username)'
    )->execute([
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => $passwordHash,
        ':role' => 'admin',
        ':is_active' => 1,
    ]);
};