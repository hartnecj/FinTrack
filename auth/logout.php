<?php
session_start();
$_SESSION = [];
session_destroy();
header("Location: /views/landing.html");
exit;
