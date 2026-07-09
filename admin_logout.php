<?php
session_start();
include_once 'db_connect.php';
include_once 'admin_audit.php';

adminAuthLog($pdo, 'LOGOUT');

session_unset();
session_destroy();

header("Location: admin_login.php");
exit;
