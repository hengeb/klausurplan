# Klausurplan – Projektanweisungen für Claude Code

## Projektüberblick

Dieses Tool verwaltet Klausurtermine an einer deutschen Schule (Oberstufe/Gymnasium).
Es wird per **LTI 1.3** in Moodle eingebunden, läuft auf einem PHP-Schulserver und ist
**datenschutzkonform** (kein externes CDN, keine Analytics, DSGVO).

Die Authentifizierung erfolgt ausschließlich über Moodle/LTI. Das Tool nutzt
die Moodle REST API für den Nutzerdaten-Import (E-Mail-Adressen, Zuordnung).

---

## Technische Rahmenbedingungen

| Parameter | Wert |
|---|---|
| PHP | 8.5 |
| Datenbank | MariaDB (PDO, prepared statements **immer**) |
| LTI | 1.3 via `celtic-project/LTI-PHP` |
| E-Mail | PHPMailer via SMTP |
| Frontend | Statisches HTML + Vanilla JS (fetch API → REST-artige PHP-Endpunkte) |
| Paketmanager | Composer |
| Deployment | Schulhomepageserver, kein Docker |

**Kein externes CDN.** Alle Assets lokal. Kein Bootstrap über CDN.
Minimales, funktionales CSS – kein Framework zwingend, aber sauberes eigenes CSS.

**`.env` liegt oberhalb von `public/`** und ist damit nicht per HTTP erreichbar. Auf dem Server wird sie manuell angelegt (nicht im Git). phpdotenv lädt sie in allen Umgebungen gleich.

---

## Verzeichnisstruktur

```
klausurplan/
├── CLAUDE.md                  ← diese Datei
├── composer.json
├── .env.example               ← Vorlage, niemals .env committen
├── .gitignore
│
├── public/                    ← Document Root des Webservers
│   ├── index.php              ← Einstiegspunkt, Router
│   ├── lti-launch.php         ← LTI 1.3 Launch-Handler
│   ├── api.php                ← REST-API-Einstiegspunkt
│   ├── assets/
│   │   ├── app.css
│   │   └── app.js
│   └── templates/             ← HTML-Shells (JS lädt Daten nach)
│       ├── layout.php
│       ├── admin.html
│       ├── stufenleitung.html
│       ├── lehrkraft.html
│       └── schueler.html
│
├── src/
│   ├── Auth/
│   │   ├── LtiHandler.php     ← LTI 1.3 Launch, Session-Initialisierung
│   │   ├── Session.php        ← Session-Wrapper, Rollenprüfung
│   │   └── MoodleApi.php      ← Moodle REST API Client (Nutzerimport)
│   │
│   ├── Api/                   ← API-Controller (JSON in/out)
│   │   ├── Router.php
│   │   ├── AdminApi.php
│   │   ├── StufenleitungApi.php
│   │   ├── LehrkraftApi.php
│   │   └── SchuelerApi.php
│   │
│   ├── Import/
│   │   ├── GomstImporter.php  ← GoMST .dat Datei einlesen
│   │   └── KlausurPasteParser.php  ← Tab-getrenntes Excel-Paste parsen
│   │
│   ├── Mail/
│   │   ├── Mailer.php         ← PHPMailer-Wrapper
│   │   └── EmailTemplates.php ← HTML-Mail-Vorlagen
│   │
│   ├── Models/                ← Datenbankzugriff via PDO
│   │   ├── Database.php       ← PDO-Singleton
│   │   ├── Benutzer.php
│   │   ├── Kurs.php
│   │   ├── Klausur.php
│   │   ├── Nachschreibtermin.php
│   │   ├── Anwesenheit.php
│   │   └── Benachrichtigung.php
│   │
│   └── Cron/
│       └── erinnerungen_senden.php  ← CLI-Script für Cronjob
│
└── migrations/
    ├── 001_schema.sql
    └── 002_faecher_lookup.sql
```

