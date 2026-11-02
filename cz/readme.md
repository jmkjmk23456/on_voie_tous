# Tschechien: Daten sammeln

An großen tschechischen Bahnhöfen wie Praha hl.n., Brno hl.n. oder Olomouc hl.n. steht der Bahnsteig oft erst 10–25 Minuten vor der Abfahrt fest. Bevor es dafür eine Prognose geben kann, braucht es Trainingsdaten. Die sammelt [collector/](collector).

## Was gesammelt wird

Alle 3 Minuten, für die Bahnhöfe in [stations.php](collector/stations.php):

- **Abfahrtstafel der Správa železnic** (provoz.spravazeleznic.cz/Tabule): Zug, Linie, Ziel, Verspätung und der angezeigte **Bahnsteig samt Abschnitt**, z. B. `6J`, `7S`, `6` (ganzer Bahnsteig) oder `3 / 6` (Bahnsteig / Gleis). Das ist das Ziel, das das Modell lernen soll.
- **Transitous** (`/api/v5/stoptimes`): das **geplante Gleis** (*kolej*) aus den CZPTT-Fahrplandaten, Verspätung und in Prag zusätzlich der Bahnsteig aus den PID-Daten (Haltestelle `cz-PID_U142V4J` = Bahnsteig 4, Abschnitt J). Das sind die Merkmale, die schon lange vor der Abfahrt bekannt sind.

Jede Tafel wird nur gespeichert, wenn sie sich seit dem letzten Abruf geändert hat, als eine JSON-Zeile in `data/JJJJ-MM-TT.jsonl.gz`.

Später werden daraus zwei getrennte Prognosen: **Bahnsteig** (z. B. „6 (85 %)“) und **Abschnitt** J/S/ganz (z. B. „J (70 %)“).

## Einrichten

Der Workflow [cz-collector.yml](../.github/workflows/cz-collector.yml) lädt `collector/` bei jedem Push per FTPS in den Ordner `gleisprognose-cz/` des Webspace. Dafür braucht das Repository diese Secrets:

| Secret | Inhalt |
|---|---|
| `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` | FTP-Zugang des Webspace |
| `CZ_COLLECTOR_TOKEN` | langes Zufallswort, schützt `collect.php` und `export.php` |

Danach eine geplante Aufgabe anlegen, die alle 3 Minuten (`*/3 * * * *`) diese Adresse abruft:

```
https://<webspace>/gleisprognose-cz/collect.php?token=<CZ_COLLECTOR_TOKEN>
```

Die gesammelten Daten listet `export.php?token=…` auf, `export.php?token=…&day=2026-11-03` lädt einen Tag herunter.

## Datenquellen

- Transitous: Fahrplan- und Echtzeitdaten unter offenen Lizenzen (CZPTT: CC0).
- Správa železnic: Die Tafel ist öffentlich, ihre Nutzung aber laut Fußzeile nur mit Zustimmung von SŽ erlaubt. Die Daten werden hier nur zum Trainieren gesammelt und nicht weitergegeben.
