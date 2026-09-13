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

$headers  = 'From: ' . ABSENDERNAME . ' <' . ABSENDER . ">\r\n";
$headers .= 'Reply-To: ' . $name . ' <' . $email . ">\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: 8bit\r\n";
$headers .= "X-Mailer: RayCube-Form\r\n";

$gesendet = @mail(EMPFAENGER, '=?UTF-8?B?' . base64_encode($betreff) . '?=', $body, $headers);

// --- Bestaetigung an den Absender ---
if ($sprache === 'en') {
    $bBetreff = "Your RayCube enquiry — $vorgang";
    $bBody = "Dear $name,\n\n"
        . "thank you for your enquiry. We have received your configuration and will come back to you "
        . "within two working days. The written quotation including prices, terms and lead time follows "
        . "within five working days.\n\n"
        . "Your reference: $vorgang\n\n"
        . "Your configuration:\n$konfig\n$leistung\n"
        . ($optionen !== '' ? "Options: $optionen\n" : '')
        . "\nKind regards\n\nThermProTEC GmbH\nZunftstr. 20 · 77694 Kehl-Marlen · Germany\n"
        . "info@thermprotec.com · +49 (7854) 98711 0\nwww.thermprotec.com\n";
} else {
    $bBetreff = "Ihre RayCube-Anfrage — $vorgang";
    $bBody = "Guten Tag $name,\n\n"
        . "vielen Dank für Ihre Anfrage. Ihre Konfiguration ist bei uns eingegangen. Wir melden uns "
        . "innerhalb von zwei Werktagen bei Ihnen; das schriftliche Angebot mit Preisen, Konditionen "
        . "und Lieferzeit erhalten Sie innerhalb von fünf Werktagen.\n\n"
        . "Ihre Vorgangsnummer: $vorgang\n\n"
        . "Ihre Konfiguration:\n$konfig\n$leistung\n"
        . ($optionen !== '' ? "Zusatzoptionen: $optionen\n" : '')
        . "\nMit freundlichen Grüßen\n\nThermProTEC GmbH\nZunftstr. 20 · 77694 Kehl-Marlen\n"
        . "info@thermprotec.com · +49 (7854) 98711 0\nwww.thermprotec.com\n";
}
$bHeaders  = 'From: ' . ABSENDERNAME . ' <' . ABSENDER . ">\r\n";
$bHeaders .= 'Reply-To: ThermProTEC <' . EMPFAENGER . ">\r\n";
$bHeaders .= "Content-Type: text/plain; charset=UTF-8\r\n";
$bHeaders .= "Content-Transfer-Encoding: 8bit\r\n";
@mail($email, '=?UTF-8?B?' . base64_encode($bBetreff) . '?=', $bBody, $bHeaders);

// Auch wenn der Mailversand scheitert: die Anfrage steht in der CSV.
raus(true, $gesendet ? '' : 'mail_nicht_bestaetigt');
