<?php
declare(strict_types=1);

session_start();

if (isset($_SESSION["user_id"])) {
  header("Location: /dashboard.php");
  exit;
}

$mode = $_GET["mode"] ?? "login";
if ($mode !== "login" && $mode !== "register") {
  $mode = "login";
}

$flash_error = $_SESSION["flash_error"] ?? "";
unset($_SESSION["flash_error"]);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>FinTrack</title>
</head>
<body>
  <h2>FinTrack</h2>

  <?php if ($flash_error): ?>
    <p><?php echo htmlspecialchars($flash_error); ?></p>
  <?php endif; ?>

  <p>
    <a href="/index.php?mode=login">Login</a> |
    <a href="/index.php?mode=register">Register</a>
  </p>

  <?php if ($mode === "register"): ?>
    <form method="POST" action="/auth/register.php">
      <input type="text" name="name" placeholder="Name" required><br>
      <input type="email" name="email" placeholder="Email" required><br>
      <input type="password" name="password" placeholder="Password" required><br>
      <input type="password" name="confirm_password" placeholder="Confirm Password" required><br>
      <button type="submit">Register</button>
    </form>
  <?php else: ?>
    <form method="POST" action="/auth/login.php">
      <input type="email" name="email" placeholder="Email" required><br>
      <input type="password" name="password" placeholder="Password" required><br>
      <button type="submit">Login</button>
    </form>
  <?php endif; ?>
</body>
</html>
