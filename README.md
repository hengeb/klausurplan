# Klausurplan

Verwaltung von Klausurterminen und Anwesenheit für die Oberstufe (EF, Q1, Q2). Das Tool wird per
**LTI 1.3 in Moodle** eingebunden – es gibt keine eigene Anmeldung – und läuft auf einem normalen
PHP-Webserver ohne Shell-Zugriff.

**Was es kann**

- GoMST-Kursexport importieren (Kurse, Teilnehmende, Fachlehrkräfte), Namen den Moodle-Konten zuordnen
  (dauerhaft gespeichert, jederzeit korrigierbar).
- Klausurtermine anlegen: einzeln oder per Excel-Import (CSV-Vorlage je Stufe/Halbjahr), Nachschreibtermine.
- Fachlehrkräfte tragen die Anwesenheit ein – in der Oberfläche oder per Link in einer E-Mail (ohne Login).
  Stufenleitungen bekommen eine Übersicht, wenn Anwesenheit fehlt. Externe Lehrkräfte ohne Moodle-Konto
  werden per E-Mail-Link eingebunden.
- Schüler*innen sehen ihre eigenen Termine.

Rollen: **Administrator*in**, **Stufenleitung** (verwaltet selbst, für welche Stufen sie zuständig ist),
**Lehrkraft**, **Schüler*in**. Technische Details für Entwicklung: [CLAUDE.md](CLAUDE.md).

---

## Voraussetzungen

