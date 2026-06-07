-- migrations/001_schema.sql
-- Klausurplan Datenbankschema

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS benutzer (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    moodle_id       VARCHAR(255) UNIQUE NOT NULL,
    vorname         VARCHAR(100) NOT NULL,
    nachname        VARCHAR(100) NOT NULL,
    email           VARCHAR(255),
    kuerzel         VARCHAR(20),
    zuletzt_gesehen DATETIME,
    erstellt_am     DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rollen (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    benutzer_id INT NOT NULL,
    rolle       ENUM('admin','stufenleitung','lehrkraft','schueler') NOT NULL,
    FOREIGN KEY (benutzer_id) REFERENCES benutzer(id) ON DELETE CASCADE,
    UNIQUE KEY (benutzer_id, rolle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stufen (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(20) NOT NULL,
    schuljahr  VARCHAR(9)  NOT NULL,
    UNIQUE KEY (name, schuljahr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stufenleitungen (
    benutzer_id INT NOT NULL,
    stufe_id    INT NOT NULL,
    PRIMARY KEY (benutzer_id, stufe_id),
    FOREIGN KEY (benutzer_id) REFERENCES benutzer(id) ON DELETE CASCADE,
    FOREIGN KEY (stufe_id)    REFERENCES stufen(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS halbjahre (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    stufe_id      INT NOT NULL,
    abschnitt     TINYINT NOT NULL,
    importiert_am DATETIME DEFAULT CURRENT_TIMESTAMP,
    importiert_von INT,
    FOREIGN KEY (stufe_id)       REFERENCES stufen(id)    ON DELETE RESTRICT,
    FOREIGN KEY (importiert_von) REFERENCES benutzer(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kurse (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    halbjahr_id    INT NOT NULL,
    kurs_kuerzel   VARCHAR(50) NOT NULL,
    fach_kuerzel   VARCHAR(10) NOT NULL,
    kursart        ENUM('GKS','LK1','LK2','AB3','AB4') NOT NULL,
    lehrer_kuerzel VARCHAR(20),
    lehrer_id      INT,
    anzeigename    VARCHAR(150),
    FOREIGN KEY (halbjahr_id) REFERENCES halbjahre(id) ON DELETE CASCADE,
    FOREIGN KEY (lehrer_id)   REFERENCES benutzer(id)  ON DELETE SET NULL,
    UNIQUE KEY (halbjahr_id, kurs_kuerzel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kurs_schueler (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    kurs_id         INT NOT NULL,
    name_roh        VARCHAR(200) NOT NULL,
    schueler_id     INT,
    FOREIGN KEY (kurs_id)     REFERENCES kurse(id)    ON DELETE CASCADE,
    FOREIGN KEY (schueler_id) REFERENCES benutzer(id) ON DELETE SET NULL,
    UNIQUE KEY (kurs_id, name_roh)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS klausuren (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    kurs_id        INT NOT NULL,
    klausur_nr     TINYINT DEFAULT 1,
    termin_datum   DATE,
    termin_uhrzeit TIME,
    dauer_minuten  SMALLINT,
    raum           VARCHAR(100),
    erstellt_am    DATETIME DEFAULT CURRENT_TIMESTAMP,
    erstellt_von   INT,
    FOREIGN KEY (kurs_id)     REFERENCES kurse(id)    ON DELETE CASCADE,
    FOREIGN KEY (erstellt_von) REFERENCES benutzer(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nachschreibtermine (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    termin_datum   DATE,
    termin_uhrzeit TIME,
    raum           VARCHAR(100),
    bemerkung      TEXT,
    erstellt_am    DATETIME DEFAULT CURRENT_TIMESTAMP,
    erstellt_von   INT,
    FOREIGN KEY (erstellt_von) REFERENCES benutzer(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nachschreib_zuordnungen (
    klausur_id           INT NOT NULL,
    nachschreibtermin_id INT NOT NULL,
    PRIMARY KEY (klausur_id, nachschreibtermin_id),
    FOREIGN KEY (klausur_id)           REFERENCES klausuren(id)           ON DELETE CASCADE,
    FOREIGN KEY (nachschreibtermin_id) REFERENCES nachschreibtermine(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS anwesenheiten (
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
    FOREIGN KEY (klausur_id)       REFERENCES klausuren(id)     ON DELETE CASCADE,
    FOREIGN KEY (kurs_schueler_id) REFERENCES kurs_schueler(id) ON DELETE CASCADE,
    FOREIGN KEY (erfasst_von)      REFERENCES benutzer(id)      ON DELETE SET NULL,
    FOREIGN KEY (geaendert_von)    REFERENCES benutzer(id)      ON DELETE SET NULL,
    UNIQUE KEY (klausur_id, kurs_schueler_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nachschreib_anwesenheiten (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_benachrichtigungen (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    klausur_id    INT NOT NULL,
    empfaenger_id INT NOT NULL,
    typ           ENUM('erstmeldung','erinnerung') NOT NULL,
    token         VARCHAR(64) NOT NULL UNIQUE,
    gesendet_am   DATETIME,
    beantwortet_am DATETIME,
    FOREIGN KEY (klausur_id)    REFERENCES klausuren(id) ON DELETE CASCADE,
    FOREIGN KEY (empfaenger_id) REFERENCES benutzer(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fach_bezeichnungen (
    kuerzel     VARCHAR(10) PRIMARY KEY,
    bezeichnung VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
