<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../PHPMailer/Exception.php';
require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_email = trim($_POST['email'] ?? '');

    if ($input_email === '') {
        $error = 'Please enter your email address.';
    } else {
        $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$input_email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Clean up any existing tokens for this user
            $stmt = $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?');
            $stmt->execute([$user['id']]);

            $token      = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', time() + 3600);

            $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)');
            $stmt->execute([$user['id'], $token, $expires_at]);

            $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $reset_link = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/auth/reset_password.php?token=' . $token;

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USER;
                $mail->Password   = SMTP_PASS;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = SMTP_PORT;

                $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
                $mail->addAddress($user['email']);
                $mail->Subject = 'FinTrack – Password Reset';
                $mail->isHTML(true);
                $mail->Body = '
                    <p>You requested a password reset for your FinTrack account.</p>
                    <p><a href="' . htmlspecialchars($reset_link) . '">Click here to reset your password</a></p>
                    <p>This link expires in 1 hour. If you did not request this, you can ignore this email.</p>
                ';
                $mail->AltBody = "Reset your FinTrack password: $reset_link\n\nThis link expires in 1 hour.";
                $mail->send();
            } catch (Exception $e) {
                $error = 'Could not send email: ' . $mail->ErrorInfo;
            }
        }

        // Always show success to avoid revealing whether the email exists
        if ($error === '') {
            $success = 'If that email is registered, a reset link has been sent.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>FinTrack - Forgot Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/style.css?v=5">
</head>
<body class="ft-page">

<nav>
    <ul>
        <li><a href="/landing.php"><button class="btn">Home</button></a></li>
        <li><a href="/auth/login.php"><button class="btn">Login</button></a></li>
        <li><a href="/auth/register.php"><button class="btn">Register</button></a></li>
    </ul>
</nav>

<section class="main-container shadow-lg p-5">
    <h4>Forgot Password</h4>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <a href="/auth/login.php" class="btn w-100 mt-2">Back to Login</a>

    <?php else: ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/auth/forgot_password.php">
            <div class="mb-3">
                <label for="email">Email address</label>
                <input type="email" id="email" name="email" class="form-control" required>
            </div>
            <button type="submit" class="btn w-100">Send Reset Link</button>
        </form>
        <p class="text-center mt-3"><a href="/auth/login.php">Back to Login</a></p>
    <?php endif; ?>
</section>

</body>
</html>
