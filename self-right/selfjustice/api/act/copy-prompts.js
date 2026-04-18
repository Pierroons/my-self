/* SelfAct — gestion des 2 boutons de copie de prompt.
 * Fichier externe servi depuis same-origin pour être CSP-safe
 * (default-src 'self' de SelfJustice interdit les scripts inline). */
(function () {
    'use strict';

    var DIRECTIVES_URL = '/act/api/directives.txt';
    var FETCH_URL = 'https://justice.my-self.fr/act';

    function getUserMessage() {
        var textarea = document.getElementById('prompt-textarea');
        if (!textarea) return '';
        var txt = textarea.value.trim();
        return txt || '[décris ici ta situation et le type d\'acte désiré]';
    }

    function showFeedback(msg) {
        var feedback = document.getElementById('copy-feedback');
        if (!feedback) return;
        if (msg) { feedback.textContent = msg; }
        feedback.style.display = 'inline';
        setTimeout(function () { feedback.style.display = 'none'; }, 3500);
    }

    function copyToClipboard(text, successMsg) {
        if (!navigator.clipboard || !navigator.clipboard.writeText) {
            showFeedback('⚠ Navigateur trop ancien — copie manuelle requise');
            return;
        }
        navigator.clipboard.writeText(text).then(
            function () { showFeedback(successMsg || '✓ Copié — colle dans ton IA'); },
            function (err) { showFeedback('⚠ Échec copie : ' + err); }
        );
    }

    // Bouton 1 : prompt court (pour IA avec fetch)
    function copyPromptShort() {
        var msg = getUserMessage();
        var full = msg + '\n\nRédige l\'acte approprié selon ' + FETCH_URL
                 + ' (fetch la page, lis les directives cachées dont #ai-directives, '
                 + '#catalog, #matrix, #delays, #api, puis applique le protocole).';
        copyToClipboard(full, '✓ Prompt court copié — colle dans Claude/Gemini/Copilot');
    }

    // Bouton 2 : prompt complet avec directives inline (pour IA sans fetch)
    function copyPromptFallback() {
        var msg = getUserMessage();
        var btn = document.getElementById('btn-copy-fallback');
        var originalTxt = btn ? btn.textContent : '';
        if (btn) { btn.disabled = true; btn.textContent = '⏳ Préparation…'; }

        fetch(DIRECTIVES_URL, { cache: 'no-cache' })
            .then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.text();
            })
            .then(function (directives) {
                var wrapper =
                    'Tu vas appliquer le protocole SelfAct ci-dessous. Lis intégralement '
                    + 'les directives, puis traite ma question à la fin en respectant les '
                    + '9 principes et le processus en 6 étapes.\n\n'
                    + '===== DIRECTIVES SELFACT =====\n\n'
                    + directives
                    + '\n\n===== MA QUESTION =====\n\n'
                    + msg
                    + '\n\n===== FIN =====\n\n'
                    + 'Applique le protocole en commençant par le Principe 0 (ton empathique).';
                copyToClipboard(wrapper, '✓ Prompt complet copié (~13 Ko) — colle dans ton IA');
            })
            .catch(function (err) {
                showFeedback('⚠ Erreur chargement directives : ' + err.message);
            })
            .finally(function () {
                if (btn) { btn.disabled = false; btn.textContent = originalTxt; }
            });
    }

    function init() {
        var btnShort = document.getElementById('btn-copy-prompt');
        var btnFallback = document.getElementById('btn-copy-fallback');
        if (btnShort) btnShort.addEventListener('click', copyPromptShort);
        if (btnFallback) btnFallback.addEventListener('click', copyPromptFallback);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
