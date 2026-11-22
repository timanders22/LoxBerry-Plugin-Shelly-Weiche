#!/bin/bash
# Shelly-Weiche - preupgrade
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Reihenfolge des Installers beim Upgrade (sbin/plugininstall.pl, gelesen in Geraet/2026-09-05/08_plugininstall.pl):
#   preupgrade :846 -> purge_installation :874 (loescht config/plugins/<ordner>/ UND data/plugins/<ordner>/)
#   -> preinstall :877 -> config :916 -> html :1064 -> postinstall :1305 -> postupgrade :1330
# Was das Upgrade ueberleben soll, muss also HIER gerettet werden, vor dem Loeschen, und zwar NEBEN die Ordner (Name
# mit Punkt) - nicht nach /tmp. Bauform der Ecowitt-Weiche 0.9.38.
#
# Gesichert wird, was der Code schreibt (CLAUDE.md 3), nicht was das Archiv mitbringt:
#   config/plugins/<ordner>/shelly.json   -> <ordner>.backup.json  (Aktionstoken, Haken, Geraete; nach INHALT)
#   data/plugins/<ordner>/haus.json       -> <ordner>.haus.json     (die gemerkten haus/klima-Themen: Merker "war
#                                                                    Anbieter" und Liste zum Abraeumen)
#   data/plugins/<ordner>/geraete.json    -> <ordner>.geraete.json  (erkannte Geraete und ihre letzten Werte; ohne
#                                                                    sie gaelte nach dem Update die Startsperre)
# Die uebrigen Dateien des Datenordners (Lauscher-, Takt- und Sitzungsstand, Sperren, Merkwort, Einmalmeldung)
# entstehen beim naechsten Lauf neu; haus_lauf.json fehlt danach, und der naechste Minutenlauf sendet den vollen Satz.
#
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige Zufallskennung. $3 ist der Ordnername, $5 die
# LoxBerry-Wurzel (Regeln/06). Gearbeitet wird ausschliesslich mit $3 und $5.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-shellyweiche}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts gesichert."; exit 2 ;;
esac

# Ohne Wurzel wird nichts gesichert; Rueckgabewert 2, sonst liefe der Purge weiter.
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<FAIL> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts gesichert, das Update wird abgebrochen."
    exit 2
fi
# Als Erstes die Upgrade-Marke mit der Unixzeit. preinstall.sh und postinstall.sh erkennen eine Aktualisierung
# allein an dieser Datei; Minutenlauf und Lauscher ruhen, solange sie juenger als eine Stunde ist.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if date +%s > "$MARKE" 2>/dev/null; then
    echo "<OK> Upgrade-Marke gesetzt: $MARKE"
else
    rm -f "$MARKE" 2>/dev/null
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen - die Aktualisierung wird abgebrochen,"
    echo "<FAIL> die bisherige Fassung bleibt unveraendert installiert."
    exit 2
fi
# Update-Schutz: plugininstall.pl bricht nur bei Rueckgabewert >1 vor purge_installation ab. Die Kopie entsteht als
# <Ziel>.neu.<pid>, traegt 0600, BEVOR Inhalt hineinkommt, wird mit cmp verglichen und erst dann per mv an ihren
# Platz gestellt: eine heile Sicherung bleibt heil.
ZS_FEHL=""
zs_hinlegen() {   # $1 Quelle, $2 Ziel
    [ -d "$2" ] && return 1
    zs_neu="$2.neu.$$"
    rm -f "$zs_neu" 2>/dev/null
    if { : > "$zs_neu"; } 2>/dev/null && chmod 600 "$zs_neu" 2>/dev/null \
       && cp "$1" "$zs_neu" 2>/dev/null && cmp -s "$1" "$zs_neu" \
       && mv -f "$zs_neu" "$2" 2>/dev/null; then
        return 0
    fi
    rm -f "$zs_neu" 2>/dev/null
    return 1
}
zs_schluss() {   # Fehlschlag gesammelt: Marke weg, Abbruch vor purge_installation
    [ -n "$ZS_FEHL" ] || return 0
    echo "<FAIL> Nicht gesichert:$ZS_FEHL - die Aktualisierung wird abgebrochen, damit der Purge die Originale nicht loescht."
    echo "<FAIL> Die bisherige Fassung bleibt installiert. Bitte Platz und Rechte unter config/plugins und data/plugins pruefen."
    rm -f "$MARKE" 2>/dev/null
    exit 2
}

