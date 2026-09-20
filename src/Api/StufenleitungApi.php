<?php

declare(strict_types=1);

namespace Klausurplan\Api;

use Klausurplan\Auth\Session;
use Klausurplan\Import\GomstImporter;
use Klausurplan\Mail\EmailTemplates;
use Klausurplan\Mail\Mailer;
use Klausurplan\Models\Database;
use Klausurplan\Models\Zuordnung;
use RuntimeException;

class StufenleitungApi
{
    // ------------------------------------------------------------------
    // GoMST-Import
    // ------------------------------------------------------------------

    /**
     * Verarbeitet einen GoMST-Datei-Upload (multipart/form-data, Feld "datei").
     *
     * Wer die Rolle Stufenleitung hat, wird für alle importierten Stufen automatisch
     * Stufenleitung. Die dabei neu hinzugekommenen Stufen stehen in `stufenleitung_neu`
     * (damit die Oberfläche sie anzeigen und per Klick wieder abgeben lassen kann).
     *
     * @return array{kurse: int, schueler: int, entfernt: int, halbjahre: int,
     *               stufenleitung_neu: list<array{id: int, name: string, schuljahr: string}>}
     */
    public static function gomstImport(): array
    {
        Session::requireRolle('admin', 'stufenleitung');

        if (!isset($_FILES['datei']) || $_FILES['datei']['error'] !== UPLOAD_ERR_OK) {
            $fehlerCode = $_FILES['datei']['error'] ?? -1;
            http_response_code(400);
            throw new RuntimeException("Keine gültige Datei hochgeladen (Fehlercode: $fehlerCode).");
        }

        $inhalt = file_get_contents($_FILES['datei']['tmp_name']);
        if ($inhalt === false || $inhalt === '') {
            throw new RuntimeException('Datei konnte nicht gelesen werden oder ist leer.');
        }

        $db         = Database::getInstance();
        $benutzer   = Session::getBenutzer();
        $benutzerId = (int) $benutzer['id'];
        $istSL      = in_array('stufenleitung', $benutzer['rollen'] ?? [], true);

        $vorher = $istSL ? self::eigeneStufenIds($db, $benutzerId) : [];

        $importer = new GomstImporter($benutzerId);
        $ergebnis = $importer->importiere($inhalt);

        $stufenIds = $ergebnis['stufen_ids'];
        unset($ergebnis['stufen_ids']);

        $neu = [];
        if ($istSL && !empty($stufenIds)) {
            $ins = $db->prepare('INSERT IGNORE INTO stufenleitungen (benutzer_id, stufe_id) VALUES (?, ?)');
            foreach ($stufenIds as $stufeId) {
                $ins->execute([$benutzerId, $stufeId]);
            }

            // „Neu“ = vor dem Import noch nicht zuständig (auch wenn die Übernahme
            // von der Vorgängerstufe schon beim Anlegen der Stufe passiert ist)
            $ph   = implode(',', array_fill(0, count($stufenIds), '?'));
            $stmt = $db->prepare(
                "SELECT s.id, s.name, s.schuljahr
                 FROM stufen s
                 JOIN stufenleitungen sl ON sl.stufe_id = s.id AND sl.benutzer_id = ?
                 WHERE s.id IN ($ph)
                 ORDER BY s.schuljahr DESC, s.name"
            );
            $stmt->execute([$benutzerId, ...$stufenIds]);
            foreach ($stmt->fetchAll() as $stufe) {
                if (!in_array((int) $stufe['id'], $vorher, true)) {
                    $neu[] = [
                        'id'        => (int) $stufe['id'],
                        'name'      => $stufe['name'],
                        'schuljahr' => $stufe['schuljahr'],
                    ];
                }
            }
        }

        $ergebnis['stufenleitung_neu'] = $neu;
        return $ergebnis;
    }

