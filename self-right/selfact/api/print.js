/* SelfAct — script d'impression pour draft.php.
 * Fichier externe servi depuis same-origin pour être CSP-safe
 * (default-src 'self' de SelfJustice interdit les scripts inline). */
(function () {
    'use strict';
    function init() {
        var btn = document.getElementById('btn-print');
        if (!btn) return;
        btn.addEventListener('click', function () {
            window.print();
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
