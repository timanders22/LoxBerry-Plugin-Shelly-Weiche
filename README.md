# Shelly-Weiche – LoxBerry-Plugin

Fassung 0.9.2.

Shelly H&T Gen3 (Batteriefühler für Temperatur und Feuchte) melden sich mit eigenem MQTT am Broker des LoxBerry. Ihre Messwerte kommen als JSON-RPC-Benachrichtigung unter `<präfix>/events/rpc`, **nicht zurückbehalten** und nur in den kurzen Augenblicken, in denen das Gerät aufwacht. Wer später fragt, bekommt nichts; zurückbehalten ist nur `<präfix>/online`, und das steht im Schlaf auf `false`.

Die Shelly-Weiche hört diese Meldungen mit, merkt sich je Gerät die letzten Werte und gibt sie – nur wenn Sie es einschalten, ab Werk aus – unter `haus/klima/<name>/…` an andere Plugins weiter (Raumklima, Sprachsteuerung, SignalBot). Dem Miniserver liefert sie nichts; das MQTT-Gateway abonniert die Themen der Geräte weiter wie bisher.

## Neu in 0.9.2

- **Gespeicherte Einstellungen, die beim Laden nicht gelten (Entscheidung des Hausherrn 23./24.11.2026, Regeln/05 „Lade-Beanstandungen“):** Trägt die Datei `shelly.json` einen ungültigen Wert (von Hand bearbeitet oder aus einer alten Fassung), arbeitet die Weiche weiter mit der Vorgabe, sagt es aber jetzt: ein gelber Kasten über den Reitern nennt jedes Feld mit gespeichertem und verwendetem Wert, der Reiter *Test* zeigt die Zeile „Gelten die gespeicherten Einstellungen beim Laden so, wie sie gespeichert sind?“ rot, und das Protokoll nennt die Namen einmal je Änderung. Bis 0.9.1 geschah das still – eine ungültige Geräteliste galt im Betrieb als leer, ein ungültiges Token als leer (jede Adresse im Miniserver wurde abgewiesen) –, und die Zeile „Werte, die das Zurückspielen abweisen würde“ sah es nicht, weil sie den schon bereinigten Stand prüfte. Jetzt bleibt die Datei, wie sie ist: Speichern behält bei einem genannten Feld, das Sie nicht ändern, den gespeicherten Wert; die angezeigten Werte übernimmt nur der Knopf „Angezeigte Werte übernehmen“ mit gesetztem Haken. Ein vorhandenes Token bleibt in Gebrauch – auch ein formwidriges, und das Formular „Einstellungen“ lässt sich mit ihm unverändert speichern; abgewiesen wird nur ein geänderter Wert. Der Minutentakt vervollständigt die Datei auch mit einem formwidrigen Token. Das Token erscheint in keiner Meldung mit seinem Wert, das Protokoll nennt keinen Wert. Gemeinsames Sicherungsmodul 1.3.0.
- **Statusmeldungen an SignalBot (Entscheidung des Hausherrn 24.11.2026), ab Werk aus:** Im Reiter MQTT gibt es den Block „Meldungen an SignalBot“. Eingeschaltet meldet die Weiche unter `haus/meldung/shellyweiche` an das Plugin SignalBot (gemeinsames Modul `meldung.php`), wenn der MQTT-Broker nach einem Ausfall wieder erreichbar ist, mit der Dauer des Ausfalls (Anlass `broker_wieder`). Den Ausfall selbst kann sie nicht melden: ohne Broker erreicht keine Nachricht SignalBot. Erkannt wird der Ausfall am Lauscher, der sich jede Minute am Broker anmeldet. Dieselbe Meldung höchstens einmal in der eingestellten Sperrzeit (ab Werk 60 Minuten), insgesamt höchstens 10 je Stunde. Nötig ist SignalBot ab 0.9.44 mit „Meldungen unter haus/meldung annehmen“; der Knopf „Testmeldung senden“ prüft den Weg.

## Neu in 0.9.1

