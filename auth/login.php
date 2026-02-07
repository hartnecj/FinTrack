<?php
session_start();
require_once __DIR__ . "/../config/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  header("Location: /index.php?mode=login");
  exit;
}

$email = strtolower(trim($_POST["email"] ?? ""));
$password = $_POST["password"] ?? "";

if ($email === "" || $password === "") {
  $_SESSION["flash_error"] = "Enter email and password.";
  header("Location: /index.php?mode=login");
  exit;
}

$stmt = $pdo->prepare("SELECT id, name, password_hash FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user["password_hash"])) {
  $_SESSION["flash_error"] = "Invalid email or password.";
  header("Location: /index.php?mode=login");
  exit;
}

$_SESSION["user_id"] = (int)$user["id"];
$_SESSION["user_name"] = $user["name"];

header("Location: /dashboard.php");
exit;
