'use strict';

// ---------------------------------------------------------------------------
// API-Helfer
// ---------------------------------------------------------------------------

const rollen = window.KLAUSURPLAN_ROLLEN ?? [];

async function apiFetch(path, options = {}) {
    const isDateiUpload = options.body instanceof FormData;
    const headers = isDateiUpload ? {} : { 'Content-Type': 'application/json', ...(options.headers ?? {}) };

    const res = await fetch('/api' + path, { headers, ...options });
    if (!res.ok) {
        const err = await res.json().catch(() => ({ fehler: res.statusText }));
        throw new Error(err.fehler ?? `HTTP ${res.status}`);
    }
    return res.json();
}

function hatRolle(...gesucht) {
    return gesucht.some(r => rollen.includes(r));
}

// ---------------------------------------------------------------------------
// Navigation & Routing (Hash-basiert)
// ---------------------------------------------------------------------------

const VIEWS = {
    start:               viewStart,
    import:              viewImport,
    zuordnungen:         viewZuordnungen,
    halbjahre:           viewHalbjahre,
    klausuren:           viewKlausuren,
    nachschreibtermine:  viewNachschreibtermine,
};

function navigate(hash) {
    location.hash = hash;
}

window.addEventListener('hashchange', render);

function render() {
    const hash = location.hash.replace('#', '') || 'start';
    const view = VIEWS[hash] ?? viewStart;

    renderNav();
    const app = document.getElementById('app');
    app.innerHTML = '<p class="lade-text">Wird geladen…</p>';
    view(app).catch(err => {
        app.innerHTML = `<p class="fehler">Fehler: ${err.message}</p>`;
    });
}

function renderNav() {
    const nav = document.getElementById('nav');
    if (!nav) return;

    const links = [
        { hash: 'start', label: 'Übersicht' },
    ];

    if (hatRolle('admin', 'stufenleitung', 'lehrkraft')) {
        links.push({ hash: 'klausuren', label: 'Klausuren' });
        links.push({ hash: 'nachschreibtermine', label: 'Nachschreibtermine' });
    }

    if (hatRolle('admin', 'stufenleitung')) {
        links.push(
            { hash: 'import',      label: 'GoMST-Import' },
            { hash: 'zuordnungen', label: 'Zuordnungen' },
            { hash: 'halbjahre',   label: 'Halbjahre & Kurse' },
        );
    }

    const aktiv = location.hash.replace('#', '') || 'start';
    nav.innerHTML = links.map(l =>
        `<a href="#${l.hash}" class="${l.hash === aktiv ? 'aktiv' : ''}">${l.label}</a>`
    ).join('');
}

// ---------------------------------------------------------------------------
// View: Start
// ---------------------------------------------------------------------------

async function viewStart(el) {
    const me = await apiFetch('/me');
    el.innerHTML = `
        <div class="karte">
            <h2>Willkommen, ${me.vorname} ${me.nachname}</h2>
            <p>Rollen: <strong>${me.rollen.length ? me.rollen.join(', ') : '–'}</strong></p>
        </div>
        <div class="kacheln">
            ${hatRolle('admin', 'stufenleitung', 'lehrkraft') ? `
            <a href="#klausuren" class="kachel">
                <span class="kachel-icon">📝</span>
                <span>Klausuren</span>
            </a>
            <a href="#nachschreibtermine" class="kachel">
                <span class="kachel-icon">🔄</span>
                <span>Nachschreibtermine</span>
            </a>` : ''}
            ${hatRolle('admin', 'stufenleitung') ? `
            <a href="#import" class="kachel">
                <span class="kachel-icon">📥</span>
                <span>GoMST importieren</span>
            </a>
            <a href="#zuordnungen" class="kachel">
                <span class="kachel-icon">🔗</span>
                <span>Zuordnungen</span>
            </a>
            <a href="#halbjahre" class="kachel">
                <span class="kachel-icon">📋</span>
                <span>Halbjahre & Kurse</span>
            </a>` : ''}
        </div>
    `;
}

// ---------------------------------------------------------------------------
// View: GoMST-Import
// ---------------------------------------------------------------------------

async function viewImport(el) {
    if (!hatRolle('admin', 'stufenleitung')) {
        el.innerHTML = '<p class="fehler">Kein Zugriff.</p>';
        return;
    }

    el.innerHTML = `
        <h2>GoMST-Import</h2>
        <div class="karte">
            <p>
                Bitte laden Sie die GoMST-Exportdatei (.dat) hoch.
                Es werden nur klausurrelevante Kursarten importiert
                (GKS, LK1, LK2, AB3, AB4). GKM und ZK werden übersprungen.
            </p>
            <div class="formular-zeile">
                <label for="gomst-datei" class="btn btn-sekundaer">Datei auswählen</label>
                <input type="file" id="gomst-datei" accept=".dat,.txt,.csv" style="display:none">
                <span id="datei-name" class="datei-name-text">Keine Datei ausgewählt</span>
            </div>
            <button id="import-btn" class="btn" disabled>Importieren</button>
            <div id="import-ergebnis"></div>
        </div>
    `;

    const dateiInput = el.querySelector('#gomst-datei');
    const dateiNameEl = el.querySelector('#datei-name');
    const importBtn = el.querySelector('#import-btn');
    const ergebnisEl = el.querySelector('#import-ergebnis');

    dateiInput.addEventListener('change', () => {
        const datei = dateiInput.files[0];
        dateiNameEl.textContent = datei ? datei.name : 'Keine Datei ausgewählt';
        importBtn.disabled = !datei;
    });

    importBtn.addEventListener('click', async () => {
        const datei = dateiInput.files[0];
        if (!datei) return;

        importBtn.disabled = true;
        importBtn.textContent = 'Wird importiert…';
        ergebnisEl.innerHTML = '';

        try {
            const formData = new FormData();
            formData.append('datei', datei);

            const res = await apiFetch('/stufenleitung/gomst-import', {
                method: 'POST',
                body: formData,
            });

            ergebnisEl.innerHTML = `
                <div class="ergebnis-box ergebnis-ok">
                    <strong>Import erfolgreich</strong>
                    <ul>
                        <li>${res.halbjahre} Halbjahr(e) angelegt/aktualisiert</li>
                        <li>${res.kurse} Kurs(e) verarbeitet</li>
                        <li>${res.schueler} Schüler*innen importiert</li>
                        <li>${res.entfernt} Schüler*innen entfernt (nicht mehr in Datei)</li>
                    </ul>
                    <a href="#zuordnungen" class="btn btn-sekundaer">Zu den Zuordnungen →</a>
                </div>
            `;
        } catch (err) {
            ergebnisEl.innerHTML = `<p class="fehler">${err.message}</p>`;
        } finally {
            importBtn.disabled = false;
            importBtn.textContent = 'Importieren';
        }
    });
}

// ---------------------------------------------------------------------------
// View: Zuordnungen
// ---------------------------------------------------------------------------

async function viewZuordnungen(el) {
    if (!hatRolle('admin', 'stufenleitung')) {
        el.innerHTML = '<p class="fehler">Kein Zugriff.</p>';
        return;
    }

    el.innerHTML = '<p class="lade-text">Zuordnungen werden geladen…</p>';
    const daten = await apiFetch('/stufenleitung/zuordnungen');
    renderZuordnungenView(el, daten);
}

/**
 * Prüft ob ein Moodle-Nutzer zur Stufe eines GoMST-Eintrags passt.
 * - Kein Moodle-Stufe → immer anzeigen (unbekannt = nicht ausschließen)
 * - GoMST-Stufe beginnt mit Zahl (z.B. "9a", "10b") → Moodle-Stufe muss mit
 *   derselben führenden Zahl beginnen (z.B. "9b", "10c" passen auch)
 * - Sonst (z.B. "Q2", "EF") → exakter Vergleich
 */
