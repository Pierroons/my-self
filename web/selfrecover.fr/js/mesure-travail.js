/* Travailleur de « Comprendre » : un Argon2id au profil de la bibliothèque, chronométré hors du fil de la page. */
'use strict';
importScripts('/js/argon2id.js', '/js/sr-kdf.js');
self.onmessage = async function () {
  var debut = performance.now();
  try {
    await self.srKdfDeriver('essai', self.srKdfEngendrerSel());
    self.postMessage({ ms: performance.now() - debut, profil: self.SR_KDF_PROFIL });
  } catch (e) {
    self.postMessage({ erreur: String(e && e.message || e) });
  }
};
