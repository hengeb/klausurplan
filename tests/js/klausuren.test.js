'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { starte, warten, ereignis, SL } = require('./helfer');

const kurse = [
    { id: 1, kurs_kuerzel: 'A', anzeigename: 'Q2 Sport', kursart: 'GK', stufe_id: 1, stufe: 'Q2', schuljahr: '2025/2026', abschnitt: 1, halbjahr_id: 1, ist_eigene_sl: 1 },
    { id: 2, kurs_kuerzel: 'B', anzeigename: 'Q2 Mathe HJ2', kursart: 'GK', stufe_id: 1, stufe: 'Q2', schuljahr: '2025/2026', abschnitt: 2, halbjahr_id: 2, ist_eigene_sl: 1 },
    { id: 3, kurs_kuerzel: 'C', anzeigename: 'Q1 Englisch', kursart: 'GK', stufe_id: 2, stufe: 'Q1', schuljahr: '2025/2026', abschnitt: 1, halbjahr_id: 3, ist_eigene_sl: 0 },
    { id: 4, kurs_kuerzel: 'D', anzeigename: 'EF Bio', kursart: 'GK', stufe_id: 3, stufe: 'EF', schuljahr: '2026/2027', abschnitt: 1, halbjahr_id: 4, ist_eigene_sl: 0 },
];

const klausur = (id, extra) => ({
    id, klausur_nr: 1, termin_datum: '2026-10-01', termin_uhrzeit: '08:00:00', dauer_minuten: 90, kurs_kuerzel: 'A',
    kurs_anzeigename: 'Q2 Sport', kursart: 'GK', stufe: 'Q2', schuljahr: '2025/2026', halbjahr_id: 1, abschnitt: 1,
    lehrer_id: 2, lehrer_vorname: 'Anna', lehrer_nachname: 'L', lehrer_extern: 0, schueler_anzahl: 5, anwesenheit_erfasst: 0, ist_eigene_sl: 1,
    ...extra,
});

const fixtures = () => ({
    'GET /me': { id: 2, vorname: 'Anna', nachname: 'L', rollen: SL, stufen: [{ id: 1, name: 'Q2', schuljahr: '2025/2026' }] },
    'GET /kurse': kurse,
    'GET /klausuren': [
        klausur(1),
        klausur(2, { termin_datum: null, termin_uhrzeit: null, dauer_minuten: null, kurs_anzeigename: 'Q1 Englisch', stufe: 'Q1', halbjahr_id: 3, lehrer_id: 5, lehrer_vorname: 'Ex', lehrer_nachname: 'Tern', lehrer_extern: 1, ist_eigene_sl: 0 }),
    ],
    'GET /klausuren?alle=1': [],
    'GET /klausuren/meine-nachschreibtermine': [],
    'POST /klausuren': { id: 9, klausur_nr: 1 },
    'POST /klausuren/paste-import*': { erstellt: 1, aktualisiert: 0, fehler: [] },
    'PUT /klausuren/*': { ok: true },
    'DELETE /klausuren/*': { ok: true },
    'POST /stufenleitung/email-ausloesen/*': { gesendet: true, empfaenger: 'a@x.de' },
});

test('Klausurübersicht: eine Liste je Stufe/Halbjahr, Kopfzeile nennt die eigenen Stufen', async () => {
    const { d } = await starte('klausuren', SL, fixtures(), { warteMs: 60 });

    assert.match(d.querySelector('.filter-leiste').textContent, /Q2/);
    assert.equal(d.querySelectorAll('#tab-uebersicht .karte').length, 2, 'Q2 und Q1 getrennt');
    assert.match(d.body.textContent, /\(extern\)/, 'externe Lehrkraft gekennzeichnet');
});

test('Bearbeiten/Löschen für alle Stufen, E-Mail-Button nur für die eigene', async () => {
    const { d } = await starte('klausuren', SL, fixtures(), { warteMs: 60 });

    assert.equal(d.querySelectorAll('.btn-klausur-bearbeiten').length, 2);
    assert.equal(d.querySelectorAll('.btn-klausur-loeschen').length, 2);
    assert.equal(d.querySelectorAll('.btn-email-ausloesen').length, 1);
});

test('Reine Lehrkraft sieht keine Verwaltungsknöpfe und keine Kopfzeile', async () => {
    const { d } = await starte('klausuren', ['lehrkraft'], fixtures(), { warteMs: 60 });

    assert.equal(d.querySelector('.filter-leiste'), null);
    assert.equal(d.querySelectorAll('.btn-klausur-bearbeiten').length, 0);
    assert.equal(d.querySelector('.tab[data-tab="neu"]'), null);
    assert.equal(d.querySelectorAll('.btn-anwesenheit').length, 2);
});