function matchesStufe(moodleStufe, gomstStufen) {
    if (!moodleStufe) return true;
    const stufen = (gomstStufen ?? '').split(',').map(s => s.trim()).filter(Boolean);
    if (stufen.length === 0) return true;
    for (const stufe of stufen) {
        const numPräfix = stufe.match(/^(\d+)/);
        if (numPräfix) {
            if (moodleStufe.startsWith(numPräfix[1])) return true;
        } else {
            if (moodleStufe === stufe) return true;
        }
    }
    return false;
}

function renderZuordnungenView(el, daten) {
    const {
        schueler_gomst: sGomst,
        schueler_moodle: sMoodle,
        lehrkraefte_kurse: lKurseRaw,
        lehrkraefte_moodle: lMoodle,
    } = daten;

    // Kürzels, die mit einer Ziffer enden, sind keine echten Lehrkraft-Kürzels
    const lKurse = lKurseRaw.filter(k => !/\d$/.test(k.lehrer_kuerzel));

    const anzUnzugeordnetS = sGomst.length;
    const anzUnzugeordnetL = lKurse.filter(k => !lMoodle.some(l => l.kuerzel === k.lehrer_kuerzel)).length;

    el.innerHTML = `
        <h2>Zuordnungen</h2>

        <div class="tabs">
            <button class="tab aktiv" data-tab="schueler">
                Schüler*innen
                ${anzUnzugeordnetS > 0 ? `<span class="badge">${anzUnzugeordnetS}</span>` : ''}
            </button>
            <button class="tab" data-tab="lehrkraefte">
                Lehrkräfte
                ${anzUnzugeordnetL > 0 ? `<span class="badge">${anzUnzugeordnetL}</span>` : ''}
            </button>
        </div>

        <div id="tab-schueler" class="tab-inhalt">
            ${renderSchuelerZuordnung(sGomst, sMoodle)}
        </div>
        <div id="tab-lehrkraefte" class="tab-inhalt versteckt">
            ${renderLehrkraefte(lKurse, lMoodle)}
        </div>
    `;

    // Tab-Wechsel
    el.querySelectorAll('.tab').forEach(btn => {
        btn.addEventListener('click', () => {
            el.querySelectorAll('.tab').forEach(b => b.classList.remove('aktiv'));
            el.querySelectorAll('.tab-inhalt').forEach(t => t.classList.add('versteckt'));
            btn.classList.add('aktiv');
            el.querySelector(`#tab-${btn.dataset.tab}`).classList.remove('versteckt');
        });
    });

    // Zuordnungs-Buttons für Schüler*innen
    el.querySelectorAll('.btn-zuordnen-s').forEach(btn => {
        btn.addEventListener('click', async () => {
            const nameRoh = btn.dataset.nameRoh;
            const select  = el.querySelector(`select[data-name-roh="${CSS.escape(nameRoh)}"]`);
            const benutzerId = select?.value ? parseInt(select.value) : null;

            if (!benutzerId) return;

            btn.disabled = true;
            try {
                await apiFetch('/stufenleitung/zuordnungen', {
                    method: 'POST',
                    body: JSON.stringify({ typ: 'schueler', name_roh: nameRoh, benutzer_id: benutzerId }),
                });
                const zeile = btn.closest('tr');
                zeile.classList.add('zugeordnet');
                zeile.querySelector('.zuordnung-status').textContent = '✓ Zugeordnet';
                select.disabled = true;
                btn.remove();
            } catch (err) {
                alert('Fehler: ' + err.message);
                btn.disabled = false;
            }
        });
    });

    // Zuordnungs-Buttons für Lehrkräfte
    el.querySelectorAll('.btn-zuordnen-l').forEach(btn => {
        btn.addEventListener('click', async () => {
            const kuerzel    = btn.dataset.kuerzel;
            const select     = el.querySelector(`select[data-kuerzel="${CSS.escape(kuerzel)}"]`);
            const benutzerId = select?.value ? parseInt(select.value) : null;

            if (!benutzerId) return;

            btn.disabled = true;
            try {
                await apiFetch('/stufenleitung/zuordnungen', {
                    method: 'POST',
                    body: JSON.stringify({ typ: 'lehrkraft', lehrer_kuerzel: kuerzel, benutzer_id: benutzerId }),
                });
                const zeile = btn.closest('tr');
                zeile.classList.add('zugeordnet');
                zeile.querySelector('.zuordnung-status').textContent = '✓ Zugeordnet';
                select.disabled = true;
                btn.remove();
            } catch (err) {
                alert('Fehler: ' + err.message);
                btn.disabled = false;
            }
        });
    });
}

function renderSchuelerZuordnung(sGomst, sMoodle) {
    if (sGomst.length === 0) {
        return '<div class="karte"><p class="ok-text">✓ Alle Schüler*innen sind zugeordnet.</p></div>';
    }

    const zeilen = sGomst.map(ks => {
        const [nachname, vorname] = ks.name_roh.split('|');
        const nameRohAttr = escHtml(ks.name_roh);

        // Nur Moodle-Konten mit passender Stufe anzeigen
        const passende = sMoodle.filter(m => matchesStufe(m.stufe, ks.stufen));
        const moodleOptionen = passende.map(m =>
            `<option value="${m.id}">${m.nachname}, ${m.vorname}${m.stufe ? ` (${m.stufe})` : ''}</option>`
        ).join('');

        return `
        <tr>
            <td>${escHtml(nachname)}, ${escHtml(vorname ?? '')}</td>
            <td>${escHtml(ks.stufen)}</td>
            <td>${ks.anzahl_kurse}</td>
            <td>
                <select data-name-roh="${nameRohAttr}" class="select-zuordnung">
                    <option value="">– Moodle-Konto wählen –</option>
                    ${moodleOptionen}
                </select>
            </td>
            <td>
                <button class="btn btn-klein btn-zuordnen-s" data-name-roh="${nameRohAttr}">Zuordnen</button>
                <span class="zuordnung-status"></span>
            </td>
        </tr>`;
    }).join('');

    return `
        <div class="tabelle-wrapper">
            <p class="tabelle-hinweis">
                ${sGomst.length} Schüler*innen aus GoMST ohne Moodle-Konto-Zuordnung.
                Wählen Sie das passende Moodle-Konto aus – die Zuordnung gilt für alle Kurse der Person.
            </p>
            <table class="zuordnungs-tabelle">
                <thead>
                    <tr>
                        <th>GoMST-Name</th>
                        <th>Stufe(n)</th>
                        <th>Kurse</th>
                        <th>Moodle-Konto</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>${zeilen}</tbody>
            </table>
        </div>
    `;
}

function renderLehrkraefte(lKurse, lMoodle) {
    if (lKurse.length === 0) {
        return '<div class="karte"><p class="ok-text">✓ Alle Lehrkräfte sind zugeordnet.</p></div>';
    }

    const moodleOptionen = lMoodle.map(l =>
        `<option value="${l.id}">${l.nachname}, ${l.vorname} (${l.kuerzel})</option>`
    ).join('');

    const zeilen = lKurse.map(k => {
        const kuerzelAttr = escHtml(k.lehrer_kuerzel);
        return `
        <tr>
            <td>${kuerzelAttr}</td>
            <td>${k.anzahl_kurse}</td>
            <td>
                <select data-kuerzel="${kuerzelAttr}" class="select-zuordnung">
                    <option value="">– Lehrkraft wählen –</option>
                    ${moodleOptionen}
                </select>
            </td>
            <td>
                <button class="btn btn-klein btn-zuordnen-l" data-kuerzel="${kuerzelAttr}">Zuordnen</button>
                <span class="zuordnung-status"></span>
            </td>
        </tr>`;
    }).join('');

    return `
        <div class="tabelle-wrapper">
            <p class="tabelle-hinweis">
                ${lKurse.length} Kürzel ohne Moodle-Konto-Zuordnung.
                Die Zuordnung gilt für alle Kurse mit dem jeweiligen Kürzel.
            </p>
            <table class="zuordnungs-tabelle">
                <thead>
                    <tr>
                        <th>Kürzel</th>
                        <th>Kurse</th>
                        <th>Moodle-Konto</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>${zeilen}</tbody>
            </table>
        </div>
    `;
}

