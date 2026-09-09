<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require dirname(__DIR__, 2) . '/assets/php/db.php';

const API_AUTH_MAX_BODY_BYTES = 8000;
const API_AUTH_MAX_EMAIL_LENGTH = 180;
const API_AUTH_TOKEN_BYTES = 32;

function authResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function authAllowedOrigins(): array
{
    $configured = trim((string) (getenv('CONTACT_ALLOWED_ORIGINS') ?: ''));
    $origins = $configured === ''
        ? [
            'https://renaud-ist.github.io',
            'http://localhost:8080',
            'http://127.0.0.1:8080',
        ]
        : preg_split('/\s*,\s*/', $configured, -1, PREG_SPLIT_NO_EMPTY);

    return array_values(array_unique(array_filter(
        $origins,
        static fn (mixed $origin): bool => is_string($origin) && filter_var($origin, FILTER_VALIDATE_URL)
    )));
}

function authApplyCors(string $methods): void
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $allowed = in_array($origin, authAllowedOrigins(), true);

    if ($origin !== '' && $allowed) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        if (!$allowed) {
            authResponse(['ok' => false, 'error' => 'Origin is not allowed.'], 403);
        }

        header('Access-Control-Allow-Methods: ' . $methods . ', OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');
        header('Access-Control-Max-Age: 600');
        http_response_code(204);
        exit;
    }

    if ($origin !== '' && !$allowed) {
        authResponse(['ok' => false, 'error' => 'Origin is not allowed.'], 403);
    }
}

function authRequireMethod(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        header('Allow: ' . $method . ', OPTIONS');
        authResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
}

function authReadJson(): array
{
    $contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
    if ($contentLength !== false && $contentLength > API_AUTH_MAX_BODY_BYTES) {
        authResponse(['ok' => false, 'error' => 'Request is too large.'], 413);
    }

    $input = fopen('php://input', 'rb');
    $body = $input === false ? false : stream_get_contents($input, API_AUTH_MAX_BODY_BYTES + 1);
    if (is_resource($input)) {
        fclose($input);
    }

    if ($body === false || strlen($body) > API_AUTH_MAX_BODY_BYTES) {
        authResponse(['ok' => false, 'error' => 'Request is too large.'], 413);
    }

    try {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        authResponse(['ok' => false, 'error' => 'Request body must contain valid JSON.'], 400);
    }

    if (!is_array($payload)) {
        authResponse(['ok' => false, 'error' => 'Request body must contain a JSON object.'], 400);
    }

    return $payload;
}

function authRequireJsonContentType(): void
{
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
    if ($contentType !== 'application/json') {
        authResponse(['ok' => false, 'error' => 'Content-Type must be application/json.'], 415);
    }
}

function authBearerToken(): ?string
{
    $header = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = trim((string) $value);
                break;
            }
        }
    }

    if (!preg_match('/^Bearer\s+([^\s]+)$/i', $header, $matches)) {
        return null;
    }

    return $matches[1];
}

function authFindToken(PDO $pdo, string $rawToken, bool $requireActive = true): ?array
{
    $tokenHash = hash('sha256', $rawToken);
    $sql =
        'SELECT admin_tokens.id AS token_id, admin_tokens.admin_user_id, admin_tokens.expires_at, admin_tokens.revoked_at,
                admin_users.id, admin_users.email, admin_users.username, admin_users.role, admin_users.is_active
         FROM admin_tokens
         INNER JOIN admin_users ON admin_users.id = admin_tokens.admin_user_id
         WHERE admin_tokens.token_hash = :token_hash';
    if ($requireActive) {
        $sql .= ' AND admin_users.is_active = 1';
    }
    $sql .= ' AND admin_tokens.expires_at > CURRENT_TIMESTAMP';
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':token_hash' => $tokenHash]);
    $token = $stmt->fetch();

    if (!$token || $token['revoked_at'] !== null) {
        return null;
    }

    return $token;
}

function authRequireBearer(PDO $pdo): array
{
    $rawToken = authBearerToken();
    if ($rawToken === null || $rawToken === '') {
        authResponse(['ok' => false, 'error' => 'Authentication required.'], 401);
    }

    try {
        $token = authFindToken($pdo, $rawToken);
    } catch (Throwable $error) {
        error_log('API authentication lookup failed: ' . $error->getMessage());
        authResponse(['ok' => false, 'error' => 'Authentication failed.'], 401);
    }

    if ($token === null) {
        authResponse(['ok' => false, 'error' => 'Authentication required.'], 401);
    }

    try {
        $used = $pdo->prepare('UPDATE admin_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = :token_id');
        $used->execute([':token_id' => $token['token_id']]);
    } catch (Throwable $error) {
        error_log('API token update failed: ' . $error->getMessage());
        authResponse(['ok' => false, 'error' => 'Authentication service is temporarily unavailable.'], 503);
    }

    return $token;
}

function authAdminIdentity(array $token): array
{
    return [
        'id' => (string) $token['id'],
        'email' => $token['email'],
        'username' => $token['username'],
        'role' => $token['role'],
    ];
}

function authDatabaseRequired(): PDO
{
    global $dbReady, $pdo;

    if (!$dbReady || !$pdo) {
        authResponse(['ok' => false, 'error' => 'Authentication service is temporarily unavailable.'], 503);
    }

    return $pdo;
}
