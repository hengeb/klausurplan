# Klausurplan – Projektanweisungen für Claude Code

Klausurtermine und Anwesenheit für die Oberstufe eines deutschen Gymnasiums. Läuft als
**LTI-1.3-Tool in Moodle** (Login nur über Moodle), PHP + MariaDB, Vanilla-JS-Frontend,
DSGVO-konform (kein CDN, keine Analytics). Installation/Update für Menschen: [README.md](README.md).

## Randbedingungen (nicht verhandelbar)

- **PHP 8.5**, `declare(strict_types=1)` überall, Rückgabe-/Parametertypen konsequent.
- **MariaDB**, PDO mit **Prepared Statements immer** (nie Strings konkatenieren). SQL soll auch auf MySQL 8 laufen.
- **Kein CDN, keine externen Requests aus dem Browser.** Alle Assets liegen lokal in `public/assets/`.
- **Server: kein SSH, keine CLI, kein Docker.** Alles Administrative läuft über Web-Seiten
  (`public/setup.php`) oder wird manuell hochgeladen (`vendor/` wird lokal gebaut, Migrationen
  per phpMyAdmin). Nichts entwerfen, das Shell-Zugriff auf dem Server voraussetzt.
- `.env` liegt **oberhalb von `public/`**, wird manuell angelegt, nie committet; phpdotenv lädt sie
  bedingungslos (`createImmutable()->load()`). Wichtig: Immutable → echte Umgebungsvariablen haben Vorrang.
- Kommentare auf Deutsch. **Anrede im ganzen Projekt „du“** (UI, E-Mails, Fehlermeldungen, README) – nie „Sie“/„Ihr“.
  **UI-Texte gendergerecht** („Schüler*innen“, „Teilnehmende“, „Lehrkraft“,
  „Administrator*in“); in PHP-Code und DB-Bezeichnern nicht nötig.
- Fehler: Exceptions → JSON `{"fehler": "..."}` mit HTTP-Status. Handler setzen 403/404/409/422 selbst
  (`http_response_code()`) und werfen dann `RuntimeException`; der Router behält diesen Status (sonst 400).
  Unerwartete Fehler → 500 ohne Details (nur im Error-Log).

## Arbeitsweise & Tests

**Nach jeder Änderung zuerst die Unit-Tests, dann erst Integrationstests im Wegwerf-Container:**

| Befehl | Zweck | Dauer |
|---|---|---|
| `vendor/bin/phpunit --testsuite Unit` (`composer test`) | PHP-Unit-Tests mit `FakePdo`, ohne DB | < 1 s |
| `node --test tests/js/` (`npm test`) | Frontend-Tests (jsdom) | ~3 s |
| `vendor/bin/phpunit --testsuite Integration` | lädt nur die Integrationsklassen (ohne DB alle „skipped“) – fängt Syntax-/Ladefehler | < 1 s |
| `bin/test-integration.sh` | Integrationstests gegen Wegwerf-MariaDB in Docker | ~40 s |
| `bin/test-integration.sh --coverage` | dito, Unit + Integration mit Coverage (pcov, Bericht in `coverage/`) | ~40 s |
| `DB_IMAGE=mysql:8.4 bin/test-integration.sh` | dasselbe gegen MySQL | ~45 s |

- Lokal fehlt `pdo_mysql`; deshalb Docker (`tests/docker/Dockerfile`, PHP 8.5 + pcov). Es wird nur das
  Projektverzeichnis eingebunden. **Die echte lokale `.env`, `SchuelerLeistungsdaten.dat` und `private.key`
  nie lesen, ausgeben oder in Tests verwenden.**
- **Neuer/geänderter Code bekommt Tests.** Unit-Tests prüfen Logik und *welche* Statements gesendet werden;
  ob das SQL stimmt, prüfen nur die Integrationstests → SQL-Änderungen immer dort absichern.
- Stand: 339 PHP-Unit-, 83 Integrations-, 38 JS-Tests. Zeilenabdeckung von `src/`: Unit allein ≈ 99,0 %, Unit + Integration
  ≈ 99,85 % (offen nur `exit`, private Konstruktoren). Nicht abgedeckt: `public/*.php`-Einstiegsskripte, `setup.php`, `bin/`.
- Test-Nahtstellen im Produktivcode (nicht entfernen): `Support\Prozess::beenden()` statt `exit`,
  `Database::setInstance()`, `Router::jsonBody($roh)`, `MoodleApi::alleNutzer()/get()` (protected).
- Stolpersteine in Tests: `proc_open` verwirft **leere** Umgebungsvariablen (deshalb `SMTP_ENCRYPTION=none`);
  DB-relative Zeiten (`NOW()`) laufen in UTC → in Tests `gmdate()`; PHPUnit fängt `error_log()` als
  Testausgabe ab (`expectOutputRegex`); `fputcsv()` braucht den `escape`-Parameter (PHP 8.4+ Deprecation).