// ---------------------------------------------------------------------------
// View: Halbjahre & Kurse
// ---------------------------------------------------------------------------

async function viewHalbjahre(el) {
    if (!hatRolle('admin', 'stufenleitung')) {
        el.innerHTML = '<p class="fehler">Kein Zugriff.</p>';
        return;
    }

    el.innerHTML = '<p class="lade-text">Halbjahre werden geladen…</p>';
    const halbjahre = await apiFetch('/stufenleitung/halbjahre');

    if (halbjahre.length === 0) {
        el.innerHTML = `
            <h2>Halbjahre & Kurse</h2>
            <div class="karte">
                <p>Noch keine Daten importiert. <a href="#import">GoMST-Datei importieren →</a></p>
            </div>`;
        return;
    }

    // Nach Schuljahr gruppieren
    const nachSchuljahr = {};
    for (const hj of halbjahre) {
        (nachSchuljahr[hj.schuljahr] ??= []).push(hj);
    }

    const accordion = Object.entries(nachSchuljahr)
        .sort(([a], [b]) => b.localeCompare(a))
        .map(([schuljahr, hjs]) => `
            <div class="accordion-item">
                <button class="accordion-kopf" type="button">
                    Schuljahr ${escHtml(schuljahr)}
                    <span class="accordion-anzahl">${hjs.length} Halbjahr(e)</span>
                </button>
                <div class="accordion-body">
                    ${hjs.map(hj => `
                        <div class="hj-block">
                            <h4>${escHtml(hj.stufe)} – ${hj.abschnitt}. Halbjahr
                                <span class="hj-meta">${hj.kurs_anzahl} Kurs(e)</span>
                            </h4>
                            <button class="btn btn-klein btn-kurs-laden" data-hj-id="${hj.id}">
                                Kurse anzeigen
                            </button>
                            <div id="kurse-${hj.id}" class="kurs-liste versteckt"></div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `).join('');

    el.innerHTML = `<h2>Halbjahre & Kurse</h2><div class="accordion">${accordion}</div>`;

    // Accordion
    el.querySelectorAll('.accordion-kopf').forEach(btn => {
        btn.addEventListener('click', () => {
            const body = btn.nextElementSibling;
            body.classList.toggle('versteckt');
            btn.classList.toggle('offen');
        });
        // Erstes Schuljahr aufklappen
        if (btn === el.querySelector('.accordion-kopf')) {
            btn.nextElementSibling.classList.remove('versteckt');
            btn.classList.add('offen');
        }
    });

    // Kurse lazy laden
    el.querySelectorAll('.btn-kurs-laden').forEach(btn => {
        btn.addEventListener('click', async () => {
            const hjId = btn.dataset.hjId;
            const ziel = el.querySelector(`#kurse-${hjId}`);
            btn.disabled = true;
            btn.textContent = 'Wird geladen…';
            try {
                const kurse = await apiFetch(`/stufenleitung/halbjahre/${hjId}/kurse`);
                ziel.innerHTML = renderKursTabelle(kurse);
                ziel.classList.remove('versteckt');
                btn.remove();
            } catch (err) {
                btn.disabled = false;
                btn.textContent = 'Kurse anzeigen';
                ziel.innerHTML = `<p class="fehler">${err.message}</p>`;
                ziel.classList.remove('versteckt');
            }
        });
    });
}

function renderKursTabelle(kurse) {
    if (kurse.length === 0) return '<p class="hinweis">Keine Kurse in diesem Halbjahr.</p>';

    const zeilen = kurse.map(k => {
        const lehrkraft = k.lehrer_id
            ? `${escHtml(k.lehrer_nachname)}, ${escHtml(k.lehrer_vorname)}`
            : `<span class="fehlend">${escHtml(k.lehrer_kuerzel ?? '–')} (nicht zugeordnet)</span>`;

        const zugeordnetProzent = k.schueler_gesamt > 0
            ? Math.round((k.schueler_zugeordnet / k.schueler_gesamt) * 100)
            : 0;

        const ampel = k.schueler_gesamt === 0 ? 'grau'
            : zugeordnetProzent === 100 ? 'gruen'
            : zugeordnetProzent > 0 ? 'gelb'
            : 'rot';

        return `
        <tr>
            <td>${escHtml(k.anzeigename)}</td>
            <td>${escHtml(k.kursart)}</td>
            <td>${lehrkraft}</td>
            <td>
                <span class="ampel ampel-${ampel}"></span>
                ${k.schueler_zugeordnet}/${k.schueler_gesamt}
            </td>
        </tr>`;
    }).join('');

    return `
        <table class="kurs-tabelle">
            <thead>
                <tr>
                    <th>Kurs</th>
                    <th>Art</th>
                    <th>Lehrkraft</th>
                    <th>Schüler*innen</th>
                </tr>
            </thead>
            <tbody>${zeilen}</tbody>
        </table>`;
}

// ---------------------------------------------------------------------------
// View: Klausuren
// ---------------------------------------------------------------------------

async function viewKlausuren(el) {
    if (!hatRolle('admin', 'stufenleitung', 'lehrkraft')) {
        el.innerHTML = '<p class="fehler">Kein Zugriff.</p>';
        return;
    }

    el.innerHTML = `
        <h2>Klausuren</h2>
        <div class="tabs">
            <button class="tab aktiv" data-tab="uebersicht">Übersicht</button>
            ${hatRolle('admin', 'stufenleitung') ? `
            <button class="tab" data-tab="neu">Einzeln anlegen</button>
            <button class="tab" data-tab="paste">Excel-Import</button>` : ''}
        </div>
        <div id="tab-uebersicht" class="tab-inhalt">
            <p class="lade-text">Wird geladen…</p>
        </div>
        ${hatRolle('admin', 'stufenleitung') ? `
        <div id="tab-neu" class="tab-inhalt versteckt"></div>
        <div id="tab-paste" class="tab-inhalt versteckt"></div>` : ''}
    `;

    el.querySelectorAll('.tab').forEach(btn => {
        btn.addEventListener('click', () => {
            el.querySelectorAll('.tab').forEach(b => b.classList.remove('aktiv'));
            el.querySelectorAll('.tab-inhalt').forEach(t => t.classList.add('versteckt'));
            btn.classList.add('aktiv');
            const ziel = el.querySelector(`#tab-${btn.dataset.tab}`);
            ziel.classList.remove('versteckt');

            if (btn.dataset.tab === 'uebersicht' && ziel.children.length === 0) {
                ladeKlausurenUebersicht(ziel);
            } else if (btn.dataset.tab === 'neu' && ziel.children.length === 0) {
                ladeKlausurNeuFormular(ziel, () => {
                    el.querySelector('[data-tab="uebersicht"]').click();
                });
            } else if (btn.dataset.tab === 'paste' && ziel.children.length === 0) {
                ladePasteImport(ziel, () => {
                    el.querySelector('[data-tab="uebersicht"]').click();
                });
            }
        });
    });

    // Übersicht sofort laden
    ladeKlausurenUebersicht(el.querySelector('#tab-uebersicht'));
}

async function ladeKlausurenUebersicht(el) {
    el.innerHTML = '<p class="lade-text">Wird geladen…</p>';
    try {
        const klausuren = await apiFetch('/klausuren');
        renderKlausurenUebersicht(el, klausuren);
    } catch (err) {
        el.innerHTML = `<p class="fehler">${err.message}</p>`;
    }
}

