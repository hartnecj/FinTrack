<?php
/*
|--------------------------------------------------------------------------
| auth/logout.php
|--------------------------------------------------------------------------
| Notes:
| - Destroys the session and redirects to the actual landing page
| - Original redirects to a /views path that doesn't exist in this build
|--------------------------------------------------------------------------
*/
session_start();
$_SESSION = [];
session_destroy();

header("Location: /landing.php");
exit;
