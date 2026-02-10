<?php
require_once __DIR__ . '/../auth_guard.php';
require_once __DIR__ . '/../config/db.php';

$user_id = $_SESSION["user_id"];
$error = '';

// Handle POST request for creating a group
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $group_name = trim($_POST['group_name'] ?? '');
    $group_password = $_POST['group_password'] ?? '';

    // Validate input
    if ($group_name === '' || $group_password === '') {
        $error = 'Please provide both group name and password.';
    } else {
        // Check if user is already in a group
        $stmt = $pdo->prepare('SELECT group_id FROM group_members WHERE user_id = ? LIMIT 1');
        $stmt->execute([$user_id]);

        if ($stmt->fetch()) { // User is already in a group
            $error = 'You are already in a group. Please leave your current group first.';
        } else {
            // Check if group name already exists
            // This is a simple check to prevent duplicate group names
            // Maybe in the future we can allow duplicate names but use unique IDs for groups instead
            $stmt = $pdo->prepare('SELECT id FROM groups WHERE name = ? LIMIT 1');
            $stmt->execute([$group_name]);
            if ($stmt->fetch()) {
                $error = 'A group with that name already exists. Please choose a different name.';
            } else {
                // Create the group
                $password_hash = password_hash($group_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO groups (name, password_hash, owner_id) VALUES (?, ?, ?)');
                $stmt->execute([$group_name, $password_hash, $user_id]);

                $group_id = $pdo->lastInsertId();

                // Add creator as first member
                $stmt = $pdo->prepare('INSERT INTO group_members (group_id, user_id) VALUES (?, ?)');
                $stmt->execute([$group_id, $user_id]);

                // Redirect back to groups page
                header('Location: /groups.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>FinTrack - Create Group</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">
    <script src="https://code.jquery.com/jquery-4.0.0.js" integrity="sha256-9fsHeVnKBvqh3FB2HYu7g2xseAZ5MlN6Kz/qnkASV8U=" crossorigin="anonymous"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/css/style.css">
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

    <script src="/assets/js/pageCustomization.js"></script>
</body>
</html>