function renderKlausurenUebersicht(el, klausuren) {
    if (klausuren.length === 0) {
        el.innerHTML = `<div class="karte"><p>Noch keine Klausuren angelegt.
            ${hatRolle('admin', 'stufenleitung') ? ' Nutzen Sie "Einzeln anlegen" oder "Excel-Import".' : ''}</p></div>`;
        return;
    }

    // Nach Halbjahr gruppieren
    const gruppen = {};
    for (const k of klausuren) {
        const key = `${k.schuljahr}|${k.halbjahr_id}`;
        if (!gruppen[key]) {
            gruppen[key] = {
                label: `${k.stufe} – ${k.schuljahr}, ${k.abschnitt}. Halbjahr`,
                schuljahr: k.schuljahr,
                abschnitt: k.abschnitt,
                items: [],
            };
        }
        gruppen[key].items.push(k);
    }

    const sortiert = Object.values(gruppen).sort((a, b) =>
        b.schuljahr.localeCompare(a.schuljahr) || b.abschnitt - a.abschnitt
    );

    el.innerHTML = sortiert.map(g => `
        <div class="karte">
            <h3 class="karte-titel">${escHtml(g.label)}</h3>
            <table class="klausur-tabelle">
                <thead>
                    <tr>
                        <th>Kurs</th>
                        <th>Art</th>
                        <th>Lehrkraft</th>
                        <th>Datum</th>
                        <th>Uhrzeit</th>
                        <th>Dauer</th>
                        <th>Raum</th>
                        <th>TN</th>
                        <th>Anw.</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    ${g.items.map(k => renderKlausurZeile(k)).join('')}
                </tbody>
            </table>
        </div>
    `).join('');

    // Bearbeiten-Buttons
    if (hatRolle('admin', 'stufenleitung')) {
        el.querySelectorAll('.btn-klausur-bearbeiten').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = parseInt(btn.dataset.id);
                const k  = klausuren.find(x => x.id === id);
                if (k) zeigeKlausurBearbeitenDialog(k, () => ladeKlausurenUebersicht(el));
            });
        });
    }

    // Anwesenheits-Buttons
    el.querySelectorAll('.btn-anwesenheit').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.dataset.id);
            const k  = klausuren.find(x => x.id === id);
            if (k) zeigeAnwesenheitDialog(k);
        });
    });
}

function renderKlausurZeile(k) {
    const datum   = k.termin_datum    ? formatDatum(k.termin_datum)       : '<span class="fehlend">–</span>';
    const uhrzeit = k.termin_uhrzeit  ? k.termin_uhrzeit.substring(0, 5)  : '–';
    const dauer   = k.dauer_minuten   ? `${k.dauer_minuten} min`          : '–';
    const raum    = k.raum            ? escHtml(k.raum)                   : '–';
    const lk = k.lehrer_id
        ? `${escHtml(k.lehrer_nachname)}, ${escHtml(k.lehrer_vorname)}`
        : `<span class="fehlend">${escHtml(k.lehrer_kuerzel ?? '–')}</span>`;

    const aktionen = [];
    if (hatRolle('admin', 'stufenleitung')) {
        aktionen.push(`<button class="btn btn-klein btn-sekundaer btn-klausur-bearbeiten" data-id="${k.id}">Bearbeiten</button>`);
    }
    aktionen.push(`<button class="btn btn-klein btn-anwesenheit" data-id="${k.id}">Anwesenheit</button>`);

    return `
        <tr>
            <td>${escHtml(k.kurs_anzeigename)}${k.klausur_nr > 1 ? ` <span class="klausur-nr">(Nr. ${k.klausur_nr})</span>` : ''}</td>
            <td>${escHtml(k.kursart)}</td>
            <td>${lk}</td>
            <td>${datum}</td>
            <td>${uhrzeit}</td>
            <td>${dauer}</td>
            <td>${raum}</td>
            <td>${k.schueler_anzahl}</td>
            <td>${renderAnwesenheitStatus(k.anwesenheit_erfasst, k.schueler_anzahl)}</td>
            <td class="td-aktionen">${aktionen.join(' ')}</td>
        </tr>`;
}

function zeigeKlausurBearbeitenDialog(k, nachSpeichern) {
    const overlay = document.createElement('div');
    overlay.className = 'dialog-overlay';
    overlay.innerHTML = `
        <div class="dialog">
            <h3>Klausur bearbeiten</h3>
            <p class="dialog-kursname">${escHtml(k.kurs_anzeigename)}</p>
            <div class="formular-gruppe">
                <label>Datum</label>
                <input type="date" id="dlg-datum" value="${k.termin_datum ?? ''}">
            </div>
            <div class="formular-gruppe">
                <label>Uhrzeit</label>
                <input type="time" id="dlg-uhrzeit" value="${k.termin_uhrzeit ? k.termin_uhrzeit.substring(0,5) : ''}">
            </div>
            <div class="formular-gruppe">
                <label>Dauer (Minuten)</label>
                <input type="number" id="dlg-dauer" min="1" max="600" value="${k.dauer_minuten ?? ''}">
            </div>
            <div class="formular-gruppe">
                <label>Raum</label>
                <input type="text" id="dlg-raum" value="${escHtml(k.raum ?? '')}">
            </div>
            <div class="dialog-aktionen">
                <button class="btn" id="dlg-speichern">Speichern</button>
                <button class="btn btn-sekundaer" id="dlg-abbrechen">Abbrechen</button>
            </div>
            <p id="dlg-fehler" class="fehler" style="display:none"></p>
        </div>
    `;
    document.body.appendChild(overlay);

    overlay.querySelector('#dlg-abbrechen').addEventListener('click', () => overlay.remove());
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });

    overlay.querySelector('#dlg-speichern').addEventListener('click', async () => {
        const btn = overlay.querySelector('#dlg-speichern');
        const fehlerEl = overlay.querySelector('#dlg-fehler');
        btn.disabled = true;
        fehlerEl.style.display = 'none';

        try {
            await apiFetch(`/klausuren/${k.id}`, {
                method: 'PUT',
                body: JSON.stringify({
                    termin_datum:    overlay.querySelector('#dlg-datum').value   || null,
                    termin_uhrzeit:  overlay.querySelector('#dlg-uhrzeit').value || null,
                    dauer_minuten:   parseInt(overlay.querySelector('#dlg-dauer').value) || null,
                    raum:            overlay.querySelector('#dlg-raum').value    || null,
                }),
            });
            overlay.remove();
            nachSpeichern();
        } catch (err) {
            fehlerEl.textContent = err.message;
            fehlerEl.style.display = '';
            btn.disabled = false;
        }
    });
}

async function ladeKlausurNeuFormular(el, nachSpeichern) {
    el.innerHTML = '<p class="lade-text">Kursliste wird geladen…</p>';
    try {
        const kurse = await apiFetch('/kurse');
        renderKlausurNeuFormular(el, kurse, nachSpeichern);
    } catch (err) {
        el.innerHTML = `<p class="fehler">${err.message}</p>`;
    }
}

