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

require __DIR__ . '/mail.php';

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
if ($ts === false) finalyn_die(400, 'Creneau invalide.');
if ($ts < time() + 12 * 3600) finalyn_die(400, "Les rendez-vous se reservent au moins une demi-journee a l'avance (12 h). Merci de choisir un creneau un peu plus tard.");
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
$token = bin2hex(random_bytes(16));
$ins = $pdo->prepare("INSERT INTO bookings (created_at, firstname, lastname, email, company, slot_date, slot_time, message, status, token)
                      VALUES (?,?,?,?,?,?,?,?, 'confirmed', ?)");
$ins->execute([$now, $firstname, $lastname, $email, $company, $date, $time, $message, $token]);
$bookingId = (int)$pdo->lastInsertId();
$cancelUrl = 'https://ia.finalyn.ch/api/cancel.php?id=' . $bookingId . '&t=' . $token;

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

// Invitation .ics (METHOD:REQUEST, compatible Apple/Outlook/Google) + liens rapides Google et Outlook
$ics = null; $gcal = ''; $outlook = '';
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
    $outlook = 'https://outlook.office.com/calendar/0/deeplink/compose?path=/calendar/action/compose&rru=addevent'
        . '&subject=' . rawurlencode($sum)
        . '&startdt=' . rawurlencode($start->format('Y-m-d\TH:i:s') . 'Z')
        . '&enddt=' . rawurlencode($end->format('Y-m-d\TH:i:s') . 'Z')
        . '&body=' . rawurlencode($desc)
        . '&location=' . rawurlencode('Visioconference');
}

// 1) Notification a l'equipe finalyn (Reply-To = client) - HTML + texte
if ($team !== '') {
    $tBody = "Nouvelle reservation d'audit via ia.finalyn.ch\n\n"
        . 'Nom        : ' . $firstname . ' ' . $lastname . "\n"
        . 'E-mail     : ' . $email . "\n"
        . 'Entreprise : ' . $company . "\n"
        . 'Creneau    : ' . $dateFr . ' a ' . $time . " (heure de Zurich)\n";
    if ($message !== '') { $tBody .= "\nMessage    : " . $message . "\n"; }
    if ($gcal !== '') { $tBody .= "\nGoogle Agenda : " . $gcal . "\n"; }
    if ($outlook !== '') { $tBody .= "Outlook : " . $outlook . "\n"; }

    $tPar = '<p style="margin:0 0 14px;">Nouvelle réservation d\'audit via <strong>ia.finalyn.ch</strong>.</p>'
        . '<p style="margin:0 0 6px;"><strong>Nom</strong> : ' . htmlspecialchars($firstname . ' ' . $lastname) . '<br>'
        . '<strong>E-mail</strong> : ' . htmlspecialchars($email) . '<br>'
        . '<strong>Entreprise</strong> : ' . htmlspecialchars($company) . '<br>'
        . '<strong>Créneau</strong> : ' . htmlspecialchars($dateFr . ' à ' . $time) . ' (heure de Zurich)</p>'
        . ($message !== '' ? '<p style="margin:14px 0 6px;background:#F4F0E9;border-radius:10px;padding:12px 14px;"><strong>Message</strong> : ' . nl2br(htmlspecialchars($message)) . '</p>' : '');
    $tBtns = [];
    if ($gcal !== '') { $tBtns[] = ['label' => 'Google Agenda', 'url' => $gcal, 'primary' => false]; }
    if ($outlook !== '') { $tBtns[] = ['label' => 'Outlook', 'url' => $outlook, 'primary' => false]; }
    $tHtml = finalyn_email_html('Nouvelle réservation', $tPar, $tBtns);
    finalyn_send_mail($team, 'Nouvelle reservation : ' . $firstname . ' ' . $lastname . ' (' . $company . ')', $tBody, $from, $email, $ics, $tHtml);
}

// 2) Confirmation au client (Reply-To = equipe finalyn) - HTML + texte
$cBody = "Bonjour " . $firstname . ",\n\n"
    . "Votre audit gratuit avec finalyn.ia est bien confirmé.\n\n"
    . "Date : " . $dateFr . " à " . $time . " (heure de Zurich)\n"
    . "Format : visioconférence, environ 30 minutes\n\n"
    . "L'invitation (.ics) est jointe : ouvrez-la pour l'ajouter à Apple Calendar, Outlook, Google ou tout autre agenda.";
if ($gcal !== '') { $cBody .= "\nGoogle Agenda : " . $gcal; }
if ($outlook !== '') { $cBody .= "\nOutlook : " . $outlook; }
$cBody .= "\n\nNous vous enverrons le lien de connexion peu avant le rendez-vous.\n"
    . "Besoin d'annuler ou de décaler ? C'est ici, en un clic : " . $cancelUrl . "\n\n"
    . "À très bientôt,\n"
    . "L'équipe finalyn.ia\n"
    . "contact@finalyn.com · +41 79 639 36 84";

$cPar = '<p style="margin:0 0 14px;">Bonjour ' . htmlspecialchars($firstname) . ',</p>'
    . '<p style="margin:0 0 14px;">Votre <strong>audit gratuit</strong> avec finalyn.ia est bien confirmé.</p>'
    . '<p style="margin:0 0 14px;background:#F4F0E9;border-radius:10px;padding:14px 16px;">'
    . '<strong>Date</strong> : ' . htmlspecialchars($dateFr . ' à ' . $time) . ' (heure de Zurich)<br>'
    . '<strong>Format</strong> : visioconférence, environ 30 minutes</p>'
    . '<p style="margin:0 0 6px;">L\'invitation (.ics) est jointe : ouvrez-la pour l\'ajouter à Apple Calendar, Outlook ou tout autre agenda. Ou en un clic ci-dessous. Nous vous enverrons le lien de connexion peu avant le rendez-vous.</p>';
$cBtns = [];
if ($gcal !== '') { $cBtns[] = ['label' => 'Google Agenda', 'url' => $gcal, 'primary' => false]; }
if ($outlook !== '') { $cBtns[] = ['label' => 'Outlook', 'url' => $outlook, 'primary' => false]; }
$cBtns[] = ['label' => 'Annuler ou décaler', 'url' => $cancelUrl, 'primary' => false];
$cHtml = finalyn_email_html('Votre audit est confirmé', $cPar, $cBtns);
finalyn_send_mail($email, 'Votre audit est confirmé · finalyn.ia', $cBody, $from, $organizer, $ics, $cHtml);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
