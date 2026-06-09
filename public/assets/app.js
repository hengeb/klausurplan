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

/** Macht ein Overlay-Element durch Backdrop-Klick und Escape schließbar. */
function schliessbar(overlay) {
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    const onEsc = e => {
        if (e.key === 'Escape' && overlay.isConnected) {
            overlay.remove();
            document.removeEventListener('keydown', onEsc);
        }
    };
    document.addEventListener('keydown', onEsc);
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
    admin:               viewAdmin,
    schueler:            viewSchueler,
};

window.addEventListener('hashchange', render);

function render() {
    const app = document.getElementById('app');

    // Reine Schüler*innen sehen immer direkt die Klausurliste
    if (hatRolle('schueler') && !hatRolle('admin', 'stufenleitung', 'lehrkraft')) {
        app.innerHTML = '<p class="lade-text">Wird geladen…</p>';
        viewSchueler(app).catch(err => {
            app.innerHTML = `<p class="fehler">Fehler: ${err.message}</p>`;
        });
        return;
    }

    const hash = location.hash.replace('#', '') || 'start';
    const view = VIEWS[hash] ?? viewStart;

    renderNav();
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

    if (hatRolle('schueler') && !hatRolle('admin', 'stufenleitung', 'lehrkraft')) {
        links.push({ hash: 'schueler', label: 'Meine Klausuren' });
    }

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

    if (hatRolle('admin')) {
        links.push({ hash: 'admin', label: 'Administration' });
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
            ${hatRolle('schueler') && !hatRolle('admin', 'stufenleitung', 'lehrkraft') ? `
            <a href="#schueler" class="kachel">
                <span class="kachel-icon">📅</span>
                <span>Meine Klausuren</span>
            </a>` : ''}
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
            ${hatRolle('admin') ? `
            <a href="#admin" class="kachel">
                <span class="kachel-icon">⚙️</span>
                <span>Administration</span>
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
        const nameAnzeige = parseZeigeNameRoh(ks.name_roh);
        const nameRohAttr = escHtml(ks.name_roh);

        // Nur Moodle-Konten mit passender Stufe anzeigen
        const passende = sMoodle.filter(m => matchesStufe(m.stufe, ks.stufen));
        const moodleOptionen = passende.map(m =>
            `<option value="${m.id}">${m.nachname}, ${m.vorname}${m.stufe ? ` (${m.stufe})` : ''}</option>`
        ).join('');

        return `
        <tr>
            <td>${nameAnzeige}</td>
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

    const moodleOptionen = lMoodle.map(l => {
        // Moodle speichert z.B. "Gebauer (GB)" als Nachname; Kürzel daher aus Nachname entfernen
        const nachname = l.kuerzel
            ? l.nachname.replace(/\s*\([^)]+\)$/, '')
            : l.nachname;
        const anzeige = l.kuerzel
            ? `${nachname}, ${l.vorname} (${l.kuerzel})`
            : `${nachname}, ${l.vorname}`;
        return `<option value="${l.id}">${anzeige}</option>`;
    }).join('');

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
                        <div class="hj-block" data-hj-id="${hj.id}">
                            <div class="hj-kopf">
                                <h4 style="margin:0">${escHtml(hj.stufe)} – ${hj.abschnitt}. Halbjahr
                                    <span class="hj-meta">${hj.kurs_anzahl} Kurs(e)</span>
                                </h4>
                                <div style="display:flex;gap:.5rem;align-items:center">
                                    <button class="btn btn-klein btn-kurs-laden" data-hj-id="${hj.id}">
                                        Kurse anzeigen
                                    </button>
                                    <button class="btn-icon btn-icon-gefahr btn-hj-loeschen"
                                            data-hj-id="${hj.id}"
                                            data-hj-label="${escHtml(hj.stufe + ' – ' + hj.abschnitt + '. HJ ' + hj.schuljahr)}"
                                            title="Löschen">🗑️</button>
                                </div>
                            </div>
                            <div id="kurse-${hj.id}" class="kurs-liste versteckt"></div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `).join('');

    const aktionenLeiste = hatRolle('admin', 'stufenleitung') ? `
        <div style="margin-top:1.5rem;display:flex;gap:.5rem;flex-wrap:wrap">
            <button class="btn btn-sekundaer" id="btn-hj-anlegen">+ Halbjahr anlegen</button>
            <button class="btn btn-sekundaer" id="btn-kurs-global-hinzufuegen">+ Kurs hinzufügen</button>
        </div>` : '';
    el.innerHTML = `<h2>Halbjahre & Kurse</h2><div class="accordion">${accordion}</div>${aktionenLeiste}`;

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
            const hjId = parseInt(btn.dataset.hjId);
            const ziel = el.querySelector(`#kurse-${hjId}`);
            btn.disabled = true;
            btn.textContent = 'Wird geladen…';
            try {
                const kurse = await apiFetch(`/stufenleitung/halbjahre/${hjId}/kurse`);
                ziel.innerHTML = renderKursTabelle(kurse);
                ziel.classList.remove('versteckt');
                btn.remove();

                // Event delegation: Teilnehmende + Kurs löschen
                ziel.addEventListener('click', async ev => {
                    const tnBtn = ev.target.closest('.btn-kurs-teilnehmer');
                    if (tnBtn) {
                        zeigeKursTeilnehmerDialog(parseInt(tnBtn.dataset.kursId), tnBtn.dataset.kursName);
                        return;
                    }

                    const loeschBtn = ev.target.closest('.btn-kurs-loeschen');
                    if (loeschBtn) {
                        const kursId   = parseInt(loeschBtn.dataset.kursId);
                        const kursName = loeschBtn.dataset.kursName;
                        if (!confirm(`Kurs „${kursName}" wirklich löschen?\n\nDabei werden alle Klausuren und Anwesenheitsdaten dieses Kurses unwiderruflich gelöscht.`)) return;
                        loeschBtn.disabled = true;
                        try {
                            await apiFetch(`/stufenleitung/kurse/${kursId}`, { method: 'DELETE' });
                            ziel.querySelector(`tr[data-kurs-id="${kursId}"]`)?.remove();
                            const tbody = ziel.querySelector('tbody');
                            if (tbody && tbody.querySelectorAll('tr[data-kurs-id]').length === 0) {
                                const cols = hatRolle('admin', 'stufenleitung') ? 5 : 4;
                                tbody.innerHTML = `<tr><td colspan="${cols}" class="hinweis" style="padding:.5rem">Noch keine Kurse.</td></tr>`;
                            }
                        } catch (err) {
                            alert(`Fehler: ${err.message}`);
                            loeschBtn.disabled = false;
                        }
                    }
                });
            } catch (err) {
                btn.disabled = false;
                btn.textContent = 'Kurse anzeigen';
                ziel.innerHTML = `<p class="fehler">${err.message}</p>`;
                ziel.classList.remove('versteckt');
            }
        });
    });

    // Halbjahr anlegen
    el.querySelector('#btn-hj-anlegen')?.addEventListener('click', () => {
        zeigeHalbjahrAnlegenDialog(el);
    });

    // Kurs global hinzufügen (lädt halbjahre frisch beim Öffnen)
    el.querySelector('#btn-kurs-global-hinzufuegen')?.addEventListener('click', () => {
        zeigeKursHinzufuegenDialog(el);
    });

    // Halbjahr löschen
    el.querySelectorAll('.btn-hj-loeschen').forEach(btn => {
        btn.addEventListener('click', async () => {
            const hjId    = btn.dataset.hjId;
            const label   = btn.dataset.hjLabel;
            if (!confirm(`Halbjahr „${label}" wirklich löschen?\n\nDabei werden alle Kurse, Klausuren und Anwesenheitsdaten dieses Halbjahrs unwiderruflich gelöscht.`)) return;

            btn.disabled = true;
            try {
                await apiFetch(`/stufenleitung/daten/${hjId}`, { method: 'DELETE' });
                btn.closest('.hj-block').remove();
            } catch (err) {
                alert(`Fehler: ${err.message}`);
                btn.disabled = false;
            }
        });
    });
}