CF="$BASE/config/plugins/$PFOLDER/shelly.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
KAP="$BASE/config/plugins/$PFOLDER.kaputt.json"

for PAAR in "haus.json:gemerkte haus/klima-Themen" "geraete.json:Geraetezustand"; do
    DATEI="${PAAR%%:*}"
    WAS="${PAAR#*:}"
    Q="$BASE/data/plugins/$PFOLDER/$DATEI"
    Z="$BASE/data/plugins/$PFOLDER.$DATEI"
    rm -f "$Z" "$Z.neu."* 2>/dev/null
    if [ -s "$Q" ]; then
        if zs_hinlegen "$Q" "$Z"; then
            echo "<OK> $WAS gesichert: $Z"
        else
            rm -f "$Z.neu.$$" 2>/dev/null
            echo "<WARNING> $WAS liessen sich nicht sichern."
            ZS_FEHL="$ZS_FEHL $DATEI"
        fi
    else
        echo "<INFO> $WAS: nichts zu sichern."
    fi
done

# Die Konfiguration wird nach INHALT gesichert, nie direkt ueber die alte Zweitschrift: Inhalt heisst dasselbe wie
# in sw_hat_inhalt() - ein JSON-Objekt, in dem das Token schon einmal geschrieben wurde. Rueckgabe 0 = ja, 1 = leer
# (fehlt, nur Leerraum oder "{}"), 3 = etwas steht darin, aber kein verwertbarer Stand, 2 = nicht pruefbar (kein php).
mit_inhalt() {
    [ -s "$1" ] || return 1
    php -r '$r = (string) @file_get_contents($argv[1]); $d = json_decode($r, true);
        if (is_array($d) && array_key_exists("token", $d) && is_string($d["token"])) { exit(0); }
        exit((trim($r) === "" || (is_array($d) && count($d) === 0)) ? 1 : 3);' -- "$1" 2>/dev/null
    RC=$?
    case "$RC" in
        0|1|3) return "$RC" ;;
    esac
    return 2
}

mit_inhalt "$CF"
case "$?" in
0)
    if zs_hinlegen "$CF" "$BK"; then
        echo "<OK> Konfiguration gesichert: $BK"
    else
        echo "<WARNING> Die Konfiguration liess sich nicht sichern. Die vorhandene Zweitschrift bleibt unveraendert: $BK"
        ZS_FEHL="$ZS_FEHL shelly.json"
    fi
    ;;
1)
    if [ -f "$BK" ]; then
        echo "<WARNING> shelly.json traegt keinen verwertbaren Stand. Die vorhandene Zweitschrift bleibt unveraendert;"
        echo "<WARNING> aus ihr holt postinstall.sh die Einstellungen zurueck: $BK"
    else
        echo "<INFO> Keine Einstellungen vorhanden - es gibt nichts zu sichern."
    fi
    ;;
3)
    if [ -f "$BK" ]; then
        echo "<WARNING> shelly.json ist unlesbar oder traegt kein Token. Die vorhandene Zweitschrift bleibt unveraendert;"
        echo "<WARNING> aus ihr holt postinstall.sh die Einstellungen zurueck: $BK"
    else
        rm -f "$KAP.neu."* 2>/dev/null
        if zs_hinlegen "$CF" "$KAP"; then
            echo "<WARNING> shelly.json ist unlesbar oder traegt kein Token, und es gibt keine Zweitschrift. Der Stand ist"
            echo "<WARNING> NICHT verloren: er liegt als $KAP und nach dem Update als shelly.json.kaputt im Konfigurationsordner."
        else
            rm -f "$KAP.neu.$$" 2>/dev/null
            echo "<WARNING> shelly.json ist unlesbar, es gibt keine Zweitschrift, und der Stand liess sich nicht beiseitelegen: $CF"
            ZS_FEHL="$ZS_FEHL shelly.json"
        fi
    fi
    ;;
*)
    echo "<WARNING> Der Inhalt von $CF liess sich nicht pruefen (fehlt php?). Die Zweitschrift bleibt unveraendert: $BK"
    ;;
esac
zs_schluss
echo "<OK> preupgrade abgeschlossen."
exit 0