---

## Datenbankschema

**Namenskonvention:** Deutsch, snake_case, Tabellen im Plural.

```sql
-- migrations/001_schema.sql

CREATE TABLE benutzer (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    moodle_id       VARCHAR(255) UNIQUE NOT NULL,
    vorname         VARCHAR(100) NOT NULL,
    nachname        VARCHAR(100) NOT NULL,
    email           VARCHAR(255),
    kuerzel         VARCHAR(20),        -- Paraphe, z.B. "SZ" aus "Gebauer (SZ)"
    zuletzt_gesehen DATETIME,
    erstellt_am     DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE rollen (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    benutzer_id INT NOT NULL,
    rolle       ENUM('admin','stufenleitung','lehrkraft','schueler') NOT NULL,
    FOREIGN KEY (benutzer_id) REFERENCES benutzer(id) ON DELETE CASCADE,
    UNIQUE KEY (benutzer_id, rolle)
);

CREATE TABLE stufen (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(20) NOT NULL,   -- z.B. "Q2", "Q1", "EF"
    schuljahr  VARCHAR(9)  NOT NULL,   -- z.B. "2023/2024"
    UNIQUE KEY (name, schuljahr)
);

CREATE TABLE stufenleitungen (
    benutzer_id INT NOT NULL,
    stufe_id    INT NOT NULL,
    PRIMARY KEY (benutzer_id, stufe_id),
    FOREIGN KEY (benutzer_id) REFERENCES benutzer(id) ON DELETE CASCADE,
    FOREIGN KEY (stufe_id)    REFERENCES stufen(id)   ON DELETE CASCADE
);

-- Import-Kontext: eine Datei = ein Halbjahr (ein Import-Lauf)
CREATE TABLE halbjahre (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    stufe_id      INT NOT NULL,
    abschnitt     TINYINT NOT NULL,   -- 1 oder 2
    importiert_am DATETIME DEFAULT CURRENT_TIMESTAMP,
    importiert_von INT,
    FOREIGN KEY (stufe_id)       REFERENCES stufen(id)    ON DELETE RESTRICT,
    FOREIGN KEY (importiert_von) REFERENCES benutzer(id)  ON DELETE SET NULL
);

CREATE TABLE kurse (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    halbjahr_id    INT NOT NULL,
    kurs_kuerzel   VARCHAR(50) NOT NULL,   -- z.B. "SP_Q2_GK1_SZ"
    fach_kuerzel   VARCHAR(10) NOT NULL,   -- z.B. "SP"
    kursart        ENUM('GKS','LK1','LK2','AB3','AB4') NOT NULL,
    lehrer_kuerzel VARCHAR(20),
    lehrer_id      INT,                    -- nach Zuordnung gesetzt
    anzeigename    VARCHAR(150),           -- z.B. "Q2 Sport GK 1 SZ"
    FOREIGN KEY (halbjahr_id) REFERENCES halbjahre(id) ON DELETE CASCADE,
    FOREIGN KEY (lehrer_id)   REFERENCES benutzer(id)  ON DELETE SET NULL,
    UNIQUE KEY (halbjahr_id, kurs_kuerzel)
);

-- Klausurrelevante Kursarten: GKS, LK1, LK2, AB3, AB4
-- GKM = Grundkurs Mündlich → KEIN Import
-- ZK = Zusatzkurs → KEIN Import (wie GKM behandeln)

CREATE TABLE kurs_schueler (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    kurs_id         INT NOT NULL,
    name_roh        VARCHAR(200) NOT NULL,  -- "Mustermann|Max" aus GoMST
    schueler_id     INT,                    -- nach Zuordnung gesetzt
    FOREIGN KEY (kurs_id)    REFERENCES kurse(id)    ON DELETE CASCADE,
    FOREIGN KEY (schueler_id) REFERENCES benutzer(id) ON DELETE SET NULL,
    UNIQUE KEY (kurs_id, name_roh)
);

CREATE TABLE klausuren (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    kurs_id      INT NOT NULL,
    klausur_nr   TINYINT DEFAULT 1,        -- welche Klausur im Halbjahr
    termin_datum DATE,                     -- nullable: noch nicht festgelegt
    termin_uhrzeit TIME,
    dauer_minuten SMALLINT,
    raum         VARCHAR(100),
    erstellt_am  DATETIME DEFAULT CURRENT_TIMESTAMP,
    erstellt_von INT,
    FOREIGN KEY (kurs_id)    REFERENCES kurse(id)    ON DELETE CASCADE,
    FOREIGN KEY (erstellt_von) REFERENCES benutzer(id) ON DELETE SET NULL
);

CREATE TABLE nachschreibtermine (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    termin_datum   DATE,
    termin_uhrzeit TIME,
    raum           VARCHAR(100),
    bemerkung      TEXT,
    erstellt_am    DATETIME DEFAULT CURRENT_TIMESTAMP,
    erstellt_von   INT,
    FOREIGN KEY (erstellt_von) REFERENCES benutzer(id) ON DELETE SET NULL
);

-- M:N: mehrere Klausuren können denselben Nachschreibtermin haben
CREATE TABLE nachschreib_zuordnungen (
    klausur_id           INT NOT NULL,
    nachschreibtermin_id INT NOT NULL,
    PRIMARY KEY (klausur_id, nachschreibtermin_id),
    FOREIGN KEY (klausur_id)           REFERENCES klausuren(id)           ON DELETE CASCADE,
    FOREIGN KEY (nachschreibtermin_id) REFERENCES nachschreibtermine(id)  ON DELETE CASCADE
);

CREATE TABLE anwesenheiten (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    klausur_id       INT NOT NULL,
    kurs_schueler_id INT NOT NULL,
    status           ENUM('anwesend','fehlend','ausstehend') DEFAULT 'ausstehend',
    entschuldigt     BOOLEAN DEFAULT FALSE,
    kommentar        TEXT,
    erfasst_von      INT,
    erfasst_am       DATETIME,
    geaendert_von    INT,
    geaendert_am     DATETIME,
    FOREIGN KEY (klausur_id)       REFERENCES klausuren(id)    ON DELETE CASCADE,
    FOREIGN KEY (kurs_schueler_id) REFERENCES kurs_schueler(id) ON DELETE CASCADE,
    FOREIGN KEY (erfasst_von)      REFERENCES benutzer(id)     ON DELETE SET NULL,
    FOREIGN KEY (geaendert_von)    REFERENCES benutzer(id)     ON DELETE SET NULL,
    UNIQUE KEY (klausur_id, kurs_schueler_id)
);

CREATE TABLE nachschreib_anwesenheiten (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    nachschreibtermin_id INT NOT NULL,
    kurs_schueler_id     INT NOT NULL,
    status               ENUM('anwesend','fehlend','ausstehend') DEFAULT 'ausstehend',
    entschuldigt         BOOLEAN DEFAULT FALSE,
    kommentar            TEXT,
    erfasst_von          INT,
    erfasst_am           DATETIME,
    FOREIGN KEY (nachschreibtermin_id) REFERENCES nachschreibtermine(id)  ON DELETE CASCADE,
    FOREIGN KEY (kurs_schueler_id)     REFERENCES kurs_schueler(id)       ON DELETE CASCADE,
    FOREIGN KEY (erfasst_von)          REFERENCES benutzer(id)            ON DELETE SET NULL,
    UNIQUE KEY (nachschreibtermin_id, kurs_schueler_id)
);

CREATE TABLE email_benachrichtigungen (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    klausur_id   INT NOT NULL,
    empfaenger_id INT NOT NULL,
    typ          ENUM('erstmeldung','erinnerung') NOT NULL,
    token        VARCHAR(64) NOT NULL UNIQUE,  -- zufälliger Token für den Link
    gesendet_am  DATETIME,
    beantwortet_am DATETIME,
    FOREIGN KEY (klausur_id)    REFERENCES klausuren(id) ON DELETE CASCADE,
    FOREIGN KEY (empfaenger_id) REFERENCES benutzer(id)  ON DELETE CASCADE
);

-- Fächerbezeichnungen (pflegbar durch Admin, vorbelegt)
CREATE TABLE fach_bezeichnungen (
    kuerzel     VARCHAR(10) PRIMARY KEY,
    bezeichnung VARCHAR(100) NOT NULL
);
```

