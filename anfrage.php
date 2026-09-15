<?php
/**
 * RayCube — Anfrage-Endpunkt
 * ---------------------------------------------------------------
 * Nimmt das Anfrageformular von raycube.de entgegen, sendet die
 * Anfrage per E-Mail an ThermProTEC, schickt dem Absender eine
 * Bestaetigung und schreibt jede Anfrage in eine CSV-Datei
 * (die "Kontaktdatenbank").
 *
 * Ablage: in denselben Ordner wie index.html
 * Der Ordner /data muss vom Webserver beschreibbar sein.
 * ---------------------------------------------------------------
 */

declare(strict_types=1);

// ================== KONFIGURATION ==================
const EMPFAENGER   = 'info@thermprotec.com';
const ABSENDER     = 'noreply@thermprotec.com';   // muss zur Domain passen, sonst greift SPF nicht
const ABSENDERNAME = 'RayCube Website';
const DATENORDNER  = __DIR__ . '/data';
const CSV_DATEI    = DATENORDNER . '/anfragen.csv';
const MAX_PRO_STUNDE_PRO_IP = 5;
// ===================================================

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function raus(bool $ok, string $meldung = '', int $code = 200): never {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'error' => $meldung], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    raus(false, 'method', 405);
}

// --- Spam-Falle: unsichtbares Feld muss leer bleiben ---
if (!empty($_POST['website'])) {
    raus(true); // Bot glauben lassen, es habe geklappt
}

// --- Eingaben einsammeln und saeubern ---
function feld(string $name, int $max = 500): string {
    $v = trim((string)($_POST[$name] ?? ''));
    $v = str_replace(["\r", "\0"], '', $v);
    if (mb_strlen($v) > $max) $v = mb_substr($v, 0, $max);
    return $v;
}

$firma    = feld('firma', 150);
$name     = feld('name', 150);
$funktion = feld('funktion', 150);
$email    = feld('email', 180);
$telefon  = feld('telefon', 80);
$land     = feld('land', 80);
$standort = feld('standort', 150);
$termin   = feld('termin', 80);
$bedarf   = feld('bedarf', 80);
$dichte   = feld('dichte', 80);
$schicht  = feld('schicht', 10);
$zweck    = feld('zweck', 120);
$nachricht = feld('nachricht', 4000);
$konfig    = feld('konfiguration', 400);
$leistung  = feld('leistungsdaten', 200);
$optionen  = feld('optionen', 800);
$klartext  = feld('klartext', 8000);
$sprache   = feld('sprache', 5) === 'en' ? 'en' : 'de';
$consent   = !empty($_POST['consent']);

