/**
 * SelfRecover — dérivation du mot mémorisé CÔTÉ CLIENT.
 *
 * Le mot mémorisé (recovery word) ne quitte JAMAIS le navigateur : on en dérive
 * une clé via HMAC-SHA256(clé = mot, message = label de service STABLE), et seule
 * cette clé dérivée transite vers le serveur, qui stocke Argon2id(clé).
 *
 * Le message est un label STABLE configuré (PAS location.hostname) : un changement
 * d'URL (rachat de domaine) ne doit pas casser la dérivation (cf R9-02).
 */
(function () {
  'use strict';

  // Label de service stable, versionné. À NE PAS changer sans plan de migration.
  window.SR_DOMAIN = 'myself-lab-domain-v1';

  function toHex(buf) {
    return Array.from(new Uint8Array(buf))
      .map(function (b) { return b.toString(16).padStart(2, '0'); })
      .join('');
  }

  /** Retourne (Promise) la clé dérivée hex (64 car.) du mot mémorisé. */
  window.srDerive = async function (word) {
    var enc = new TextEncoder();
    var key = await crypto.subtle.importKey(
      'raw', enc.encode(word), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']
    );
    var sig = await crypto.subtle.sign('HMAC', key, enc.encode(window.SR_DOMAIN));
    return toHex(sig);
  };
})();
