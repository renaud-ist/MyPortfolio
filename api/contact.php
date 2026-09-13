<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require dirname(__DIR__) . '/assets/php/db.php';

const CONTACT_API_MAX_BODY_BYTES = 12000;
const CONTACT_API_MAX_NAME_LENGTH = 120;
const CONTACT_API_MAX_EMAIL_LENGTH = 180;
const CONTACT_API_MAX_SUBJECT_LENGTH = 255;
const CONTACT_API_MAX_MESSAGE_LENGTH = 5000;

function apiResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    if ($status !== 204) {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
    exit;
}

function allowedOrigins(): array
{
    $configured = trim((string) (getenv('CONTACT_ALLOWED_ORIGINS') ?: ''));
    $productionOrigin = 'https://renaud-ist.github.io';
    $origins = $configured === ''
        ? [
            $productionOrigin,
            'http://localhost:8080',
            'http://127.0.0.1:8080',
        ]
        : array_merge(
            preg_split('/\s*,\s*/', $configured, -1, PREG_SPLIT_NO_EMPTY),
            [$productionOrigin]
        );

    return array_values(array_unique(array_filter($origins, static fn (mixed $origin): bool => is_string($origin) && filter_var($origin, FILTER_VALIDATE_URL))));
}

function applyCors(): void
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $allowed = in_array($origin, allowedOrigins(), true);

    if ($origin !== '' && $allowed) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        if (!$allowed) {
            apiResponse(['success' => false, 'message' => 'Origin is not allowed.'], 403);
        }

        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept');
        header('Access-Control-Max-Age: 600');
        apiResponse([], 204);
    }

    if ($origin !== '' && !$allowed) {
        apiResponse(['success' => false, 'message' => 'Origin is not allowed.'], 403);
    }
}

function requestBody(): string
{
    $contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
    if ($contentLength !== false && $contentLength > CONTACT_API_MAX_BODY_BYTES) {
        apiResponse(['success' => false, 'message' => 'Request is too large.'], 413);
    }

    $input = fopen('php://input', 'rb');
    $body = $input === false ? false : stream_get_contents($input, CONTACT_API_MAX_BODY_BYTES + 1);
    if (is_resource($input)) {
        fclose($input);
    }

    if ($body === false || strlen($body) > CONTACT_API_MAX_BODY_BYTES) {
        apiResponse(['success' => false, 'message' => 'Request is too large.'], 413);
    }

    return $body;
}

function stringField(array $payload, string $field): string
{
    $value = $payload[$field] ?? '';

    return is_string($value) ? trim($value) : '';
}

applyCors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST, OPTIONS');
    apiResponse(['success' => false, 'message' => 'Only POST requests are accepted.'], 405);
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
if ($contentType !== 'application/json') {
    apiResponse(['success' => false, 'message' => 'Content-Type must be application/json.'], 415);
}

try {
    $payload = json_decode(requestBody(), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    apiResponse(['success' => false, 'message' => 'Request body must contain valid JSON.'], 400);
}

if (!is_array($payload)) {
    apiResponse(['success' => false, 'message' => 'Request body must contain a JSON object.'], 400);
}

$name = stringField($payload, 'name');
$email = stringField($payload, 'email');
$subject = stringField($payload, 'subject');
$message = stringField($payload, 'message');
$website = stringField($payload, 'website');

if ($website !== '') {
    apiResponse(['success' => true, 'message' => 'Your message has been received.']);
}

if ($name === '' || $email === '' || $message === '') {
    apiResponse(['success' => false, 'message' => 'Name, email, and message are required.'], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    apiResponse(['success' => false, 'message' => 'Please use a valid email address.'], 400);
}

if (strlen($name) > CONTACT_API_MAX_NAME_LENGTH
    || strlen($email) > CONTACT_API_MAX_EMAIL_LENGTH
    || strlen($subject) > CONTACT_API_MAX_SUBJECT_LENGTH
    || strlen($message) > CONTACT_API_MAX_MESSAGE_LENGTH
) {
    apiResponse(['success' => false, 'message' => 'One or more fields are too long.'], 400);
}

if (!$dbReady || !$pdo) {
    apiResponse(['success' => false, 'message' => 'The message service is temporarily unavailable.'], 503);
}

try {
    $pdo->beginTransaction();

    $rateLimitSeconds = max(10, (int) (getenv('CONTACT_RATE_LIMIT_SECONDS') ?: 60));
    $rateKey = hash('sha256', 'api|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
    $rateStmt = $pdo->prepare('SELECT last_submission FROM contact_rate_limits WHERE rate_key = :rate_key FOR UPDATE');
    $rateStmt->execute([':rate_key' => $rateKey]);
    $lastSubmission = (int) ($rateStmt->fetchColumn() ?: 0);

    if ($lastSubmission > 0 && (time() - $lastSubmission) < $rateLimitSeconds) {
        $pdo->rollBack();
        apiResponse(['success' => false, 'message' => 'Please wait a moment before sending another message.'], 429);
    }

    $conversation = $pdo->prepare(
        'INSERT INTO conversations
            (contact_name, contact_email, subject, status, created_at, updated_at, last_message_at)
         VALUES (:name, :email, :subject, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    );
    $conversation->execute([
        ':name' => $name,
        ':email' => $email,
        ':subject' => $subject !== '' ? $subject : null,
        ':status' => 'open',
    ]);
    $conversationId = (int) $pdo->lastInsertId();

    $messageStmt = $pdo->prepare(
        'INSERT INTO conversation_messages
            (conversation_id, sender_type, sender_name, sender_email, body, created_at)
         VALUES (:conversation_id, :sender_type, :sender_name, :sender_email, :body, CURRENT_TIMESTAMP)'
    );
    $messageStmt->execute([
        ':conversation_id' => $conversationId,
        ':sender_type' => 'visitor',
        ':sender_name' => $name,
        ':sender_email' => $email,
        ':body' => $message,
    ]);
    $messageId = (int) $pdo->lastInsertId();

    $notification = $pdo->prepare(
        'INSERT INTO notifications
            (conversation_id, conversation_message_id, recipient_type, notification_type, status, created_at, updated_at)
         VALUES (:conversation_id, :message_id, :recipient_type, :notification_type, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    );
    $notification->execute([
        ':conversation_id' => $conversationId,
        ':message_id' => $messageId,
        ':recipient_type' => 'admin',
        ':notification_type' => 'new_contact_message',
        ':status' => 'pending',
    ]);

    $saveRate = $pdo->prepare(
        'INSERT INTO contact_rate_limits (rate_key, last_submission) VALUES (:rate_key, :last_submission)
         ON DUPLICATE KEY UPDATE last_submission = VALUES(last_submission)'
    );
    $saveRate->execute([':rate_key' => $rateKey, ':last_submission' => time()]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Contact API transaction failed: ' . $error->getMessage());
    apiResponse(['success' => false, 'message' => 'The message could not be saved. Please try again later.'], 503);
}

apiResponse(['success' => true, 'message' => 'Your message has been received.']);
