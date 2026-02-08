<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION["user_id"])) {
  header("Location: /index.php?mode=login");
  exit;
}

$name = $_SESSION["user_name"] ?? "User";
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Dashboard</title>
</head>
<body>
  <h2>Dashboard</h2>

  <p>Welcome, <?php echo htmlspecialchars($name); ?>.</p>

  <p><a href="/groups.php">Groups</a></p>
  <p><a href="/budgets.php">Budgets</a></p>
  <p><a href="/expenses.php">Expenses</a></p>

  <p><a href="/auth/logout.php">Logout</a></p>
</body>
</html>