// --- Pflichtfelder ---
if ($firma === '' || $name === '' || $email === '' || !$consent) {
    raus(false, 'pflichtfelder', 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    raus(false, 'email', 422);
}
// Header-Injection ausschliessen
foreach ([$firma, $name, $email] as $wert) {
    if (preg_match('/[\r\n]/', $wert)) raus(false, 'ungueltig', 422);
}

// ================== MAILVERSAND (SMTP) ==================
// PHP mail() ist auf dem GoDaddy-Windows-Hosting nicht an einen Mailserver
// angebunden (liefert false). Daher direkter SMTP-Versand.
// Konfiguration optional in data/smtp.ini (liegt ausserhalb des Repos, per
// IIS gesperrt):
//   host = smtp.example.com
//   port = 587
//   user = noreply@thermprotec.com
//   pass = geheim
//   from = noreply@thermprotec.com
// Ohne smtp.ini: GoDaddy-Hosting-Relay ohne Anmeldung.
function smtp_konfig(): array {
    $k = ['host' => 'relay-hosting.secureserver.net', 'port' => 25, 'user' => '', 'pass' => '', 'from' => ABSENDER];
    $ini = DATENORDNER . '/smtp.ini';
    if (is_readable($ini)) {
        $p = parse_ini_file($ini) ?: [];
        foreach ($k as $key => $v) if (isset($p[$key]) && $p[$key] !== '') $k[$key] = $p[$key];
        $k['port'] = (int)$k['port'];
    }
    return $k;
}

function smtp_senden(string $an, string $betreff, string $body, string $replyTo, string $replyName): bool {
    $c = smtp_konfig();
    $host = $c['host']; $port = $c['port'];
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $pre = $port === 465 ? 'ssl://' : '';
    $fp = @stream_socket_client($pre . $host . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { error_log("SMTP connect $host:$port: $errstr"); return false; }
    stream_set_timeout($fp, 20);

    $lies = function () use ($fp): array {
        $code = 0; $text = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $text .= $line;
            if (preg_match('/^(\d{3})([ -])/', $line, $m)) { $code = (int)$m[1]; if ($m[2] === ' ') break; }
            else break;
        }
        return [$code, $text];
    };
    $sag = function (string $cmd, array $ok) use ($fp, $lies): bool {
        fwrite($fp, $cmd . "\r\n");
        [$code, $text] = $lies();
        if (!in_array($code, $ok, true)) { error_log("SMTP '$cmd' -> $text"); return false; }
        return true;
    };

    [$code] = $lies();
    if ($code !== 220) { fclose($fp); return false; }
    $me = 'raycube.de';
    if (!$sag("EHLO $me", [250])) { fclose($fp); return false; }

    if ($port === 587 || ($c['user'] !== '' && $port !== 465)) {
        if ($sag('STARTTLS', [220])) {
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }
            if (!$sag("EHLO $me", [250])) { fclose($fp); return false; }
        }
    }
    if ($c['user'] !== '') {
        if (!$sag('AUTH LOGIN', [334]) || !$sag(base64_encode($c['user']), [334]) || !$sag(base64_encode($c['pass']), [235])) { fclose($fp); return false; }
    }
    $from = $c['from'];
    if (!$sag("MAIL FROM:<$from>", [250]) || !$sag("RCPT TO:<$an>", [250, 251])) { fclose($fp); return false; }
    if (!$sag('DATA', [354])) { fclose($fp); return false; }

    $kopf  = 'From: ' . ABSENDERNAME . " <$from>\r\n";
    $kopf .= "To: <$an>\r\n";
    $kopf .= "Reply-To: " . ($replyName !== '' ? "$replyName <$replyTo>" : "<$replyTo>") . "\r\n";
    $kopf .= 'Subject: =?UTF-8?B?' . base64_encode($betreff) . "?=\r\n";
    $kopf .= 'Date: ' . date('r') . "\r\n";
    $kopf .= 'Message-ID: <' . bin2hex(random_bytes(8)) . "@raycube.de>\r\n";
    $kopf .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    $kopf .= "X-Mailer: RayCube-Form\r\n";
    $daten = preg_replace('/\r?\n/', "\r\n", $body);
    $daten = preg_replace('/^\./m', '..', $daten);
    fwrite($fp, $kopf . "\r\n" . $daten . "\r\n.\r\n");
    [$code, $text] = $lies();
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    if ($code !== 250) { error_log("SMTP DATA -> $text"); return false; }
    return true;
}
// ========================================================

// --- Ordner anlegen ---
if (!is_dir(DATENORDNER)) {
    @mkdir(DATENORDNER, 0750, true);
}
// Datenordner nicht ueber den Browser erreichbar machen
$htaccess = DATENORDNER . '/.htaccess';
if (!file_exists($htaccess)) {
    @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
}
$webconfig = DATENORDNER . '/web.config';
if (!file_exists($webconfig)) {
    @file_put_contents($webconfig,
        '<?xml version="1.0"?><configuration><system.webServer><security>' .
        '<authorization><deny users="*"/></authorization></security>' .
        '</system.webServer></configuration>');
}

$ip  = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$now = time();

// --- einfache Ratenbegrenzung je IP ---
$ratefile = DATENORDNER . '/rate_' . md5($ip) . '.txt';
$stempel  = [];
if (is_readable($ratefile)) {
    $stempel = array_filter(
        array_map('intval', explode(',', (string)file_get_contents($ratefile))),
        fn($t) => $t > $now - 3600
    );
}
if (count($stempel) >= MAX_PRO_STUNDE_PRO_IP) {
    raus(false, 'zu_viele_anfragen', 429);
}
$stempel[] = $now;
@file_put_contents($ratefile, implode(',', $stempel), LOCK_EX);

