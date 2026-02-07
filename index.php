<?php
session_start();

$mode = $_GET["mode"] ?? "login";
if ($mode !== "login" && $mode !== "register") {
  $mode = "login";
}

$flash_error = $_SESSION["flash_error"] ?? "";
unset($_SESSION["flash_error"]);

$flash_success = $_SESSION["flash_success"] ?? "";
unset($_SESSION["flash_success"]);

if (isset($_SESSION["user_id"])) {
  header("Location: /dashboard.php");
  exit;
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>FinTrack Auth</title>
</head>
<body>
  <h2>FinTrack</h2>

  <?php if ($flash_error): ?>
    <p style="color:red;"><?php echo htmlspecialchars($flash_error); ?></p>
  <?php endif; ?>

  <?php if ($flash_success): ?>
    <p style="color:green;"><?php echo htmlspecialchars($flash_success); ?></p>
  <?php endif; ?>

  <p>
    <a href="/index.php?mode=login">Login</a>
    |
    <a href="/index.php?mode=register">Register</a>
  </p>

  <?php if ($mode === "register"): ?>
    <h3>Create account</h3>
    <form method="POST" action="/auth/register.php">
      <label>Name</label><br>
      <input type="text" name="name" required><br><br>

      <label>Email</label><br>
      <input type="email" name="email" required><br><br>

      <label>Password</label><br>
      <input type="password" name="password" required><br><br>

      <label>Confirm Password</label><br>
      <input type="password" name="confirm_password" required><br><br>

      <button type="submit">Register</button>
    </form>

  <?php else: ?>
    <h3>Login</h3>
    <form method="POST" action="/auth/login.php">
      <label>Email</label><br>
      <input type="email" name="email" required><br><br>

      <label>Password</label><br>
      <input type="password" name="password" required><br><br>

      <button type="submit">Login</button>
    </form>
  <?php endif; ?>
</body>
</html>
