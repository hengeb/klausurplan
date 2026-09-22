'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { starte, warten, ereignis, SL } = require('./helfer');

const zuordnungen = () => ({
    schueler_gomsth: [{ name_roh: 'Unbekannt|Uwe', anzahl_kurse: 2, stufen: 'Q2' }],
    schueler_moodle: [{ id: 4, vorname: 'Tom', nachname: 'Frei', stufe: 'Q2' }],
    schueler_zugeordnet: [
        { name_roh: 'Mustermann|Max', schueler_id: 1, vorname: 'Max', nachname: 'Mustermann', moodle_stufe: 'Q2', anzahl_kurse: 1, stufen: 'Q2', manuell: 1 },
        { name_roh: 'Schüler|Eva', schueler_id: 3, vorname: 'Eva', nachname: 'Schüler', moodle_stufe: null, anzahl_kurse: 1, stufen: 'Q2', manuell: 0 },
    ],
    lehrkraefte_kurse: [{ lehrer_kuerzel: 'ZZ', anzahl_kurse: 2 }, { lehrer_kuerzel: 'AB1', anzahl_kurse: 1 }],
    lehrkraefte_moodle: [
        { id: 2, vorname: 'Anna', nachname: 'Lehrer (SZ)', kuerzel: 'SZ', extern: 0, vergeben: 1 },
        { id: 6, vorname: 'Frei', nachname: 'Lehrer', kuerzel: null, extern: 0, vergeben: 0 },
        { id: 5, vorname: 'Ex', nachname: 'Tern', kuerzel: 'XT', extern: 1, vergeben: 1 },
    ],
    lehrkraefte_zugeordnet: [{ lehrer_kuerzel: 'SZ', lehrer_id: 2, vorname: 'Anna', nachname: 'Lehrer (SZ)', kuerzel: 'SZ', extern: 0, anzahl_kurse: 1, manuell: 1 }],
    externe_lehrkraefte: [{ id: 5, vorname: 'Ex', nachname: 'Tern', kuerzel: 'XT', email: 'ex@y.de', anzahl_kurse: 1 }],
});

const fixtures = () => ({
    'GET /stufenleitung/zuordnungen': zuordnungen,
    'GET /stufenleitung/moodle-schueler': [
        { id: 1, vorname: 'Max', nachname: 'Mustermann', stufe: 'Q2', vergeben: 1 },
        { id: 3, vorname: 'Eva', nachname: 'Schüler', stufe: 'Q2', vergeben: 1 },
        { id: 4, vorname: 'Tom', nachname: 'Frei', stufe: 'Q2', vergeben: 0 },
        { id: 9, vorname: 'Otto', nachname: 'Anders', stufe: 'EF', vergeben: 0 },
    ],
    'POST /stufenleitung/zuordnungen': { ok: true },
    'POST /stufenleitung/externe-lehrkraefte': { id: 7 },
    'PUT /stufenleitung/externe-lehrkraefte/*': { ok: true },
    'DELETE /stufenleitung/externe-lehrkraefte/*': { ok: true },
});

test('Schüler-Tab zeigt offene und bereits zugeordnete Namen', async () => {
    const { d } = await starte('zuordnungen', SL, fixtures());

    assert.equal(d.querySelectorAll('#tab-schueler .zuordnungs-tabelle').length, 2);
    const zeilen = d.querySelectorAll('#tab-schueler .zugeordnet-block tbody tr');
    assert.equal(zeilen.length, 2);
    assert.match(zeilen[0].textContent, /manuell/);
    assert.match(zeilen[1].textContent, /automatisch/);
    assert.match(d.querySelector('.badge').textContent, /1/, 'Badge zählt offene Namen');
});

test('Suchfeld filtert die zugeordneten Zeilen', async () => {
    const { w, d } = await starte('zuordnungen', SL, fixtures());
    const suche = d.querySelector('.filter-zugeordnet[data-liste="schueler"]');

    suche.value = 'eva';
    ereignis(w, suche, 'input');
    assert.equal(d.querySelectorAll('#tab-schueler .zugeordnet-block tbody tr.versteckt').length, 1);

    suche.value = '';
    ereignis(w, suche, 'input');
    assert.equal(d.querySelectorAll('#tab-schueler .zugeordnet-block tbody tr.versteckt').length, 0);
});

