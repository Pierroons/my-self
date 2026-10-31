/* selfrecover.fr — le niveau de lecture de « Comprendre » : public, ou dev (qui ajoute formules et code).
 * Chargé en tête, sans defer : le niveau retenu est posé avant le premier rendu, sans saut de page. */
(function () {
  'use strict';
  var CLE = 'sr_niveau', racine = document.documentElement, niveau = 'public';
  try { if (window.localStorage.getItem(CLE) === 'dev') niveau = 'dev'; } catch (e) { /* stockage refusé : public */ }
  racine.setAttribute('data-niveau', niveau);

  document.addEventListener('DOMContentLoaded', function () {
    var boutons = Array.prototype.slice.call(document.querySelectorAll('[data-choix-niveau]'));
    function afficher(v) {
      boutons.forEach(function (b) { b.setAttribute('aria-pressed', String(b.getAttribute('data-choix-niveau') === v)); });
    }
    boutons.forEach(function (b) {
      b.addEventListener('click', function () {
        var v = b.getAttribute('data-choix-niveau');
        racine.setAttribute('data-niveau', v);
        afficher(v);
        try { window.localStorage.setItem(CLE, v); } catch (e) { /* le choix vaut pour cette page seulement */ }
      });
    });
    var groupe = document.querySelector('.niveau');
    if (groupe) groupe.hidden = false;
    afficher(racine.getAttribute('data-niveau'));
  });
})();
