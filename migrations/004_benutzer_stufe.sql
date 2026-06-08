-- Stufe der Schüler*innen aus dem Moodle-Customfield "klasse" (z.B. "EF", "Q1", "Q2")
ALTER TABLE benutzer ADD COLUMN stufe VARCHAR(20) DEFAULT NULL AFTER kuerzel;
