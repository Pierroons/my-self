/**
 * SelfRecover — dérivation d'une clé de CHIFFREMENT depuis le mot mémorisé.
 *
 * ── Ce fichier n'est pas `sr-derive.js`, et la différence est tout ──────────
 *
 * `sr-derive.js` produit une empreinte qui PART vers le serveur : c'est une
 * preuve, elle transite, et le serveur en range un Argon2id de son côté.
 *
 * Ici, rien ne part. La clé calculée sert à chiffrer un secret qui reste dans
 * ce navigateur — aujourd'hui la clé privée du facteur « cet appareil ». Le
 * serveur ne la voit jamais, ne la range nulle part, et n'a aucun moyen d'y
 * ajouter du coût. **Le seul rempart contre qui vole le blob chiffré est donc
 * la KDF elle-même**, et c'est pour ça qu'elle doit être mémoire-dure.
 *
 * Le même mot mémorisé sert aux deux, avec deux rôles distincts. Ce n'est pas
 * une redondance : c'est la raison pour laquelle l'arbitrage KDF de l'un ne se
 * transporte pas sur l'autre.
 *
 * ── Pourquoi Argon2id et pas PBKDF2 ────────────────────────────────────────
 *
 * L'exception PBKDF2 du projet est SelfVault, et elle énonce sa propre condition
 * de validité : « à 103 bits TIRÉS AU SORT, une KDF mémoire-dure n'achète rien ;
 * son intérêt est de rattraper les secrets CHOISIS PAR UN HUMAIN ». SelfVault n'a
 * aucun secret choisi, par construction.
 *
 * Le mot mémorisé, lui, est choisi par un humain — c'est sa définition. La
 * condition tombe, donc l'exception ne couvre pas ce fichier. Argon2id, qui est
 * la règle du projet, s'applique.
 *
 * ── L'implémentation vient d'à côté ────────────────────────────────────────
 *
 * `crypto.subtle` n'expose ni Argon2id ni aucune KDF mémoire-dure. `argon2id.js`,
 * dans ce même répertoire, la fournit — écrite ici, lisible, et confrontée aux
 * vecteurs de libsodium. Chargez-la par une balise `<script>` avant ce fichier.
 *
 * `srKdfPoserImplementation(fn)` accepte la vôtre à la place — native, WebAssembly,
 * autre — à la seule condition qu'elle reproduise `tests/vecteurs-argon2.json`.
 *
 * ── Le blob porte sa KDF ───────────────────────────────────────────────────
 *
 * La version et les paramètres de dérivation sont DANS le blob. Un blob se relit
 * donc sous ses propres paramètres, et s'écrit sous le profil courant. Un format
 * qui ne dit pas sous quoi il a été produit ne se migre pas : on ne peut rien
 * changer sans rendre illisible ce qui existe.
 */

'use strict';