function renderKlausurNeuFormular(el, kurse, nachSpeichern) {
    // Kurse nach Schuljahr/Halbjahr gruppieren
    const gruppen = {};
    for (const k of kurse) {
        const key = `${k.schuljahr} / ${k.abschnitt}. HJ`;
        (gruppen[key] ??= []).push(k);
    }

    const optionen = Object.entries(gruppen).map(([gruppe, ks]) =>
        `<optgroup label="${escHtml(gruppe)}">
            ${ks.map(k => `<option value="${k.id}">${escHtml(k.anzeigename)}</option>`).join('')}
        </optgroup>`
    ).join('');

    el.innerHTML = `
        <div class="karte" style="max-width:520px">
            <h3>Neue Klausur anlegen</h3>
            <div class="formular-gruppe">
                <label for="neu-kurs">Kurs *</label>
                <select id="neu-kurs" class="select-zuordnung" style="max-width:100%">
                    <option value="">– Kurs wählen –</option>
                    ${optionen}
                </select>
            </div>
            <div class="formular-gruppe">
                <label for="neu-datum">Datum</label>
                <input type="date" id="neu-datum">
            </div>
            <div class="formular-gruppe">
                <label for="neu-uhrzeit">Uhrzeit</label>
                <input type="time" id="neu-uhrzeit">
            </div>
            <div class="formular-gruppe">
                <label for="neu-dauer">Dauer (Minuten)</label>
                <input type="number" id="neu-dauer" min="1" max="600" placeholder="z.B. 90">
            </div>
            <div class="formular-gruppe">
                <label for="neu-raum">Raum</label>
                <input type="text" id="neu-raum" placeholder="z.B. Aula">
            </div>
            <div class="formular-zeile">
                <button class="btn" id="neu-speichern">Klausur anlegen</button>
            </div>
            <p id="neu-fehler" class="fehler" style="display:none"></p>
            <p id="neu-ok" class="ok-text" style="display:none"></p>
        </div>
    `;

    el.querySelector('#neu-speichern').addEventListener('click', async () => {
        const btn     = el.querySelector('#neu-speichern');
        const fehlerEl = el.querySelector('#neu-fehler');
        const okEl    = el.querySelector('#neu-ok');
        const kursId  = parseInt(el.querySelector('#neu-kurs').value);

        fehlerEl.style.display = 'none';
        okEl.style.display = 'none';

        if (!kursId) {
            fehlerEl.textContent = 'Bitte einen Kurs auswählen.';
            fehlerEl.style.display = '';
            return;
        }

        btn.disabled = true;
        try {
            await apiFetch('/klausuren', {
                method: 'POST',
                body: JSON.stringify({
                    kurs_id:        kursId,
                    termin_datum:   el.querySelector('#neu-datum').value   || null,
                    termin_uhrzeit: el.querySelector('#neu-uhrzeit').value || null,
                    dauer_minuten:  parseInt(el.querySelector('#neu-dauer').value) || null,
                    raum:           el.querySelector('#neu-raum').value    || null,
                }),
            });
            okEl.textContent = '✓ Klausur angelegt.';
            okEl.style.display = '';
            // Formular zurücksetzen
            el.querySelector('#neu-kurs').value   = '';
            el.querySelector('#neu-datum').value  = '';
            el.querySelector('#neu-uhrzeit').value = '';
            el.querySelector('#neu-dauer').value  = '';
            el.querySelector('#neu-raum').value   = '';
        } catch (err) {
            fehlerEl.textContent = err.message;
            fehlerEl.style.display = '';
        } finally {
            btn.disabled = false;
        }
    });
}

function ladePasteImport(el, nachImport) {
    el.innerHTML = `
        <div class="karte">
            <h3>Excel-Import</h3>
            <p>
                Laden Sie die Vorlage herunter – sie enthält bereits alle Kurse des aktuellen Halbjahres.
                Öffnen Sie sie in Excel, tragen Sie Datum, Uhrzeit, Dauer und Raum ein.
                Dann alles markieren (Strg+A), kopieren (Strg+C), in das Textfeld unten klicken und einfügen (Strg+V).
            </p>
            <p class="hinweis">
                Datum im Format <strong>TT.MM.JJJJ</strong> als Text eingeben
                (Zellen ggf. als Text formatieren, damit Excel das Datum nicht umwandelt).
                Die Spalte "Anzeigename" wird beim Import ignoriert.
            </p>
            <div style="margin-bottom:1rem">
                <a href="/api/klausuren/vorlage" class="btn btn-sekundaer">Vorlage herunterladen (.csv)</a>
            </div>
            <textarea id="paste-feld" class="paste-textarea" placeholder="Hier einfügen (Strg+V)…" rows="10"></textarea>
            <div id="paste-vorschau"></div>
        </div>
    `;

    el.querySelector('#paste-feld').addEventListener('input', () => {
        const text = el.querySelector('#paste-feld').value.trim();
        if (text) parsePasteVorschau(text, el.querySelector('#paste-vorschau'), nachImport);
        else el.querySelector('#paste-vorschau').innerHTML = '';
    });
}

function parsePasteVorschau(text, vorschauEl, nachImport) {
    const zeilen = text.split('\n').filter(z => z.trim() !== '');
    if (zeilen.length < 2) {
        vorschauEl.innerHTML = '<p class="hinweis">Mindestens eine Kopfzeile und eine Datenzeile erforderlich.</p>';
        return;
    }

    const header = zeilen[0].split('\t').map(h => h.trim().toLowerCase());
    const felder  = ['kurs', 'datum', 'uhrzeit', 'dauer', 'raum'];
    const fehlendeFelder = ['kurs'].filter(f => !header.includes(f));

    if (fehlendeFelder.length > 0) {
        vorschauEl.innerHTML = `<p class="fehler">Pflichtfeld fehlt: ${fehlendeFelder.join(', ')}</p>`;
        return;
    }

    const daten = zeilen.slice(1).map(z => {
        const werte = z.split('\t');
        const obj = {};
        header.forEach((h, i) => { obj[h] = (werte[i] ?? '').trim(); });
        return obj;
    });

    // Vorschau-Tabelle
    const zeileHtml = daten.map((d, i) => `
        <tr>
            <td>${i + 1}</td>
            <td>${escHtml(d.kurs ?? '')}</td>
            <td>${escHtml(d.datum ?? '')}</td>
            <td>${escHtml(d.uhrzeit ?? '')}</td>
            <td>${escHtml(d.dauer ?? '')}</td>
            <td>${escHtml(d.raum ?? '')}</td>
        </tr>`).join('');

    vorschauEl.innerHTML = `
        <div class="tabelle-wrapper" style="margin-top:1rem">
            <p class="tabelle-hinweis">${daten.length} Zeile(n) erkannt. Vorschau:</p>
            <table class="klausur-tabelle">
                <thead><tr><th>#</th><th>Kurs</th><th>Datum</th><th>Uhrzeit</th><th>Dauer</th><th>Raum</th></tr></thead>
                <tbody>${zeileHtml}</tbody>
            </table>
        </div>
        <div style="margin-top:1rem">
            <button class="btn" id="paste-importieren">Importieren</button>
        </div>
        <div id="paste-ergebnis"></div>
    `;

    vorschauEl.querySelector('#paste-importieren').addEventListener('click', async () => {
        const btn = vorschauEl.querySelector('#paste-importieren');
        const ergebnisEl = vorschauEl.querySelector('#paste-ergebnis');
        btn.disabled = true;
        btn.textContent = 'Wird importiert…';
        ergebnisEl.innerHTML = '';

        try {
            const res = await apiFetch('/klausuren/paste-import', {
                method: 'POST',
                body: JSON.stringify(daten),
            });

            let html = `<div class="ergebnis-box ergebnis-ok">
                <strong>Import abgeschlossen</strong>
                <ul>
                    <li>${res.erstellt} Klausur(en) neu angelegt</li>
                    <li>${res.aktualisiert} Klausur(en) aktualisiert</li>
                </ul>`;

            if (res.fehler?.length > 0) {
                html += `<p><strong>${res.fehler.length} Fehler:</strong></p><ul>`;
                res.fehler.forEach(f => {
                    html += `<li>Zeile ${f.zeile}: ${escHtml(f.meldung)}</li>`;
                });
                html += '</ul>';
            }

            html += `<a href="#klausuren" class="btn btn-sekundaer" style="margin-top:.5rem">Zur Übersicht →</a></div>`;
            ergebnisEl.innerHTML = html;
        } catch (err) {
            ergebnisEl.innerHTML = `<p class="fehler">${err.message}</p>`;
        } finally {
            btn.disabled = false;
            btn.textContent = 'Importieren';
        }
    });
}

// ---------------------------------------------------------------------------
// Anwesenheits-Dialog
// ---------------------------------------------------------------------------

