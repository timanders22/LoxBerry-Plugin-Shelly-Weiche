#!/bin/bash
# Shelly-Weiche - postinstall
#
# Der Installer ruft mit:  <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASE> <TEMPFOLDER>
# $1 ist NICHT der Arbeitsordner, sondern eine Zufallskennung; gearbeitet wird mit $3 (Ordner) und $5 (Wurzel).
# postinstall laeuft IMMER, auch beim Upgrade - alles hier muss mehrfach ausfuehrbar sein. Bauform der
# Ecowitt-Weiche 0.9.38.
# [upgrade_daten] Gemeinsamer Baustein (Werkzeuge/gemeinsam/upgrade_daten.sh): vor allem anderen den Datenordner
# [upgrade_daten] aus der Sicherung von preupgrade.sh zurueckspielen, ohne eine Datei der neuen Installation zu
# [upgrade_daten] ueberschreiben. Ohne Sicherung (Neuinstallation) geschieht still nichts.
if [ -f "$(dirname "$0")/upgrade_daten.sh" ] && . "$(dirname "$0")/upgrade_daten.sh"; then
    upgrade_daten_zurueck "$3" "$5" "$(dirname "$0")" || :
else
    echo "<WARNING> Der Baustein upgrade_daten.sh fehlt neben $(basename "$0") - der Datenordner wurde nicht zurueckgespielt."
fi

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-shellyweiche}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<FAIL> Unzulaessiger Ordnername '$PFOLDER'."; exit 1 ;;
esac

# Upgrade-Marke: nur wenn preupgrade.sh sie angelegt hat, ist dies eine Aktualisierung, und nur dann wird
# zurueckgespielt. Die Marke wird auf jedem Weg aus diesem Skript abgeraeumt, auch bei einem Abbruch.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
UPGRADE=0
[ -f "$MARKE" ] && UPGRADE=1
trap 'rm -f "$MARKE" 2>/dev/null' EXIT

PCONFIG="$BASE/config/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PHTML="$BASE/webfrontend/html/plugins/$PFOLDER"
PBIN="$BASE/bin/plugins/$PFOLDER"

mkdir -p "$PCONFIG" "$PLOG" "$PDATA" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PLOG" 2>/dev/null
# Konfiguration und Datenordner tragen Aktionstoken bzw. Geraetepraefixe - sie gehen niemanden ausser dem Plugin
# etwas an.
chmod 700 "$PCONFIG" "$PDATA" 2>/dev/null

CF="$PCONFIG/shelly.json"
[ -f "$CF" ] || echo '{}' > "$CF"
chmod 600 "$CF" 2>/dev/null

