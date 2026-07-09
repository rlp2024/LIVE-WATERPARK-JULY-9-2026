<?php
include_once 'admin_audit.php';

// admin_audit.php
function adminAuthLog(PDO $pdo, string $action, array $admin = [], string $notes = ''): void
{
    try {
        $admin_id  = $admin['admin_id'] ?? ($_SESSION['admin_id'] ?? null);
        $username  = $admin['username'] ?? ($_SESSION['admin_user'] ?? null);
        $fullname  = $admin['full_name'] ?? ($_SESSION['admin_fullname'] ?? null);
        $role      = $admin['role'] ?? ($_SESSION['admin_role'] ?? null);

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $pdo->prepare("
            INSERT INTO admin_auth_logs (admin_id, username, full_name, role, action, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$admin_id, $username, $fullname, $role, $action, $ip, $ua]);

    } catch (Exception $e) {
        // silent lang para di masira login/logout flow
    }
}
