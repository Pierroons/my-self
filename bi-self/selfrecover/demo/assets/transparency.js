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
                <span class="tp-title">TRANSPARENCY LIVE — backend visible</span>
                <div class="tp-controls">
                    <button id="tp-pause" title="Pause / reprise">PAUSE</button>
                    <button id="tp-clear" title="Effacer">CLEAR</button>
                    <button id="tp-export" title="Exporter le log">EXPORT</button>
                </div>
            </div>
            <div class="tp-body" id="tp-body">
                <div class="tp-empty">Aucune activite. Utilise la demo (Register / Login / Recover) pour voir le backend en direct. Aucune donnee sensible n'est affichee : passphrases, mots de passe et hashes complets sont volontairement masques.</div>
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
    }

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

        if (!res.ok) throw new Error(data.error || 'Unknown error');
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
