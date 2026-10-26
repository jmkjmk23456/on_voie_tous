# On Voie Tous als Android-App

**Gleisprognosen für Bahnhöfe in Frankreich, Italien und Spanien, schon bevor das Gleis angezeigt wird.**

### [⬇ App herunterladen (APK, ca. 1 MB)](https://github.com/jmkjmk23456/on_voie_tous/releases/download/android-latest/on-voie-tous.apk)

## Was macht die App?

In vielen französischen Bahnhöfen erscheint das Gleis erst etwa 20 Minuten vor der Abfahrt. Diese App zeigt die offiziellen Abfahrtstafeln der Bahngesellschaften an und ergänzt bei jedem Zug eine Prognose, an welchem Gleis er voraussichtlich abfährt, zum Beispiel:

- **C (80%)** heißt: Gleis C mit 80 % Wahrscheinlichkeit.
- **8 | 10 (46%)** heißt: Die Bahn meldet schon Gleis 8, die Prognose tippt auf Gleis 10.

Das funktioniert auf den Abfahrtstafeln von

- 🇫🇷 **SNCF Gares & Connexions** (Frankreich)
- 🇮🇹 **RFI** (Italien)
- 🇪🇸 **Adif** (Spanien)

![Abfahrten in Nizza mit Prognose (links) und ohne (rechts)](../promo.png)

Die Prognosen kommen aus dem Projekt [On Voie Tous](https://github.com/bovine3dom/on_voie_tous) von bovine3dom. Dort gibt es sie als Erweiterung für Firefox. Chrome auf Android kann keine Erweiterungen, deshalb gibt es hier die App.

## Einrichten

1. Öffne auf dem Android-Handy den Link **[App herunterladen](https://github.com/jmkjmk23456/on_voie_tous/releases/download/android-latest/on-voie-tous.apk)**.
2. Tippe nach dem Download auf die Datei `on-voie-tous.apk` (oder öffne sie unter *Downloads*).
3. Android fragt beim ersten Mal, ob dein Browser Apps installieren darf. Tippe auf **Einstellungen**, schalte **Aus dieser Quelle zulassen** ein und gehe zurück.
4. Tippe auf **Installieren**. Falls Google Play Protect warnt, dass die App unbekannt ist: **Weitere Details → Trotzdem installieren**. Die App kommt nicht aus dem Play Store, deshalb kennt Google sie nicht.
5. Öffne **On Voie Tous** auf dem Startbildschirm.

Die App braucht nur Internet, keine weiteren Berechtigungen und kein Konto.

## Benutzen

1. Wähle auf der Startseite das Land bzw. die Bahn.
2. Suche den Bahnhof und öffne die **Abfahrten**:
   - **SNCF:** Bahnhof suchen, dann „Horaires“ bzw. „Départs“.
   - **RFI:** „Partenze“ auswählen, Bahnhof suchen bzw. in der Liste auswählen, dann „Vai al monitor“.
   - **Adif:** Bahnhofsseite auf adif.es öffnen, oder „Pantallas estaciones“ nutzen.
3. Nach ein paar Sekunden erscheinen die Prognosen in der Gleisspalte, oben steht ein oranger Hinweis.

Zuletzt geöffnete Abfahrtstafeln stehen danach oben auf der Startseite.
Hast du eine Abfahrtstafel in Chrome offen, kannst du sie über **Teilen → On Voie Tous** direkt in der App öffnen.

## Gut zu wissen

- **Keine Prognose zu sehen?** Prognosen gibt es nur für größere Bahnhöfe, für die es ein Modell gibt, und nur für Abfahrten, nicht für Ankünfte. Sonst geh einmal zurück und öffne die Tafel noch einmal.
- **Es bleibt eine Schätzung.** Achte trotzdem auf Anzeigetafeln und Durchsagen am Bahnhof.
- **Daten:** Bahnhof und Zugdaten der geöffneten Tafel gehen an den Prognose-Server des Originalprojekts. Laut dessen Autor wird dort nichts gespeichert.
- **Updates:** Lade die App über denselben Link erneut herunter und installiere sie. Sie ersetzt die alte Version, die Einstellungen bleiben erhalten.
- **Spanien:** Adif sperrt seine Seiten zeitweise für Aufrufe aus dem Ausland.

## Für Entwickler

Die App ist ein WebView, der die Abfahrtstafeln öffnet und [content.user.js](../src/content.user.js) beim Seitenstart einspielt, wie die Firefox-Erweiterung. Auf adif.es laufen die Prognose-Anfragen über eine native Brücke, die die Rolle des Hintergrundskripts der Erweiterung übernimmt.

GitHub Actions baut die APK bei jedem Push, der `android/` oder `src/content.user.js` ändert, und veröffentlicht sie als Release `android-latest`. Lokal, mit Android SDK und Gradle 8.11:

```
cd android
gradle assembleRelease
```

Die APK wird mit einem festen Schlüssel signiert, damit jede neue Version die installierte App aktualisieren kann. Der Schlüssel liegt nicht im Repo: Der Workflow liest ihn aus den Repository-Secrets `ANDROID_KEYSTORE_BASE64` (die PKCS12-Datei, base64) und `ANDROID_KEYSTORE_PASSWORD`, Alias `onvoie`. Lokale Builds ohne diese Secrets werden mit dem Debug-Schlüssel signiert und können eine von GitHub installierte App nicht aktualisieren.