async function zeigeHalbjahrAnlegenDialog(el) {
    const overlay = document.createElement('div');
    overlay.className = 'dialog-overlay';
    overlay.innerHTML = `
        <div class="dialog">
            <h3>Halbjahr anlegen</h3>
            <div class="formular-gruppe">
                <label>Stufe <span style="font-weight:normal;font-size:.85em">(kommagetrennt für mehrere)</span></label>
                <input type="text" id="dlg-hj-stufe" placeholder="z.B. EF, Q1, Q2"
                       maxlength="100" autocomplete="off">
            </div>
            <div class="formular-gruppe">
                <label>Schuljahr</label>
                <input type="text" id="dlg-hj-schuljahr" placeholder="z.B. 2024/2025" maxlength="9">
            </div>
            <div class="formular-gruppe">
                <label>Halbjahr</label>
                <select id="dlg-hj-abschnitt">
                    <option value="1">1. Halbjahr</option>
                    <option value="2">2. Halbjahr</option>
                </select>
            </div>
            <div class="dialog-aktionen">
                <button class="btn" id="dlg-hj-speichern">Anlegen</button>
                <button class="btn btn-sekundaer" id="dlg-hj-abbrechen">Abbrechen</button>
            </div>
            <p id="dlg-hj-fehler" class="fehler" style="display:none"></p>
        </div>`;
    document.body.appendChild(overlay);

    overlay.querySelector('#dlg-hj-abbrechen').addEventListener('click', () => overlay.remove());
    schliessbar(overlay);

    // Vorschlag laden und Felder vorausfüllen
    try {
        const vorschlag = await apiFetch('/stufenleitung/halbjahr-vorschlag');
        overlay.querySelector('#dlg-hj-schuljahr').value = vorschlag.schuljahr ?? '';
        overlay.querySelector('#dlg-hj-abschnitt').value = String(vorschlag.abschnitt ?? 1);
        if (vorschlag.fehlende_stufen && vorschlag.fehlende_stufen.length > 0) {
            overlay.querySelector('#dlg-hj-stufe').value = vorschlag.fehlende_stufen.join(', ');
        }
    } catch {
        // Vorschlag optional – kein Fehler anzeigen
    }
    overlay.querySelector('#dlg-hj-stufe').focus();

    overlay.querySelector('#dlg-hj-speichern').addEventListener('click', async () => {
        const btn       = overlay.querySelector('#dlg-hj-speichern');
        const fehlerEl  = overlay.querySelector('#dlg-hj-fehler');
        const stufe     = overlay.querySelector('#dlg-hj-stufe').value.trim();
        const schuljahr = overlay.querySelector('#dlg-hj-schuljahr').value.trim();
        const abschnitt = parseInt(overlay.querySelector('#dlg-hj-abschnitt').value);

        if (!stufe) {
            fehlerEl.textContent = 'Bitte eine Stufe eingeben.';
            fehlerEl.style.display = '';
            return;
        }
        if (!schuljahr) {
            fehlerEl.textContent = 'Bitte ein Schuljahr eingeben.';
            fehlerEl.style.display = '';
            return;
        }

        btn.disabled = true;
        fehlerEl.style.display = 'none';
        try {
            const res = await apiFetch('/stufenleitung/halbjahre', {
                method: 'POST',
                body: JSON.stringify({ stufe_name: stufe, schuljahr, abschnitt }),
            });

            // Bei Mehrfach-Anlage: Fehler anzeigen, aber trotzdem neu laden
            if (res.fehler && res.fehler.length > 0) {
                const fehlermeldungen = res.fehler.map(f => `${f.stufe}: ${f.meldung}`).join('\n');
                const erstelltAnzahl = res.erstellt?.length ?? 0;
                const meldung = erstelltAnzahl > 0
                    ? `${erstelltAnzahl} Halbjahr(e) angelegt.\n\nFehler:\n${fehlermeldungen}`
                    : `Fehler:\n${fehlermeldungen}`;
                alert(meldung);
            }

            if (!res.fehler || res.erstellt?.length > 0) {
                overlay.remove();
                viewHalbjahre(el);
            } else {
                btn.disabled = false;
            }
        } catch (err) {
            fehlerEl.textContent = err.message;
            fehlerEl.style.display = '';
            btn.disabled = false;
        }
    });
}

async function zeigeKursHinzufuegenDialog(viewEl) {
    let halbjahre = [], lehrkraefte = [];
    try {
        [halbjahre, lehrkraefte] = await Promise.all([
            apiFetch('/stufenleitung/halbjahre'),
            apiFetch('/stufenleitung/lehrkraefte'),
        ]);
    } catch {}

    const hjOptionen = halbjahre.map(hj =>
        `<option value="${hj.id}">${escHtml(hj.stufe)} – ${hj.abschnitt}. HJ ${escHtml(hj.schuljahr)}</option>`
    ).join('');
    const lkOptionen = `<option value="">– keine –</option>` + lehrkraefte.map(lk => {
        const nachname = lk.nachname.replace(/\s*\([^)]+\)$/, '');
        const kuerzel  = lk.kuerzel ? ` (${escHtml(lk.kuerzel)})` : '';
        return `<option value="${lk.id}">${escHtml(nachname)}, ${escHtml(lk.vorname)}${kuerzel}</option>`;
    }).join('');

    const overlay = document.createElement('div');
    overlay.className = 'dialog-overlay';
    overlay.innerHTML = `
        <div class="dialog">
            <h3>Kurs hinzufügen</h3>
            <div class="formular-gruppe">
                <label>Stufe</label>
                <select id="dlg-hj-id">${hjOptionen}</select>
            </div>
            <div class="formular-gruppe">
                <label>Kursbezeichnung</label>
                <input type="text" id="dlg-kurs-bezeichnung" placeholder="z.B. SP_Q2_GK1_SZ" maxlength="50">
            </div>
            <div class="formular-gruppe">
                <label>Kursart</label>
                <select id="dlg-kursart">
                    <option value="GK">GK</option>
                    <option value="LK">LK</option>
                </select>
            </div>
            <div class="formular-gruppe">
                <label>Lehrkraft</label>
                <select id="dlg-lk-id">${lkOptionen}</select>
            </div>
            <div class="dialog-aktionen">
                <button class="btn" id="dlg-kurs-speichern">Hinzufügen</button>
                <button class="btn btn-sekundaer" id="dlg-kurs-abbrechen">Abbrechen</button>
            </div>
            <p id="dlg-kurs-fehler" class="fehler" style="display:none"></p>
        </div>`;
    document.body.appendChild(overlay);

    overlay.querySelector('#dlg-kurs-abbrechen').addEventListener('click', () => overlay.remove());
    schliessbar(overlay);

    overlay.querySelector('#dlg-kurs-speichern').addEventListener('click', async () => {
        const btn       = overlay.querySelector('#dlg-kurs-speichern');
        const fehlerEl  = overlay.querySelector('#dlg-kurs-fehler');
        const hjId      = parseInt(overlay.querySelector('#dlg-hj-id').value);
        const bezeichnung = overlay.querySelector('#dlg-kurs-bezeichnung').value.trim();
        const kursart   = overlay.querySelector('#dlg-kursart').value;
        const lehrerId  = parseInt(overlay.querySelector('#dlg-lk-id').value) || null;

        if (!bezeichnung) {
            fehlerEl.textContent = 'Bitte eine Kursbezeichnung eingeben.';
            fehlerEl.style.display = '';
            return;
        }

        btn.disabled = true;
        fehlerEl.style.display = 'none';
        try {
            const neuerKurs = await apiFetch(`/stufenleitung/halbjahre/${hjId}/kurse`, {
                method: 'POST',
                body: JSON.stringify({ bezeichnung, kursart, lehrer_id: lehrerId }),
            });

            // Kurs in sichtbare Kursliste einfügen, falls bereits geladen
            const ziel = viewEl.querySelector(`#kurse-${hjId}`);
            if (ziel && !ziel.classList.contains('versteckt')) {
                const tbody = ziel.querySelector('tbody');
                if (tbody) {
                    tbody.querySelector('tr td[colspan]')?.closest('tr')?.remove();
                    tbody.insertAdjacentHTML('beforeend', renderKursZeile(neuerKurs));
                }
            }

            // Kurs-Anzahl im Halbjahr-Kopf aktualisieren
            const hjBlock = viewEl.querySelector(`.hj-block[data-hj-id="${hjId}"]`);
            const meta = hjBlock?.querySelector('.hj-meta');
            if (meta) {
                const n = (parseInt(meta.textContent) || 0) + 1;
                meta.textContent = `${n} Kurs(e)`;
            }

            overlay.remove();
        } catch (err) {
            fehlerEl.textContent = err.message;
            fehlerEl.style.display = '';
            btn.disabled = false;
        }
    });
}

