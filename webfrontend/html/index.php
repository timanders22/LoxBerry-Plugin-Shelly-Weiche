<?php
/**
 * Shelly-Weiche - Aktionsendpunkt (unangemeldeter Bereich)
 *
 * Die Weiche liefert dem Miniserver keine Werte; dieser Endpunkt nimmt nur zwei Aufrufe an, beide mit Token:
 *
 *   /plugins/<Ordner>/index.php?selftest=1&token=<T>   prueft nur das Token, keine Wirkung
 *       SELFTEST;OK=1;TOKEN=OK                              richtiges Token
 *       HTTP 403  SELFTEST;OK=0;ERR=TOKEN                   falsches oder fehlendes Token
 *       HTTP 403  SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET in der Oberflaeche ist kein Token gesetzt
 *   /plugins/<Ordner>/index.php?aktion=voll&token=<T>  der naechste Minutenlauf sendet haus/klima vollstaendig
 *       AKTION;OK=1;AKTION=voll
 *       HTTP 403  AKTION;OK=0;ERR=TOKEN | KEIN_TOKEN_EINGERICHTET
 *       HTTP 400  AKTION;OK=0;ERR=ZEICHEN (unerlaubte Zeichen) | UNBEKANNT (keine erlaubte Aktion)
 *
 * Ohne Parameter antwortet die Datei mit HTTP 403 und listet nichts auf (Regeln/03: ohne index.php liefert Apache
 * eine "Index of"-Liste des Webordners). Token und Parameter kommen aus $_GET und $_POST, nie aus $_REQUEST; erst
 * is_string, dann Laenge, dann alles andere (Regeln/03). hash_equals('', '') ist wahr - der leere Fall wird vorher
 * abgefangen. Die Konfiguration wird nur gelesen (sw_config(false)): dieser Endpunkt legt nichts an. Jeder Weg
 * schreibt eine Protokollzeile mit der Adresse des Anrufers, nie das Token.
 *
 * (c) Shelly-Weiche Plugin Authors - MIT-Lizenz
 */

require_once __DIR__ . '/sw_lib.php';

function sw_ep_ende($code, $zeile, $grund)
{
    $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
        && preg_match('/^[0-9A-Fa-f:.]{1,45}\z/', $_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?';
    sw_log('Endpunkt: ' . $ip . ' - ' . $grund . ' (HTTP ' . (int) $code . ').');
    if (function_exists('http_response_code')) {
        http_response_code((int) $code);
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $zeile . "\n";
    exit;
}

/* Einmal zentral einsammeln: erst is_string, dann Laenge (Regeln/03). */
$sw_ein = array();
$sw_form = false;
foreach (array('token', 'selftest', 'aktion') as $sw_n) {
    $sw_w = isset($_GET[$sw_n]) ? $_GET[$sw_n] : (isset($_POST[$sw_n]) ? $_POST[$sw_n] : null);
    if ($sw_w === null) {
        continue;
    }
    if (!is_string($sw_w) || strlen($sw_w) > 128) {
        $sw_form = true;
        continue;
    }
    $sw_ein[$sw_n] = $sw_w;
}
$sw_art = isset($sw_ein['selftest']) && $sw_ein['selftest'] !== '' && $sw_ein['selftest'] !== '0' ? 'SELFTEST' : 'AKTION';

if ($sw_form) {
    sw_ep_ende(400, $sw_art . ';OK=0;ERR=FORM', 'Parameter in unzulaessiger Form abgewiesen');
}
if (!isset($sw_ein['aktion']) && $sw_art !== 'SELFTEST') {
    sw_ep_ende(403, 'Absichtlich ohne Inhalt.', 'Aufruf ohne Aktion abgewiesen');
}

$sw_cfg = sw_config(false);
if ($sw_cfg === null) {
    sw_ep_ende(503, $sw_art . ';OK=0;ERR=NICHT_EINGERICHTET', 'nicht eingerichtet (Oberflaeche noch nie geoeffnet)');
}
$sw_soll = (string) $sw_cfg['token'];
if ($sw_soll === '') {
    sw_ep_ende(403, $sw_art . ';OK=0;ERR=KEIN_TOKEN_EINGERICHTET', 'kein Token eingerichtet');
}
$sw_ist = isset($sw_ein['token']) ? $sw_ein['token'] : '';
if ($sw_ist === '' || !hash_equals($sw_soll, $sw_ist)) {
    sw_ep_ende(403, $sw_art . ';OK=0;ERR=TOKEN', ($sw_art === 'SELFTEST' ? 'Selbsttest' : 'Aktion')
        . ' mit falschem oder fehlendem Token abgewiesen');
}
if ($sw_art === 'SELFTEST') {
    sw_ep_ende(200, 'SELFTEST;OK=1;TOKEN=OK', 'Selbsttest, Token in Ordnung');
}
$sw_a = $sw_ein['aktion'];
if (preg_match('/^[A-Za-z0-9_]{1,32}\z/', $sw_a) !== 1) {
    sw_ep_ende(400, 'AKTION;OK=0;ERR=ZEICHEN', 'Aktion mit unerlaubten Zeichen abgewiesen');
}
if (!in_array($sw_a, array('voll'), true)) {
    sw_ep_ende(400, 'AKTION;OK=0;ERR=UNBEKANNT', 'unbekannte Aktion "' . $sw_a . '" abgewiesen');
}
if (!sw_haus_voll_merken()) {
    sw_ep_ende(500, 'AKTION;OK=0;ERR=SCHREIBEN', 'Aktion voll: Merker liess sich nicht schreiben');
}
sw_ep_ende(200, 'AKTION;OK=1;AKTION=voll', 'Aktion voll angenommen - der naechste Minutenlauf sendet alles');