```sql
-- migrations/002_faecher_lookup.sql
-- NRW Oberstufe – Standardbezeichnungen

INSERT INTO fach_bezeichnungen (kuerzel, bezeichnung) VALUES
('D',   'Deutsch'),
('E',   'Englisch'),
('F',   'Französisch'),
('L',   'Latein'),
('GR',  'Griechisch'),
('SP',  'Spanisch'),
('NL',  'Niederländisch'),
('RU',  'Russisch'),
('IT',  'Italienisch'),
('M',   'Mathematik'),
('PH',  'Physik'),
('CH',  'Chemie'),
('BI',  'Biologie'),
('IF',  'Informatik'),
('GE',  'Geschichte'),
('EK',  'Erdkunde'),
('SW',  'Sozialwissenschaften'),
('PL',  'Philosophie'),
('PA',  'Pädagogik'),
('KU',  'Kunst'),
('MU',  'Musik'),
('LI',  'Literatur'),
('SP',  'Sport'),
('ER',  'Evangelische Religionslehre'),
('KR',  'Katholische Religionslehre'),
('PS',  'Psychologie'),
('RK',  'Rechtskunde'),
('WI',  'Wirtschaft'),
('VO',  'Vokalpraktischer Kurs'),
('ZG',  'Zusatzkurs Gesellschaft'),
('ZN',  'Zusatzkurs Naturwissenschaften')
ON DUPLICATE KEY UPDATE bezeichnung = VALUES(bezeichnung);
```