async function zeigeAnwesenheitDialog(klausur) {
    const overlay = document.createElement('div');
    overlay.className = 'dialog-overlay';
    overlay.innerHTML = `
        <div class="dialog dialog-anwesenheit">
            <h3>Anwesenheit</h3>
            <p class="dialog-kursname">
                ${escHtml(klausur.kurs_anzeigename)}
                ${klausur.klausur_nr > 1 ? ` <span class="klausur-nr">(Nr. ${klausur.klausur_nr})</span>` : ''}
                ${klausur.termin_datum ? ' – ' + formatDatum(klausur.termin_datum) : ''}
            </p>
            <div id="aw-inhalt"><p class="lade-text">Wird geladen…</p></div>
            <div class="dialog-aktionen" style="margin-top:1rem">
                <button class="btn" id="aw-speichern" disabled>Speichern</button>
                <button class="btn btn-sekundaer" id="aw-schliessen">Schließen</button>
            </div>
            <p id="aw-fehler" class="fehler" style="display:none"></p>
            <p id="aw-ok" class="ok-text" style="display:none"></p>
        </div>
    `;
    document.body.appendChild(overlay);

    overlay.querySelector('#aw-schliessen').addEventListener('click', () => overlay.remove());
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });

    // Anwesenheitsdaten laden
    let eintraege = [];
    try {
        eintraege = await apiFetch(`/anwesenheit/${klausur.id}`);
    } catch (err) {
        overlay.querySelector('#aw-inhalt').innerHTML = `<p class="fehler">${err.message}</p>`;
        return;
    }

    if (eintraege.length === 0) {
        overlay.querySelector('#aw-inhalt').innerHTML =
            '<p class="hinweis">Keine Prüflinge diesem Kurs zugeordnet.</p>';
        return;
    }

    const status = {}; // kurs_schueler_id → { status, kommentar }
    for (const e of eintraege) {
        status[e.kurs_schueler_id] = {
            status:       e.status,
            kommentar:    e.kommentar ?? '',
            entschuldigt: !!e.entschuldigt,
        };
    }

    const zeilen = eintraege.map(e => {
        const name = e.nachname
            ? `${escHtml(e.nachname)}, ${escHtml(e.vorname ?? '')}`
            : escHtml((e.name_roh ?? '').replace('|', ', '));

        return `
        <tr class="aw-zeile" data-id="${e.kurs_schueler_id}" data-aid="${e.anwesenheit_id ?? ''}">
            <td>${name}</td>
            <td class="td-status">
                <div class="status-gruppe">
                    <button type="button" class="btn-status ${e.status === 'anwesend' ? 'aktiv status-anwesend' : ''}"
                            data-status="anwesend">Anwesend</button>
                    <button type="button" class="btn-status ${e.status === 'fehlend' ? 'aktiv status-fehlend' : ''}"
                            data-status="fehlend">Fehlend</button>
                    <button type="button" class="btn-status ${e.status === 'ausstehend' ? 'aktiv status-ausstehend' : ''}"
                            data-status="ausstehend">?</button>
                </div>
            </td>
            <td>
                <input type="text" class="aw-kommentar" placeholder="Kommentar"
                       value="${escHtml(e.kommentar ?? '')}"
                       style="width:100%;max-width:200px;padding:.25rem .4rem;border:1px solid #ccc;border-radius:3px;font-size:.875rem">
            </td>
            ${hatRolle('admin', 'stufenleitung') ? `
            <td>
                <select class="aw-entschuldigt" ${e.status !== 'fehlend' ? 'disabled' : ''}>
                    <option value=""  ${e.entschuldigt === null  ? 'selected' : ''}>Offen</option>
                    <option value="1" ${e.entschuldigt == 1      ? 'selected' : ''}>Entschuldigt</option>
                    <option value="0" ${e.entschuldigt == 0 && e.entschuldigt !== null ? 'selected' : ''}>Unentschuldigt</option>
                </select>
            </td>` : ''}
        </tr>`;
    }).join('');

    overlay.querySelector('#aw-inhalt').innerHTML = `
        <div style="margin-bottom:.5rem">
            <button class="btn btn-klein btn-sekundaer" id="aw-alle-anwesend">Alle anwesend</button>
        </div>
        <div class="tabelle-wrapper aw-tabelle-wrapper">
            <table class="aw-tabelle">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Kommentar</th>
                        ${hatRolle('admin', 'stufenleitung') ? '<th>Entschuldigt</th>' : ''}
                    </tr>
                </thead>
                <tbody>${zeilen}</tbody>
            </table>
        </div>
    `;

    overlay.querySelector('#aw-speichern').disabled = false;

    // Alle anwesend
    overlay.querySelector('#aw-alle-anwesend').addEventListener('click', () => {
        overlay.querySelectorAll('.aw-zeile').forEach(zeile => {
            setzeStatus(zeile, 'anwesend', status);
        });
    });

    // Status-Toggle
    overlay.querySelectorAll('.btn-status').forEach(btn => {
        btn.addEventListener('click', () => {
            const zeile = btn.closest('.aw-zeile');
            setzeStatus(zeile, btn.dataset.status, status);
        });
    });

    // Kommentar-Änderung tracken
    overlay.querySelectorAll('.aw-kommentar').forEach(input => {
        input.addEventListener('input', () => {
            const id = parseInt(input.closest('.aw-zeile').dataset.id);
            status[id].kommentar = input.value;
        });
    });

    // Entschuldigungsstatus tracken (nur admin/SL)
    if (hatRolle('admin', 'stufenleitung')) {
        overlay.querySelectorAll('.aw-entschuldigt').forEach(sel => {
            sel.addEventListener('change', () => {
                const id = parseInt(sel.closest('.aw-zeile').dataset.id);
                status[id].entschuldigt = sel.value === '' ? null
                    : sel.value === '1' ? true : false;
            });
        });
    }

    // Speichern
    overlay.querySelector('#aw-speichern').addEventListener('click', async () => {
        const btn     = overlay.querySelector('#aw-speichern');
        const fehlerEl = overlay.querySelector('#aw-fehler');
        const okEl    = overlay.querySelector('#aw-ok');
        btn.disabled  = true;
        fehlerEl.style.display = 'none';
        okEl.style.display     = 'none';

        const eintraegePut = Object.entries(status).map(([id, s]) => ({
            kurs_schueler_id: parseInt(id),
            status:           s.status,
            kommentar:        s.kommentar || '',
            ...(hatRolle('admin', 'stufenleitung') ? { entschuldigt: s.entschuldigt } : {}),
        }));

        try {
            const res = await apiFetch(`/anwesenheit/${klausur.id}`, {
                method:  'POST',
                body:    JSON.stringify(eintraegePut),
            });
            okEl.textContent  = `✓ ${res.gespeichert} Eintrag/Einträge gespeichert.`;
            okEl.style.display = '';
        } catch (err) {
            fehlerEl.textContent  = err.message;
            fehlerEl.style.display = '';
        } finally {
            btn.disabled = false;
        }
    });
}

function setzeStatus(zeile, neuerStatus, statusObj) {
    const id = parseInt(zeile.dataset.id);
    statusObj[id].status = neuerStatus;
    zeile.querySelectorAll('.btn-status').forEach(b => {
        b.classList.remove('aktiv', 'status-anwesend', 'status-fehlend', 'status-ausstehend');
        if (b.dataset.status === neuerStatus) {
            b.classList.add('aktiv', `status-${neuerStatus}`);
        }
    });

    // Entschuldigungs-Select nur bei 'fehlend' aktiv
    const entschSel = zeile.querySelector('.aw-entschuldigt');
    if (entschSel) {
        entschSel.disabled = neuerStatus !== 'fehlend';
        if (neuerStatus !== 'fehlend') {
            entschSel.value = '';
            statusObj[id].entschuldigt = null;
        }
    }
}

// ---------------------------------------------------------------------------
// View: Nachschreibtermine
// ---------------------------------------------------------------------------

