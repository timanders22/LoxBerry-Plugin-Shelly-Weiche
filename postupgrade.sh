#!/bin/bash
# Shelly-Weiche - postupgrade
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# postinstall.sh laeuft beim Upgrade ohnehin und spielt dort zurueck, was preupgrade.sh gesichert hat (Konfiguration
# nach Inhalt, gemerkte haus/klima-Themen, Geraetezustand). Hier bleibt nur das Aufraeumen von Nebendateien eines
# abgebrochenen Schreibens. Das Protokoll (log/plugins/<ordner>/) loescht purge_installation nicht.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-shellyweiche}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts aufgeraeumt."; exit 0 ;;
esac
rm -f "$BASE/config/plugins/$PFOLDER/shelly.json.tmp."* \
      "$BASE/config/plugins/$PFOLDER.backup.json.tmp."* \
      "$BASE/data/plugins/$PFOLDER/"*.json.tmp.* 2>/dev/null
echo "<OK> postupgrade abgeschlossen."
if [ -s "$BASE/data/plugins/$PFOLDER/geraete.json" ]; then
    echo "<INFO> Erkannte Geraete und ihre letzten Werte sind erhalten."
else
    echo "<INFO> Es gab keinen gesicherten Geraetezustand; die Geraete erscheinen wieder mit ihrer naechsten Meldung."
fi
exit 0
