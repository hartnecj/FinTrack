<?php
session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: /dashboard.php');
    exit;
}

header('Location: /landing.php');
exit;
