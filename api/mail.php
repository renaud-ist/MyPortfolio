<?php

declare(strict_types=1);

function replyMailStatus(array $conversation, string $body): array
{
    $enabled = filter_var(getenv('REPLY_EMAIL_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN);
    if (!$enabled) {
        return ['status' => 'disabled'];
    }

    $recipient = filter_var($conversation['contact_email'] ?? '', FILTER_VALIDATE_EMAIL);
    $from = filter_var(getenv('REPLY_FROM_EMAIL') ?: '', FILTER_VALIDATE_EMAIL);
    if (!$recipient || !$from || !function_exists('mail')) {
        return ['status' => 'not_configured'];
    }

    $subject = (string) ($conversation['subject'] ?? '');
    $subject = $subject !== '' ? 'Re: ' . $subject : 'Reply to your portfolio message';
    $headers = 'From: ' . $from . "\r\n" . 'Reply-To: ' . $from;
    $sent = @mail($recipient, $subject, $body, $headers);

    return ['status' => $sent ? 'sent' : 'failed'];
}
