<?php
include_once 'db_connect.php';
header('Content-Type: application/json');

$calendar_booking_counts = [];

try {
    $stmt = $pdo->query("
        SELECT
            b.visit_date AS booking_day,
            COUNT(ti.ticket_id) AS total_bookings
        FROM bookings b
        INNER JOIN ticket_instances ti
            ON ti.booking_id = b.booking_id
        WHERE b.visit_date IS NOT NULL
          AND b.visit_date >= CURDATE()
          AND b.payment_status = 'paid'
        GROUP BY b.visit_date
        ORDER BY b.visit_date ASC
    ");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $calendar_booking_counts[$row['booking_day']] = (int)$row['total_bookings'];
    }

    echo json_encode([
        'success' => true,
        'counts' => $calendar_booking_counts
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'counts' => []
    ]);
}