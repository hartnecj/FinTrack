<?php
/*
|--------------------------------------------------------------------------
| groups/join.php
|--------------------------------------------------------------------------
| Original join logic was implemented by teammate:
| - Look up group by name
| - Verify password_hash using password_verify
| - Insert membership into group_members
|
| Enhancements added:
| - CSRF protection
| - Prevent duplicate membership with friendly message
| - Sets active_group_id in session for future budgets/expenses context
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../config/db.php';

$user_id = (int)$_SESSION["user_id"];
$error = '';

function require_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $posted = $_POST['csrf_token'] ?? '';
        if (!$posted || !hash_equals($_SESSION['csrf_token'], $posted)) {
            http_response_code(403);
            die("Invalid CSRF token.");
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $group_name = trim($_POST['group_name'] ?? '');
    $group_password = $_POST['group_password'] ?? '';

    if ($group_name === '' || $group_password === '') {
        $error = 'Please provide both group name and password.';
    } else {
        // Find the group by name
        $stmt = $pdo->prepare('SELECT id, password_hash FROM groups WHERE name = ? LIMIT 1');
        $stmt->execute([$group_name]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$group) {
            $error = 'Group not found.';
        } elseif (!password_verify($group_password, $group['password_hash'])) {
            $error = 'Incorrect password.';
        } else {
            $group_id = (int)$group['id'];

            // Prevent duplicates (DB also blocks if uq_group_members exists)
            $stmt = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$group_id, $user_id]);

            if ($stmt->fetch()) {
                $error = 'You are already a member of that group.';
            } else {
                try {
                    $stmt = $pdo->prepare('INSERT INTO group_members (group_id, user_id) VALUES (?, ?)');
                    $stmt->execute([$group_id, $user_id]);

                    // Set active group so budgets/expenses know what group to use
                    $_SESSION['active_group_id'] = $group_id;

                    header('Location: /groups.php');
                    exit;
                } catch (Exception $e) {
                    $error = 'Could not join group. Please try again.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>FinTrack - Join Group</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-4.0.0.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- NOTE: Corrected path to match actual project structure -->
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav>
    <ul>
        <li><a href="/"><button class="btn">Home</button></a></li>
        <li><a href="/dashboard.php"><button class="btn">Dashboard</button></a></li>
        <li><a href="/budgets.php"><button class="btn">Budgets</button></a></li>
        <li><a href="/expenses.php"><button class="btn">Expenses</button></a></li>
        <li><a href="/groups.php"><button class="btn">Groups</button></a></li>
        <li><a href="/auth/logout.php"><button class="btn">Logout</button></a></li>
    </ul>
</nav>

<section class="main-container shadow-lg">
    <div style="padding: 5%;">
        <h2>Join a Group</h2>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post" action="/groups/join.php" style="margin-top: 30px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="mb-3">
                <label for="group_name" class="form-label">Group Name</label>
                <input type="text" class="form-control" id="group_name" name="group_name" required>
            </div>

            <div class="mb-3">
                <label for="group_password" class="form-label">Group Password</label>
                <input type="password" class="form-control" id="group_password" name="group_password" required>
            </div>

            <button type="submit" class="btn">Join Group</button>
        </form>

        <p style="margin-top: 30px;"><a href="/groups.php">Back to groups</a></p>
    </div>
</section>

<footer>
    <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch" id="styleSwitch">
        <label class="form-check-label" for="styleSwitch" id="styleLabel"> Light mode: On </label>
    </div>
</footer>

<!-- NOTE: Corrected path to match actual project structure -->
<script src="/assets/pageCustomization.js"></script>
</body>
</html>
