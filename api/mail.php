<?php
/** Envoi d'e-mails finalyn.ia : SMTP authentifie si configure, sinon mail() natif. */
require_once __DIR__ . '/config_load.php';

/**
 * Envoi e-mail UTF-8 (best effort), invitation .ics optionnelle.
 * Si le SMTP est configure, l'expediteur (From) est force a smtp_user :
 * Infomaniak (et la plupart des serveurs) rejettent un From different de la boite authentifiee.
 */
function finalyn_send_mail($to, $subject, $body, $from, $replyTo, $ics = null) {
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $subjEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $textPart = rtrim(chunk_split(base64_encode($body)));

    if ($ics === null) {
        $ctype = "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
        $content = $textPart;
    } else {
        $bnd = 'fin_' . bin2hex(random_bytes(8));
        $ctype = 'Content-Type: multipart/mixed; boundary="' . $bnd . '"';
        $content = '--' . $bnd . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . $textPart . "\r\n"
            . '--' . $bnd . "\r\n"
            . "Content-Type: text/calendar; method=REQUEST; charset=UTF-8; name=\"audit.ics\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"audit.ics\"\r\n\r\n"
            . rtrim(chunk_split(base64_encode($ics))) . "\r\n"
            . '--' . $bnd . "--\r\n";
    }

    $cfg = finalyn_config();

    // --- SMTP authentifie (recommande, fiable) ---
    if (!empty($cfg['smtp_host']) && !empty($cfg['smtp_user'])) {
        $envFrom = $cfg['smtp_user']; // l'expediteur DOIT etre la boite authentifiee
        $dom = substr(strrchr($envFrom, '@'), 1) ?: 'finalyn.ch';
        $headers = 'Date: ' . date('r') . "\r\n"
                 . 'From: finalyn.ia <' . $envFrom . '>' . "\r\n"
                 . 'Reply-To: ' . $replyTo . "\r\n"
                 . 'To: ' . $to . "\r\n"
                 . 'Subject: ' . $subjEnc . "\r\n"
                 . 'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $dom . ">\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . $ctype . "\r\n";
        return finalyn_smtp_send($cfg, $envFrom, $to, $headers . "\r\n" . $content);
    }

    // --- Repli : fonction mail() native ---
    $headers = 'From: finalyn.ia <' . $from . '>' . "\r\n"
             . 'Reply-To: ' . $replyTo . "\r\n"
             . "MIME-Version: 1.0\r\n"
             . $ctype . "\r\n";
    return @mail($to, $subjEnc, $content, $headers);
}

/** Client SMTP minimal : SSL (465) ou STARTTLS (587), AUTH LOGIN. Debug si FINALYN_MAIL_DEBUG. */
function finalyn_smtp_send($cfg, $from, $to, $message) {
    $host = $cfg['smtp_host'];
    $port = (int)($cfg['smtp_port'] ?? 465);
    $user = $cfg['smtp_user'];
    $pass = (string)($cfg['smtp_pass'] ?? '');
    $secure = $cfg['smtp_secure'] ?? 'ssl';
    $prefix = ($secure === 'ssl') ? 'ssl://' : '';
    $debug = defined('FINALYN_MAIL_DEBUG') && FINALYN_MAIL_DEBUG;

    $fp = @stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, 20);
    if (!$fp) {
        $msg = 'connexion impossible a ' . $host . ':' . $port . ' (' . $errstr . ')';
        if ($debug) fwrite(STDOUT, "!! $msg\n");
        error_log('finalyn smtp: ' . $msg);
        return false;
    }
    stream_set_timeout($fp, 20);
    $get = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 600)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $io = function ($c, $secret = false) use ($fp, $get, $debug) {
        if ($debug) fwrite(STDOUT, '>> ' . ($secret ? '(identifiants masques)' : $c) . "\n");
        fwrite($fp, $c . "\r\n");
        $r = $get();
        if ($debug) fwrite(STDOUT, '<< ' . trim($r) . "\n");
        return $r;
    };

    $g = $get();
    if ($debug) fwrite(STDOUT, '<< ' . trim($g) . "\n");
    $io('EHLO finalyn.ch');
    if ($secure === 'tls') {
        $io('STARTTLS');
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('finalyn smtp: STARTTLS echoue'); fclose($fp); return false;
        }
        $io('EHLO finalyn.ch');
    }
    $io('AUTH LOGIN');
    $io(base64_encode($user), true);
    $r = $io(base64_encode($pass), true);
    if (strpos($r, '235') === false) { error_log('finalyn smtp auth: ' . trim($r)); $io('QUIT'); fclose($fp); return false; }
    $io('MAIL FROM:<' . $from . '>');
    $io('RCPT TO:<' . $to . '>');
    $r = $io('DATA');
    if (strpos($r, '354') === false) { error_log('finalyn smtp data: ' . trim($r)); $io('QUIT'); fclose($fp); return false; }
    $message = preg_replace('/^\./m', '..', $message); // dot-stuffing
    fwrite($fp, $message . "\r\n.\r\n");
    $r = $get();
    if ($debug) fwrite(STDOUT, '<< ' . trim($r) . "\n");
    $io('QUIT');
    fclose($fp);
    return strpos($r, '250') !== false;
}
