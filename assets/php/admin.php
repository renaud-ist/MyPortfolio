<?php
require __DIR__ . '/db.php';

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);

function html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function adminToken(): string
{
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_csrf'];
}

function redirectToDashboard(): never
{
    header('Location: admin.php');
    exit;
}

$adminUser = (string) (getenv('ADMIN_USER') ?: '');
$adminPassword = (string) (getenv('ADMIN_PASS') ?: '');
$error = '';
$loginLockSeconds = 300;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $token = (string) ($_POST['admin_csrf'] ?? '');

    if (!hash_equals($_SESSION['admin_csrf'] ?? '', $token)) {
        $error = 'Your session expired. Refresh and try again.';
    } elseif ($action === 'login') {
        $attempts = (int) ($_SESSION['admin_login_attempts'] ?? 0);
        $lockedAt = (int) ($_SESSION['admin_locked_at'] ?? 0);
        $username = (string) ($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if ($lockedAt && time() - $lockedAt < $loginLockSeconds) {
            $error = 'Too many failed attempts. Please wait a few minutes.';
        } else {
            $validPassword = str_starts_with($adminPassword, '$2y$')
                ? password_verify($password, $adminPassword)
                : ($adminPassword !== '' && hash_equals($adminPassword, $password));

            if ($adminUser !== '' && hash_equals($adminUser, $username) && $validPassword) {
                session_regenerate_id(true);
                $_SESSION['admin_authenticated'] = true;
                unset($_SESSION['admin_login_attempts'], $_SESSION['admin_locked_at']);
                redirectToDashboard();
            }

            $attempts++;
            $_SESSION['admin_login_attempts'] = $attempts;
            if ($attempts >= 5) {
                $_SESSION['admin_locked_at'] = time();
            }
            $error = 'Invalid administrator credentials.';
        }
    } elseif ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        redirectToDashboard();
    }
}

$isAuthenticated = !empty($_SESSION['admin_authenticated']);
$query = trim((string) ($_GET['q'] ?? ''));
$status = in_array($_GET['status'] ?? 'active', ['active', 'archived', 'all'], true) ? $_GET['status'] : 'active';
$messages = [];