function renderKursZeile(k) {
    const lehrkraft = k.lehrer_id
        ? `${escHtml(k.lehrer_nachname)}, ${escHtml(k.lehrer_vorname)}`
        : k.lehrer_kuerzel
        ? `<span class="fehlend">${escHtml(k.lehrer_kuerzel)} (nicht zugeordnet)</span>`
        : '<span class="fehlend">–</span>';

    const zugeordnetProzent = k.schueler_gesamt > 0
        ? Math.round((k.schueler_zugeordnet / k.schueler_gesamt) * 100) : 0;
    const ampel = k.schueler_gesamt === 0 ? 'grau'
        : zugeordnetProzent === 100 ? 'gruen'
        : zugeordnetProzent > 0 ? 'gelb' : 'rot';

    const loeschTd = hatRolle('admin', 'stufenleitung')
        ? `<td><button class="btn-icon btn-icon-gefahr btn-kurs-loeschen"
                       data-kurs-id="${k.id}" data-kurs-name="${escHtml(k.anzeigename)}"
                       title="Kurs löschen">🗑️</button></td>`
        : '';

    return `
    <tr data-kurs-id="${k.id}">
        <td>${escHtml(k.anzeigename)}</td>
        <td>${escHtml(k.kursart)}</td>
        <td>${lehrkraft}</td>
        <td><button class="link-btn btn-kurs-teilnehmer"
                    data-kurs-id="${k.id}" data-kurs-name="${escHtml(k.anzeigename)}"
                    title="Teilnehmendenliste öffnen">
            <span class="ampel ampel-${ampel}"></span>${k.schueler_zugeordnet}/${k.schueler_gesamt}
        </button></td>
        ${loeschTd}
    </tr>`;
}

function renderKursTabelle(kurse) {
    const loeschTh = hatRolle('admin', 'stufenleitung') ? '<th></th>' : '';
    const cols = hatRolle('admin', 'stufenleitung') ? 5 : 4;
    const tbody = kurse.length > 0
        ? kurse.map(renderKursZeile).join('')
        : `<tr><td colspan="${cols}" class="hinweis" style="padding:.5rem">Noch keine Kurse.</td></tr>`;

    return `
        <table class="kurs-tabelle">
            <thead>
                <tr><th>Kurs</th><th>Art</th><th>Lehrkraft</th><th>Schüler*innen</th>${loeschTh}</tr>
            </thead>
            <tbody>${tbody}</tbody>
        </table>`;
}

// ---------------------------------------------------------------------------
// Dialog: Teilnehmendenliste eines Kurses
// ---------------------------------------------------------------------------

async function zeigeKursTeilnehmerDialog(kursId, kursName) {
    const overlay = document.createElement('div');
    overlay.className = 'dialog-overlay';
    overlay.innerHTML = `
        <div class="dialog" style="max-width:560px">
            <h3>Teilnehmende</h3>
            <p class="dialog-kursname">${escHtml(kursName)}</p>
            <div id="tn-inhalt"><p class="lade-text">Wird geladen…</p></div>
            ${hatRolle('admin', 'stufenleitung') ? `
            <div style="margin-top:1rem;border-top:1px solid #e5e7eb;padding-top:.75rem">
                <p style="font-size:.8rem;font-weight:600;margin:0 0 .4rem">Zusätzliche Prüflinge hinzufügen</p>
                <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
                    <input type="text" id="tn-zusatz-name"
                           placeholder="Name – mehrere Zeilen mit Strg+V möglich"
                           style="flex:1;min-width:180px;padding:.3rem .5rem;border:1px solid #ccc;border-radius:3px;font-size:.875rem">
                    <button class="btn btn-klein" id="tn-zusatz-btn">Hinzufügen</button>
                </div>
                <p id="tn-zusatz-info" style="font-size:.8rem;margin:.4rem 0 0;color:#555"></p>
            </div>` : ''}
            <div class="dialog-aktionen" style="margin-top:.75rem">
                <button class="btn btn-sekundaer" id="tn-schliessen">Schließen</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    overlay.querySelector('#tn-schliessen').addEventListener('click', () => overlay.remove());
    schliessbar(overlay);

    async function ladeTeilnehmer(stille = false) {
        const inhaltEl = overlay.querySelector('#tn-inhalt');
        if (!stille) {
            inhaltEl.innerHTML = '<p class="lade-text">Wird geladen…</p>';
        }
        try {
            const eintraege = await apiFetch(`/stufenleitung/kurse/${kursId}/schueler`);
            renderTeilnehmerListe(inhaltEl, eintraege);
        } catch (err) {
            inhaltEl.innerHTML = `<p class="fehler">${escHtml(err.message)}</p>`;
        }
    }

    function renderTeilnehmerListe(el, eintraege) {
        if (eintraege.length === 0) {
            el.innerHTML = '<p class="hinweis">Keine Prüflinge in diesem Kurs.</p>';
            return;
        }

        const zeilen = eintraege.map(e => {
            const name = e.nachname
                ? `${escHtml(e.nachname)}, ${escHtml(e.vorname ?? '')}`
                : parseZeigeNameRoh(e.name_roh);

            const kursart = e.kursart ? escHtml(e.kursart) : '<span class="fehlend">–</span>';
            const istZusatz = e.ist_zusatz == 1;
            const loeschBtn = (hatRolle('admin', 'stufenleitung') && istZusatz)
                ? `<button class="btn-icon btn-icon-gefahr btn-tn-loeschen" data-ks-id="${e.id}" title="Löschen">🗑️</button>`
                : '';

            return `<tr>
                <td>${name}</td>
                <td>${kursart}</td>
                <td>${loeschBtn}</td>
            </tr>`;
        }).join('');

        el.innerHTML = `
            <div class="tabelle-wrapper">
                <table class="data-tabelle">
                    <thead><tr><th>Name</th><th>Kursart</th><th></th></tr></thead>
                    <tbody>${zeilen}</tbody>
                </table>
            </div>`;

        if (hatRolle('admin', 'stufenleitung')) {
            el.querySelectorAll('.btn-tn-loeschen').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const ksId = parseInt(btn.dataset.ksId);
                    const name = btn.closest('tr').querySelector('td').textContent;
                    if (!confirm(`„${name}" wirklich entfernen?`)) return;
                    btn.disabled = true;
                    try {
                        await apiFetch(`/stufenleitung/kurse/${kursId}/zusatz-schueler/${ksId}`, { method: 'DELETE' });
                        await ladeTeilnehmer(true);
                    } catch (err) {
                        alert(err.message);
                        btn.disabled = false;
                    }
                });
            });
        }
    }

    if (hatRolle('admin', 'stufenleitung')) {
        const input  = overlay.querySelector('#tn-zusatz-name');
        const infoEl = overlay.querySelector('#tn-zusatz-info');

        async function addZusatz(name) {
            await apiFetch(`/stufenleitung/kurse/${kursId}/zusatz-schueler`, {
                method: 'POST',
                body: JSON.stringify({ name }),
            });
        }

        input.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                overlay.querySelector('#tn-zusatz-btn').click();
            }
        });

        overlay.querySelector('#tn-zusatz-btn').addEventListener('click', async () => {
            const name = input.value.trim();
            if (!name) return;
            try {
                await addZusatz(name);
                infoEl.style.color = '#27ae60';
                infoEl.textContent = `✓ „${name}" hinzugefügt.`;
                input.value = '';
                await ladeTeilnehmer(true);
            } catch (err) {
                infoEl.style.color = '#c0392b';
                infoEl.textContent = err.message;
            }
        });

        input.addEventListener('paste', async e => {
            const text = (e.clipboardData ?? window.clipboardData)?.getData('text') ?? '';
            if (!text.includes('\n')) return;
            e.preventDefault();
            const namen = text.split('\n').map(n => n.trim()).filter(n => n !== '');
            if (namen.length === 0) return;

            infoEl.style.color = '#555';
            infoEl.textContent = `Füge ${namen.length} Einträge hinzu…`;
            input.value = '';

            let hinzugefuegt = 0;
            const fehler = [];
            for (const name of namen) {
                try { await addZusatz(name); hinzugefuegt++; }
                catch (err) { fehler.push(`„${name}": ${err.message}`); }
            }

            if (fehler.length === 0) {
                infoEl.style.color = '#27ae60';
                infoEl.textContent = `✓ ${hinzugefuegt} Einträge hinzugefügt.`;
            } else {
                infoEl.style.color = '#c0392b';
                infoEl.textContent = `${hinzugefuegt} hinzugefügt, ${fehler.length} Fehler: ${fehler[0]}`;
            }
            await ladeTeilnehmer(true);
        });
    }

    await ladeTeilnehmer();
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

            if (btn.dataset.tab === 'uebersicht') {
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
        const nurLehrkraft = hatRolle('lehrkraft') && !hatRolle('admin', 'stufenleitung');
        const anfragen = [apiFetch('/klausuren')];
        if (nurLehrkraft) anfragen.push(apiFetch('/klausuren/meine-nachschreibtermine'));
        const [klausuren, nachschreibtermine] = await Promise.all(anfragen);
        renderKlausurenUebersicht(el, klausuren, nachschreibtermine ?? []);
    } catch (err) {
        el.innerHTML = `<p class="fehler">${err.message}</p>`;
    }
}

