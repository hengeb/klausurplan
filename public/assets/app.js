// Klausurplan – Haupt-JS (Phase 4–7 ergänzen die Views)
'use strict';

const rollen = window.KLAUSURPLAN_ROLLEN ?? [];

async function apiFetch(path, options = {}) {
    const res = await fetch('/api' + path, {
        headers: { 'Content-Type': 'application/json', ...options.headers },
        ...options,
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({ fehler: res.statusText }));
        throw new Error(err.fehler ?? `HTTP ${res.status}`);
    }
    return res.json();
}

async function init() {
    const me = await apiFetch('/me');
    document.getElementById('app').innerHTML =
        `<p>Angemeldet als <strong>${me.vorname} ${me.nachname}</strong> (${me.rollen.join(', ')})</p>`;
}

init().catch(err => {
    document.getElementById('app').innerHTML = `<p style="color:red">Fehler: ${err.message}</p>`;
});
