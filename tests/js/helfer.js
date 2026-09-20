'use strict';

// Hilfen für die Frontend-Tests: lädt public/assets/app.js in jsdom und ersetzt fetch durch Testdaten.

const { JSDOM } = require('jsdom');
const fs = require('node:fs');
const path = require('node:path');

const QUELLE = fs.readFileSync(path.join(__dirname, '../../public/assets/app.js'), 'utf8');

const SL = ['admin', 'stufenleitung', 'lehrkraft'];

const warten = (ms = 30) => new Promise(r => setTimeout(r, ms));

/**
 * Startet die App unter #hash mit den angegebenen Rollen.
 * @param {string} hash
 * @param {string[]} rollen
 * @param {Record<string, any>} fixtures  "METHODE /pfad" (Präfix mit * am Ende) → Antwort oder Funktion(options)
 * @returns {Promise<{w: Window, d: Document, calls: string[]}>}  calls = alle API-Aufrufe ("GET /pfad", "POST /pfad {body}")
 */
async function starte(hash, rollen, fixtures, { warteMs = 30, meId = 2 } = {}) {
    const calls = [];
    const dom = new JSDOM('<!DOCTYPE html><nav id="nav"></nav><main id="app"></main>', {
        runScripts: 'outside-only',
        url: 'http://klausurplan.test/#' + hash,
    });
    const w = dom.window;

    w.fetch = (url, opts = {}) => {
        const methode = opts.method ?? 'GET';
        const pfad = url.replace(/^\/api/, '');
        calls.push(`${methode} ${pfad}` + (typeof opts.body === 'string' ? ' ' + opts.body : ''));
        for (const [schluessel, wert] of Object.entries(fixtures)) {
            const [m, p] = schluessel.split(' ');
            if (m === methode && (p === pfad || (p.endsWith('*') && pfad.startsWith(p.slice(0, -1))))) {
                const antwort = typeof wert === 'function' ? wert(opts) : wert;
                return Promise.resolve({ ok: true, status: 200, statusText: 'OK', json: () => Promise.resolve(antwort) });
            }
        }
        return Promise.resolve({ ok: false, status: 404, statusText: 'Not Found', json: () => Promise.resolve({ fehler: `keine Testdaten für ${methode} ${pfad}` }) });
    };
    w.KLAUSURPLAN_ROLLEN = rollen;
    w.KLAUSURPLAN_ME_ID = meId;
    w.confirm = () => true;
    w.alert = m => calls.push('ALERT ' + m);
    w.scrollTo = () => {};
    w.eval(QUELLE);
    await warten(warteMs);

    return { w, d: w.document, calls };
}

/** Löst ein Change-/Input-Ereignis aus. */
const ereignis = (w, el, typ, opts = {}) => el.dispatchEvent(new w.Event(typ, { bubbles: true, ...opts }));

module.exports = { starte, warten, ereignis, SL };
