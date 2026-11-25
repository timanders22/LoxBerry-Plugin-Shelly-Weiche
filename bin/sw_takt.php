<?php
/**
 * Shelly-Weiche - Minutenlauf und Lauscher
 *
 * Aufruf aus cron/cron.01min, jede Minute, als Benutzer loxberry:
 *   php sw_takt.php                ein Minutenlauf: haus/klima senden (nur mit eingeschaltetem Haken) oder
 *                                  nachraeumen, Zaehler; endet nach wenigen Sekunden
 *   php sw_takt.php --lauschen     ein Lauscher bis zur naechsten vollen Minute plus Nachlauf (im Hintergrund
 *                                  gestartet); liest nur
 *   php sw_takt.php --haus-leeren  Deinstallation: die gemerkten haus/klima-Themen mit Ruecklesen abraeumen
 *   php sw_takt.php --selbsttest   prueft Auswertung, Rundung und Namensregel an nachgebildeten Meldungen;
 *                                  fragt keinen Broker, sendet nichts, schreibt nichts
 * Ausgabe nur im Fehlerfall (stderr, die Cron-Zeile haengt sie an log/plugins/<ordner>/cron.err).
 *
 * (c) Shelly-Weiche Plugin Authors - MIT-Lizenz
 */

/* Die Bibliothek liegt im ANDEREN Baum:
     installiert  $LB/bin/plugins/<ordner>/sw_takt.php
                  $LB/webfrontend/html/plugins/<ordner>/sw_lib.php
     im Archiv    <plugin>/bin/sw_takt.php
                  <plugin>/webfrontend/html/sw_lib.php
   Der Cron setzt keine LBP*-Variablen (Regeln/06); sw_paths() findet die Wurzel dann ueber die Suche aufwaerts vom
   Ort der Bibliothek. */
$sw_kandidaten = array();
$sw_html = getenv('LBPHTMLDIR');
if ($sw_html !== false && $sw_html !== '') {
    $sw_kandidaten[] = rtrim($sw_html, '/\\') . '/sw_lib.php';
}
$sw_kandidaten[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/sw_lib.php';
$sw_kandidaten[] = dirname(__DIR__) . '/webfrontend/html/sw_lib.php';
$sw_geladen = '';
foreach ($sw_kandidaten as $sw_k) {
    if (is_file($sw_k)) {
        require_once $sw_k;
        $sw_geladen = $sw_k;
        break;
    }
}
if ($sw_geladen === '' || !function_exists('sw_minutenlauf')) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' sw_takt.php: sw_lib.php nicht gefunden: ' . implode(' | ', $sw_kandidaten) . "\n");
    exit(1);
}

$sw_schalter = isset($argv[1]) ? (string) $argv[1] : '';
if ($sw_schalter !== '' && !in_array($sw_schalter, array('--lauschen', '--haus-leeren', '--selbsttest'), true)) {
    fwrite(STDERR, "sw_takt.php: unbekannter Schalter - erlaubt sind --lauschen, --haus-leeren, --selbsttest.\n");
    exit(2);
}

if ($sw_schalter === '--haus-leeren') {
    exit(sw_haus_leeren_ausgabe());
}

if ($sw_schalter === '--lauschen') {
    $sw_r = sw_lauscher_lauf();
    exit(in_array($sw_r['ende'], array('gelaufen', 'besetzt', 'upgrade', 'nicht_eingerichtet'), true) ? 0 : 1);
}

