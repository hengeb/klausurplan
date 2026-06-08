-- Klausurplan – vollständiges Datenbankschema (Erstinstallation)

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

-- ------------------------------------------------------------------
-- Anwendungstabellen
-- ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS benutzer (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    moodle_id       VARCHAR(255) UNIQUE NOT NULL,
    vorname         VARCHAR(100) NOT NULL,
    nachname        VARCHAR(100) NOT NULL,
    email           VARCHAR(255),
    kuerzel         VARCHAR(20),
    stufe           VARCHAR(20) DEFAULT NULL,
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
    id        INT AUTO_INCREMENT PRIMARY KEY,
    name      VARCHAR(20) NOT NULL,
    schuljahr VARCHAR(9)  NOT NULL,
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
    id             INT AUTO_INCREMENT PRIMARY KEY,
    stufe_id       INT NOT NULL,
    abschnitt      TINYINT NOT NULL,
    importiert_am  DATETIME DEFAULT CURRENT_TIMESTAMP,
    importiert_von INT,
    FOREIGN KEY (stufe_id)       REFERENCES stufen(id)   ON DELETE RESTRICT,
    FOREIGN KEY (importiert_von) REFERENCES benutzer(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- kursart vereinfacht: LK1/LK2 → LK, GKS/AB3/AB4 → GK
CREATE TABLE IF NOT EXISTS kurse (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    halbjahr_id    INT NOT NULL,
    kurs_kuerzel   VARCHAR(50) NOT NULL,
    fach_kuerzel   VARCHAR(10) NOT NULL,
    kursart        ENUM('LK','GK') NOT NULL,
    lehrer_kuerzel VARCHAR(20),
    lehrer_id      INT,
    anzeigename    VARCHAR(150),
    FOREIGN KEY (halbjahr_id) REFERENCES halbjahre(id) ON DELETE CASCADE,
    FOREIGN KEY (lehrer_id)   REFERENCES benutzer(id)  ON DELETE SET NULL,
    UNIQUE KEY (halbjahr_id, kurs_kuerzel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- kursart: individuelle AB-/LK-Zuordnung im Abitur (GKS, LK1, LK2, AB3, AB4)
CREATE TABLE IF NOT EXISTS kurs_schueler (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    kurs_id     INT NOT NULL,
    name_roh    VARCHAR(200) NOT NULL,
    kursart     ENUM('GKS','LK1','LK2','AB3','AB4') DEFAULT NULL,
    schueler_id INT,
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
    erstellt_am    DATETIME DEFAULT CURRENT_TIMESTAMP,
    erstellt_von   INT,
    FOREIGN KEY (kurs_id)      REFERENCES kurse(id)    ON DELETE CASCADE,
    FOREIGN KEY (erstellt_von) REFERENCES benutzer(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nachschreibtermine (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    termin_datum   DATE,
    termin_uhrzeit TIME,
    bemerkung      TEXT,
    erstellt_am    DATETIME DEFAULT CURRENT_TIMESTAMP,
    erstellt_von   INT,
    FOREIGN KEY (erstellt_von) REFERENCES benutzer(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nachschreib_zuordnungen (
    klausur_id           INT NOT NULL,
    nachschreibtermin_id INT NOT NULL,
    PRIMARY KEY (klausur_id, nachschreibtermin_id),
    FOREIGN KEY (klausur_id)           REFERENCES klausuren(id)          ON DELETE CASCADE,
    FOREIGN KEY (nachschreibtermin_id) REFERENCES nachschreibtermine(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- entschuldigt: NULL = offen, TRUE = entschuldigt, FALSE = unentschuldigt
CREATE TABLE IF NOT EXISTS anwesenheiten (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    klausur_id       INT NOT NULL,
    kurs_schueler_id INT NOT NULL,
    status           ENUM('anwesend','fehlend','ausstehend') DEFAULT 'ausstehend',
    entschuldigt     BOOLEAN DEFAULT NULL,
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
    entschuldigt         BOOLEAN DEFAULT NULL,
    kommentar            TEXT,
    erfasst_von          INT,
    erfasst_am           DATETIME,
    FOREIGN KEY (nachschreibtermin_id) REFERENCES nachschreibtermine(id) ON DELETE CASCADE,
    FOREIGN KEY (kurs_schueler_id)     REFERENCES kurs_schueler(id)      ON DELETE CASCADE,
    FOREIGN KEY (erfasst_von)          REFERENCES benutzer(id)           ON DELETE SET NULL,
    UNIQUE KEY (nachschreibtermin_id, kurs_schueler_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_benachrichtigungen (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    klausur_id     INT NOT NULL,
    empfaenger_id  INT NOT NULL,
    typ            ENUM('erstmeldung','erinnerung') NOT NULL,
    token          VARCHAR(64) NOT NULL UNIQUE,
    gesendet_am    DATETIME,
    beantwortet_am DATETIME,
    FOREIGN KEY (klausur_id)    REFERENCES klausuren(id) ON DELETE CASCADE,
    FOREIGN KEY (empfaenger_id) REFERENCES benutzer(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fach_bezeichnungen (
    kuerzel     VARCHAR(10) PRIMARY KEY,
    bezeichnung VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- LTI 1.3-Tabellen (longhornopen/lti)
-- ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS lti2_consumer (
    consumer_pk      INT(11)       NOT NULL AUTO_INCREMENT,
    name             VARCHAR(50)   NOT NULL,
    consumer_key     VARCHAR(255)  DEFAULT NULL,
    secret           VARCHAR(1024) DEFAULT NULL,
    platform_id      VARCHAR(255)  DEFAULT NULL,
    client_id        VARCHAR(255)  DEFAULT NULL,
    deployment_id    VARCHAR(255)  DEFAULT NULL,
    public_key       TEXT          DEFAULT NULL,
    lti_version      VARCHAR(10)   DEFAULT NULL,
    signature_method VARCHAR(15)   NOT NULL DEFAULT 'HMAC-SHA1',
    consumer_name    VARCHAR(255)  DEFAULT NULL,
    consumer_version VARCHAR(255)  DEFAULT NULL,
    consumer_guid    VARCHAR(1024) DEFAULT NULL,
    profile          TEXT          DEFAULT NULL,
    tool_proxy       TEXT          DEFAULT NULL,
    settings         TEXT          DEFAULT NULL,
    protected        TINYINT(1)    NOT NULL,
    enabled          TINYINT(1)    NOT NULL,
    enable_from      DATETIME      DEFAULT NULL,
    enable_until     DATETIME      DEFAULT NULL,
    last_access      DATE          DEFAULT NULL,
    created          DATETIME      NOT NULL,
    updated          DATETIME      NOT NULL,
    PRIMARY KEY (consumer_pk),
    UNIQUE KEY lti2_consumer_consumer_key_UNIQUE (consumer_key),
    UNIQUE KEY lti2_consumer_platform_UNIQUE (platform_id, client_id, deployment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lti2_nonce (
    consumer_pk INT(11)     NOT NULL,
    value       VARCHAR(50) NOT NULL,
    expires     DATETIME    NOT NULL,
    PRIMARY KEY (consumer_pk, value),
    CONSTRAINT lti2_nonce_lti2_consumer_FK1
        FOREIGN KEY (consumer_pk) REFERENCES lti2_consumer (consumer_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lti2_access_token (
    consumer_pk INT(11)       NOT NULL,
    scopes      TEXT          NOT NULL,
    token       VARCHAR(2000) NOT NULL,
    expires     DATETIME      NOT NULL,
    created     DATETIME      NOT NULL,
    updated     DATETIME      NOT NULL,
    PRIMARY KEY (consumer_pk),
    CONSTRAINT lti2_access_token_lti2_consumer_FK1
        FOREIGN KEY (consumer_pk) REFERENCES lti2_consumer (consumer_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lti2_context (
    context_pk     INT(11)      NOT NULL AUTO_INCREMENT,
    consumer_pk    INT(11)      NOT NULL,
    title          VARCHAR(255) DEFAULT NULL,
    lti_context_id VARCHAR(255) NOT NULL,
    type           VARCHAR(50)  DEFAULT NULL,
    settings       TEXT         DEFAULT NULL,
    created        DATETIME     NOT NULL,
    updated        DATETIME     NOT NULL,
    PRIMARY KEY (context_pk),
    CONSTRAINT lti2_context_lti2_consumer_FK1
        FOREIGN KEY (consumer_pk) REFERENCES lti2_consumer (consumer_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lti2_resource_link (
    resource_link_pk         INT(11)      AUTO_INCREMENT,
    context_pk               INT(11)      DEFAULT NULL,
    consumer_pk              INT(11)      DEFAULT NULL,
    title                    VARCHAR(255) DEFAULT NULL,
    lti_resource_link_id     VARCHAR(255) NOT NULL,
    settings                 TEXT,
    primary_resource_link_pk INT(11)      DEFAULT NULL,
    share_approved           TINYINT(1)   DEFAULT NULL,
    created                  DATETIME     NOT NULL,
    updated                  DATETIME     NOT NULL,
    PRIMARY KEY (resource_link_pk),
    CONSTRAINT lti2_resource_link_lti2_consumer_FK1
        FOREIGN KEY (consumer_pk) REFERENCES lti2_consumer (consumer_pk),
    CONSTRAINT lti2_resource_link_lti2_context_FK1
        FOREIGN KEY (context_pk) REFERENCES lti2_context (context_pk),
    CONSTRAINT lti2_resource_link_lti2_resource_link_FK1
        FOREIGN KEY (primary_resource_link_pk) REFERENCES lti2_resource_link (resource_link_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lti2_user_result (
    user_result_pk       INT(11)       AUTO_INCREMENT,
    resource_link_pk     INT(11)       NOT NULL,
    lti_user_id          VARCHAR(255)  NOT NULL,
    lti_result_sourcedid VARCHAR(1024) NOT NULL,
    created              DATETIME      NOT NULL,
    updated              DATETIME      NOT NULL,
    PRIMARY KEY (user_result_pk),
    CONSTRAINT lti2_user_result_lti2_resource_link_FK1
        FOREIGN KEY (resource_link_pk) REFERENCES lti2_resource_link (resource_link_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lti2_share_key (
    share_key_id     VARCHAR(32) NOT NULL,
    resource_link_pk INT(11)     NOT NULL,
    auto_approve     TINYINT(1)  NOT NULL,
    expires          DATETIME    NOT NULL,
    PRIMARY KEY (share_key_id),
    CONSTRAINT lti2_share_key_lti2_resource_link_FK1
        FOREIGN KEY (resource_link_pk) REFERENCES lti2_resource_link (resource_link_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET foreign_key_checks = 1;

-- ------------------------------------------------------------------
-- Stammdaten: Fächerbezeichnungen NRW Oberstufe
-- ------------------------------------------------------------------

INSERT INTO fach_bezeichnungen (kuerzel, bezeichnung) VALUES
('BI',  'Biologie'),
('CH',  'Chemie'),
('D',   'Deutsch'),
('E',   'Englisch'),
('EK',  'Erdkunde'),
('ER',  'Evangelische Religionslehre'),
('F',   'Französisch'),
('GE',  'Geschichte'),
('GR',  'Griechisch'),
('IF',  'Informatik'),
('IT',  'Italienisch'),
('KR',  'Katholische Religionslehre'),
('KU',  'Kunst'),
('L',   'Latein'),
('LI',  'Literatur'),
('M',   'Mathematik'),
('MU',  'Musik'),
('NL',  'Niederländisch'),
('PA',  'Pädagogik'),
('PH',  'Physik'),
('PL',  'Philosophie'),
('PS',  'Psychologie'),
('RK',  'Rechtskunde'),
('RU',  'Russisch'),
('SPA', 'Sport'),
('SW',  'Sozialwissenschaften'),
('VO',  'Vokalpraktischer Kurs'),
('WI',  'Wirtschaft'),
('ZG',  'Zusatzkurs Gesellschaft'),
('ZH',  'Chinesisch'),
('ZN',  'Zusatzkurs Naturwissenschaften')
ON DUPLICATE KEY UPDATE bezeichnung = VALUES(bezeichnung);
