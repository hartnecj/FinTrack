<?php
require_once __DIR__ . '/../config/db.php';

$token = trim($_GET['token'] ?? '');
$error = '';
$success = '';

// Validate token on every request
$reset_row = null;
if ($token !== '') {
    $stmt = $pdo->prepare("
        SELECT pr.id, pr.user_id, pr.expires_at, u.email
        FROM password_resets pr
        JOIN users u ON pr.user_id = u.id
        WHERE pr.token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $reset_row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reset_row) {
        $error = 'This reset link is invalid.';
    } elseif (strtotime($reset_row['expires_at']) < time()) {
        $error = 'This reset link has expired. Please request a new one from your profile.';
        $reset_row = null;
    }
}

if ($token === '') {
    $error = 'No reset token provided.';
}

// Handle new password submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset_row) {
    $new_password         = $_POST['password'] ?? '';
    $new_password_confirm = $_POST['passwordConfirm'] ?? '';

    if ($new_password === '') {
        $error = 'Please enter a new password.';
    } elseif (strlen($new_password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($new_password !== $new_password_confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hashed, $reset_row['user_id']]);

        // Invalidate the token
        $stmt = $pdo->prepare('DELETE FROM password_resets WHERE id = ?');
        $stmt->execute([$reset_row['id']]);

        $success = 'Password updated. You can now log in with your new password.';
        $reset_row = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>FinTrack - Reset Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/style.css?v=5">
</head>
<body class="ft-page">

<nav>
    <ul>
        <li><a href="/landing.php"><button class="btn">Home</button></a></li>
        <li><a href="/auth/login.php"><button class="btn">Login</button></a></li>
    </ul>
</nav>

<section class="main-container shadow-lg p-5">
    <h4>Reset Password</h4>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <a href="/auth/login.php" class="btn w-100">Go to Login</a>

    <?php elseif ($error !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>

    <?php else: ?>
        <form method="POST" action="/auth/reset_password.php?token=<?= htmlspecialchars($token) ?>">
            <div class="mb-3">
                <label for="password">New Password</label>
                <input type="password" id="password" name="password" class="form-control" required minlength="8">
            </div>
            <div class="mb-3">
                <label for="passwordConfirm">Confirm New Password</label>
                <input type="password" id="passwordConfirm" name="passwordConfirm" class="form-control" required>
            </div>
            <button type="submit" class="btn w-100">Set New Password</button>
        </form>
    <?php endif; ?>
</section>

</body>
</html>