# Zweitschrift zurueckspielen - NUR bei einer Aktualisierung, NUR wenn die Konfiguration keinen Inhalt traegt.
# Inhalt wie sw_hat_inhalt(): ein JSON-Objekt, in dem das Token schon einmal geschrieben wurde.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
mit_inhalt() {   # 0 = ja, 1 = leer, 3 = ohne verwertbaren Stand, 2 = nicht pruefbar (kein php)
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
if [ "$UPGRADE" = 1 ] && [ -f "$BK" ]; then
    mit_inhalt "$CF"; CF_RC=$?
    if [ "$CF_RC" = 1 ] || [ "$CF_RC" = 3 ]; then
        mit_inhalt "$BK"; BK_RC=$?
        if [ "$BK_RC" = 0 ]; then
            if [ -s "$CF" ] && [ "$(tr -d ' \t\r\n' < "$CF")" != '{}' ]; then
                cp -p "$CF" "$CF.kaputt" 2>/dev/null && chmod 600 "$CF.kaputt" 2>/dev/null
            fi
            if cp -p "$BK" "$CF" 2>/dev/null && chmod 600 "$CF" 2>/dev/null; then
                echo "<OK> Konfiguration aus der Zweitschrift wiederhergestellt."
            else
                echo "<WARNING> Die Zweitschrift liess sich nicht zurueckspielen: $BK"
            fi
        else
            echo "<WARNING> Die Zweitschrift traegt keinen verwertbaren Stand - nichts zurueckgespielt: $BK"
        fi
    elif [ "$CF_RC" = 2 ]; then
        echo "<WARNING> Der Inhalt von $CF liess sich nicht pruefen (fehlt php?). Nichts zurueckgespielt; die Zweitschrift liegt weiter unter $BK"
    fi
fi

# Was preupgrade.sh neben den Datenordner gelegt hat, kommt nur bei Marke zurueck; bei einer Neuinstallation hat
# preinstall.sh Liegengebliebenes als .alt beiseitegelegt.
for PAAR in "haus.json:Gemerkte haus/klima-Themen" "geraete.json:Geraetezustand"; do
    DATEI="${PAAR%%:*}"
    WAS="${PAAR#*:}"
    Z="$BASE/data/plugins/$PFOLDER.$DATEI"
    if [ "$UPGRADE" = 1 ] && [ -f "$Z" ]; then
        if mv -f "$Z" "$PDATA/$DATEI" 2>/dev/null && chmod 600 "$PDATA/$DATEI" 2>/dev/null; then
            echo "<OK> $WAS aus der Sicherung uebernommen."
        else
            echo "<WARNING> $WAS liessen sich nicht zurueckspielen: $Z"
        fi
    fi
done
KAP="$BASE/config/plugins/$PFOLDER.kaputt.json"
if [ "$UPGRADE" = 1 ] && [ -f "$KAP" ]; then
    if mv -f "$KAP" "$CF.kaputt" 2>/dev/null && chmod 600 "$CF.kaputt" 2>/dev/null; then
        echo "<WARNING> Die unlesbare Konfiguration von vor dem Update liegt als $CF.kaputt."
    else
        echo "<WARNING> Die unlesbare Konfiguration von vor dem Update liegt weiter unter $KAP."
    fi
fi

if ! command -v php >/dev/null 2>&1; then
    echo "<FAIL> PHP wurde nicht gefunden. Ohne PHP laufen weder Oberflaeche noch Lauscher."
    exit 1
fi

# Laeuft der Unterbau ueberhaupt? Die Bibliothek muss sich laden lassen UND denselben Konfigurationspfad errechnen,
# den dieses Skript eben angelegt hat. Dazu der Selbsttest des Takts (fragt keinen Broker, sendet nichts, schreibt
# nichts). Den Minutenlauf selbst startet der Cron in der naechsten Minute.
if [ -f "$PHTML/sw_lib.php" ]; then
    AUS=$(SWLIB="$PHTML/sw_lib.php" LBHOMEDIR="$BASE" LBPPLUGINDIR="$PFOLDER" php -r 'require getenv("SWLIB"); $p = sw_paths(); echo $p["cfgdatei"];' 2>&1)
    RC=$?
    if [ $RC -ne 0 ]; then
        echo "<FAIL> Der Unterbau laesst sich nicht laden:"
        echo "$AUS" | tail -10 | sed 's/^/<FAIL> /'
    elif [ "$AUS" != "$CF" ]; then
        echo "<FAIL> Der Unterbau sucht seine Konfiguration an der falschen Stelle (erwartet $CF, errechnet $AUS)."
    else
        echo "<OK> Der Unterbau laedt und findet $AUS"
    fi
else
    echo "<INFO> sw_lib.php wurde unter $PHTML nicht gefunden - der Selbsttest entfaellt."
fi
if [ -f "$PBIN/sw_takt.php" ]; then
    ST=$(LBHOMEDIR="$BASE" LBPPLUGINDIR="$PFOLDER" php "$PBIN/sw_takt.php" --selbsttest 2>&1 < /dev/null)
    RC=$?
    if [ $RC -eq 0 ]; then
        echo "<OK> $(echo "$ST" | tail -1)"
    else
        echo "<FAIL> Selbsttest des Takts mit Rueckgabewert $RC:"
        echo "$ST" | grep -v '^\[OK\]' | tail -10 | sed 's/^/<FAIL> /'
    fi
fi

if id loxberry >/dev/null 2>&1; then
    chown -R loxberry:loxberry "$PCONFIG" "$PLOG" "$PDATA" 2>/dev/null
    [ -f "$BK" ] && chown loxberry:loxberry "$BK" 2>/dev/null
fi

echo "<OK> Installation abgeschlossen."
echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche. Bis sie einmal geoeffnet ist, ruhen Lauscher und"
echo "<INFO> Minutenlauf - erst dann entsteht das Aktionstoken."
echo "<INFO>  1. Reiter Einstellungen: nach der ersten Meldung eines Fuehlers erscheint er in der Geraeteliste."
echo "<INFO>  2. Reiter MQTT: haus/klima einschalten (ab Werk aus), je Geraet Haken und Namen waehlen, speichern."
echo "<INFO>  3. Reiter Test: zeigt, ob Lauscher und Broker tragen und ob haus/klima am Broker ankommt."
exit 0
