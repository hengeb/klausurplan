'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { starte, warten, ereignis, SL } = require('./helfer');

const stufen = [
    { id: 3, name: 'EF', schuljahr: '2026/2027', ist_meine: 0 },
    { id: 1, name: 'Q2', schuljahr: '2025/2026', ist_meine: 1 },
    { id: 2, name: 'Q1', schuljahr: '2025/2026', ist_meine: 0 },
];

const fixtures = () => ({
    'GET /me': { id: 2, vorname: 'Anna', nachname: 'L', rollen: SL, stufen: [{ id: 1, name: 'Q2', schuljahr: '2025/2026' }] },
    'GET /stufenleitung/meine-stufen': stufen,
    'PUT /stufenleitung/meine-stufen/*': { ok: true },
    'DELETE /stufenleitung/meine-stufen/*': { ok: true },
    'POST /stufenleitung/gomst-import': {
        halbjahre: 1, kurse: 3, schueler: 20, entfernt: 0,
        stufenleitung_neu: [{ id: 3, name: 'EF', schuljahr: '2026/2027' }],
    },
});

test('Startseite: Karte „Meine Stufen“ nur für die Stufenleitung', async () => {
    const mit = await starte('start', SL, fixtures());
    assert.match(mit.d.querySelector('#meine-stufen-karte').textContent, /Q2/);

    const ohne = await starte('start', ['lehrkraft'], fixtures());
    assert.equal(ohne.d.querySelector('#meine-stufen-karte'), null);
});

test('Startseite ohne Stufen erklärt, dass keine Stufe zugeordnet ist', async () => {
    const fx = fixtures();
    fx['GET /me'] = { id: 2, vorname: 'A', nachname: 'B', rollen: ['stufenleitung'], stufen: [] };
    const { d } = await starte('start', ['stufenleitung'], fx);

    assert.match(d.querySelector('#meine-stufen-karte').textContent, /für keine Stufe zuständig/);
});

test('Dialog „Meine Stufen“: Übernehmen = PUT, Abgeben = DELETE, danach wird neu geladen', async () => {
    const { w, d, calls } = await starte('start', SL, fixtures());
    calls.length = 0;

    d.querySelector('#meine-stufen-karte button').click();
    await warten();
    const boxen = [...d.querySelectorAll('.ms-stufe input')];
    assert.equal(boxen.length, 3);
    assert.equal(boxen.filter(b => b.checked).length, 1);
    assert.match([...d.querySelectorAll('.ms-jahr-titel')][0].textContent, /2026\/2027/, 'neuestes Schuljahr zuerst');

    const q1 = boxen.find(b => b.dataset.stufeId === '2');
    q1.checked = true;
    ereignis(w, q1, 'change');
    await warten();
    const q2 = boxen.find(b => b.dataset.stufeId === '1');
    q2.checked = false;
    ereignis(w, q2, 'change');
    await warten();
    assert.ok(calls.includes('PUT /stufenleitung/meine-stufen/2'));
    assert.ok(calls.includes('DELETE /stufenleitung/meine-stufen/1'));

    d.querySelector('#ms-fertig').click();
    await warten();
    assert.ok(calls.filter(c => c === 'GET /me').length >= 1, 'Übersicht wird neu geladen');
    assert.equal(d.querySelector('.dialog-overlay'), null);
});

test('Dialog: ohne Änderung kein Neuladen; Escape schließt', async () => {
    const { w, d, calls } = await starte('start', SL, fixtures());
    d.querySelector('#meine-stufen-karte button').click();
    await warten();
    calls.length = 0;

    w.document.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape' }));
    await warten();

    assert.equal(d.querySelector('.dialog-overlay'), null);
    assert.equal(calls.length, 0);
});

test('Dialog: Fehler beim Speichern setzt den Haken zurück und zeigt die Meldung', async () => {
    const fx = fixtures();
    delete fx['PUT /stufenleitung/meine-stufen/*'];
    const { w, d } = await starte('start', SL, fx);
    d.querySelector('#meine-stufen-karte button').click();
    await warten();

    const q1 = [...d.querySelectorAll('.ms-stufe input')].find(b => b.dataset.stufeId === '2');
    q1.checked = true;
    ereignis(w, q1, 'change');
    await warten();

    assert.equal(q1.checked, false);
    assert.match(d.querySelector('#ms-fehler').textContent, /keine Testdaten/);
});

test('Import zeigt neu erlangte Zuständigkeit und erlaubt Abgabe per Klick', async () => {
    const { w, d, calls } = await starte('import', SL, fixtures());
    const eingabe = d.querySelector('#gomst-datei');
    Object.defineProperty(eingabe, 'files', { value: [new w.File(['x'], 'export.dat')] });
    ereignis(w, eingabe, 'change');
    calls.length = 0;

    d.querySelector('#import-btn').click();
    await warten();

    assert.match(d.querySelector('#import-ergebnis').textContent, /Import erfolgreich/);
    assert.match(d.querySelector('#import-ergebnis').textContent, /Du bist jetzt Stufenleitung für/);
    assert.match(d.querySelector('.sl-neu-liste').textContent, /EF/);

    d.querySelector('.btn-sl-abgeben').click();
    await warten();
    assert.ok(calls.includes('DELETE /stufenleitung/meine-stufen/3'));
    assert.match(d.querySelector('.sl-neu-liste').textContent, /abgegeben/);
});

test('Import ohne neue Zuständigkeit zeigt keinen Hinweis; Import-Button erst mit Datei aktiv', async () => {
    const fx = fixtures();
    fx['POST /stufenleitung/gomst-import'] = { halbjahre: 1, kurse: 1, schueler: 1, entfernt: 0, stufenleitung_neu: [] };
    const { w, d } = await starte('import', SL, fx);
    assert.equal(d.querySelector('#import-btn').disabled, true);

    const eingabe = d.querySelector('#gomst-datei');
    Object.defineProperty(eingabe, 'files', { value: [new w.File(['x'], 'export.dat')] });
    ereignis(w, eingabe, 'change');
    assert.equal(d.querySelector('#import-btn').disabled, false);
    d.querySelector('#import-btn').click();
    await warten();

    assert.equal(d.querySelector('.sl-neu-liste'), null);
});

test('Navigation zeigt je Rolle die passenden Bereiche', async () => {
    const admin = await starte('start', ['admin'], fixtures());
    assert.match(admin.d.querySelector('#nav').textContent, /Administration/);
    assert.match(admin.d.querySelector('#nav').textContent, /Zuordnungen/);

    const lehrkraft = await starte('start', ['lehrkraft'], fixtures());
    assert.doesNotMatch(lehrkraft.d.querySelector('#nav').textContent, /Zuordnungen|Administration|Import/);
    assert.match(lehrkraft.d.querySelector('#nav').textContent, /Klausuren/);
});