- **Berichtigt – reservierter Name `datum_text`:** Ein Gerät mit dem Namen `datum_text` wird jetzt wie `zaehler` oder `heute` abgewiesen: beim Speichern beanstandet, im Reiter Test gemeldet und nicht gesendet. Weatherdata sendet seit 0.9.13 das Thema `haus/wetter/datum_text`; der Name fehlte in der Liste der reservierten Namen (Regeln/07).
- Reiter Test: Die Selbstprüfung der Namensregel prüft auch `datum_text`.

## Was das Plugin tut

- **Erkennen an der Meldung, nicht am Namen.** Ein Gerät gilt als Fühler, wenn eine Benachrichtigung `NotifyFullStatus` oder `NotifyStatus` unter `<präfix>/events/rpc` die Komponente `temperature:0` (`tC`) oder `humidity:0` (`rh`) trägt. Das Präfix stellen Sie in der Shelly-App ein (etwa `shellyhtg3-Kueche` oder `shellyhtg3-Bad`); es wird nie fest verdrahtet. Abonniert werden `+/events/rpc`, `+/+/events/rpc`, wahlweise ein weiteres Abo für tiefere Präfixe, und je bekanntem Gerät `<präfix>/online`.
- **Keine Gerätekennung.** Die Kennung `src` (sie enthält die MAC-Adresse) wird nie gespeichert, nur der Modellteil davor (`shellyhtg3`). Rohmeldungen werden nicht abgelegt.
- **Ohne Dauerdienst, ohne Lücke.** Jede Minute startet der Cron einen Lauscher, der bis zur nächsten vollen Minute plus acht Sekunden abonniert bleibt. Zwei Plätze mit je einer Sperre sorgen dafür, dass der nächste Lauscher anläuft, bevor der vorige endet; eine in der Überlappung doppelt empfangene Meldung wird am Zeitstempel des Geräts erkannt und einmal gezählt. Jeder Prozess endet nach spätestens 75 Sekunden.
- **haus/klima nach der Hausregel** (ab Werk aus, Reiter MQTT, je Gerät Haken und Name):

  | Thema | Bedeutung | zurückbehalten |
  |---|---|---|
  | `haus/klima/<name>/temperatur` | °C, eine Nachkommastelle; ohne Aussage `-999` | ja |
  | `haus/klima/<name>/feuchte` | %, ganz; ohne Aussage `-1` | ja |
  | `haus/klima/<name>/zeit` | Unixzeit der letzten Meldung des Geräts (Empfang am LoxBerry); `-1` unbekannt | ja |
  | `haus/klima/<name>/anbieter` | `shellyweiche` | ja |
  | `haus/klima/<name>/frist` | Gültigkeitsfrist in Sekunden: `16200` (4 h 30 min) | ja |
  | `haus/klima/zaehler/shellyweiche` | Lebenszeichen 0…999 je Minute | nie |

  Gesendet wird über eine kurze Sitzung direkt am Broker (QoS 0): Felder bei Änderung, alle 30 Minuten der volle Satz, `zeit` nur zusammen mit einem Feld desselben Namens. Liegt die letzte Meldung eines Feldes über 4 h 30 min zurück, geht es ohne Aussage hinaus; `zeit` bleibt der echte Zeitpunkt. `frist` geht wie `anbieter` bei Änderung und mit dem vollen Satz. Ein Name ohne jede Messung seit dem Start wird 4 h 30 min lang nicht gesendet – der zurückbehaltene Wert trägt seine eigene `zeit`. Ein Name, den ein anderes Plugin schon hält (`…/anbieter` mit fremdem Namen oder ein fremder zurückbehaltener Wert), wird nicht überschrieben; der Reiter Test nennt den Konflikt. Ausschalten, Abwählen, Umbenennen und Deinstallieren räumen die selbst gesendeten Themen mit Rücklesen ab – nie `haus/#`, nie ein fremdes Thema.
