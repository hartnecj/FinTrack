<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . "/../config/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  header("Location: /index.php?mode=register");
  exit;
}

$name = trim($_POST["name"] ?? "");
$email = strtolower(trim($_POST["email"] ?? ""));
$password = $_POST["password"] ?? "";
$confirm = $_POST["confirm_password"] ?? "";

if ($name === "" || $email === "" || $password === "" || $confirm === "") {
  $_SESSION["flash_error"] = "Please fill out all fields.";
  header("Location: /index.php?mode=register");
  exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  $_SESSION["flash_error"] = "Please enter a valid email.";
  header("Location: /index.php?mode=register");
  exit;
}

if ($password !== $confirm || strlen($password) < 8) {
  $_SESSION["flash_error"] = "Invalid password.";
  header("Location: /index.php?mode=register");
  exit;
}

$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
  $_SESSION["flash_error"] = "That email is already registered.";
  header("Location: /index.php?mode=register");
  exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)");
$stmt->execute([$name, $email, $hash]);

session_regenerate_id(true);
$_SESSION["user_id"] = (int)$pdo->lastInsertId();
$_SESSION["user_name"] = $name;

header("Location: /dashboard.php");
exit;
