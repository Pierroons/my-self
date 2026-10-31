/* selfrecover.fr — « Comprendre » : la dérivation pas à pas, par le dériveur de la bibliothèque.
 * Le mot ne sort pas de cette fonction : il n'est recopié nulle part dans la page. */
(function () {
  'use strict';
  var mot = document.getElementById('d-mot');
  if (!mot || typeof window.srDerive !== 'function') return;
  var C = window.Coulisses;
  var $ = function (id) { return document.getElementById(id); };
  var etapes = Array.prototype.slice.call(document.querySelectorAll('.chaine .maillon'));
  var hote = window.location.hostname.toLowerCase();
  var sel = window.srEngendrerSel(), tour = 0, minuteur = 0, minuteurJournal = 0;
  var reduit = window.matchMedia('(prefers-reduced-motion: reduce)');

  function mode() { return $('d-mode-label').checked ? 'label' : 'hostname'; }
  function materiel() { return mode() === 'label' ? ($('d-label').value || '') : hote; }

  function allumer(jusqua) {
    etapes.forEach(function (e, i) { e.classList.toggle('allume', i < jusqua); });
  }

  function poser(id, valeur, anime) {
    var c = $(id);
    if (anime && C) C.decrypter(c, valeur); else c.textContent = valeur;
  }

  async function calculer() {
    var n = ++tour, m = mot.value;
    $('d-materiel').textContent = materiel() || '— étiquette vide —';
    $('d-sel').textContent = sel;
    $('d-message').textContent = materiel() + '|v2' + sel;
    if (!m || !materiel()) { allumer(0); poser('d-empreinte', '— tape un mot —'); return; }
    var empreinte;
    try {
      empreinte = await window.srDerive(m, sel, mode() === 'label' ? { mode: 'label', label: materiel() } : { mode: 'hostname' });
    } catch (e) { poser('d-empreinte', 'refusé par le dériveur : ' + e.message); return; }
    if (n !== tour) return;
    // L'allumage pas à pas suit l'ordre du calcul ; sous mouvement réduit, tout s'allume d'un coup.
    var pas = reduit.matches ? 0 : 220;
    etapes.forEach(function (e, i) { window.setTimeout(function () { if (n === tour) allumer(i + 1); }, pas * i); });
    window.setTimeout(function () { if (n === tour) poser('d-empreinte', empreinte, true); }, pas * etapes.length);
    window.clearTimeout(minuteurJournal);
    minuteurJournal = window.setTimeout(function () {
      if (!C) return;
      C.emit('nav', 'La page « Comprendre » a dérivé ton mot, ici, sans rien envoyer.', [
        ['mode', mode() === 'label' ? 'étiquette « ' + materiel() + ' »' : 'nom d\'hôte'],
        ['message HMAC', materiel() + '|v2' + sel],
        ['empreinte', empreinte, true]]);
    }, 800);
  }

  function relancer() { window.clearTimeout(minuteur); minuteur = window.setTimeout(calculer, 120); }
  mot.addEventListener('input', relancer);
  $('d-label').addEventListener('input', relancer);
  [$('d-mode-hote'), $('d-mode-label')].forEach(function (r) {
    r.addEventListener('change', function () { $('d-label').disabled = mode() !== 'label'; relancer(); });
  });
  $('d-resel').addEventListener('click', function () { sel = window.srEngendrerSel(); relancer(); });
  $('d-hote').textContent = hote;
  calculer();
})();