if ($isAuthenticated && $dbReady && $pdo) {
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        $export = $pdo->query('SELECT id, name, email, message, created_at, read_at, archived_at FROM contact_messages ORDER BY created_at DESC')->fetchAll();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="portfolio-messages.csv"');
        $output = fopen('php://output', 'wb');
        fputcsv($output, ['id', 'name', 'email', 'message', 'created_at', 'read_at', 'archived_at']);
        foreach ($export as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    $where = [];
    $params = [];
    if ($status === 'active') {
        $where[] = 'archived_at IS NULL';
    } elseif ($status === 'archived') {
        $where[] = 'archived_at IS NOT NULL';
    }
    if ($query !== '') {
        $where[] = '(name LIKE :query OR email LIKE :query OR message LIKE :query)';
        $params[':query'] = '%' . $query . '%';
    }
    $sql = 'SELECT id, name, email, message, created_at, read_at, archived_at FROM contact_messages';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $messages = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAuthenticated && $dbReady && $pdo && hash_equals($_SESSION['admin_csrf'] ?? '', (string) ($_POST['admin_csrf'] ?? ''))) {
    $action = (string) ($_POST['action'] ?? '');
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id && in_array($action, ['read', 'unread', 'archive', 'restore', 'delete'], true)) {
        if ($action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM contact_messages WHERE id = :id');
            $stmt->execute([':id' => $id]);
        } else {
            $column = in_array($action, ['archive', 'restore'], true) ? 'archived_at' : 'read_at';
            $value = in_array($action, ['read', 'archive'], true) ? 'CURRENT_TIMESTAMP' : 'NULL';
            $stmt = $pdo->prepare("UPDATE contact_messages SET {$column} = {$value} WHERE id = :id");
            $stmt->execute([':id' => $id]);
        }
        redirectToDashboard();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Portfolio message dashboard</title>
  <style>
    body { margin: 0; padding: 2rem; font: 16px/1.5 system-ui, sans-serif; color: #17212b; background: #eef3f6; }
    main { max-width: 1000px; margin: auto; }
    section, article { background: #fff; border: 1px solid #d7e0e6; border-radius: 10px; padding: 1.25rem; margin-bottom: 1rem; }
    label { display: grid; gap: .35rem; margin-bottom: 1rem; }
    input, select { padding: .7rem; border: 1px solid #aab8c2; border-radius: 6px; }
    button, .button { display: inline-block; padding: .65rem .9rem; border: 0; border-radius: 6px; color: #fff; background: #1b6ca8; cursor: pointer; text-decoration: none; font: inherit; }
    .danger { background: #ad3434; }
    .muted { background: #53636e; }
    .error { color: #ad3434; }
    .message-meta { color: #53636e; font-size: .9rem; }
    pre { white-space: pre-wrap; font: inherit; }
    header, .toolbar, .actions { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .75rem; align-items: center; }
    .toolbar { justify-content: flex-start; margin-bottom: 1rem; }
    .unread { border-left: 5px solid #1b6ca8; }
  </style>
</head>
<body>
<main>
<?php if (!$isAuthenticated): ?>
  <section>
    <h1>Message dashboard</h1>
    <p>Administrator access is required.</p>
    <?php if ($adminUser === '' || $adminPassword === ''): ?><p class="error">Set ADMIN_USER and ADMIN_PASS in the server environment first.</p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error"><?= html($error) ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="admin_csrf" value="<?= html(adminToken()) ?>">
      <label>Username <input name="username" autocomplete="username" required></label>
      <label>Password <input type="password" name="password" autocomplete="current-password" required></label>
      <button type="submit">Sign in</button>
    </form>
  </section>
<?php else: ?>
  <header><h1>Contact messages</h1><form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="admin_csrf" value="<?= html(adminToken()) ?>"><button type="submit">Sign out</button></form></header>
  <form class="toolbar" method="get">
    <input name="q" value="<?= html($query) ?>" placeholder="Search name, email, or message">
    <select name="status"><option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option><option value="archived"<?= $status === 'archived' ? ' selected' : '' ?>>Archived</option><option value="all"<?= $status === 'all' ? ' selected' : '' ?>>All messages</option></select>
    <button type="submit">Filter</button>
    <a class="button muted" href="admin.php?export=csv">Export CSV</a>
  </form>
  <?php if (!$dbReady): ?><p class="error">Database is unavailable.</p><?php elseif (!$messages): ?><section><p>No messages found.</p></section><?php else: foreach ($messages as $message): ?>
    <article class="<?= $message['read_at'] ? '' : 'unread' ?>">
      <div class="message-meta">#<?= html((string) $message['id']) ?> · <?= html($message['created_at']) ?> · <?= html($message['email']) ?> · <?= $message['archived_at'] ? 'Archived' : 'Active' ?> · <?= $message['read_at'] ? 'Read' : 'Unread' ?></div>
      <h2><?= html($message['name']) ?></h2>
      <pre><?= html($message['message']) ?></pre>
      <div class="actions">
        <?php foreach (($message['archived_at'] ? [['restore', 'Restore']] : [['archive', 'Archive']]) as [$action, $label]): ?><form method="post"><input type="hidden" name="action" value="<?= $action ?>"><input type="hidden" name="id" value="<?= html((string) $message['id']) ?>"><input type="hidden" name="admin_csrf" value="<?= html(adminToken()) ?>"><button class="muted" type="submit"><?= $label ?></button></form><?php endforeach; ?>
        <?php $readAction = $message['read_at'] ? 'unread' : 'read'; $readLabel = $message['read_at'] ? 'Mark unread' : 'Mark read'; ?><form method="post"><input type="hidden" name="action" value="<?= $readAction ?>"><input type="hidden" name="id" value="<?= html((string) $message['id']) ?>"><input type="hidden" name="admin_csrf" value="<?= html(adminToken()) ?>"><button type="submit"><?= $readLabel ?></button></form>
        <form method="post" onsubmit="return confirm('Delete this message permanently?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= html((string) $message['id']) ?>"><input type="hidden" name="admin_csrf" value="<?= html(adminToken()) ?>"><button class="danger" type="submit">Delete</button></form>
      </div>
    </article>
  <?php endforeach; endif; ?>
<?php endif; ?>
</main>
</body>
</html>
