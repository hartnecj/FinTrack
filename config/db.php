<?php

$DB_HOST = "localhost";
$DB_NAME = "u431967787_uWdMBMh9X_FinTrack";
$DB_USER = "u431967787_uWdMBMh9X_FinTrack";
$DB_PASS = "F1nTr@ck";

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Database connection failed.";
    exit;
}