if ($sw_schalter === '--selbsttest') {
    $sw_fehl = 0;
    $sw_zeile = function ($ok, $text) use (&$sw_fehl) {
        echo ($ok ? '[OK]   ' : '[FEHL] ') . $text . "\n";
        if (!$ok) {
            $sw_fehl++;
        }
    };
    /* Eine nachgebildete NotifyFullStatus (Bauform wie am Geraet gemessen 22.11.2026; Kennung und Praefix erfunden). */
    $sw_voll = '{"src":"shellyhtg3-0a1b2c3d4e5f","dst":"shellyhtg3-kueche/events","method":"NotifyFullStatus",'
        . '"params":{"ts":1795300000.25,"temperature:0":{"id":0,"tC":21.25,"tF":70.3},"humidity:0":{"id":0,"rh":54.5},'
        . '"devicepower:0":{"id":0,"battery":{"V":5.9,"percent":87}}}}';
    $sw_m = sw_meldung_auswerten('shellyhtg3-kueche/events/rpc', $sw_voll);
    $sw_zeile(is_array($sw_m) && $sw_m['praefix'] === 'shellyhtg3-kueche' && $sw_m['modell'] === 'shellyhtg3'
        && $sw_m['werte'] === array('temperatur' => 21.25, 'feuchte' => 54.5) && $sw_m['batterie'] === 87,
        'NotifyFullStatus: Praefix, Modell, Werte, Batterie');
    $sw_zeile(is_array($sw_m) && strpos(json_encode($sw_m), '0a1b2c3d4e5f') === false,
        'die Geraetekennung (MAC) steht in keinem ausgewerteten Feld');
    $sw_zeile(sw_meldung_auswerten('shellyplug-bad/events/rpc',
        '{"method":"NotifyStatus","params":{"ts":1795300000,"switch:0":{"apower":3.2}}}') === null,
        'eine Meldung ohne temperature:0/humidity:0 ist keine Fuehlermeldung');
    $sw_zeile(sw_meldung_auswerten('shellyhtg3-bad/status', $sw_voll) === null, 'nur .../events/rpc wird ausgewertet');
    $sw_n = sw_meldung_auswerten('shellyhtg3-bad/events/rpc',
        '{"method":"NotifyStatus","params":{"ts":1795300100,"temperature:0":{"tC":"21.0"}}}');
    $sw_zeile(is_array($sw_n) && $sw_n['werte'] === array('temperatur' => null), 'Text statt Zahl: geliefert, ohne Aussage');
    $sw_st = array('start' => 1795290000, 'geraete' => array());
    $sw_e1 = sw_meldung_eintragen($sw_st, $sw_m, 1795300001);
    $sw_e2 = sw_meldung_eintragen($sw_st, $sw_m, 1795300002);
    $sw_zeile($sw_e1 === 'neu' && $sw_e2 === 'doppelt' && $sw_st['geraete']['shellyhtg3-kueche']['zahl'] === 1,
        'dieselbe Meldung zweimal (Ueberlappung der Lauscher): einmal gezaehlt');
    list($sw_w, $sw_h) = sw_haus_werte(array(array('praefix' => 'shellyhtg3-kueche', 'name' => 'kueche')), $sw_st,
        1795300060, array());
    $sw_zeile($sw_w === array('haus/klima/kueche/temperatur' => '21.3', 'haus/klima/kueche/feuchte' => '55',
        'haus/klima/kueche/zeit' => '1795300001', 'haus/klima/kueche/anbieter' => 'shellyweiche',
        'haus/klima/kueche/frist' => '16200') && !$sw_h,
        'haus/klima: Rundung (21,25 -> 21.3, 54,5 -> 55), zeit = Empfang, anbieter, frist 16200');
    list($sw_w) = sw_haus_werte(array(array('praefix' => 'shellyhtg3-kueche', 'name' => 'kueche')), $sw_st,
        1795300001 + 3601, array());
    $sw_zeile($sw_w['haus/klima/kueche/temperatur'] === '21.3' && $sw_w['haus/klima/kueche/feuchte'] === '55',
        'nach 3601 s ohne Meldung: noch gueltig (Frist 4 h 30 min, zwei Stunden Meldetakt)');
    list($sw_w) = sw_haus_werte(array(array('praefix' => 'shellyhtg3-kueche', 'name' => 'kueche')), $sw_st,
        1795300001 + 16201, array());
    $sw_zeile($sw_w['haus/klima/kueche/temperatur'] === '-999' && $sw_w['haus/klima/kueche/feuchte'] === '-1'
        && $sw_w['haus/klima/kueche/zeit'] === '1795300001', 'nach 16201 s ohne Meldung: ohne Aussage, zeit bleibt echt');
    $sw_zeile(sw_haus_name("K\u{00FC}che S\u{00FC}d") === 'kueche_sued' && !sw_haus_name_taugt('Bad')
        && !sw_haus_name_taugt('zaehler') && !sw_haus_name_taugt('datum_text') && sw_haus_name_taugt('bad_oben'), 'Namensregel');
    $sw_v = sw_vorbelegung(array('shellyhtg3-Kueche', 'shellyhtg3-0a1b2c3d4e5f', 'shellyhtg3-kueche'), array());
    $sw_zeile($sw_v === array('shellyhtg3-Kueche' => 'kueche', 'shellyhtg3-0a1b2c3d4e5f' => 'shelly_ht_1',
        'shellyhtg3-kueche' => 'kueche_2'), 'Namensvorschlag: Raumteil, keine MAC im Namen, doppelt mit _2');
    echo $sw_fehl ? "SELBSTTEST: $sw_fehl Befund(e)\n" : "SELBSTTEST: in Ordnung\n";
    exit($sw_fehl ? 1 : 0);
}

/* Fehlende Vorgaben einmal in shelly.json schreiben (Regeln/05) - vor dem ersten Lesen der Konfiguration in diesem
   Lauf. Waehrend einer Aktualisierung (Marke) schreibt die Funktion nichts. */
sw_dienst_pflege();     // 0.9.2: vervollstaendigen und Lade-Beanstandungen einmal melden (Regeln/05)
$sw_r = sw_minutenlauf();
exit(in_array($sw_r['ende'], array('gelaufen', 'besetzt', 'schranke', 'upgrade', 'nicht_eingerichtet'), true) ? 0 : 1);