---

## GoMST-Import

**Dateiformat:** Pipe-getrennt (`|`), UTF-8 mit BOM (`utf-8-sig`), CRLF-Zeilenenden.

**Relevante Spalten:**

| Spalte | Verwendung |
|---|---|
| `Nachname` + `Vorname` | Schüleridentifikation |
| `Fach` | Fachkürzel |
| `Fachlehrer` | Lehrerkürzel (Paraphe) |
| `Kursart` | Filterkriterium |
| `Kurs` | Eindeutige Kursbezeichnung |
| `Jahrgang` | Stufe (z.B. Q2) |
| `Abschnitt` | Schulhalbjahr (1 oder 2) |
| `Jahr` | Schuljahr (z.B. 2023 = 2023/2024) |

**Klausurrelevante Kursarten:** `GKS`, `LK1`, `LK2`, `AB3`, `AB4`
**Nicht klausurrelevant (überspringen):** `GKM`, `ZK`

**Importlogik (GomstImporter.php):**

1. Datei einlesen, BOM und CRLF bereinigen
2. Pro Zeile: Kursart prüfen – `GKM` oder `ZK` → überspringen
3. Stufe aus `Jahrgang` ermitteln oder anlegen (`stufen`)
4. Halbjahr anlegen (`halbjahre`)
5. Kurs anlegen oder aktualisieren (`kurse`) – `kurs_kuerzel` ist eindeutig pro Halbjahr
6. Schüler dem Kurs zuordnen (`kurs_schueler`)
7. **Bei erneutem Import derselben Daten:**
   - Kurse, die in der neuen Datei enthalten sind → Schülerliste vollständig ersetzen
   - Schüler, die nicht mehr enthalten sind → aus `kurs_schueler` entfernen
   - Bereits erfasste Anwesenheitsdaten dabei NICHT löschen (Klausuren bleiben)
