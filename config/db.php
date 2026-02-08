<?php
declare(strict_types=1);

$DB_HOST = "localhost";
$DB_NAME = "u431967787_uWdMBMh9X_FinTrack";
$DB_USER = "u431967787_uWdMBMh9X_FinTrack";
$DB_PASS = "F1nTr@ck";

try {
  $pdo = new PDO(
    "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
  );
} catch (PDOException $e) {
  http_response_code(500);
  exit("Database connection failed.");
}