test('Neu zuordnen sendet POST und lädt die Ansicht neu', async () => {
    const { d, calls } = await starte('zuordnungen', SL, fixtures());
    calls.length = 0;

    d.querySelector('#tab-schueler select[data-name-roh]').value = '4';
    d.querySelector('.btn-zuordnen-s').click();
    await warten();

    const post = calls.find(c => c.startsWith('POST /stufenleitung/zuordnungen'));
    assert.deepEqual(JSON.parse(post.split(' ').slice(2).join(' ')), { typ: 'schueler', name_roh: 'Unbekannt|Uwe', benutzer_id: 4 });
    assert.ok(calls.includes('GET /stufenleitung/zuordnungen'));
});

test('Ohne gewähltes Konto wird nichts gesendet', async () => {
    const { d, calls } = await starte('zuordnungen', SL, fixtures());
    calls.length = 0;

    d.querySelector('.btn-zuordnen-s').click();
    await warten();

    assert.equal(calls.length, 0);
});

test('Ändern: nur passende Stufe, „Alle Stufen“ erweitert, Abbrechen stellt die Zeile her', async () => {
    const { w, d } = await starte('zuordnungen', SL, fixtures());
    const zeile = d.querySelector('#tab-schueler .zugeordnet-block tbody tr[data-name-roh="Schüler|Eva"]');

    zeile.querySelector('.btn-korrigieren-s').click();
    await warten();
    const optionen = () => [...zeile.querySelectorAll('select option')].map(o => o.textContent);
    assert.ok(optionen().some(t => /Frei/.test(t)));
    assert.ok(!optionen().some(t => /Anders/.test(t)), 'EF-Konto ist ausgeblendet');
    assert.ok(optionen().some(t => /bereits zugeordnet/.test(t)), 'vergebene Konten sind markiert');

    const alle = zeile.querySelector('.cb-alle-stufen');
    alle.checked = true;
    ereignis(w, alle, 'change');
    assert.ok(optionen().some(t => /Anders/.test(t)));

    zeile.querySelector('.btn-korrektur-abbrechen').click();
    assert.equal(zeile.querySelector('select'), null);
    assert.equal(zeile.querySelector('.btn-korrigieren-s').disabled, false, 'Buttons sind wieder aktiv');
});

test('Ändern → Speichern sendet die neue Zuordnung; unveränderte Auswahl sendet nichts', async () => {
    const { d, calls } = await starte('zuordnungen', SL, fixtures());
    const zeile = d.querySelector('#tab-schueler .zugeordnet-block tbody tr[data-name-roh="Schüler|Eva"]');

    zeile.querySelector('.btn-korrigieren-s').click();
    await warten();
    calls.length = 0;
    zeile.querySelector('.btn-korrektur-speichern').click(); // Auswahl = aktuelles Konto
    await warten();
    assert.equal(calls.filter(c => c.startsWith('POST')).length, 0);

    const zeile2 = d.querySelector('#tab-schueler .zugeordnet-block tbody tr[data-name-roh="Schüler|Eva"]');
    zeile2.querySelector('.btn-korrigieren-s').click();
    await warten();
    zeile2.querySelector('select').value = '4';
    zeile2.querySelector('.btn-korrektur-speichern').click();
    await warten();
    assert.ok(calls.some(c => c.startsWith('POST /stufenleitung/zuordnungen') && c.includes('"name_roh":"Schüler|Eva"') && c.includes('"benutzer_id":4')));
});

test('Aufheben sendet benutzer_id null (nach Bestätigung)', async () => {
    const { w, d, calls } = await starte('zuordnungen', SL, fixtures());
    calls.length = 0;

    w.confirm = () => false;
    d.querySelector('.btn-aufheben-s').click();
    await warten();
    assert.equal(calls.length, 0, 'ohne Bestätigung passiert nichts');

    w.confirm = () => true;
    d.querySelector('.btn-aufheben-s').click();
    await warten();
    assert.ok(calls.some(c => c.includes('"benutzer_id":null') && c.includes('Mustermann|Max')));
});

test('Lehrkräfte-Tab: nur freie Lehrkräfte, Kürzel mit Ziffer werden ausgeblendet', async () => {
    const { d } = await starte('zuordnungen', SL, fixtures());

    d.querySelector('.tab[data-tab="lehrkraefte"]').click();
    assert.ok(!d.querySelector('#tab-lehrkraefte').classList.contains('versteckt'));
    const optionen = [...d.querySelectorAll('#tab-lehrkraefte select[data-kuerzel] option')].map(o => o.textContent);
    assert.ok(optionen.some(t => /Frei/.test(t)));
    assert.ok(!optionen.some(t => /Anna/.test(t)), 'bereits zugeordnete Lehrkraft ist nicht wählbar');
    assert.equal(d.querySelectorAll('#tab-lehrkraefte select[data-kuerzel]').length, 1, 'AB1 endet auf Ziffer');
    assert.equal(d.querySelectorAll('#tab-lehrkraefte .zuordnungs-tabelle').length, 3, 'offen, zugeordnet, extern');
});