test('„Alle Stufen anzeigen“ ruft die Liste mit ?alle=1 ab', async () => {
    const { w, d, calls } = await starte('klausuren', SL, fixtures(), { warteMs: 60 });
    calls.length = 0;

    const box = d.querySelector('#kl-alle-stufen');
    box.checked = true;
    ereignis(w, box, 'change');
    await warten();

    assert.ok(calls.includes('GET /klausuren?alle=1'));
    assert.match(d.querySelector('.filter-leiste').textContent, /aller Stufen/);
});

test('Stufenleitung ohne Stufe bekommt einen Hinweis', async () => {
    const fx = fixtures();
    fx['GET /me'] = { id: 2, vorname: 'A', nachname: 'B', rollen: ['stufenleitung'], stufen: [] };
    const { d } = await starte('klausuren', ['stufenleitung'], fx, { warteMs: 60 });

    assert.match(d.querySelector('.filter-leiste').textContent, /für keine Stufe zuständig/);
});

test('Ohne Klausuren erscheint ein Hinweis mit dem Weg zum Anlegen', async () => {
    const fx = fixtures();
    fx['GET /klausuren'] = [];
    const { d } = await starte('klausuren', SL, fx, { warteMs: 60 });

    assert.match(d.querySelector('#tab-uebersicht').textContent, /Keine Klausuren vorhanden/);
});

test('Klausur löschen und E-Mail auslösen fragen nach und rufen die API', async () => {
    const { w, d, calls } = await starte('klausuren', SL, fixtures(), { warteMs: 60 });
    calls.length = 0;

    w.confirm = () => false;
    d.querySelector('.btn-klausur-loeschen').click();
    d.querySelector('.btn-email-ausloesen').click();
    await warten();
    assert.equal(calls.length, 0);

    w.confirm = () => true;
    d.querySelector('.btn-klausur-loeschen').click();
    d.querySelector('.btn-email-ausloesen').click();
    await warten();
    assert.ok(calls.includes('DELETE /klausuren/1'));
    assert.ok(calls.includes('POST /stufenleitung/email-ausloesen/1'));
    assert.ok(calls.some(c => c.startsWith('ALERT E-Mail wurde gesendet an: a@x.de')));
});

test('Klausur bearbeiten sendet die geänderten Werte', async () => {
    const { d, calls } = await starte('klausuren', SL, fixtures(), { warteMs: 60 });

    d.querySelector('.btn-klausur-bearbeiten').click();
    d.querySelector('#dlg-datum').value = '2026-11-02';
    d.querySelector('#dlg-uhrzeit').value = '09:15';
    d.querySelector('#dlg-dauer').value = '45';
    calls.length = 0;
    d.querySelector('#dlg-speichern').click();
    await warten();

    const put = calls.find(c => c.startsWith('PUT /klausuren/1'));
    assert.deepEqual(JSON.parse(put.slice('PUT /klausuren/1 '.length)), { termin_datum: '2026-11-02', termin_uhrzeit: '09:15', dauer_minuten: 45 });
    assert.equal(d.querySelector('.dialog-overlay'), null);
});

test('Einzeln anlegen: Stufen-Vorauswahl, Kursliste je Stufe, Halbjahresgruppen', async () => {
    const { w, d, calls } = await starte('klausuren', SL, fixtures(), { warteMs: 60 });
    d.querySelector('.tab[data-tab="neu"]').click();
    await warten();

    const stufe = d.querySelector('#neu-stufe');
    assert.equal(stufe.value, '1', 'eigene Stufe Q2 – nicht die neueste (EF)');
    assert.match(stufe.options[stufe.selectedIndex].textContent, /meine Stufe/);
    assert.deepEqual([...d.querySelectorAll('#neu-kurs optgroup')].map(g => g.label), ['2. Halbjahr', '1. Halbjahr']);
    assert.ok(![...d.querySelectorAll('#neu-kurs option')].some(o => /Englisch|Bio/.test(o.textContent)), 'auf die Stufe vorgefiltert');

    stufe.value = '2';
    ereignis(w, stufe, 'change');
    assert.ok([...d.querySelectorAll('#neu-kurs option')].some(o => /Englisch/.test(o.textContent)), 'auch fremde Stufen wählbar');

    // Speichern ohne Kurs → Fehler; mit Kurs → POST
    d.querySelector('#neu-speichern').click();
    await warten();
    assert.match(d.querySelector('#neu-fehler').textContent, /Kurs auswählen/);

    d.querySelector('#neu-kurs').value = '3';
    d.querySelector('#neu-datum').value = '2026-10-05';
    calls.length = 0;
    d.querySelector('#neu-speichern').click();
    await warten();
    assert.ok(calls.some(c => c.startsWith('POST /klausuren ') && c.includes('"kurs_id":3') && c.includes('2026-10-05')));
    assert.match(d.querySelector('#neu-ok').textContent, /angelegt/);
});

