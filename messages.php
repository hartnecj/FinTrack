<?php
/*
|--------------------------------------------------------------------------
| messages.php
|--------------------------------------------------------------------------
| Simple internal group message board (MVP)
|
| Requirements covered:
| - Messaging (not external email)
|
| How it works:
| - Uses $_SESSION["active_group_id"] like budgets/expenses pages do
| - GET: shows last 100 messages for the active group
| - POST: inserts a new message (CSRF protected) then redirects (PRG pattern)
|--------------------------------------------------------------------------
*/

require_once __DIR__ . "/auth_guard.php";   // ensures session + user logged in + csrf token exists
require_once __DIR__ . "/config/db.php";    // PDO + BASE_PATH

$user_id  = (int)($_SESSION["user_id"] ?? 0);
$group_id = (int)($_SESSION["active_group_id"] ?? 0);

// Flash messages survive redirects
$success = '';
$error = '';

if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// If no active group is set, user can't message yet
if ($group_id <= 0) {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Messages</title>
        <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/style.css?v=5">
    </head>
    <body class="ft-page">
        <main class="container" style="padding: 30px;">
            <h1>Messages</h1>
            <p>You need to join or create a group before you can message.</p>
            <p><a href="<?= BASE_PATH ?>/groups.php">Go to Groups</a></p>
            <p><a href="<?= BASE_PATH ?>/dashboard.php">Back to Dashboard</a></p>
        </main>
    </body>
    </html>
    <?php
    exit;
}

/*
|--------------------------------------------------------------------------
| Recommended security check:
| Make sure the logged-in user is actually a member of the active group.
|--------------------------------------------------------------------------
*/
$mem = $pdo->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ? LIMIT 1");
$mem->execute([$group_id, $user_id]);
if (!$mem->fetchColumn()) {
    $_SESSION['flash_error'] = "You are not a member of the active group.";
    header("Location: " . BASE_PATH . "/groups.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| CSRF check helper
|--------------------------------------------------------------------------
*/
function require_csrf(): void {
    $posted = $_POST['csrf_token'] ?? '';
    if (!$posted || !hash_equals($_SESSION['csrf_token'], $posted)) {
        http_response_code(403);
        die("Invalid CSRF token.");
    }
}

/*
|--------------------------------------------------------------------------
| POST: Insert a new message
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $body = trim($_POST['body'] ?? '');

    // Basic validation
    if ($body === '') {
        $_SESSION['flash_error'] = "Message can't be empty.";
        header("Location: " . BASE_PATH . "/messages.php");
        exit;
    }

    // Keep messages reasonable for the MVP
    if (mb_strlen($body) > 1000) {
        $_SESSION['flash_error'] = "Message is too long (max 1000 characters).";
        header("Location: " . BASE_PATH . "/messages.php");
        exit;
    }

    // Insert message (prepared statement = safe)
    $stmt = $pdo->prepare("
        INSERT INTO messages (group_id, user_id, body)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$group_id, $user_id, $body]);

    // PRG pattern: redirect so refresh doesn't re-post
    $_SESSION['flash_success'] = "Message posted.";
    header("Location: " . BASE_PATH . "/messages.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| GET: Fetch recent messages for the active group
|--------------------------------------------------------------------------
| Join users to show sender name. Your users table uses `name`.
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        m.id,
        m.body,
        m.created_at,
        u.name AS sender_name,
        u.id AS sender_id
    FROM messages m
    JOIN users u ON u.id = m.user_id
    WHERE m.group_id = ?
    ORDER BY m.created_at ASC
    LIMIT 100
");
$stmt->execute([$group_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Messages</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/style.css?v=5">

    <style>
        /* Page layout */
        .msg-wrap { max-width: 900px; margin: 0 auto; padding: 30px; }
        .msg-list { max-height: 55vh; overflow-y: auto; padding: 12px; border-radius: 12px; background: rgba(0,0,0,0.03); }

        /* Message card */
        .msg-box  { background: #fff; border-radius: 12px; padding: 16px; margin-bottom: 14px; }

        /*
          FIX: Your global theme likely sets text to white.
          Since message cards are white, we force readable dark text inside them.
        */
        .msg-meta { font-size: 0.9rem; color: #111; opacity: 0.75; margin-bottom: 6px; }
        .msg-body { color: #111; white-space: pre-wrap; word-wrap: break-word; }

        /* Form */
        .msg-form textarea { width: 100%; min-height: 90px; padding: 10px; border-radius: 10px; }

        /* Flash messages */
        .flash { padding: 10px 12px; border-radius: 10px; margin: 10px 0; }
        .flash.success { background: #e7f7ea; color: #111; }
        .flash.error { background: #fde8e8; color: #111; }
    </style>
</head>

<body class="ft-page">
<nav>
    <ul>
        <li id="profile-btn"><a href="<?= BASE_PATH ?>/profile.php"><button class="btn" aria-label="profile">Profile</button></a></li>
        <li><a href="<?= BASE_PATH ?>/"><button class="btn" aria-label="home">Home</button></a></li>
        <li><a href="<?= BASE_PATH ?>/dashboard.php"><button class="btn" aria-label="dashboard">Dashboard</button></a></li>
        <li><a href="<?= BASE_PATH ?>/budgets.php"><button class="btn" aria-label="budgets">Budgets</button></a></li>
        <li><a href="<?= BASE_PATH ?>/expenses.php"><button class="btn" aria-label="expenses">Expenses</button></a></li>
        <li><a href="<?= BASE_PATH ?>/messages.php"><button class="btn" aria-label="messages">Messages</button></a></li>
        <li><a href="<?= BASE_PATH ?>/groups.php"><button class="btn" aria-label="groups">Groups</button></a></li>
        <li><a href="<?= BASE_PATH ?>/auth/logout.php"><button class="btn" aria-label="logout">Logout</button></a></li>
    </ul>
</nav>

<section class="main-container shadow-lg">
    <div class="msg-wrap">
        <h2>Messages</h2>
        <p>Posting to your active group.</p>

        <?php if ($success): ?>
            <div class="flash success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="flash error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div id="msgList" class="msg-list">
            <?php if (empty($messages)): ?>
                <div class="msg-box">
                    <div class="msg-meta">No messages yet</div>
                    <div class="msg-body">Be the first one to post.</div>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $m): ?>
                    <div class="msg-box">
                        <div class="msg-meta">
                            <?= htmlspecialchars($m['sender_name'] ?? 'Unknown') ?>
                            •
                            <?= htmlspecialchars($m['created_at']) ?>
                            <?php if ((int)$m['sender_id'] === $user_id): ?>
                                • you
                            <?php endif; ?>
                        </div>
                        <div class="msg-body"><?= htmlspecialchars($m['body']) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <form class="msg-form" method="POST" action="<?= BASE_PATH ?>/messages.php" style="margin-top: 14px;">
            <!-- CSRF token is created in auth_guard.php once per session -->
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <label for="body">New message</label>
            <textarea id="body" name="body" maxlength="1000" placeholder="Type your message..." aria-label="message text"></textarea>

            <button class="btn" type="submit" style="margin-top: 10px;" aria-label="post">Post message</button>
        </form>
    </div>
</section>

<script>
  // Auto-scroll to bottom so the newest messages are visible
  const msgList = document.getElementById('msgList');
  if (msgList) msgList.scrollTop = msgList.scrollHeight;
</script>

</body>
</html>