function renderKlausurenUebersicht(el, klausuren, nachschreibtermine = []) {
    if (klausuren.length === 0) {
        el.innerHTML = `<div class="karte"><p>Noch keine Klausuren angelegt.
            ${hatRolle('admin', 'stufenleitung') ? ' Nutzen Sie "Einzeln anlegen" oder "Excel-Import".' : ''}</p></div>`;
        renderLehrkraftNachschreibtermine(el, nachschreibtermine);
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

    // Klausur löschen (admin/SL)
    el.querySelectorAll('.btn-klausur-loeschen').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id   = parseInt(btn.dataset.id);
            const name = btn.dataset.name;
            if (!confirm(`Klausur „${name}" wirklich löschen?\nAlle Anwesenheitsdaten dieser Klausur werden ebenfalls gelöscht.`)) return;
            btn.disabled = true;
            try {
                await apiFetch(`/klausuren/${id}`, { method: 'DELETE' });
                btn.closest('tr').remove();
            } catch (err) {
                alert(`Fehler: ${err.message}`);
                btn.disabled = false;
            }
        });
    });

    // E-Mail-Buttons (admin/SL)
    el.querySelectorAll('.btn-email-ausloesen').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = parseInt(btn.dataset.id);
            const k  = klausuren.find(x => x.id === id);
            const kursname = k ? k.kurs_anzeigename : `Klausur ${id}`;

            if (!confirm(`Anwesenheits-E-Mail für „${kursname}" jetzt senden?`)) return;

            btn.disabled    = true;
            btn.textContent = '…';
            try {
                const res = await apiFetch(`/stufenleitung/email-ausloesen/${id}`, { method: 'POST' });
                alert(`E-Mail wurde gesendet an: ${res.empfaenger}`);
            } catch (err) {
                alert(`Fehler: ${err.message}`);
            } finally {
                btn.disabled    = false;
                btn.textContent = 'E-Mail';
            }
        });
    });

    renderLehrkraftNachschreibtermine(el, nachschreibtermine);
}

function renderLehrkraftNachschreibtermine(el, nachschreibtermine) {
    if (!nachschreibtermine || nachschreibtermine.length === 0) return;

    const zeilen = nachschreibtermine.map(nt => {
        const datum   = nt.termin_datum   ? formatDatum(nt.termin_datum)      : '<span class="fehlend">–</span>';
        const uhrzeit = nt.termin_uhrzeit ? nt.termin_uhrzeit.substring(0, 5) : '–';
        const anzahl  = parseInt(nt.nachschreiber_anzahl) || 0;
        const nr      = nt.klausur_nr > 1 ? ` <span class="klausur-nr">(Nr. ${nt.klausur_nr})</span>` : '';
        return `
        <tr${!nt.termin_datum ? ' class="fehlend"' : ''}>
            <td>${escHtml(nt.kurs_anzeigename)}${nr}</td>
            <td>${datum}</td>
            <td>${uhrzeit}</td>
            <td>${anzahl > 0 ? anzahl : '–'}</td>
            <td>${nt.bemerkung ? escHtml(nt.bemerkung) : '–'}</td>
        </tr>`;
    }).join('');

    const div = document.createElement('div');
    div.innerHTML = `
        <h3 style="margin-top:1.5rem">Meine Nachschreibtermine</h3>
        <div class="karte" style="padding:0;overflow:hidden">
            <div class="tabelle-wrapper">
                <table class="data-tabelle">
                    <thead>
                        <tr>
                            <th>Kurs</th>
                            <th>Datum</th>
                            <th>Uhrzeit</th>
                            <th>Nachschreiber*innen</th>
                            <th>Bemerkung</th>
                        </tr>
                    </thead>
                    <tbody>${zeilen}</tbody>
                </table>
            </div>
        </div>`;
    el.appendChild(div);
}

