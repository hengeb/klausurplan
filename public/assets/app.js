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
    start:       viewStart,
    import:      viewImport,
    zuordnungen: viewZuordnungen,
    halbjahre:   viewHalbjahre,
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
        ${hatRolle('admin', 'stufenleitung') ? `
        <div class="kacheln">
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
            </a>
        </div>` : ''}
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

function renderZuordnungenView(el, daten) {
    const {
        schueler_gomst: sGomst,
        schueler_moodle: sMoodle,
        lehrkraefte_kurse: lKurse,
        lehrkraefte_moodle: lMoodle,
    } = daten;

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

    // Moodle-Nutzer*innen als Optionsliste
    const moodleOptionen = sMoodle.map(m =>
        `<option value="${m.id}">${m.nachname}, ${m.vorname}${m.stufe ? ` (${m.stufe})` : ''}</option>`
    ).join('');

    const zeilen = sGomst.map(ks => {
        const [nachname, vorname] = ks.name_roh.split('|');
        const nameRohAttr = escHtml(ks.name_roh);
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
// Hilfs­funktionen
// ---------------------------------------------------------------------------

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
