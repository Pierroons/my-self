/* selfrecover.fr — l'essai de l'accueil : le dériveur de la bibliothèque, appliqué au mot tapé, ici et sur une copie.
 * Le mot ne quitte pas cette fonction : ni réseau, ni stockage, ni journal. */
(function () {
  'use strict';
  var mot = document.getElementById('essai-mot');
  if (!mot || typeof window.srDerive !== 'function') return;
  var PIEGE = 'selfrec0ver.example';
  var hote = window.location.hostname.toLowerCase();
  var sortie = {
    ici: document.getElementById('essai-ici'),
    piege: document.getElementById('essai-piege'),
    verdict: document.getElementById('essai-verdict')
  };
  var C = window.Coulisses;
  // Un sel de compte d'essai, propre à cette visite : il n'est pas affiché ici, les coulisses le montrent.
  var sel = window.srEngendrerSel(), tour = 0, minuteur = 0, minuteurJournal = 0;

  document.getElementById('essai-hote').textContent = hote;
  document.getElementById('essai-hote-piege').textContent = PIEGE;

  function poser(cible, valeur, attente) {
    cible.classList.toggle('attente', !!attente);
    if (C && !attente) C.decrypter(cible, valeur); else cible.textContent = valeur;
  }

  function attendre() {
    poser(sortie.ici, '— tape un mot —', true);
    poser(sortie.piege, '— tape un mot —', true);
    sortie.verdict.textContent = 'Les deux empreintes apparaîtront ici.';
    sortie.verdict.classList.add('vide');
  }

  function journaliser(ici, piege) {
    if (!C) return;
    C.emit('nav', 'Ton navigateur a dérivé ton mot en empreinte. Le mot, lui, n\'a pas bougé.', [
      ['formule', 'HMAC-SHA256(clé = ton mot, message = « ' + hote + '|v2 » + sel)'],
      ['sel du compte d\'essai', sel, true],
      ['empreinte', ici, true]
    ]);
    C.emit('wire', 'Aucune requête : tout s\'est passé dans ton navigateur.');
    C.emit('nav', 'Copiée sur ' + PIEGE + ', la même page aurait dérivé une autre empreinte, sans valeur ici.', [
      ['empreinte de la copie', piege, true]
    ]);
  }

  async function calculer() {
    var m = mot.value, n = ++tour;
    if (!m) { attendre(); return; }
    var r = await Promise.all([
      window.srDerive(m, sel, { mode: 'hostname' }),
      window.srDerive(m, sel, { mode: 'label', label: PIEGE })
    ]);
    if (n !== tour) return;
    poser(sortie.ici, r[0]);
    poser(sortie.piege, r[1]);
    sortie.verdict.textContent = '≠  Même mot, deux adresses : la copie obtient une empreinte qui ne vaut rien ici.';
    sortie.verdict.classList.remove('vide');
    window.clearTimeout(minuteurJournal);
    minuteurJournal = window.setTimeout(function () { journaliser(r[0], r[1]); }, 700);
  }

  mot.addEventListener('input', function () {
    window.clearTimeout(minuteur);
    minuteur = window.setTimeout(calculer, 120);
  });
  attendre();
})();
