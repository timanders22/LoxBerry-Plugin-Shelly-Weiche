<?php
/**
 * Shelly-Weiche - Bedienoberflaeche
 *
 * Diese Datei ist NUR Oberflaeche. Der Unterbau steht in webfrontend/html/sw_lib.php, der Aktionsendpunkt in
 * webfrontend/html/index.php, Minutenlauf und Lauscher in bin/sw_takt.php - drei Aufgaben, drei Dateien.
 *
 * (c) Shelly-Weiche Plugin Authors - MIT-Lizenz
 */

/* Der Unterbau liegt im ANDEREN Baum (Regeln/03, Kandidatenliste):
     installiert  $LB/webfrontend/htmlauth/plugins/<ordner>/index.php
                  $LB/webfrontend/html/plugins/<ordner>/sw_lib.php
     im Archiv    <plugin>/webfrontend/htmlauth/index.php
                  <plugin>/webfrontend/html/sw_lib.php
   Vorrang hat die Umgebungsvariable, die LoxBerry selbst setzt. */
$sw_kandidaten = array();
$sw_html = getenv('LBPHTMLDIR');
if ($sw_html !== false && $sw_html !== '') {
    $sw_kandidaten[] = rtrim($sw_html, '/\\') . '/sw_lib.php';
}
$sw_kandidaten[] = dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/sw_lib.php';
$sw_kandidaten[] = dirname(__DIR__) . '/html/sw_lib.php';

/* Reihenfolge (Bauvorschrift, Regeln/03): Bibliothek, Konfiguration, Wachposten, Reiterwahl, ALLE Handler samt
   Download, dann erst lbheader(), dann HTML. */
$sw_geladen = '';
foreach ($sw_kandidaten as $sw_k) {
    if (is_file($sw_k)) {
        require_once $sw_k;
        $sw_geladen = $sw_k;
        break;
    }
}
if ($sw_geladen === '' || !function_exists('sw_paths')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>Shelly-Weiche: der Unterbau wurde nicht gefunden</h2><p>Gesucht wurde an diesen Stellen:</p><ul>';
    foreach ($sw_kandidaten as $sw_k) {
        echo '<li><code>' . htmlspecialchars($sw_k, ENT_QUOTES, 'UTF-8') . '</code></li>';
    }
    echo '</ul><p>Die Datei <code>sw_lib.php</code> gehoert nach <code>webfrontend/html/plugins/&lt;ordner&gt;/</code>. '
       . 'Fehlt sie dort, ist die Installation unvollstaendig - das Plugin bitte neu installieren.</p>';
    exit;
}

/* Der LoxBerry-Rahmen; loxberry_system.php zuerst, loxberry_web.php baut darauf auf. */
$sw_lb = sw_paths()['lbhome'];
if ($sw_lb !== '' && is_file($sw_lb . '/libs/phplib/loxberry_system.php')) {
    require_once $sw_lb . '/libs/phplib/loxberry_system.php';
    if (is_file($sw_lb . '/libs/phplib/loxberry_web.php')) {
        require_once $sw_lb . '/libs/phplib/loxberry_web.php';
    }
}

/* ---------- Reiter: Positivliste, ids und Leiste gehoeren zusammen; die Leiste steht unten ausgeschrieben ---------- */
$sw_reiter = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');
$sw_tab = 'tab-settings';
if (isset($_POST['activetab']) && is_string($_POST['activetab']) && in_array($_POST['activetab'], $sw_reiter, true)) {
    $sw_tab = $_POST['activetab'];
} elseif (isset($_GET['form']) && is_string($_GET['form']) && in_array('tab-' . $_GET['form'], $sw_reiter, true)) {
    $sw_tab = 'tab-' . $_GET['form'];
}

/* sw_config() merkt sich beim ERSTEN Lesen den Zustand der Datei vor der Heilung - der Reiter Test zeigt ihn. */
$sw_cfg = sw_config();
$sw_meldung = '';
$sw_hinweise = array();
$sw_fehler = array();
$sw_post = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';

/* Das Ergebnis der vorigen Anfrage (Einmalmeldung) - NUR beim GET gelesen. */
if (!$sw_post) {
    $sw_einmal = sw_einmal_lesen();
    if (is_array($sw_einmal)) {
        if (isset($sw_einmal['meldung']) && is_string($sw_einmal['meldung'])) {
            $sw_meldung = $sw_einmal['meldung'];
        }
        if (isset($sw_einmal['hinweise']) && is_array($sw_einmal['hinweise'])) {
            $sw_hinweise = array_values($sw_einmal['hinweise']);
        }
        if (isset($sw_einmal['fehler']) && is_array($sw_einmal['fehler'])) {
            $sw_fehler = array_values($sw_einmal['fehler']);
        }
    }
}

/* Der Wachposten - EIN Posten, vor allen Handlern. Abgewiesen heisst gemeldet, und es wird NICHTS ausgefuehrt:
   $_POST wird bis auf den aktiven Reiter geleert. */
$sw_wache = sw_wachposten();
if ($sw_wache !== '') {
    $sw_reiter_merk = isset($_POST['activetab']) && is_string($_POST['activetab']) ? $_POST['activetab'] : null;
    $_POST = array();
    if ($sw_reiter_merk !== null) {
        $_POST['activetab'] = $sw_reiter_merk;
    }
    $sw_fehler[] = $sw_wache;
}

/* Ein Formularfeld als Zeichenkette, am Rand gekuerzt; ein Feld (name[]=) ergibt '' statt "Array". */
function sw_feld($name)
{
    return (isset($_POST[$name]) && is_string($_POST[$name])) ? trim($_POST[$name]) : '';
}

/* Der Geraetezustand (Lauscher) - unter der Sperre gelesen, nur gelesen. */
$sw_sp = sw_sperre('geraete');
$sw_stand = sw_geraete_lesen();
sw_freigeben($sw_sp);

/* Die Zeilen der Geraetetabelle: zuerst die gespeicherten Geraete (in ihrer Reihenfolge), dann die neu erkannten mit
   Namensvorschlag; ihr Haken ist ab Werk AUS - ein Speichern aus anderem Anlass schaltet kein neues Geraet ein. */