    /** @return list<int> Stufen-IDs, für die der Benutzer Stufenleitung ist. */
    private static function eigeneStufenIds(\PDO $db, int $benutzerId): array
    {
        $stmt = $db->prepare('SELECT stufe_id FROM stufenleitungen WHERE benutzer_id = ?');
        $stmt->execute([$benutzerId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    // ------------------------------------------------------------------
    // Eigene Stufen verwalten (Self-Service der Stufenleitung)
    // ------------------------------------------------------------------

    /**
     * Alle Stufen mit Flag, ob die angemeldete Person dafür zuständig ist.
     * Jede Stufenleitung verwaltet ihre Zuständigkeit selbst – ohne Admin.
     *
     * @return list<array{id: int, name: string, schuljahr: string, ist_meine: int}>
     */
    public static function getMeineStufen(): array
    {
        Session::requireRolle('stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare(
            "SELECT s.id, s.name, s.schuljahr,
                    (sl.benutzer_id IS NOT NULL) AS ist_meine
             FROM stufen s
             LEFT JOIN stufenleitungen sl ON sl.stufe_id = s.id AND sl.benutzer_id = ?
             ORDER BY s.schuljahr DESC, s.name"
        );
        $stmt->execute([Session::getBenutzerId()]);
        return $stmt->fetchAll();
    }

    /** Übernimmt die Zuständigkeit für eine Stufe. */
    public static function meineStufeUebernehmen(int $stufeId): array
    {
        Session::requireRolle('stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT 1 FROM stufen WHERE id = ?');
        $stmt->execute([$stufeId]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Stufe $stufeId nicht gefunden.");
        }

        $db->prepare('INSERT IGNORE INTO stufenleitungen (benutzer_id, stufe_id) VALUES (?, ?)')
           ->execute([Session::getBenutzerId(), $stufeId]);

        return ['ok' => true];
    }

    /** Gibt die Zuständigkeit für eine Stufe ab. */
    public static function meineStufeAbgeben(int $stufeId): array
    {
        Session::requireRolle('stufenleitung');
        $db = Database::getInstance();

        $db->prepare('DELETE FROM stufenleitungen WHERE benutzer_id = ? AND stufe_id = ?')
           ->execute([Session::getBenutzerId(), $stufeId]);

        return ['ok' => true];
    }

    // ------------------------------------------------------------------
    // Zuordnungen abrufen
    // ------------------------------------------------------------------

    /**
     * Liefert die Daten für die Zuordnungs-UI:
     * - schueler_gomst:        GoMST-Namen ohne Moodle-Konto
     * - schueler_moodle:       Moodle-Konten (ohne Lehrkräfte), die noch keinem GoMST-Namen zugeordnet sind
     * - schueler_zugeordnet:   bereits zugeordnete GoMST-Namen (zum Korrigieren/Aufheben)
     * - lehrkraefte_kurse:     Lehrerkürzel ohne Lehrkraft
     * - lehrkraefte_moodle:    alle Lehrkräfte (inkl. externer) mit Flag `vergeben`
     * - lehrkraefte_zugeordnet: bereits zugeordnete Kürzel (zum Korrigieren/Aufheben)
     * - externe_lehrkraefte:   Lehrkräfte ohne Moodle-Konto
     */
    public static function getZuordnungen(): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        // Nicht zugeordnete Prüflinge – GoMST-Einträge (mit '|') und Zusatzschüler
        $schuelerGomst = $db->query(
            "SELECT ks.name_roh,
                    COUNT(DISTINCT ks.id)                                         AS anzahl_kurse,
                    GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ')  AS stufen
             FROM kurs_schueler ks
             JOIN kurse k     ON k.id = ks.kurs_id
             JOIN halbjahre h ON h.id = k.halbjahr_id
             JOIN stufen s    ON s.id = h.stufe_id
             WHERE ks.schueler_id IS NULL
             GROUP BY ks.name_roh
             ORDER BY ks.name_roh"
        )->fetchAll();

        // Moodle-Konten, die noch keinem GoMST-Namen zugeordnet sind (weder aktuell noch dauerhaft)
        $schuelerMoodle = $db->query(
            "SELECT b.id, b.vorname, b.nachname, b.stufe
             FROM benutzer b
             WHERE NOT EXISTS (SELECT 1 FROM kurs_schueler ks WHERE ks.schueler_id = b.id)
               AND NOT EXISTS (SELECT 1 FROM schueler_zuordnungen z WHERE z.benutzer_id = b.id)
               AND NOT EXISTS (SELECT 1 FROM rollen r WHERE r.benutzer_id = b.id AND r.rolle = 'lehrkraft')
             ORDER BY b.nachname, b.vorname"
        )->fetchAll();

        // Bereits zugeordnete Namen: aktuell in Kursen vorkommende …
        $schuelerZugeordnet = $db->query(
            "SELECT ks.name_roh,
                    ks.schueler_id,
                    b.vorname, b.nachname, b.stufe AS moodle_stufe,
                    COUNT(DISTINCT ks.id)                                         AS anzahl_kurse,
                    GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ')  AS stufen,
                    MAX(z.name_roh IS NOT NULL)                                   AS manuell
             FROM kurs_schueler ks
             JOIN benutzer b  ON b.id = ks.schueler_id
             JOIN kurse k     ON k.id = ks.kurs_id
             JOIN halbjahre h ON h.id = k.halbjahr_id
             JOIN stufen s    ON s.id = h.stufe_id
             LEFT JOIN schueler_zuordnungen z ON z.name_roh = ks.name_roh
             WHERE ks.schueler_id IS NOT NULL
             GROUP BY ks.name_roh, ks.schueler_id, b.vorname, b.nachname, b.stufe"
        )->fetchAll();

        // … und gespeicherte Zuordnungen, deren Kurse (noch) nicht vorhanden sind
        $schuelerGespeichert = $db->query(
            "SELECT z.name_roh,
                    z.benutzer_id AS schueler_id,
                    b.vorname, b.nachname, b.stufe AS moodle_stufe,
                    0 AS anzahl_kurse, '' AS stufen, 1 AS manuell
             FROM schueler_zuordnungen z
             JOIN benutzer b ON b.id = z.benutzer_id
             WHERE NOT EXISTS (SELECT 1 FROM kurs_schueler ks WHERE ks.name_roh = z.name_roh)"
        )->fetchAll();

        $schuelerZugeordnet = array_merge($schuelerZugeordnet, $schuelerGespeichert);
        usort($schuelerZugeordnet, static fn (array $x, array $y): int
            => strnatcasecmp($x['name_roh'], $y['name_roh']));

        // Personen mit unbekanntem Kürzel – eine Zeile pro Kürzel
        $lehrkraefteKurse = $db->query(
            "SELECT k.lehrer_kuerzel,
                    COUNT(DISTINCT k.id) AS anzahl_kurse
             FROM kurse k
             WHERE k.lehrer_kuerzel IS NOT NULL
               AND k.lehrer_id IS NULL
             GROUP BY k.lehrer_kuerzel
             ORDER BY k.lehrer_kuerzel"
        )->fetchAll();

        // Alle Lehrkräfte (auch externe); `vergeben` = hat Kurse oder eine dauerhafte Kürzel-Zuordnung
        $lehrkraefteMoodle = $db->query(
            "SELECT b.id, b.vorname, b.nachname, b.kuerzel, b.extern,
                    (EXISTS (SELECT 1 FROM kurse k WHERE k.lehrer_id = b.id)
                     OR EXISTS (SELECT 1 FROM lehrer_zuordnungen z WHERE z.benutzer_id = b.id)) AS vergeben
             FROM benutzer b
             WHERE EXISTS (SELECT 1 FROM rollen r WHERE r.benutzer_id = b.id AND r.rolle = 'lehrkraft')
             ORDER BY b.nachname, b.vorname"
        )->fetchAll();

        // Bereits zugeordnete Kürzel …
        $lehrkraefteZugeordnet = $db->query(
            "SELECT k.lehrer_kuerzel,
                    k.lehrer_id,
                    b.vorname, b.nachname, b.kuerzel, b.extern,
                    COUNT(DISTINCT k.id)                AS anzahl_kurse,
                    MAX(z.lehrer_kuerzel IS NOT NULL)   AS manuell
             FROM kurse k
             JOIN benutzer b ON b.id = k.lehrer_id
             LEFT JOIN lehrer_zuordnungen z ON z.lehrer_kuerzel = k.lehrer_kuerzel
             WHERE k.lehrer_kuerzel IS NOT NULL AND k.lehrer_id IS NOT NULL
             GROUP BY k.lehrer_kuerzel, k.lehrer_id, b.vorname, b.nachname, b.kuerzel, b.extern"
        )->fetchAll();

        // … und gespeicherte Kürzel-Zuordnungen ohne aktuelle Kurse
        $lehrkraefteGespeichert = $db->query(
            "SELECT z.lehrer_kuerzel,
                    z.benutzer_id AS lehrer_id,
                    b.vorname, b.nachname, b.kuerzel, b.extern,
                    0 AS anzahl_kurse, 1 AS manuell
             FROM lehrer_zuordnungen z
             JOIN benutzer b ON b.id = z.benutzer_id
             WHERE NOT EXISTS (SELECT 1 FROM kurse k WHERE k.lehrer_kuerzel = z.lehrer_kuerzel)"
        )->fetchAll();

        $lehrkraefteZugeordnet = array_merge($lehrkraefteZugeordnet, $lehrkraefteGespeichert);
        usort($lehrkraefteZugeordnet, static fn (array $x, array $y): int
            => strnatcasecmp($x['lehrer_kuerzel'], $y['lehrer_kuerzel']));

        return [
            'schueler_gomst'         => $schuelerGomst,
            'schueler_moodle'        => $schuelerMoodle,
            'schueler_zugeordnet'    => $schuelerZugeordnet,
            'lehrkraefte_kurse'      => $lehrkraefteKurse,
            'lehrkraefte_moodle'     => $lehrkraefteMoodle,
            'lehrkraefte_zugeordnet' => $lehrkraefteZugeordnet,
            'externe_lehrkraefte'    => self::externeLehrkraefte($db),
        ];
    }

    /**
     * Alle Moodle-Konten ohne Lehrkraft-Rolle (für das Korrigieren einer Zuordnung).
     * `vergeben` = bereits einem GoMST-Namen zugeordnet.
     */
    public static function getMoodleSchueler(): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        return $db->query(
            "SELECT b.id, b.vorname, b.nachname, b.stufe,
                    (EXISTS (SELECT 1 FROM kurs_schueler ks WHERE ks.schueler_id = b.id)
                     OR EXISTS (SELECT 1 FROM schueler_zuordnungen z WHERE z.benutzer_id = b.id)) AS vergeben
             FROM benutzer b
             WHERE NOT EXISTS (SELECT 1 FROM rollen r WHERE r.benutzer_id = b.id AND r.rolle = 'lehrkraft')
             ORDER BY b.nachname, b.vorname"
        )->fetchAll();
    }

    // ------------------------------------------------------------------
    // Manuelle Zuordnung speichern
    // ------------------------------------------------------------------

    /**
     * Speichert eine manuelle Zuordnung – dauerhaft und personenbezogen, nicht kursbezogen.
     * Die Zuordnung überlebt das Löschen von Kursen und wird bei jedem Import wieder angewendet.
     *
     * Body für Schüler*innen:
     *   { "typ": "schueler", "name_roh": "Mustermann|Max", "benutzer_id": 17 }
     *   Aktualisiert ALLE kurs_schueler-Einträge mit diesem name_roh.
     *
     * Body für Lehrkräfte:
     *   { "typ": "lehrkraft", "lehrer_kuerzel": "SZ", "benutzer_id": 23 }
     *   Aktualisiert ALLE Kurse mit diesem lehrer_kuerzel.
     *
     * benutzer_id = null hebt die Zuordnung auf; es wird dann auch nicht mehr automatisch
     * zugeordnet, bis eine neue Zuordnung gespeichert wird.
     */
    public static function postZuordnung(array $body): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db  = Database::getInstance();
        $typ = $body['typ'] ?? '';

        $benutzerId = isset($body['benutzer_id']) && $body['benutzer_id'] !== null
            ? (int) $body['benutzer_id']
            : null;

        if ($typ !== 'schueler' && $typ !== 'lehrkraft') {
            http_response_code(400);
            throw new RuntimeException("Unbekannter Typ '$typ'. Erwartet: 'schueler' oder 'lehrkraft'.");
        }

        if ($benutzerId !== null) {
            $stmt = $db->prepare('SELECT 1 FROM benutzer WHERE id = ?');
            $stmt->execute([$benutzerId]);
            if ($stmt->fetchColumn() === false) {
                http_response_code(404);
                throw new RuntimeException("Benutzer*in $benutzerId nicht gefunden.");
            }
        }

        $vonId = Session::getBenutzerId();

        if ($typ === 'schueler') {
            $nameRoh = trim((string) ($body['name_roh'] ?? ''));
            if ($nameRoh === '') {
                http_response_code(400);
                throw new RuntimeException('name_roh fehlt.');
            }

            return [
                'ok'           => true,
                'aktualisiert' => Zuordnung::speichereSchuelerZuordnung($db, $nameRoh, $benutzerId, $vonId),
            ];
        }

        $lehrerKuerzel = trim((string) ($body['lehrer_kuerzel'] ?? ''));
        if ($lehrerKuerzel === '') {
            http_response_code(400);
            throw new RuntimeException('lehrer_kuerzel fehlt.');
        }

        return [
            'ok'           => true,
            'aktualisiert' => Zuordnung::speichereLehrerZuordnung($db, $lehrerKuerzel, $benutzerId, $vonId),
        ];
    }