(function (global) {
  /**
   * Le profil d'ÉCRITURE. Figé, et volontairement pas configurable par appel :
   * un paramètre qu'on peut choisir est un paramètre que quelqu'un choisira mal.
   *
   * `t=3, m=64 Mio, p=1` — celui de SelfDataGuard, pour une raison qui n'est pas
   * l'habitude. `p=1` est le SEUL degré de parallélisme que `sodium_crypto_pwhash`
   * sache produire : libsodium le force. C'est donc le seul profil dont l'oracle
   * PHP puisse vérifier les vecteurs, et donc le seul qui permette de tenir deux
   * langages d'accord sur les mêmes empreintes.
   *
   * ── 🔑 La mémoire ne se baisse pas pour gagner du temps ────────────────────
   *
   * Cette dérivation prend environ une seconde sur une machine de bureau, et
   * plusieurs sur un téléphone : `argon2id.js` est du JavaScript, qui n'a pas
   * d'entiers 64 bits et doit reconstruire cent millions de multiplications à la
   * main. La tentation évidente est de descendre `m` à 32 Mio.
   *
   * **Elle a été pesée et écartée le 10/09/2026, et voici pourquoi elle est un
   * mauvais échange.** Le temps que NOUS mettons ne coûte rien à un attaquant :
   * lui n'utilisera pas ce fichier, mais une implémentation C ou un GPU. Ce qui
   * lui coûte, c'est `m` — 64 Mio par essai s'imposent à toutes les
   * implémentations, y compris la sienne. Diviser la mémoire par deux divise donc
   * par deux le prix d'une attaque, pour économiser une demi-seconde chez
   * l'utilisateur légitime. On échangerait de la sécurité contre du confort.
   *
   * Si l'attente devient un problème, le levier est ailleurs : sortir l'appel du
   * fil principal (Web Worker). Le temps reste, la page cesse de se figer.
   */
  const PROFIL = Object.freeze({ alg: 'argon2id', t: 3, m: 65536, p: 1, dkLen: 32 });

  /**
   * La version ÉCRITE par `srKdfChiffrer`.
   *
   * ⚠️ La monter ne suffit pas à garder les anciens blobs lisibles : il faut
   * aussi laisser leur version dans `VERSIONS_LUES` ci-dessous. Le premier jet de
   * ce fichier annonçait ici « change = les anciens blobs restent lisibles »
   * alors que la lecture comparait par égalité stricte — le commentaire invitait
   * à casser tous les enrôlements existants.
   */
  const BLOB_VERSION = 1;

  /**
   * Les versions que `srKdfDechiffrer` sait relire — **elle inclut toujours celle
   * qu'on écrit**, et elle ne se vide pas quand on en ajoute une.
   *
   * 🔑 C'est ce qui rend la promesse du versionnage vraie. Porter la version dans
   * le blob permet de savoir CE QU'ON LIT ; encore faut-il un chemin qui accepte
   * de le lire. Sans cette liste, « le blob porte sa version » ne serait qu'une
   * étiquette sur un format qu'on ne saurait plus ouvrir.
   *
   * Une version se retire d'ici quand on décide de ne plus la relire — c'est un
   * choix explicite, qui se paie en ré-enrôlements.
   */
  const VERSIONS_LUES = Object.freeze([1]);

  /**
   * Le PLANCHER DE LECTURE, distinct du profil d'écriture — et il doit le rester.
   *
   * Un blob dit sous quels paramètres il a été produit, et on le relit sous
   * ceux-là : c'est ce qui rend une migration possible. Mais accepter n'importe
   * quels paramètres reviendrait à accepter `t=1, m=8` d'un blob remplacé, donc à
   * laisser quelqu'un affaiblir la KDF depuis le stockage local.
   *
   * Le jour où le profil d'écriture monte, ce plancher NE monte pas avec lui —
   * sinon la montée rendrait illisible tout ce qui a été écrit avant elle, ce qui
   * est exactement le défaut que le versionnage ferme.
   */
  const PLANCHER = Object.freeze({ t: 3, m: 65536, p: 1 });

  /**
   * Le sel fait 16 octets, ni plus ni moins, et ce n'est pas un choix de style :
   * `sodium_crypto_pwhash` n'accepte que cette longueur (mesuré — un sel de 15
   * octets lève). Un sel d'une autre taille produirait des blobs qu'aucun oracle
   * PHP ne pourrait vérifier, donc des blobs dont personne ne pourrait dire s'ils
   * sont conformes.
   */
  const SEL_OCTETS = 16;

  const enc = new TextEncoder();

  const hex = (buf) =>
    [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('');

  function deHex(h) {
    const a = new Uint8Array(h.length / 2);
    for (let i = 0; i < a.length; i++) {
      a[i] = parseInt(h.substr(i * 2, 2), 16);
    }

    return a;
  }

  const b64u = (buf) =>
    btoa(String.fromCharCode(...new Uint8Array(buf)))
      .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

  function deB64u(s) {
    let t = s.replace(/-/g, '+').replace(/_/g, '/');
    while (t.length % 4) {
      t += '=';
    }
    const bin = atob(t);
    const a = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) {
      a[i] = bin.charCodeAt(i);
    }

    return a;
  }

  /** L'implémentation posée à la main, si quelqu'un en a posé une. */
  let implementation = null;

  /**
   * Pose une implémentation d'Argon2id.
   *
   * @param {(mot: Uint8Array, sel: Uint8Array, p: {t:number,m:number,p:number,dkLen:number})
   *          => Promise<Uint8Array>} fn
   *        `m` est en KIBIOCTETS — l'unité de PHP, de LUKS et de la RFC 9106.
   *        `sodium_crypto_pwhash` prend des octets : c'est la conversion qui
   *        rate le plus souvent, et un facteur 1024 sur la mémoire ne se voit
   *        pas au résultat, seulement au coût.
   */
  function srKdfPoserImplementation(fn) {
    // `null` rend la main à `argon2id.js`. Sans ce retour, une implémentation
    // posée une fois ne se retire plus — et une sonde qui veut éprouver les deux
    // doit relancer un processus par implémentation.
    if (fn === null) {
      implementation = null;

      return;
    }
    if (typeof fn !== 'function') {
      throw new Error("srKdf : une implémentation est une fonction, ou null pour revenir à celle d'argon2id.js.");
    }
    implementation = fn;
  }

  /** L'implémentation à utiliser : celle qu'on a posée, sinon celle d'à côté. */
  function argon2id() {
    if (implementation) {
      return implementation;
    }

    if (typeof global.srArgon2id === 'function') {
      // `srArgon2id` est synchrone ; le contrat est asynchrone pour laisser la
      // place à une implémentation qui ne le serait pas.
      return async (mot, sel, p) => global.srArgon2id(mot, sel, p);
    }

    throw new Error(
      "srKdf : aucune implémentation d'Argon2id. Chargez `argon2id.js` avant " +
      'ce fichier, ou posez la vôtre avec srKdfPoserImplementation(fn) — elle ' +
      'doit reproduire tests/vecteurs-argon2.json.',
    );
  }

  /** Un sel : 16 octets, rendus en 32 hexadécimaux. */
  function srKdfEngendrerSel() {
    return hex(global.crypto.getRandomValues(new Uint8Array(SEL_OCTETS)));
  }

  function verifierParametres(p, ou) {
    if (p.alg !== 'argon2id') {
      throw new Error(`srKdf : ${ou} — algorithme « ${p.alg} » inconnu, seul argon2id est accepté.`);
    }

    // 🔑 Absent et trop faible sont deux pannes différentes, et les confondre
    // envoie chercher au mauvais endroit : « t=undefined sous le plancher »
    // laisse croire à un paramètre mal réglé là où il manque purement.
    const manquants = ['t', 'm', 'p'].filter((c) => typeof p[c] !== 'number');
    if (manquants.length > 0) {
      throw new Error(
        `srKdf : ${ou} — paramètres absents (${manquants.join(', ')}). ` +
        'Un blob qui ne dit pas sous quelle KDF il a été produit est illisible : ' +
        'refus de deviner.',
      );
    }

    if (!(p.t >= PLANCHER.t && p.m >= PLANCHER.m && p.p >= PLANCHER.p)) {
      throw new Error(
        `srKdf : ${ou} — paramètres sous le plancher (t=${p.t} m=${p.m} p=${p.p}, ` +
        `plancher t=${PLANCHER.t} m=${PLANCHER.m} p=${PLANCHER.p}). Refus : ` +
        'des paramètres affaiblis dans un blob affaibliraient la dérivation.',
      );
    }
  }

  /**
   * Dérive la clé de chiffrement. Rend (Promise) 32 octets.
   *
   * @param {string} mot     le mot mémorisé — ne sort pas de cette fonction
   * @param {string} selHex  32 hexadécimaux (16 octets) — OBLIGATOIRE
   * @param {{t:number,m:number,p:number,dkLen:number,alg:string}} [profil]
   *        les paramètres d'un blob relu ; par défaut le profil d'écriture
   */
  async function srKdfDeriver(mot, selHex, profil) {
    if (typeof mot !== 'string' || mot === '') {
      throw new Error('srKdf : le mot mémorisé est obligatoire et non vide.');
    }
    if (typeof selHex !== 'string' || !/^[0-9a-f]{32}$/.test(selHex)) {
      throw new Error(
        'srKdf : sel obligatoire — 32 caractères hexadécimaux (16 octets), ' +
        'engendré par srKdfEngendrerSel. Sans lui, deux personnes qui ont choisi ' +
        'le même mot produisent la même clé.',
      );
    }

    const p = Object.assign({ dkLen: PROFIL.dkLen }, profil || PROFIL);
    verifierParametres(p, 'dérivation');

    return argon2id()(enc.encode(mot), deHex(selHex), p);
  }

  /**
   * Chiffre `octets` sous le mot mémorisé. Rend (Promise) le blob à ranger.
   *
   * Le blob est un objet ordinaire, sérialisable en JSON. Il porte sa version et
   * ses paramètres : c'est ce qui permettra de le relire après une montée de
   * profil, et de savoir ce qu'on lit.
   */
  async function srKdfChiffrer(mot, octets) {
    const selHex = srKdfEngendrerSel();
    const brut = await srKdfDeriver(mot, selHex);
    const cle = await global.crypto.subtle.importKey(
      'raw', brut, { name: 'AES-GCM' }, false, ['encrypt'],
    );
    const iv = global.crypto.getRandomValues(new Uint8Array(12));
    const ct = await global.crypto.subtle.encrypt({ name: 'AES-GCM', iv }, cle, octets);

    return {
      v: BLOB_VERSION,
      kdf: { alg: PROFIL.alg, t: PROFIL.t, m: PROFIL.m, p: PROFIL.p },
      sel: selHex,
      iv: hex(iv),
      ct: b64u(ct),
    };
  }

  /**
   * Déchiffre un blob. Rend (Promise) les octets, ou lève.
   *
   * 🔑 Un blob sans `v` n'est pas un blob corrompu : c'est un blob écrit avant
   * que ce format existe. Le dire explicitement importe, parce que la seule issue
   * est un ré-enrôlement — et qu'un message générique enverrait l'utilisateur
   * chercher un mot de passe qu'il n'a pas oublié.
   */
  async function srKdfDechiffrer(mot, blob) {
    if (!blob || typeof blob !== 'object') {
      throw new Error('srKdf : blob absent ou illisible.');
    }
    if (blob.v === undefined) {
      throw new Error(
        'srKdf : blob sans version — écrit avant le format versionné, ' +
        'avec une KDF que rien ne permet de retrouver. Cet appareil doit être ' +
        'ré-enrôlé ; le mot mémorisé, lui, est inchangé.',
      );
    }
    if (!VERSIONS_LUES.includes(blob.v)) {
      throw new Error(
        `srKdf : blob de version ${blob.v} — cette bibliothèque lit ` +
        `${VERSIONS_LUES.join(', ')} et écrit ${BLOB_VERSION}.`,
      );
    }
    if (!blob.kdf || typeof blob.kdf !== 'object') {
      throw new Error('srKdf : blob versionné sans paramètres de KDF — refus de deviner.');
    }
    if (typeof blob.iv !== 'string' || !/^[0-9a-f]{24}$/.test(blob.iv)) {
      throw new Error('srKdf : nonce absent ou mal formé (24 hexadécimaux attendus).');
    }

    const p = Object.assign({ dkLen: PROFIL.dkLen }, blob.kdf);
    const brut = await srKdfDeriver(mot, blob.sel, p);
    const cle = await global.crypto.subtle.importKey(
      'raw', brut, { name: 'AES-GCM' }, false, ['decrypt'],
    );

    return new Uint8Array(await global.crypto.subtle.decrypt(
      { name: 'AES-GCM', iv: deHex(blob.iv) }, cle, deB64u(blob.ct),
    ));
  }

  global.srKdfDeriver = srKdfDeriver;
  global.srKdfChiffrer = srKdfChiffrer;
  global.srKdfDechiffrer = srKdfDechiffrer;
  global.srKdfEngendrerSel = srKdfEngendrerSel;
  global.srKdfPoserImplementation = srKdfPoserImplementation;
  global.SR_KDF_PROFIL = PROFIL;
  global.SR_KDF_BLOB_VERSION = BLOB_VERSION;

  // Pour la sonde, qui tourne sous node et n'a pas de `window`.
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
      srKdfDeriver, srKdfChiffrer, srKdfDechiffrer, srKdfEngendrerSel,
      srKdfPoserImplementation, PROFIL, PLANCHER, BLOB_VERSION, VERSIONS_LUES, SEL_OCTETS,
    };
  }
})(typeof globalThis !== 'undefined' ? globalThis : this);
