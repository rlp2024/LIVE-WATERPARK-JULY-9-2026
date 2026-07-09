<?php
$DB_HOST = 'localhost';
$DB_NAME = 'awpit_ajman_waterpark';
$DB_USER = 'awpit_it2026';
$DB_PASS = 'ajmanwaterpark';

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     // 1. Gagawa muna ng connection
     $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, $options);
     
     // 2. Pagka-connect, utusan ang database na gamitin ang Dubai Time (+04:00)
     $pdo->exec("SET time_zone = '+04:00';");

} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

// --- HEARTBEAT STATUS TRACKER ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Kapag naka-login ang staff/admin, i-update ang oras ng huling galaw
if (isset($_SESSION['admin_id'])) {
    try {
        // Dahil nag SET time_zone na tayo sa itaas, ang NOW() dito ay Dubai Time na.
        $stmtUpdate = $pdo->prepare("UPDATE admins SET last_active_at = NOW() WHERE admin_id = ?");
        $stmtUpdate->execute([$_SESSION['admin_id']]);
    } catch (Exception $e) {
        // Silent fail
    }
}
?>