function renderKlausurZeile(k) {
    const datum   = k.termin_datum    ? formatDatum(k.termin_datum)       : '<span class="fehlend">–</span>';
    const uhrzeit = k.termin_uhrzeit  ? k.termin_uhrzeit.substring(0, 5)  : '–';
    const dauer   = k.dauer_minuten   ? `${k.dauer_minuten} min`          : '–';
    const lk = k.lehrer_id
        ? `${escHtml(k.lehrer_nachname)}, ${escHtml(k.lehrer_vorname)}`
        : `<span class="fehlend">${escHtml(k.lehrer_kuerzel ?? '–')}</span>`;

    const aktionen = [];
    if (hatRolle('admin', 'stufenleitung')) {
        aktionen.push(`<button class="btn-icon btn-klausur-bearbeiten" data-id="${k.id}" title="Bearbeiten">✏️</button>`);
        if (k.lehrer_id) {
            aktionen.push(`<button class="btn-icon btn-email-ausloesen" data-id="${k.id}" title="Anwesenheits-E-Mail senden">✉️</button>`);
        }
        aktionen.push(`<button class="btn-icon btn-icon-gefahr btn-klausur-loeschen" data-id="${k.id}" data-name="${escHtml(k.kurs_anzeigename)}" title="Löschen">🗑️</button>`);
    }
    aktionen.push(`<button class="btn btn-klein btn-anwesenheit" data-id="${k.id}">Anwesenheit</button>`);

    return `
        <tr data-klausur-id="${k.id}">
            <td>${escHtml(k.kurs_anzeigename)}${k.klausur_nr > 1 ? ` <span class="klausur-nr">(Nr. ${k.klausur_nr})</span>` : ''}</td>
            <td>${escHtml(k.kursart)}</td>
            <td>${lk}</td>
            <td>${datum}</td>
            <td>${uhrzeit}</td>
            <td>${dauer}</td>
            <td>${k.schueler_anzahl}</td>
            <td class="td-aw-status">${renderAnwesenheitStatus(k.anwesenheit_erfasst, k.schueler_anzahl)}</td>
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
            <div class="dialog-aktionen">
                <button class="btn" id="dlg-speichern">Speichern</button>
                <button class="btn btn-sekundaer" id="dlg-abbrechen">Abbrechen</button>
            </div>
            <p id="dlg-fehler" class="fehler" style="display:none"></p>
        </div>
    `;
    document.body.appendChild(overlay);

    overlay.querySelector('#dlg-abbrechen').addEventListener('click', () => overlay.remove());
    schliessbar(overlay);

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
                }),
            });
            okEl.textContent = '✓ Klausur angelegt.';
            okEl.style.display = '';
            // Formular zurücksetzen
            el.querySelector('#neu-kurs').value   = '';
            el.querySelector('#neu-datum').value  = '';
            el.querySelector('#neu-uhrzeit').value = '';
            el.querySelector('#neu-dauer').value  = '';
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
                Öffnen Sie sie in Excel, tragen Sie Datum, Uhrzeit und Dauer ein.
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
    const felder  = ['kurs', 'datum', 'uhrzeit', 'dauer'];
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
        </tr>`).join('');

    vorschauEl.innerHTML = `
        <div class="tabelle-wrapper" style="margin-top:1rem">
            <p class="tabelle-hinweis">${daten.length} Zeile(n) erkannt. Vorschau:</p>
            <table class="klausur-tabelle">
                <thead><tr><th>#</th><th>Kurs</th><th>Datum</th><th>Uhrzeit</th><th>Dauer</th></tr></thead>
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
    schliessbar(overlay);

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
            entschuldigt: e.entschuldigt == null ? null : !!e.entschuldigt,
        };
    }

    const zeilen = eintraege.map(e => {
        const name = e.nachname
            ? `${escHtml(e.nachname)}, ${escHtml(e.vorname ?? '')}`
            : parseZeigeNameRoh(e.name_roh);

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
            okEl.textContent   = `✓ ${res.gespeichert} Eintrag/Einträge gespeichert.`;
            okEl.style.display = '';

            // Anwesenheits-Status in der Übersichtstabelle aktualisieren
            const erfasst = Object.values(status).filter(s => s.status !== 'ausstehend').length;
            const gesamt  = Object.keys(status).length;
            const tr      = document.querySelector(`tr[data-klausur-id="${klausur.id}"]`);
            if (tr) {
                tr.querySelector('.td-aw-status').innerHTML =
                    renderAnwesenheitStatus(erfasst, gesamt);
            }
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
                <span class="nt-datum">${datum}, ${uhrzeit} Uhr</span>
                <div class="nt-aktionen">
                    ${hatRolle('admin', 'stufenleitung', 'lehrkraft') ? `
                    <button class="btn btn-klein btn-sekundaer btn-nt-anwesenheit" data-id="${t.id}">Anwesenheit</button>` : ''}
                    ${hatRolle('admin', 'stufenleitung') ? `
                    <button class="btn-icon btn-nt-bearbeiten" data-id="${t.id}" title="Bearbeiten">✏️</button>
                    <button class="btn-icon btn-icon-gefahr btn-nt-loeschen" data-id="${t.id}" title="Löschen">🗑️</button>
                    <button class="btn btn-klein btn-sekundaer btn-nt-klausuren" data-id="${t.id}">Klausuren verknüpfen</button>` : ''}
                </div>
            </div>
            ${t.bemerkung ? `<p class="nt-bemerkung">${escHtml(t.bemerkung)}</p>` : ''}
            <div class="nt-klausuren">${klausurenHtml}</div>
            <div class="nt-edit-bereich-bearbeiten versteckt"></div>
            <div class="nt-edit-bereich-klausuren versteckt"></div>
        </div>`;
    }).join('');

    // Bearbeiten (eigener Bereich, unabhängig von Klausuren verknüpfen)
    el.querySelectorAll('.btn-nt-bearbeiten').forEach(btn => {
        btn.addEventListener('click', () => {
            const id      = parseInt(btn.dataset.id);
            const karte   = el.querySelector(`.nt-karte[data-id="${id}"]`);
            const bereich = karte.querySelector('.nt-edit-bereich-bearbeiten');
            const anderer = karte.querySelector('.nt-edit-bereich-klausuren');
            anderer.classList.add('versteckt');
            bereich.classList.toggle('versteckt');
            if (!bereich.classList.contains('versteckt')) {
                bereich.innerHTML = '';
                const t = termine.find(x => x.id === id);
                renderNachschreibterminFormular(bereich, t, () => {
                    bereich.classList.add('versteckt');
                    ladeNachschreibtermine(el);
                });
            }
        });
    });

    // Klausuren verknüpfen (eigener Bereich)
    el.querySelectorAll('.btn-nt-klausuren').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id      = parseInt(btn.dataset.id);
            const karte   = el.querySelector(`.nt-karte[data-id="${id}"]`);
            const bereich = karte.querySelector('.nt-edit-bereich-klausuren');
            const anderer = karte.querySelector('.nt-edit-bereich-bearbeiten');
            anderer.classList.add('versteckt');
            bereich.classList.toggle('versteckt');
            if (!bereich.classList.contains('versteckt')) {
                bereich.innerHTML = '<p class="lade-text">Klausuren werden geladen…</p>';
                try {
                    const alleKlausuren = await apiFetch('/klausuren?nachschreiber=1');
                    const t = termine.find(x => x.id === id);
                    renderKlausurVerknuepfung(bereich, id, alleKlausuren, t.klausuren, () => {
                        bereich.classList.add('versteckt');
                        ladeNachschreibtermine(el);
                    });
                } catch (err) {
                    bereich.innerHTML = `<p class="fehler">${err.message}</p>`;
                }
            }
        });
    });

    // Löschen
    el.querySelectorAll('.btn-nt-loeschen').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id    = parseInt(btn.dataset.id);
            const t     = termine.find(x => x.id === id);
            const label = t ? formatDatum(t.termin_datum) : `Termin ${id}`;
            if (!confirm(`Nachschreibtermin vom ${label} wirklich löschen?\nAlle verknüpften Daten werden gelöscht.`)) return;
            btn.disabled = true;
            try {
                await apiFetch(`/nachschreibtermine/${id}`, { method: 'DELETE' });
                el.querySelector(`.nt-karte[data-id="${id}"]`).remove();
            } catch (err) {
                alert(`Fehler: ${err.message}`);
                btn.disabled = false;
            }
        });
    });

    // Anwesenheit
    el.querySelectorAll('.btn-nt-anwesenheit').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.dataset.id);
            const t  = termine.find(x => x.id === id);
            if (t) zeigeNachschreibAnwesenheitDialog(t);
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
                : parseZeigeNameRoh(s.name_roh);
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
            : parseZeigeNameRoh(ns.name_roh);
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

/**
 * Parst einen name_roh-Wert für die Anzeige und gibt "Nachname, Vorname" zurück.
 * Formate: "Nachname|Vorname" (GoMST), "Nachname, Vorname" (manuelle Eingabe),
 *          "Vorname Nachname" (ohne Komma → erstes Wort = Vorname).
 */
function parseZeigeNameRoh(nameRoh) {
    const s = nameRoh ?? '';
    if (s.includes('|')) {
        const [n, v] = s.split('|');
        return `${escHtml(n.trim())}, ${escHtml((v ?? '').trim())}`;
    }
    if (s.includes(',')) {
        const i = s.indexOf(',');
        return `${escHtml(s.substring(0, i).trim())}, ${escHtml(s.substring(i + 1).trim())}`;
    }
    const j = s.indexOf(' ');
    if (j > 0) {
        return `${escHtml(s.substring(j + 1).trim())}, ${escHtml(s.substring(0, j).trim())}`;
    }
    return escHtml(s);
}

function escHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ---------------------------------------------------------------------------
// View: Administration (nur Admin)
// ---------------------------------------------------------------------------

async function viewAdmin(el) {
    if (!hatRolle('admin')) {
        el.innerHTML = '<p class="fehler">Kein Zugriff.</p>';
        return;
    }

    el.innerHTML = `
        <h2>Administration</h2>
        <div class="karte">
            <h3>Benutzerverwaltung</h3>
            <p>Rollen und Stufenzuordnungen der angemeldeten Nutzer*innen verwalten.</p>
            <div id="benutzer-container"></div>
        </div>
        <div class="karte">
            <h3>Moodle-Nutzer synchronisieren</h3>
            <p>
                Liest alle aktiven Nutzer*innen aus Moodle und aktualisiert
                die lokale Benutzertabelle (Namen, E-Mail-Adressen, Kürzel).
                Bereits zugewiesene Rollen werden nicht verändert.
            </p>
            <button id="sync-btn" class="btn">Jetzt synchronisieren</button>
            <div id="sync-ergebnis" style="margin-top: 1rem;"></div>
        </div>
        <div class="karte">
            <h3>Fächerbezeichnungen</h3>
            <p>Zuordnung von Fachkürzeln (wie in GoMST) zu lesbaren Bezeichnungen.</p>
            <div id="faecher-container"></div>
        </div>
    `;

    el.querySelector('#sync-btn').addEventListener('click', async () => {
        const btn      = el.querySelector('#sync-btn');
        const ergebnis = el.querySelector('#sync-ergebnis');
        btn.disabled   = true;
        btn.textContent = 'Synchronisiere…';
        ergebnis.innerHTML = '';

        try {
            const res = await apiFetch('/admin/moodle-sync', { method: 'POST' });
            ergebnis.innerHTML = `
                <div class="hinweis-box hinweis-ok">
                    Synchronisation abgeschlossen:
                    <strong>${res.neu}</strong> neue Nutzer*innen,
                    <strong>${res.aktualisiert}</strong> aktualisiert,
                    <strong>${res.geloescht}</strong> gelöscht,
                    <strong>${res.gesamt}</strong> gesamt verarbeitet.
                </div>`;
            // Benutzertabelle still aktualisieren – kein Flackern, kein Scroll-Sprung
            await ladeBenutzerAbschnitt(el, { stille: true });
        } catch (err) {
            ergebnis.innerHTML = `<p class="fehler">Fehler: ${escHtml(err.message)}</p>`;
        } finally {
            btn.disabled    = false;
            btn.textContent = 'Jetzt synchronisieren';
        }
    });

    // Benutzer- und Fächerliste laden
    await Promise.all([
        ladeBenutzerAbschnitt(el),
        ladeFaecherAbschnitt(el),
    ]);
}

async function ladeFaecherAbschnitt(el) {
    const container = el.querySelector('#faecher-container');
    container.innerHTML = '<p class="lade-text">Wird geladen…</p>';

    let faecher;
    try {
        faecher = await apiFetch('/admin/faecher');
    } catch (err) {
        container.innerHTML = `<p class="fehler">${escHtml(err.message)}</p>`;
        return;
    }

    const zeilen = faecher.map(f => `
        <tr data-kuerzel="${escHtml(f.kuerzel)}">
            <td class="fach-kuerzel-zelle">${escHtml(f.kuerzel)}</td>
            <td>
                <input type="text" class="fach-bezeichnung-input" value="${escHtml(f.bezeichnung)}"
                       style="width:100%;max-width:260px;padding:.25rem .4rem;border:1px solid #ccc;border-radius:3px;font-size:.875rem">
            </td>
            <td>
                <button class="btn btn-klein btn-fach-speichern">Speichern</button>
                <span class="fach-ok" style="display:none;color:#27ae60;font-size:.8rem;margin-left:.4rem">✓</span>
                <button class="btn-icon btn-icon-gefahr btn-fach-loeschen" style="margin-left:.25rem" title="Löschen">🗑️</button>
            </td>
        </tr>`).join('');

    container.innerHTML = `
        <div class="tabelle-wrapper" style="margin-bottom:.75rem">
            <table class="data-tabelle">
                <thead><tr><th>Kürzel</th><th>Bezeichnung</th><th></th></tr></thead>
                <tbody>${zeilen}</tbody>
            </table>
        </div>
        <details class="fach-neu-details">
            <summary style="cursor:pointer;font-weight:600;font-size:.875rem">+ Neues Fach hinzufügen</summary>
            <div style="display:flex;gap:.5rem;align-items:flex-end;margin-top:.5rem;flex-wrap:wrap">
                <div>
                    <label style="display:block;font-size:.8rem;margin-bottom:.2rem">Kürzel</label>
                    <input type="text" id="fach-neu-kuerzel" placeholder="z.B. PS" maxlength="10"
                           style="width:80px;padding:.3rem .4rem;border:1px solid #ccc;border-radius:3px;font-size:.875rem">
                </div>
                <div>
                    <label style="display:block;font-size:.8rem;margin-bottom:.2rem">Bezeichnung</label>
                    <input type="text" id="fach-neu-bezeichnung" placeholder="z.B. Psychologie" maxlength="100"
                           style="width:200px;padding:.3rem .4rem;border:1px solid #ccc;border-radius:3px;font-size:.875rem">
                </div>
                <button class="btn" id="fach-neu-btn">Hinzufügen</button>
                <span id="fach-neu-info" style="font-size:.85rem"></span>
            </div>
        </details>`;

    container.querySelectorAll('.btn-fach-speichern').forEach(btn => {
        btn.addEventListener('click', async () => {
            const tr          = btn.closest('tr');
            const kuerzel     = tr.dataset.kuerzel;
            const bezeichnung = tr.querySelector('.fach-bezeichnung-input').value.trim();
            const okEl        = tr.querySelector('.fach-ok');
            if (!bezeichnung) return;

            btn.disabled = true;
            try {
                await apiFetch(`/admin/faecher/${encodeURIComponent(kuerzel)}`, {
                    method: 'PUT',
                    body:   JSON.stringify({ bezeichnung }),
                });
                okEl.style.display = '';
                setTimeout(() => { okEl.style.display = 'none'; }, 2000);
            } catch (err) {
                alert(`Fehler: ${err.message}`);
            } finally {
                btn.disabled = false;
            }
        });
    });

    container.querySelectorAll('.btn-fach-loeschen').forEach(btn => {
        btn.addEventListener('click', async () => {
            const tr      = btn.closest('tr');
            const kuerzel = tr.dataset.kuerzel;
            if (!confirm(`Fach „${kuerzel}" wirklich löschen?`)) return;
            btn.disabled = true;
            try {
                await apiFetch(`/admin/faecher/${encodeURIComponent(kuerzel)}`, { method: 'DELETE' });
                tr.remove();
            } catch (err) {
                alert(`Fehler: ${err.message}`);
                btn.disabled = false;
            }
        });
    });

    container.querySelector('#fach-neu-btn').addEventListener('click', async () => {
        const kuerzelInput     = container.querySelector('#fach-neu-kuerzel');
        const bezeichnungInput = container.querySelector('#fach-neu-bezeichnung');
        const infoEl           = container.querySelector('#fach-neu-info');
        const kuerzel          = kuerzelInput.value.trim().toUpperCase();
        const bezeichnung      = bezeichnungInput.value.trim();
        if (!kuerzel || !bezeichnung) {
            infoEl.style.color = '#c0392b';
            infoEl.textContent = 'Kürzel und Bezeichnung erforderlich.';
            return;
        }

        try {
            await apiFetch('/admin/faecher', {
                method: 'POST',
                body:   JSON.stringify({ kuerzel, bezeichnung }),
            });
            infoEl.style.color  = '#27ae60';
            infoEl.textContent  = `✓ „${kuerzel}" hinzugefügt.`;
            kuerzelInput.value  = '';
            bezeichnungInput.value = '';
            // Tabelle neu laden
            await ladeFaecherAbschnitt(document);
        } catch (err) {
            infoEl.style.color = '#c0392b';
            infoEl.textContent = err.message;
        }
    });
}

