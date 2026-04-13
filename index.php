
// updated to use bathpath
<?php
session_start();
require_once __DIR__ . "/config/db.php";

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_PATH . '/dashboard.php');
    exit;
}

header('Location: ' . BASE_PATH . '/landing.php');
exit;