// --- Vorgangsnummer ---
$vorgang = 'RC-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
$zeit    = date('Y-m-d H:i:s');

// --- CSV schreiben (die Kontaktdatenbank) ---
$kopf = ['Vorgang','Zeitstempel','Firma','Ansprechpartner','Funktion','E-Mail','Telefon','Land',
         'Standort','Liefertermin','Jahresbedarf','Zielschuettdichte','Schichtbetrieb','Einsatzzweck',
         'Konfiguration','Leistungsdaten','Zusatzoptionen','Anmerkungen','Sprache','IP'];
$zeile = [$vorgang,$zeit,$firma,$name,$funktion,$email,$telefon,$land,
          $standort,$termin,$bedarf,$dichte,$schicht,$zweck,
          $konfig,$leistung,$optionen,str_replace("\n",' | ',$nachricht),$sprache,$ip];

$neu = !file_exists(CSV_DATEI);
if ($fh = @fopen(CSV_DATEI, 'a')) {
    if (flock($fh, LOCK_EX)) {
        if ($neu) {
            fwrite($fh, "\xEF\xBB\xBF");           // BOM, damit Excel UTF-8 erkennt
            fputcsv($fh, $kopf, ';');
        }
        fputcsv($fh, $zeile, ';');
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

// --- Mail an ThermProTEC ---
$betreff = sprintf('[%s] RayCube-Anfrage — %s', $vorgang, $firma);
$body  = "Neue RayCube-Anfrage über raycube.de\n";
$body .= str_repeat('=', 56) . "\n";
$body .= "Vorgang:      $vorgang\nEingegangen:  $zeit\nSprache:      $sprache\n\n";
$body .= $klartext !== '' ? $klartext : "Konfiguration: $konfig\n$leistung\nOptionen: $optionen\n";
$body .= "\n" . str_repeat('=', 56) . "\n";
$body .= "Erfasst in: data/anfragen.csv\n";

$gesendet = smtp_senden(EMPFAENGER, $betreff, $body, $email, $name);

// --- Bestaetigung an den Absender ---
if ($sprache === 'en') {
    $bBetreff = "Your RayCube enquiry — $vorgang";
    $bBody = "Dear $name,\n\n"
        . "thank you for your enquiry. We have received your details and your configuration and will "
        . "get in touch with you as soon as possible.\n\n"
        . "Your reference: $vorgang\n\n"
        . "Your configuration:\n$konfig\n$leistung\n"
        . ($optionen !== '' ? "Options: $optionen\n" : '')
        . "\nKind regards\n\nThermProTEC GmbH\nZunftstr. 20 · 77694 Kehl-Marlen · Germany\n"
        . "info@thermprotec.com · +49 (7854) 98711 0\nwww.thermprotec.com\n";
} else {
    $bBetreff = "Ihre RayCube-Anfrage — $vorgang";
    $bBody = "Guten Tag $name,\n\n"
        . "vielen Dank für Ihre Anfrage. Ihre Angaben und Ihre Konfiguration sind bei uns eingegangen. "
        . "Wir setzen uns so bald wie möglich mit Ihnen in Verbindung.\n\n"
        . "Ihre Vorgangsnummer: $vorgang\n\n"
        . "Ihre Konfiguration:\n$konfig\n$leistung\n"
        . ($optionen !== '' ? "Zusatzoptionen: $optionen\n" : '')
        . "\nMit freundlichen Grüßen\n\nThermProTEC GmbH\nZunftstr. 20 · 77694 Kehl-Marlen\n"
        . "info@thermprotec.com · +49 (7854) 98711 0\nwww.thermprotec.com\n";
}
smtp_senden($email, $bBetreff, $bBody, EMPFAENGER, 'ThermProTEC');

// Auch wenn der Mailversand scheitert: die Anfrage steht in der CSV.
raus(true, $gesendet ? '' : 'mail_nicht_bestaetigt');
