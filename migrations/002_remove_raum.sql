-- Entfernt das Attribut "raum" aus klausuren und nachschreibtermine.

ALTER TABLE klausuren         DROP COLUMN IF EXISTS raum;
ALTER TABLE nachschreibtermine DROP COLUMN IF EXISTS raum;