- Für Frontend-Änderungen zusätzlich `node --check public/assets/app.js`.

## Verzeichnisstruktur

```
public/                    Document Root
  index.php                Einstieg: Token-Seiten (ohne Login) und Layout je Rolle
  api.php                  REST-Einstieg, alle Routen (Router), Rollen je Route
  lti-launch.php           LTI-1.3-Launch (Moodle → Tool)
  lti-jwks.php             öffentlicher Schlüssel (JWKS) für Moodle
  setup.php                Setup-Assistent im Browser (SETUP_TOKEN in .env), danach Token entfernen
  .htaccess                Rewrites (/api/* → api.php), CSP + nosniff (mod_headers)
  assets/app.js            gesamtes Frontend (Views, Dialoge, apiFetch), assets/app.css
  templates/               layout.php (Shell, setzt window.KLAUSURPLAN_ROLLEN / _ME_ID), Hinweisseiten
src/
  Api/                     Router, AdminApi, StufenleitungApi, LehrkraftApi, AnwesenheitApi, SchuelerApi, MeController
  Auth/                    LtiHandler (Launch, Nutzer-Sync), Session (Rollenprüfung), MoodleApi (REST-Nutzerimport)
  Import/                  GomstImporter, KlausurPasteParser
  Mail/                    Mailer (PHPMailer/SMTP), EmailTemplates
  Models/                  Database (PDO-Singleton), Zuordnung (dauerhafte Zuordnungen + Matching)
  Support/Prozess.php      zentrales Beenden der Anfrage (Test-Nahtstelle für exit)
  Cron/erinnerungen_senden.php   CLI-Cronjob (stündlich)
migrations/                001_schema.sql = vollständiges aktuelles Schema; 00N_*.sql = Deltas für bestehende Installationen
bin/                       test-integration.sh; generate-lti-key.php / register-platform.php (CLI-Alternativen zu setup.php)
tests/                     Unit/, Integration/, js/, Support/ (FakePdo, Fixtures, SmtpSenke, MoodleAttrappe), docker/
```

Die statischen `*Api`-Klassen enthalten die Fachlogik je Rolle (`Session::requireRolle(...)` am Anfang jeder Methode).
Es gibt keine globalen Variablen außer dem DB-Singleton.

## Rollen & Rechte

Rollen (`rollen`-Tabelle, mehrere je Person möglich): `admin`, `stufenleitung`, `lehrkraft`, `schueler`.
Sie stehen in der PHP-Session (Änderung wirkt erst nach neuem Login). Wer sich in Moodle als
Systemadministrator*in anmeldet, wird automatisch `admin` (Bootstrapping); neue Nutzer*innen starten als `schueler`.

| Aktion | admin | stufenleitung | lehrkraft | schueler |
|---|:---:|:---:|:---:|:---:|
| Rollen zuweisen, Fächer pflegen, Moodle-Sync | ✓ | – | – | – |
| GoMST importieren, Zuordnungen, externe Lehrkräfte | ✓ | ✓ (alle Stufen) | – | – |
| Halbjahre/Kurse anlegen, Klausuren anlegen/ändern/löschen, Nachschreibtermine | ✓ | ✓ (**alle** Stufen) | – | – |
| Anwesenheit eintragen | ✓ | ✓ | nur eigene Kurse | – |
| Entschuldigen | ✓ | ✓ | – | – |
| Anwesenheits-Mail manuell auslösen (✉️) | ✓ | UI nur für eigene Stufen | – | – |
| Eigene Klausuren sehen | ✓ | ✓ | ✓ | ✓ (nur eigene Termine) |

**Zuständigkeit der Stufenleitung** (`stufenleitungen`): Wer die Rolle hat, verwaltet selbst, für welche Stufen
sie/er zuständig ist („Meine Stufen“, `GET/PUT/DELETE /api/stufenleitung/meine-stufen[/{id}]`) – nie der Admin.
Es kann keine Stufe zugewiesen sein. Beim GoMST-Import wird man automatisch für die importierten Stufen
zuständig (Antwort `stufenleitung_neu`, im UI per Klick abgebbar; Admin ohne SL-Rolle: nicht). Eine neue
Stufe erbt die Zuständigen der Vorgängerstufe (EF←Q2, Q1←EF, Q2←Q1 des Vorjahres). Die Zuständigkeit steuert:
1. die Standard-Klausurliste (`GET /api/klausuren`; `?alle=1` zeigt alle Stufen; eine Liste je Stufe/Halbjahr),
2. Vorauswahl von Stufe/Halbjahr beim Anlegen und im Excel-Import,
3. den ✉️-Button und die **Übersichtsmail an die Stufenleitung** (siehe E-Mail).
Entzug der Rolle `stufenleitung` löscht die Zuordnungen.

