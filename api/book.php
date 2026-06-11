<?php
/** Enregistre une reservation d'audit (calendrier maison) + notifie par e-mail. */
require __DIR__ . '/guard.php';
require __DIR__ . '/config_load.php';
require __DIR__ . '/db.php';
require __DIR__ . '/settings.php';

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

/** Envoi d'un e-mail UTF-8 (best effort), avec invitation .ics optionnelle. */
function finalyn_send_mail($to, $subject, $body, $from, $replyTo, $ics = null) {
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $textPart = rtrim(chunk_split(base64_encode($body)));

    if ($ics === null) {
        $headers = 'From: finalyn.ia <' . $from . '>' . "\r\n"
                 . 'Reply-To: ' . $replyTo . "\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: base64\r\n";
        return @mail($to, $subj, $textPart, $headers);
    }

    $b = 'fin_' . bin2hex(random_bytes(8));
    $headers = 'From: finalyn.ia <' . $from . '>' . "\r\n"
             . 'Reply-To: ' . $replyTo . "\r\n"
             . "MIME-Version: 1.0\r\n"
             . 'Content-Type: multipart/mixed; boundary="' . $b . '"' . "\r\n";
    $msg = '--' . $b . "\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n"
         . "Content-Transfer-Encoding: base64\r\n\r\n"
         . $textPart . "\r\n"
         . '--' . $b . "\r\n"
         . "Content-Type: text/calendar; method=REQUEST; charset=UTF-8; name=\"audit.ics\"\r\n"
         . "Content-Transfer-Encoding: base64\r\n"
         . "Content-Disposition: attachment; filename=\"audit.ics\"\r\n\r\n"
         . rtrim(chunk_split(base64_encode($ics))) . "\r\n"
         . '--' . $b . "--\r\n";
    return @mail($to, $subj, $msg, $headers);
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

// ----- E-mails + invitation agenda (best effort, ne bloquent jamais la reservation) -----
$cfg      = finalyn_config();
$team     = $cfg['notify_email'] ?? '';
$from      = $cfg['from_email'] ?? 'noreply@finalyn.com';
$organizer = ($team !== '' && filter_var($team, FILTER_VALIDATE_EMAIL)) ? $team : 'contact@finalyn.com';
$duration  = max(15, (int)(finalyn_avail()['duration'] ?? 30));

$frMonths = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$dts = strtotime($date);
$dateFr = (int)date('j', $dts) . ' ' . $frMonths[(int)date('n', $dts)] . ' ' . date('Y', $dts);

// Creneau en UTC (a partir de l'heure de Zurich) pour l'ICS et le lien agenda
$startStr = $endStr = $stampStr = '';
try {
    $tz = new DateTimeZone('Europe/Zurich'); $utc = new DateTimeZone('UTC');
    $start = new DateTime($date . ' ' . $time . ':00', $tz);
    $end = (clone $start)->modify('+' . $duration . ' minutes');
    $start->setTimezone($utc); $end->setTimezone($utc);
    $startStr = $start->format('Ymd\THis\Z');
    $endStr   = $end->format('Ymd\THis\Z');
    $stampStr = (new DateTime('now', $utc))->format('Ymd\THis\Z');
} catch (Throwable $e) {}

// Invitation .ics (METHOD:REQUEST) + lien Google Agenda
$ics = null; $gcal = '';
if ($startStr !== '') {
    $esc = function ($s) { return str_replace([',', ';', "\n"], ['\\,', '\\;', '\\n'], $s); };
    $sum = 'Audit finalyn.ia (visio) - ' . $firstname . ' ' . $lastname;
    $desc = "Audit gratuit de 30 min en visioconference avec finalyn.ia. Le lien de connexion vous sera envoye avant le rendez-vous.";
    $uid = 'audit-' . $date . '-' . str_replace(':', '', $time) . '-' . substr(md5($email), 0, 8) . '@finalyn.com';
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//finalyn.ia//Audit//FR\r\nCALSCALE:GREGORIAN\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\n"
        . "UID:" . $uid . "\r\n"
        . "DTSTAMP:" . $stampStr . "\r\n"
        . "DTSTART:" . $startStr . "\r\n"
        . "DTEND:" . $endStr . "\r\n"
        . "SUMMARY:" . $esc($sum) . "\r\n"
        . "DESCRIPTION:" . $esc($desc) . "\r\n"
        . "LOCATION:Visioconference\r\n"
        . "ORGANIZER;CN=finalyn.ia:mailto:" . $organizer . "\r\n"
        . "ATTENDEE;CN=" . $esc($firstname . ' ' . $lastname) . ";RSVP=TRUE:mailto:" . $email . "\r\n"
        . "STATUS:CONFIRMED\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    $gcal = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
        . '&text=' . rawurlencode($sum)
        . '&dates=' . $startStr . '/' . $endStr
        . '&details=' . rawurlencode($desc)
        . '&location=' . rawurlencode('Visioconference');
}

// 1) Notification a l'equipe finalyn (Reply-To = client)
if ($team !== '') {
    $tBody = "Nouvelle reservation d'audit via finalyn.com\n\n"
        . 'Nom        : ' . $firstname . ' ' . $lastname . "\n"
        . 'E-mail     : ' . $email . "\n"
        . 'Entreprise : ' . $company . "\n"
        . 'Creneau    : ' . $dateFr . ' a ' . $time . " (heure de Zurich)\n";
    if ($message !== '') { $tBody .= "\nMessage    : " . $message . "\n"; }
    if ($gcal !== '') { $tBody .= "\nAjouter a votre agenda : " . $gcal . "\n"; }
    finalyn_send_mail($team, 'Nouvelle reservation : ' . $firstname . ' ' . $lastname . ' (' . $company . ')', $tBody, $from, $email, $ics);
}

// 2) Confirmation au client (Reply-To = equipe finalyn)
$cBody = "Bonjour " . $firstname . ",\n\n"
    . "Votre audit gratuit avec finalyn.ia est bien confirmé.\n\n"
    . "Date : " . $dateFr . " à " . $time . " (heure de Zurich)\n"
    . "Format : visioconférence, environ 30 minutes\n\n"
    . "L'invitation est jointe à cet e-mail (ouvrez-la pour l'ajouter à votre agenda).";
if ($gcal !== '') { $cBody .= "\nOu en un clic : " . $gcal; }
$cBody .= "\n\nNous vous enverrons le lien de connexion peu avant le rendez-vous. "
    . "Si vous avez une question ou besoin de décaler le créneau, répondez simplement à cet e-mail.\n\n"
    . "À très bientôt,\n"
    . "L'équipe finalyn.ia\n"
    . "contact@finalyn.com · +41 79 639 36 84";
finalyn_send_mail($email, 'Votre audit est confirmé · finalyn.ia', $cBody, $from, $organizer, $ics);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
