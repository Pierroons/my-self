/* SelfDataGuard — le mot mémorisé reste dans la page (vanilla JS)
 *
 * La page envoie l'empreinte que dérive le composant de SelfRecover
 * (sr-derive.js, un lien vers bi-self/selfrecover/client/) : un HMAC-SHA256
 * dont le mot est la clé, sur le nom d'hôte, la version du format et le sel
 * du compte. La bibliothèque scelle la serrure du mot sur cette empreinte, et
 * refuse le mot lui-même.
 *
 * Le sel : SelfRecover le tire à l'inscription (srEngendrerSel) et le garde
 * avec le compte. La démo n'a pas de compte SelfRecover pour le garder : elle
 * le dérive de l'identifiant. Il reste propre à chaque compte, mais il est
 * connu avant que le compte existe. Un intégrateur le tire.
 */

'use strict';

// 'hostname' : une page servie sous un autre nom dérive une autre empreinte.
const DEMO_DERIVATION = { mode: 'hostname' };

async function demoSel(userId) {
  const h = await crypto.subtle.digest('SHA-256', new TextEncoder().encode('selfdataguard-demo/sel|' + userId));
  return [...new Uint8Array(h).slice(0, 16)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/** L'empreinte du mot pour ce compte. Lève, sans rien envoyer, si la dérivation manque. */
async function demoEmpreinte(mot, userId) {
  if (typeof srDerive !== 'function') {
    throw new Error("La dérivation n'est pas chargée (sr-derive.js) : rien n'a été envoyé, ton mot n'a pas quitté cette page.");
  }
  const m = mot.trim();
  if (m === '') {
    throw new Error('Le mot mémorisé est vide.');
  }
  return srDerive(m, await demoSel(userId), DEMO_DERIVATION);
}

/** Le secret à envoyer : l'empreinte pour le mot, le mot de passe tel quel. */
async function demoSecret(mode, secret, userId) {
  return mode === 'memorized' ? demoEmpreinte(secret, userId) : secret;
}
