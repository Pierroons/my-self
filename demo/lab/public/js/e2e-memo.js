/*
 * MySelf-Lab — chiffrement E2E du mémo, CÔTÉ CLIENT (WebCrypto).
 *
 * Incarne le mapping SelfRecover ⇄ SelfDataGuard :
 *   secret racine ──PBKDF2──► master ──HKDF(label)──► clé fille
 *     label "data-enc"     (dérivée du PASSWORD)     → enveloppe A
 *     label "data-recover" (dérivée de la PASSPHRASE)→ enveloppe B
 * Une vault_key aléatoire chiffre réellement le mémo ; elle est wrappée dans les
 * deux enveloppes. Aucune clé ni aucun plaintext ne quitte ce fichier.
 *
 * Isomorphe : navigateur (window) + Node 20 (globalThis.crypto) pour les tests.
 */
(function () {
  'use strict';
  const _crypto = globalThis.crypto;
  const subtle = _crypto.subtle;
  const enc = (s) => new TextEncoder().encode(s);
  const dec = (b) => new TextDecoder().decode(b);

  function bytesToB64(bytes) {
    let bin = '';
    for (const b of bytes) bin += String.fromCharCode(b);
    return btoa(bin);
  }
  function b64ToBytes(b64) {
    const bin = atob(b64);
    const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  }

  // secret → PBKDF2 (lent, salé) → HKDF(label) → clé AES-GCM fille
  async function deriveAesKey(secret, saltBytes, iter, label) {
    const base = await subtle.importKey('raw', enc(secret), 'PBKDF2', false, ['deriveBits']);
    const master = await subtle.deriveBits(
      { name: 'PBKDF2', salt: saltBytes, iterations: iter, hash: 'SHA-256' }, base, 256);
    const hk = await subtle.importKey('raw', master, 'HKDF', false, ['deriveKey']);
    return subtle.deriveKey(
      { name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(0), info: enc('myself-lab/memo/' + label) },
      hk, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
  }

  const PBKDF2_ITER = 600000; // recommandation OWASP (PBKDF2-SHA256)

  // Crée le coffre complet (création / réinitialisation). Renvoie les blobs base64.
  async function createVault(password, passphrase, memoText) {
    const salt = _crypto.getRandomValues(new Uint8Array(16));
    const encKey = await deriveAesKey(password, salt, PBKDF2_ITER, 'data-enc');
    const recKey = await deriveAesKey(passphrase, salt, PBKDF2_ITER, 'data-recover');

    const vaultKeyRaw = _crypto.getRandomValues(new Uint8Array(32));
    const vaultKey = await subtle.importKey('raw', vaultKeyRaw, { name: 'AES-GCM' }, false, ['encrypt']);

    const memoIv = _crypto.getRandomValues(new Uint8Array(12));
    const memoCt = new Uint8Array(await subtle.encrypt({ name: 'AES-GCM', iv: memoIv }, vaultKey, enc(memoText)));

    const pwIv = _crypto.getRandomValues(new Uint8Array(12));
    const pwCt = new Uint8Array(await subtle.encrypt({ name: 'AES-GCM', iv: pwIv }, encKey, vaultKeyRaw));

    const recIv = _crypto.getRandomValues(new Uint8Array(12));
    const recCt = new Uint8Array(await subtle.encrypt({ name: 'AES-GCM', iv: recIv }, recKey, vaultKeyRaw));

    return {
      kdf_salt: bytesToB64(salt), kdf_iter: PBKDF2_ITER,
      memo_iv: bytesToB64(memoIv), memo_ct: bytesToB64(memoCt),
      wrap_pw_iv: bytesToB64(pwIv), wrap_pw_ct: bytesToB64(pwCt),
      wrap_rec_iv: bytesToB64(recIv), wrap_rec_ct: bytesToB64(recCt),
    };
  }

  // Ouvre le coffre. which = 'pw' (password) ou 'rec' (passphrase de secours).
  // Renvoie {memo, vaultKeyB64} ; lève une erreur si le secret est faux.
  async function unlock(secret, vault, which) {
    const salt = b64ToBytes(vault.kdf_salt);
    const iter = Number(vault.kdf_iter);
    const isRec = which === 'rec';
    const key = await deriveAesKey(secret, salt, iter, isRec ? 'data-recover' : 'data-enc');
    const wIv = b64ToBytes(isRec ? vault.wrap_rec_iv : vault.wrap_pw_iv);
    const wCt = b64ToBytes(isRec ? vault.wrap_rec_ct : vault.wrap_pw_ct);

    let vaultKeyRaw;
    try {
      vaultKeyRaw = new Uint8Array(await subtle.decrypt({ name: 'AES-GCM', iv: wIv }, key, wCt));
    } catch (e) {
      throw new Error('secret_incorrect');
    }
    const vaultKey = await subtle.importKey('raw', vaultKeyRaw, { name: 'AES-GCM' }, false, ['decrypt']);
    const memoBytes = new Uint8Array(await subtle.decrypt(
      { name: 'AES-GCM', iv: b64ToBytes(vault.memo_iv) }, vaultKey, b64ToBytes(vault.memo_ct)));
    return { memo: dec(memoBytes), vaultKeyB64: bytesToB64(vaultKeyRaw) };
  }

  // Ré-chiffre le mémo avec la vault_key déjà déverrouillée (édition sans re-saisir le secret).
  async function reEncryptMemo(vaultKeyB64, memoText) {
    const vaultKey = await subtle.importKey('raw', b64ToBytes(vaultKeyB64), { name: 'AES-GCM' }, false, ['encrypt']);
    const iv = _crypto.getRandomValues(new Uint8Array(12));
    const ct = new Uint8Array(await subtle.encrypt({ name: 'AES-GCM', iv }, vaultKey, enc(memoText)));
    return { memo_iv: bytesToB64(iv), memo_ct: bytesToB64(ct) };
  }

  const E2EMemo = { createVault, unlock, reEncryptMemo, PBKDF2_ITER };
  if (typeof window !== 'undefined') window.E2EMemo = E2EMemo;
  if (typeof module !== 'undefined' && module.exports) module.exports = E2EMemo;
})();
