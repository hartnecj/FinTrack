<?php
/*
|--------------------------------------------------------------------------
| auth_guard.php
|--------------------------------------------------------------------------
| Purpose:
| - Start session if needed
| - Block access to protected pages if user is not logged in
|
| Notes:
| - Original file redirected to /views/login.html (not in this project)
| - Updated redirect to /auth/login.php to match our folder structure
| - Added CSRF token generation so all POST actions can be protected
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CSRF token is used to protect POST requests from forged submissions.
// This gets created once per session.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// If not logged in, send user to our actual login page.
if (empty($_SESSION["user_id"])) {
    header("Location: /FinTrack/auth/login.php");
    exit;
}
