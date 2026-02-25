<?php
session_start();

require_once __DIR__ . "/config/db.php"; // added for base path on db.php

// changed for db.php BASE_PATH auto-detect
if (!empty($_SESSION['user_id'])) {
    header("Location: " . BASE_PATH . "/dashboard.php");
    exit;
}
// changed for db.php BASE_PATH auto-detect
header("Location: " . BASE_PATH . "/landing.php");
exit;
