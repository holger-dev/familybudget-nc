# FamilyBudget – DEV Notes

Version: 0.2.4

Dieses Dokument sammelt den aktuellen Stand der App, Dev‑Workflow und die geplante Roadmap, damit zukünftige Sessions schnell andocken können.

## Überblick

FamilyBudget ist eine Nextcloud‑App zur Verwaltung gemeinsamer Ausgaben mit Buch‑Konzept, Mitgliederverwaltung und mobiler OCS‑API.

Backend:
- Nextcloud AppFramework (PHP 8.1+), OCS‑API für externe Clients
- DB‑Tabellen: `fc_books`, `fc_book_members`, `fc_expenses`
- Controller: `BookController`, `ExpenseController`, `OcsApiController`

Frontend:
- Vue‑basierte UI (Webpack Build nach `js/` und `css/`)

## Aktueller Funktionsumfang (0.2.1)

- Bücher
  - Listen, Erstellen, Umbenennen (PUT/PATCH/POST rename), Löschen
  - Mitglieder listen, einladen, entfernen (Owner‑Regeln)
- Ausgaben
  - CRUD je Buch; Speicherung in `amount_cents` (integer), Datum `occurred_at`
  - Serverseitige Filter für Abfragen:
    - Monatsliste: `month=YYYY-MM` (wiederholbar) oder `months=YYYY-MM,YYYY-MM,...`
    - Zeitraum: `from=YYYY-MM` und optional `to=YYYY-MM` (Priorität vor Monatsliste)
- API
  - OCS‑Endpunkte unter `ocs/v2.php/apps/familybudget/...` für alle Operationen (Books + Expenses)
  - Header `OCS-APIRequest: true` und Basic Auth (App‑Passwort) unterstützt
  - App‑Routen für die Web‑UI (GETs CSRF‑frei), Schreibzugriffe via OCS
- Doku/Tests
  - `docs/API.md` inkl. Flutter/Dio‑Beispielservice
  - `scripts/ocs_smoke.php` als End‑to‑End Test (OCS) inkl. Filterbeispiele

## Dev‑Workflow

Voraussetzungen:
- Node.js (für Frontend‑Build), PHP 8.1+, Nextcloud 27–31

Docker‑Setup (empfohlen):
- `make up` – Nextcloud starten
- `make exec` – Shell im Container
- `make occ cmd='status'` – OCC Befehle ausführen
- `make build` / `make watch` – UI bauen/entwickeln

Bare‑metal (alternativ):
- App nach `custom_apps/familybudget` legen
- OCC ausführen: `sudo -u www-data php occ ...`

Build/Assets:
- `npm install && npm run build` erzeugt `js/familybudget.js` und CSS

Datenbank/Migrationen:
- Erste Migration vorhanden: `lib/Migration/Version0001Date202409030001.php`
- Schema: Bücher, Mitglieder, Ausgaben mit Zeitstempeln

## Release‑Prozess

1) Version anheben
- `appinfo/info.xml` `<version>` und `package.json` `version`
- Version im `docs/API.md` anpassen

2) Funktion prüfen
- `php scripts/ocs_smoke.php https://<cloud>/ocs/v2.php/apps/familybudget USER APP-PASS`

3) Paket erstellen (Beispiel ZIP)
- `git archive --format=zip -o familybudget-<VERSION>.zip HEAD`

4) Nextcloud Store Upload / Deployment
- Store‑Upload der ZIP/TGZ
- Lokales Deployment: Code nach `custom_apps/familybudget`, dann `occ app:enable familybudget && occ upgrade`

## Architektur‑Notizen

- OCSController liefert JSON‑Payload ohne OCS‑XML‑Wrapper
- Fehlercodes: 200/201 OK; 400 Bad Request; 401 Unauthorized; 403 Forbidden; 404 Not Found; 500 Internal Error
- Berechtigungen: Nur Mitglieder eines Buchs sehen/ändern dessen Ausgaben; Owner‑Pfad für kritische Buch‑Operationen
- Beträge: Client sendet `amount` (Double), Backend speichert `amount_cents` (int)
- Datum: Client sendet `date` (`YYYY-MM-DD`), Backend speichert `occurred_at` (`YYYY-MM-DD 00:00:00`)

## Roadmap / TODO

Kurzfristig
- Statistik‑Auswertung
  - Aggregationen je Monat/Buch/Benutzer (Summen, Anteile, Trends)
  - OCS‑Endpoints für Statistiken (z. B. `/stats?from=YYYY-MM&to=YYYY-MM`)
  - UI‑Charts (konservativ: serverseitige Aggregation, clientseitige Darstellung)
- CSV‑Export
  - OCS‑Endpoint für CSV‑Export pro Buch mit Filtern (`month`, `months`, `from`/`to`)
  - Optional: Spaltenset konfigurierbar (Beschreibung, Nutzer, Betrag, Datum, Währung)

Mittelfristig
- Pagination/Limitierung für Expenses‑Listen (z. B. `limit`/`offset`), optional `X-Total-Count`
- Kategorien/Tags für Ausgaben + Filter
- Verbesserte Validierung und einheitliche Fehler‑Payloads (Fehlercode + message + field)
- i18n für Web‑UI und API‑Fehlermeldungen
- Caching von Statistik‑Aggregaten

Qualität/Sicherheit
- Code‑Check: `occ app:check-code familybudget` (statisch)
- E2E‑Tests für OCS (optional via PHPUnit oder einfache PHP‑Skripte)
- Permissions‑Review (Owner/Member‑Grenzen, Einladungen)

## Referenzen

- API: `docs/API.md`
- Smoketest: `scripts/ocs_smoke.php`
- Makefile‑Targets: `make up|down|exec|occ|build|watch`