8. Automatisches Namensmatching nach Import (siehe Zuordnungslogik)

**Anzeigename generieren** aus `kurs_kuerzel` (z.B. `SP_Q2_GK1_SZ`):
```
[FACH]_[STUFE]_[KURSART+NR]_[KUERZEL]
→ Fachname aus fach_bezeichnungen
→ Kursart: GKS → "GK", LK1/LK2 → "LK", AB3/AB4 → "AB"
→ Nummer aus dem Kursart-Teil extrahieren (GK1 → 1, LK2 → 2)
→ Ergebnis: "Q2 Sport GK 1 SZ"
```

---

## Klausuren anlegen: Excel-Paste

**Ablauf:**
1. Nutzer markiert in Excel alle Zeilen inkl. Kopfzeile (Strg+A), kopiert (Strg+C)
2. Klick in Textarea im Browser, Strg+V
3. JS erkennt tab-getrennten Text, parsed ihn clientseitig
4. Vorschau-Tabelle wird angezeigt, Nutzer bestätigt
5. JS sendet JSON an API

**Erwartete Spaltenüberschriften (case-insensitive, Reihenfolge egal):**
`Kurs`, `Datum`, `Uhrzeit`, `Dauer`, `Raum`

- `Kurs` = exaktes `kurs_kuerzel` aus GoMST
- `Datum` = deutsches Format: `31.01.2024` oder leer
- `Uhrzeit` = `08:00` oder `8:00` oder leer
- `Dauer` = Minuten als Zahl oder leer
- `Raum` = beliebiger String oder leer

Fehlende Datums/Zeit/Dauer/Raum-Felder sind erlaubt → `NULL` in DB.
Klausuren ohne Datum werden in der Übersicht **unterhalb** datierter Klausuren angezeigt.

**Direkte Anlage im Tool:** Formular mit Dropdown (Kurs) + Datumsfelder.
Mehrere Klausuren pro Kurs möglich (Klausur 1, 2, …).

---

## LTI 1.3 Integration

**Library:** `celtic-project/LTI-PHP` (via Composer)

**Wichtige LTI-Claims:**

```
Rollen-Claim: https://purl.imsglobal.org/spec/lti/claim/roles
Moodle-Systemadmin-Rolle: http://purl.imsglobal.org/vocab/lis/v2/institution/person#Administrator

Name: given_name, family_name
E-Mail: email
Moodle User ID: sub (oder custom claim)
```

**Bootstrapping:** Wer mit Moodle-Systemadmin-Rolle einloggt, wird automatisch
als `admin` in `rollen` eingetragen (falls noch nicht vorhanden).

**Lehrerkürzel aus Moodle-Nachname extrahieren:**
Moodle speichert z.B. `Gebauer (GB)` → Regex: `/\(([A-ZÄÖÜa-zäöü]+)\)$/`
→ Kürzel `GB` in `benutzer.kuerzel` speichern.

---

## Moodle REST API

Endpunkt: `{MOODLE_URL}/webservices/rest/server.php`

```php
// Alle Nutzer laden
$params = [
    'wstoken'    => MOODLE_API_TOKEN,
    'wsfunction' => 'core_user_get_users',
    'moodlewsrestformat' => 'json',
    'criteria[0][key]'   => 'auth',
    'criteria[0][value]' => 'ldap',  // oder 'manual'
];
```

In `.env`:
```
MOODLE_URL=https://moodle.schule.de
MOODLE_API_TOKEN=xxxx
```

---

## Namensmatching (GoMST ↔ Moodle-Benutzer)

**Automatisch bei Import:**
1. Vollständiger Name aus GoMST: `Nachname Vorname` (z.B. `Mustermann Max`)
2. Vergleich mit `benutzer.nachname + ' ' + benutzer.vorname`
3. Bei exakter Übereinstimmung (case-insensitive, trim): direkt zuordnen
4. Sonst: In `kurs_schueler.schueler_id` bleibt `NULL`

