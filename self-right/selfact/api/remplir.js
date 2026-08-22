/* SelfAct — remplissage des gabarits, entièrement dans le navigateur.
 *
 * 🔑 Aucune donnée ne quitte la machine. Pas de requête, pas de stockage, pas
 * de journal : le texte saisi vit dans la page et disparaît à sa fermeture.
 * C'est une décision d'architecture, pas une commodité — un remplissage côté
 * serveur ferait de SelfAct un traitement de données personnelles sensibles
 * (identité, adresse, récit d'un litige), avec base légale, minimisation,
 * conservation et sous-traitance à porter. Ici, il n'y a rien à protéger
 * puisqu'il n'y a rien à recevoir.
 *
 * 🔑 SelfAct met en forme ce que la personne fournit ; il ne qualifie rien.
 * Aucun champ n'est deviné, aucun article suggéré, aucune demande formulée à
 * partir d'un récit. La différence entre mise en forme et analyse des faits de
 * l'espèce est exactement la frontière de la loi 71-1130.
 *
 * Le procédé : les crochets du gabarit deviennent des zones éditables. Rien
 * n'est ajouté au document, seule son édition est rendue possible.
 */
(function () {
  'use strict';

  var MOTIF = /\[[^\]\n]{3,60}\]/g;

  function editable(texte) {
    var s = document.createElement('span');
    s.className = 'champ-a-remplir';
    s.setAttribute('contenteditable', 'plaintext-only');
    s.setAttribute('spellcheck', 'false');
    s.dataset.vide = texte;          // pour restaurer un champ effacé
    s.textContent = texte;
    return s;
  }

  // Un nœud texte à la fois : remplacer par tranches préserve la mise en page
  // et n'altère jamais le contenu rédactionnel du gabarit.
  function transformer(noeud) {
    var texte = noeud.nodeValue;
    if (!MOTIF.test(texte)) { return; }
    MOTIF.lastIndex = 0;

    var fragment = document.createDocumentFragment();
    var position = 0, trouve;
    while ((trouve = MOTIF.exec(texte)) !== null) {
      if (trouve.index > position) {
        fragment.appendChild(document.createTextNode(texte.slice(position, trouve.index)));
      }
      fragment.appendChild(editable(trouve[0]));
      position = trouve.index + trouve[0].length;
    }
    if (position < texte.length) {
      fragment.appendChild(document.createTextNode(texte.slice(position)));
    }
    noeud.parentNode.replaceChild(fragment, noeud);
  }

  function parcourir(racine) {
    // ⚠️ Collecter avant de modifier : remplacer un nœud pendant l'itération
    // fait sauter des éléments au parcours suivant.
    var it = document.createTreeWalker(racine, NodeFilter.SHOW_TEXT, {
      acceptNode: function (n) {
        if (!n.nodeValue || n.nodeValue.indexOf('[') === -1) { return NodeFilter.FILTER_REJECT; }
        var p = n.parentNode;
        // Ni dans l'avertissement, ni dans un script ou un lien : ces crochets
        // appartiennent au document, pas à l'utilisateur.
        while (p && p !== racine) {
          var c = p.className || '';
          if (p.tagName === 'SCRIPT' || p.tagName === 'STYLE' || p.tagName === 'A'
              || (typeof c === 'string' && c.indexOf('disclaimer') !== -1)) {
            return NodeFilter.FILTER_REJECT;
          }
          p = p.parentNode;
        }
        return NodeFilter.FILTER_ACCEPT;
      }
    });
    var noeuds = [], n;
    while ((n = it.nextNode())) { noeuds.push(n); }
    noeuds.forEach(transformer);
    return noeuds.length;
  }

  function bandeau(nombre) {
    var d = document.createElement('div');
    d.className = 'bandeau-remplissage';
    d.innerHTML =
      '<strong>' + nombre + ' champ(s) à compléter.</strong> ' +
      'Clique dessus et écris. <strong>Rien n\'est envoyé</strong> : le texte reste ' +
      'sur cette machine, n\'est ni transmis ni enregistré, et disparaît à la fermeture ' +
      'de l\'onglet. Imprime ou enregistre en PDF avant de fermer.' +
      '<br>Les faits, montants et dates ne sont jamais devinés : ils t\'appartiennent.';
    return d;
  }

  document.addEventListener('DOMContentLoaded', function () {
    var page = document.querySelector('.page, body');
    if (!page) { return; }
    var n = parcourir(page);
    if (!n) { return; }

    document.body.insertBefore(bandeau(document.querySelectorAll('.champ-a-remplir').length),
                               document.body.firstChild);

    // Un champ vidé retrouve son intitulé : sans ça, l'utilisateur perd
    // l'indication de ce qu'il devait écrire et imprime un blanc.
    document.addEventListener('blur', function (e) {
      var c = e.target;
      if (c && c.classList && c.classList.contains('champ-a-remplir')
          && !c.textContent.trim()) {
        c.textContent = c.dataset.vide;
      }
    }, true);
  });
})();