    // ------------------------------------------------------------------
    // Externe Lehrkräfte (ohne Moodle-Konto)
    // ------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private static function externeLehrkraefte(\PDO $db): array
    {
        return $db->query(
            "SELECT b.id, b.vorname, b.nachname, b.kuerzel, b.email,
                    (SELECT COUNT(*) FROM kurse k WHERE k.lehrer_id = b.id) AS anzahl_kurse
             FROM benutzer b
             WHERE b.extern = 1
             ORDER BY b.nachname, b.vorname"
        )->fetchAll();
    }

    /**
     * Legt eine externe Lehrkraft an (kein Moodle-Konto, z.B. weil die Klausur an einer
     * anderen Schule geschrieben wird). Sie erhält die Anwesenheits-Mails samt Token-Links,
     * kann sich aber nicht anmelden. Kurse mit diesem Kürzel werden ihr sofort zugeordnet.
     *
     * Body: { vorname, nachname, kuerzel, email }
     */
    public static function addExterneLehrkraft(array $body): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        [$vorname, $nachname, $email] = self::validiereExterneLehrkraft($body);

        $kuerzel = trim((string) ($body['kuerzel'] ?? ''));
        if ($kuerzel === '' || mb_strlen($kuerzel) > 20) {
            http_response_code(400);
            throw new RuntimeException('Kürzel ist erforderlich (max. 20 Zeichen).');
        }