## Datenmodell (Details: `migrations/001_schema.sql`)

Namen deutsch, snake_case, Tabellen im Plural.
- `benutzer` (`moodle_id` eindeutig, `kuerzel`, `email`, `stufe`, `extern`), `rollen`.
- `stufen` (name + schuljahr) → `halbjahre` (stufe, abschnitt 1/2) → `kurse` (kurs_kuerzel eindeutig **je Halbjahr**,
  kursart `LK|GK`, `lehrer_kuerzel`, `lehrer_id`, `anzeigename`) → `kurs_schueler` (`name_roh` = „Nachname|Vorname“,
  `schueler_id`, GoMST-`kursart`) und `klausuren` (klausur_nr, termin_datum/-uhrzeit, dauer_minuten; alles außer Kurs nullable).
- `anwesenheiten` (status `anwesend|fehlend|ausstehend`, `entschuldigt` NULL=offen/1/0), `nachschreibtermine`,
  `nachschreib_zuordnungen` (M:N Klausur↔Termin), `nachschreib_anwesenheiten`.
- `email_benachrichtigungen` (Token, typ `erstmeldung|erinnerung`, `beantwortet_am`), `stufenleitung_erinnerungen`.
- `schueler_zuordnungen` / `lehrer_zuordnungen`: **dauerhafte** Zuordnung GoMST-Name/Kürzel → Konto (siehe unten).
- `fach_bezeichnungen` (Kürzel → Name, vom Admin pflegbar), `lti2_*` (LTI-Bibliothek).
- Kein Raum-Feld (bewusst entfernt).

**Migrationen:** `001_schema.sql` ist immer der vollständige Endstand (Neuinstallation). Jede Änderung zusätzlich als
neues, möglichst idempotentes Delta `00N_beschreibung.sql` (MariaDB **und** MySQL: kein `ADD COLUMN IF NOT EXISTS`,
stattdessen `information_schema`-Prüfung + `PREPARE`). Neue Migration **auch in die Tabelle im README (Abschnitt „Update“)
eintragen**. Tests: `SchemaMigrationTest`.

## Kernlogik

**GoMST-Import** (`Import/GomstImporter`): Pipe-getrennt, UTF-8 mit BOM, CRLF. Spalten `Nachname, Vorname, Fach,
Fachlehrer, Kursart, Kurs, Jahrgang, Abschnitt, Jahr`. Nur `GKS, LK1, LK2, AB3, AB4` (GKM/ZK übersprungen).
Legt Stufe/Halbjahr/Kurs/Prüflinge an bzw. aktualisiert; Prüflinge, die nicht mehr in der Datei stehen, werden
entfernt, **außer** es gibt Anwesenheitsdaten. Anzeigename: „Q2 Sport GK 1 SZ“ aus Kurskürzel + `fach_bezeichnungen`.

**Zuordnung** (`Models/Zuordnung`, UI „Zuordnungen“): Reihenfolge beim Import/Hinzufügen: (1) gespeicherte
Zuordnung, (2) automatisches Matching (Nachname exakt; Vorname exakt oder erster Vorname; case-insensitive;
Lehrkräfte über `benutzer.kuerzel`, Moodle-Konten vor externen). Manuelle Zuordnungen gelten **personenbezogen für alle
Kurse** und überleben das Löschen von Kursen/Halbjahren. `benutzer_id = NULL` = „bewusst aufgehoben“ → kein
Auto-Matching mehr. Alles (auch Automatisches) ist im UI änder- und aufhebbar.

**Externe Lehrkräfte** (`benutzer.extern = 1`, `moodle_id = 'extern:…'`, Rolle `lehrkraft`): Lehrkräfte ohne
Moodle-Konto (z.B. Klausur an anderer Schule). Pflicht: Vor-/Nachname, Kürzel (eindeutig, nach dem Anlegen nicht
änderbar), E-Mail. Verwaltbar von **jeder** Stufenleitung und Admin, unabhängig von Stufen. Können sich nicht anmelden,
erhalten aber die Mail-Token-Links. Der Moodle-Sync löscht sie nicht und lässt ihr Kürzel unangetastet.

**Klausuren:** Einzeln anlegen (Stufe → Kurs, mehrere Klausuren je Kurs) oder Excel-Paste: Spalten `Kurs, Datum
(TT.MM.JJJJ), Uhrzeit, Dauer` (Kopfzeile Pflicht, Reihenfolge egal; `Anzeigename`/`TN` ignoriert). Die CSV-Vorlage
(`GET /api/klausuren/vorlage?halbjahr_id=`) und der Import (`POST …/paste-import?halbjahr_id=`) beziehen sich auf ein
gewähltes Halbjahr, weil Kurskürzel nur je Halbjahr eindeutig sind. Import-Priorität: gleicher Kurs+Datum → aktualisieren;
Klausur ohne Datum → füllen; sonst neu. Undatierte Klausuren stehen unter den datierten.

