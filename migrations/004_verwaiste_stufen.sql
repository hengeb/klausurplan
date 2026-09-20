-- Entfernt verwaiste Stufen: Stufen, zu denen es kein Halbjahr mehr gibt.
-- Sie stammen aus älteren Versionen (Halbjahr gelöscht, Stufe blieb übrig) und
-- tauchten sonst in der Stufenauswahl der Stufenleitungen auf.
-- Zuordnungen in stufenleitungen verschwinden per ON DELETE CASCADE mit.
-- Mehrfaches Ausführen ist unbedenklich.

DELETE FROM stufen
 WHERE NOT EXISTS (SELECT 1 FROM halbjahre h WHERE h.stufe_id = stufen.id);
