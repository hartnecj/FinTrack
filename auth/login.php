<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . "/../config/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  header("Location: /index.php?mode=login");
  exit;
}

$email = strtolower(trim($_POST["email"] ?? ""));
$password = $_POST["password"] ?? "";

if ($email === "" || $password === "") {
  $_SESSION["flash_error"] = "Invalid login.";
  header("Location: /index.php?mode=login");
  exit;
}

$stmt = $pdo->prepare("SELECT id, name, password_hash FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user["password_hash"])) {
  $_SESSION["flash_error"] = "Invalid login.";
  header("Location: /index.php?mode=login");
  exit;
}

session_regenerate_id(true);
$_SESSION["user_id"] = (int)$user["id"];
$_SESSION["user_name"] = $user["name"];

header("Location: /dashboard.php");
exit;