test('Lehrkraft zuordnen, ändern und aufheben', async () => {
    const { w, d, calls } = await starte('zuordnungen', SL, fixtures());
    d.querySelector('.tab[data-tab="lehrkraefte"]').click();
    calls.length = 0;

    d.querySelector('#tab-lehrkraefte select[data-kuerzel]').value = '6';
    d.querySelector('.btn-zuordnen-l').click();
    await warten();
    assert.ok(calls.some(c => c.includes('"typ":"lehrkraft"') && c.includes('"lehrer_kuerzel":"ZZ"') && c.includes('"benutzer_id":6')));

    calls.length = 0;
    d.querySelector('#tab-lehrkraefte .zugeordnet-block .btn-korrigieren-l').click();
    assert.equal(d.querySelectorAll('#tab-lehrkraefte .zugeordnet-block select option').length, 3, 'alle Lehrkräfte inkl. externer');
    d.querySelector('#tab-lehrkraefte .zugeordnet-block select').value = '5';
    d.querySelector('.btn-korrektur-speichern').click();
    await warten();
    assert.ok(calls.some(c => c.includes('"lehrer_kuerzel":"SZ"') && c.includes('"benutzer_id":5')));

    calls.length = 0;
    d.querySelector('#tab-lehrkraefte .zugeordnet-block .btn-aufheben-l').click();
    await warten();
    assert.ok(calls.some(c => c.includes('"lehrer_kuerzel":"SZ"') && c.includes('"benutzer_id":null')));
    assert.ok(w);
});

test('Externe Lehrkraft anlegen (mit vorbelegtem Kürzel), bearbeiten und löschen', async () => {
    const { d, calls } = await starte('zuordnungen', SL, fixtures());
    d.querySelector('.tab[data-tab="lehrkraefte"]').click();

    d.querySelector('.btn-extern-anlegen').click();
    assert.equal(d.querySelector('#ext-kuerzel').value, 'ZZ');
    d.querySelector('#ext-vorname').value = 'Vera';
    d.querySelector('#ext-nachname').value = 'Extern';
    d.querySelector('#ext-email').value = 'v@e.de';
    calls.length = 0;
    d.querySelector('#ext-speichern').click();
    await warten();
    assert.ok(calls.some(c => c.startsWith('POST /stufenleitung/externe-lehrkraefte') && c.includes('"kuerzel":"ZZ"') && c.includes('v@e.de')));
    assert.equal(d.querySelector('.dialog-overlay'), null, 'Dialog schließt');

    d.querySelector('.tab[data-tab="lehrkraefte"]').click();
    d.querySelector('.btn-extern-bearbeiten').click();
    assert.equal(d.querySelector('#ext-kuerzel').disabled, true, 'Kürzel nicht änderbar');
    assert.equal(d.querySelector('#ext-email').value, 'ex@y.de');
    calls.length = 0;
    d.querySelector('#ext-speichern').click();
    await warten();
    assert.ok(calls.some(c => c.startsWith('PUT /stufenleitung/externe-lehrkraefte/5')));

    d.querySelector('.tab[data-tab="lehrkraefte"]').click();
    calls.length = 0;
    d.querySelector('.btn-extern-loeschen').click();
    await warten();
    assert.ok(calls.includes('DELETE /stufenleitung/externe-lehrkraefte/5'));
});

test('Fehler beim Anlegen einer externen Lehrkraft erscheinen im Dialog', async () => {
    const fx = fixtures();
    fx['POST /stufenleitung/externe-lehrkraefte'] = undefined;
    delete fx['POST /stufenleitung/externe-lehrkraefte']; // → 404 mit Fehlermeldung
    const { d } = await starte('zuordnungen', SL, fx);
    d.querySelector('.tab[data-tab="lehrkraefte"]').click();

    d.querySelector('#btn-extern-neu').click();
    d.querySelector('#ext-speichern').click();
    await warten();

    assert.match(d.querySelector('#ext-fehler').textContent, /keine Testdaten/);
    assert.equal(d.querySelector('#ext-fehler').style.display, '');
    assert.equal(d.querySelector('#ext-speichern').disabled, false);
});

test('Ohne Rolle gibt es keinen Zugriff', async () => {
    const { d } = await starte('zuordnungen', ['lehrkraft'], fixtures());

    assert.match(d.querySelector('#app').textContent, /Kein Zugriff/);
});
