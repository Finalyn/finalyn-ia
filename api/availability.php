<?php
/** Renvoie les dates indisponibles (bloquees par l'admin ou completes) pour le calendrier public. */
require __DIR__ . '/guard.php';
require __DIR__ . '/db.php';

finalyn_json_out();
header('Cache-Control: public, max-age=120');
finalyn_check_origin();

$today = gmdate('Y-m-d');
$blocked = [];

try {
    $pdo = finalyn_db();

    // Dates explicitement bloquees (a venir)
    $stmt = $pdo->prepare('SELECT slot_date FROM blocked_dates WHERE slot_date >= ?');
    $stmt->execute([$today]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) { $blocked[$d] = true; }

    // Jours dont tous les creneaux sont pris (8 creneaux : 08-11h, 14-17h)
    $stmt = $pdo->prepare("SELECT slot_date, COUNT(DISTINCT slot_time) AS n
                           FROM bookings WHERE status != 'cancelled' AND slot_date >= ?
                           GROUP BY slot_date HAVING n >= 8");
    $stmt->execute([$today]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) { $blocked[$d] = true; }
} catch (Throwable $e) {
    error_log('finalyn availability: ' . $e->getMessage());
}

echo json_encode(['blocked' => array_keys($blocked)], JSON_UNESCAPED_UNICODE);