| Was | Anforderung |
|---|---|
| Webserver | Apache mit `mod_rewrite` und `mod_headers`, `AllowOverride All` für den Document Root, **HTTPS** |
| PHP | **8.5** mit den Erweiterungen `pdo_mysql`, `mbstring`, `openssl`, `curl`, `json`, `ctype`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `libxml`, `session`, `tokenizer` |
| Datenbank | MariaDB 10.x (oder MySQL 8), Zeichensatz `utf8mb4` |
| Moodle | LTI 1.3 (externes Tool), Webservice-Token mit Funktion `core_user_get_users`, Profilfeld `klasse` (siehe unten) |
| E-Mail | SMTP-Zugang (z. B. `klausurplan@schule.de`) |
| Lokal (nur zum Bauen) | PHP 8.5 und [Composer](https://getcomposer.org) – der Server braucht kein Composer |

Das Tool erwartet, im **Wurzelverzeichnis einer (Sub-)Domain** zu laufen (z. B. `https://klausurplan.schule.de`);
Pfade wie `/api` und `/assets` sind absolut.

---

## Installation

### 1. Code vorbereiten (lokal)

```bash
git clone <repository-url> klausurplan && cd klausurplan
composer install --no-dev --optimize-autoloader     # erzeugt vendor/ ohne Test-Werkzeuge
```

### 2. Auf den Server hochladen (SFTP/FTP)

Empfohlene Verzeichnisstruktur – **nur `public/` darf per HTTP erreichbar sein**:

```
/pfad/zum/projekt/           ← Projektverzeichnis (nicht öffentlich)
├── .env                     ← legst du in Schritt 4 an
├── private.key              ← erzeugt der Setup-Assistent in Schritt 5
├── vendor/  src/  migrations/  composer.json
└── public/                  ← Document Root der (Sub-)Domain
```

Hochladen: `public/`, `src/`, `vendor/`, `migrations/`, `composer.json`.
**Nicht** hochladen: `.git/`, `tests/`, `node_modules/`, `coverage/`, `package*.json`, `phpunit.xml.dist`,
deine lokale `.env`, `*.key`, Exportdateien aus GoMST. Stelle den Document Root der Domain auf `public/`.

### 3. Datenbank anlegen

1. Datenbank (`utf8mb4`, Kollation `utf8mb4_unicode_ci`) und einen eigenen Benutzer mit allen Rechten darauf anlegen.
2. In phpMyAdmin die Datei **`migrations/001_schema.sql`** importieren (Reiter *Importieren*). Sie enthält das
   vollständige Schema samt LTI-Tabellen und Fächerbezeichnungen.

Die weiteren Dateien `migrations/002_…`, `003_…` sind nur für **Updates** bestehender Installationen.

### 4. Konfiguration (`.env`)

`.env.example` kopieren, im Projektverzeichnis (oberhalb von `public/`) als `.env` ablegen und ausfüllen:

| Variable | Bedeutung |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Datenbankzugang |
| `APP_URL` | öffentliche Adresse ohne Slash am Ende, z. B. `https://klausurplan.schule.de` (Links in E-Mails, LTI) |
| `APP_ENV` | `production` |
| `LTI_PRIVATE_KEY_FILE` | Pfad des RSA-Schlüssels, relativ zum Projektverzeichnis (Standard `private.key`) oder absolut |
| `LTI_KID` | Kennung des Schlüssels (Standard `klausurplan-key-1`) |
| `MOODLE_URL` | Adresse deiner Moodle-Installation |
| `MOODLE_API_TOKEN` | Webservice-Token für den Nutzerabgleich |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM_NAME` | Postausgang |
| `SMTP_ENCRYPTION` | `ssl` (Port 465) oder `tls` (Port 587, STARTTLS) |
| `SETUP_TOKEN` | **nur während der Einrichtung**: langer Zufallswert; schaltet `/setup.php` frei |

Die `.env` niemals committen oder in `public/` legen. Dateirechte am besten `600`.

### 5. LTI mit Moodle verbinden (Setup-Assistent)

1. In der `.env` `SETUP_TOKEN=<langer-zufälliger-wert>` setzen und `https://<APP_URL>/setup.php?token=<wert>` öffnen.
2. **Schritt 1:** RSA-Schlüssel erzeugen lassen (legt `private.key` an).
3. **Schritt 2:** In Moodle unter *Website-Administration → Plugins → Aktivitäten → Externes Tool → Tool manuell
   konfigurieren* die Werte eintragen, die der Assistent anzeigt (Tool-URL, LTI 1.3, Schlüsselsatz-URL
   `…/lti-jwks.php`, Anmelde-URL und Umleitungs-URI `…/lti-launch.php`, Name und E-Mail „Immer“ übergeben).
4. **Schritt 3:** Die Werte, die Moodle nach dem Speichern anzeigt (Client-ID, Deployment-ID, URLs), im Assistenten
   eintragen und die Plattform registrieren.
5. **Schritt 4:** `SETUP_TOKEN` wieder aus der `.env` **entfernen** – danach ist `/setup.php` nicht mehr erreichbar.

Binde das Tool anschließend in Moodle an gewünschter Stelle als Aktivität/Link ein.

### 6. Moodle für den Nutzerabgleich vorbereiten

- Webservices und das REST-Protokoll aktivieren, einen externen Dienst mit der Funktion `core_user_get_users` anlegen,
  einem technischen Konto zuweisen und für dieses ein **Token** erzeugen → `MOODLE_API_TOKEN`.
- Benutzerdefiniertes Profilfeld mit Kurznamen **`klasse`**: Lehrkräfte haben dort exakt den Wert `Lehrkraft`,
  Schüler*innen ihre Stufe/Klasse (z. B. `Q2` oder `Q1 Kurs 3`; das führende Kürzel wird als Stufe verwendet).
- Lehrkräfte haben ihr Kürzel im Nachnamen in Klammern: `Mustermann (MU)`.

### 7. Erster Start

1. Als **Moodle-Systemadministrator*in** das Tool in Moodle öffnen – du wirst automatisch *Administrator*in*.
2. *Administration → Moodle-Nutzer synchronisieren* ausführen.
3. Unter *Benutzerverwaltung* die Rolle **Stufenleitung** an die zuständigen Personen vergeben (die Stufen wählen
   diese anschließend selbst). Bei Bedarf *Fächerbezeichnungen* anpassen.
4. Stufenleitung: **GoMST-Import** → **Zuordnungen** prüfen → **Klausuren** anlegen (Excel-Import oder einzeln).

### 8. Cronjob für die E-Mails einrichten

Beim Hoster einen Cronjob **stündlich** anlegen (Cron-Verwaltung im Hosting-Panel):

```
0 * * * * php /pfad/zum/projekt/src/Cron/erinnerungen_senden.php
```

Das Skript versendet Anwesenheits-Mails an Fachlehrkräfte (und nach 7 Tagen einmalig eine Erinnerung) sowie die
Übersicht an Stufenleitungen. Es liest die `.env` im Projektverzeichnis. Test: in der Klausurliste bei einer Klausur
mit Lehrkraft das ✉️ anklicken – die Mail sollte ankommen. Fehler des Cronjobs stehen in dessen Ausgabe/Mail.

---

## Update

**Vor jedem Update:** Datenbank-Export (phpMyAdmin → *Exportieren*) und Kopie von `.env` und `private.key` anlegen.

1. **Neuen Stand holen und bauen (lokal):**
   ```bash
   git pull
   composer install --no-dev --optimize-autoloader
   ```
2. **Hochladen:** `public/` und `src/` ersetzen (am besten vorher die alten Dateien dort löschen, damit keine
   veralteten Klassen bleiben). `vendor/` nur hochladen, wenn sich `composer.lock` geändert hat.
   **`.env` und `private.key` nicht überschreiben.**
3. **Datenbank-Migrationen einspielen:** in phpMyAdmin (*Importieren*) alle Dateien aus `migrations/` einspielen,
   die deine Installation noch nicht kennt – **in aufsteigender Reihenfolge**. `001_schema.sql` wird bei einem
   Update *nicht* erneut eingespielt.

   | Datei | Inhalt | Hinweis |
   |---|---|---|
   | `002_remove_raum.sql` | entfernt das Feld „Raum“ | nur **einmal** und nur, wenn `klausuren` noch die Spalte `raum` hat (Installationen aus einem alten Stand) |
   | `003_zuordnungen_extern.sql` | dauerhafte Zuordnungen, externe Lehrkräfte, Stufenleitungs-Mails | mehrfach ausführbar; übernimmt bestehende Zuordnungen |

   Jede neue Migration wird hier eingetragen. Notiere dir, welche Nummer bei dir zuletzt eingespielt wurde.
4. **Prüfen:** Tool aus Moodle öffnen (Browser-Cache mit `Strg+F5` leeren, falls die Seite alt aussieht),
   Klausurliste und Zuordnungen öffnen. Bei einem weißen Bildschirm/Fehler: PHP-Error-Log des Servers ansehen.
5. **Zurückrollen:** alte Dateien wieder hochladen und den DB-Export einspielen.

Neue Schuljahre: GoMST-Export des neuen Halbjahres importieren (legt Stufe/Halbjahr an; Stufenleitungen der
Vorgängerstufe werden übernommen). Nicht mehr benötigte Halbjahre unter *Halbjahre & Kurse* löschen – dabei werden
Klausuren und Anwesenheitsdaten dieses Halbjahres gelöscht (Datenschutz).

---

## Fehlersuche

| Symptom | Ursache / Abhilfe |
|---|---|
| „Fehler beim LTI-Launch“ | Plattform nicht (richtig) registriert, `private.key` fehlt/falsch, `APP_URL` stimmt nicht mit Moodle überein → Setup-Assistent erneut mit gesetztem `SETUP_TOKEN` |
| Weißer Bildschirm / HTTP 500 | PHP-Version < 8.5, fehlende Erweiterung, `vendor/` fehlt oder `.env` nicht gefunden → PHP-Error-Log |
| „Nicht authentifiziert“ | Tool wurde nicht aus Moodle heraus geöffnet (Session fehlt) |
| Keine E-Mails | Cronjob läuft nicht, `SMTP_*`/`SMTP_ENCRYPTION` falsch, Lehrkraft ohne E-Mail-Adresse |
| Lehrkraft/Schüler*in nicht zuordenbar | Moodle-Sync ausführen; ansonsten manuell unter *Zuordnungen*; Lehrkräfte ohne Moodle-Konto als *Externe Lehrkraft* anlegen |
| Änderungen im Frontend „fehlen“ | Browser-Cache leeren (`Strg+F5`) |

---

## Entwicklung & Tests

```bash
composer install                      # inkl. PHPUnit
npm install                           # nur für die Frontend-Tests (jsdom)

vendor/bin/phpunit --testsuite Unit   # schnelle PHP-Tests ohne Datenbank (< 1 s)   = composer test
npm test                              # Frontend-Tests (jsdom)
bin/test-integration.sh               # Integrationstests gegen eine Wegwerf-Datenbank (benötigt Docker)
bin/test-integration.sh --coverage    # Unit + Integration mit Code-Coverage (Bericht: coverage/)
DB_IMAGE=mysql:8.4 bin/test-integration.sh   # dieselben Tests gegen MySQL
```

Die Integrationstests starten MariaDB und PHP 8.5 in Docker, laden `migrations/001_schema.sql`, prüfen die
Migrationen und führen auch das Cron-Skript gegen einen lokalen Test-SMTP-Server aus. Es wird keine echte `.env` benötigt.

Projektstruktur, Rechtekonzept und Konventionen: [CLAUDE.md](CLAUDE.md).
