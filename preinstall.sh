#!/bin/bash
# Shelly-Weiche - preinstall
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# Bauform der Ecowitt-Weiche 0.9.38. Der Installer ruft dieses Skript bei JEDEM Einbau auf, nach dem Aufraeumen der
# alten Fassung und VOR dem Kopieren von Konfiguration und Oberflaeche (sbin/plugininstall.pl, gelesen in
# Geraet/2026-09-05/08_plugininstall.pl: preupgrade :846, purge :874, preinstall :877, config :916, html :1064,
# postinstall :1305).
#
# Eine Aktualisierung erkennt es allein an der Marke data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als
# Erstes anlegt. Dann tut es nichts: was dort gesichert wurde, braucht postinstall.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Liegengebliebene Dateien einer frueheren Installation - Zweitschrift
# (config/plugins/<ordner>.backup.json, mit dem Aktionstoken und den Geraetepraefixen), eine beiseitegelegte
# unlesbare Konfiguration, die gemerkten haus/klima-Themen und der Geraetezustand - gehen nach <name>.alt, gemeldet
# mit genau einer <WARNING>. Die Selbstheilung der Bibliothek liest .alt nie; die Deinstallation raeumt es ab.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-shellyweiche}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.json" \
            "$BASE/config/plugins/$PFOLDER.kaputt.json" \
            "$BASE/data/plugins/$PFOLDER.haus.json" \
            "$BASE/data/plugins/$PFOLDER.geraete.json"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
for A in "$BASE/config/plugins/$PFOLDER.backup.json.alt" "$BASE/config/plugins/$PFOLDER.kaputt.json.alt" \
         "$BASE/data/plugins/$PFOLDER.geraete.json.alt"; do
    [ -f "$A" ] && [ ! -L "$A" ] && chmod 600 "$A" 2>/dev/null
done
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    T="<WARNING> Neuinstallation: Einstellungen einer frueheren Installation (Aktionstoken, Geraete, gemerkte haus/klima-Themen) werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    T="$T Zurueckbehaltene haus/klima-Themen der frueheren Installation bleiben am Broker stehen, bis sie von Hand geleert werden."
    echo "$T"
fi
exit 0
