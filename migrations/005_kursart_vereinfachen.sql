-- Kursart auf kurse vereinfachen: LK1/LK2 → LK, GKS/AB3/AB4 → GK
-- Muss laufen BEVOR die ENUM-Definition geändert wird.

UPDATE kurse SET kursart = 'LK' WHERE kursart IN ('LK1', 'LK2');
UPDATE kurse SET kursart = 'GK' WHERE kursart IN ('GKS', 'AB3', 'AB4');

ALTER TABLE kurse MODIFY COLUMN kursart ENUM('LK', 'GK') NOT NULL;

-- Detaillierte Kursart pro Schüler*in (LK1, LK2, AB3, AB4, GKS = individuelle Zuordnung im Abitur)
ALTER TABLE kurs_schueler
    ADD COLUMN kursart ENUM('GKS','LK1','LK2','AB3','AB4') DEFAULT NULL AFTER name_roh;
