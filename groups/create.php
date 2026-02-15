<?php
/*
|--------------------------------------------------------------------------
| groups/create.php
|--------------------------------------------------------------------------
| Original group creation logic was implemented by teammate.
|
| Enhancements added:
| - CSRF protection
| - Database transaction (atomic create group + add membership)
| - Sets active_group_id in session so budgets/expenses know which group to use
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
        // Check if group name already exists (DB also enforces if uq_groups_name was added)
        $stmt = $pdo->prepare('SELECT id FROM groups WHERE name = ? LIMIT 1');
        $stmt->execute([$group_name]);

        if ($stmt->fetch()) {
            $error = 'A group with that name already exists. Please choose a different name.';
        } else {
            try {
                // Transaction ensures we don’t create a group without adding the owner as a member
                $pdo->beginTransaction();

                $password_hash = password_hash($group_password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare('INSERT INTO groups (name, password_hash, owner_id) VALUES (?, ?, ?)');
                $stmt->execute([$group_name, $password_hash, $user_id]);

                $group_id = (int)$pdo->lastInsertId();

                $stmt = $pdo->prepare('INSERT INTO group_members (group_id, user_id) VALUES (?, ?)');
                $stmt->execute([$group_id, $user_id]);

                $pdo->commit();

                // This becomes the current context for budgets/expenses later
                $_SESSION['active_group_id'] = $group_id;

                header('Location: /groups.php');
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Something went wrong creating the group. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>FinTrack - Create Group</title>
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
        <h2>Create a Group</h2>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post" action="/groups/create.php" style="margin-top: 30px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="mb-3">
                <label for="group_name" class="form-label">Group Name</label>
                <input type="text" class="form-control" id="group_name" name="group_name" required>
            </div>

            <div class="mb-3">
                <label for="group_password" class="form-label">Group Password</label>
                <input type="password" class="form-control" id="group_password" name="group_password" required>
            </div>

            <button type="submit" class="btn">Create Group</button>
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