        $stmt = $db->prepare('SELECT 1 FROM benutzer WHERE UPPER(kuerzel) = UPPER(?) LIMIT 1');
        $stmt->execute([$kuerzel]);
        if ($stmt->fetchColumn() !== false) {
            http_response_code(409);
            throw new RuntimeException("Das Kürzel „{$kuerzel}“ wird bereits von einer anderen Person verwendet.");
        }

        // Platzhalter-moodle_id: kann nie mit einer echten Moodle-ID (numerisch) kollidieren
        $db->prepare(
            'INSERT INTO benutzer (moodle_id, vorname, nachname, email, kuerzel, extern)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute(['extern:' . bin2hex(random_bytes(8)), $vorname, $nachname, $email, $kuerzel]);
        $id = (int) $db->lastInsertId();

        $db->prepare('INSERT INTO rollen (benutzer_id, rolle) VALUES (?, ?)')->execute([$id, 'lehrkraft']);

        // Kurse mit diesem Kürzel zuordnen – außer, es gibt bereits eine dauerhafte Zuordnung
        // auf eine andere Person (die wird nicht stillschweigend überschrieben).
        $stmt = $db->prepare('SELECT 1 FROM lehrer_zuordnungen WHERE lehrer_kuerzel = ? AND benutzer_id IS NOT NULL');
        $stmt->execute([$kuerzel]);
        $zugeordnet = 0;
        if ($stmt->fetchColumn() === false) {
            $zugeordnet = Zuordnung::speichereLehrerZuordnung($db, $kuerzel, $id, Session::getBenutzerId());
        }

        return ['id' => $id, 'zugeordnete_kurse' => $zugeordnet];
    }

