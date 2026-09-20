'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { starte, warten, ereignis } = require('./helfer');

const benutzer = [
    { id: 2, vorname: 'Anna', nachname: 'L', extern: 0, rollen: ['lehrkraft', 'stufenleitung'] },
    { id: 5, vorname: 'Ex', nachname: 'Tern', extern: 1, rollen: ['lehrkraft'] },
];

const fixtures = () => ({
    'GET /admin/benutzer': benutzer,
    'GET /admin/faecher': [{ kuerzel: 'D', bezeichnung: 'Deutsch' }],
    'POST /admin/benutzer/2/rollen': { ok: true },
});

test('Benutzerliste: externe Lehrkräfte sind gekennzeichnet und nicht bearbeitbar', async () => {
    const { d } = await starte('admin', ['admin'], fixtures());

    assert.equal(d.querySelectorAll('#benutzer-container tbody tr').length, 2);
    assert.equal(d.querySelectorAll('.btn-rollen-bearbeiten').length, 1);
    assert.match(d.body.textContent, /\(extern\)/);
});

test('Rollendialog: Stufen verwaltet die Stufenleitung selbst – nur Hinweis, kein Stufen-Picker', async () => {
    const { w, d, calls } = await starte('admin', ['admin'], fixtures());

    d.querySelector('.btn-rollen-bearbeiten').click();
    await warten();
    assert.equal(d.querySelector('#sl-stufen'), null);
    const hinweis = d.querySelector('#sl-stufen-hinweis');
    assert.notEqual(hinweis.style.display, 'none');

    const box = d.querySelector('.cb-rolle[value="stufenleitung"]');
    box.checked = false;
    ereignis(w, box, 'change');
    assert.equal(hinweis.style.display, 'none');
    box.checked = true;
    ereignis(w, box, 'change');

    calls.length = 0;
    d.querySelector('#rollen-speichern').click();
    await warten();

    assert.deepEqual(calls.map(c => c.split(' ')[0] + ' ' + c.split(' ')[1]), ['POST /admin/benutzer/2/rollen'], 'keine Stufen-Zuordnung durch den Admin');
    assert.deepEqual(JSON.parse(calls[0].slice('POST /admin/benutzer/2/rollen '.length)).rollen.sort(), ['lehrkraft', 'stufenleitung']);
    assert.equal(d.querySelector('.dialog-overlay'), null);
});

test('Rollendialog: Lehrkraft-Rolle ist fest, die eigene Admin-Rolle nicht entziehbar', async () => {
    const fx = fixtures();
    fx['GET /admin/benutzer'] = [{ id: 2, vorname: 'Anna', nachname: 'L', extern: 0, rollen: ['admin', 'lehrkraft'] }];
    const { d } = await starte('admin', ['admin'], fx, { meId: 2 });

    d.querySelector('.btn-rollen-bearbeiten').click();
    await warten();

    assert.equal(d.querySelector('.cb-rolle[value="lehrkraft"]').disabled, true);
    assert.equal(d.querySelector('.cb-rolle[value="admin"]').disabled, true);
});

test('Administration ist nur für Admins erreichbar', async () => {
    const { d } = await starte('admin', ['stufenleitung'], fixtures());

    assert.match(d.querySelector('#app').textContent, /Kein Zugriff/);
});

test('Reine Schüler*innen landen direkt in „Meine Klausuren“', async () => {
    const { d } = await starte('start', ['schueler'], {
        'GET /schueler/meine-klausuren': [{ kurs_anzeigename: 'Q2 Sport', kursart: 'GK', klausur_nr: 1, termin_datum: '2026-10-01', termin_uhrzeit: '08:00:00', dauer_minuten: 90 }],
        'GET /schueler/meine-nachschreibtermine': [],
    });

    assert.match(d.querySelector('#app').textContent, /Q2 Sport/);
    assert.equal(d.querySelector('.tab[data-tab="neu"]'), null);
});
