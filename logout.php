<?php
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/auth_helpers.php';

if (isset($_SESSION['kullanici_id']) && !empty($_COOKIE['hatirla_beni'])) {
    hatirla_cookie_clear($db, (int)$_SESSION['kullanici_id']);
}

session_destroy();
header("Location: index.php");
exit;