// ---------------------------------------------------------------------------
// View: Schüler*innen
// ---------------------------------------------------------------------------

async function viewSchueler(el) {
    if (!hatRolle('schueler')) {
        el.innerHTML = '<p class="fehler">Kein Zugriff.</p>';
        return;
    }

    el.innerHTML = '<p class="lade-text">Klausurtermine werden geladen…</p>';

    let klausuren, nachschreibtermine;
    try {
        [klausuren, nachschreibtermine] = await Promise.all([
            apiFetch('/schueler/meine-klausuren'),
            apiFetch('/schueler/meine-nachschreibtermine'),
        ]);
    } catch (err) {
        el.innerHTML = `<p class="fehler">Fehler: ${escHtml(err.message)}</p>`;
        return;
    }

    const klausurenHtml = klausuren.length === 0
        ? '<div class="karte"><p>Es wurden noch keine Klausurtermine für dich eingetragen.</p></div>'
        : `<div class="karte" style="padding:0;overflow:hidden">
            <div class="tabelle-wrapper">
                <table class="data-tabelle">
                    <thead>
                        <tr>
                            <th>Kurs</th>
                            <th>Datum</th>
                            <th>Uhrzeit</th>
                            <th>Dauer</th>
                        </tr>
                    </thead>
                    <tbody>${klausuren.map(k => {
                        const datum   = k.termin_datum   ? formatDatum(k.termin_datum)      : '<span class="fehlend">–</span>';
                        const uhrzeit = k.termin_uhrzeit ? k.termin_uhrzeit.substring(0, 5) : '–';
                        const dauer   = k.dauer_minuten  ? `${k.dauer_minuten} min`         : '–';
                        const nr      = k.klausur_nr > 1 ? ` <span class="klausur-nr">(Nr. ${k.klausur_nr})</span>` : '';
                        return `
                        <tr${!k.termin_datum ? ' class="fehlend"' : ''}>
                            <td>${escHtml(k.kurs_anzeigename)}${nr}</td>
                            <td>${datum}</td>
                            <td>${uhrzeit}</td>
                            <td>${dauer}</td>
                        </tr>`;
                    }).join('')}</tbody>
                </table>
            </div>
        </div>`;

    const ntHtml = nachschreibtermine.length === 0 ? '' : `
        <h2>Meine Nachschreibtermine</h2>
        <div class="karte" style="padding:0;overflow:hidden">
            <div class="tabelle-wrapper">
                <table class="data-tabelle">
                    <thead>
                        <tr>
                            <th>Kurs</th>
                            <th>Datum</th>
                            <th>Uhrzeit</th>
                            <th>Bemerkung</th>
                        </tr>
                    </thead>
                    <tbody>${nachschreibtermine.map(nt => {
                        const datum   = nt.termin_datum   ? formatDatum(nt.termin_datum)      : '<span class="fehlend">–</span>';
                        const uhrzeit = nt.termin_uhrzeit ? nt.termin_uhrzeit.substring(0, 5) : '–';
                        return `
                        <tr${!nt.termin_datum ? ' class="fehlend"' : ''}>
                            <td>${escHtml(nt.kurs_anzeigename)}</td>
                            <td>${datum}</td>
                            <td>${uhrzeit}</td>
                            <td>${nt.bemerkung ? escHtml(nt.bemerkung) : '–'}</td>
                        </tr>`;
                    }).join('')}</tbody>
                </table>
            </div>
        </div>`;

    el.innerHTML = `<h2>Meine Klausuren</h2>${klausurenHtml}${ntHtml}`;
}