function sw_zeilen(array $c, array $stand)
{
    $z = array();
    $belegt = array();
    $gespeichert = array();
    foreach ($c['geraete'] as $e) {
        $z[] = array('praefix' => $e['praefix'], 'name' => $e['name'], 'ein' => (int) $e['ein'], 'neu' => false);
        $belegt[$e['name']] = true;
        $gespeichert[$e['praefix']] = true;
    }
    $neu = array();
    foreach (array_keys($stand['geraete']) as $pr) {
        if (!isset($gespeichert[(string) $pr])) {
            $neu[] = (string) $pr;
        }
    }
    sort($neu, SORT_STRING);
    foreach (sw_vorbelegung($neu, $belegt) as $pr => $n) {
        $z[] = array('praefix' => $pr, 'name' => $n, 'ein' => 0, 'neu' => true);
    }
    return $z;
}

/* ---------------- Einstellungen speichern (Aktionstoken) ----------------
   Abweisen und benennen, nie still zurechtbiegen (Regeln/05). Ein LEERES Feld heisst nicht "loeschen" - zum
   Entfernen gibt es einen eigenen Knopf. */
if ($sw_post && isset($_POST['speichern'])) {
    $sw_neu = $sw_cfg;
    $sw_roh = sw_feld('token');
    if ($sw_roh !== '' && !sw_token_taugt($sw_roh)) {
        $sw_fehler[] = array('kopf' => sw_t('TEXT.NICHT_GESPEICHERT'),
                             'punkte' => array(sprintf(sw_t('TEXT.TOKEN_UNGUELTIG'), strlen($sw_roh))));
    } else {
        if ($sw_roh !== '') {
            $sw_neu['token'] = $sw_roh;
        }
        if (sw_config_speichern($sw_neu)) {
            $sw_meldung = sw_t('TEXT.GESPEICHERT');
        } else {
            $sw_fehler[] = sw_t('TEXT.SCHREIBFEHLER');
        }
    }
    $sw_cfg = sw_config();
}
if ($sw_post && isset($_POST['token_neu'])) {
    $sw_cfg['token'] = sw_token_erzeugen();
    if (sw_config_speichern($sw_cfg)) {
        $sw_meldung = sw_t('TEXT.TOKEN_ERZEUGT');
    } else {
        $sw_fehler[] = sw_t('TEXT.SCHREIBFEHLER');
    }
    $sw_cfg = sw_config();
}
if ($sw_post && isset($_POST['token_weg'])) {
    $sw_cfg['token'] = '';
    if (sw_config_speichern($sw_cfg)) {
        $sw_meldung = sw_t('TEXT.TOKEN_ENTFERNT');
    } else {
        $sw_fehler[] = sw_t('TEXT.SCHREIBFEHLER');
    }
    $sw_cfg = sw_config();
}

/* ---------------- MQTT speichern: haus/klima, weiteres Abo, Geraete ----------------
   Eigenes Formular, eigener Handler (Regeln/07). ALLE Beanstandungen werden gesammelt; bei einer einzigen wird GAR
   NICHTS gespeichert. Ein Praefix, das weder gespeichert noch vom Lauscher erkannt ist, wird abgewiesen - das
   Formular kann kein Geraet erfinden. */
if ($sw_post && isset($_POST['mqtt_speichern'])) {
    $sw_neu = $sw_cfg;
    $sw_mangel = array();
    $sw_neu['haus_klima_ein'] = empty($_POST['haus_klima_ein']) ? 0 : 1;
    $sw_roh = sw_feld('abo_zusatz');
    if (!sw_abo_taugt($sw_roh)) {
        $sw_mangel[] = sprintf(sw_t('MQTT.ABO_UNGUELTIG'), sw_kurz($sw_roh, 100));
    } else {
        $sw_neu['abo_zusatz'] = $sw_roh;
    }
    $sw_bekannt = array();
    foreach (sw_zeilen($sw_cfg, $sw_stand) as $sw_z) {
        $sw_bekannt[$sw_z['praefix']] = true;
    }
    $sw_pl = (isset($_POST['g_praefix']) && is_array($_POST['g_praefix'])) ? $_POST['g_praefix'] : array();
    $sw_nl = (isset($_POST['g_name']) && is_array($_POST['g_name'])) ? $_POST['g_name'] : array();
    $sw_el = (isset($_POST['g_ein']) && is_array($_POST['g_ein'])) ? $_POST['g_ein'] : array();
    $sw_wl = (isset($_POST['g_weg']) && is_array($_POST['g_weg'])) ? $_POST['g_weg'] : array();
    $sw_liste = array();
    $sw_gesehen = array();
    if (count($sw_pl) > SW_GERAETE_MAX) {
        $sw_mangel[] = sprintf(sw_t('GERAET.ZU_VIELE'), count($sw_pl), SW_GERAETE_MAX);
        $sw_pl = array();
    }
    foreach ($sw_pl as $sw_i => $sw_pr) {
        if (!is_string($sw_pr) || !isset($sw_bekannt[$sw_pr]) || isset($sw_gesehen[$sw_pr])) {
            $sw_mangel[] = sprintf(sw_t('GERAET.ZEILE_UNBEKANNT'), is_int($sw_i) ? $sw_i + 1 : 0);
            continue;
        }
        $sw_gesehen[$sw_pr] = true;
        if (!empty($sw_wl[$sw_i])) {
            continue;
        }
        $sw_n = (isset($sw_nl[$sw_i]) && is_string($sw_nl[$sw_i])) ? trim($sw_nl[$sw_i]) : '';
        $sw_liste[] = array('praefix' => $sw_pr, 'name' => $sw_n, 'ein' => empty($sw_el[$sw_i]) ? 0 : 1);
    }
    foreach (sw_geraete_pruefen($sw_liste) as $sw_x) {
        $sw_mangel[] = $sw_x;
    }
    if ($sw_mangel) {
        $sw_fehler[] = array('kopf' => sw_t('TEXT.NICHT_GESPEICHERT'), 'punkte' => $sw_mangel);
    } else {
        $sw_neu['geraete'] = sw_geraete_normal($sw_liste);
        if (sw_config_speichern($sw_neu)) {
            $sw_meldung = sw_t('MQTT.GESPEICHERT');
            /* Was nicht mehr gesendet wird (Haken aus, Geraet abgewaehlt, Name geaendert), sofort mit Ruecklesen
               abraeumen; der naechste Minutenlauf sendet den vollen Satz. */
            list($sw_hm, $sw_hlage) = sw_haus_folgen($sw_neu);
            if ($sw_hm !== '') {
                if ($sw_hlage === 'ok') {
                    $sw_hinweise[] = $sw_hm;
                } else {
                    $sw_fehler[] = $sw_hm;
                }
            }
        } else {
            $sw_fehler[] = sw_t('TEXT.SCHREIBFEHLER');
        }
    }
    $sw_cfg = sw_config();
}