**Manuell durch Stufenleitung/Admin:**
- Seite zeigt zwei Listen:
  - Links: nicht zugeordnete GoMST-Einträge (`schueler_id IS NULL`)
  - Rechts: nicht zugeordnete Moodle-Benutzer (keiner Klausur zugeordnet)
- Per Dropdown oder Drag & Drop zusammenführen
- Auch Lehrer: `kurse.lehrer_id IS NULL` → Kürzel-Matching versuchen, sonst manuell

**Lehrerkürzel-Matching:**
- GoMST-Spalte `Fachlehrer` enthält die Paraphe (z.B. `SZ`)
- `benutzer.kuerzel` (aus LTI-Login extrahiert) mit GoMST-`Fachlehrer` vergleichen

---

## Rollen & Berechtigungen

| Aktion | admin | stufenleitung | lehrkraft | schueler |
|---|:---:|:---:|:---:|:---:|
| Rollen zuweisen | ✓ | – | – | – |
| Stufen verwalten | ✓ | – | – | – |
| Stufenleitung zuweisen | ✓ | – | – | – |
| GoMST importieren | ✓ | ✓* | – | – |
| Nutzerzuordnung manuell | ✓ | ✓* | – | – |
| Klausuren anlegen/bearbeiten | ✓ | ✓* | – | – |
| Nachschreibtermine anlegen | ✓ | ✓* | – | – |
| Anwesenheit eintragen/korrigieren | ✓ | ✓* | ✓** | – |
| Entschuldigungen eintragen | ✓ | ✓* | – | – |
| E-Mail manuell auslösen | ✓ | ✓* | – | – |
| Daten löschen | ✓ | ✓* | – | – |
| Eigene Klausuren + Schüler sehen | ✓ | ✓ | ✓ | – |
| Eigene Klausurtermine sehen | ✓ | ✓ | ✓ | ✓ |

`*` Stufenleitung nur für ihre eigenen Stufen
`**` Lehrkraft nur für ihre eigenen Kurse/Klausuren

**Rollen schließen sich nicht aus.** Eine Person kann gleichzeitig `lehrkraft`
UND `stufenleitung` sein. Die UI zeigt dann alle verfügbaren Bereiche.

---

## E-Mail-System

**Automatisch:** Cronjob (`cron/erinnerungen_senden.php`) läuft stündlich.
- Prüft: Klausur hat `termin_datum` + `termin_uhrzeit`, die in der Vergangenheit liegt
- Noch keine `email_benachrichtigungen` vom Typ `erstmeldung` für diese Klausur?
  → Mail senden
- Erstmeldung gesendet, aber nach 7 Tagen noch keine Antwort?
  → Erinnerungsmail senden (einmalig)

**Cron-Eintrag (Beispiel):**
```
0 * * * * php /var/www/klausurplan/src/Cron/erinnerungen_senden.php
```

**Mail-Inhalt (Erstmeldung):**
```
Betreff: Anwesenheit Klausur [Kursname] am [Datum]

Bitte tragen Sie die Anwesenheit für Ihre Klausur ein.

[Alle waren anwesend]  ← Button → GET /anwesenheit/alle-da?token=XXX
[Jemand hat gefehlt]   ← Button → GET /anwesenheit/eingabe?token=XXX
```

**Token-basierte Seite** (`/anwesenheit/eingabe?token=XXX`):
- Token in `email_benachrichtigungen.token` nachschlagen
- Zeigt Liste aller Prüflinge mit Checkboxen (fehlend ja/nein + Kommentarfeld)
- Kein Login erforderlich (Token = Authentifizierung)
- Formular sendet an `POST /anwesenheit/token-eintrag`
- Nachträgliche Korrekturen: Lehrkraft, Stufenleitung, Admin über normale UI

