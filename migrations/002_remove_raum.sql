-- Entfernt das Attribut "raum" aus klausuren und nachschreibtermine.

ALTER TABLE klausuren          DROP COLUMN raum;
ALTER TABLE nachschreibtermine DROP COLUMN raum;
