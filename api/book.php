<?php
/** Enregistre une reservation d'audit (calendrier maison) + notifie par e-mail. */
require __DIR__ . '/guard.php';
require __DIR__ . '/config_load.php';
require __DIR__ . '/db.php';

finalyn_json_out();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') finalyn_die(405, 'Methode non autorisee.');
finalyn_check_origin();
finalyn_rate_limit('book', 8, 600);

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) finalyn_die(400, 'Requete invalide.');

function finalyn_field($body, $key, $max = 120) {
    $v = isset($body[$key]) && is_string($body[$key]) ? trim($body[$key]) : '';
    return mb_substr($v, 0, $max);
}

$firstname = finalyn_field($body, 'firstname', 80);
$lastname  = finalyn_field($body, 'lastname', 80);
$email     = finalyn_field($body, 'email', 160);
$company   = finalyn_field($body, 'company', 120);
$date      = finalyn_field($body, 'date', 10);
$time      = finalyn_field($body, 'time', 5);
$message   = finalyn_field($body, 'message', 500);

// Validation
if ($firstname === '' || $lastname === '' || $email === '' || $company === '' || $date === '' || $time === '') {
    finalyn_die(400, 'Merci de remplir tous les champs.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) finalyn_die(400, 'Adresse e-mail invalide.');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) finalyn_die(400, 'Date invalide.');
if (!preg_match('/^\d{2}:\d{2}$/', $time)) finalyn_die(400, 'Heure invalide.');

// Date dans le futur et jour ouvre (lundi-vendredi)
$ts = strtotime($date . ' ' . $time . ':00');
if ($ts === false || $ts < time() - 3600) finalyn_die(400, 'Ce creneau est deja passe.');
$weekday = (int)date('N', strtotime($date)); // 1=lundi .. 7=dimanche
if ($weekday >= 6) finalyn_die(400, 'Les audits ont lieu du lundi au vendredi.');

$pdo = finalyn_db();

// Date bloquee par l'admin ?
$chk = $pdo->prepare('SELECT 1 FROM blocked_dates WHERE slot_date = ?');
$chk->execute([$date]);
if ($chk->fetchColumn()) finalyn_die(409, 'Ce jour n\'est plus disponible. Merci d\'en choisir un autre.');

// Creneau deja pris ?
$dup = $pdo->prepare("SELECT 1 FROM bookings WHERE slot_date = ? AND slot_time = ? AND status != 'cancelled'");
$dup->execute([$date, $time]);
if ($dup->fetchColumn()) finalyn_die(409, 'Ce creneau vient d\'etre reserve. Merci d\'en choisir un autre.');

$now = gmdate('Y-m-d H:i:s');
$ins = $pdo->prepare("INSERT INTO bookings (created_at, firstname, lastname, email, company, slot_date, slot_time, message, status)
                      VALUES (?,?,?,?,?,?,?,?, 'confirmed')");
$ins->execute([$now, $firstname, $lastname, $email, $company, $date, $time, $message]);

// Notification e-mail (best effort, ne bloque pas la reservation)
$cfg = finalyn_config();
$to = $cfg['notify_email'] ?? '';
if ($to !== '') {
    $subject = 'Nouvelle reservation audit : ' . $firstname . ' ' . $lastname;
    $lines = [
        "Nouvelle reservation d'audit via finalyn.com",
        '',
        'Nom       : ' . $firstname . ' ' . $lastname,
        'E-mail    : ' . $email,
        'Entreprise: ' . $company,
        'Creneau   : ' . $date . ' a ' . $time . ' (heure de Zurich)',
    ];
    if ($message !== '') { $lines[] = ''; $lines[] = 'Message   : ' . $message; }
    $headers = "From: site@finalyn.com\r\nReply-To: " . $email . "\r\nContent-Type: text/plain; charset=utf-8\r\n";
    @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', implode("\n", $lines), $headers);
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
