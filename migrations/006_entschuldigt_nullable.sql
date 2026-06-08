-- Entschuldigungsstatus: NULL = offen, 1 = entschuldigt, 0 = unentschuldigt
-- Bisher: DEFAULT FALSE → alle neuen Fehlend-Einträge galten als unentschuldigt
-- Ab jetzt: DEFAULT NULL → Standardzustand ist "noch offen"

ALTER TABLE anwesenheiten
    MODIFY COLUMN entschuldigt BOOLEAN DEFAULT NULL;

ALTER TABLE nachschreib_anwesenheiten
    MODIFY COLUMN entschuldigt BOOLEAN DEFAULT NULL;
