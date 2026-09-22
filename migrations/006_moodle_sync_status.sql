-- Merkt den Zeitpunkt der letzten Moodle-Synchronisierung, damit der Cronjob
-- (cron_script.php) höchstens einmal täglich automatisch synchronisiert.
-- Mehrfaches Ausführen ist unbedenklich.

CREATE TABLE IF NOT EXISTS moodle_sync_status (
    id         TINYINT  NOT NULL PRIMARY KEY,
    zuletzt_am DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
