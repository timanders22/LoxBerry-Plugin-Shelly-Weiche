<?php
/* Gemeinsames Sicherungsmodul (Abschrift von Werkzeuge/gemeinsam/sicherung.php): "Einstellungen sichern" und
 * "Einstellungen zurueckspielen" verhalten sich in allen Plugins des Hauses gleich. Liegt neben dieser Datei und
 * schuetzt sich selbst gegen doppeltes Laden. */
require_once __DIR__ . '/sicherung.php';
/**
 * Shelly-Weiche - gemeinsamer Unterbau
 *
 * WARUM ES DIESES PLUGIN GIBT
 * ---------------------------
 * Shelly H&T Gen3 (Batteriefuehler fuer Temperatur und Feuchte) melden sich mit
 * eigenem MQTT am Broker des LoxBerry. Ihre Messwerte kommen als JSON-RPC-
 * Benachrichtigung unter <praefix>/events/rpc, NICHT zurueckbehalten, und nur in
 * den kurzen Augenblicken, in denen das Geraet aufwacht. Wer spaeter fragt, bekommt
 * nichts; zurueckbehalten ist nur <praefix>/online, und das steht im Schlaf auf
 * false. Andere Plugins (Raumklima, Sprachsteuerung, SignalBot) lesen Raumklima
 * aber unter haus/klima/<name>/... (Regeln/07, Entscheidung Nr. 15) - zurueck-
 * behalten, mit Zeitpunkt und Lebenszeichen.
 *
 * Die Weiche hoert die Benachrichtigungen mit, merkt sich je Geraet die letzten
 * Werte und liefert sie - nur wenn der Anwender es einschaltet, ab Werk aus - als
 * Anbieter "shellyweiche" unter haus/klima/. Dem Miniserver liefert sie nichts;
 * das MQTT-Gateway abonniert die Rohthemen der Geraete wie bisher.
 *
 * WIE SIE HOERT
 * -------------
 * Ohne Dauerdienst: cron/cron.01min startet jede Minute einen Lauscher im
 * Hintergrund (bin/sw_takt.php --lauschen), der bis zur naechsten vollen Minute
 * plus SW_LAUSCH_NACHLAUF Sekunden am Broker abonniert bleibt. Zwei Plaetze (a, b)
 * mit je einer Sperre: der Lauscher der naechsten Minute laeuft an, bevor der
 * vorige endet - es gibt keine Luecke, in der eine nicht zurueckbehaltene Meldung
 * verloren ginge. Doppelt empfangene Meldungen (in der Ueberlappung) werden am
 * Zeitstempel des Geraets erkannt. Jeder Prozess endet nach spaetestens
 * SW_LAUSCH_MAX Sekunden; es gibt keinen Waechter, keine Waise und keinen
 * Deskriptor auf einer geloeschten Protokolldatei.
 *
 * Erkannt wird ein Geraet an der MELDUNG, nicht am Namen: eine Benachrichtigung
 * NotifyFullStatus oder NotifyStatus mit temperature:0 (tC) oder humidity:0 (rh).
 * Das Praefix stellt der Anwender in der Shelly-App ein; es wird nie fest
 * verdrahtet. Die Geraetekennung (src, sie traegt die MAC) wird nie gespeichert -
 * nur der Modellteil davor.
 *
 * (c) Shelly-Weiche Plugin Authors - MIT-Lizenz
 */

/* ------------------------------------------------------------------
 * Grenzen - jede steht genau hier (Regeln/04 "Eine Grenze steht genau
 * einmal, als Konstante in der Bibliothek").
 * ------------------------------------------------------------------ */

/** Der eigene Name in haus/klima/<name>/anbieter und haus/klima/zaehler/<anbieter> (Ordnername, plugin.cfg FOLDER). */
define('SW_ANBIETER', 'shellyweiche');
/** Die Gueltigkeitsfrist: liegt die letzte Messung eines Felds laenger zurueck (oder ist sie unbekannt), geht "ohne
 *  Aussage" hinaus. Sie geht als haus/klima/<name>/frist mit hinaus (Regeln/07 Nr. 15, Entscheidung des Hausherrn
 *  22.11.2026 "Eigenes Feld frist"; ein Abnehmer nimmt sie von 3600 bis 43200 s an). 4 h 30 min, gemessen 22.11.2026
 *  an 12 H&T Gen3 (Regeln/12): Meldung alle 120-121 min, einzelne Termine fallen aus (groesster Abstand 241 min) - die
 *  Frist uebersteht einen ausgefallenen Termin, zwei nicht. Bis 22.11.2026 fest eine Stunde (zu kurz: die Werte galten
 *  die meiste Zeit als ohne Aussage). */
define('SW_STUMM', 16200);
/** Alle so viele Sekunden geht der volle Satz hinaus. */
define('SW_VOLL', 1800);
/** Ein gescheitertes Abraeumen versucht der Minutentakt hoechstens in diesem Abstand erneut. */
define('SW_LEEREN_ABSTAND', 600);
/** So lange wartet eine kurze Sitzung nach dem SUBACK auf zurueckbehaltene Werte. */
define('SW_LESEFENSTER', 1.0);
/** Ein Minutenlauf vor weniger als so vielen Sekunden beendet den neuen (Regeln/03: unter dem Cron-Takt). */
define('SW_TAKT_SCHRANKE', 30);
/** Ein Minutenlauf, der laenger zurueckliegt, ist im Reiter Test ein Befund. */
define('SW_TAKT_ALT', 180);
/** Eine Upgrade-Marke, die juenger ist, haelt Takt und Lauscher an (Regeln/06). */
define('SW_MARKE_FRIST', 3600);
/** Der Lauscher bleibt bis zur naechsten vollen Minute plus so vielen Sekunden (Ueberlappung mit dem naechsten). */
define('SW_LAUSCH_NACHLAUF', 8);
/** Kein Lauscher laeuft laenger als so viele Sekunden. */
define('SW_LAUSCH_MAX', 75);
/** Alle so viele Sekunden ein PINGREQ (Keepalive der Sitzung 60 s). */
define('SW_LAUSCH_PING', 30);
/** Hoechstens so viele Geraete werden gemerkt und in der Oberflaeche gefuehrt. */
define('SW_GERAETE_MAX', 64);
/** Groesste Nutzlast, die ausgewertet wird (Byte); groessere Pakete werden gelesen und verworfen. */
define('SW_NUTZLAST_MAX', 65536);
/** Groesstes Paket, das ueberhaupt gelesen wird; darueber wird die Sitzung beendet. */
define('SW_PAKET_MAX', 1048576);
/** So viele Abstaende zwischen zwei Meldungen werden je Geraet gemerkt (Reiter Test, Meldetakt). */
define('SW_ABSTAENDE', 20);
/** Ein Geraet, dessen letzte Meldung laenger zurueckliegt, gilt im Reiter Einstellungen als "still" - dieselbe Frist,
 *  nach der seine Werte ohne Aussage hinausgehen. */
define('SW_STILL', SW_STUMM);

/**
 * Die LoxBerry-Wurzel. Gelesen wird $LBHOMEDIR; fehlt die Umgebung, wird vom Ablageort dieser Datei aufwaerts der
 * Ordner gesucht, der config/plugins, data/plugins UND config/system/general.json traegt (Regeln/06). Findet sich
 * keine Wurzel, bleibt sie leer, und die Bibliothek schreibt nirgends hin.
 */
function sw_wurzel()
{
    $lb = getenv('LBHOMEDIR');
    if ($lb !== false && $lb !== '') {
        return $lb;
    }
    $d = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
            && is_file($d . '/config/system/general.json')) {
            return $d;
        }
        $eltern = dirname($d);
        if ($eltern === $d) {
            break;
        }
        $d = $eltern;
    }
    return '';
}

/** Pfade des Plugins. LBP*-Umgebungsvariablen setzt LoxBerry; der Ordnername ist die letzte Stufe des Pfades. */
function sw_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $ordner = getenv('LBPPLUGINDIR');
    if ($ordner === false || $ordner === '') {
        $ordner = basename(__DIR__);
    }
    if ($ordner === '' || $ordner === '.' || $ordner === '/'
        || $ordner === 'html' || $ordner === 'htmlauth' || $ordner === 'plugins') {
        $ordner = 'shellyweiche';
    }
    $lb = sw_wurzel();
    $cfg = getenv('LBPCONFIGDIR');
    $log = getenv('LBPLOGDIR');
    $dat = getenv('LBPDATADIR');
    $p = array(
        'plugin'  => $ordner,
        'lbhome'  => $lb,
        'config'  => ($cfg !== false && $cfg !== '') ? $cfg
                     : ($lb !== '' ? $lb . '/config/plugins/' . $ordner : ''),
        'log'     => ($log !== false && $log !== '') ? $log
                     : ($lb !== '' ? $lb . '/log/plugins/' . $ordner : ''),
        'datadir' => ($dat !== false && $dat !== '') ? $dat
                     : ($lb !== '' ? $lb . '/data/plugins/' . $ordner : ''),
    );
    $p['cfgdatei'] = $p['config'] !== '' ? $p['config'] . '/shelly.json' : '';
    if ($lb !== '') {
        $p['sicherung'] = $lb . '/config/plugins/' . $ordner . '.backup.json';
    } elseif ($p['config'] !== '') {
        $p['sicherung'] = dirname($p['config']) . '/' . $ordner . '.backup.json';
    } else {
        $p['sicherung'] = '';
    }
    return $p;
}

/** Vorgaben. Alles ab Werk aus; ein Geraet sendet erst, wenn es mit Namen und Haken gespeichert ist. */
function sw_vorgaben()
{
    return array(
        'token'          => '',
        'haus_klima_ein' => 0,
        'abo_zusatz'     => '',
        'geraete'        => array(),
    );
}

/** Schalter: 0 oder 1 (als Zahl oder Ziffer). Rueckgabe 0/1 oder null. */
function sw_schalter_wert($v)
{
    if ($v === 0 || $v === 1) {
        return $v;
    }
    if ($v === '0' || $v === '1') {
        return (int) $v;
    }
    return null;
}

/** Aktionstoken: was ohne Kodierung in eine Adresse passt, hoechstens 64 Zeichen. Leer heisst "nicht eingerichtet" -
 *  der Endpunkt lehnt dann jede Aktion ab. */
function sw_token_taugt($t)
{
    return is_string($t) && preg_match('/^[A-Za-z0-9_.\-]{0,64}\z/', $t) === 1;
}

/** Eine Zeichenkette fuer eine Meldung kuerzen: eine Zeile, hoechstens $n Zeichen, Steuerzeichen als Leerzeichen. */
function sw_kurz($s, $n = 80)
{
    $s = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $s));
    return strlen($s) > $n ? substr($s, 0, $n) . '...' : $s;
}

/** Einen fremden Wert fuer eine Meldung beschreiben. Ein Token wird nie gezeigt, nur seine Laenge. */
function sw_wert_zeigen($w, $geheim = false)
{
    if (is_array($w)) {
        return sw_t('WERT.LISTE');
    }
    if (is_bool($w)) {
        return $w ? 'true' : 'false';
    }
    if ($w === null) {
        return 'null';
    }
    $s = (string) $w;
    if ($geheim) {
        return sprintf(sw_t('WERT.ZEICHEN'), strlen($s));
    }
    return '"' . sw_kurz($s, 60) . '"';
}

/**
 * Ein Themenpraefix eines Geraets (wie in der Shelly-App eingestellt): 1 bis 100 Byte, gueltiges UTF-8, keine
 * Steuerzeichen, keine Platzhalter + und #, nicht mit / oder $ beginnend, kein / am Ende, keine leere Ebene.
 */
function sw_praefix_taugt($p)
{
    if (!is_string($p) || $p === '' || strlen($p) > 100 || preg_match('//u', $p) !== 1) {
        return false;
    }
    if (preg_match('/[\x00-\x1F\x7F+#]/', $p) === 1 || $p[0] === '/' || $p[0] === '$'
        || substr($p, -1) === '/' || strpos($p, '//') !== false) {
        return false;
    }
    return true;
}

/** Ein weiteres Abo fuer Geraete mit tieferem Praefix: leer, oder Ebenen aus [A-Za-z0-9_.:-] bzw. genau "+", endend
 *  auf /events/rpc, hoechstens 100 Zeichen, nie "#" (sonst kaeme der ganze Broker). */
function sw_abo_taugt($a)
{
    if (!is_string($a)) {
        return false;
    }
    if ($a === '') {
        return true;
    }
    if (strlen($a) > 100 || substr($a, -11) !== '/events/rpc') {
        return false;
    }
    $ebenen = explode('/', substr($a, 0, -11));
    foreach ($ebenen as $e) {
        if ($e !== '+' && preg_match('/^[A-Za-z0-9_.:\-]{1,64}\z/', $e) !== 1) {
            return false;
        }
    }
    return true;
}

/**
 * Die Geraeteliste der Konfiguration pruefen: eine Liste (0..n-1) von Eintraegen mit genau praefix, name, ein;
 * praefix tauglich und nicht doppelt, name nach der Namensregel, ein 0/1, ein Name hoechstens einmal unter den
 * eingeschalteten, hoechstens SW_GERAETE_MAX Eintraege. Rueckgabe: Liste der Beanstandungen (leer = in Ordnung).
 * Alle Beanstandungen werden gesammelt, nicht die erste.
 */
