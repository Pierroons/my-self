/**
 * SelfRecover — facteur possession « CET APPAREIL » côté client (ECDSA P-256).
 *
 * Le navigateur génère une paire ECDSA P-256. La clé PRIVÉE est chiffrée au repos
 * (AES-256-GCM) par une clé dérivée du MOT MÉMORISÉ, puis stockée localement. Le
 * serveur ne reçoit QUE la clé publique. Récupérer = signer un challenge :
 * impossible sans l'appareil (le blob) ET le mot (pour déchiffrer la privée) →
 * 2FA cryptographique.
 *
 * ── La dérivation n'est pas ici ────────────────────────────────────────────
 *
 * Elle vit dans `sr-kdf.js`, livré par la bibliothèque et partagé par lien
 * symbolique : un seul porteur, des vecteurs figés vérifiés en PHP et en
 * JavaScript. Ce fichier n'en est qu'un appelant.
 *
 * Le mot mémorisé est choisi par un humain — `register.php` en accepte quatre
 * caractères — et il sert ici à CHIFFRER. L'exception PBKDF2 du projet
 * (SelfVault) ne couvre que des secrets tirés au sort : elle ne s'applique pas.
 *
 * ⚠️ **Les blobs écrits avant ce changement ne sont pas lisibles** : rien n'y
 * indique la KDF employée, c'est exactement le défaut corrigé. `srDeviceRecover`
 * le dit et invite à ré-enrôler, plutôt que de laisser croire à un mot oublié.
 *
 * Charger avant celui-ci : `argon2id.js` puis `sr-kdf.js`.
 * Stockage : localStorage['srdev_<username>'].
 */
(function () {
  'use strict';
  var enc = new TextEncoder();
  function hex(buf){ return Array.from(new Uint8Array(buf)).map(b=>b.toString(16).padStart(2,'0')).join(''); }
  function b64u(buf){ return btoa(String.fromCharCode.apply(null,new Uint8Array(buf))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,''); }

  function post(url, payload){ return fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}).then(r=>r.json()); }

  /** Enrôle cet appareil pour `username`, protégé par `word`. Retourne {ok,message}. */
  window.srDeviceEnroll = async function(username, word){
    var kp = await crypto.subtle.generateKey({name:'ECDSA',namedCurve:'P-256'}, true, ['sign','verify']);
    var pubSpki = await crypto.subtle.exportKey('spki', kp.publicKey);
    var privPkcs8 = await crypto.subtle.exportKey('pkcs8', kp.privateKey);
    // Le blob porte sa version et ses paramètres : c'est `sr-kdf.js` qui les écrit.
    var blob = await srKdfChiffrer(word, privPkcs8);
    var credentialId = hex(crypto.getRandomValues(new Uint8Array(16))); // 32 hex → [A-Za-z0-9_-]{16,64}
    // Le serveur exige la preuve qu'on détient le mot : sans elle, on pourrait
    // enrôler son appareil sur le compte d'un autre. Le mot lui-même ne part
    // pas — seule sa dérivation HMAC, comme partout ailleurs.
    // Le sel du compte, que la session identifie : l'enrôlement n'a pas de code
    // de secours sous la main, mais il sait déjà qui il est.
    var rs = await fetch('/api/sel.php', {method:'POST', credentials:'include',
      headers:{'Content-Type':'application/json'}, body:'{}'}).then(function(r){ return r.json(); });
    var derived = await window.srDerive(word, rs.sel, { mode: 'hostname' });
    var r = await post('/api/device_enroll.php', {
      username: username, credential_id: credentialId,
      public_key: b64u(pubSpki), memorized_derived_key: derived
    });
    if (r.ok) {
      localStorage.setItem('srdev_'+username, JSON.stringify({ credentialId: credentialId, blob: blob }));
    }
    return r;
  };

  /** True si un credential est enrôlé localement pour `username`. */
  window.srDeviceHas = function(username){ return !!localStorage.getItem('srdev_'+username); };

  /** Récupère depuis cet appareil : signe le challenge avec la privée déchiffrée par `word`. */
  window.srDeviceRecover = async function(username, word){
    var raw = localStorage.getItem('srdev_'+username);
    if (!raw) return { ok:false, message:'Aucun appareil enrôlé ici pour ce compte.' };
    var st = JSON.parse(raw);
    var begin = await post('/api/device_auth_begin.php', { credential_id: st.credentialId });
    if (!begin.ok) return begin;
    // 🔑 Un blob d'avant le versionnage n'est pas un blob corrompu, et le dire
    // change ce que la personne va faire : sans distinction, elle chercherait un
    // mot qu'elle n'a pas oublié. La seule issue est un ré-enrôlement.
    if (!st.blob) {
      return { ok:false, message:"Cet appareil a été enrôlé avec une version antérieure, dont la protection ne peut plus être relue. Ton mot mémorisé est inchangé : ré-enrôle cet appareil." };
    }
    var privPkcs8;
    try {
      privPkcs8 = await srKdfDechiffrer(word, st.blob);
    } catch(e) {
      // 🔑 Deux pannes très différentes arrivent ici. Un mot faux fait échouer
      // AES-GCM sans message exploitable ; un blob mal formé, d'une version
      // inconnue ou aux paramètres affaiblis fait lever `sr-kdf.js` avec une
      // raison. Les confondre enverrait chercher un mot qui est le bon.
      var raison = String((e && e.message) || '');
      if (raison.indexOf('srKdf : ') === 0) {
        return { ok:false, message:'Le contenu enrôlé sur cet appareil est inutilisable — ' + raison.slice(8) };
      }
      return { ok:false, message:'Mot mémorisé incorrect (clé de cet appareil non déchiffrable).' };
    }
    var priv = await crypto.subtle.importKey('pkcs8', privPkcs8, {name:'ECDSA',namedCurve:'P-256'}, false, ['sign']);
    var sig  = await crypto.subtle.sign({name:'ECDSA',hash:'SHA-256'}, priv, enc.encode(begin.challenge)); // P1363 r||s
    return post('/api/device_auth_finish.php', { credential_id: st.credentialId, challenge: begin.challenge, signature: b64u(sig) });
  };
})();
