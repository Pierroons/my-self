/**
 * SelfRecover — facteur possession « CET APPAREIL » côté client (ECDSA P-256).
 *
 * Le navigateur génère une paire ECDSA P-256. La clé PRIVÉE est chiffrée au repos
 * (AES-256-GCM) par une clé dérivée du MOT MÉMORISÉ (PBKDF2), puis stockée localement.
 * Le serveur ne reçoit QUE la clé publique. Récupérer = signer un challenge : impossible
 * sans l'appareil (le blob) ET le mot (pour déchiffrer la privée) → 2FA cryptographique.
 *
 * Dépend de rien d'externe (WebCrypto natif). Stockage : localStorage['srdev_<username>'].
 */
(function () {
  'use strict';
  var enc = new TextEncoder();
  function hex(buf){ return Array.from(new Uint8Array(buf)).map(b=>b.toString(16).padStart(2,'0')).join(''); }
  function fromHex(h){ var a=new Uint8Array(h.length/2); for(var i=0;i<a.length;i++)a[i]=parseInt(h.substr(i*2,2),16); return a; }
  function b64u(buf){ return btoa(String.fromCharCode.apply(null,new Uint8Array(buf))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,''); }
  function unb64u(s){ s=s.replace(/-/g,'+').replace(/_/g,'/'); while(s.length%4)s+='='; var bin=atob(s),a=new Uint8Array(bin.length); for(var i=0;i<bin.length;i++)a[i]=bin.charCodeAt(i); return a; }

  // Clé AES-GCM dérivée du mot mémorisé (PBKDF2-SHA256, 200k itérations) + sel par-appareil.
  async function aesKey(word, salt){
    var base = await crypto.subtle.importKey('raw', enc.encode(word), 'PBKDF2', false, ['deriveKey']);
    return crypto.subtle.deriveKey(
      { name:'PBKDF2', salt: salt, iterations: 200000, hash:'SHA-256' },
      base, { name:'AES-GCM', length:256 }, false, ['encrypt','decrypt']
    );
  }

  function post(url, payload){ return fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}).then(r=>r.json()); }

  /** Enrôle cet appareil pour `username`, protégé par `word`. Retourne {ok,message}. */
  window.srDeviceEnroll = async function(username, word){
    var kp = await crypto.subtle.generateKey({name:'ECDSA',namedCurve:'P-256'}, true, ['sign','verify']);
    var pubSpki = await crypto.subtle.exportKey('spki', kp.publicKey);
    var privPkcs8 = await crypto.subtle.exportKey('pkcs8', kp.privateKey);
    var salt = crypto.getRandomValues(new Uint8Array(16));
    var iv   = crypto.getRandomValues(new Uint8Array(12));
    var ct   = await crypto.subtle.encrypt({name:'AES-GCM',iv:iv}, await aesKey(word,salt), privPkcs8);
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
      localStorage.setItem('srdev_'+username, JSON.stringify({ credentialId: credentialId, salt: hex(salt), iv: hex(iv), ct: b64u(ct) }));
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
    var privPkcs8;
    try {
      privPkcs8 = await crypto.subtle.decrypt({name:'AES-GCM',iv:fromHex(st.iv)}, await aesKey(word, fromHex(st.salt)), unb64u(st.ct));
    } catch(e) {
      return { ok:false, message:'Mot mémorisé incorrect (clé de cet appareil non déchiffrable).' };
    }
    var priv = await crypto.subtle.importKey('pkcs8', privPkcs8, {name:'ECDSA',namedCurve:'P-256'}, false, ['sign']);
    var sig  = await crypto.subtle.sign({name:'ECDSA',hash:'SHA-256'}, priv, enc.encode(begin.challenge)); // P1363 r||s
    return post('/api/device_auth_finish.php', { credential_id: st.credentialId, challenge: begin.challenge, signature: b64u(sig) });
  };
})();