function sw_geraete_pruefen($liste)
{
    $m = array();
    if (!is_array($liste) || ($liste !== array() && array_keys($liste) !== range(0, count($liste) - 1))) {
        return array(sw_t('GERAET.KEINE_LISTE'));
    }
    if (count($liste) > SW_GERAETE_MAX) {
        $m[] = sprintf(sw_t('GERAET.ZU_VIELE'), count($liste), SW_GERAETE_MAX);
    }
    $praefixe = array();
    $namen = array();
    foreach ($liste as $i => $e) {
        $nr = $i + 1;
        $wer = (is_array($e) && isset($e['praefix']) && sw_praefix_taugt($e['praefix'])) ? $e['praefix'] : '#' . $nr;
        if (!is_array($e)) {
            $m[] = sprintf(sw_t('GERAET.EINTRAG_FORM'), $nr);
            continue;
        }
        $keys = array_keys($e);
        sort($keys);
        if ($keys !== array('ein', 'name', 'praefix')) {
            $m[] = sprintf(sw_t('GERAET.EINTRAG_FORM'), $nr);
            continue;
        }
        if (!sw_praefix_taugt($e['praefix'])) {
            $m[] = sprintf(sw_t('GERAET.PRAEFIX_UNGUELTIG'), $nr);
        } elseif (isset($praefixe[$e['praefix']])) {
            $m[] = sprintf(sw_t('GERAET.PRAEFIX_DOPPELT'), $nr, sw_kurz($e['praefix'], 60));
        } else {
            $praefixe[$e['praefix']] = true;
        }
        if (!sw_haus_name_taugt($e['name'])) {
            $vor = is_string($e['name']) ? sw_haus_name($e['name']) : '';
            $m[] = sprintf(sw_t('HAUS.NAME_UNGUELTIG'), $wer, sw_wert_zeigen($e['name']),
                sw_haus_name_taugt($vor) ? $vor : '-');
        }
        $ein = sw_schalter_wert($e['ein']);
        if ($ein === null) {
            $m[] = sprintf(sw_t('GERAET.EIN_UNGUELTIG'), $wer);
        } elseif ($ein === 1 && is_string($e['name'])) {
            if (isset($namen[$e['name']])) {
                $m[] = sprintf(sw_t('HAUS.NAME_DOPPELT'), $wer, $e['name']);
            }
            $namen[$e['name']] = true;
        }
    }
    return $m;
}

/** Die Geraeteliste in der Normalform (ein als Zahl). Nur nach bestandener Pruefung aufrufen. */
function sw_geraete_normal(array $liste)
{
    $aus = array();
    foreach ($liste as $e) {
        $aus[] = array('praefix' => (string) $e['praefix'], 'name' => (string) $e['name'],
                       'ein' => sw_schalter_wert($e['ein']) === 1 ? 1 : 0);
    }
    return $aus;
}

/**
 * Einen Wert fuer eine Einstellung pruefen - fuer Formular und Sicherung. Rueckgabe array(zulaessig, normierter
 * Wert, Beanstandung).
 */
function sw_wert_pruefen($k, $w)
{
    $ok = false;
    $wert = null;
    $grund = '';
    switch ($k) {
    case 'token':
        $ok = sw_token_taugt($w);
        $wert = $w;
        break;
    case 'haus_klima_ein':
        $wert = sw_schalter_wert($w);
        $ok = $wert !== null;
        break;
    case 'abo_zusatz':
        $ok = sw_abo_taugt($w);
        $wert = $w;
        break;
    case 'geraete':
        $m = sw_geraete_pruefen($w);
        $ok = !$m;
        $wert = $ok ? sw_geraete_normal($w) : null;
        $grund = implode(' ', $m);
        break;
    }
    if ($ok) {
        return array(true, $wert, '');
    }
    if ($grund !== '') {
        return array(false, null, sprintf(sw_t('TEXT.WERT_LISTE_UNZULAESSIG'), $k, $grund));
    }
    return array(false, null, sprintf(sw_t('TEXT.WERT_UNZULAESSIG'), $k,
        sw_wert_zeigen($w, $k === 'token'), sw_t('ERLAUBT.' . strtoupper($k))));
}