test('Einzeln anlegen ohne Kurse verweist auf den Import', async () => {
    const fx = fixtures();
    fx['GET /kurse'] = [];
    const { d } = await starte('klausuren', SL, fx, { warteMs: 60 });
    d.querySelector('.tab[data-tab="neu"]').click();
    await warten();

    assert.match(d.querySelector('#tab-neu').textContent, /noch keine Kurse/);
});

test('Excel-Import: Halbjahr vorausgewählt, Vorlage-Link und Import folgen der Auswahl', async () => {
    const { w, d, calls } = await starte('klausuren', SL, fixtures(), { warteMs: 60 });
    d.querySelector('.tab[data-tab="paste"]').click();
    await warten();

    const halbjahr = d.querySelector('#paste-halbjahr');
    assert.equal(halbjahr.value, '2', 'neuestes Halbjahr einer eigenen Stufe (Q2, 2. HJ)');
    assert.equal(d.querySelector('#paste-vorlage').getAttribute('href'), '/api/klausuren/vorlage?halbjahr_id=2');

    halbjahr.value = '3';
    ereignis(w, halbjahr, 'change');
    assert.equal(d.querySelector('#paste-vorlage').getAttribute('href'), '/api/klausuren/vorlage?halbjahr_id=3', 'auch für fremde Stufen');

    const feld = d.querySelector('#paste-feld');
    feld.value = 'Kurs\tDatum\tUhrzeit\tDauer\nC\t01.10.2026\t8:00\t90';
    ereignis(w, feld, 'input');
    assert.match(d.querySelector('#paste-vorschau').textContent, /1 Zeile\(n\) erkannt/);

    calls.length = 0;
    d.querySelector('#paste-importieren').click();
    await warten();
    assert.ok(calls.some(c => c.startsWith('POST /klausuren/paste-import?halbjahr_id=3')));
    assert.match(d.querySelector('#paste-ergebnis').textContent, /1 Klausur\(en\) neu angelegt/);
});

test('Excel-Import: Vorschau meldet fehlende Spalte „Kurs“ und zu wenige Zeilen', async () => {
    const { w, d } = await starte('klausuren', SL, fixtures(), { warteMs: 60 });
    d.querySelector('.tab[data-tab="paste"]').click();
    await warten();
    const feld = d.querySelector('#paste-feld');

    feld.value = 'nur eine Zeile';
    ereignis(w, feld, 'input');
    assert.match(d.querySelector('#paste-vorschau').textContent, /Mindestens eine Kopfzeile/);

    feld.value = 'Datum\tUhrzeit\n01.10.2026\t8:00';
    ereignis(w, feld, 'input');
    assert.match(d.querySelector('#paste-vorschau').textContent, /Pflichtfeld fehlt: kurs/);

    feld.value = '';
    ereignis(w, feld, 'input');
    assert.equal(d.querySelector('#paste-vorschau').innerHTML, '');
});

test('Excel-Import zeigt Fehlermeldungen des Servers zeilenweise', async () => {
    const fx = fixtures();
    fx['POST /klausuren/paste-import*'] = { erstellt: 0, aktualisiert: 0, fehler: [{ zeile: 2, meldung: 'Kurs "X" nicht gefunden.' }] };
    const { w, d } = await starte('klausuren', SL, fx, { warteMs: 60 });
    d.querySelector('.tab[data-tab="paste"]').click();
    await warten();
    const feld = d.querySelector('#paste-feld');
    feld.value = 'Kurs\tDatum\nX\t01.10.2026';
    ereignis(w, feld, 'input');

    d.querySelector('#paste-importieren').click();
    await warten();

    assert.match(d.querySelector('#paste-ergebnis').textContent, /Zeile 2: Kurs "X" nicht gefunden/);
});