async function viewNachschreibtermine(el) {
    if (!hatRolle('admin', 'stufenleitung', 'lehrkraft')) {
        el.innerHTML = '<p class="fehler">Kein Zugriff.</p>';
        return;
    }

    el.innerHTML = `
        <h2>Nachschreibtermine</h2>
        ${hatRolle('admin', 'stufenleitung') ? `
        <div style="margin-bottom:1rem">
            <button class="btn" id="nt-neu-btn">+ Neuer Nachschreibtermin</button>
        </div>
        <div id="nt-neu-formular" class="versteckt"></div>` : ''}
        <div id="nt-liste"><p class="lade-text">Wird geladen…</p></div>
    `;

    ladeNachschreibtermine(el.querySelector('#nt-liste'));

    if (hatRolle('admin', 'stufenleitung')) {
        el.querySelector('#nt-neu-btn').addEventListener('click', () => {
            const formEl = el.querySelector('#nt-neu-formular');
            formEl.classList.toggle('versteckt');
            if (!formEl.classList.contains('versteckt') && formEl.children.length === 0) {
                renderNachschreibterminFormular(formEl, null, () => {
                    formEl.classList.add('versteckt');
                    formEl.innerHTML = '';
                    ladeNachschreibtermine(el.querySelector('#nt-liste'));
                });
            }
        });
    }
}

async function ladeNachschreibtermine(el) {
    el.innerHTML = '<p class="lade-text">Wird geladen…</p>';
    try {
        const termine = await apiFetch('/nachschreibtermine');
        renderNachschreibtermineList(el, termine);
    } catch (err) {
        el.innerHTML = `<p class="fehler">${err.message}</p>`;
    }
}

function renderNachschreibtermineList(el, termine) {
    if (termine.length === 0) {
        el.innerHTML = '<div class="karte"><p>Noch keine Nachschreibtermine angelegt.</p></div>';
        return;
    }

    el.innerHTML = termine.map(t => {
        const datum   = t.termin_datum   ? formatDatum(t.termin_datum)        : '–';
        const uhrzeit = t.termin_uhrzeit ? t.termin_uhrzeit.substring(0, 5)   : '–';

        const klausurenHtml = t.klausuren.length > 0
            ? t.klausuren.map(k => renderNachschreibKlausurBlock(k)).join('')
            : '<span class="hinweis">Keine Klausuren verknüpft</span>';

        return `
        <div class="karte nt-karte" data-id="${t.id}">
            <div class="nt-kopf">
                <span class="nt-datum">${datum}, ${uhrzeit} Uhr${t.raum ? ' – ' + escHtml(t.raum) : ''}</span>
                ${hatRolle('admin', 'stufenleitung') ? `
                <div class="nt-aktionen">
                    <button class="btn btn-klein btn-sekundaer btn-nt-bearbeiten" data-id="${t.id}">Bearbeiten</button>
                    <button class="btn btn-klein btn-sekundaer btn-nt-klausuren" data-id="${t.id}">Klausuren verknüpfen</button>
                </div>` : ''}
            </div>
            ${t.bemerkung ? `<p class="nt-bemerkung">${escHtml(t.bemerkung)}</p>` : ''}
            <div class="nt-klausuren">${klausurenHtml}</div>
            <div class="nt-edit-bereich versteckt"></div>
        </div>`;
    }).join('');

    // Bearbeiten
    el.querySelectorAll('.btn-nt-bearbeiten').forEach(btn => {
        btn.addEventListener('click', () => {
            const id    = parseInt(btn.dataset.id);
            const karte = el.querySelector(`.nt-karte[data-id="${id}"]`);
            const bereich = karte.querySelector('.nt-edit-bereich');
            bereich.classList.toggle('versteckt');
            if (!bereich.classList.contains('versteckt') && bereich.children.length === 0) {
                const t = termine.find(x => x.id === id);
                renderNachschreibterminFormular(bereich, t, () => {
                    bereich.classList.add('versteckt');
                    bereich.innerHTML = '';
                    ladeNachschreibtermine(el);
                });
            }
        });
    });

    // Klausuren verknüpfen
    el.querySelectorAll('.btn-nt-klausuren').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id    = parseInt(btn.dataset.id);
            const karte = el.querySelector(`.nt-karte[data-id="${id}"]`);
            const bereich = karte.querySelector('.nt-edit-bereich');
            bereich.classList.toggle('versteckt');
            if (!bereich.classList.contains('versteckt') && bereich.children.length === 0) {
                bereich.innerHTML = '<p class="lade-text">Klausuren werden geladen…</p>';
                try {
                    const alleKlausuren = await apiFetch('/klausuren?nachschreiber=1');
                    const t = termine.find(x => x.id === id);
                    renderKlausurVerknuepfung(bereich, id, alleKlausuren, t.klausuren, () => {
                        bereich.classList.add('versteckt');
                        bereich.innerHTML = '';
                        ladeNachschreibtermine(el);
                    });
                } catch (err) {
                    bereich.innerHTML = `<p class="fehler">${err.message}</p>`;
                }
            }
        });
    });
}

/** Gibt ein Badge für den Entschuldigungsstatus zurück. */
function entschuldigungsBadge(entschuldigt) {
    if (entschuldigt == 1)     return ' <span class="vk-entsch">entsch.</span>';
    if (entschuldigt === null) return ' <span class="vk-offen">offen</span>';
    return '';
}

function renderNachschreibKlausurBlock(k) {
    const ns = k.nachschreiber ?? [];
    let nsHtml = '';
    if (ns.length > 0) {
        const items = ns.map(s => {
            const name = s.nachname
                ? `${escHtml(s.nachname)}, ${escHtml(s.vorname ?? '')}`
                : escHtml((s.name_roh ?? '').replace('|', ', '));
            return `<li>${name}${entschuldigungsBadge(s.entschuldigt)}</li>`;
        }).join('');
        nsHtml = `<ul class="nt-ns-liste">${items}</ul>`;
    }
    return `<div class="nt-klausur-block"><span class="klausur-tag">${escHtml(k.kurs_anzeigename)}</span>${nsHtml}</div>`;
}

function renderNachschreibterminFormular(el, vorhandener, nachSpeichern) {
    const istNeu = vorhandener === null;
    el.innerHTML = `
        <div class="nt-formular">
            <div class="formular-gruppe">
                <label>Datum</label>
                <input type="date" class="nt-datum" value="${vorhandener?.termin_datum ?? ''}">
            </div>
            <div class="formular-gruppe">
                <label>Uhrzeit</label>
                <input type="time" class="nt-uhrzeit" value="${vorhandener?.termin_uhrzeit ? vorhandener.termin_uhrzeit.substring(0,5) : ''}">
            </div>
            <div class="formular-gruppe">
                <label>Raum</label>
                <input type="text" class="nt-raum" value="${escHtml(vorhandener?.raum ?? '')}">
            </div>
            <div class="formular-gruppe">
                <label>Bemerkung</label>
                <input type="text" class="nt-bemerkung" value="${escHtml(vorhandener?.bemerkung ?? '')}">
            </div>
            <div class="formular-zeile">
                <button class="btn nt-speichern">${istNeu ? 'Anlegen' : 'Speichern'}</button>
                <button class="btn btn-sekundaer nt-abbrechen">Abbrechen</button>
            </div>
            <p class="nt-fehler fehler" style="display:none"></p>
        </div>
    `;

    el.querySelector('.nt-abbrechen').addEventListener('click', () => nachSpeichern());

    el.querySelector('.nt-speichern').addEventListener('click', async () => {
        const btn     = el.querySelector('.nt-speichern');
        const fehlerEl = el.querySelector('.nt-fehler');
        btn.disabled = true;
        fehlerEl.style.display = 'none';

        const body = {
            termin_datum:    el.querySelector('.nt-datum').value    || null,
            termin_uhrzeit:  el.querySelector('.nt-uhrzeit').value  || null,
            raum:            el.querySelector('.nt-raum').value     || null,
            bemerkung:       el.querySelector('.nt-bemerkung').value || null,
        };

        try {
            if (istNeu) {
                await apiFetch('/nachschreibtermine', { method: 'POST', body: JSON.stringify(body) });
            } else {
                await apiFetch(`/nachschreibtermine/${vorhandener.id}`, { method: 'PUT', body: JSON.stringify(body) });
            }
            nachSpeichern();
        } catch (err) {
            fehlerEl.textContent = err.message;
            fehlerEl.style.display = '';
            btn.disabled = false;
        }
    });
}

