/* selfrecover.fr — « Comprendre » : mesurer sur l'appareil du visiteur ce que coûte un Argon2id.
 * Le calcul tourne dans un worker : la page ne se fige pas. Le profil affiché est lu dans la bibliothèque. */
(function () {
  'use strict';
  var bouton = document.getElementById('m-lancer');
  if (!bouton || typeof window.Worker !== 'function') return;
  var sortie = document.getElementById('m-sortie'), C = window.Coulisses;
  bouton.hidden = false;
  bouton.addEventListener('click', function () {
    bouton.disabled = true;
    sortie.textContent = 'Calcul en cours : ton appareil réserve la mémoire du profil et la parcourt plusieurs fois…';
    var w = new window.Worker('/js/mesure-travail.js');
    w.onmessage = function (e) {
      w.terminate();
      bouton.disabled = false;
      if (e.data.erreur) { sortie.textContent = 'Mesure impossible ici : ' + e.data.erreur; return; }
      var s = (e.data.ms / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 2 });
      var p = e.data.profil, mio = p.m / 1024;
      sortie.textContent = 'Un essai : ' + s + ' s sur ton appareil (Argon2id, ' + mio + ' Mio, ' + p.t + ' passes).';
      if (C) C.emit('nav', 'Ton navigateur a payé un Argon2id au profil de la bibliothèque : ' + s + ' s.', [
        ['profil', 'm = ' + p.m + ' Kio · t = ' + p.t + ' · p = ' + p.p + ' · sortie ' + p.dkLen + ' octets'],
        ['source', 'SR_KDF_PROFIL, client/sr-kdf.js']]);
    };
    w.onerror = function () { w.terminate(); bouton.disabled = false; sortie.textContent = 'Mesure impossible ici (travailleur refusé).'; };
    w.postMessage(null);
  });
})();