// ---------------------------------------------------------------------------
// Nachschreib-Anwesenheit Dialog
// ---------------------------------------------------------------------------

async function zeigeNachschreibAnwesenheitDialog(termin) {
    const datumStr = termin.termin_datum ? formatDatum(termin.termin_datum) : '–';
    const overlay  = document.createElement('div');
    overlay.className = 'dialog-overlay';
    overlay.innerHTML = `
        <div class="dialog dialog-anwesenheit">
            <h3>Anwesenheit Nachschreibtermin</h3>
            <p class="dialog-kursname">${datumStr}</p>
            <div id="ns-aw-inhalt"><p class="lade-text">Wird geladen…</p></div>
            <div class="dialog-aktionen" style="margin-top:.75rem">
                <button class="btn" id="ns-aw-speichern" disabled>Speichern</button>
                <button class="btn btn-sekundaer" id="ns-aw-schliessen">Schließen</button>
            </div>
            <p id="ns-aw-fehler" class="fehler" style="display:none"></p>
            <p id="ns-aw-ok" class="ok-text" style="display:none"></p>
        </div>`;
    document.body.appendChild(overlay);
    overlay.querySelector('#ns-aw-schliessen').addEventListener('click', () => overlay.remove());
    schliessbar(overlay);

    let eintraege = [];
    try {
        eintraege = await apiFetch(`/nachschreib-anwesenheit/${termin.id}`);
    } catch (err) {
        overlay.querySelector('#ns-aw-inhalt').innerHTML = `<p class="fehler">${escHtml(err.message)}</p>`;
        return;
    }

    if (eintraege.length === 0) {
        overlay.querySelector('#ns-aw-inhalt').innerHTML =
            '<p class="hinweis">Keine Nachschreiber*innen für diesen Termin.</p>';
        return;
    }

    const status = {};
    for (const e of eintraege) {
        status[e.kurs_schueler_id] = { status: e.status, kommentar: e.kommentar ?? '' };
    }

    const zeilen = eintraege.map(e => {
        const name = e.nachname
            ? `${escHtml(e.nachname)}, ${escHtml(e.vorname ?? '')}`
            : parseZeigeNameRoh(e.name_roh);
        const kursTag = e.kurs_anzeigename
            ? ` <span class="klausur-tag" style="font-size:.75rem">${escHtml(e.kurs_anzeigename)}</span>`
            : '';
        return `
        <tr class="aw-zeile" data-id="${e.kurs_schueler_id}">
            <td>${name}${kursTag}</td>
            <td class="td-status">
                <div class="status-gruppe">
                    <button type="button" class="btn-status ${e.status === 'anwesend'   ? 'aktiv status-anwesend'   : ''}" data-status="anwesend">Anwesend</button>
                    <button type="button" class="btn-status ${e.status === 'fehlend'    ? 'aktiv status-fehlend'    : ''}" data-status="fehlend">Fehlend</button>
                    <button type="button" class="btn-status ${e.status === 'ausstehend' ? 'aktiv status-ausstehend' : ''}" data-status="ausstehend">?</button>
                </div>
            </td>
            <td>
                <input type="text" class="aw-kommentar" placeholder="Kommentar" value="${escHtml(e.kommentar ?? '')}"
                       style="width:100%;max-width:200px;padding:.25rem .4rem;border:1px solid #ccc;border-radius:3px;font-size:.875rem">
            </td>
        </tr>`;
    }).join('');

    overlay.querySelector('#ns-aw-inhalt').innerHTML = `
        <div style="margin-bottom:.5rem">
            <button class="btn btn-klein btn-sekundaer" id="ns-alle-anwesend">Alle anwesend</button>
        </div>
        <div class="tabelle-wrapper aw-tabelle-wrapper">
            <table class="aw-tabelle">
                <thead><tr><th>Name</th><th>Status</th><th>Kommentar</th></tr></thead>
                <tbody>${zeilen}</tbody>
            </table>
        </div>`;

    overlay.querySelector('#ns-aw-speichern').disabled = false;

    overlay.querySelector('#ns-alle-anwesend').addEventListener('click', () => {
        overlay.querySelectorAll('.aw-zeile').forEach(z => setzeStatus(z, 'anwesend', status));
    });

    overlay.querySelectorAll('.btn-status').forEach(btn => {
        btn.addEventListener('click', () => setzeStatus(btn.closest('.aw-zeile'), btn.dataset.status, status));
    });

    overlay.querySelectorAll('.aw-kommentar').forEach(input => {
        input.addEventListener('input', () => {
            status[parseInt(input.closest('.aw-zeile').dataset.id)].kommentar = input.value;
        });
    });

    overlay.querySelector('#ns-aw-speichern').addEventListener('click', async () => {
        const btn      = overlay.querySelector('#ns-aw-speichern');
        const fehlerEl = overlay.querySelector('#ns-aw-fehler');
        const okEl     = overlay.querySelector('#ns-aw-ok');
        btn.disabled   = true;
        fehlerEl.style.display = okEl.style.display = 'none';

        const payload = Object.entries(status).map(([id, s]) => ({
            kurs_schueler_id: parseInt(id),
            status:           s.status,
            kommentar:        s.kommentar || '',
        }));

        try {
            const res = await apiFetch(`/nachschreib-anwesenheit/${termin.id}`, {
                method: 'POST',
                body:   JSON.stringify(payload),
            });
            okEl.textContent   = `✓ ${res.gespeichert} Eintrag/Einträge gespeichert.`;
            okEl.style.display = '';
        } catch (err) {
            fehlerEl.textContent   = err.message;
            fehlerEl.style.display = '';
        } finally {
            btn.disabled = false;
        }
    });
}

