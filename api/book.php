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

/** Envoi d'un e-mail texte UTF-8 (best effort). */
function finalyn_send_mail($to, $subject, $body, $from, $replyTo) {
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $headers = 'From: finalyn.ia <' . $from . '>' . "\r\n"
             . 'Reply-To: ' . $replyTo . "\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n";
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return @mail($to, $subj, rtrim(chunk_split(base64_encode($body))), $headers);
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

// ----- E-mails (best effort, ne bloquent jamais la reservation) -----
$cfg  = finalyn_config();
$team = $cfg['notify_email'] ?? '';
$from = $cfg['from_email'] ?? 'contact@finalyn.com';

$frMonths = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$dts = strtotime($date);
$dateFr = (int)date('j', $dts) . ' ' . $frMonths[(int)date('n', $dts)] . ' ' . date('Y', $dts);

// 1) Notification a l'equipe finalyn (Reply-To = client, pour repondre directement)
if ($team !== '') {
    $tBody = "Nouvelle reservation d'audit via finalyn.com\n\n"
        . 'Nom        : ' . $firstname . ' ' . $lastname . "\n"
        . 'E-mail     : ' . $email . "\n"
        . 'Entreprise : ' . $company . "\n"
        . 'Creneau    : ' . $dateFr . ' a ' . $time . " (heure de Zurich)\n";
    if ($message !== '') { $tBody .= "\nMessage    : " . $message . "\n"; }
    finalyn_send_mail($team, 'Nouvelle reservation : ' . $firstname . ' ' . $lastname . ' (' . $company . ')', $tBody, $from, $email);
}

// 2) Confirmation au client (Reply-To = equipe finalyn)
$cBody = "Bonjour " . $firstname . ",\n\n"
    . "Votre audit gratuit avec finalyn.ia est bien confirmé.\n\n"
    . "Date : " . $dateFr . " à " . $time . " (heure de Zurich)\n"
    . "Format : visioconférence, environ 30 minutes\n\n"
    . "Nous vous enverrons le lien de connexion peu avant le rendez-vous. "
    . "Si vous avez une question ou besoin de décaler le créneau, répondez simplement à cet e-mail.\n\n"
    . "À très bientôt,\n"
    . "L'équipe finalyn.ia\n"
    . "contact@finalyn.com · +41 79 639 36 84";
finalyn_send_mail($email, 'Votre audit est confirmé · finalyn.ia', $cBody, $from, ($team !== '' ? $team : $from));

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