    /**
     * Ändert Name und E-Mail einer externen Lehrkraft. Das Kürzel bleibt unverändert
     * (die Zuordnung der Kurse hängt daran) – bei Bedarf löschen und neu anlegen.
     *
     * Body: { vorname, nachname, email }
     */
    public static function updateExterneLehrkraft(int $id, array $body): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT 1 FROM benutzer WHERE id = ? AND extern = 1');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Externe Lehrkraft $id nicht gefunden.");
        }

        [$vorname, $nachname, $email] = self::validiereExterneLehrkraft($body);

        $db->prepare('UPDATE benutzer SET vorname = ?, nachname = ?, email = ? WHERE id = ?')
           ->execute([$vorname, $nachname, $email, $id]);

        return ['ok' => true];
    }

    /**
     * Löscht eine externe Lehrkraft. Ihre Kurse bleiben erhalten, haben danach aber
     * keine Lehrkraft mehr (und bereits versandte Anwesenheits-Links werden ungültig).
     */
    public static function deleteExterneLehrkraft(int $id): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare('DELETE FROM benutzer WHERE id = ? AND extern = 1');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            throw new RuntimeException("Externe Lehrkraft $id nicht gefunden.");
        }

        return ['ok' => true];
    }

    /** @return array{0: string, 1: string, 2: string} [vorname, nachname, email] */
    private static function validiereExterneLehrkraft(array $body): array
    {
        $vorname  = trim((string) ($body['vorname']  ?? ''));
        $nachname = trim((string) ($body['nachname'] ?? ''));
        $email    = trim((string) ($body['email']    ?? ''));

        if ($vorname === '' || $nachname === '' || mb_strlen($vorname) > 100 || mb_strlen($nachname) > 100) {
            http_response_code(400);
            throw new RuntimeException('Vor- und Nachname sind erforderlich (max. 100 Zeichen).');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            http_response_code(400);
            throw new RuntimeException('Bitte eine gültige E-Mail-Adresse angeben.');
        }

        return [$vorname, $nachname, $email];
    }

    // ------------------------------------------------------------------
    // Halbjahre und Kurse für die Übersicht
    // ------------------------------------------------------------------

    /**
     * Berechnet den Vorschlag für das nächste anzulegende Halbjahr.
     *
     * Logik:
     * – Wenn das neueste Halbjahr noch nicht alle bekannten Stufen enthält:
     *   → gleiches Schuljahr/Abschnitt, fehlende_stufen gefüllt
     * – Sonst: Abschnitt 1 → Abschnitt 2 (gleiches Schuljahr)
     *          Abschnitt 2 → Abschnitt 1 (nächstes Schuljahr)
     *
     * @return array{schuljahr: string, abschnitt: int, fehlende_stufen: list<string>}
     */
    public static function getHalbjahrVorschlag(): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        // Alle bekannten Stufennamen (Referenzmenge)
        $alleStufenNamen = $db->query(
            "SELECT DISTINCT name FROM stufen ORDER BY name"
        )->fetchAll(\PDO::FETCH_COLUMN);

        // Neuestes Halbjahr bestimmen
        $neuestes = $db->query(
            "SELECT s.schuljahr, h.abschnitt
             FROM halbjahre h
             JOIN stufen s ON s.id = h.stufe_id
             ORDER BY s.schuljahr DESC, h.abschnitt DESC
             LIMIT 1"
        )->fetch();

        if ($neuestes === false) {
            $year = (int) date('Y');
            $month = (int) date('n');
            $schuljahr = $month >= 8 ? "{$year}/" . ($year + 1) : ($year - 1) . "/{$year}";
            return ['schuljahr' => $schuljahr, 'abschnitt' => 1, 'fehlende_stufen' => []];
        }

        $schuljahr = $neuestes['schuljahr'];
        $abschnitt = (int) $neuestes['abschnitt'];

        // Welche Stufen sind für dieses Schuljahr+Abschnitt bereits vorhanden?
        $stmt = $db->prepare(
            "SELECT s.name FROM halbjahre h
             JOIN stufen s ON s.id = h.stufe_id
             WHERE s.schuljahr = ? AND h.abschnitt = ?"
        );
        $stmt->execute([$schuljahr, $abschnitt]);
        $vorhandene = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $fehlende = array_values(array_diff($alleStufenNamen, $vorhandene));
        sort($fehlende);

        if (!empty($fehlende)) {
            return ['schuljahr' => $schuljahr, 'abschnitt' => $abschnitt, 'fehlende_stufen' => $fehlende];
        }

        // Alle vollständig → nächstes Halbjahr vorschlagen, alle bekannten Stufen voranstellen
        $alleStufen = array_values($alleStufenNamen);
        sort($alleStufen);
        if ($abschnitt === 1) {
            return ['schuljahr' => $schuljahr, 'abschnitt' => 2, 'fehlende_stufen' => $alleStufen];
        }
        [$start, $end] = explode('/', $schuljahr, 2);
        $naechstesSchuljahr = ((int) $start + 1) . '/' . ((int) $end + 1);
        return ['schuljahr' => $naechstesSchuljahr, 'abschnitt' => 1, 'fehlende_stufen' => $alleStufen];
    }

    /**
     * Legt ein neues Halbjahr an. stufe_name darf eine kommagetrennte Liste sein,
     * dann werden mehrere Halbjahre auf einmal angelegt.
     *
     * Body: { stufe_name: string, schuljahr: string, abschnitt: 1|2 }
     * Rückgabe (Einzel): { id, stufe, schuljahr, abschnitt, kurs_anzahl }
     * Rückgabe (Mehrfach): { erstellt: [...], fehler: [...] }
     */
    public static function addHalbjahr(array $body): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stufeNamen = array_values(array_unique(array_filter(
            array_map(fn(string $s) => strtoupper(trim($s)), explode(',', trim($body['stufe_name'] ?? ''))),
            fn(string $s) => $s !== ''
        )));
        $schuljahr = trim($body['schuljahr'] ?? '');
        $abschnitt = (int) ($body['abschnitt'] ?? 0);

        if (empty($stufeNamen)) {
            http_response_code(400);
            throw new RuntimeException('Stufe darf nicht leer sein.');
        }
        if (!preg_match('/^\d{4}\/\d{4}$/', $schuljahr)) {
            http_response_code(400);
            throw new RuntimeException('Schuljahr muss im Format JJJJ/JJJJ angegeben werden (z.B. 2024/2025).');
        }
        if (!in_array($abschnitt, [1, 2], true)) {
            http_response_code(400);
            throw new RuntimeException('Abschnitt muss 1 oder 2 sein.');
        }

        $benutzerId = Session::getBenutzer()['id'];

        if (count($stufeNamen) === 1) {
            try {
                return self::erstelleHalbjahr($stufeNamen[0], $schuljahr, $abschnitt, $db, $benutzerId);
            } catch (\DomainException $e) {
                http_response_code(409);
                throw new RuntimeException($e->getMessage());
            }
        }

        // Mehrere Stufen: alle verarbeiten, Teilerfolge erlaubt
        $erstellt = [];
        $fehler   = [];
        foreach ($stufeNamen as $stufeName) {
            try {
                $erstellt[] = self::erstelleHalbjahr($stufeName, $schuljahr, $abschnitt, $db, $benutzerId);
            } catch (\DomainException $e) {
                $fehler[] = ['stufe' => $stufeName, 'meldung' => $e->getMessage()];
            }
        }
        http_response_code(200);
        return ['erstellt' => $erstellt, 'fehler' => $fehler];
    }

    /** Legt genau eine Stufe+Halbjahr-Kombination an. Wirft \DomainException bei Duplikat. */
    private static function erstelleHalbjahr(
        string $stufeName,
        string $schuljahr,
        int    $abschnitt,
        \PDO   $db,
        int    $benutzerId
    ): array {
        $stmt = $db->prepare('SELECT id FROM stufen WHERE name = ? AND schuljahr = ?');
        $stmt->execute([$stufeName, $schuljahr]);
        $stufeId   = $stmt->fetchColumn();
        $neueStufe = $stufeId === false;

        if (!$neueStufe) {
            // Prüfe Duplikat bevor wir irgendetwas schreiben
            $stmt = $db->prepare('SELECT id FROM halbjahre WHERE stufe_id = ? AND abschnitt = ?');
            $stmt->execute([$stufeId, $abschnitt]);
            if ($stmt->fetchColumn() !== false) {
                throw new \DomainException("{$stufeName} – {$abschnitt}. Halbjahr {$schuljahr} existiert bereits.");
            }
        }

        if ($neueStufe) {
            $db->prepare('INSERT INTO stufen (name, schuljahr) VALUES (?, ?)')->execute([$stufeName, $schuljahr]);
            $stufeId = (int) $db->lastInsertId();
        }

        $db->prepare(
            'INSERT INTO halbjahre (stufe_id, abschnitt, importiert_von) VALUES (?, ?, ?)'
        )->execute([$stufeId, $abschnitt, $benutzerId]);
        $halbjahrId = (int) $db->lastInsertId();

        if ($neueStufe) {
            self::autoForwardStufenleitung($stufeId, $stufeName, $schuljahr, $db);
        }

        return [
            'id'          => $halbjahrId,
            'stufe'       => $stufeName,
            'schuljahr'   => $schuljahr,
            'abschnitt'   => $abschnitt,
            'kurs_anzahl' => 0,
        ];
    }

    /** Überträgt Stufenleitungen auf eine neue Stufe (EF→Q2, Q1→EF, Q2→Q1 im Vorjahr). */
    private static function autoForwardStufenleitung(int $neueStufeId, string $name, string $schuljahr, \PDO $db): void
    {
        static $vorgaengerMap = ['EF' => 'Q2', 'Q1' => 'EF', 'Q2' => 'Q1'];
        $vorgaengerName = $vorgaengerMap[$name] ?? null;
        if ($vorgaengerName === null) {
            return;
        }

        [$start, $end] = explode('/', $schuljahr, 2);
        $vorgaengerSchuljahr = ((int) $start - 1) . '/' . ((int) $end - 1);

        $stmt = $db->prepare('SELECT id FROM stufen WHERE name = ? AND schuljahr = ?');
        $stmt->execute([$vorgaengerName, $vorgaengerSchuljahr]);
        $vorgaengerStufeId = $stmt->fetchColumn();
        if ($vorgaengerStufeId === false) {
            return;
        }

        $stmt = $db->prepare(
            'SELECT sl.benutzer_id FROM stufenleitungen sl
             JOIN rollen r ON r.benutzer_id = sl.benutzer_id AND r.rolle = \'stufenleitung\'
             WHERE sl.stufe_id = ?'
        );
        $stmt->execute([(int) $vorgaengerStufeId]);

        $ins = $db->prepare('INSERT IGNORE INTO stufenleitungen (benutzer_id, stufe_id) VALUES (?, ?)');
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $benutzerId) {
            $ins->execute([$benutzerId, $neueStufeId]);
        }
    }

    /** Alle Lehrkräfte (für Dropdowns). */
    public static function getLehrkraefte(): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        return $db->query(
            "SELECT b.id, b.vorname, b.nachname, b.kuerzel, b.extern
             FROM benutzer b
             JOIN rollen r ON r.benutzer_id = b.id AND r.rolle = 'lehrkraft'
             ORDER BY b.nachname, b.vorname"
        )->fetchAll();
    }

    /**
     * Alle Halbjahre mit Stufeninformationen, Stufenleitungsnamen und eigenem Zugriffsflag.
     *
     * ist_eigene_stufe = 1 wenn der aktuelle Nutzer Stufenleitung für diese Stufe ist
     * (oder Admin, dann wird es in PHP auf 1 gesetzt).
     * stufenleitungen = semikolongetrennte Liste aller SL-Namen dieser Stufe.
     */
    public static function getHalbjahre(): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db       = Database::getInstance();
        $benutzer = Session::getBenutzer();
        $meineId  = $benutzer['id'];
        $istAdmin = in_array('admin', $benutzer['rollen'] ?? [], true);

        $stmt = $db->prepare(
            "SELECT h.id,
                    h.abschnitt,
                    h.importiert_am,
                    s.name                  AS stufe,
                    s.schuljahr,
                    COUNT(DISTINCT k.id)    AS kurs_anzahl,
                    GROUP_CONCAT(
                        DISTINCT CONCAT(b.nachname, ', ', b.vorname)
                        ORDER BY b.nachname, b.vorname
                        SEPARATOR '; '
                    )                       AS stufenleitungen,
                    MAX(CASE WHEN sl_ich.benutzer_id IS NOT NULL THEN 1 ELSE 0 END)
                                            AS ist_eigene_stufe
             FROM halbjahre h
             JOIN stufen s        ON s.id  = h.stufe_id
             LEFT JOIN kurse k    ON k.halbjahr_id = h.id
             LEFT JOIN stufenleitungen sl_alle ON sl_alle.stufe_id = s.id
             LEFT JOIN benutzer b             ON b.id = sl_alle.benutzer_id
             LEFT JOIN stufenleitungen sl_ich ON sl_ich.stufe_id = s.id
                                             AND sl_ich.benutzer_id = ?
             GROUP BY h.id, h.abschnitt, h.importiert_am, s.name, s.schuljahr
             ORDER BY s.schuljahr DESC, s.name, h.abschnitt"
        );
        $stmt->execute([$meineId]);
        $rows = $stmt->fetchAll();

        if ($istAdmin) {
            foreach ($rows as &$row) {
                $row['ist_eigene_stufe'] = 1;
            }
            unset($row);
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // Prüflinge eines Kurses (GoMST + manuell hinzugefügte)
    // ------------------------------------------------------------------

    /**
     * Alle Prüflinge eines Kurses – aus GoMST und manuell hinzugefügte.
     * Liefert aufgelöste Namen wenn schueler_id gesetzt ist.
     *
     * @return array<array{id: int, name_roh: string, kursart: string|null, schueler_id: int|null, vorname: string|null, nachname: string|null, ist_zusatz: int}>
     */
    public static function getKursSchueler(int $kursId): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT id FROM kurse WHERE id = ?');
        $stmt->execute([$kursId]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Kurs $kursId nicht gefunden.");
        }

        $stmt = $db->prepare(
            "SELECT ks.id,
                    ks.name_roh,
                    ks.kursart,
                    ks.schueler_id,
                    b.vorname,
                    b.nachname,
                    (ks.name_roh NOT LIKE '%|%') AS ist_zusatz
             FROM kurs_schueler ks
             LEFT JOIN benutzer b ON b.id = ks.schueler_id
             WHERE ks.kurs_id = ?
             ORDER BY ks.name_roh"
        );
        $stmt->execute([$kursId]);
        return $stmt->fetchAll();
    }

    /**
     * Fügt eine Person manuell einem Kurs hinzu.
     *
     * Body: { name: string }
     * @return array{kurs_schueler_id: int, name_roh: string}
     */
    public static function addZusatzSchuelerZuKurs(int $kursId, array $body): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $name = trim($body['name'] ?? '');
        if ($name === '') {
            http_response_code(400);
            throw new RuntimeException('Name darf nicht leer sein.');
        }
        if (strlen($name) > 200) {
            http_response_code(400);
            throw new RuntimeException('Name zu lang (max. 200 Zeichen).');
        }

        $stmt = $db->prepare('SELECT id FROM kurse WHERE id = ?');
        $stmt->execute([$kursId]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Kurs $kursId nicht gefunden.");
        }

        $dup = $db->prepare('SELECT id FROM kurs_schueler WHERE kurs_id = ? AND name_roh = ?');
        $dup->execute([$kursId, $name]);
        if ($dup->fetchColumn() !== false) {
            http_response_code(409);
            throw new RuntimeException('Eine Person mit diesem Namen ist bereits in diesem Kurs eingetragen.');
        }

        $db->prepare(
            'INSERT INTO kurs_schueler (kurs_id, name_roh) VALUES (?, ?)'
        )->execute([$kursId, $name]);

        $ksId = (int) $db->lastInsertId();

        // Gespeicherte Zuordnung bzw. automatisches Namensmatching (wie GoMST-Import)
        $schuelerId = Zuordnung::ermittleSchuelerId($db, $name);
        if ($schuelerId !== null) {
            $db->prepare('UPDATE kurs_schueler SET schueler_id = ? WHERE id = ?')
               ->execute([$schuelerId, $ksId]);
        }

        return ['kurs_schueler_id' => $ksId, 'name_roh' => $name, 'schueler_id' => $schuelerId];
    }

    /**
     * Löscht einen manuell hinzugefügten Prüfling aus einem Kurs.
     * Schlägt fehl wenn der Eintrag GoMST-Format hat (enthält '|') oder nicht zum Kurs gehört.
     */
    public static function deleteZusatzSchuelerAusKurs(int $kursId, int $ksId): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare(
            "SELECT id FROM kurs_schueler
             WHERE id = ? AND kurs_id = ? AND name_roh NOT LIKE '%|%'"
        );
        $stmt->execute([$ksId, $kursId]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException('Zusatzschüler*in nicht gefunden oder gehört nicht zu diesem Kurs.');
        }

        $db->prepare('DELETE FROM kurs_schueler WHERE id = ?')->execute([$ksId]);
        return ['ok' => true];
    }

    // ------------------------------------------------------------------
    // E-Mail manuell auslösen
    // ------------------------------------------------------------------

    /**
     * Sendet eine Anwesenheits-E-Mail für eine Klausur manuell.
     * Erzeugt immer einen neuen Token (unabhängig von bereits gesendeten Mails).
     *
     * @return array{gesendet: bool, empfaenger: string}
     */
    public static function emailAusloesen(int $klausurId): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare(
            "SELECT kl.id, kl.termin_datum, k.anzeigename AS kurs_anzeigename,
                    b.id AS lehrer_id, b.email, b.vorname, b.nachname
             FROM klausuren kl
             JOIN kurse k    ON k.id = kl.kurs_id
             JOIN benutzer b ON b.id = k.lehrer_id
             WHERE kl.id = ?"
        );
        $stmt->execute([$klausurId]);
        $kl = $stmt->fetch();

        if ($kl === false) {
            http_response_code(404);
            throw new RuntimeException("Klausur {$klausurId} nicht gefunden oder keine Lehrkraft zugeordnet.");
        }

        if (empty($kl['email'])) {
            http_response_code(422);
            throw new RuntimeException('Die zugeordnete Lehrkraft hat keine E-Mail-Adresse hinterlegt.');
        }

        $token = bin2hex(random_bytes(32));

        $klausurDaten = [
            'kurs_anzeigename' => $kl['kurs_anzeigename'],
            'termin_datum'     => $kl['termin_datum'],
        ];

        $datumStr = $kl['termin_datum']
            ? date('d.m.Y', strtotime($kl['termin_datum']))
            : '–';
        $betreff  = "Anwesenheit Klausur {$kl['kurs_anzeigename']} am {$datumStr}";

        $db->prepare(
            "INSERT INTO email_benachrichtigungen
             (klausur_id, empfaenger_id, typ, token, gesendet_am)
             VALUES (?, ?, 'erstmeldung', ?, NOW())"
        )->execute([$klausurId, $kl['lehrer_id'], $token]);

        Mailer::send(
            $kl['email'],
            trim($kl['vorname'] . ' ' . $kl['nachname']),
            $betreff,
            EmailTemplates::erstmeldung($klausurDaten, $token),
        );

        return [
            'gesendet'   => true,
            'empfaenger' => $kl['email'],
        ];
    }

    // ------------------------------------------------------------------

    /** Kursliste für ein Halbjahr inkl. Schüler*innen-Anzahl und Zuordnungsstatus. */
    public static function getKurse(int $halbjahrId): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $kurse = $db->prepare(
            "SELECT k.id,
                    k.kurs_kuerzel,
                    k.anzeigename,
                    k.fach_kuerzel,
                    k.kursart,
                    k.lehrer_kuerzel,
                    k.lehrer_id,
                    b.vorname      AS lehrer_vorname,
                    b.nachname     AS lehrer_nachname,
                    b.extern       AS lehrer_extern,
                    COUNT(ks.id)   AS schueler_gesamt,
                    SUM(CASE WHEN ks.schueler_id IS NOT NULL THEN 1 ELSE 0 END) AS schueler_zugeordnet
             FROM kurse k
             LEFT JOIN benutzer b   ON b.id  = k.lehrer_id
             LEFT JOIN kurs_schueler ks ON ks.kurs_id = k.id
             WHERE k.halbjahr_id = ?
             GROUP BY k.id
             ORDER BY k.anzeigename"
        );
        $kurse->execute([$halbjahrId]);

        return $kurse->fetchAll();
    }

    // ------------------------------------------------------------------
    // Manuelle Kursverwaltung
    // ------------------------------------------------------------------

    /**
     * Legt einen Kurs manuell für ein Halbjahr an.
     *
     * Body: { bezeichnung: string, kursart: 'LK'|'GK', fach_kuerzel?: string, lehrer_kuerzel?: string }
     */
    public static function addKurs(int $halbjahrId, array $body): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $bezeichnung   = trim($body['bezeichnung'] ?? '');
        $kursart       = $body['kursart'] ?? 'GK';
        $fachKuerzel   = strtoupper(trim($body['fach_kuerzel'] ?? ''));
        $lehrerKuerzel = strtoupper(trim($body['lehrer_kuerzel'] ?? '')) ?: null;
        $lehrerIdDirekt = isset($body['lehrer_id']) && (int) $body['lehrer_id'] > 0
            ? (int) $body['lehrer_id'] : null;

        if ($bezeichnung === '') {
            http_response_code(400);
            throw new RuntimeException('Bezeichnung darf nicht leer sein.');
        }
        if (strlen($bezeichnung) > 50) {
            http_response_code(400);
            throw new RuntimeException('Bezeichnung zu lang (max. 50 Zeichen).');
        }
        if (!in_array($kursart, ['LK', 'GK'], true)) {
            http_response_code(400);
            throw new RuntimeException("Ungültige Kursart '$kursart'.");
        }
        if ($fachKuerzel === '') {
            $fachKuerzel = strtoupper(preg_replace('/[\s_].*/', '', $bezeichnung));
            $fachKuerzel = substr($fachKuerzel, 0, 10) ?: '–';
        }

        $stmt = $db->prepare('SELECT id FROM halbjahre WHERE id = ?');
        $stmt->execute([$halbjahrId]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Halbjahr $halbjahrId nicht gefunden.");
        }

        $dup = $db->prepare('SELECT id FROM kurse WHERE halbjahr_id = ? AND kurs_kuerzel = ?');
        $dup->execute([$halbjahrId, $bezeichnung]);
        if ($dup->fetchColumn() !== false) {
            http_response_code(409);
            throw new RuntimeException('Ein Kurs mit dieser Bezeichnung existiert bereits in diesem Halbjahr.');
        }

        // Lehrkraft auflösen: direkte ID hat Vorrang vor Kürzel
        $lehrerId = null;
        if ($lehrerIdDirekt !== null) {
            $lehrerId = $lehrerIdDirekt;
        } elseif ($lehrerKuerzel !== null) {
            $lehrerId = Zuordnung::ermittleLehrerId($db, $lehrerKuerzel);
        }

        $lehrerVorname  = null;
        $lehrerNachname = null;
        $lehrerExtern   = 0;
        if ($lehrerId !== null) {
            $ls = $db->prepare('SELECT id, vorname, nachname, kuerzel, extern FROM benutzer WHERE id = ?');
            $ls->execute([$lehrerId]);
            $lehrer = $ls->fetch();
            if ($lehrer !== false) {
                $lehrerVorname  = $lehrer['vorname'];
                $lehrerNachname = $lehrer['nachname'];
                $lehrerExtern   = (int) $lehrer['extern'];
                if ($lehrerIdDirekt !== null) {
                    $lehrerKuerzel = $lehrer['kuerzel'] ?? $lehrerKuerzel;
                }
            } else {
                $lehrerId = null;
            }
        }

        $db->prepare(
            'INSERT INTO kurse (halbjahr_id, kurs_kuerzel, fach_kuerzel, kursart, anzeigename, lehrer_kuerzel, lehrer_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$halbjahrId, $bezeichnung, $fachKuerzel, $kursart, $bezeichnung, $lehrerKuerzel, $lehrerId]);

        return [
            'id'                  => (int) $db->lastInsertId(),
            'kurs_kuerzel'        => $bezeichnung,
            'anzeigename'         => $bezeichnung,
            'fach_kuerzel'        => $fachKuerzel,
            'kursart'             => $kursart,
            'lehrer_kuerzel'      => $lehrerKuerzel,
            'lehrer_id'           => $lehrerId,
            'lehrer_vorname'      => $lehrerVorname,
            'lehrer_nachname'     => $lehrerNachname,
            'lehrer_extern'       => $lehrerExtern,
            'schueler_gesamt'     => 0,
            'schueler_zugeordnet' => 0,
        ];
    }

    /**
     * Löscht einen Kurs samt aller abhängigen Daten (CASCADE).
     */
    public static function deleteKurs(int $kursId): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT id FROM kurse WHERE id = ?');
        $stmt->execute([$kursId]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(404);
            throw new RuntimeException("Kurs $kursId nicht gefunden.");
        }

        $db->prepare('DELETE FROM kurse WHERE id = ?')->execute([$kursId]);
        return ['ok' => true];
    }

    // ------------------------------------------------------------------
    // Daten löschen
    // ------------------------------------------------------------------

    /**
     * Löscht ein Halbjahr samt aller abhängigen Daten (CASCADE).
     *
     * @return array{ok: bool}
     */
    public static function deleteHalbjahr(int $halbjahrId): array
    {
        Session::requireRolle('admin', 'stufenleitung');
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT stufe_id FROM halbjahre WHERE id = ?');
        $stmt->execute([$halbjahrId]);
        $stufeId = $stmt->fetchColumn();
        if ($stufeId === false) {
            http_response_code(404);
            throw new RuntimeException("Halbjahr {$halbjahrId} nicht gefunden.");
        }

        $db->prepare('DELETE FROM halbjahre WHERE id = ?')->execute([$halbjahrId]);

        // Stufe löschen wenn keine Halbjahre mehr → kaskadiert stufenleitungen
        $remaining = $db->prepare('SELECT COUNT(*) FROM halbjahre WHERE stufe_id = ?');
        $remaining->execute([$stufeId]);
        if ((int) $remaining->fetchColumn() === 0) {
            $db->prepare('DELETE FROM stufen WHERE id = ?')->execute([$stufeId]);
        }

        return ['ok' => true];
    }
}