**E-Mail** (`Cron/erinnerungen_senden.php`, stündlich; `Mail/`): (1) Erstmeldung an die Fachlehrkraft, sobald Termin
(Datum+Uhrzeit) vergangen; nach 7 Tagen ohne Antwort einmalig eine Erinnerung. Links mit Token
(`random_bytes(32)`, kein Ablauf): `/anwesenheit/alle-da?token=`, `/anwesenheit/eingabe?token=`,
`POST /anwesenheit/token-eintrag` – **ohne Login**, Token = Authentifizierung. (2) **Übersicht an die
Stufenleitung**: eine Woche nach der Klausur noch keine Anwesenheit → Sammelmail über Klausuren der *eigenen* Stufen,
einmalig je Klausur und Person. Manuell: `POST /api/stufenleitung/email-ausloesen/{klausur_id}`.

**Moodle** (`Auth/MoodleApi`): `core_user_get_users` für `auth=ldap` und `manual` (`{MOODLE_URL}/webservice/rest/server.php`).
Lehrkraft = Custom-Field `klasse == "Lehrkraft"`; Kürzel aus dem Nachnamen (`Gebauer (SZ)` → `SZ`); E-Mail nur für
Lehrkräfte; Stufe aus `klasse`. Nicht mehr vorhandene, unreferenzierte, nicht-externe Konten werden gelöscht.

## API (`public/api.php`)

`GET /api/me` · Admin: `/admin/benutzer[/{id}/rollen]`, `/admin/moodle-sync`, `/admin/faecher[/{k}]`, `/admin/stufen`,
`/admin/benutzer/{id}/stufenleitungen` · Stufenleitung: `/stufenleitung/gomst-import`, `…/zuordnungen` (GET/POST),
`…/moodle-schueler`, `…/externe-lehrkraefte[/{id}]`, `…/meine-stufen[/{id}]`, `…/lehrkraefte`, `…/halbjahre[/{id}/kurse]`,
`…/halbjahr-vorschlag`, `…/kurse/{id}[/schueler|/zusatz-schueler[/{ks}]]`, `…/entschuldigung/{id}`,
`…/email-ausloesen/{klausur}`, `…/daten/{halbjahr}` (löschen) · Klausuren: `/klausuren[/{id}]`, `/klausuren/vorlage`,
`/klausuren/paste-import`, `/klausuren/meine-nachschreibtermine`, `/kurse` · `/nachschreibtermine[/{id}[/klausuren]]` ·
`/anwesenheit/{klausur}`, `/nachschreib-anwesenheit/{id}` · Schüler: `/schueler/meine-klausuren`, `…/meine-nachschreibtermine`.
Feste Routen stehen **vor** Routen mit `{id}`. Die Rollen je Route stehen am Ende der Registrierung in `api.php`.

## Frontend (`public/assets/app.js`)

Eine Datei, Hash-Routing (`VIEWS`), `apiFetch('/pfad')` (JSON, wirft `Error` mit der Servermeldung), Dialoge als
`.dialog-overlay` (`schliessbar()`), Rollen über `hatRolle()`. **Immer `escHtml()`** für Daten in `innerHTML`.
Zuordnungs-Ansicht lädt nach jeder Änderung neu (`ladeZuordnungen`) und behält Tab/Filter (`zuordnungenZustand`).
CSS in `app.css` (funktional, eigenes Design, keine Frameworks).

## Sicherheit & Datenschutz

- Prepared Statements; Ausgaben escapen (HTML-Token-Seiten mit `htmlspecialchars`, JSON im Frontend mit `escHtml`).
- Session: PHP-Sessions (`klausurplan_session`, httponly, SameSite=Lax), `session_regenerate_id()` nach Login.
- Schüler*innen sehen nur eigene Daten (`SchuelerApi` fragt ausschließlich mit der eigenen ID ab).
- CSP über `.htaccess`/`api.php`/`index.php` (nur `'self'`, Frame-Ancestors Moodle). Kein Zugriffs-Log, aber PHP-Error-Log aktiv.
- Halbjahre samt Klausuren löschbar (CASCADE). `.env`, `*.key`, `vendor/` nie committen (`.gitignore`).
- Bekannt/offen: kein CSRF-Token (SameSite=Lax + JSON-Content-Type); transitive Composer-Abhängigkeiten
  (guzzle, commonmark) haben Advisories (`composer audit`) – bei Gelegenheit `composer update` prüfen.
