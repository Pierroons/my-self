/**
 * SelfRecover — Transparency Live panel
 *
 * Affiche en temps réel l'arrière-boutique du protocole : requêtes API,
 * dérivations cryptographiques côté client, opérations serveur, durées.
 *
 * RÈGLE D'OR : aucune donnée sensible en clair n'est jamais affichée.
 * - Passphrases brutes / mots de récupération / mots de passe : JAMAIS
 * - Hashes Argon2id complets : JAMAIS (juste prefix 7 chars optionnel)
 * - Body de requête : anonymisé en (clé, type, longueur)
 *
 * Logs côté client envoyés au panneau via window.SelfRecoverTP.log()
 * Logs côté serveur récupérés via le champ `_trace` de chaque réponse JSON
 */

(function () {
    'use strict';

    let paused = false;
    let panelEl = null;
    let bodyEl = null;
    const buffer = [];
    const MAX_LINES = 500;

    // ===================== STYLES =====================
    function injectStyles() {
        const css = `
        /* Split visuel ludique : panel-app gauche / panel-logs (transparency) droite */
        #transparency-panel {
            position: fixed; top: 0; right: 0; bottom: 0;
            width: 380px;
            background: #0a0e14; color: #e2e8f0;
            border-left: 2px solid #7ab7ff;
            font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
            font-size: 11px; line-height: 1.4;
            z-index: 9999;
            display: flex; flex-direction: column;
            box-shadow: -4px 0 24px rgba(0,0,0,0.4);
            transition: transform 0.18s ease;
        }
        #transparency-panel.collapsed { transform: translateX(calc(100% - 32px)); }
        @media (max-width: 900px) {
            #transparency-panel {
                top: auto; left: 0; right: 0; bottom: 0;
                width: auto; max-height: 35vh; min-height: 32px;
                border-left: none; border-top: 2px solid #7ab7ff;
                box-shadow: 0 -4px 24px rgba(0,0,0,0.4);
            }
            #transparency-panel.collapsed { transform: translateY(calc(100% - 32px)); }
        }
        .tp-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 8px 12px; background: #1a1f29; border-bottom: 1px solid #2a3038;
            cursor: pointer; flex-shrink: 0; user-select: none;
        }
        .tp-title { font-weight: 600; color: #7ab7ff; font-family: -apple-system, system-ui, sans-serif; font-size: 12px; }
        .tp-title::before { content: "● "; color: #7cc47c; animation: tp-blink 1.5s ease-in-out infinite; }
        @keyframes tp-blink { 50% { opacity: 0.3; } }
        .tp-controls { display: flex; gap: 4px; }
        .tp-controls button {
            background: #0f1419; color: #9aa0a6; border: 1px solid #2a3038;
            padding: 3px 8px; font-size: 10px; cursor: pointer; border-radius: 3px;
            font-family: inherit;
        }
        .tp-controls button:hover { background: #1a2028; color: #e2e8f0; }
        .tp-body {
            overflow-y: auto; padding: 6px 12px; flex: 1;
        }
        .tp-empty { color: #64748b; font-style: italic; padding: 12px 0; }
        .tp-row {
            display: grid; grid-template-columns: 70px 60px 1fr; gap: 8px;
            padding: 1px 0; border-bottom: 1px dotted #1a2028;
            white-space: pre-wrap; word-break: break-word;
        }
        .tp-ts { color: #64748b; }
        .tp-level {
            text-align: center; font-weight: bold; padding: 0 4px;
            border-radius: 2px; font-size: 9px; height: 14px; line-height: 14px;
        }
        .tp-out    .tp-level { background: #1e3a8a; color: #93c5fd; }
        .tp-in     .tp-level { background: #14532d; color: #86efac; }
        .tp-client .tp-level { background: #92400e; color: #fdba74; }
        .tp-server .tp-level { background: #4c1d95; color: #c4b5fd; }
        .tp-error  .tp-level { background: #7f1d1d; color: #fca5a5; }
        .tp-info   .tp-level { background: #2a3038; color: #9aa0a6; }
        .tp-msg { color: #e2e8f0; }
        .tp-out    .tp-msg { color: #93c5fd; }
        .tp-in     .tp-msg { color: #86efac; }
        .tp-client .tp-msg { color: #fdba74; }
        .tp-server .tp-msg { color: #c4b5fd; }
        .tp-error  .tp-msg { color: #fca5a5; font-weight: 600; }
        /* Onglets Backend / Admin */
        .tp-tabs { display: flex; border-bottom: 1px solid #2a3038; flex-shrink: 0; }
        .tp-tab { flex: 1; background: #12161d; color: #9aa0a6; border: none; border-bottom: 2px solid transparent; padding: 6px 8px; font-size: 11px; cursor: pointer; font-family: inherit; }
        .tp-tab.active { color: #7ab7ff; border-bottom-color: #7ab7ff; background: #1a2028; }
        .tp-admin { overflow-y: auto; padding: 8px 10px; flex: 1; font-size: 11px; color: #e2e8f0; }
        .tp-admin .demo-tag { background: #92400e; color: #fdba74; font-size: 9px; padding: 2px 6px; border-radius: 3px; display: inline-block; margin-bottom: 8px; }
        .tp-admin h4 { font-size: 11px; color: #7ab7ff; margin: 10px 0 4px; text-transform: uppercase; letter-spacing: 0.5px; }
        .tp-sig { color: #fdba74; padding: 2px 0; border-bottom: 1px dotted #1a2028; }
        .tp-disp { border: 1px solid #2a3038; border-radius: 5px; padding: 7px; margin-bottom: 8px; background: #12161d; }
        .tp-disp .num { color: #7ab7ff; font-weight: 600; }
        .tp-disp .meta { color: #9aa0a6; font-size: 10px; margin: 2px 0; }
        .tp-disp .corr { color: #fca5a5; }
        .tp-chat { background: #0a0e14; border-radius: 4px; padding: 5px; margin: 5px 0; max-height: 120px; overflow-y: auto; }
        .tp-chat .m { padding: 2px 0; } .tp-chat .m.admin { color: #86efac; } .tp-chat .m.user { color: #93c5fd; }
        .tp-disp input { width: 100%; background: #0f1419; color: #e2e8f0; border: 1px solid #2a3038; border-radius: 3px; padding: 4px; font-size: 11px; font-family: inherit; margin: 3px 0; }
        .tp-disp .acts { display: flex; gap: 4px; margin-top: 4px; }
        .tp-disp .acts button { flex: 1; padding: 4px; font-size: 10px; border: none; border-radius: 3px; cursor: pointer; font-family: inherit; }
        .tp-disp .grant { background: #14532d; color: #86efac; } .tp-disp .refuse { background: #7f1d1d; color: #fca5a5; }
        body { padding-right: 380px !important; }
        @media (max-width: 900px) {
            body { padding-right: 0 !important; padding-bottom: 32px !important; }
        }
        `;
        const el = document.createElement('style');
        el.textContent = css;
        document.head.appendChild(el);
    }

    // ===================== UI =====================
    function injectPanel() {
        panelEl = document.createElement('div');
        panelEl.id = 'transparency-panel';
        // Plus de collapsed par défaut : le split frontend/backend rend le panel-logs
        // visible en permanence (style ludique aligné sur les démos legacy).
        panelEl.innerHTML = `
            <div class="tp-header" id="tp-header" title="Cliquer pour deplier/replier">
                <span class="tp-title">TRANSPARENCY LIVE</span>
                <div class="tp-controls">
                    <button id="tp-pause" title="Pause / reprise">PAUSE</button>
                    <button id="tp-clear" title="Effacer">CLEAR</button>
                    <button id="tp-export" title="Exporter le log">EXPORT</button>
                </div>
            </div>
            <div class="tp-tabs">
                <button class="tp-tab active" data-view="backend">📡 Backend</button>
                <button class="tp-tab" data-view="admin">🛡️ Admin</button>
            </div>
            <div class="tp-body" id="tp-body">
                <div class="tp-empty">Aucune activite. Utilise la demo (Register / Login / Recover) pour voir le backend en direct. Aucune donnee sensible n'est affichee : passphrases, mots de passe et hashes complets sont volontairement masques.</div>
            </div>
            <div class="tp-admin" id="tp-admin" style="display:none">
                <span class="demo-tag">DÉMO — en prod, console séparée &amp; authentifiée</span>
                <div id="tp-admin-body">Ouvre cet onglet pour piloter les litiges…</div>
            </div>
        `;
        document.body.appendChild(panelEl);
        bodyEl = document.getElementById('tp-body');

        document.getElementById('tp-header').addEventListener('click', (e) => {
            if (e.target.closest('button')) return;
            toggle();
        });
        document.getElementById('tp-pause').addEventListener('click', (e) => {
            e.stopPropagation();
            paused = !paused;
            e.target.textContent = paused ? 'RESUME' : 'PAUSE';
        });
        document.getElementById('tp-clear').addEventListener('click', (e) => {
            e.stopPropagation();
            buffer.length = 0;
            bodyEl.innerHTML = '<div class="tp-empty">Log efface.</div>';
        });
        document.getElementById('tp-export').addEventListener('click', (e) => {
            e.stopPropagation();
            exportLog();
        });
        document.querySelectorAll('.tp-tab').forEach(t => t.addEventListener('click', (e) => {
            e.stopPropagation();
            document.querySelectorAll('.tp-tab').forEach(x => x.classList.remove('active'));
            t.classList.add('active');
            const admin = t.dataset.view === 'admin';
            document.getElementById('tp-body').style.display = admin ? 'none' : '';
            document.getElementById('tp-admin').style.display = admin ? '' : 'none';
            if (admin) tpAdminStart(); else tpAdminStop();
        }));
    }

    // ===================== CONSOLE ADMIN (démo — surface pédago, PAS la prod) =====================
    const TP_ADMIN_TOKEN = 'admindemo'; // démo : doit matcher SELFRECOVER_ADMIN_TOKEN au lancement du serveur
    let tpAdminTimer = null;

    async function tpAdminApi(action, body) {
        const opts = { method: body ? 'POST' : 'GET', headers: { 'X-Admin-Token': TP_ADMIN_TOKEN } };
        if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
        const res = await fetch('api/index.php?action=' + action, opts);
        return res.json();
    }

    async function tpRenderAdmin() {
        const el = document.getElementById('tp-admin-body');
        if (!el) return;
        // Ne pas ré-render pendant que l'admin tape (sinon l'input est recréé et le texte perdu)
        const af = document.activeElement;
        if (af && af.tagName === 'INPUT' && af.closest && af.closest('.tp-admin')) return;
        let sig = { signals: [] }, disp = { disputes: [] };
        try { sig = await tpAdminApi('admin-l2-signals'); } catch (e) {}
        try { disp = await tpAdminApi('admin-disputes'); } catch (e) {}
        if (sig.error || disp.error) { el.innerHTML = '<div style="color:#fca5a5">' + escapeHtml(sig.error || disp.error) + ' (token démo « admindemo » — lance le serveur avec SELFRECOVER_ADMIN_TOKEN=admindemo)</div>'; return; }
        // Récupère les messages de chaque litige (côté admin) pour l'afficher
        for (const d of (disp.disputes || [])) {
            try { const c = await tpAdminApi('dispute-chat', { dispute_number: d.dispute_number }); d._msgs = c.messages || []; d.status = c.status || d.status; } catch (e) { d._msgs = []; }
        }
        let html = '<h4>Signaux L2 (anonymes)</h4>';
        html += (sig.signals && sig.signals.length)
            ? sig.signals.map(s => '<div class="tp-sig">⚠ ' + s.attempts + ' tentative(s) L2 · ' + escapeHtml(s.last_seen || '') + '</div>').join('')
            : '<div style="color:#64748b">aucun</div>';
        html += '<h4>Litiges L3</h4>';
        html += (disp.disputes && disp.disputes.length)
            ? disp.disputes.map(tpRenderDispute).join('')
            : '<div style="color:#64748b">aucun</div>';
        el.innerHTML = html;
    }

    function tpRenderDispute(d) {
        const open = ['open', 'awaiting_admin'].includes(d.status);
        const msgs = (d._msgs || []).length
            ? d._msgs.map(m => '<div class="m ' + m.sender + '">' + (m.sender === 'admin' ? 'Admin' : escapeHtml(d.username)) + ' : ' + escapeHtml(m.body) + '</div>').join('')
            : '<div style="color:#64748b">…</div>';
        const corr = d.l2_prior_attempts ? ' · <span class="corr">' + d.l2_prior_attempts + ' échec(s) L2 préalable(s) (même source)</span>' : '';
        return '<div class="tp-disp" data-num="' + escapeHtml(d.dispute_number) + '">' +
            '<div><span class="num">' + escapeHtml(d.dispute_number) + '</span> · <b>' + escapeHtml(d.username) + '</b></div>' +
            '<div class="meta">statut : ' + escapeHtml(d.status) + corr + '</div>' +
            '<div class="tp-chat">' + msgs + '</div>' +
            (open
                ? '<input type="text" placeholder="Répondre au demandeur…" onkeydown="if(event.key===\'Enter\'){window.SelfRecoverTP.adminChat(this.closest(\'.tp-disp\').dataset.num,this.value);this.value=\'\';}">' +
                  '<div class="acts"><button class="grant" onclick="window.SelfRecoverTP.adminDecide(\'' + d.dispute_number + '\',\'grant\')">Accorder</button>' +
                  '<button class="refuse" onclick="window.SelfRecoverTP.adminDecide(\'' + d.dispute_number + '\',\'refuse\')">Refuser</button></div>'
                : '') +
            '</div>';
    }

    function tpAdminStart() { tpRenderAdmin(); if (tpAdminTimer) clearInterval(tpAdminTimer); tpAdminTimer = setInterval(tpRenderAdmin, 3000); }
    function tpAdminStop() { if (tpAdminTimer) { clearInterval(tpAdminTimer); tpAdminTimer = null; } }

    function toggle() {
        panelEl.classList.toggle('collapsed');
    }

    // ===================== LOG =====================
    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function log(level, msg) {
        if (paused) return;
        const ts = new Date().toLocaleTimeString('fr-FR', { hour12: false });
        const line = { ts, level, msg };
        buffer.push(line);
        if (buffer.length > MAX_LINES) buffer.shift();

        // Vider le placeholder
        const empty = bodyEl.querySelector('.tp-empty');
        if (empty) empty.remove();

        const row = document.createElement('div');
        row.className = `tp-row tp-${level}`;
        row.innerHTML = `<span class="tp-ts">${ts}</span><span class="tp-level">${level.toUpperCase()}</span><span class="tp-msg">${escapeHtml(msg)}</span>`;
        bodyEl.appendChild(row);

        // Limite DOM size
        while (bodyEl.children.length > MAX_LINES) {
            bodyEl.removeChild(bodyEl.firstChild);
        }
        bodyEl.scrollTop = bodyEl.scrollHeight;
    }

    function exportLog() {
        const text = buffer.map(l => `[${l.ts}] [${l.level.padEnd(7)}] ${l.msg}`).join('\n');
        const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `selfrecover-trace-${new Date().toISOString().replace(/[:.]/g, '-')}.txt`;
        a.click();
        URL.revokeObjectURL(a.href);
    }

    // ===================== ANONYMISATION =====================
    function anonBody(body) {
        if (!body) return null;
        const safe = {};
        for (const k of Object.keys(body)) {
            const v = body[k];
            if (v === null || v === undefined) safe[k] = null;
            else if (typeof v === 'string') safe[k] = `<string ${v.length}ch>`;
            else if (typeof v === 'number') safe[k] = `<number>`;
            else if (typeof v === 'boolean') safe[k] = `<bool>`;
            else safe[k] = `<${typeof v}>`;
        }
        return safe;
    }

    // ===================== WRAPPERS API =====================
    async function apiTraced(action, body) {
        log('out', `OUT ${body ? 'POST' : 'GET'} api/index.php?action=${action}`);
        if (body) {
            log('out', `    body keys: ${JSON.stringify(anonBody(body))}`);
        }
        const t0 = performance.now();
        const opts = {
            method: body ? 'POST' : 'GET',
            headers: body ? { 'Content-Type': 'application/json' } : {},
        };
        if (body) opts.body = JSON.stringify(body);
        const res = await fetch('api/index.php?action=' + action, opts);
        const data = await res.json();
        const dur = (performance.now() - t0).toFixed(1);

        if (data._trace) {
            for (const t of data._trace) log('server', t);
        }

        if (res.ok) {
            log('in', `IN  ${res.status} ${res.statusText} (${dur} ms)`);
        } else {
            log('error', `IN  ${res.status} ${data.error || 'Unknown'} (${dur} ms)`);
        }

        if (!res.ok) { const err = new Error(data.error || 'Unknown error'); err.data = data; throw err; }
        // Retire _trace de la donnée renvoyée pour ne pas polluer le code utilisateur
        const { _trace, ...clean } = data;
        return clean;
    }

    async function hmacDeriveTraced(word, salt) {
        const enc = new TextEncoder();
        const label = window.SERVICE_LABEL || 'selfrecover.my-self.fr'; // R9-02 : label stable, pas l'URL
        log('client', `CLIENT computing HMAC-SHA256(input.length=${word.length}ch, key="${label}"+per-user-salt[${salt.length}ch])`);
        const t0 = performance.now();
        const keyMaterial = enc.encode(label + salt);
        const key = await crypto.subtle.importKey(
            'raw', keyMaterial,
            { name: 'HMAC', hash: 'SHA-256' },
            false, ['sign']
        );
        const sig = await crypto.subtle.sign('HMAC', key, enc.encode(word));
        const hex = Array.from(new Uint8Array(sig))
            .map(b => b.toString(16).padStart(2, '0')).join('');
        const dur = (performance.now() - t0).toFixed(1);
        log('client', `CLIENT derived (${hex.length}ch hex, prefix: ${hex.substring(0, 16)}...) en ${dur} ms`);
        log('client', `CLIENT raw input never leaves browser. Only derivee est transmise au serveur.`);
        return hex;
    }

    // ===================== EXPORT API =====================
    window.SelfRecoverTP = {
        log: log,
        toggle: toggle,
        clear: () => { buffer.length = 0; bodyEl.innerHTML = ''; },
        export: exportLog,
        apiTraced: apiTraced,
        hmacDeriveTraced: hmacDeriveTraced,
        adminDecide: async (num, decision) => { await tpAdminApi('admin-dispute-decide', { dispute_number: num, decision }); tpRenderAdmin(); },
        adminChat: async (num, msg) => { if (!msg.trim()) return; await tpAdminApi('dispute-chat', { dispute_number: num, message: msg }); tpRenderAdmin(); },
    };

    // ===================== AUTO-INIT + MONKEY-PATCH =====================
    function init() {
        injectStyles();
        injectPanel();

        // Monkey-patch les fonctions globales si déjà définies
        if (typeof window.api === 'function') {
            window.api = apiTraced;
        }
        if (typeof window.hmacDerive === 'function') {
            window.hmacDerive = hmacDeriveTraced;
        }

        // Trace initiale
        log('info', "Transparency panel chargee. Aucune donnee sensible (passphrase, mdp, mot de recup) n'est affichee ici. Cliquer le header pour deplier.");
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
