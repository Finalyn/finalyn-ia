<?php
/** Disponibilites du calendrier public : jours ouvres, creneaux, dates bloquees/completes. */
require __DIR__ . '/guard.php';
require __DIR__ . '/db.php';
require __DIR__ . '/settings.php';

finalyn_json_out();
header('Cache-Control: public, max-age=60');
finalyn_check_origin();

$avail = finalyn_avail();
$today = gmdate('Y-m-d');
$blocked = [];

try {
    $pdo = finalyn_db();
    $slotCount = max(1, count($avail['slots']));

    $stmt = $pdo->prepare('SELECT slot_date FROM blocked_dates WHERE slot_date >= ?');
    $stmt->execute([$today]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) { $blocked[$d] = true; }

    $stmt = $pdo->prepare("SELECT slot_date FROM bookings WHERE status != 'cancelled' AND slot_date >= ?
                           GROUP BY slot_date HAVING COUNT(DISTINCT slot_time) >= ?");
    $stmt->execute([$today, $slotCount]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) { $blocked[$d] = true; }
} catch (Throwable $e) {
    error_log('finalyn availability: ' . $e->getMessage());
}

echo json_encode([
    'blocked'  => array_keys($blocked),
    'days'     => $avail['days'],
    'slots'    => $avail['slots'],
    'duration' => $avail['duration'],
], JSON_UNESCAPED_UNICODE);
