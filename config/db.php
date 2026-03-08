<?php
// BASE_PATH auto-detect (supports root and any 1-level subfolder like /testing or /myTestFile-Mary)
if (!defined('BASE_PATH')) {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $parts = explode('/', trim($scriptName, '/'));

    // If running from root (e.g., /dashboard.php), BASE_PATH is empty.
    // If running from a folder (e.g., /testing/dashboard.php), BASE_PATH is /testing.
    define('BASE_PATH', (count($parts) > 1) ? '/' . $parts[0] : '');
}

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

// end of file. 