- **Namensregel:** klein, nur `a–z`, `0–9`, `_` und `-`, höchstens 40 Zeichen, ä→ae, ö→oe, ü→ue, ß→ss; `zaehler`, `anbieter` und die Wörter des Wetterdienstes sind vergeben. Ein Name, den die Regel ändern würde, wird abgewiesen und mit der passenden Schreibweise genannt; zwei eingeschaltete Geräte dürfen nicht denselben Namen tragen. Vorbelegt ist der Raumteil des Präfixes; sieht er aus wie eine Gerätekennung, steht dort `shelly_ht_<n>`.

## Gültigkeit und Meldetakt

Ein Abnehmer verwendet einen Wert nur, solange `zeit` jünger als `frist` ist (hier 16200 s = 4 h 30 min; ohne `frist` gilt nach der Regel des Hauses eine Stunde) und sich der Zähler binnen 120 Sekunden ändert. Gemessen am 22.11.2026 an zwölf H&T Gen3 (Firmware 2.0.3): Sie melden alle zwei Stunden und zusätzlich, wenn die Temperatur um 0,5 °C oder die Feuchte um 5 % springt (am Gerät einstellbar, die Temperaturschwelle steht schon am Minimum). Einzelne Zwei-Stunden-Termine fallen aus, der größte Abstand war 241 Minuten; die Frist übersteht einen ausgefallenen Termin, zwei nicht. Abnehmer, die `frist` auswerten: Raumklima ab 0.11.43. Ein Abnehmer, der `frist` nicht kennt, verwirft die Werte schon nach einer Stunde. Der Reiter Test zeigt je Gerät, wie lange die letzte Meldung zurückliegt, und warnt, wenn ein Gerät über 4 h 30 min still war; seine Werte gehen dann ohne Aussage hinaus.

## Reiter

- **Einstellungen:** erkannte Geräte (Präfix, Modell, letzte Meldung, Werte, Batterie, online, Zahl der Meldungen, letzter Abstand), Aktionstoken, Einstellungen sichern und zurückspielen.
- **MQTT:** Haken „Raumklima unter haus/klima/ melden“, je Gerät Haken, Name und „vergessen“, weiteres Abo, Zustand des MQTT-Gateways, Tabelle der gesendeten Themen.
- **Einbindung in Loxone:** welche Themen Sie im MQTT-Gateway abonnieren können, um die Werte auch in Loxone zu sehen, mit den Titeln der Virtuellen Eingänge, und die Adressen des Endpunkts mit Token.
- **Test:** Konfiguration, Formularschutz, Reiter, Endpunkt (über 127.0.0.1), Anmeldung am Broker, Lauscher, Meldetakt, neu erkannte Geräte, Sicherung, und haus/klima mit Rücklesen am Broker.
- **Logdateien:** das Protokoll der Weiche (RAM-Scheibe, übersteht keinen Neustart).

## Endpunkt

`/plugins/shellyweiche/index.php` nimmt nur zwei Aufrufe an, beide mit Aktionstoken:

- `?selftest=1&token=<T>` – prüft nur das Token: `SELFTEST;OK=1;TOKEN=OK`, sonst HTTP 403 mit `ERR=TOKEN` oder `ERR=KEIN_TOKEN_EINGERICHTET`.
- `?aktion=voll&token=<T>` – der nächste Minutenlauf sendet haus/klima vollständig: `AKTION;OK=1;AKTION=voll`; unbekannte Aktion oder unerlaubte Zeichen HTTP 400.

Ohne Parameter antwortet er mit HTTP 403. Jeder Aufruf hinterlässt eine Protokollzeile mit der Adresse des Anrufers, nie das Token.

## Sichern und Zurückspielen

Über das gemeinsame Sicherungsmodul der Plugins dieses Hauses. Die Sicherung trägt das Aktionstoken, den Haken, das weitere Abo und die Geräteliste mit Präfixen, Namen und Haken – wie ein Passwort behandeln. Eine fremde oder halb gültige Datei ändert gar nichts.

## Voraussetzungen

LoxBerry 3.0 oder neuer mit dem eingebauten MQTT-Broker; Shelly-Geräte mit eingeschaltetem MQTT und diesem LoxBerry als Server. Das Plugin ist reines PHP (7.4 und 8.x).

## Lizenz

MIT.
