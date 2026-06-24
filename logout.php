<?php
/*
 * logout.php — cierra la sesión y vuelve al login.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
$_SESSION = [];
session_destroy();
header('Location: /login.php');
exit;