// ---------------------------------------------------------------------------
// Admin: Benutzerverwaltung (Rollenzuweisung)
// ---------------------------------------------------------------------------

async function ladeBenutzerAbschnitt(el, { stille = false } = {}) {
    const container = el.querySelector('#benutzer-container');
    if (!stille) {
        container.innerHTML = '<p class="lade-text">Wird geladen…</p>';
    }

    let [benutzer, stufen] = await Promise.all([
        apiFetch('/admin/benutzer'),
        apiFetch('/admin/stufen').catch(() => []),
    ]);

    if (benutzer.length === 0) {
        container.innerHTML = '<p>Keine Benutzer*innen gefunden.</p>';
        return;
    }

    const stufenOptionen = stufen.map(s =>
        `<option value="${s.id}">${escHtml(s.name)} (${escHtml(s.schuljahr)})</option>`
    ).join('');

    const zeilen = benutzer.map(b => {
        const rArr   = b.rollen ?? [];
        const badges = rArr.length
            ? rArr.map(r => `<span class="rolle-badge rolle-${r}">${r}</span>`).join(' ')
            : '<span class="fehlend">–</span>';
        return `
        <tr data-id="${b.id}">
            <td>${escHtml(b.nachname)}, ${escHtml(b.vorname)}</td>
            <td class="td-rollen">${badges}</td>
            <td>
                <button class="btn-icon btn-rollen-bearbeiten"
                        data-id="${b.id}" title="Rollen bearbeiten">✏️</button>
            </td>
        </tr>`;
    }).join('');

    container.innerHTML = `
        <div class="tabelle-wrapper">
            <table class="data-tabelle">
                <thead><tr><th>Name</th><th>Rollen</th><th></th></tr></thead>
                <tbody>${zeilen}</tbody>
            </table>
        </div>`;

    container.querySelectorAll('.btn-rollen-bearbeiten').forEach(btn => {
        btn.addEventListener('click', async () => {
            const bid  = parseInt(btn.dataset.id);
            const row  = container.querySelector(`tr[data-id="${bid}"]`);
            const b    = benutzer.find(x => x.id === bid);
            const rArr = b.rollen ?? [];

            // Stufen-Zuordnungen laden wenn SL
            let slStufen = [];
            if (rArr.includes('stufenleitung')) {
                slStufen = await apiFetch(`/admin/benutzer/${bid}/stufenleitungen`).catch(() => []);
            }

            const overlay = document.createElement('div');
            overlay.className = 'dialog-overlay';
            overlay.innerHTML = `
                <div class="dialog">
                    <h3>Rollen: ${escHtml(b.vorname)} ${escHtml(b.nachname)}</h3>
                    <div class="formular-gruppe">
                        ${['admin','stufenleitung','lehrkraft','schueler'].map(r => `
                        <label style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem">
                            <input type="checkbox" class="cb-rolle" value="${r}" ${rArr.includes(r) ? 'checked' : ''}>
                            ${r}
                        </label>`).join('')}
                    </div>
                    <div id="sl-stufen-abschnitt" style="${rArr.includes('stufenleitung') ? '' : 'display:none'}">
                        <label style="font-size:.875rem;font-weight:600">Stufen (Stufenleitung):</label>
                        <select id="sl-stufen" multiple size="5" style="width:100%;margin-top:.3rem;border:1px solid #ccc;border-radius:3px;padding:.3rem">
                            ${stufenOptionen}
                        </select>
                    </div>
                    <div class="dialog-aktionen">
                        <button class="btn" id="rollen-speichern">Speichern</button>
                        <button class="btn btn-sekundaer" id="rollen-abbrechen">Abbrechen</button>
                    </div>
                    <p id="rollen-fehler" class="fehler" style="display:none"></p>
                </div>`;
            document.body.appendChild(overlay);
            schliessbar(overlay);
            overlay.querySelector('#rollen-abbrechen').addEventListener('click', () => overlay.remove());

            // Vorselektierte Stufen
            const slSelect = overlay.querySelector('#sl-stufen');
            slStufen.forEach(sid => {
                const opt = slSelect.querySelector(`option[value="${sid}"]`);
                if (opt) opt.selected = true;
            });

            // Stufenleitung-Checkbox zeigt/versteckt Stufen-Picker
            overlay.querySelector('.cb-rolle[value="stufenleitung"]').addEventListener('change', e => {
                overlay.querySelector('#sl-stufen-abschnitt').style.display = e.target.checked ? '' : 'none';
            });

            overlay.querySelector('#rollen-speichern').addEventListener('click', async () => {
                const saveBtn  = overlay.querySelector('#rollen-speichern');
                const fehlerEl = overlay.querySelector('#rollen-fehler');
                saveBtn.disabled = true;
                fehlerEl.style.display = 'none';

                const neueRollen = [...overlay.querySelectorAll('.cb-rolle:checked')].map(cb => cb.value);
                const neueStufen = neueRollen.includes('stufenleitung')
                    ? [...slSelect.selectedOptions].map(o => parseInt(o.value))
                    : [];

                try {
                    await apiFetch(`/admin/benutzer/${bid}/rollen`, {
                        method: 'POST',
                        body: JSON.stringify({ rollen: neueRollen }),
                    });
                    if (neueRollen.includes('stufenleitung')) {
                        await apiFetch(`/admin/benutzer/${bid}/stufenleitungen`, {
                            method: 'POST',
                            body: JSON.stringify({ stufen_ids: neueStufen }),
                        });
                    }
                    b.rollen = neueRollen;
                    const badges = neueRollen.length
                        ? neueRollen.map(r => `<span class="rolle-badge rolle-${r}">${r}</span>`).join(' ')
                        : '<span class="fehlend">–</span>';
                    row.querySelector('.td-rollen').innerHTML = badges;
                    overlay.remove();
                } catch (err) {
                    fehlerEl.textContent   = err.message;
                    fehlerEl.style.display = '';
                    saveBtn.disabled = false;
                }
            });
        });
    });
}

// ---------------------------------------------------------------------------
// Start
// ---------------------------------------------------------------------------

render();