**PHPMailer-Konfiguration** (aus `.env`):
```
SMTP_HOST=mail.schule.de
SMTP_PORT=587
SMTP_USER=klausurplan@schule.de
SMTP_PASS=xxxx
SMTP_FROM_NAME=Klausurplan
```

---

## API-Endpunkte (public/api.php)

Alle Endpunkte prüfen Session/Rolle, geben JSON zurück.

```
# Authentifizierung / Session
GET  /api/me                          → eigene Nutzerinfos + Rollen

# Admin
GET  /api/admin/benutzer              → alle Benutzer mit Rollen
POST /api/admin/benutzer/{id}/rollen  → Rollen setzen
POST /api/admin/moodle-sync           → Moodle API abfragen, Nutzer aktualisieren
GET  /api/admin/faecher               → Fächerliste
PUT  /api/admin/faecher/{kuerzel}     → Fach bearbeiten

# Stufenleitung
POST /api/stufenleitung/gomst-import         → GoMST-Datei hochladen
GET  /api/stufenleitung/zuordnungen          → nicht zugeordnete Namen
POST /api/stufenleitung/zuordnungen          → manuelle Zuordnung speichern
GET  /api/stufenleitung/anwesenheiten/{halbjahr_id}  → Übersicht
POST /api/stufenleitung/entschuldigung/{anwesenheit_id}
POST /api/stufenleitung/email-ausloesen/{klausur_id}  → manuelle Mail
DELETE /api/stufenleitung/daten/{halbjahr_id}         → Daten löschen

# Nachschreibtermine
GET  /api/nachschreibtermine
POST /api/nachschreibtermine
PUT  /api/nachschreibtermine/{id}
POST /api/nachschreibtermine/{id}/klausuren  → Klausuren verknüpfen

# Klausuren
GET  /api/klausuren                   → eigene (Lehrkraft) oder alle (SL/Admin)
POST /api/klausuren                   → Klausur anlegen
PUT  /api/klausuren/{id}
POST /api/klausuren/paste-import      → Excel-Paste-Daten

# Anwesenheit
GET  /api/anwesenheit/{klausur_id}
POST /api/anwesenheit/{klausur_id}    → Anwesenheiten eintragen/aktualisieren

# Token-basiert (kein Login nötig)
GET  /anwesenheit/alle-da?token=XXX
GET  /anwesenheit/eingabe?token=XXX
POST /anwesenheit/token-eintrag

# Schüler
GET  /api/schueler/meine-klausuren
```

---

## Sicherheit & Datenschutz

- **Alle DB-Zugriffe:** PDO Prepared Statements, niemals String-Konkatenation
- **CSRF-Schutz:** Für alle POST-Requests (Token im HTML/Header)
- **Session:** PHP-Sessions, `session_regenerate_id()` nach Login
- **Token-Links:** `random_bytes(32)` → hex, in DB gespeichert, kein Ablaufdatum
- **Schüler sehen keine anderen Schüler:** API gibt bei Schüler-Rolle nur eigene Daten zurück
- **Keine externen Requests** aus dem Browser (kein CDN)
- **Logs:** Kein Zugriffs-Log, aber PHP-Error-Log aktiv halten
- **Datenlöschung:** Stufenleitung/Admin können Halbjahre inkl. aller Klausuren löschen
  (CASCADE in DB kümmert sich um abhängige Tabellen)
- `.env` niemals im Webroot, nicht committen

---

## .env.example

```ini
# Datenbank
DB_HOST=localhost
DB_NAME=klausurplan
DB_USER=klausurplan_user
DB_PASS=

# LTI 1.3 – Plattformdaten stehen in der DB (Setup-Assistent /setup.php)
LTI_PRIVATE_KEY_FILE=private.key
LTI_KID=klausurplan-key-1

# Moodle REST API
MOODLE_URL=https://moodle.schule.de
MOODLE_API_TOKEN=

# SMTP
SMTP_HOST=mail.schule.de
SMTP_PORT=587
SMTP_USER=klausurplan@schule.de
SMTP_PASS=
SMTP_FROM_NAME=Klausurplan

# App
APP_URL=https://schule.de/klausurplan
APP_ENV=production
```

