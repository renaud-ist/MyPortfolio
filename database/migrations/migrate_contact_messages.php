<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->beginTransaction();

    try {
        $pdo->exec(
            "INSERT INTO conversations
                (contact_name, contact_email, status, read_at, archived_at, legacy_contact_message_id, created_at, updated_at, last_message_at)
             SELECT legacy.name, legacy.email, 'open', legacy.read_at, legacy.archived_at, legacy.id, legacy.created_at, legacy.created_at, legacy.created_at
             FROM contact_messages AS legacy
             LEFT JOIN conversations AS existing
                ON existing.legacy_contact_message_id = legacy.id
             WHERE existing.id IS NULL"
        );

        $pdo->exec(
            "INSERT INTO conversation_messages
                (conversation_id, sender_type, sender_email, body, read_at, legacy_contact_message_id, created_at)
             SELECT conversations.id, 'visitor', legacy.email, legacy.message, legacy.read_at, legacy.id, legacy.created_at
             FROM contact_messages AS legacy
             INNER JOIN conversations
                ON conversations.legacy_contact_message_id = legacy.id
             LEFT JOIN conversation_messages AS existing
                ON existing.legacy_contact_message_id = legacy.id
             WHERE existing.id IS NULL"
        );

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
};