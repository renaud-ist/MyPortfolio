<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/db.php';

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(['success' => false, 'message' => 'Only POST requests are accepted.'], 405);
}

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$comment = trim($_POST['message'] ?? '');
$website = trim($_POST['website'] ?? '');
$csrfToken = (string) ($_POST['csrf_token'] ?? '');

if ($name === '' || $email === '' || $comment === '') {
    respond(['success' => false, 'message' => 'Please fill in all fields.'], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['success' => false, 'message' => 'Please use a valid email address.'], 400);
}

if ($website !== '') {
    respond(['success' => true, 'message' => 'Your message was received.']);
}

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    respond(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.'], 419);
}

if (strlen($name) > 120 || strlen($email) > 180 || strlen($comment) > 5000) {
    respond(['success' => false, 'message' => 'Please shorten one or more fields and try again.'], 400);
}

if ($dbReady && $pdo) {
    try {
        $pdo->beginTransaction();
        $rateLimitSeconds = max(10, (int) (getenv('CONTACT_RATE_LIMIT_SECONDS') ?: 60));
        $rateKey = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
        $rateStmt = $pdo->prepare('SELECT last_submission FROM contact_rate_limits WHERE rate_key = :rate_key FOR UPDATE');
        $rateStmt->execute([':rate_key' => $rateKey]);
        $lastSubmission = (int) ($rateStmt->fetchColumn() ?: 0);

        if ($lastSubmission > 0 && (time() - $lastSubmission) < $rateLimitSeconds) {
            $pdo->rollBack();
            respond(['success' => false, 'message' => 'Please wait a moment before sending another message.'], 429);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO contact_messages (name, email, message, created_at) VALUES (:name, :email, :message, NOW())'
        );
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':message' => $comment,
        ]);

        $saveRate = $pdo->prepare(
            'INSERT INTO contact_rate_limits (rate_key, last_submission) VALUES (:rate_key, :last_submission)
             ON DUPLICATE KEY UPDATE last_submission = VALUES(last_submission)'
        );
        $saveRate->execute([':rate_key' => $rateKey, ':last_submission' => time()]);
        $pdo->commit();

        $notificationEmail = filter_var(getenv('CONTACT_NOTIFICATION_EMAIL') ?: '', FILTER_VALIDATE_EMAIL);
        if ($notificationEmail && function_exists('mail')) {
            $subject = 'New portfolio contact message';
            $notification = "Name: {$name}\nEmail: {$email}\n\n{$comment}";
            @mail($notificationEmail, $subject, $notification, 'Reply-To: ' . $email);
        }

        respond(['success' => true, 'message' => 'Your message was successfully sent.']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $dbError = $e;
    }
}

$allowFileFallback = filter_var(getenv('CONTACT_ALLOW_FILE_FALLBACK') ?: '0', FILTER_VALIDATE_BOOLEAN);

if (!$allowFileFallback) {
    respond(['success' => false, 'message' => 'The message service is not connected to MySQL yet. Please try again later.'], 503);
}

$logPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'portfolio_contact_log.jsonl';
$entry = json_encode([
    'created_at' => date('c'),
    'name' => $name,
    'email' => $email,
    'message' => $comment,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;

if (file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX) !== false) {
    respond([
        'success' => true,
        'message' => 'Your message was captured in demo mode. Connect MySQL to enable database persistence.'
    ]);
}

respond(['success' => false, 'message' => 'The message could not be saved. Please try again later.'], 503);