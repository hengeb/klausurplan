-- Entfernt die Übersichtsmail an die Stufenleitung wieder: Die Stufenleitung möchte
-- keine eigenen Erinnerungen erhalten (nur die Fachlehrkräfte, jetzt täglich statt wöchentlich).
-- Mehrfaches Ausführen ist unbedenklich.

DROP TABLE IF EXISTS stufenleitung_erinnerungen;