/** Eine JSON-Datei als Feld lesen; null, wenn sie fehlt oder kein Objekt/keine Liste ist. */
function sw_json_lesen($f)
{
    if ($f === '' || !is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

/** Traegt ein Stand Inhalt? Ja, wenn er ein Objekt ist, in dem das Token schon einmal geschrieben wurde - auch leer,
 *  denn wer es in der Oberflaeche bewusst entfernt, will es ohne (sw_config()). */
function sw_hat_inhalt($d)
{
    return is_array($d) && array_key_exists('token', $d) && is_string($d['token']);
}

/**
 * Unteilbar schreiben: Nebendatei mit 0600 (Rechte beim Anlegen), vollstaendig schreiben, Laenge vergleichen,
 * dann umbenennen. Rueckgabe true nur, wenn die Datei danach dasteht. Vorbild ew_json_schreiben() der
 * Ecowitt-Weiche 0.9.38.
 */
function sw_json_schreiben($pfad, array $daten)
{
    if ($pfad === '') {
        return false;
    }
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return false;
    }
    $js = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($js === false) {
        return false;
    }
    $tmp = $pfad . '.tmp.' . getmypid();
    $umask_alt = umask(0077);
    $fh = @fopen($tmp, 'c');
    umask($umask_alt);
    if ($fh === false) {
        return false;
    }
    @chmod($tmp, 0600);
    $ok = ftruncate($fh, 0) && @fwrite($fh, $js) === strlen($js);
    if (!@fflush($fh)) {
        $ok = false;
    }
    if (!@fclose($fh)) {
        $ok = false;
    }
    if (!$ok || !@rename($tmp, $pfad)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Selbstheilung nach Inhalt, aufgerufen, wenn die Konfiguration keinen Inhalt traegt (fehlt, "{}" oder unlesbar):
 * traegt die Zweitschrift Inhalt, wird aus ihr geheilt; ein verdraengter Stand, der mehr ist als "{}", bleibt als
 * shelly.json.kaputt (0600) liegen. Die Zweitschrift selbst wird nicht angefasst. Rueckgabe: der Stand, mit dem
 * sw_config() weiterarbeitet (null = neu).
 */
function sw_config_heilen(array $p, $d)
{
    if ($p['cfgdatei'] === '') {
        return $d;
    }
    $da = is_file($p['cfgdatei']);
    $kaputt = $da && $d === null;
    $z = sw_json_lesen($p['sicherung']);
    if (!sw_hat_inhalt($z) && !$kaputt) {
        return $d;
    }
    if ($kaputt || (is_array($d) && count($d) > 0)) {
        $k = $p['cfgdatei'] . '.kaputt';
        if (@rename($p['cfgdatei'], $k)) {
            @chmod($k, 0600);
        }
    }
    if (!sw_hat_inhalt($z)) {
        sw_log('Konfiguration unlesbar und keine Zweitschrift mit Inhalt - der Stand liegt als '
             . basename($p['cfgdatei']) . '.kaputt, begonnen wird mit den Vorgaben.');
        return null;
    }
    if (!sw_json_schreiben($p['cfgdatei'], $z)) {
        sw_log('Konfiguration ohne Inhalt; die Zweitschrift liess sich nicht zurueckschreiben.');
        return $z;
    }
    sw_log('Konfiguration aus der Zweitschrift wiederhergestellt.');
    return $z;
}

/** Der Zustand der Konfiguration, wie ihn der ERSTE Aufruf von sw_config() in diesem Prozess vorfand - vor der
 *  Heilung (ok, fehlt, leer, ohne_token, zweitschrift, kaputt_zweitschrift, kaputt; null = noch nicht gelesen). */
function sw_config_zustand($neu = null)
{
    static $z = null;
    if ($neu !== null && $z === null) {
        $z = $neu;
    }
    return $z;
}

/**
 * Die Konfiguration lesen.
 * $erzeugen = true (Oberflaeche): heilt aus der Zweitschrift und legt beim ersten Mal ein Token an.
 * $erzeugen = false (Endpunkt, Takt, Lauscher): liest NUR. Traegt die Datei keinen Inhalt, kommt null zurueck;
 * geschrieben, geheilt oder angelegt wird nichts (Regeln/05 "Der unangemeldete Endpunkt darf nichts anlegen").
 * Werte, die die Pruefung nicht bestehen (eine von Hand bearbeitete Datei), gelten als Vorgabe - die Oberflaeche
 * nennt sie im Reiter Test (sw_rueckspiel_altwerte()).
 */
function sw_config($erzeugen = true)
{
    $p = sw_paths();
    $c = sw_vorgaben();
    $je_gesetzt = false;
    $d = sw_json_lesen($p['cfgdatei']);
    if (!sw_hat_inhalt($d)) {
        if (!$erzeugen) {
            return null;
        }
        $da = $p['cfgdatei'] !== '' && is_file($p['cfgdatei']);
        $vorher = !$da ? 'fehlt'
                : ($d === null ? 'kaputt' : (count($d) === 0 ? 'leer' : 'ohne_token'));
        $d = sw_config_heilen($p, $d);
        if (sw_hat_inhalt($d)) {
            $vorher = ($vorher === 'kaputt') ? 'kaputt_zweitschrift' : 'zweitschrift';
        }
        sw_config_zustand($vorher);
    } else {
        sw_config_zustand('ok');
    }
    if (is_array($d)) {
        foreach ($c as $k => $v) {
            if (array_key_exists($k, $d)) {
                list($ok, $w) = sw_wert_pruefen($k, $d[$k]);
                if ($ok) {
                    $c[$k] = $w;
                }
            }
        }
        $je_gesetzt = array_key_exists('token', $d);
    }
    if ($erzeugen && !$je_gesetzt && $c['token'] === '') {
        $c['token'] = sw_token_erzeugen();
        sw_config_speichern($c);
    }
    return $c;
}

/** Konfiguration und Zweitschrift schreiben, beide unteilbar. Rueckgabe true nur, wenn die Konfiguration dasteht;
 *  $zweit sagt, ob auch die Zweitschrift steht. */
function sw_config_speichern(array $c, &$zweit = null)
{
    $p = sw_paths();
    $zweit = false;
    if (!sw_json_schreiben($p['cfgdatei'], $c)) {
        return false;
    }
    if (sw_hat_inhalt($c)) {
        $zweit = sw_json_schreiben($p['sicherung'], $c);
        if (!$zweit) {
            sw_log('Die Zweitschrift ' . basename($p['sicherung']) . ' liess sich nicht schreiben.');
        }
    }
    return true;
}

/**
 * Fehlende Vorgaben EINMAL in shelly.json schreiben (Regeln/05, Regeln/03 "Der Cron-Lauf ergaenzt fehlende
 * Einstellungen selbst und sagt im Protokoll, welche"). Nur mit Wurzel, nicht waehrend einer Aktualisierung, nur
 * wenn die Datei ein Objekt mit nicht leerem, tauglichem Token ist; geschrieben werden die Werte AUS DER DATEI plus
 * die fehlenden Vorgaben; eine Protokollzeile mit den Namen, nie mit Werten.
 */
function sw_config_vervollstaendigen()
{
    $p = sw_paths();
    if ($p['lbhome'] === '' || $p['datadir'] === '' || $p['cfgdatei'] === '') {
        return array('stand' => 'ruht', 'schluessel' => array());
    }
    if (sw_marke_frisch(sw_marke_datei())) {
        return array('stand' => 'ruht', 'schluessel' => array());
    }
    clearstatcache(true, $p['cfgdatei']);
    if (!is_file($p['cfgdatei'])) {
        return array('stand' => 'ohne_datei', 'schluessel' => array());
    }
    $roh = @file_get_contents($p['cfgdatei']);
    $datei = is_string($roh) ? json_decode($roh, true) : null;
    if (!is_array($datei) || !isset($datei['token']) || !sw_token_taugt($datei['token']) || $datei['token'] === '') {
        return array('stand' => 'ohne_merkwort', 'schluessel' => array());
    }
    $fehlend = array();
    foreach (sw_vorgaben() as $k => $w) {
        if (!array_key_exists($k, $datei)) {
            $datei[$k] = $w;
            $fehlend[] = $k;
        }
    }
    if (!$fehlend) {
        return array('stand' => 'vollstaendig', 'schluessel' => array());
    }
    if (!sw_config_speichern($datei)) {
        sw_log('Konfiguration NICHT vervollstaendigt: shelly.json liess sich nicht schreiben ('
             . count($fehlend) . ' fehlende Schluessel).');
        return array('stand' => 'fehler', 'schluessel' => $fehlend);
    }
    sw_log('Konfiguration vervollstaendigt - ' . count($fehlend)
         . ' fehlende Vorgaben in shelly.json geschrieben: ' . implode(', ', $fehlend));
    return array('stand' => 'ergaenzt', 'schluessel' => $fehlend);
}

function sw_token_erzeugen()
{
    $b = @random_bytes(16);
    if ($b === false || $b === null) {
        return substr(str_replace('.', '', uniqid('', true)), 0, 32);
    }
    return bin2hex($b);
}

/** Protokoll log/plugins/<ordner>/shellyweiche.log, gekappt (Regeln/03 "Log-Kappung ist Pflicht"). Nie ein Token,
 *  nie ein Kennwort, nie ein Geraetepraefix. */
function sw_log($text)
{
    $p = sw_paths();
    if ($p['log'] === '') {
        return;
    }
    if (!is_dir($p['log'])) {
        @mkdir($p['log'], 0775, true);
    }
    $f = sw_log_datei();
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 262144) {
        $z = file($f, FILE_IGNORE_NEW_LINES) ?: array();
        @file_put_contents($f, implode("\n", array_slice($z, -800)) . "\n");
    }
    @file_put_contents($f, date('Y-m-d H:i:s') . ' ' . $text . "\n", FILE_APPEND);
}

function sw_log_datei()
{
    $p = sw_paths();
    return $p['log'] !== '' ? $p['log'] . '/shellyweiche.log' : '';
}

/** Das Ende einer Protokolldatei rueckwaerts mit fseek lesen (VORLAGE_hausstandard.css.html, nie exec("tail")). */
function sw_log_ende($datei, $anzahl = 200, $block = 8192)
{
    if ($datei === '' || !is_file($datei)) {
        return array();
    }
    $fp = @fopen($datei, 'rb');
    if ($fp === false) {
        return array();
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
}

/* ==================================================================
 * SICHERN UND ZURUECKSPIELEN - gemeinsames Sicherungsmodul
 * ==================================================================
 * Die Sicherungsdatei traegt das Aktionstoken (ohne es waere sie nach dem
 * Zurueckspielen wertlos) und die Geraeteliste mit den Praefixen. Es gibt keine
 * aeltere Fassung dieser Linie, also keinen Uebersetzer und keine spaeten
 * Schluessel: jeder Schluessel der Vorgaben ist Pflicht.
 * ================================================================== */
function sw_sicherung_beschreibung()
{
    $p = sw_paths();
    return array(
        'kennzeichen' => 'LoxBerry-Plugin-Shelly-Weiche',
        'hinweis' => sw_t('TEXT.SICH_KOPF'),
        'home' => (string) $p['lbhome'], 'ordner' => (string) $p['plugin'],
        'plugin_cfg' => dirname(dirname(__DIR__)) . '/plugin.cfg',
        'vorgaben' => sw_vorgaben(),
        'schluessel' => array(
            'geraete' => sicherung_liste(array('praefix', 'name', 'ein'), 'praefix'),
            'haus_klima_ein' => array('wandeln' => function ($w) {
                list(, $v) = sw_wert_pruefen('haus_klima_ein', $w);
                return $v;
            }),
        ),
        'pruefen' => function ($k, $w) {
            list($ok, , $grund) = sw_wert_pruefen($k, $w);
            return $ok ? '' : $grund;
        },
        'jetzt' => function () {
            $w = sw_json_lesen(sw_paths()['cfgdatei']);
            return is_array($w) ? $w : array();
        },
        't' => 'sw_t',
    );
}

/** Die Beschreibung fuer den Knopf "Einstellungen zurueckspielen": Meldungen des Moduls als Klartext ('e'), denn die
 *  Oberflaeche maskiert jede Meldung einmal bei der Ausgabe (sw_e()). */
function sw_sicherung_klartext()
{
    return array('e' => function ($s) { return (string) $s; }) + sw_sicherung_beschreibung();
}

/**
 * Nach dem Zurueckspielen nachziehen und sagen, was geschah (Regeln/05). Die Weiche hat keinen Dauerdienst: Takt
 * und Lauscher lesen die Konfiguration in jedem Lauf neu, ein Neustart ist nicht noetig. Was nach dem Zurueckspielen
 * nicht mehr gesendet wird, wird sofort mit Ruecklesen abgeraeumt; der naechste Minutenlauf sendet den vollen Satz.
 */
function sw_sicherung_nachziehen($neu, $jetzt, $r)
{
    $h = array();
    if ((string) $neu['token'] === '') {
        $h[] = sw_t('TEXT.SICH_OHNE_TOKEN');
    }
    $h[] = sw_t('TEXT.SICH_DIENST_TAKT');
    list($hm) = sw_haus_folgen($neu);
    if ($hm !== '') {
        $h[] = $hm;
    }
    return $h;
}

/** Die Sicherungsdatei (lesbarer Kopf und alle Schluessel der Vorgaben), gebaut vom Sicherungsmodul. */
function sw_sicherung_bauen(array $cfg)
{
    return sicherung_json(sicherung_bauen(sw_sicherung_beschreibung(), $cfg));
}

/** Welche gespeicherten Werte bestuenden das eigene Zurueckspielen nicht? Rueckgabe: Namen, nie Werte. */
function sw_rueckspiel_altwerte(array $cfg)
{
    $b = sw_sicherung_beschreibung();
    $d = sicherung_bauen($b, $cfg);
    unset($d['_warnung']);
    return sicherung_selbstpruefung($b, $d, $cfg);
}

/* ==================================================================
 * SPRACHE, MASKIEREN, FORMULARSCHUTZ, EINMALMELDUNG
 * ================================================================== */

function sw_sprachdatei()
{
    $t = getenv('LBPTEMPLATEDIR');
    if ($t === false || $t === '') {
        $lb = sw_paths()['lbhome'];
        $kand = array();
        if ($lb !== '') {
            $kand[] = rtrim($lb, '/\\') . '/templates/plugins/' . basename(__DIR__);
        }
        $kand[] = dirname(dirname(dirname(dirname(__DIR__)))) . '/templates/plugins/' . basename(__DIR__);
        $kand[] = dirname(dirname(__DIR__)) . '/templates';
        $t = $kand[count($kand) - 1];
        foreach ($kand as $k) {
            if (is_dir($k . '/lang')) {
                $t = $k;
                break;
            }
        }
    }
    $lang = 'de';
    $lb = sw_paths()['lbhome'];
    $g = $lb !== '' ? $lb . '/config/system/general.json' : '';
    if ($g !== '' && is_file($g)) {
        $d = json_decode((string) @file_get_contents($g), true);
        if (isset($d['Base']['Lang']) && $d['Base']['Lang'] === 'en') {
            $lang = 'en';
        }
    }
    $f = $t . '/lang/language_' . $lang . '.ini';
    return is_file($f) ? $f : $t . '/lang/language_de.ini';
}

function sw_t($schluessel)
{
    static $tab = null;
    if ($tab === null) {
        $tab = @parse_ini_file(sw_sprachdatei(), true);
        if (!is_array($tab)) {
            $tab = array();
        }
    }
    $teil = explode('.', $schluessel, 2);
    if (count($teil) === 2 && isset($tab[$teil[0]][$teil[1]])) {
        return $tab[$teil[0]][$teil[1]];
    }
    return $schluessel;
}

/* Der Escape-Helfer gehoert in die Bibliothek: Oberflaeche und Endpunkt brauchen ihn beide. */
function sw_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/*
 * Wachposten gegen fremde Formulare (Bauart ew_merkwort()/ew_wachposten() der Ecowitt-Weiche): das Merkmal wird
 * aus einem Merkwort im Datenordner abgeleitet (HMAC), nicht gespeichert; der leere Fall wird vor hash_equals()
 * abgefangen; gelesen wird aus $_POST und $_GET, nie aus $_REQUEST.
 */
function sw_merkwort()
{
    static $wort = null;
    if ($wort !== null) {
        return $wort;
    }
    $verz = sw_paths()['datadir'];
    if ($verz === '') {
        return '';
    }
    $datei = $verz . '/formmerkwort';
    if (is_readable($datei)) {
        $roh = trim((string) @file_get_contents($datei));
        if (preg_match('/^[0-9a-f]{32,64}$/', $roh)) {
            $wort = $roh;
            return $wort;
        }
    }
    if (function_exists('random_bytes')) {
        $neu = bin2hex(random_bytes(24));
    } else {
        $neu = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 48);
    }
    if (!is_dir($verz)) {
        @mkdir($verz, 0775, true);
    }
    $tmp = $datei . '.tmp';
    $umask_alt = umask(0077);
    $ok = @file_put_contents($tmp, $neu) !== false;
    umask($umask_alt);
    if ($ok) {
        @chmod($tmp, 0600);
        if (@rename($tmp, $datei)) {
            @chmod($datei, 0600);
        } else {
            @unlink($tmp);
        }
    }
    $wort = $neu;
    return $wort;
}

function sw_formtoken()
{
    $grund = sw_merkwort();
    return $grund === '' ? '' : hash_hmac('sha256', 'formular-v1', $grund);
}

/** Das versteckte Feld des Formularschutzes. */
function sw_fmt()
{
    return '<input data-role="none" type="hidden" name="fmt" value="'
         . htmlspecialchars(sw_formtoken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Rueckgabe: '' wenn die Anfrage durchgelassen wird, sonst der Grund. */
function sw_wachposten()
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '';
    }
    $soll = sw_formtoken();
    $ist = isset($_POST['fmt']) ? $_POST['fmt'] : (isset($_GET['fmt']) ? $_GET['fmt'] : null);
    if (!is_string($ist) || $ist === '' || $soll === '') {
        return sw_t('WACHE.FEHLT');
    }
    if (!hash_equals($soll, $ist)) {
        return sw_t('WACHE.FALSCH');
    }
    return '';
}

/* Einmalmeldung nach einem POST (Regeln/04: jeder POST endet mit einer Umleitung): eine Datei im Datenordner, 0600,
   gelesen NUR beim GET und dabei geloescht, aelter als 120 s verworfen. Kein Token darin. */
function sw_einmal_datei()
{
    $p = sw_paths();
    return $p['datadir'] !== '' ? $p['datadir'] . '/einmalmeldung.json' : '';
}

function sw_einmal_schreiben(array $m)
{
    $f = sw_einmal_datei();
    $m['zeit'] = time();
    return $f !== '' && sw_json_schreiben($f, $m);
}

function sw_einmal_lesen()
{
    $f = sw_einmal_datei();
    if ($f === '' || !is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) {
        return null;
    }
    return $d;
}

/** Ein Alter in Worten. */
function sw_alter_text($sek)
{
    $sek = (int) $sek;
    if ($sek < 0) {
        return sw_t('ALTER.ZUKUNFT');
    }
    if ($sek < 120) {
        return sprintf(sw_t('ALTER.S'), $sek);
    }
    if ($sek < 7200) {
        return sprintf(sw_t('ALTER.MIN'), (int) floor($sek / 60));
    }
    if ($sek < 172800) {
        return sprintf(sw_t('ALTER.H'), (int) floor($sek / 3600));
    }
    return sprintf(sw_t('ALTER.T'), (int) floor($sek / 86400));
}

/** Die Upgrade-Marke neben dem Datenordner (preupgrade.sh legt sie mit der Unixzeit an). */
function sw_marke_datei()
{
    $p = sw_paths();
    return $p['datadir'] !== '' ? dirname($p['datadir']) . '/' . $p['plugin'] . '.upgrade_laeuft' : '';
}

/** Gilt die Marke? Inhalt Unixzeit, nicht aus der Zukunft, juenger als SW_MARKE_FRIST (Hausregel 18.11.2026). */
function sw_marke_frisch($marke)
{
    if ($marke === '') {
        return false;
    }
    clearstatcache(true, $marke);
    if (!is_file($marke)) {
        return false;
    }
    $t = trim((string) @file_get_contents($marke));
    if (!preg_match('/^[0-9]{1,12}$/', $t)) {
        return false;
    }
    $alter = time() - (int) $t;
    return $alter >= 0 && $alter < SW_MARKE_FRIST;
}

/** Der Port des LoxBerry-Webservers (general.json), sonst 80. */
function sw_webport()
{
    $p = sw_paths();
    $d = $p['lbhome'] !== '' ? sw_json_lesen($p['lbhome'] . '/config/system/general.json') : null;
    $port = (is_array($d) && isset($d['Webserver']['Port'])) ? (int) $d['Webserver']['Port'] : 0;
    return ($port > 0 && $port <= 65535) ? $port : 80;
}

/** Der Rechnername fuer die Adressen im Reiter Einbindung in Loxone. */
function sw_rechner()
{
    $h = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    if ($h === '' || preg_match('/^[A-Za-z0-9.\-:\[\]]{1,255}\z/', $h) !== 1) {
        $h = 'loxberry';
    }
    return $h;
}

/** Zustand des MQTT-Gateways laut general.json: Fassung (Mqtt.Gatewayversion, Rueckfall 1) und Autostart. */
function sw_mqtt_gateway()
{
    $aus = array('fassung' => 0, 'autostart' => 0, 'gefunden' => 0);
    $lb = sw_paths()['lbhome'];
    $g = $lb !== '' ? sw_json_lesen($lb . '/config/system/general.json') : null;
    $m = (is_array($g) && isset($g['Mqtt']) && is_array($g['Mqtt'])) ? $g['Mqtt'] : null;
    if ($m === null) {
        return $aus;
    }
    $aus['gefunden'] = 1;
    $aus['fassung'] = (isset($m['Gatewayversion']) && is_numeric($m['Gatewayversion'])) ? (int) $m['Gatewayversion'] : 1;
    $aus['autostart'] = (isset($m['Gatewayautostart'])
        && in_array((string) $m['Gatewayautostart'], array('1', 'true'), true)) ? 1 : 0;
    return $aus;
}

/* ==================================================================
 * MQTT - der Broker des LoxBerry, Pakete nach MQTT 3.1.1
 * ==================================================================
 * Zugang aus general.json (Mqtt.Brokerhost/-port/-user/-pass, Regeln/07
 * Abschnitt 2). Das Kennwort steht nur im CONNECT-Paket, nie in einem
 * Protokoll, nie auf einer Befehlszeile, nie in einer Datei dieses Plugins
 * (Regeln/05). Bauart der kurzen Sitzung: ew_haus_sitzung() der
 * Ecowitt-Weiche 0.9.38 (Abschrift von spot_haus_sitzung(), Spotpreis aWATTar
 * 1.2.39), hier in Bausteine zerlegt, damit der Lauscher dieselben Pakete baut.
 * ================================================================== */

/** Der Zugang zum Broker oder null (keine Mqtt-Angaben in general.json). */
function sw_broker()
{
    $p = sw_paths();
    $gen = $p['lbhome'] !== '' ? sw_json_lesen($p['lbhome'] . '/config/system/general.json') : null;
    if (!is_array($gen) || !isset($gen['Mqtt']) || !is_array($gen['Mqtt']) || !$gen['Mqtt']) {
        return null;
    }
    $m = $gen['Mqtt'];
    $hol = function ($k) use ($m) {
        return (isset($m[$k]) && is_scalar($m[$k])) ? (string) $m[$k] : '';
    };
    $host = trim($hol('Brokerhost'));
    if ($host === '' || $host === 'localhost') {
        $host = '127.0.0.1';
    }
    $port = (int) $hol('Brokerport');
    if ($port <= 0 || $port > 65535) {
        $port = 1883;
    }
    return array('host' => $host, 'port' => $port, 'user' => $hol('Brokeruser'), 'pass' => $hol('Brokerpass'));
}

function sw_mq_zk($t)
{
    return pack('n', strlen($t)) . $t;
}

function sw_mq_laenge($n)
{
    $o = '';
    do {
        $b = $n % 128;
        $n = intdiv($n, 128);
        if ($n > 0) {
            $b |= 128;
        }
        $o .= chr($b);
    } while ($n > 0);
    return $o;
}

function sw_mq_schreiben($s, $daten)
{
    $daten = (string) $daten;
    return @fwrite($s, $daten) === strlen($daten);
}

/** Genau $n Byte lesen, bis $bis (microtime). null bei Zeitablauf oder Ende der Verbindung. */
function sw_mq_lies($s, $n, $bis)
{
    $d = '';
    while (strlen($d) < $n) {
        if (microtime(true) >= $bis) {
            return null;
        }
        $t = @fread($s, min(65536, $n - strlen($d)));
        if ($t === false || $t === '') {
            $meta = stream_get_meta_data($s);
            if (!empty($meta['eof']) || feof($s)) {
                return null;
            }
            continue;
        }
        $d .= $t;
    }
    return $d;
}

/** Ein Paket lesen: array(Kopfbyte, Rumpf) oder null. Ein Rumpf ueber SW_PAKET_MAX ist ein Fehler (null). */
function sw_mq_paket($s, $bis)
{
    $k = sw_mq_lies($s, 1, $bis);
    if ($k === null) {
        return null;
    }
    $n = 0;
    $mult = 1;
    for ($i = 0; $i < 4; $i++) {
        $b = sw_mq_lies($s, 1, $bis);
        if ($b === null) {
            return null;
        }
        $n += (ord($b) & 127) * $mult;
        $mult *= 128;
        if (!(ord($b) & 128)) {
            break;
        }
    }
    if ($n > SW_PAKET_MAX) {
        return null;
    }
    $r = ($n > 0) ? sw_mq_lies($s, $n, $bis) : '';
    return ($r === null) ? null : array(ord($k), $r);
}

/** Thema und Nutzlast eines PUBLISH-Pakets: array(Thema, Nutzlast, retain) oder null. */
function sw_mq_publish_lesen(array $pk)
{
    if (($pk[0] >> 4) !== 3 || strlen($pk[1]) < 2) {
        return null;
    }
    $tl = unpack('n', substr($pk[1], 0, 2));
    $t = (string) substr($pk[1], 2, $tl[1]);
    $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
    return array($t, (string) substr($pk[1], $versatz), ($pk[0] & 1) ? 1 : 0);
}

/**
 * Verbinden und anmelden. Rueckgabe array(lage, Griff): lage ok | kein_broker | abgelehnt | zeit. Der Griff ist
 * nur bei ok offen.
 */
function sw_mq_verbinden($kennung, $keepalive = 60)
{
    $b = sw_broker();
    if ($b === null) {
        return array('kein_broker', null);
    }
    $errno = 0;
    $errstr = '';
    $s = @stream_socket_client('tcp://' . $b['host'] . ':' . $b['port'], $errno, $errstr, 2);
    if (!$s) {
        return array('kein_broker', null);
    }
    stream_set_timeout($s, 1);
    $flags = 0x02;
    $nutz = sw_mq_zk((string) $kennung);
    if ($b['user'] !== '') {
        $flags |= 0x80;
        if ($b['pass'] !== '') {
            $flags |= 0x40;
        }
    }
    $kopf = sw_mq_zk('MQTT') . chr(4) . chr($flags) . pack('n', (int) $keepalive);
    if ($b['user'] !== '') {
        $nutz .= sw_mq_zk($b['user']);
        if ($b['pass'] !== '') {
            $nutz .= sw_mq_zk($b['pass']);
        }
    }
    if (!sw_mq_schreiben($s, chr(0x10) . sw_mq_laenge(strlen($kopf . $nutz)) . $kopf . $nutz)) {
        fclose($s);
        return array('kein_broker', null);
    }
    $ack = sw_mq_paket($s, microtime(true) + 5.0);
    if ($ack === null || ($ack[0] >> 4) !== 2 || strlen($ack[1]) < 2) {
        fclose($s);
        return array('zeit', null);
    }
    if (ord($ack[1][1]) !== 0) {
        fclose($s);
        return array('abgelehnt', null);
    }
    return array('ok', $s);
}

/** SUBSCRIBE der Filter (QoS 0) und auf das SUBACK warten. Pakete, die vorher kommen (zurueckbehaltene Werte),
 *  gehen an $bei. Rueckgabe ok | abgelehnt | zeit. */
function sw_mq_abonnieren($s, array $filter, $bei = null)
{
    $sub = pack('n', 1);
    foreach ($filter as $t) {
        $sub .= sw_mq_zk((string) $t) . chr(0);
    }
    if (!sw_mq_schreiben($s, chr(0x82) . sw_mq_laenge(strlen($sub)) . $sub)) {
        return 'zeit';
    }
    $ende = microtime(true) + 3.0;
    while (microtime(true) < $ende) {
        $pk = sw_mq_paket($s, $ende);
        if ($pk === null) {
            if (feof($s)) {
                return 'zeit';
            }
            continue;
        }
        $art = $pk[0] >> 4;
        if ($art === 9) {
            $rc = (string) substr($pk[1], 2);
            if (strlen($rc) !== count($filter)) {
                return 'abgelehnt';
            }
            for ($i = 0; $i < strlen($rc); $i++) {
                if (ord($rc[$i]) >= 0x80) {
                    return 'abgelehnt';
                }
            }
            return 'ok';
        }
        if ($art === 3 && is_callable($bei)) {
            call_user_func($bei, $pk);
        }
    }
    return 'zeit';
}

/** Ein PUBLISH QoS 0; eine leere Nutzlast nur mit retain (Abraeumen). */
function sw_mq_senden($s, $thema, $wert, $retain)
{
    $thema = (string) $thema;
    $wert = (string) $wert;
    if ($thema === '' || strpbrk($thema, "#+\0") !== false || ($wert === '' && !$retain)) {
        return false;
    }
    $rumpf = sw_mq_zk($thema) . $wert;
    return sw_mq_schreiben($s, chr(0x30 | ($retain ? 1 : 0)) . sw_mq_laenge(strlen($rumpf)) . $rumpf);
}

/**
 * DISCONNECT und geordnet schliessen: erst die Schreibseite zu, dann lesen, bis der Broker schliesst (hoechstens
 * 1 s). Ein fclose() mit ungelesenen Daten im Empfangspuffer schickt ein RST - dabei koennen die eben gesendeten
 * PUBLISH beim Broker verloren gehen. Gemessen an der Broker-Attrappe 22.11.2026 (Vorprobe der Eichung): eine Sitzung,
 * die ihre eigenen Themen abonniert hatte, bekam ihre Werte zurueck, schloss ungelesen, und von vier retained
 * Themen kamen in mehreren Laeufen nur zwei an.
 */
function sw_mq_trennen($s)
{
    if (!is_resource($s)) {
        return;
    }
    sw_mq_schreiben($s, chr(0xE0) . chr(0));
    @stream_socket_shutdown($s, STREAM_SHUT_WR);
    $bis = microtime(true) + 1.0;
    while (microtime(true) < $bis) {
        $t = @fread($s, 65536);
        if ($t === false || ($t === '' && feof($s))) {
            break;
        }
    }
    fclose($s);
}

/**
 * Eine kurze Sitzung am Broker: CONNECT, SUBSCRIBE der genauen Themen in $lesen (QoS 0, SUBACK geprueft),
 * SW_LESEFENSTER s zurueckbehaltene Werte sammeln, dann PUBLISH QoS 0 - $senden ist Thema => array(Wert, retain).
 * Ist $entscheiden ein Aufruf, bekommt er die gelesenen Werte und gibt die Liste zurueck, die tatsaechlich gesendet
 * wird (Pruefung auf fremde Werte VOR dem Senden). Rueckgabe array('lage' => ok|kein_broker|abgelehnt|zeit,
 * 'werte' => Thema => Wert (nur Retain-Bit), 'gesendet' => n, 'themen' => gesendete Themen).
 */
function sw_haus_sitzung(array $senden, array $lesen, $entscheiden = null)
{
    $aus = array('lage' => 'kein_broker', 'werte' => array(), 'gesendet' => 0, 'themen' => array());
    $soll = array();
    foreach ($lesen as $t) {
        $t = (string) $t;
        if ($t !== '' && strpbrk($t, "#+\0") === false) {
            $soll[$t] = true;
        }
    }
    $soll = array_slice(array_keys($soll), 0, 50);
    list($lage, $s) = sw_mq_verbinden('swhaus' . getmypid());
    if ($lage !== 'ok') {
        $aus['lage'] = $lage;
        return $aus;
    }
    /* Nur ein zurueckbehaltener Wert (Retain-Bit) zaehlt: er ist das, was ein Abnehmer beim Anmelden bekommt. */
    $merke = function ($pk) use (&$aus, $soll) {
        $pub = sw_mq_publish_lesen($pk);
        if ($pub !== null && $pub[2] === 1 && in_array($pub[0], $soll, true)) {
            $aus['werte'][$pub[0]] = $pub[1];
        }
    };
    if ($soll) {
        $lage = sw_mq_abonnieren($s, $soll, $merke);
        if ($lage === 'ok') {
            $ende = microtime(true) + SW_LESEFENSTER;
            while (microtime(true) < $ende) {
                $pk = sw_mq_paket($s, $ende);
                if ($pk === null) {
                    if (feof($s)) {
                        break;
                    }
                    continue;
                }
                $merke($pk);
            }
        }
    }
    if ($lage === 'ok') {
        if (is_callable($entscheiden)) {
            $senden = call_user_func($entscheiden, $aus['werte']);
            if (!is_array($senden)) {
                $senden = array();
            }
        }
        foreach ($senden as $t => $wr) {
            $w = is_array($wr) ? $wr[0] : $wr;
            $ret = is_array($wr) && !empty($wr[1]);
            if (sw_mq_senden($s, (string) $t, (string) $w, $ret)) {
                $aus['gesendet']++;
                $aus['themen'][] = (string) $t;
            }
        }
    }
    sw_mq_trennen($s);
    $aus['lage'] = $lage;
    return $aus;
}

/* ==================================================================
 * GERAETE - Erkennen an der Meldung, Zustand im Datenordner
 * ==================================================================
 * data/plugins/<ordner>/geraete.json: 'start' (wann dieser Zustand angelegt
 * wurde - fuer die Startsperre), 'geraete' (Praefix => Stand). Je Geraet: das
 * Modell (Teil der Kennung vor dem letzten "-", nie die Kennung selbst), die
 * letzten Werte mit ihrem Empfangszeitpunkt, Batterie, online, Zahl der
 * Meldungen und die letzten Abstaende. Rohmeldungen werden nicht abgelegt.
 * ================================================================== */

/** Die Felder fuer haus/klima: array(Feld, Komponente, Schluessel, Rundung, ohne Aussage, kleinster, groesster). */
function sw_haus_felder()
{
    return array(
        array('temperatur', 'temperature:0', 'tC', 'z1', -999, -60, 100),
        array('feuchte', 'humidity:0', 'rh', 'z0', -1, 0, 100),
    );
}

/** Eine Zahl aus der Meldung: nur int oder float (JSON-Zahl), nie bool, nie Text. */
function sw_zahl($v)
{
    return (is_int($v) || is_float($v)) && is_finite((float) $v) ? (float) $v : null;
}

/**
 * Eine Meldung auswerten (rein, ohne Datei): $thema wie empfangen, $nutzlast roh. Rueckgabe null (keine Meldung
 * eines Fuehlers) oder array('praefix', 'modell', 'methode', 'ts' (float|null), 'werte' => Feld => Zahl|null
 * (null = geliefert, aber unplausibel), 'batterie' => int|null). Eine Meldung ohne temperature:0.tC und ohne
 * humidity:0.rh ist keine Fuehlermeldung (andere Shelly, NotifyEvent).
 */
function sw_meldung_auswerten($thema, $nutzlast)
{
    $thema = (string) $thema;
    if (substr($thema, -11) !== '/events/rpc' || strlen($nutzlast) > SW_NUTZLAST_MAX) {
        return null;
    }
    $praefix = substr($thema, 0, -11);
    if (!sw_praefix_taugt($praefix)) {
        return null;
    }
    $d = json_decode((string) $nutzlast, true);
    if (!is_array($d) || !isset($d['method']) || !is_string($d['method'])
        || !in_array($d['method'], array('NotifyFullStatus', 'NotifyStatus'), true)
        || !isset($d['params']) || !is_array($d['params'])) {
        return null;
    }
    $pa = $d['params'];
    $werte = array();
    foreach (sw_haus_felder() as $f) {
        if (isset($pa[$f[1]]) && is_array($pa[$f[1]]) && array_key_exists($f[2], $pa[$f[1]])) {
            $x = sw_zahl($pa[$f[1]][$f[2]]);
            $werte[$f[0]] = ($x !== null && $x >= $f[5] && $x <= $f[6]) ? $x : null;
        }
    }
    if (!$werte) {
        return null;
    }
    $modell = '';
    if (isset($d['src']) && is_string($d['src']) && preg_match('/^([a-z0-9]{2,24})-[A-Za-z0-9]+\z/', $d['src'], $m)) {
        $modell = $m[1];
    }
    $bat = null;
    if (isset($pa['devicepower:0']['battery']['percent'])) {
        $b = sw_zahl($pa['devicepower:0']['battery']['percent']);
        if ($b !== null && $b >= 0 && $b <= 100) {
            $bat = (int) round($b);
        }
    }
    $ts = isset($pa['ts']) ? sw_zahl($pa['ts']) : null;
    if ($ts !== null && $ts < 1500000000) {
        $ts = null;
    }
    return array('praefix' => $praefix, 'modell' => $modell, 'methode' => $d['method'], 'ts' => $ts,
                 'werte' => $werte, 'batterie' => $bat);
}

/**
 * Eine ausgewertete Meldung in den Zustand eintragen. Doppelt (dieselbe Meldung, von zwei Lauschern in der
 * Ueberlappung empfangen) erkennt die Funktion an Methode und Zeitstempel des Geraets; ohne Zeitstempel an Methode
 * und Werten binnen 10 s. Rueckgabe 'neu' | 'doppelt' | 'voll' (SW_GERAETE_MAX erreicht, neues Geraet verworfen).
 */
function sw_meldung_eintragen(array &$stand, array $m, $empfang)
{
    if (!isset($stand['geraete']) || !is_array($stand['geraete'])) {
        $stand['geraete'] = array();
    }
    $p = $m['praefix'];
    if (!isset($stand['geraete'][$p])) {
        if (count($stand['geraete']) >= SW_GERAETE_MAX) {
            return 'voll';
        }
        $stand['geraete'][$p] = array('erst' => (int) $empfang, 'zahl' => 0, 'werte' => array(),
                                      'werte_zeit' => array(), 'abstaende' => array(), 'zuletzt' => array());
    }
    $g =& $stand['geraete'][$p];
    $schluessel = $m['methode'] . '|' . ($m['ts'] !== null ? sprintf('%.3F', $m['ts'])
        : 'ohne|' . json_encode($m['werte']) . '|' . (int) floor($empfang / 10));
    $zuletzt = (isset($g['zuletzt']) && is_array($g['zuletzt'])) ? $g['zuletzt'] : array();
    if (in_array($schluessel, $zuletzt, true)) {
        return 'doppelt';
    }
    $zuletzt[] = $schluessel;
    $g['zuletzt'] = array_slice($zuletzt, -8);
    if (isset($g['empfang']) && (int) $g['empfang'] > 0 && $empfang >= (int) $g['empfang']) {
        $ab = (isset($g['abstaende']) && is_array($g['abstaende'])) ? $g['abstaende'] : array();
        $ab[] = (int) $empfang - (int) $g['empfang'];
        $g['abstaende'] = array_slice($ab, -SW_ABSTAENDE);
    }
    $g['empfang'] = (int) $empfang;
    $g['zahl'] = (isset($g['zahl']) ? (int) $g['zahl'] : 0) + 1;
    $g['methode'] = $m['methode'];
    $g['ts'] = $m['ts'];
    if ($m['modell'] !== '') {
        $g['modell'] = $m['modell'];
    }
    if ($m['batterie'] !== null) {
        $g['batterie'] = $m['batterie'];
    }
    foreach ($m['werte'] as $f => $x) {
        $g['werte'][$f] = $x;
        $g['werte_zeit'][$f] = (int) $empfang;
    }
    unset($g);
    return 'neu';
}

function sw_geraete_datei()
{
    $p = sw_paths();
    return $p['datadir'] !== '' ? $p['datadir'] . '/geraete.json' : '';
}

/** Der Geraetezustand; ein fehlender wird mit 'start' = jetzt angelegt (nur im Speicher). */
function sw_geraete_lesen()
{
    $d = sw_json_lesen(sw_geraete_datei());
    if (!is_array($d)) {
        $d = array();
    }
    if (!isset($d['start']) || !is_int($d['start'])) {
        $d['start'] = time();
    }
    if (!isset($d['geraete']) || !is_array($d['geraete'])) {
        $d['geraete'] = array();
    }
    return $d;
}

function sw_geraete_schreiben(array $d)
{
    if (!$d['geraete']) {
        $d['geraete'] = new stdClass();
    }
    return sw_json_schreiben(sw_geraete_datei(), $d);
}

/** Sperre fuer geraete.json und lauscher.json (Lauscher, Minutenlauf, Oberflaeche). Griff oder null. */
function sw_sperre($name)
{
    $p = sw_paths();
    if ($p['datadir'] === '' || preg_match('/^[a-z_]{1,20}\z/', $name) !== 1) {
        return null;
    }
    if (!is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
    }
    $s = @fopen($p['datadir'] . '/' . $name . '.lock', 'c');
    if ($s === false) {
        return null;
    }
    if (!flock($s, LOCK_EX)) {
        fclose($s);
        return null;
    }
    return $s;
}

function sw_freigeben($s)
{
    if (is_resource($s)) {
        flock($s, LOCK_UN);
        fclose($s);
    }
}

/* ==================================================================
 * HAUS-THEMEN haus/klima (Regeln/07 Nr. 15)
 * ==================================================================
 * Ab Werk aus (haus_klima_ein = 0). Je Geraet waehlt der Anwender Namen und
 * Haken; gesendet wird nur ein gespeichertes Geraet mit Haken und gueltigem,
 * eindeutigem Namen. Bauart der Ecowitt-Weiche 0.9.38 (ew_haus_*) mit den
 * Ergaenzungen des BLE-Scanner NG 1.3.45:
 *  - Felder temperatur (eine Nachkommastelle, ohne Aussage -999) und feuchte
 *    (ganz, ohne Aussage -1), nur die, die das Geraet geliefert hat; zeit = die
 *    Unixzeit des Empfangs der letzten Meldung mit einem Feld; anbieter =
 *    shellyweiche; alles retained, QoS 0, kurze Sitzung direkt am Broker.
 *  - Ein Feld, dessen letzte Messung ueber SW_STUMM zurueckliegt, geht ohne
 *    Aussage hinaus; zeit bleibt der echte Zeitpunkt. SW_STUMM geht als frist
 *    mit (wie anbieter: bei Aenderung und mit dem vollen Satz, kein Feld).
 *  - Startsperre: ein Name ohne jede Messung seit dem Anlegen des Zustands geht
 *    SW_STUMM s lang nicht hinaus - der zurueckbehaltene Wert traegt seine
 *    eigene zeit; danach ohne Aussage mit zeit -1.
 *  - Felder bei Aenderung, alle SW_VOLL s voll; zeit nur zusammen mit einem
 *    Feld desselben Namens, das nach der Konfliktpruefung noch hinausgeht.
 *  - Konfliktfilter: ein Name, dessen anbieter am Broker einen anderen Namen
 *    traegt, oder ein nicht gemerktes Thema mit zurueckbehaltenem Wert geht
 *    nicht hinaus; der Reiter Test nennt es.
 *  - Lebenszeichen haus/klima/zaehler/shellyweiche 0...999 je Minutenlauf,
 *    nie retained.
 *  - Abraeumen mit Ruecklesen ueber die Merkliste data/.../haus.json (Haken aus,
 *    Name geaendert, Geraet abgewaehlt, Deinstallation). Nie haus/#, nie ein
 *    fremdes Thema.
 * ================================================================== */

/** Namen, die nie ein <name> sind (Regeln/07 Nr. 15; wie KLIMA_RESERVIERT des BLE-Scanner NG). */
function sw_haus_reserviert()
{
    return array('zaehler', 'anbieter', 'jetzt', 'heute', 'morgen', 'warnung', 'pollen', 'ok', 'datum', 'datum_text', 'zeit', 'sprache');
}

/** Namensregel (Regeln/07 Nr. 15, wie ew_haus_name()): klein, Umlaute ausgeschrieben, jedes andere Zeichen ausser
 *  a-z, 0-9, _ und - wird _, hoechstens 40 Zeichen. */
function sw_haus_name($t)
{
    $t = str_replace(array("\u{00E4}", "\u{00F6}", "\u{00FC}", "\u{00DF}", "\u{00C4}", "\u{00D6}", "\u{00DC}"),
                     array('ae', 'oe', 'ue', 'ss', 'ae', 'oe', 'ue'), (string) $t);
    $t = preg_replace('/[^a-z0-9_-]+/', '_', strtolower($t));
    $t = trim((string) $t, '_');
    return rtrim(substr($t, 0, 40), '_');
}

/** Taugt eine Eingabe als <name>? Nur, wenn die Namensregel sie unveraendert laesst und sie nicht reserviert ist -
 *  abgewiesen wird, nicht zurechtgebogen (Regeln/05). */
function sw_haus_name_taugt($n)
{
    return is_string($n) && $n !== '' && sw_haus_name($n) === $n && !in_array($n, sw_haus_reserviert(), true);
}

/** Eine Zahl fuer ein Haus-Thema: Punkt, z1 eine Nachkommastelle (eine 0 dahinter faellt weg), z0 ganz, nie
 *  Exponent, "-0" -> "0". */
function sw_haus_zahl($x, $art)
{
    if ($art === 'z1') {
        $s = sprintf('%.1F', round((float) $x, 1));
        if (substr($s, -2) === '.0') {
            $s = substr($s, 0, -2);
        }
    } else {
        $s = sprintf('%.0F', round((float) $x));
    }
    return ($s === '-0') ? '0' : $s;
}

/**
 * Der Namensvorschlag fuer ein Praefix: der Raumteil hinter "shelly...-" (oder die letzte Ebene) nach der
 * Namensregel. Sieht er aus wie eine Geraetekennung (12 Hexziffern, die Werkseinstellung der Shelly-App), gibt es
 * keinen Vorschlag - die MAC soll nie in einem Thema stehen.
 */
function sw_name_vorschlag($praefix)
{
    $teil = (string) $praefix;
    $p = strrpos($teil, '/');
    if ($p !== false) {
        $teil = substr($teil, $p + 1);
    }
    if (preg_match('/^shelly[a-z0-9]*-(.+)\z/i', $teil, $m)) {
        $teil = $m[1];
    }
    $n = sw_haus_name($teil);
    if (preg_match('/^[0-9a-f]{12}\z/', $n) === 1 || !sw_haus_name_taugt($n)) {
        return '';
    }
    return $n;
}

/** Vorschlaege fuer neu erkannte Praefixe, eindeutig gegen $belegt: ohne Vorschlag shelly_ht_<n>, doppelt
 *  <name>_<n> (Regeln/07: gleiche Namen bekommen _<nummer>). Rueckgabe Praefix => Name. */
function sw_vorbelegung(array $praefixe, array $belegt)
{
    $aus = array();
    $frei = function ($n) use (&$belegt) {
        return !isset($belegt[$n]);
    };
    foreach ($praefixe as $pr) {
        $n = sw_name_vorschlag($pr);
        if ($n === '') {
            $k = 1;
            while (!$frei('shelly_ht_' . $k)) {
                $k++;
            }
            $n = 'shelly_ht_' . $k;
        } elseif (!$frei($n)) {
            $k = 2;
            while (!$frei(rtrim(substr($n, 0, 40 - strlen('_' . $k)), '_') . '_' . $k)) {
                $k++;
            }
            $n = rtrim(substr($n, 0, 40 - strlen('_' . $k)), '_') . '_' . $k;
        }
        $belegt[$n] = true;
        $aus[$pr] = $n;
    }
    return $aus;
}

/**
 * Welche Geraete gehen unter welchem Namen hinaus? Rueckgabe array(liste, beanstandet): liste = array von
 * array(praefix, name) - nur mit eingeschaltetem Haken haus_klima_ein, Haken am Geraet und gueltigem Namen;
 * beanstandet = Praefixe, deren Name nicht taugt oder schon vergeben ist (geschlossen: dieses Geraet sendet nicht).
 */
function sw_haus_zuordnung(array $c)
{
    $liste = array();
    $schlecht = array();
    $belegt = array();
    if (empty($c['haus_klima_ein']) || !isset($c['geraete']) || !is_array($c['geraete'])) {
        return array($liste, $schlecht);
    }
    foreach ($c['geraete'] as $e) {
        if (!is_array($e) || !isset($e['praefix'], $e['name'], $e['ein']) || sw_schalter_wert($e['ein']) !== 1) {
            continue;
        }
        if (!sw_praefix_taugt($e['praefix']) || !sw_haus_name_taugt($e['name']) || isset($belegt[$e['name']])) {
            $schlecht[] = is_string($e['praefix']) ? $e['praefix'] : '?';
            continue;
        }
        $belegt[$e['name']] = true;
        $liste[] = array('praefix' => $e['praefix'], 'name' => $e['name']);
    }
    return array($liste, $schlecht);
}

/** Ablage: haus.json (die gemerkten Themen, reist beim Update mit), haus_lauf.json (letzte Sitzung, zuletzt gesendete
 *  Werte; ein Update loescht sie, dann geht der volle Satz hinaus). */
function sw_haus_datei()
{
    $p = sw_paths();
    return $p['datadir'] !== '' ? $p['datadir'] . '/haus.json' : '';
}

function sw_haus_lauf_datei()
{
    $p = sw_paths();
    return $p['datadir'] !== '' ? $p['datadir'] . '/haus_lauf.json' : '';
}

/** Die gemerkten Themen: Thema => Praefix. Nur Themen haus/klima/<name>/<feld> ohne # und +. */
function sw_haus_themen_lesen()
{
    $d = sw_json_lesen(sw_haus_datei());
    $aus = array();
    if (is_array($d) && isset($d['themen']) && is_array($d['themen'])) {
        foreach ($d['themen'] as $t => $f) {
            if (is_string($t) && preg_match('#^haus/klima/[a-z0-9_-]{1,40}/[a-z0-9_]{1,40}\z#', $t) === 1
                && is_string($f)) {
                $aus[$t] = $f;
            }
        }
    }
    return $aus;
}

function sw_haus_themen_schreiben(array $t)
{
    ksort($t);
    return sw_json_schreiben(sw_haus_datei(), array('themen' => $t ? $t : new stdClass()));
}

function sw_haus_lauf_lesen()
{
    $d = sw_json_lesen(sw_haus_lauf_datei());
    return is_array($d) ? $d : array();
}

/**
 * Themen abraeumen (Bauart ew_haus_leeren()): erst zuruecklesen; ist nichts belegt -> 'nichts'. Sonst leere
 * Nutzlast mit Retain-Bit fuer die belegten Themen und danach in einer EIGENEN Sitzung zuruecklesen, bis zu drei
 * Runden. Nur die uebergebenen (gemerkten) Themen, nie haus/#. Rueckgabe array('lage' => geleert | nichts | rest |
 * kein_broker, 'offen' => noch belegte Themen, 'grund' => Lage der gescheiterten Sitzung).
 */
function sw_haus_leeren(array $th, $runden = 3)
{
    $th = array_values(array_unique(array_map('strval', $th)));
    if (!$th) {
        return array('lage' => 'nichts', 'offen' => array(), 'grund' => '');
    }
    $belegt = function ($w) {
        $o = array();
        foreach ($w as $t => $v) {
            if ((string) $v !== '') {
                $o[] = $t;
            }
        }
        return $o;
    };
    $lesen = function ($liste) {
        $alle = array('lage' => 'ok', 'werte' => array());
        foreach (array_chunk($liste, 40) as $teil) {
            $r = sw_haus_sitzung(array(), $teil);
            if ($r['lage'] !== 'ok') {
                return $r;
            }
            $alle['werte'] += $r['werte'];
        }
        return $alle;
    };
    $r = $lesen($th);
    if ($r['lage'] !== 'ok') {
        return array('lage' => 'kein_broker', 'offen' => array(), 'grund' => $r['lage']);
    }
    $offen = $belegt($r['werte']);
    if (!$offen) {
        return array('lage' => 'nichts', 'offen' => array(), 'grund' => '');
    }
    $lage = 'rest';
    $grund = '';
    for ($i = 1; $i <= max(1, (int) $runden); $i++) {
        $senden = array();
        foreach ($offen as $t) {
            $senden[$t] = array('', true);
        }
        $r = sw_haus_sitzung($senden, array());
        if ($r['lage'] !== 'ok') {
            $lage = 'kein_broker';
            $grund = $r['lage'];
            continue;
        }
        usleep(300000);
        $r = $lesen($th);
        if ($r['lage'] !== 'ok') {
            $lage = 'kein_broker';
            $grund = $r['lage'];
            continue;
        }
        $offen = $belegt($r['werte']);
        if (!$offen) {
            return array('lage' => 'geleert', 'offen' => array(), 'grund' => '');
        }
        $lage = 'rest';
    }
    return array('lage' => $lage, 'offen' => $offen, 'grund' => $grund);
}

/** Gemerkte Themen, deren Name nicht mehr gesendet wird (Haken aus, Geraet abgewaehlt, Name geaendert). */
function sw_haus_verwaist(array $themen, array $liste)
{
    $an = array();
    foreach ($liste as $z) {
        $an['haus/klima/' . $z['name'] . '/'] = true;
    }
    $weg = array();
    foreach (array_keys($themen) as $t) {
        $teile = explode('/', $t);
        if (count($teile) !== 4 || !isset($an[$teile[0] . '/' . $teile[1] . '/' . $teile[2] . '/'])) {
            $weg[] = $t;
        }
    }
    return $weg;
}

/** Verwaiste Themen abraeumen, wenn $erzwingen oder der letzte Versuch SW_LEEREN_ABSTAND s zurueckliegt. Aendert
 *  $themen und $lauf; schreibt haus.json nur bei Erfolg. Rueckgabe das Ergebnis oder null (nichts zu tun). */
function sw_haus_abraeumen(array &$themen, array $liste, array &$lauf, $jetzt, $erzwingen)
{
    $weg = sw_haus_verwaist($themen, $liste);
    if (!$weg) {
        unset($lauf['leeren']);
        return null;
    }
    $vt = isset($lauf['leeren_ts']) ? (int) $lauf['leeren_ts'] : 0;
    if (!$erzwingen && $vt > 0 && $vt <= $jetzt && $jetzt - $vt < SW_LEEREN_ABSTAND) {
        return null;
    }
    $e = sw_haus_leeren($weg);
    $lauf['leeren_ts'] = $jetzt;
    $lauf['leeren'] = array('zeit' => $jetzt, 'lage' => $e['lage'], 'offen' => $e['offen'], 'grund' => $e['grund'],
                            'themen' => count($weg));
    if ($e['lage'] === 'geleert' || $e['lage'] === 'nichts') {
        foreach ($weg as $t) {
            unset($themen[$t]);
            if (isset($lauf['werte'][$t])) {
                unset($lauf['werte'][$t]);
            }
        }
        sw_haus_themen_schreiben($themen);
        unset($lauf['leeren']);
    }
    sw_log('Haus-Themen: Abraeumen von ' . count($weg) . ' Thema/Themen - ' . $e['lage']
        . ($e['offen'] ? ' (noch belegt: ' . implode(', ', $e['offen']) . ')' : '') . '.');
    return $e;
}

/**
 * Die Werte eines Laufs aus dem Geraetezustand. Rueckgabe array(werte: Thema => Wert, halten: Basis => true (geht
 * noch nicht hinaus, Startsperre), namen: Basis => Praefix, felder: Basis => frische Felder). Ein Feld, das das
 * Geraet nie geliefert hat und das nicht gemerkt ist, geht nicht hinaus; ein unplausibles oder ueber SW_STUMM altes
 * Feld geht ohne Aussage. Kennt der Zustand keine Messung des Geraets: waehrend der ersten SW_STUMM s nach 'start'
 * gehalten, danach alle Felder ohne Aussage mit zeit -1.
 */
function sw_haus_werte(array $liste, array $stand, $jetzt, array $themen)
{
    $start = isset($stand['start']) ? (int) $stand['start'] : (int) $jetzt;
    $werte = array();
    $halten = array();
    $namen = array();
    $frisch = array();
    foreach ($liste as $z) {
        $b = 'haus/klima/' . $z['name'] . '/';
        $namen[$b] = $z['praefix'];
        $g = (isset($stand['geraete'][$z['praefix']]) && is_array($stand['geraete'][$z['praefix']]))
            ? $stand['geraete'][$z['praefix']] : array();
        $gw = (isset($g['werte']) && is_array($g['werte'])) ? $g['werte'] : array();
        $gz = (isset($g['werte_zeit']) && is_array($g['werte_zeit'])) ? $g['werte_zeit'] : array();
        $letzte = null;
        $w = array();
        $frisch[$b] = array();
        foreach (sw_haus_felder() as $f) {
            if (array_key_exists($f[0], $gw) && isset($gz[$f[0]])) {
                $t = (int) $gz[$f[0]];
                $letzte = ($letzte === null) ? $t : max($letzte, $t);
                $x = sw_zahl($gw[$f[0]]);
                if ($x !== null && $t > 0 && $jetzt - $t <= SW_STUMM && $jetzt - $t >= -300) {
                    $w[$b . $f[0]] = sw_haus_zahl($x, $f[3]);
                    $frisch[$b][] = $f[0];
                } else {
                    $w[$b . $f[0]] = (string) $f[4];
                }
            } elseif (isset($themen[$b . $f[0]])) {
                $w[$b . $f[0]] = (string) $f[4];
            }
        }
        if ($letzte === null) {
            if ($jetzt >= $start && $jetzt - $start < SW_STUMM) {
                $halten[$b] = true;
            } else {
                foreach (sw_haus_felder() as $f) {
                    $w[$b . $f[0]] = (string) $f[4];
                }
            }
        }
        if ($w) {
            $w[$b . 'zeit'] = ($letzte !== null) ? (string) $letzte : '-1';
            $w[$b . 'anbieter'] = SW_ANBIETER;
            $w[$b . 'frist'] = (string) SW_STUMM;
        }
        $werte += $w;
    }
    return array($werte, $halten, $namen, $frisch);
}

/** Was geht hinaus? Felder bei Aenderung (oder alle bei $voll), zeit nur zusammen mit einem Feld desselben Namens,
 *  anbieter bei Aenderung und mit dem vollen Satz; nichts von einem gehaltenen Namen. Thema => array(Wert, true). */
function sw_haus_auswahl(array $werte, array $merk, $voll, array $halten)
{
    $sel = array();
    $mit = array();
    foreach ($werte as $t => $v) {
        $basis = substr($t, 0, strrpos($t, '/') + 1);
        $feld = substr($t, strlen($basis));
        if ($feld === 'zeit' || $feld === 'anbieter' || $feld === 'frist' || isset($halten[$basis])) {
            continue;
        }
        if ($voll || !array_key_exists($t, $merk) || (string) $merk[$t] !== (string) $v) {
            $sel[$t] = array((string) $v, true);
            $mit[$basis] = true;
        }
    }
    foreach ($werte as $t => $v) {
        $basis = substr($t, 0, strrpos($t, '/') + 1);
        $feld = substr($t, strlen($basis));
        if (isset($halten[$basis])) {
            continue;
        }
        if ($feld === 'zeit' && isset($mit[$basis])) {
            $sel[$t] = array((string) $v, true);
        } elseif (($feld === 'anbieter' || $feld === 'frist')
                  && ($voll || !array_key_exists($t, $merk) || (string) $merk[$t] !== (string) $v)) {
            $sel[$t] = array((string) $v, true);
        }
    }
    return $sel;
}

/**
 * Pruefung auf fremde Werte (Regeln/07 Nr. 15: ein Anbieter ueberschreibt nie einen retained Wert, den er nicht
 * selbst gesendet hat). Traegt <name>/anbieter einen fremden Namen und ist das Thema nicht gemerkt, geht von diesem
 * Namen nichts hinaus; traegt es den eigenen Namen, gelten seine Themen als eigene; sonst bleibt jedes nicht
 * gemerkte Thema mit einem zurueckbehaltenen Wert stehen. Danach geht zeit nur noch mit, wenn von seinem Namen noch
 * ein Feld hinausgeht (BLE-Scanner NG 1.3.45, Fall P8b). Rueckgabe array(sel, konflikte).
 */
function sw_haus_fremd(array $sel, array $themen, array $gelesen)
{
    $konflikte = array();
    $basen = array();
    foreach (array_keys($sel) as $t) {
        if (strpos($t, 'haus/') === 0 && strpos($t, '/zaehler/') === false && !isset($themen[$t])) {
            $basen[substr($t, 0, strrpos($t, '/') + 1)] = true;
        }
    }
    foreach (array_keys($basen) as $b) {
        $anb = $b . 'anbieter';
        $fremd = (!isset($themen[$anb]) && isset($gelesen[$anb]) && $gelesen[$anb] !== '') ? $gelesen[$anb] : '';
        if ($fremd !== '' && $fremd !== SW_ANBIETER) {
            foreach (array_keys($sel) as $t) {
                if (strpos($t, $b) === 0) {
                    unset($sel[$t]);
                }
            }
            $konflikte[] = $anb . ' = ' . sw_kurz($fremd, 40);
            continue;
        }
        if ($fremd === SW_ANBIETER) {
            continue;
        }
        foreach (array_keys($sel) as $t) {
            if (strpos($t, $b) === 0 && !isset($themen[$t]) && isset($gelesen[$t]) && $gelesen[$t] !== '') {
                unset($sel[$t]);
                $konflikte[] = $t;
            }
        }
    }
    $mit_feld = array();
    foreach (array_keys($sel) as $t) {
        $basis = substr($t, 0, strrpos($t, '/') + 1);
        $feld = substr($t, strlen($basis));
        if ($feld !== 'zeit' && $feld !== 'anbieter' && $feld !== 'frist') {
            $mit_feld[$basis] = true;
        }
    }
    foreach (array_keys($sel) as $t) {
        $basis = substr($t, 0, strrpos($t, '/') + 1);
        if (substr($t, strlen($basis)) === 'zeit' && !isset($mit_feld[$basis])) {
            unset($sel[$t]);
        }
    }
    return array($sel, $konflikte);
}

/** Merker "beim naechsten Lauf alles senden" (Speichern, Zurueckspielen, Aktion voll). */
function sw_haus_voll_merken()
{
    $sp = sw_sperre('haus');
    if ($sp === null) {
        return false;
    }
    $lauf = sw_haus_lauf_lesen();
    unset($lauf['werte'], $lauf['voll_ts']);
    $ok = sw_json_schreiben(sw_haus_lauf_datei(), $lauf);
    sw_freigeben($sp);
    return $ok;
}

/**
 * Ein Lauf des Anbieters (aus sw_minutenlauf()). Ohne aktive Zuordnung raeumt er nur verwaiste Themen ab.
 * Rueckgabe: der neue Stand von haus_lauf.json oder null.
 */
function sw_haus_lauf(array $c, array $stand, $zaehler, $jetzt)
{
    $p = sw_paths();
    if ($p['datadir'] === '' || $p['lbhome'] === '') {
        return null;
    }
    $sp = sw_sperre('haus');
    if ($sp === null) {
        return null;
    }
    list($liste, $schlecht) = sw_haus_zuordnung($c);
    $themen = sw_haus_themen_lesen();
    $lauf = sw_haus_lauf_lesen();
    $merk = (isset($lauf['werte']) && is_array($lauf['werte'])) ? $lauf['werte'] : array();
    $lauf['werte'] = $merk;
    sw_haus_abraeumen($themen, $liste, $lauf, $jetzt, false);
    $lauf['beanstandet'] = $schlecht;
    if (!$liste) {
        $lauf['aktiv'] = array();
        $lauf['gehalten'] = array();
        unset($lauf['werte'], $lauf['voll_ts']);
        sw_json_schreiben(sw_haus_lauf_datei(), $lauf);
        sw_freigeben($sp);
        return $lauf;
    }
    list($werte, $halten, $namen, $frisch) = sw_haus_werte($liste, $stand, $jetzt, $themen);
    $voll_ts = isset($lauf['voll_ts']) ? (int) $lauf['voll_ts'] : 0;
    $voll = !isset($lauf['voll_ts']) || $voll_ts <= 0 || $voll_ts > $jetzt || $jetzt - $voll_ts >= SW_VOLL;
    $sel = sw_haus_auswahl($werte, $lauf['werte'], $voll, $halten);
    $zt = array('haus/klima/zaehler/' . SW_ANBIETER => array((string) ((((int) $zaehler) % 1000 + 1000) % 1000), false));
    $lese = array();
    foreach (array_keys($sel) as $t) {
        if (!isset($themen[$t])) {
            $b = substr($t, 0, strrpos($t, '/') + 1);
            $lese[$t] = true;
            $lese[$b . 'anbieter'] = true;
        }
    }
    $konflikte = array();
    $endgueltig = $sel;
    $entscheiden = function ($gelesen) use ($sel, $themen, $zt, &$konflikte, &$endgueltig) {
        list($endgueltig, $konflikte) = sw_haus_fremd($sel, $themen, $gelesen);
        return $endgueltig + $zt;
    };
    $r = sw_haus_sitzung(array(), array_keys($lese), $entscheiden);
    $lauf['sitzung'] = array('zeit' => $jetzt, 'lage' => $r['lage'], 'gesendet' => (int) $r['gesendet'],
                             'voll' => $voll ? 1 : 0, 'werte' => count($endgueltig), 'zaehler' => (int) $zaehler);
    if ($r['lage'] === 'ok') {
        $neu = false;
        foreach ($r['themen'] as $t) {
            if (isset($endgueltig[$t])) {
                $lauf['werte'][$t] = $endgueltig[$t][0];
                $b = substr($t, 0, strrpos($t, '/') + 1);
                if (!isset($themen[$t]) || $themen[$t] !== $namen[$b]) {
                    $themen[$t] = $namen[$b];
                    $neu = true;
                }
            }
        }
        if ($neu) {
            sw_haus_themen_schreiben($themen);
        }
        if ($voll) {
            $lauf['voll_ts'] = $jetzt;
        }
        $lauf['konflikte'] = $konflikte;
    }
    $aktiv = array();
    foreach ($liste as $z) {
        $aktiv[] = 'haus/klima/' . $z['name'];
    }
    $lauf['aktiv'] = $aktiv;
    $lauf['gehalten'] = array_keys($halten);
    $lauf['frisch'] = $frisch;
    $alt = isset($lauf['log_lage']) ? (string) $lauf['log_lage'] : '';
    if ($alt !== $r['lage']) {
        sw_log($r['lage'] === 'ok' ? 'Haus-Themen: Sitzung am Broker ok (' . implode(', ', $aktiv) . ').'
            : 'Haus-Themen: Broker nicht erreichbar (' . $r['lage'] . ') - naechster Versuch in einer Minute.');
        $lauf['log_lage'] = $r['lage'];
    }
    $kalt = isset($lauf['log_konflikte']) ? (string) $lauf['log_konflikte'] : '';
    if ($r['lage'] === 'ok' && implode(', ', $konflikte) !== $kalt) {
        if ($konflikte) {
            sw_log('Haus-Themen: nicht gesendet, ein anderer Anbieter haelt den Wert: ' . implode(', ', $konflikte) . '.');
        }
        $lauf['log_konflikte'] = implode(', ', $konflikte);
    }
    sw_json_schreiben(sw_haus_lauf_datei(), $lauf);
    sw_freigeben($sp);
    return $lauf;
}

/** haus/klima aus: gemerkte Themen trotzdem abraeumen, hoechstens alle SW_LEEREN_ABSTAND s. */
function sw_haus_nachraeumen(array $c, $jetzt)
{
    $f = sw_haus_datei();
    if ($f === '' || !is_file($f)) {
        return null;
    }
    $sp = sw_sperre('haus');
    if ($sp === null) {
        return null;
    }
    $themen = sw_haus_themen_lesen();
    $lauf = sw_haus_lauf_lesen();
    list($liste) = sw_haus_zuordnung($c);
    $e = $themen ? sw_haus_abraeumen($themen, $liste, $lauf, $jetzt, false) : null;
    if ($e !== null) {
        sw_json_schreiben(sw_haus_lauf_datei(), $lauf);
    }
    sw_freigeben($sp);
    return $e;
}

/**
 * Folgen einer Aenderung (Speichern im Reiter MQTT, Zurueckspielen): der naechste Minutenlauf schickt den vollen
 * Satz; was nicht mehr gesendet wird, wird sofort mit Ruecklesen abgeraeumt. Rueckgabe array(Meldung oder '',
 * ok|warn).
 */
function sw_haus_folgen(array $neu)
{
    $sp = sw_sperre('haus');
    if ($sp === null) {
        return array('', 'ok');
    }
    $themen = sw_haus_themen_lesen();
    $lauf = sw_haus_lauf_lesen();
    unset($lauf['werte'], $lauf['voll_ts']);
    list($liste) = sw_haus_zuordnung($neu);
    $n = count(sw_haus_verwaist($themen, $liste));
    $e = $themen ? sw_haus_abraeumen($themen, $liste, $lauf, time(), true) : null;
    sw_json_schreiben(sw_haus_lauf_datei(), $lauf);
    sw_freigeben($sp);
    if ($e === null) {
        return array('', 'ok');
    }
    if ($e['lage'] === 'geleert') {
        return array(sprintf(sw_t('HAUS.M_GELEERT'), $n), 'ok');
    }
    if ($e['lage'] === 'nichts') {
        return array(sw_t('HAUS.M_NICHTS'), 'ok');
    }
    if ($e['lage'] === 'rest') {
        return array(sprintf(sw_t('HAUS.M_REST'), implode(', ', $e['offen'])), 'warn');
    }
    return array(sprintf(sw_t('HAUS.M_KEIN_BROKER'), $e['grund']), 'warn');
}

/** Deinstallation (bin/sw_takt.php --haus-leeren): alle gemerkten Themen mit Ruecklesen abraeumen. Ausgabe fuer das
 *  Installationsprotokoll; Rueckgabe immer 0 - die Deinstallation laeuft weiter. */
function sw_haus_leeren_ausgabe()
{
    $sp = sw_sperre('haus');
    $themen = sw_haus_themen_lesen();
    if (!$themen) {
        echo "<INFO> haus/klima: diese Installation war nie Anbieter - nichts zu leeren.\n";
        sw_freigeben($sp);
        return 0;
    }
    $e = sw_haus_leeren(array_keys($themen));
    if ($e['lage'] === 'geleert' || $e['lage'] === 'nichts') {
        sw_haus_themen_schreiben(array());
    }
    sw_freigeben($sp);
    if ($e['lage'] === 'geleert') {
        echo '<OK> haus/klima: geleert (vom Broker bestaetigt, ' . count($themen) . " Themen).\n";
    } elseif ($e['lage'] === 'nichts') {
        echo '<INFO> haus/klima: nichts zu leeren (der Broker haelt keines der ' . count($themen) . " Themen zurueck).\n";
    } elseif ($e['lage'] === 'rest') {
        echo '<WARNING> haus/klima: noch zurueckbehalten - bitte von Hand leeren: '
            . implode(', ', $e['offen']) . " (mosquitto_pub -r -n -t <thema>)\n";
    } else {
        echo '<WARNING> haus/klima: der Broker war nicht erreichbar (' . $e['grund']
            . ') - bitte von Hand leeren: ' . implode(', ', array_keys($themen)) . " (mosquitto_pub -r -n -t <thema>)\n";
    }
    return 0;
}

/** Die Themenliste fuer den Reiter MQTT und den Reiter Einbindung: je aktivem Namen die Felder, zeit, anbieter, frist
 *  und der Zaehler. Rueckgabe array von array(Thema, Sprachschluessel der Bedeutung, retained). */
function sw_haus_themenliste(array $c)
{
    list($liste) = sw_haus_zuordnung($c);
    $aus = array();
    foreach ($liste as $z) {
        $b = 'haus/klima/' . $z['name'] . '/';
        foreach (sw_haus_felder() as $f) {
            $aus[] = array($b . $f[0], 'HAUS.F_' . strtoupper($f[0]), true);
        }
        $aus[] = array($b . 'zeit', 'HAUS.F_ZEIT', true);
        $aus[] = array($b . 'anbieter', 'HAUS.F_ANBIETER', true);
        $aus[] = array($b . 'frist', 'HAUS.F_FRIST', true);
    }
    if ($liste) {
        $aus[] = array('haus/klima/zaehler/' . SW_ANBIETER, 'HAUS.F_ZAEHLER', false);
    }
    return $aus;
}

/**
 * Prueflinie "haus/klima" des Reiters Test. Mit $messen (nur bei geoeffnetem Reiter Test) liest sie am Broker zurueck,
 * ob je aktivem Namen haus/klima/<name>/anbieter = shellyweiche und zeit = dem zuletzt gesendeten Wert zurueckbehalten
 * sind - die Wirkung, nicht der Rueckgabewert. Rueckgabe array(ok|fehl|hinweis, Text).
 */
function sw_pruef_haus(array $c, $messen)
{
    list($liste, $schlecht) = sw_haus_zuordnung($c);
    $themen = sw_haus_themen_lesen();
    $lauf = sw_haus_lauf_lesen();
    $offen = sw_haus_verwaist($themen, $liste);
    if ($offen) {
        $l = isset($lauf['leeren']['lage']) ? (string) $lauf['leeren']['lage'] : '-';
        return array('fehl', sprintf(sw_t('HAUS.T_OFFEN'), count($offen), implode(', ', array_slice($offen, 0, 6))
            . (count($offen) > 6 ? ' ...' : ''), $l));
    }
    if (empty($c['haus_klima_ein'])) {
        return array('hinweis', sw_t('HAUS.T_AUS'));
    }
    if ($schlecht) {
        return array('fehl', sprintf(sw_t('HAUS.T_NAME_SCHLECHT'), count($schlecht)));
    }
    if (!$liste) {
        return array('fehl', sw_t('HAUS.T_KEIN_GERAET'));
    }
    $sz = (isset($lauf['sitzung']) && is_array($lauf['sitzung'])) ? $lauf['sitzung'] : array();
    if (!isset($sz['zeit'], $sz['lage'])) {
        return array('fehl', sw_t('HAUS.T_NIE'));
    }
    $alter = time() - (int) $sz['zeit'];
    if ((string) $sz['lage'] !== 'ok') {
        return array('fehl', sprintf(sw_t('HAUS.T_BROKER'), (string) $sz['lage'], sw_alter_text($alter)));
    }
    if ($alter < 0 || $alter > SW_TAKT_ALT) {
        return array('fehl', sprintf(sw_t('HAUS.T_ALT'), sw_alter_text($alter)));
    }
    if (!empty($lauf['konflikte']) && is_array($lauf['konflikte'])) {
        return array('fehl', sprintf(sw_t('HAUS.T_KONFLIKT'), implode(', ', $lauf['konflikte'])));
    }
    $gehalten = (isset($lauf['gehalten']) && is_array($lauf['gehalten'])) ? $lauf['gehalten'] : array();
    $namen = array();
    foreach ($liste as $z) {
        if (!in_array('haus/klima/' . $z['name'] . '/', $gehalten, true)) {
            $namen[] = 'haus/klima/' . $z['name'];
        }
    }
    if (!$namen) {
        return array('hinweis', sprintf(sw_t('HAUS.T_GEHALTEN'), count($gehalten)));
    }
    if (!$messen) {
        return array('hinweis', sprintf(sw_t('HAUS.T_NUR_OFFEN'), implode(', ', $namen)));
    }
    $lese = array();
    foreach ($namen as $b) {
        $lese[] = $b . '/anbieter';
        $lese[] = $b . '/zeit';
    }
    $r = sw_haus_sitzung(array(), $lese);
    if ($r['lage'] !== 'ok') {
        return array('fehl', sprintf(sw_t('HAUS.T_LESEN'), $r['lage']));
    }
    $merk = (isset($lauf['werte']) && is_array($lauf['werte'])) ? $lauf['werte'] : array();
    $fehlt = array();
    $gut = array();
    foreach ($namen as $b) {
        $a = isset($r['werte'][$b . '/anbieter']) ? $r['werte'][$b . '/anbieter'] : '';
        $zt = isset($r['werte'][$b . '/zeit']) ? $r['werte'][$b . '/zeit'] : '';
        if ($a !== SW_ANBIETER || $zt === '' || (isset($merk[$b . '/zeit']) && (string) $merk[$b . '/zeit'] !== $zt)) {
            $fehlt[] = $b . ' (anbieter "' . sw_kurz($a, 30) . '", zeit "' . sw_kurz($zt, 12) . '")';
        } else {
            $gut[] = $b . ' (zeit ' . (preg_match('/^[0-9]{9,11}\z/', $zt) ? date('d.m.Y H:i:s', (int) $zt) : $zt) . ')';
        }
    }
    if ($fehlt) {
        return array('fehl', sprintf(sw_t('HAUS.T_WIRKUNG_FEHLT'), implode(', ', $fehlt)));
    }
    return array('ok', sprintf(sw_t('HAUS.T_OK'), implode(', ', $gut), sw_alter_text($alter)));
}

/* ==================================================================
 * LAUSCHER UND MINUTENLAUF (bin/sw_takt.php)
 * ================================================================== */

/** Die Abos des Lauschers: die beiden festen Filter, das weitere Abo und je bekanntem Geraet <praefix>/online. */
function sw_abos(array $c, array $stand)
{
    $a = array('+/events/rpc', '+/+/events/rpc');
    if (isset($c['abo_zusatz']) && is_string($c['abo_zusatz']) && $c['abo_zusatz'] !== ''
        && sw_abo_taugt($c['abo_zusatz']) && !in_array($c['abo_zusatz'], $a, true)) {
        $a[] = $c['abo_zusatz'];
    }
    foreach (array_keys($stand['geraete']) as $pr) {
        if (sw_praefix_taugt((string) $pr) && count($a) < 2 + 1 + SW_GERAETE_MAX) {
            $a[] = $pr . '/online';
        }
    }
    return $a;
}

function sw_lauscher_datei()
{
    $p = sw_paths();
    return $p['datadir'] !== '' ? $p['datadir'] . '/lauscher.json' : '';
}

/** Die letzte Sitzung je Platz (a, b). */
function sw_lauscher_lesen()
{
    $d = sw_json_lesen(sw_lauscher_datei());
    return is_array($d) ? $d : array();
}

function sw_lauscher_merken($platz, array $z)
{
    $sp = sw_sperre('geraete');
    $d = sw_lauscher_lesen();
    $d[$platz] = $z;
    sw_json_schreiben(sw_lauscher_datei(), $d);
    sw_freigeben($sp);
}

/**
 * Ein Lauscher: bis $bis (Unixzeit) am Broker abonniert, jede Fuehlermeldung sofort unter der Sperre in
 * geraete.json eintragen. Liest nur; sendet nichts ausser PINGREQ und DISCONNECT. Rueckgabe die Zahlen der Sitzung.
 */
function sw_lauschen(array $c, $bis, $platz)
{
    $z = array('start' => time(), 'ende' => 0, 'lage' => 'laeuft', 'abos' => 0, 'meldungen' => 0, 'fuehler' => 0,
               'andere' => 0, 'doppelt' => 0, 'verworfen' => 0, 'online' => 0);
    $sp = sw_sperre('geraete');
    $stand = sw_geraete_lesen();
    sw_freigeben($sp);
    $abos = sw_abos($c, $stand);
    $z['abos'] = count($abos);
    sw_lauscher_merken($platz, $z);
    list($lage, $s) = sw_mq_verbinden('swlausch' . $platz . getmypid(), 60);
    if ($lage !== 'ok') {
        $z['lage'] = $lage;
        $z['ende'] = time();
        sw_lauscher_merken($platz, $z);
        return $z;
    }
    $bekannt = array_flip(array_keys($stand['geraete']));
    $verarbeiten = function ($pk) use (&$z, &$bekannt) {
        $pub = sw_mq_publish_lesen($pk);
        if ($pub === null) {
            return;
        }
        $empfang = time();
        if (substr($pub[0], -7) === '/online') {
            $pr = substr($pub[0], 0, -7);
            if (isset($bekannt[$pr]) && in_array($pub[1], array('true', 'false'), true)) {
                $sp = sw_sperre('geraete');
                $st = sw_geraete_lesen();
                if (isset($st['geraete'][$pr])) {
                    $st['geraete'][$pr]['online'] = $pub[1];
                    $st['geraete'][$pr]['online_zeit'] = $empfang;
                    sw_geraete_schreiben($st);
                    $z['online']++;
                }
                sw_freigeben($sp);
            }
            return;
        }
        $z['meldungen']++;
        $m = sw_meldung_auswerten($pub[0], $pub[1]);
        if ($m === null) {
            $z['andere']++;
            return;
        }
        $sp = sw_sperre('geraete');
        $st = sw_geraete_lesen();
        $erg = sw_meldung_eintragen($st, $m, $empfang);
        if ($erg === 'neu') {
            sw_geraete_schreiben($st);
            $z['fuehler']++;
            $bekannt[$m['praefix']] = true;
        } elseif ($erg === 'doppelt') {
            $z['doppelt']++;
        } else {
            $z['verworfen']++;
        }
        sw_freigeben($sp);
    };
    $lage = sw_mq_abonnieren($s, $abos, $verarbeiten);
    if ($lage === 'ok') {
        $ping = microtime(true) + SW_LAUSCH_PING;
        $offene_pings = 0;
        while (microtime(true) < $bis) {
            $warte = min(5.0, max(0.0, $bis - microtime(true)), max(0.0, $ping - microtime(true)));
            $r = array($s);
            $w = null;
            $e = null;
            $sek = (int) floor($warte);
            $usek = (int) (($warte - $sek) * 1000000);
            $bereit = @stream_select($r, $w, $e, $sek, $usek);
            if ($bereit === false) {
                $lage = 'getrennt';
                break;
            }
            if ($bereit > 0) {
                $pk = sw_mq_paket($s, microtime(true) + 10.0);
                if ($pk === null) {
                    $lage = 'getrennt';
                    break;
                }
                $art = $pk[0] >> 4;
                if ($art === 3) {
                    $verarbeiten($pk);
                } elseif ($art === 13) {
                    $offene_pings = 0;
                }
            }
            if (microtime(true) >= $ping) {
                if ($offene_pings >= 2 || !sw_mq_schreiben($s, chr(0xC0) . chr(0))) {
                    $lage = 'getrennt';
                    break;
                }
                $offene_pings++;
                $ping = microtime(true) + SW_LAUSCH_PING;
            }
        }
        if ($lage === 'ok') {
            $lage = 'beendet';
        }
    }
    sw_mq_trennen($s);
    $z['lage'] = $lage;
    $z['ende'] = time();
    sw_lauscher_merken($platz, $z);
    return $z;
}

/** Wann der Lauscher dieses Laufs endet: naechste volle Minute plus SW_LAUSCH_NACHLAUF, hoechstens SW_LAUSCH_MAX. */
function sw_lausch_ende($jetzt)
{
    $bis = (floor($jetzt / 60) + 1) * 60 + SW_LAUSCH_NACHLAUF;
    return min($bis, $jetzt + SW_LAUSCH_MAX);
}

/**
 * Den Lauscher dieses Laufs starten (bin/sw_takt.php --lauschen, aus cron.01min im Hintergrund). Upgrade-Marke,
 * Konfiguration (nur lesen), dann einer der beiden Plaetze (Sperre lausch_a / lausch_b, nicht blockierend).
 * Rueckgabe array('ende' => upgrade | ohne_wurzel | nicht_eingerichtet | besetzt | gelaufen, ...).
 */
function sw_lauscher_lauf()
{
    $p = sw_paths();
    if ($p['datadir'] === '' || $p['lbhome'] === '') {
        return array('ende' => 'ohne_wurzel');
    }
    if (sw_marke_frisch(sw_marke_datei())) {
        return array('ende' => 'upgrade');
    }
    $c = sw_config(false);
    if ($c === null) {
        return array('ende' => 'nicht_eingerichtet');
    }
    if (!is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
    }
    foreach (array('a', 'b') as $platz) {
        $h = @fopen($p['datadir'] . '/lausch_' . $platz . '.lock', 'c');
        if ($h === false) {
            continue;
        }
        if (flock($h, LOCK_EX | LOCK_NB)) {
            $jetzt = microtime(true);
            $z = sw_lauschen($c, sw_lausch_ende($jetzt), $platz);
            flock($h, LOCK_UN);
            fclose($h);
            return array('ende' => 'gelaufen', 'platz' => $platz) + $z;
        }
        fclose($h);
    }
    return array('ende' => 'besetzt');
}

function sw_takt_datei()
{
    $p = sw_paths();
    return $p['datadir'] !== '' ? $p['datadir'] . '/takt.json' : '';
}

function sw_takt_lesen()
{
    $d = sw_json_lesen(sw_takt_datei());
    return is_array($d) ? $d : array();
}

/**
 * EIN Minutenlauf (bin/sw_takt.php ohne Schalter). Upgrade-Marke, Konfiguration nur lesen, Sperre takt.lock nicht
 * blockierend, Schranke SW_TAKT_SCHRANKE, dann haus/klima (oder Nachraeumen bei ausgeschaltetem Haken) und der
 * Zaehler. Rueckgabe array('ende' => ...).
 */
function sw_minutenlauf()
{
    $p = sw_paths();
    if ($p['datadir'] === '' || $p['lbhome'] === '') {
        return array('ende' => 'ohne_wurzel');
    }
    if (sw_marke_frisch(sw_marke_datei())) {
        return array('ende' => 'upgrade');
    }
    $c = sw_config(false);
    if ($c === null) {
        return array('ende' => 'nicht_eingerichtet');
    }
    if (!is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
    }
    $sperre = @fopen($p['datadir'] . '/takt.lock', 'c');
    if ($sperre === false) {
        return array('ende' => 'sperre_fehlt');
    }
    if (!flock($sperre, LOCK_EX | LOCK_NB)) {
        fclose($sperre);
        return array('ende' => 'besetzt');
    }
    $alt = sw_takt_lesen();
    $jetzt = time();
    $vorher = isset($alt['ts']) ? (int) $alt['ts'] : 0;
    if ($vorher > 0 && $jetzt - $vorher >= 0 && $jetzt - $vorher < SW_TAKT_SCHRANKE) {
        flock($sperre, LOCK_UN);
        fclose($sperre);
        return array('ende' => 'schranke');
    }
    $zaehler = isset($alt['zaehler']) ? ((int) $alt['zaehler'] + 1) % 1000 : 0;
    $sp = sw_sperre('geraete');
    $stand = sw_geraete_lesen();
    if (!is_file(sw_geraete_datei())) {
        sw_geraete_schreiben($stand);           // legt 'start' fest (Startsperre)
    }
    sw_freigeben($sp);
    $lauf = null;
    if (!empty($c['haus_klima_ein'])) {
        $lauf = sw_haus_lauf($c, $stand, $zaehler, $jetzt);
    } else {
        sw_haus_nachraeumen($c, $jetzt);
    }
    $takt = array('ts' => $jetzt, 'zaehler' => $zaehler, 'haus' => !empty($c['haus_klima_ein']) ? 1 : 0,
                  'lage' => (is_array($lauf) && isset($lauf['sitzung']['lage'])) ? (string) $lauf['sitzung']['lage'] : '-',
                  'geraete' => count($stand['geraete']));
    sw_json_schreiben(sw_takt_datei(), $takt);
    flock($sperre, LOCK_UN);
    fclose($sperre);
    return array('ende' => 'gelaufen') + $takt;
}

/* ==================================================================
 * PRUEFZEILEN DES REITERS TEST
 * ==================================================================
 * Jede Funktion liefert array(lage, text); lage ist 'ok', 'fehl' oder
 * 'hinweis' ("nicht feststellbar" - nie als bestanden gezaehlt).
 * ================================================================== */

/** Ist die Konfiguration heil? Aus dem Zustand, den sw_config() VOR der Heilung vorfand. */
function sw_pruef_konfiguration($z)
{
    $p = sw_paths();
    $k = $p['cfgdatei'] !== '' ? $p['cfgdatei'] . '.kaputt' : '';
    $kd = ($k !== '' && is_file($k)) ? ' ' . sprintf(sw_t('TEST.KONF_KAPUTTDATEI'), basename($k)) : '';
    switch ($z) {
    case 'ok':
        return array($kd === '' ? 'ok' : 'hinweis', sw_t('TEST.KONF_OK') . $kd);
    case 'fehlt':
        return array('hinweis', sw_t('TEST.KONF_FEHLT') . $kd);
    case 'leer':
        return array('hinweis', sw_t('TEST.KONF_LEER') . $kd);
    case 'ohne_token':
        return array('hinweis', sw_t('TEST.KONF_OHNE_TOKEN') . $kd);
    case 'zweitschrift':
        return array('fehl', sw_t('TEST.KONF_ZWEITSCHRIFT') . $kd);
    case 'kaputt_zweitschrift':
        return array('fehl', sw_t('TEST.KONF_KAPUTT_ZWEITSCHRIFT') . $kd);
    case 'kaputt':
        return array('fehl', sw_t('TEST.KONF_KAPUTT') . $kd);
    }
    return array('hinweis', sw_t('TEST.KONF_UNBEKANNT'));
}

/** Tragen alle POST-Formulare der Oberflaeche das Formularmerkmal? Gezaehlt im Quelltext; eine leere Menge ist ein
 *  Kreuz. */
function sw_pruef_formulare($quelle)
{
    $n = 0;
    $ohne = 0;
    foreach (preg_split('/<form\b/i', (string) $quelle) as $i => $teil) {
        if ($i === 0) {
            continue;
        }
        $ende = stripos($teil, '</form>');
        $block = $ende === false ? $teil : substr($teil, 0, $ende);
        if (!preg_match('/^[^>]*method="post"/i', $block)) {
            continue;
        }
        $n++;
        if (strpos($block, 'sw_fmt()') === false) {
            $ohne++;
        }
    }
    if ($n === 0) {
        return array('fehl', sw_t('TEST.FORM_LEER'));
    }
    if ($ohne > 0) {
        return array('fehl', sprintf(sw_t('TEST.FORM_FEHL'), $ohne, $n));
    }
    return array('ok', sprintf(sw_t('TEST.FORM_OK'), $n));
}

/** Eine Adresse ueber 127.0.0.1 abrufen (Selbsttest des Endpunkts): array(code, rumpf); code 0 = keine Antwort. */
function sw_lokal_abrufen($pfad, $timeout = 3)
{
    $s = @stream_socket_client('tcp://127.0.0.1:' . sw_webport(), $eno, $etxt, $timeout);
    if (!$s) {
        return array(0, '');
    }
    stream_set_timeout($s, $timeout);
    @fwrite($s, 'GET ' . $pfad . " HTTP/1.0\r\nHost: 127.0.0.1\r\nUser-Agent: shellyweiche-selbsttest\r\n"
        . "Accept: text/plain\r\nConnection: close\r\n\r\n");
    $roh = '';
    $bis = microtime(true) + $timeout;
    while (!feof($s) && microtime(true) < $bis && strlen($roh) < 65536) {
        $t = @fread($s, 8192);
        if ($t === false) {
            break;
        }
        $roh .= $t;
    }
    fclose($s);
    if (!preg_match('#^HTTP/1\.[01] (\d{3})#', $roh, $m)) {
        return array(0, '');
    }
    $pos = strpos($roh, "\r\n\r\n");
    return array((int) $m[1], $pos === false ? '' : trim(substr($roh, $pos + 4)));
}

/** Antwortet der eigene Endpunkt? Ein echter Aufruf ueber 127.0.0.1 (?selftest=1, keine Wirkung). */
function sw_pruef_endpunkt($token)
{
    $p = sw_paths();
    if ($p['lbhome'] === '') {
        return array('hinweis', sw_t('TEST.EP_OHNE'));
    }
    if (!is_string($token) || $token === '') {
        return array('fehl', sw_t('TEST.EP_KEIN_TOKEN'));
    }
    list($code, $rumpf) = sw_lokal_abrufen('/plugins/' . rawurlencode($p['plugin']) . '/index.php?selftest=1&token='
        . rawurlencode($token));
    if ($code === 200 && strpos($rumpf, 'SELFTEST;OK=1;TOKEN=OK') === 0) {
        return array('ok', sprintf(sw_t('TEST.EP_OK'), sw_kurz($rumpf, 60)));
    }
    if ($code > 0) {
        return array('fehl', sprintf(sw_t('TEST.EP_FALSCH'), $code, sw_kurz($rumpf, 80)));
    }
    return array('hinweis', sw_t('TEST.EP_KEINE'));
}

/** Meldet sich der Broker mit den Zugangsdaten aus general.json an (CONNACK 0)? "Laeuft" ist nicht "angemeldet". */
function sw_pruef_broker()
{
    list($lage, $s) = sw_mq_verbinden('swprobe' . getmypid(), 10);
    if ($lage === 'ok') {
        sw_mq_trennen($s);
        return array('ok', sw_t('TEST.BROKER_OK'));
    }
    return array('fehl', sprintf(sw_t('TEST.BROKER_FEHL'), $lage));
}

/** Laeuft der Lauscher? Die juengste Sitzung beider Plaetze: laeuft oder vor hoechstens SW_TAKT_ALT s ordentlich
 *  beendet, mit Abo. */
function sw_pruef_lauscher()
{
    $l = sw_lauscher_lesen();
    $best = null;
    foreach (array('a', 'b') as $pl) {
        if (isset($l[$pl]['start']) && ($best === null || (int) $l[$pl]['start'] > (int) $best['start'])) {
            $best = $l[$pl];
        }
    }
    if ($best === null) {
        return array('fehl', sw_t('TEST.LAUSCH_NIE'));
    }
    $alter = time() - (int) $best['start'];
    $lage = isset($best['lage']) ? (string) $best['lage'] : '-';
    if ($alter < 0 || $alter > SW_TAKT_ALT) {
        return array('fehl', sprintf(sw_t('TEST.LAUSCH_ALT'), sw_alter_text($alter)));
    }
    if (!in_array($lage, array('laeuft', 'beendet'), true)) {
        return array('fehl', sprintf(sw_t('TEST.LAUSCH_LAGE'), $lage, sw_alter_text($alter)));
    }
    return array('ok', sprintf(sw_t('TEST.LAUSCH_OK'), sw_alter_text($alter), (int) $best['abos'],
        (int) $best['meldungen'], (int) $best['fuehler'], (int) $best['doppelt']));
}

/** Wie oft melden die Geraete? Ein Geraet, dessen letzte Meldung ueber SW_STUMM zurueckliegt, liefert Abnehmern
 *  "ohne Aussage". Rueckgabe array(lage, text). */
function sw_pruef_meldetakt(array $stand)
{
    if (!$stand['geraete']) {
        return array('hinweis', sw_t('TEST.TAKT_KEINE'));
    }
    $still = 0;
    $max = 0;
    foreach ($stand['geraete'] as $g) {
        $a = isset($g['empfang']) ? time() - (int) $g['empfang'] : -1;
        if ($a < 0 || $a > SW_STUMM) {
            $still++;
        }
        foreach ((isset($g['abstaende']) && is_array($g['abstaende'])) ? $g['abstaende'] : array() as $x) {
            $max = max($max, (int) $x);
        }
    }
    $n = count($stand['geraete']);
    if ($still > 0) {
        return array('hinweis', sprintf(sw_t('TEST.TAKT_STILL'), $still, $n, (int) (SW_STUMM / 60)));
    }
    return array('ok', sprintf(sw_t('TEST.TAKT_OK'), $n, $max > 0 ? sw_alter_text($max) : '-'));
}