function renderKlausurVerknuepfung(el, ntId, alleKlausuren, bereitsVerknuepft, nachSpeichern) {
    const bereitsIds  = new Set(bereitsVerknuepft.map(k => k.id));
    const ausgewaehlt = new Set(bereitsIds);

    // Eindeutiger Schlüssel pro Person (bevorzugt benutzer_id, sonst name_roh)
    function nsKey(ns) {
        return ns.benutzer_id ? `b_${ns.benutzer_id}` : `r_${ns.name_roh}`;
    }

    // Berechnet Konflikte: Schlüssel aller Nachschreiber*innen, die in >1 gewählter Klausur fehlten
    function berechneKonflikte() {
        const schuelerKlausuren = {}; // key → Set of klausur_ids
        for (const k of alleKlausuren) {
            if (!ausgewaehlt.has(k.id)) continue;
            for (const ns of (k.nachschreiber ?? [])) {
                const key = nsKey(ns);
                (schuelerKlausuren[key] ??= new Set()).add(k.id);
            }
        }
        return new Set(
            Object.entries(schuelerKlausuren)
                .filter(([, ids]) => ids.size > 1)
                .map(([key]) => key)
        );
    }

    function schuelerAnzeigename(ns) {
        const name = ns.nachname
            ? `${escHtml(ns.nachname)}, ${escHtml(ns.vorname ?? '')}`
            : escHtml((ns.name_roh ?? '').replace('|', ', '));
        return name + entschuldigungsBadge(ns.entschuldigt);
    }

    // Zusammenfassung und Konflikt-Hervorhebungen aktualisieren
    function aktualisiereAnzeige() {
        const konflikte = berechneKonflikte();

        // Gesamtzahl eindeutiger Nachschreiber*innen
        const alleKeys = new Set();
        for (const k of alleKlausuren) {
            if (!ausgewaehlt.has(k.id)) continue;
            for (const ns of (k.nachschreiber ?? [])) alleKeys.add(nsKey(ns));
        }

        const summaryEl = el.querySelector('.vk-summary');
        if (alleKeys.size === 0) {
            summaryEl.innerHTML = '<span class="hinweis">Keine Nachschreiber*innen ausgewählt.</span>';
        } else if (konflikte.size === 0) {
            summaryEl.innerHTML =
                `<span class="ok-text">${alleKeys.size} Nachschreiber*in(nen) ausgewählt</span>`;
        } else {
            summaryEl.innerHTML =
                `<span class="warn-text">${alleKeys.size} Nachschreiber*in(nen) ausgewählt – `
                + `${konflikte.size} Konflikt(e): dieselbe Person in mehreren Klausuren</span>`;
        }

        // Namens-Spans ein-/ausblenden Konfliktfarbe
        el.querySelectorAll('.vk-schueler').forEach(span => {
            span.classList.toggle('vk-konflikt', konflikte.has(span.dataset.key));
        });

        el.dataset.hatKonflikte = konflikte.size > 0 ? '1' : '0';
    }

    // HTML bauen
    const zeilen = alleKlausuren.map(k => {
        const nachschreiber = k.nachschreiber ?? [];
        const datumStr = k.termin_datum ? formatDatum(k.termin_datum) : '';
        const dauerStr = k.dauer_minuten ? `${k.dauer_minuten} min` : '';
        const meta = [datumStr, dauerStr].filter(Boolean).join(', ');
        const nsSpans = nachschreiber.map(ns => {
            const key = escHtml(nsKey(ns));
            return `<span class="vk-schueler" data-key="${key}">${schuelerAnzeigename(ns)}</span>`;
        });
        const nsHtml = nsSpans.length > 0
            ? nsSpans.join('<span class="vk-trenner"> · </span>')
            : '<span class="vk-keine-ns">–</span>';

        return `
        <label class="check-zeile vk-zeile">
            <input type="checkbox" value="${k.id}" ${bereitsIds.has(k.id) ? 'checked' : ''}>
            <span class="vk-kursblock">
                <span class="vk-kursname">${escHtml(k.kurs_anzeigename)}${k.klausur_nr > 1 ? ` <span class="klausur-nr">(Nr. ${k.klausur_nr})</span>` : ''}${meta ? ` <span class="hinweis">– ${meta}</span>` : ''}</span>
                <span class="vk-nachschreiber">${nsHtml}</span>
            </span>
        </label>`;
    }).join('');

    el.innerHTML = `
        <div class="nt-formular">
            <p class="vk-summary"></p>
            <p><strong>Klausuren für diesen Nachschreibtermin:</strong></p>
            <div class="check-liste vk-liste">${zeilen || '<p class="hinweis">Keine Klausuren vorhanden.</p>'}</div>
            <div class="formular-zeile" style="margin-top:.75rem">
                <button class="btn nt-kl-speichern">Speichern</button>
                <button class="btn btn-sekundaer nt-kl-abbrechen">Abbrechen</button>
            </div>
            <p class="nt-fehler fehler" style="display:none"></p>
        </div>
    `;

    aktualisiereAnzeige();

    el.querySelectorAll('.vk-liste input[type="checkbox"]').forEach(cb => {
        cb.addEventListener('change', () => {
            const id = parseInt(cb.value);
            cb.checked ? ausgewaehlt.add(id) : ausgewaehlt.delete(id);
            aktualisiereAnzeige();
        });
    });

    el.querySelector('.nt-kl-abbrechen').addEventListener('click', () => nachSpeichern());

    el.querySelector('.nt-kl-speichern').addEventListener('click', async () => {
        const btn     = el.querySelector('.nt-kl-speichern');
        const fehlerEl = el.querySelector('.nt-fehler');

        if (el.dataset.hatKonflikte === '1') {
            if (!confirm(
                'Achtung: Es gibt Konflikte – dieselbe Nachschreiberin bzw. derselbe Nachschreiber '
                + 'erscheint in mehreren ausgewählten Klausuren.\n\nTrotzdem speichern?'
            )) return;
        }

        btn.disabled = true;
        fehlerEl.style.display = 'none';

        try {
            await apiFetch(`/nachschreibtermine/${ntId}/klausuren`, {
                method: 'POST',
                body: JSON.stringify({ klausur_ids: [...ausgewaehlt] }),
            });
            nachSpeichern();
        } catch (err) {
            fehlerEl.textContent = err.message;
            fehlerEl.style.display = '';
            btn.disabled = false;
        }
    });
}

// ---------------------------------------------------------------------------
// Hilfs­funktionen
// ---------------------------------------------------------------------------

/**
 * Zeigt den Bearbeitungsstand der Anwesenheit als farbige Fraktion.
 * erfasst / gesamt: "–" | "0/5" grau | "3/5" orange | "5/5" grün
 */
function renderAnwesenheitStatus(erfasst, gesamt) {
    const g = parseInt(gesamt) || 0;
    if (g === 0) return '–';
    const e = parseInt(erfasst) || 0;
    if (e === 0)    return `<span class="aw-status-offen">${e}/${g}</span>`;
    if (e < g)      return `<span class="aw-status-teil">${e}/${g}</span>`;
    return              `<span class="aw-status-voll">${e}/${g}</span>`;
}

/** YYYY-MM-DD → TT.MM.JJJJ */
function formatDatum(str) {
    if (!str) return '–';
    const [y, m, d] = str.split('-');
    return `${d}.${m}.${y}`;
}

function escHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ---------------------------------------------------------------------------
// Start
// ---------------------------------------------------------------------------

render();