/* ---------------- Einstellungen sichern ----------------
   Ausgegeben wird die VOLLE Konfiguration samt Aktionstoken (ohne es waere die Datei nach dem Zurueckspielen
   wertlos); der Hinweis am Knopf sagt das. Der Download ist die eine Antwort ohne Umleitung (Regeln/04). */
if ($sw_post && isset($_POST['sw_sichern'])) {
    $sw_js = sw_sicherung_bauen(sw_config());
    if ($sw_js !== '') {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="shellyweiche_einstellungen_' . date('Ymd_His') . '.json"');
        echo $sw_js;
        exit;
    }
    $sw_fehler[] = sw_t('TEXT.SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
   Den Eingang prueft das gemeinsame Sicherungsmodul (Haken zurueck_ok, is_uploaded_file(), Groesse); es liest,
   prueft, verwirft Unbekanntes mit Namen, laesst leere Werte den bisherigen stehen und aendert bei einer
   Beanstandung GAR NICHTS. Die Meldungen sind Klartext und werden bei der Ausgabe maskiert (sw_e()). */
if ($sw_post && isset($_POST['sw_zurueck'])) {
    list($sw_roh, $sw_ab) = sicherung_eingang(sw_sicherung_klartext(), $_POST, $_FILES, 'sw_sicherung');
    if ($sw_ab !== null) {
        $sw_sm = sicherung_meldungen(sw_sicherung_klartext(), $sw_ab);
        foreach ($sw_sm['fehler'] as $sw_x) { $sw_fehler[] = $sw_x; }
    } else {
        $sw_b = sw_sicherung_klartext();
        $sw_b['speichern'] = function ($neu) { return sw_config_speichern($neu); };
        $sw_b['zweitschrift'] = function () { $p = sw_paths(); return array($p['cfgdatei'], $p['sicherung']); };
        $sw_b['nachziehen'] = 'sw_sicherung_nachziehen';
        $sw_b['log'] = 'sw_log';
        $sw_r = sicherung_zurueckspielen($sw_b, $sw_roh);
        $sw_sm = sicherung_meldungen($sw_b, $sw_r);
        foreach ($sw_sm['fehler'] as $sw_x) { $sw_fehler[] = $sw_x; }
        $sw_sh = $sw_sm['hinweise'];
        if ($sw_r['ok'] && !empty($sw_r['gespeichert']) && $sw_sh) {
            $sw_meldung = (string) array_shift($sw_sh);
        }
        foreach ($sw_sh as $sw_x) { $sw_hinweise[] = $sw_x; }
        $sw_cfg = sw_config();
    }
}

/* ---------------- Umleitung nach jedem POST (PRG) ----------------
   Jeder POST endet mit 303 auf index.php?form=<reiter>; das Ergebnis reist als Einmalmeldung. Laesst sie sich nicht
   ablegen, wird die Seite gleich gezeigt, mit einem Hinweis. */
if ($sw_post) {
    if (sw_einmal_schreiben(array('meldung' => $sw_meldung, 'hinweise' => $sw_hinweise, 'fehler' => $sw_fehler))) {
        header('Location: index.php?form=' . rawurlencode(substr($sw_tab, 4)), true, 303);
        exit;
    }
    $sw_hinweise[] = sw_t('TEXT.EINMAL_FEHLT');
}

$sw_zl = sw_zeilen($sw_cfg, $sw_stand);
$sw_takt = sw_takt_lesen();
$sw_hlauf = sw_haus_lauf_lesen();
$sw_host = sw_rechner();
$sw_plugin = sw_paths()['plugin'];
$sw_rahmen = class_exists('LBWeb', false);
if ($sw_rahmen) {
    require_once __DIR__ . '/fassung.php';   // Fassung im Titel: gemeinsames Stueck fassung.php (21.11.2026)
    LBWeb::lbheader(plugin_kopftitel(sw_t('ALLGEMEIN.TITEL'), __DIR__), 'https://wiki.loxberry.de/', 'help.html');
}
/** Ein Zeitpunkt mit Alter, oder ein Strich. */
function sw_zeit_text($t)
{
    $t = (int) $t;
    return $t > 0 ? date('d.m.Y H:i:s', $t) . ' (' . sw_alter_text(time() - $t) . ')' : '-';
}
/** Ein Wert aus dem Geraetezustand fuer die Anzeige. */
function sw_wert_anzeige(array $g, $feld, $art)
{
    if (!isset($g['werte']) || !is_array($g['werte']) || !array_key_exists($feld, $g['werte'])) {
        return '-';
    }
    $x = sw_zahl($g['werte'][$feld]);
    return $x === null ? sw_t('GERAET.UNPLAUSIBEL') : sw_haus_zahl($x, $art);
}
?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit - man
   sieht ein schmales Feld in einem breiten weissen Kasten. Und beim
   Auswahlfeld liegt das unsichtbare <select> ueber dem Knopf und faengt die
   Klicks ab; wer es gestaltet, schiebt es weg. Deshalb wird ausschliesslich
   der Behaelter begrenzt. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. Die
   Hover-Farben unten sind kein Feinschliff, sondern Pflicht: fehlen sie, kommt
   der Hover-Zustand vom Rahmen und ist unlesbar. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
/* Statuskacheln — bewusst ein anderer Name als sm-knopfreihe.
   Beide zu verwechseln hat am 26.07.2026 die Statusanzeige zerlegt. */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }

.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
/* Eigene Hover- und Fokusfarben je Gruppe - sonst uebernimmt der Rahmen. */
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Reiterinhalte: nur der aktive ist sichtbar.
   Ohne diese zwei Zeilen stehen alle fuenf Reiter untereinander.
   MIT ihnen und OHNE serverseitiges sm-active ist die Seite dagegen
   vollstaendig leer, sobald das Skript nicht laeuft - genau das war bis
   07.08.2026 der Fall. Die Klasse gehoert deshalb schon ins ausgelieferte
   HTML, siehe die Reiterleiste weiter unten. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
/* Ergaenzungen dieser Linie, je wie in der Ecowitt-Weiche 0.9.38 (Fehlerkasten, Eingabefelder, Protokoll). */
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-wrap input[type=text], .sm-wrap input[type=file] {
  width: 100%; max-width: 520px; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px;
  font-size: 0.95em; box-sizing: border-box; }
.sm-wrap table input[type=text] { max-width: none; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-grau { color: #999; font-style: italic; }
/* Bild der Musterprojekt-Seite, wortgleich Ecowitt-Weiche 0.9.40 (22.11.2026). */
.sm-bild { margin: 12px 0; }
.sm-bild img { max-width: 100%; height: auto; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.sm-bild figcaption { font-size: .9em; color: #555; margin-top: 4px; }
</style>

<div class="sm-wrap">

<?php if ($sw_meldung !== '') { ?><div class="sm-hinweis"><?= sw_e($sw_meldung) ?></div><?php } ?>
<?php foreach ($sw_hinweise as $sw_h) { if (is_string($sw_h)) { ?><div class="sm-warnung"><?= sw_e($sw_h) ?></div><?php } } ?>
<?php foreach ($sw_fehler as $sw_fm) {
    if (is_array($sw_fm)) {
        $sw_kopf = isset($sw_fm['kopf']) && is_string($sw_fm['kopf']) ? $sw_fm['kopf'] : '';
        $sw_punkte = isset($sw_fm['punkte']) && is_array($sw_fm['punkte']) ? $sw_fm['punkte'] : array(); ?>
<div class="sm-fehler"><?= sw_e($sw_kopf) ?><ul><?php foreach ($sw_punkte as $sw_p) { if (is_string($sw_p)) { ?><li><?= sw_e($sw_p) ?></li><?php } } ?></ul></div>
<?php } elseif (is_string($sw_fm)) { ?><div class="sm-fehler"><?= sw_e($sw_fm) ?></div><?php }
} ?>

<?php /* Kopf: Statusuebersicht ueber den Reitern, nur aus den Dateien des Datenordners - die Seite fragt den Broker
   dafuer nicht. */
$sw_ll = sw_lauscher_lesen();
$sw_lj = null;
foreach (array('a', 'b') as $sw_pl) {
    if (isset($sw_ll[$sw_pl]['start']) && ($sw_lj === null || (int) $sw_ll[$sw_pl]['start'] > (int) $sw_lj['start'])) {
        $sw_lj = $sw_ll[$sw_pl];
    }
} ?>
<table class="sm-tbl" style="max-width:620px">
<tr><th><?= sw_e(sw_t('TEXT.KOPF_EIGENSCHAFT')) ?></th><th><?= sw_e(sw_t('TEXT.KOPF_WERT')) ?></th></tr>
<tr><td><?= sw_e(sw_t('TEXT.KOPF_LAUSCHER')) ?></td><td><?= $sw_lj === null ? sw_e(sw_t('TEXT.KOPF_NIE'))
    : sw_e(sprintf(sw_t('TEXT.KOPF_LAUSCHER_WERT'), sw_zeit_text($sw_lj['start']), (string) $sw_lj['lage'])) ?></td></tr>
<tr><td><?= sw_e(sw_t('TEXT.KOPF_GERAETE')) ?></td><td><?= (int) count($sw_stand['geraete']) ?></td></tr>
<tr><td><?= sw_e(sw_t('TEXT.KOPF_HAUS')) ?></td><td class="<?= !empty($sw_cfg['haus_klima_ein']) ? 'sm-an' : 'sm-aus' ?>"><?= sw_e(sw_t(!empty($sw_cfg['haus_klima_ein']) ? 'TEXT.KOPF_HAUS_AN' : 'TEXT.KOPF_HAUS_AUS')) ?></td></tr>
<tr><td><?= sw_e(sw_t('TEXT.KOPF_TAKT')) ?></td><td><?= sw_e(isset($sw_takt['ts']) ? sw_zeit_text($sw_takt['ts']) : sw_t('TEXT.KOPF_NIE')) ?></td></tr>
</table>

<!-- Die Reiterleiste steht AUSGESCHRIEBEN da, nicht in einer Schleife erzeugt (VORLAGE_hausstandard.css.html).
     Umgeschaltet wird ueber den Server; das Skript unten faengt nur den Klick ab. -->
<div class="sm-tabs">
	<a class="sm-tab<?= $sw_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?= sw_e(sw_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $sw_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
	   href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab<?= $sw_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?= sw_e(sw_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $sw_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?= sw_e(sw_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $sw_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?= sw_e(sw_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Einstellungen ================= -->
<div class="sm-seite<?= $sw_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-hinweis"><?= sw_t('TEXT.WAS_IST_DAS') ?></div>

<h2><?= sw_e(sw_t('GERAET.H')) ?></h2>
<div class="sm-step"><?= sw_t('GERAET.ERKLAERUNG') ?></div>
<?php if (!$sw_stand['geraete']) { ?>
<div class="sm-warnung"><?= sw_t('GERAET.KEINE') ?></div>
<?php } else { ?>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th><?= sw_e(sw_t('GERAET.SP_PRAEFIX')) ?></th><th><?= sw_e(sw_t('GERAET.SP_MODELL')) ?></th><th><?= sw_e(sw_t('GERAET.SP_LETZTE')) ?></th>
      <th><?= sw_e(sw_t('GERAET.SP_TEMP')) ?></th><th><?= sw_e(sw_t('GERAET.SP_FEUCHTE')) ?></th><th><?= sw_e(sw_t('GERAET.SP_BATTERIE')) ?></th>
      <th><?= sw_e(sw_t('GERAET.SP_ONLINE')) ?></th><th><?= sw_e(sw_t('GERAET.SP_ZAHL')) ?></th><th><?= sw_e(sw_t('GERAET.SP_ABSTAND')) ?></th></tr>
<?php $sw_gl = $sw_stand['geraete']; ksort($sw_gl, SORT_STRING);
foreach ($sw_gl as $sw_pr => $sw_g) {
    $sw_ab = (isset($sw_g['abstaende']) && is_array($sw_g['abstaende']) && $sw_g['abstaende']) ? end($sw_g['abstaende']) : null;
    $sw_alt = isset($sw_g['empfang']) ? time() - (int) $sw_g['empfang'] : -1; ?>
  <tr><td class="sm-mono"><?= sw_e((string) $sw_pr) ?></td><td><?= sw_e(isset($sw_g['modell']) ? (string) $sw_g['modell'] : '-') ?></td>
      <td class="<?= ($sw_alt >= 0 && $sw_alt <= SW_STILL) ? 'sm-an' : 'sm-aus' ?>"><?= sw_e(isset($sw_g['empfang']) ? sw_zeit_text($sw_g['empfang']) : '-') ?></td>
      <td><?= sw_e(sw_wert_anzeige($sw_g, 'temperatur', 'z1')) ?></td><td><?= sw_e(sw_wert_anzeige($sw_g, 'feuchte', 'z0')) ?></td>
      <td><?= sw_e(isset($sw_g['batterie']) ? (int) $sw_g['batterie'] . ' %' : '-') ?></td>
      <td><?= sw_e(isset($sw_g['online']) ? (string) $sw_g['online'] : '-') ?></td>
      <td><?= (int) (isset($sw_g['zahl']) ? $sw_g['zahl'] : 0) ?></td>
      <td><?= sw_e($sw_ab === null ? '-' : sw_alter_text((int) $sw_ab)) ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<p class="sm-hilfe"><?= sw_t('GERAET.HILFE') ?></p>

<h2><?= sw_e(sw_t('TEXT.H_TOKEN')) ?></h2>
<form action="index.php" method="post">
  <?= sw_fmt() ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-settings">
  <div class="sm-feld">
    <label><?= sw_e(sw_t('TEXT.L_TOKEN')) ?></label>
    <input data-role="none" type="text" name="token" value="<?= sw_e($sw_cfg['token']) ?>" maxlength="64" autocomplete="off">
    <p class="sm-hilfe"><?= sw_t('TEXT.H_TOKEN_HILFE') ?></p>
  </div>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?= sw_e(sw_t('TEXT.K_SPEICHERN')) ?></button>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= sw_e(sw_t('TEXT.K_TOKEN_NEU')) ?></button>
<?php if ($sw_cfg['token'] !== '') { ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_weg" value="1"><?= sw_e(sw_t('TEXT.K_TOKEN_WEG')) ?></button>
<?php } ?>
  </div>
</form>

<h2><?= sw_e(sw_t('TEXT.H_SICHERUNG')) ?></h2>
<div class="sm-hinweis"><?= sw_t('TEXT.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= sw_t('TEXT.SICH_WARNUNG') ?></div>
<?php $sw_altwerte = sw_rueckspiel_altwerte($sw_cfg); if ($sw_altwerte) { ?>
<div class="sm-warnung"><?= sw_e(sprintf(sw_t('TEXT.SICH_ALTWERT'), implode(', ', $sw_altwerte))) ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare: das Sichern liefert einen Download und endet; das Zurueckspielen braucht
       enctype="multipart/form-data". -->
  <form action="index.php" method="post">
    <?= sw_fmt() ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="sw_sichern" value="1"><?= sw_e(sw_t('TEXT.K_SICHERN')) ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <?= sw_fmt() ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="sw_sicherung" accept=".json">
    <?= sicherung_haken_zeile(sw_sicherung_beschreibung()) ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="sw_zurueck" value="1"><?= sw_e(sw_t('TEXT.K_ZURUECK')) ?></button>
  </form>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sw_e(sw_t('LEGENDE.AKTION')) ?></span> <span><i class="sm-punkt sm-b-lesen"></i> <?= sw_e(sw_t('LEGENDE.LESEN')) ?></span></div>
</div>

<!-- ================= MQTT ================= -->
<?php $sw_gw = sw_mqtt_gateway(); ?>
<div class="sm-seite<?= $sw_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<h2><?= sw_e(sw_t('MQTT.H')) ?></h2>
<div class="sm-step"><?= sw_t('MQTT.ERKLAERUNG') ?></div>
<form action="index.php" method="post">
  <?= sw_fmt() ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
  <div class="sm-feld">
    <label><input data-role="none" type="checkbox" name="haus_klima_ein" value="1"<?= !empty($sw_cfg['haus_klima_ein']) ? ' checked' : '' ?>>
      <?= sw_e(sw_t('HAUS.L_EIN')) ?></label>
    <p class="sm-hilfe"><?= sw_t('HAUS.H_EIN') ?></p>
  </div>
  <h3><?= sw_e(sw_t('HAUS.H_GERAETE')) ?></h3>
<?php if (!$sw_zl) { ?>
  <p class="sm-grau"><?= sw_e(sw_t('GERAET.KEINE_KURZ')) ?></p>
<?php } else { ?>
  <div class="sm-breit">
  <table class="sm-tbl">
    <tr><th><?= sw_e(sw_t('HAUS.SP_EIN')) ?></th><th><?= sw_e(sw_t('GERAET.SP_PRAEFIX')) ?></th><th><?= sw_e(sw_t('HAUS.SP_NAME')) ?></th>
        <th><?= sw_e(sw_t('HAUS.SP_LAGE')) ?></th><th><?= sw_e(sw_t('HAUS.SP_WEG')) ?></th></tr>
<?php foreach ($sw_zl as $sw_i => $sw_z) {
    $sw_da = isset($sw_stand['geraete'][$sw_z['praefix']]); ?>
    <tr><td><input data-role="none" type="checkbox" name="g_ein[<?= (int) $sw_i ?>]" value="1"<?= $sw_z['ein'] ? ' checked' : '' ?>></td>
        <td class="sm-mono"><input data-role="none" type="hidden" name="g_praefix[<?= (int) $sw_i ?>]" value="<?= sw_e($sw_z['praefix']) ?>"><?= sw_e($sw_z['praefix']) ?></td>
        <td><input data-role="none" type="text" name="g_name[<?= (int) $sw_i ?>]" value="<?= sw_e($sw_z['name']) ?>" maxlength="40"></td>
        <td><?= sw_e(sw_t($sw_z['neu'] ? 'HAUS.LAGE_NEU' : ($sw_da ? 'HAUS.LAGE_GESPEICHERT' : 'HAUS.LAGE_UNGESEHEN'))) ?></td>
        <td><?php if (!$sw_z['neu']) { ?><input data-role="none" type="checkbox" name="g_weg[<?= (int) $sw_i ?>]" value="1"><?php } else { ?>-<?php } ?></td></tr>
<?php } ?>
  </table>
  </div>
<?php } ?>
  <p class="sm-hilfe"><?= sw_t('HAUS.H_NAME') ?></p>
  <div class="sm-feld">
    <label><?= sw_e(sw_t('MQTT.L_ABO')) ?></label>
    <input data-role="none" type="text" name="abo_zusatz" value="<?= sw_e($sw_cfg['abo_zusatz']) ?>" maxlength="100" placeholder="haus/+/+/events/rpc">
    <p class="sm-hilfe"><?= sw_t('MQTT.H_ABO') ?></p>
  </div>
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="mqtt_speichern" value="1"><?= sw_e(sw_t('MQTT.K_SPEICHERN')) ?></button>
  </div>
  <div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= sw_e(sw_t('LEGENDE.AKTION')) ?></span></div>
</form>

<h3><?= sw_e(sw_t('MQTT.H_GATEWAY')) ?></h3>
<table class="sm-tbl" style="max-width:620px">
<tr><td><?= sw_e(sw_t('MQTT.T_FASSUNG')) ?></td><td><?= $sw_gw['fassung'] > 0 ? 'V' . (int) $sw_gw['fassung'] : sw_e(sw_t('MQTT.FASSUNG_UNBEKANNT')) ?></td></tr>
<tr><td><?= sw_e(sw_t('MQTT.T_AUTOSTART')) ?></td><td class="<?= $sw_gw['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= sw_e(sw_t($sw_gw['autostart'] ? 'MQTT.AUTOSTART_EIN' : 'MQTT.AUTOSTART_AUS')) ?></td></tr>
<tr><td><?= sw_e(sw_t('MQTT.T_ABOS')) ?></td><td class="sm-mono"><?= sw_e(implode(', ', array_slice(sw_abos($sw_cfg, array('geraete' => array())), 0, 3))) ?> <?= sw_e(sprintf(sw_t('MQTT.ABOS_ONLINE'), count($sw_stand['geraete']))) ?></td></tr>
</table>
<p class="sm-hilfe"><?= sw_t('MQTT.ABO_HILFE') ?></p>

<?php $sw_htl = sw_haus_themenliste($sw_cfg); ?>
<h3><?= sw_e(sw_t('HAUS.H_THEMEN')) ?></h3>
<p class="sm-hilfe"><?= sw_t('HAUS.THEMEN_HILFE') ?></p>
<?php if (!$sw_htl) { ?>
<p class="sm-grau"><?= sw_e(sw_t('HAUS.THEMEN_KEINE')) ?></p>
<?php } else { ?>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th><?= sw_e(sw_t('MQTT.T_THEMA')) ?></th><th><?= sw_e(sw_t('MQTT.T_BEDEUTUNG')) ?></th><th><?= sw_e(sw_t('MQTT.T_RETAIN')) ?></th></tr>
<?php foreach ($sw_htl as $sw_hz) { ?>
  <tr><td class="sm-mono"><?= sw_e($sw_hz[0]) ?></td><td><?= sw_e(sw_t($sw_hz[1])) ?></td><td><?= sw_e(sw_t($sw_hz[2] ? 'MQTT.RETAIN_JA' : 'MQTT.RETAIN_NIE')) ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-seite<?= $sw_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= sw_e(sw_t('EINBINDUNG.H')) ?></h2>
<div class="sm-step"><?= sw_t('EINBINDUNG.ERKLAERUNG') ?></div>
<h3><?= sw_e(sw_t('EINBINDUNG.S1')) ?></h3>
<p class="sm-hilfe"><?= sw_t('EINBINDUNG.S1_TEXT') ?></p>
<h3><?= sw_e(sw_t('EINBINDUNG.S2')) ?></h3>
<p class="sm-hilfe"><?= sw_t('EINBINDUNG.S2_TEXT') ?></p>
<?php list($sw_eb_liste) = sw_haus_zuordnung($sw_cfg);
if (!$sw_eb_liste) { ?>
<div class="sm-warnung"><?= sw_t('EINBINDUNG.KEINE') ?></div>
<?php } else { ?>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th><?= sw_e(sw_t('EINBINDUNG.SP_ABO')) ?></th><th><?= sw_e(sw_t('EINBINDUNG.SP_EINGANG')) ?></th><th><?= sw_e(sw_t('EINBINDUNG.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach ($sw_eb_liste as $sw_z) {
    foreach (array_merge(array_map(function ($f) { return $f[0]; }, sw_haus_felder()), array('zeit', 'frist')) as $sw_f) {
        $sw_th = 'haus/klima/' . $sw_z['name'] . '/' . $sw_f; ?>
  <tr><td class="sm-mono"><?= sw_e('haus/klima/' . $sw_z['name'] . '/#') ?></td><td class="sm-mono"><?= sw_e(str_replace(array('/', '%'), '_', $sw_th)) ?></td>
      <td><?= sw_e(sw_t('HAUS.F_' . strtoupper($sw_f))) ?></td></tr>
<?php }
} ?>
  <tr><td class="sm-mono">haus/klima/zaehler/<?= sw_e(SW_ANBIETER) ?></td><td class="sm-mono">haus_klima_zaehler_<?= sw_e(SW_ANBIETER) ?></td><td><?= sw_e(sw_t('HAUS.F_ZAEHLER')) ?></td></tr>
</table>
</div>
<?php } ?>
<h3><?= sw_e(sw_t('EINBINDUNG.S3')) ?></h3>
<p class="sm-hilfe"><?= sw_t('EINBINDUNG.S3_TEXT') ?></p>
<?php /* Baustein-Liste der Musterprojekt-Seite "Shelly-Weiche" (22.11.2026, Musterprojekt/vorbereitet_2026-11-22).
   Zeile: array(Typ, Name, Parameter, Verbindung); 'K:' = Sprachschluessel unter [BAUSTEIN], sonst fester Name. Das Bild
   erscheint nur, wenn die Datei im Plugin liegt. */
$sw_bs = array(
    array('K:T_VI', 'haus_klima_zaehler_shellyweiche', 'K:B1_PARAM', '–'),
    array('K:B2_TYP', 'K:B2_NAME', 'K:B2_PARAM', '–'),
    array('K:B3_TYP', 'K:B3_NAME', 'K:B3_PARAM', 'K:B3_VERB'),
    array('K:T_VI', 'haus_klima_kueche_temperatur', 'K:B4_PARAM', '–'),
    array('K:T_VI', 'haus_klima_kueche_feuchte', 'K:B5_PARAM', '–'),
    array('K:T_VI', 'haus_klima_kueche_zeit', 'K:B6_PARAM', '–'),
    array('K:B7_TYP', 'K:B7_NAME', 'K:B7_PARAM', '–'),
    array('K:T_VI', 'haus_klima_kueche_frist', 'K:B8_PARAM', '–'),
    array('K:B9_TYP', 'K:B9_NAME', 'K:B9_PARAM', 'K:B9_VERB'),
    array('K:B10_TYP', 'K:B10_NAME', 'K:B10_PARAM', 'K:B10_VERB'),
);
$sw_bs_t = function ($x) {
    if (strpos($x, 'K:') !== 0) { return sw_e($x); }
    return preg_replace_callback('/\{B(\d+)\}/', function ($m) { return '#' . $m[1]; }, sw_t('BAUSTEIN.' . substr($x, 2)));
}; ?>
<h3><?= sw_e(sw_t('BAUSTEIN.H')) ?></h3>
<p class="sm-hilfe"><?= $sw_bs_t('K:TEXT') ?></p>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th>#</th><th><?= sw_e(sw_t('BAUSTEIN.T_TYP')) ?></th><th><?= sw_e(sw_t('BAUSTEIN.T_NAME')) ?></th><th><?= sw_e(sw_t('BAUSTEIN.T_PARAM')) ?></th><th><?= sw_e(sw_t('BAUSTEIN.T_VERB')) ?></th></tr>
<?php foreach ($sw_bs as $sw_i => $sw_z) { ?>
  <tr><td><?= $sw_i + 1 ?></td><td><?= $sw_bs_t($sw_z[0]) ?></td><td class="sm-mono"><?= $sw_bs_t($sw_z[1]) ?></td><td><?= $sw_bs_t($sw_z[2]) ?></td><td><?= $sw_bs_t($sw_z[3]) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-warnung"><b><?= sw_e(sw_t('BAUSTEIN.HINWEISE_TITEL')) ?></b>
<ul>
  <li><?= $sw_bs_t('K:N1') ?></li>
  <li><?= $sw_bs_t('K:N2') ?></li>
  <li><?= $sw_bs_t('K:N3') ?></li>
</ul></div>
<?php if (is_file(__DIR__ . '/einbindung_shelly_weiche.png')) { ?>
<figure class="sm-bild">
<img src="einbindung_shelly_weiche.png" alt="<?= sw_e(sw_t('BAUSTEIN.BILD_ALT')) ?>" loading="lazy">
<figcaption><?= sw_e(sprintf(sw_t('BAUSTEIN.BILD_UNTERSCHRIFT'), 'Shelly-Weiche')) ?></figcaption>
</figure>
<?php } ?>
<p class="sm-hilfe"><?= sw_t('BAUSTEIN.MUSTERPROJEKT') ?></p>
<h3><?= sw_e(sw_t('EINBINDUNG.S4')) ?></h3>
<p class="sm-hilfe"><?= sw_t('EINBINDUNG.S4_TEXT') ?></p>
<?php if ($sw_cfg['token'] === '') { ?>
<div class="sm-warnung"><?= sw_t('EINBINDUNG.OHNE_TOKEN') ?></div>
<?php } else {
    $sw_basis = 'http://' . $sw_host . '/plugins/' . rawurlencode($sw_plugin) . '/index.php'; ?>
<table class="sm-tbl">
  <tr><th><?= sw_e(sw_t('EINBINDUNG.SP_ZWECK')) ?></th><th><?= sw_e(sw_t('EINBINDUNG.SP_ADRESSE')) ?></th></tr>
  <tr><td><?= sw_e(sw_t('EINBINDUNG.A_VOLL')) ?></td><td class="sm-mono"><?= sw_e($sw_basis . '?aktion=voll&token=' . rawurlencode($sw_cfg['token'])) ?></td></tr>
  <tr><td><?= sw_e(sw_t('EINBINDUNG.A_SELBSTTEST')) ?></td><td class="sm-mono"><?= sw_e($sw_basis . '?selftest=1&token=' . rawurlencode($sw_cfg['token'])) ?></td></tr>
</table>
<?php } ?>
</div>

<!-- ================= Test ================= -->
<div class="sm-seite<?= $sw_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= sw_e(sw_t('TEST.H')) ?></h2>
<div class="sm-step"><?= sw_t('TEST.ERKLAERUNG') ?></div>
<?php
/* Jede Zeile misst die Wirkung, nicht einen Rueckgabewert; Broker und Endpunkt werden nur bei geoeffnetem Reiter
   gefragt. "nicht feststellbar" (hinweis) zaehlt nie als bestanden; die Zusammenfassung ist so schlecht wie ihr
   schlechtester Punkt. */
$sw_offen = ($sw_tab === 'tab-test');
$sw_quelle = (string) @file_get_contents(__FILE__);
$sw_zeilen_t = array();
$sw_zeilen_t[] = array(sw_t('TEST.Z_KONF'), sw_pruef_konfiguration(sw_config_zustand()));
$sw_zeilen_t[] = array(sw_t('TEST.Z_FORM'), sw_pruef_formulare($sw_quelle));
preg_match_all('/data-ziel="(tab-[a-z]+)"/', $sw_quelle, $sw_m1);
preg_match_all('/class="sm-seite[^"]*"[^>]*id="(tab-[a-z]+)"/', $sw_quelle, $sw_m2);
/* Dazu (VORLAGE_hausstandard.css.html, 20.08.2026): setzen Leiste UND Bereiche sm-active serverseitig? Die
   zusammengesetzte Klasse macht hausstandard_pruefen.py dort blind - diese Zeile ersetzt die Pruefung. */
$sw_sl = preg_match_all('/class="sm-tab<\?=[^>]*sm-active/', $sw_quelle);
$sw_sb = preg_match_all('/class="sm-seite<\?=[^>]*sm-active/', $sw_quelle);
$sw_zeilen_t[] = array(sw_t('TEST.Z_REITER'), ($sw_m1[1] === $sw_reiter && $sw_m2[1] === $sw_reiter
        && $sw_sl >= count($sw_reiter) && $sw_sb >= count($sw_reiter))
    ? array('ok', sprintf(sw_t('TEST.REITER_OK'), count($sw_reiter)))
    : array('fehl', sprintf(sw_t('TEST.REITER_FEHL'), count($sw_m1[1]), count($sw_m2[1]), count($sw_reiter), $sw_sl, $sw_sb)));
$sw_zeilen_t[] = array(sw_t('TEST.Z_ENDPUNKT'), $sw_offen ? sw_pruef_endpunkt($sw_cfg['token']) : array('hinweis', sw_t('TEST.NUR_OFFEN')));
$sw_zeilen_t[] = array(sw_t('TEST.Z_BROKER'), $sw_offen ? sw_pruef_broker() : array('hinweis', sw_t('TEST.NUR_OFFEN')));
$sw_zeilen_t[] = array(sw_t('TEST.Z_LAUSCHER'), sw_pruef_lauscher());
$sw_zeilen_t[] = array(sw_t('TEST.Z_MELDETAKT'), sw_pruef_meldetakt($sw_stand));
$sw_neu_n = 0;
foreach ($sw_zl as $sw_z) { if ($sw_z['neu']) { $sw_neu_n++; } }
$sw_zeilen_t[] = array(sw_t('TEST.Z_NEU'), $sw_neu_n > 0 ? array('hinweis', sprintf(sw_t('TEST.NEU_DA'), $sw_neu_n))
    : array('ok', sw_t('TEST.NEU_KEINE')));
$sw_alt_t = sw_rueckspiel_altwerte($sw_cfg);
$sw_zeilen_t[] = array(sw_t('TEST.Z_SICHERUNG'), $sw_alt_t ? array('fehl', sprintf(sw_t('TEXT.SICH_ALTWERT'), implode(', ', $sw_alt_t)))
    : array('ok', sw_t('TEST.SICHERUNG_OK')));
$sw_zeilen_t[] = array(sw_t('TEST.Z_HAUS'), sw_pruef_haus($sw_cfg, $sw_offen));
$sw_schlecht = 'ok';
foreach ($sw_zeilen_t as $sw_zt) {
    if ($sw_zt[1][0] === 'fehl') { $sw_schlecht = 'fehl'; }
    elseif ($sw_zt[1][0] === 'hinweis' && $sw_schlecht === 'ok') { $sw_schlecht = 'hinweis'; }
} ?>
<div class="<?= $sw_schlecht === 'ok' ? 'sm-hinweis' : ($sw_schlecht === 'fehl' ? 'sm-fehler' : 'sm-warnung') ?>"><?= sw_e(sw_t('TEST.SUMME_' . strtoupper($sw_schlecht))) ?></div>
<div class="sm-breit">
<table class="sm-tbl">
  <tr><th><?= sw_e(sw_t('TEST.SP_PRUEFUNG')) ?></th><th><?= sw_e(sw_t('TEST.SP_ERGEBNIS')) ?></th><th><?= sw_e(sw_t('TEST.SP_TEXT')) ?></th></tr>
<?php foreach ($sw_zeilen_t as $sw_zt) { ?>
  <tr><td><?= sw_e($sw_zt[0]) ?></td><td class="<?= $sw_zt[1][0] === 'ok' ? 'sm-an' : ($sw_zt[1][0] === 'fehl' ? 'sm-aus' : '') ?>"><?= sw_e(sw_t('TEST.LAGE_' . strtoupper($sw_zt[1][0]))) ?></td><td><?= sw_e($sw_zt[1][1]) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-knopfreihe">
  <a data-role="none" class="sm-btn sm-b-lesen" href="index.php?form=test"><?= sw_e(sw_t('TEST.K_NEU')) ?></a>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?= sw_e(sw_t('LEGENDE.LESEN')) ?></span></div>
</div>

<!-- ================= Logdateien ================= -->
<div class="sm-seite<?= $sw_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= sw_e(sw_t('REITER.LOG')) ?></h2>
<div class="sm-hinweis"><?= sw_e(sw_t('LOG.RAMDISK')) ?></div>
<?php if ($sw_rahmen && method_exists('LBWeb', 'loglist_html')) { echo LBWeb::loglist_html(); } ?>
<p class="sm-hilfe"><?= sw_e(sw_t('LOG.TEXT')) ?> <span class="sm-mono"><?= sw_e(sw_log_datei()) ?></span></p>
<?php $sw_lz = ($sw_tab === 'tab-log') ? sw_log_ende(sw_log_datei(), 200) : array(); ?>
<?php if ($sw_tab !== 'tab-log') { ?>
<p class="sm-grau"><?= sw_e(sw_t('LOG.NUR_OFFEN')) ?></p>
<?php } elseif (!$sw_lz) { ?>
<p class="sm-grau"><?= sw_e(sw_t('LOG.LEER')) ?></p>
<?php } else { ?>
<div class="sm-log"><?= sw_e(implode("\n", $sw_lz)) ?></div>
<?php } ?>
</div>

</div>

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	// Der Server hat sm-active bereits gesetzt; dieser Aufruf richtet nur die
	// versteckten activetab-Felder aus und ist ansonsten wirkungslos.
	zeige(<?= json_encode($sw_tab) ?>);
})();
</script>
<?php
if ($sw_rahmen) {
    LBWeb::lbfooter();
}
