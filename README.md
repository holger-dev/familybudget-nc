# Familybudget Nextcloud App

Eine native Nextcloud-App zur Verwaltung von Familienausgaben mit Multi-User-Support, Kategorien und Einladungsfunktion. Die User können jeder für sich Ausgaben eintragen, die allen eingeladenen Usern ebenfalls sichtbar angezeigt werden. Es gibt eine automatische, monatliche Auswertung inkl. Split, um zu sehen, welcher User wie viel ausgegeben hat und ggf. Geld von anderen Usern erhält oder andersherum. Die App soll sich an die Designregeln von Nextcloud-Vue-Components halten und Schnittstellen haben, damit eine externe, mobile App ebenfalls Daten schreiben und lesen kann.

## Implementierung

- Aktives Git-Repo wird genutzt (dieses Repository).
- Docker-Entwicklungsumgebung für Nextcloud ist enthalten (`docker-compose.yml`).
- Frontend-Scaffold mit Nextcloud Vue Components vorhanden (`@nextcloud/vue`).
- Die App ist als Nextcloud-App-Skelett angelegt (PHP + Templating).

## Projektstruktur (Kurzüberblick)

- `appinfo/` – App-Metadaten, Routen, Asset-Registrierung
- `lib/` – PHP-Code (Controller etc.)
- `templates/` – PHP-Templates (Startpunkt rendert `<div id="familybudget-app">`)
- `src/` – Vue-Quellcode (Vite/Webpack Build; hier Webpack-Config)
- `js/` – Build-Output (wird von Webpack erzeugt)
- `css/` – App-Styles
- `docker-compose.yml` – Nextcloud + MariaDB Dev-Stack
- `Makefile` – nützliche Kommandos (up/down/logs/occ/build/watch)

## Voraussetzungen

- Docker + Docker Compose
- Node.js LTS (z. B. 18+) + npm

## Entwicklung starten

1) Nextcloud-Stack starten

```
make up
```

Danach ist Nextcloud unter `http://localhost:8080` erreichbar. Der Initial-Setup erfolgt automatisch mit:

- Benutzer: `admin`
- Passwort: `admin`

2) Abhängigkeiten für das Frontend installieren (nur einmal lokal):

```
npm install
```

3) Frontend bauen (erzeugt `js/familybudget.js`):

```
npm run build
```

Für Live-Entwicklung kann stattdessen im Watch-Mode gebaut werden:

```
npm run watch
```

4) App in Nextcloud aufrufen

Sobald Nextcloud läuft und das Frontend gebaut ist, kann die App über `http://localhost:8080/apps/familybudget/` geöffnet werden.

## Nützliche Kommandos

- Nextcloud-Logs ansehen: `make logs`
- In den Container springen: `make exec`
- OCC im Container ausführen: `make occ cmd="app:list"`
- Stack stoppen: `make down`

## Design-Richtlinien

Das Frontend folgt den Komponenten aus Nextcloud Vue Components:
https://nextcloud-vue-components.netlify.app/

In `src/App.vue` ist ein Minimalbeispiel mit `NcAppContent`, `NcEmptyContent` und `NcButton` enthalten.

## Nächste Schritte (Backlog)

- Datenmodell für Ausgaben, Kategorien, Einladungen entwerfen (Datenbank-Migrationen, Entities, Mappers)
- REST-Controller/API für das mobile App-Frontend
- State-Management im Frontend (z. B. Pinia) und Axios-Client
- Auth/Permissions (nur eingeladene User sehen Daten)
- Monatsauswertung + Split-Logik

---

Hinweis: Abhängigkeiten werden lokal via `npm ci` installiert. Der Container mountet die App automatisch nach `/var/www/html/custom_apps/familybudget`.
