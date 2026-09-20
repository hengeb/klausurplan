-- Dauerhafte Namens-/Kürzel-Zuordnungen, externe Lehrkräfte und
-- Übersichtsmails an die Stufenleitung.
-- Für bestehende Installationen; mehrfaches Ausführen ist unbedenklich.
-- (Neuinstallationen: bereits in 001_schema.sql enthalten.)

SET NAMES utf8mb4;

ALTER TABLE benutzer ADD COLUMN IF NOT EXISTS extern TINYINT(1) NOT NULL DEFAULT 0 AFTER stufe;

CREATE TABLE IF NOT EXISTS schueler_zuordnungen (
    name_roh      VARCHAR(200) NOT NULL PRIMARY KEY,
    benutzer_id   INT DEFAULT NULL,
    geaendert_von INT DEFAULT NULL,
    geaendert_am  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (benutzer_id)   REFERENCES benutzer(id) ON DELETE CASCADE,
    FOREIGN KEY (geaendert_von) REFERENCES benutzer(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lehrer_zuordnungen (
    lehrer_kuerzel VARCHAR(20) NOT NULL PRIMARY KEY,
    benutzer_id    INT DEFAULT NULL,
    geaendert_von  INT DEFAULT NULL,
    geaendert_am   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (benutzer_id)   REFERENCES benutzer(id) ON DELETE CASCADE,
    FOREIGN KEY (geaendert_von) REFERENCES benutzer(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stufenleitung_erinnerungen (
    klausur_id  INT NOT NULL,
    benutzer_id INT NOT NULL,
    gesendet_am DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (klausur_id, benutzer_id),
    FOREIGN KEY (klausur_id)  REFERENCES klausuren(id) ON DELETE CASCADE,
    FOREIGN KEY (benutzer_id) REFERENCES benutzer(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bereits vorhandene manuelle Zuordnungen retten: Was jetzt in kurs_schueler bzw. kurse
-- steht, wird als dauerhafte Zuordnung übernommen (auch wenn nicht bekannt ist, ob sie
-- manuell oder automatisch entstanden ist – beide sind gleichermaßen korrekturfähig).
INSERT IGNORE INTO schueler_zuordnungen (name_roh, benutzer_id)
    SELECT name_roh, MIN(schueler_id) FROM kurs_schueler
    WHERE schueler_id IS NOT NULL GROUP BY name_roh;

INSERT IGNORE INTO lehrer_zuordnungen (lehrer_kuerzel, benutzer_id)
    SELECT lehrer_kuerzel, MIN(lehrer_id) FROM kurse
    WHERE lehrer_kuerzel IS NOT NULL AND lehrer_id IS NOT NULL GROUP BY lehrer_kuerzel;