---

## Implementierungsreihenfolge

Claude Code soll die Phasen **der Reihe nach** abarbeiten. Nach jeder Phase
gibt es funktionierende, testbare Software.

### Phase 1 – Fundament
- [ ] `composer.json` mit Dependencies (`celtic-project/LTI-PHP`, `phpmailer/phpmailer`, `vlucas/phpdotenv`)
- [ ] Migrationen (`migrations/001_schema.sql`, `migrations/002_faecher_lookup.sql`)
- [ ] `Database.php` (PDO-Singleton)
- [ ] `Session.php` (Rollen, Zugriffsschutz)
- [ ] LTI-Launch (`lti-launch.php`, `LtiHandler.php`) inkl. Bootstrapping Admin
- [ ] Basis-Router (`public/api.php`, `Api/Router.php`)
- [ ] `/api/me` Endpunkt

### Phase 2 – Nutzerverwaltung
- [ ] `MoodleApi.php` (Nutzer-Sync)
- [ ] `AdminApi.php` – Benutzer + Rollen verwalten
- [ ] Kürzel-Extraktion beim LTI-Login
- [ ] Fächer-API (lesen + bearbeiten)

### Phase 3 – GoMST-Import & Zuordnung
- [ ] `GomstImporter.php` inkl. Update-Logik
- [ ] Anzeigename-Generierung
- [ ] Automatisches Namensmatching
- [ ] `StufenleitungApi.php` → Import + manuelle Zuordnung
- [ ] Frontend: Zuordnungs-UI

### Phase 4 – Klausurtermine
- [ ] `KlausurPasteParser.php` (Excel-Paste clientseitig in JS + Servervalidierung)
- [ ] `LehrkraftApi.php` – Klausuren CRUD
- [ ] Nachschreibtermine CRUD + Verknüpfung
- [ ] Frontend: Klausurübersicht, Paste-Textarea, Direktanlage-Formular

### Phase 5 – Anwesenheit
- [ ] Anwesenheit-API (eintragen, korrigieren, entschuldigen)
- [ ] Token-basierte Seiten (alle-da, Eingabe)
- [ ] Frontend: Anwesenheitsliste für Lehrkraft

### Phase 6 – E-Mail & Cronjob
- [ ] `Mailer.php`, `EmailTemplates.php`
- [ ] `erinnerungen_senden.php` (CLI)
- [ ] Manuelle Auslösung via API

### Phase 7 – Schüleransicht & Finish
- [ ] `SchuelerApi.php` – eigene Klausuren
- [ ] Frontend: alle Rollen-Views
- [ ] Admin-UI: Fächer-Lookup pflegen, Daten löschen
- [ ] CSS: funktionales, sauberes Layout

---

## Hinweise für Claude Code

- Immer PHP 8.5 Features nutzen (readonly, match, Fibers wenn sinnvoll)
- Typisierung konsequent: Rückgabetypen, Parameter-Typen, `strict_types=1`
- Fehlerbehandlung: Exception-basiert, JSON-Fehlerantworten mit HTTP-Statuscodes
- Keine globalen Variablen außer dem DB-Singleton
- Kommentare auf Deutsch wo sinnvoll
- Vor jeder Phase: kurz zusammenfassen, was implementiert wird
- Nach jeder Phase: Testanweisungen ausgeben (curl-Befehle oder Browser-Schritte)
- **Frontend-Sprache:** Im UI gendergerechte Sprache verwenden, z.B. „Schüler*innen", „Teilnehmende", „Lehrkraft", „Administrator*in". Im PHP-Code und bei DB-Bezeichnern ist das nicht erforderlich.
