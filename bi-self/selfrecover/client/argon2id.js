/**
 * Argon2id (RFC 9106) et BLAKE2b (RFC 7693) — implémentation de la bibliothèque.
 *
 * ── Pourquoi ce fichier existe, alors qu'on n'écrit pas sa crypto ──────────
 *
 * La règle tient toujours : personne ne devrait réimplémenter une primitive
 * cryptographique sans une raison sérieuse et un moyen de vérifier. Les deux
 * sont réunis ici, et il faut les nommer, parce qu'ils ne le sont pas ailleurs.
 *
 * **La raison.** `crypto.subtle` n'expose aucune KDF mémoire-dure. Le seul autre
 * chemin est d'embarquer un binaire tiers dans un dépôt public : un module
 * WebAssembly encodé en base64, que personne ne peut relire. Ce fichier se lit.
 *
 * **Le moyen de vérifier, et c'est lui qui rend la chose acceptable.** Argon2id
 * a une réponse connue. `tests/vecteurs-argon2.json` porte des empreintes
 * produites par libsodium — une implémentation en C, écrite par d'autres,
 * auditée, et qui ne partage pas une ligne avec celle-ci. Sept vecteurs, dont
 * quatre qui ne diffèrent que par un paramètre. Une implémentation fausse ne
 * peut pas les retrouver par hasard.
 *
 * C'est la différence entre écrire de la crypto et écrire une crypto vérifiable.
 * Le jour où l'oracle indépendant disparaît, cet argument tombe avec lui.
 *
 * ── Ce que ce fichier ne prétend pas être ──────────────────────────────────
 *
 * ⚠️ **Il n'est pas à temps constant, et il ne peut pas l'être.** JavaScript ne
 * donne aucun contrôle sur le ramasse-miettes ni sur la compilation à la volée.
 * Ce n'est pas un renoncement propre à ce fichier : aucune implémentation
 * JavaScript ne l'offre, WebAssembly compris. Argon2id est conçu pour que la
 * première moitié de sa première passe soit indépendante des données — c'est ce
 * qui le distingue d'Argon2d — mais le reste ne l'est pas, et un attaquant qui
 * mesure les temps DANS le navigateur de sa victime a déjà gagné autrement.
 *
 * Il n'efface pas non plus sa mémoire de travail de façon garantie : un
 * `TypedArray` mis à zéro peut avoir été recopié ailleurs par le ramasse-miettes.
 * On met à zéro quand même — c'est mieux que rien, ce n'est pas une garantie.
 *
 * ── L'arithmétique 64 bits ─────────────────────────────────────────────────
 *
 * Argon2 et BLAKE2b travaillent sur des entiers de 64 bits. JavaScript n'en a
 * pas : ses nombres sont des flottants à 53 bits de mantisse, et `BigInt` coûte
 * un ordre de grandeur. Chaque mot de 64 bits est donc porté par DEUX entiers de
 * 32 bits dans un `Uint32Array`, l'octet de poids faible d'abord — index pair
 * pour la moitié basse, impair pour la haute.
 *
 * C'est ce qui rend le code plus verbeux que la RFC. Chaque `a + b` de la
 * spécification devient quatre lignes, et chaque rotation en devient six.
 */

'use strict';

(function (global) {
  // ── BLAKE2b (RFC 7693) ────────────────────────────────────────────────────

  /** Les huit mots d'initialisation : les décimales de √2, √3, √5… en 64 bits. */
  const IV = new Uint32Array([
    0xf3bcc908, 0x6a09e667, 0x84caa73b, 0xbb67ae85,
    0xfe94f82b, 0x3c6ef372, 0x5f1d36f1, 0xa54ff53a,
    0xade682d1, 0x510e527f, 0x2b3e6c1f, 0x9b05688c,
    0xfb41bd6b, 0x1f83d9ab, 0x137e2179, 0x5be0cd19,
  ]);

  /** L'ordre dans lequel chaque tour lit les seize mots du message. */
  const SIGMA = new Uint8Array([
    0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15,
    14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3,
    11, 8, 12, 0, 5, 2, 15, 13, 10, 14, 3, 6, 7, 1, 9, 4,
    7, 9, 3, 1, 13, 12, 11, 14, 2, 6, 5, 10, 4, 0, 15, 8,
    9, 0, 5, 7, 2, 4, 10, 15, 14, 1, 11, 12, 6, 8, 3, 13,
    2, 12, 6, 10, 0, 11, 8, 3, 4, 13, 7, 5, 15, 14, 1, 9,
    12, 5, 1, 15, 14, 13, 4, 10, 0, 7, 6, 3, 9, 2, 8, 11,
    13, 11, 7, 14, 12, 1, 3, 9, 5, 0, 15, 4, 8, 6, 2, 10,
    6, 15, 14, 9, 11, 3, 0, 8, 12, 2, 13, 7, 1, 4, 10, 5,
    10, 2, 8, 4, 7, 6, 1, 5, 15, 11, 9, 14, 3, 12, 13, 0,
    // Les tours 11 et 12 reprennent les permutations 0 et 1.
    0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15,
    14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3,
  ]);

  /**
   * L'état de travail de la compression : seize mots de 64 bits.
   *
   * Il vit hors des fonctions parce qu'elles sont appelées des dizaines de
   * milliers de fois par dérivation — allouer à chaque tour dominerait le coût.
   * Conséquence à connaître : **ce fichier n'est pas réentrant**. Deux
   * dérivations menées en parallèle dans le même contexte s'écraseraient. En
   * pratique la fonction est synchrone du début à la fin, donc rien ne
   * s'intercale ; changer cela demanderait de rendre cet état local.
   */
  const v = new Uint32Array(32);
  const m = new Uint32Array(32);

  /**
   * Le mélange G de BLAKE2b, sur les mots d'indices a, b, c, d de `v`.
   *
   * Les indices reçus sont ceux des MOTS ; on les double pour atteindre les
   * moitiés basse et haute. Les quatre rotations (32, 24, 16, 63) sont écrites
   * à la main : une rotation de 64 bits portée par deux entiers de 32 n'a pas
   * de forme générale efficace, chacune a la sienne.
   */
  function G(a, b, c, d, x, y) {
    const a0 = a * 2, b0 = b * 2, c0 = c * 2, d0 = d * 2;
    let lo, hi, t;

    // a = a + b + m[x]
    lo = (v[a0] + v[b0]) | 0;
    hi = (v[a0 + 1] + v[b0 + 1] + ((lo >>> 0) < (v[a0] >>> 0) ? 1 : 0)) | 0;
    t = (lo + m[x * 2]) | 0;
    hi = (hi + m[x * 2 + 1] + ((t >>> 0) < (lo >>> 0) ? 1 : 0)) | 0;
    v[a0] = t; v[a0 + 1] = hi;

    // d = rotr64(d ^ a, 32) — une rotation de 32 bits échange les deux moitiés.
    lo = v[d0] ^ v[a0];
    hi = v[d0 + 1] ^ v[a0 + 1];
    v[d0] = hi; v[d0 + 1] = lo;

    // c = c + d
    lo = (v[c0] + v[d0]) | 0;
    v[c0 + 1] = (v[c0 + 1] + v[d0 + 1] + ((lo >>> 0) < (v[c0] >>> 0) ? 1 : 0)) | 0;
    v[c0] = lo;

    // b = rotr64(b ^ c, 24)
    lo = v[b0] ^ v[c0];
    hi = v[b0 + 1] ^ v[c0 + 1];
    v[b0] = (lo >>> 24) | (hi << 8);
    v[b0 + 1] = (hi >>> 24) | (lo << 8);

    // a = a + b + m[y]
    lo = (v[a0] + v[b0]) | 0;
    hi = (v[a0 + 1] + v[b0 + 1] + ((lo >>> 0) < (v[a0] >>> 0) ? 1 : 0)) | 0;
    t = (lo + m[y * 2]) | 0;
    hi = (hi + m[y * 2 + 1] + ((t >>> 0) < (lo >>> 0) ? 1 : 0)) | 0;
    v[a0] = t; v[a0 + 1] = hi;

    // d = rotr64(d ^ a, 16)
    lo = v[d0] ^ v[a0];
    hi = v[d0 + 1] ^ v[a0 + 1];
    v[d0] = (lo >>> 16) | (hi << 16);
    v[d0 + 1] = (hi >>> 16) | (lo << 16);

    // c = c + d
    lo = (v[c0] + v[d0]) | 0;
    v[c0 + 1] = (v[c0 + 1] + v[d0 + 1] + ((lo >>> 0) < (v[c0] >>> 0) ? 1 : 0)) | 0;
    v[c0] = lo;

    // b = rotr64(b ^ c, 63) — soit une rotation à GAUCHE de 1, moins coûteuse.
    lo = v[b0] ^ v[c0];
    hi = v[b0 + 1] ^ v[c0 + 1];
    v[b0] = (lo << 1) | (hi >>> 31);
    v[b0 + 1] = (hi << 1) | (lo >>> 31);
  }

  /** Un état BLAKE2b : h (8 mots), le compteur d'octets, le tampon. */
  function nouvelEtat(longueurSortie) {
    const etat = {
      h: new Uint32Array(16),
      t: 0,                       // octets absorbés
      tampon: new Uint8Array(128),
      remplissage: 0,
      sortie: longueurSortie,
    };
    etat.h.set(IV);
    // Le paramétrage tient dans le premier mot : longueur de sortie, pas de clé,
    // arité 1, profondeur 1.
    etat.h[0] ^= 0x01010000 ^ longueurSortie;

    return etat;
  }

  function compresser(etat, bloc, decalage, dernier) {
    for (let i = 0; i < 32; i++) {
      m[i] = bloc[decalage + i * 4]
        | (bloc[decalage + i * 4 + 1] << 8)
        | (bloc[decalage + i * 4 + 2] << 16)
        | (bloc[decalage + i * 4 + 3] << 24);
    }
    v.set(etat.h, 0);
    v.set(IV, 16);

    // Le compteur est un entier de 128 bits dans la spécification. Ici il tient
    // dans un `Number` : on ne hache jamais plus de quelques mégaoctets d'un
    // coup, et 2⁵³ octets sont hors de portée.
    v[24] ^= etat.t | 0;
    v[25] ^= Math.floor(etat.t / 0x100000000) | 0;
    if (dernier) {
      v[28] = ~v[28];
      v[29] = ~v[29];
    }

    for (let tour = 0; tour < 12; tour++) {
      const s = tour * 16;
      G(0, 4, 8, 12, SIGMA[s], SIGMA[s + 1]);
      G(1, 5, 9, 13, SIGMA[s + 2], SIGMA[s + 3]);
      G(2, 6, 10, 14, SIGMA[s + 4], SIGMA[s + 5]);
      G(3, 7, 11, 15, SIGMA[s + 6], SIGMA[s + 7]);
      G(0, 5, 10, 15, SIGMA[s + 8], SIGMA[s + 9]);
      G(1, 6, 11, 12, SIGMA[s + 10], SIGMA[s + 11]);
      G(2, 7, 8, 13, SIGMA[s + 12], SIGMA[s + 13]);
      G(3, 4, 9, 14, SIGMA[s + 14], SIGMA[s + 15]);
    }
    for (let i = 0; i < 16; i++) {
      etat.h[i] ^= v[i] ^ v[i + 16];
    }
  }

  function absorber(etat, octets) {
    for (let i = 0; i < octets.length; i++) {
      // 🔑 Le bloc plein n'est compressé qu'à l'arrivée du suivant : BLAKE2b
      // marque le DERNIER bloc, et on ne sait qu'il l'est qu'en n'ayant plus
      // rien à absorber. Compresser dès que le tampon est plein donnerait un
      // résultat faux pour toute entrée multiple de 128 octets.
      if (etat.remplissage === 128) {
        etat.t += 128;
        compresser(etat, etat.tampon, 0, false);
        etat.remplissage = 0;
      }
      etat.tampon[etat.remplissage++] = octets[i];
    }
  }

  function terminer(etat) {
    etat.t += etat.remplissage;
    etat.tampon.fill(0, etat.remplissage);
    compresser(etat, etat.tampon, 0, true);

    const sortie = new Uint8Array(etat.sortie);
    for (let i = 0; i < etat.sortie; i++) {
      sortie[i] = (etat.h[i >> 2] >>> (8 * (i & 3))) & 0xff;
    }

    return sortie;
  }

  /** BLAKE2b en une passe. `entrees` est une liste de `Uint8Array`. */
  function blake2b(entrees, longueurSortie) {
    const etat = nouvelEtat(longueurSortie);
    for (const e of entrees) {
      absorber(etat, e);
    }

    return terminer(etat);
  }

  /** Un entier 32 bits en petit-boutiste, comme la spécification l'exige. */
  function le32(n) {
    return new Uint8Array([n & 0xff, (n >>> 8) & 0xff, (n >>> 16) & 0xff, (n >>> 24) & 0xff]);
  }

  /**
   * H′, la fonction de hachage à sortie variable d'Argon2 (§3.3 de la RFC).
   *
   * Au-delà de 64 octets, BLAKE2b ne suffit plus : on chaîne des blocs de 64 et
   * on n'en garde que les 32 premiers octets à chaque étape, sauf le dernier.
   * Ce chevauchement est ce qui empêche de retrouver un bloc depuis le suivant.
   */
  function hPrime(entrees, longueurSortie) {
    const prefixe = le32(longueurSortie);
    if (longueurSortie <= 64) {
      return blake2b([prefixe, ...entrees], longueurSortie);
    }

    const sortie = new Uint8Array(longueurSortie);
    let bloc = blake2b([prefixe, ...entrees], 64);
    sortie.set(bloc.subarray(0, 32), 0);

    const r = Math.ceil(longueurSortie / 32) - 2;
    for (let i = 1; i < r; i++) {
      bloc = blake2b([bloc], 64);
      sortie.set(bloc.subarray(0, 32), i * 32);
    }
    sortie.set(blake2b([bloc], longueurSortie - 32 * r), r * 32);

    return sortie;
  }

  // ── Argon2 (RFC 9106) ─────────────────────────────────────────────────────

  /** Un bloc fait 1024 octets, soit 128 mots de 64 bits, soit 256 entiers de 32. */
  const MOTS_BLOC = 256;

  /**
   * Combien de positions un bloc d'adresses porte : 128, une par mot de 64 bits.
   *
   * ⚠️ À ne pas confondre avec `MOTS_BLOC`, qui compte des entiers de 32. Les
   * confondre fait renouveler le bloc d'adresses une fois sur deux trop tard, et
   * lire au-delà de ce qu'il porte — sans que rien ne lève, un `TypedArray`
   * rendant `undefined` hors bornes, converti en 0.
   */
  const ADRESSES_PAR_BLOC = 128;

  /** L'état de travail du remplissage de bloc, alloué une fois (cf. `v`/`m`). */
  const R = new Uint32Array(MOTS_BLOC);
  const Z = new Uint32Array(MOTS_BLOC);

  /**
   * Le mélange d'Argon2 : le G de BLAKE2b, avec une multiplication en plus.
   *
   * 🔑 `a += b + 2·bas(a)·bas(b)` est la seule différence avec BLAKE2b, et elle
   * n'est pas décorative : la multiplication est ce qui rend une passe coûteuse
   * à paralléliser sur un circuit dédié. La retirer donnerait un résultat bien
   * formé, et une fonction sans sa propriété.
   *
   * ⚠️ **Le corps est déroulé, et c'est délibéré.** Les quatre mots vivent dans
   * des variables locales du début à la fin : `remplirBloc` appelle cette
   * fonction cent vingt-huit fois par bloc, et un bloc est calculé deux cent
   * mille fois pour une dérivation à 64 Mio sur trois passes. Repasser par le
   * tableau entre chaque étape coûtait un tiers du temps total, mesuré.
   *
   * La contrepartie est un code qu'on relit mal. Ce qu'il fait est exactement la
   * séquence de la RFC 9106 §3.5 — quatre mélanges, quatre rotations — et
   * `tests/argon2.js` le confronte aux vecteurs de libsodium.
   */
  function GB(x, ia, ib, ic, id) {
    let aL = x[ia], aH = x[ia + 1];
    let bL = x[ib], bH = x[ib + 1];
    let cL = x[ic], cH = x[ic + 1];
    let dL = x[id], dH = x[id + 1];
    let t, lo, hi, u, uh, w, wh, w0, w1, w2, mLo, mHi;

    // a += b + 2·bas(a)·bas(b)
    u = aL & 0xffff; uh = aL >>> 16;
    w = bL & 0xffff; wh = bL >>> 16;
    t = u * w; w0 = t & 0xffff;
    t = uh * w + (t >>> 16); w1 = t & 0xffff; w2 = t >>> 16;
    t = u * wh + w1;
    mLo = ((t << 16) | w0) >>> 0;
    mHi = (uh * wh + w2 + (t >>> 16)) >>> 0;
    lo = (aL + bL) | 0;
    hi = (aH + bH + ((lo >>> 0) < (aL >>> 0) ? 1 : 0)) | 0;
    t = (lo + ((mLo << 1) | 0)) | 0;
    aH = (hi + (((mHi << 1) | (mLo >>> 31)) | 0) + ((t >>> 0) < (lo >>> 0) ? 1 : 0)) | 0;
    aL = t;
    // d = rotr64(d ^ a, 32)
    lo = dL ^ aL; hi = dH ^ aH;
    dL = hi; dH = lo;
    // c += d + 2·bas(c)·bas(d)
    u = cL & 0xffff; uh = cL >>> 16;
    w = dL & 0xffff; wh = dL >>> 16;
    t = u * w; w0 = t & 0xffff;
    t = uh * w + (t >>> 16); w1 = t & 0xffff; w2 = t >>> 16;
    t = u * wh + w1;
    mLo = ((t << 16) | w0) >>> 0;
    mHi = (uh * wh + w2 + (t >>> 16)) >>> 0;
    lo = (cL + dL) | 0;
    hi = (cH + dH + ((lo >>> 0) < (cL >>> 0) ? 1 : 0)) | 0;
    t = (lo + ((mLo << 1) | 0)) | 0;
    cH = (hi + (((mHi << 1) | (mLo >>> 31)) | 0) + ((t >>> 0) < (lo >>> 0) ? 1 : 0)) | 0;
    cL = t;
    // b = rotr64(b ^ c, 24)
    lo = bL ^ cL; hi = bH ^ cH;
    bL = (lo >>> 24) | (hi << 8);
    bH = (hi >>> 24) | (lo << 8);
    // a += b + 2·bas(a)·bas(b)
    u = aL & 0xffff; uh = aL >>> 16;
    w = bL & 0xffff; wh = bL >>> 16;
    t = u * w; w0 = t & 0xffff;
    t = uh * w + (t >>> 16); w1 = t & 0xffff; w2 = t >>> 16;
    t = u * wh + w1;
    mLo = ((t << 16) | w0) >>> 0;
    mHi = (uh * wh + w2 + (t >>> 16)) >>> 0;
    lo = (aL + bL) | 0;
    hi = (aH + bH + ((lo >>> 0) < (aL >>> 0) ? 1 : 0)) | 0;
    t = (lo + ((mLo << 1) | 0)) | 0;
    aH = (hi + (((mHi << 1) | (mLo >>> 31)) | 0) + ((t >>> 0) < (lo >>> 0) ? 1 : 0)) | 0;
    aL = t;
    // d = rotr64(d ^ a, 16)
    lo = dL ^ aL; hi = dH ^ aH;
    dL = (lo >>> 16) | (hi << 16);
    dH = (hi >>> 16) | (lo << 16);
    // c += d + 2·bas(c)·bas(d)
    u = cL & 0xffff; uh = cL >>> 16;
    w = dL & 0xffff; wh = dL >>> 16;
    t = u * w; w0 = t & 0xffff;
    t = uh * w + (t >>> 16); w1 = t & 0xffff; w2 = t >>> 16;
    t = u * wh + w1;
    mLo = ((t << 16) | w0) >>> 0;
    mHi = (uh * wh + w2 + (t >>> 16)) >>> 0;
    lo = (cL + dL) | 0;
    hi = (cH + dH + ((lo >>> 0) < (cL >>> 0) ? 1 : 0)) | 0;
    t = (lo + ((mLo << 1) | 0)) | 0;
    cH = (hi + (((mHi << 1) | (mLo >>> 31)) | 0) + ((t >>> 0) < (lo >>> 0) ? 1 : 0)) | 0;
    cL = t;
    // b = rotr64(b ^ c, 63)
    lo = bL ^ cL; hi = bH ^ cH;
    bL = (lo << 1) | (hi >>> 31);
    bH = (hi << 1) | (lo >>> 31);

    x[ia] = aL; x[ia + 1] = aH;
    x[ib] = bL; x[ib + 1] = bH;
    x[ic] = cL; x[ic + 1] = cH;
    x[id] = dL; x[id + 1] = dH;
  }

  /**
   * La permutation P : seize mots de 64 bits, HUIT mélanges.
   *
   * ⚠️ C'est le même ordonnancement que le tour de BLAKE2b — quatre mélanges en
   * colonnes, puis quatre en diagonales — et c'est ce qui le rend facile à
   * transposer à moitié. Le premier jet n'en faisait que quatre : le résultat
   * était parfaitement bien formé, de la bonne longueur, et faux.
   *
   * Deux variantes plutôt qu'une fonction à seize indices : les rangées lisent
   * seize mots consécutifs, les colonnes des paires espacées d'une rangée. La
   * séparation évite une fonction à dix-sept arguments et rend les deux accès
   * lisibles ; le gain de temps, lui, est marginal — c'est la lisibilité qui la
   * justifie, pas la vitesse.
   *
   * Les indices sont ceux des moitiés BASSES ; `+1` atteint la haute.
   */
  function Prangee(x, b) {
    GB(x, b, b + 8, b + 16, b + 24);
    GB(x, b + 2, b + 10, b + 18, b + 26);
    GB(x, b + 4, b + 12, b + 20, b + 28);
    GB(x, b + 6, b + 14, b + 22, b + 30);
    GB(x, b, b + 10, b + 20, b + 30);
    GB(x, b + 2, b + 12, b + 22, b + 24);
    GB(x, b + 4, b + 14, b + 16, b + 26);
    GB(x, b + 6, b + 8, b + 18, b + 28);
  }

  /** La même permutation, sur une colonne : des paires espacées d'une rangée. */
  function Pcolonne(x, b) {
    GB(x, b, b + 64, b + 128, b + 192);
    GB(x, b + 2, b + 66, b + 130, b + 194);
    GB(x, b + 32, b + 96, b + 160, b + 224);
    GB(x, b + 34, b + 98, b + 162, b + 226);
    GB(x, b, b + 66, b + 160, b + 226);
    GB(x, b + 2, b + 96, b + 162, b + 192);
    GB(x, b + 32, b + 98, b + 128, b + 194);
    GB(x, b + 34, b + 64, b + 130, b + 224);
  }

  /**
   * Le remplissage d'un bloc : `sortie = R ⊕ P(P(R))`, où `R = X ⊕ Y`.
   *
   * `avecXor` distingue la première passe des suivantes. À partir de la
   * deuxième, le nouveau bloc est XORé sur l'ancien au lieu de le remplacer —
   * c'est ce qui fait qu'une passe supplémentaire dépend de toutes les
   * précédentes, et non seulement de la dernière.
   */
  function remplirBloc(memoire, iX, iY, iSortie, avecXor) {
    for (let i = 0; i < MOTS_BLOC; i++) {
      R[i] = memoire[iX + i] ^ memoire[iY + i];
    }
    Z.set(R);

    // Huit tours sur les RANGÉES, puis huit sur les COLONNES. C'est ce
    // croisement qui diffuse un changement d'un octet à tout le bloc.
    for (let i = 0; i < 8; i++) {
      Prangee(Z, i * 32);
    }
    for (let i = 0; i < 8; i++) {
      Pcolonne(Z, i * 4);
    }

    if (avecXor) {
      for (let i = 0; i < MOTS_BLOC; i++) {
        memoire[iSortie + i] ^= R[i] ^ Z[i];
      }
    } else {
      for (let i = 0; i < MOTS_BLOC; i++) {
        memoire[iSortie + i] = R[i] ^ Z[i];
      }
    }
  }

  /**
   * Le produit de deux entiers de 32 bits, sur 64 bits.
   *
   * Elle sert à l'indexation, appelée une fois par bloc — là où `GB` l'a
   * déroulée parce qu'elle y tourne cent vingt-huit fois plus souvent.
   *
   * La décomposition en moitiés de 16 bits n'est pas un ornement : le produit de
   * deux entiers de 32 bits dépasse les 53 bits qu'un `Number` porte exactement,
   * et chaque produit partiel écrit ici reste sous 2³².
   */
  let mulLo = 0;
  let mulHi = 0;
  function mul32(a, b) {
    const aL = a & 0xffff, aH = a >>> 16;
    const bL = b & 0xffff, bH = b >>> 16;
    let t = aL * bL;
    const w0 = t & 0xffff;
    t = aH * bL + (t >>> 16);
    const w1 = t & 0xffff;
    const w2 = t >>> 16;
    t = aL * bH + w1;
    mulLo = ((t << 16) | w0) >>> 0;
    mulHi = (aH * bH + w2 + (t >>> 16)) >>> 0;
  }

  /**
   * L'indexation : quel bloc antérieur le mélange va lire.
   *
   * 🔑 C'est ici qu'Argon2**id** se distingue de ses deux frères, et le détail
   * décide de la propriété. Pendant la première moitié de la première passe, la
   * position se tire d'un compteur — donc indépendamment du mot de passe, donc
   * sans fuite par les accès mémoire. Ensuite elle se tire du bloc précédent,
   * ce qui interdit à un attaquant de précalculer le parcours.
   *
   * Prendre un seul des deux modes donnerait Argon2i ou Argon2d : deux fonctions
   * valides, aucune des deux n'étant celle que les vecteurs attendent.
   */
  function positionReference(J1, nbPossibles) {
    // La RFC calcule `nbPossibles - 1 - ((nbPossibles · (J1² >> 32)) >> 32)`.
    //
    // Ces deux décalages sont de l'arithmétique ENTIÈRE sur 64 bits. `J1 * J1`
    // monte à 2⁶⁴, au-delà des 2⁵³ qu'un `Number` porte exactement — mais on n'en
    // garde que les 32 bits de poids fort, qui tombent dans la partie exacte du
    // flottant. Écrit en `Math.floor`, ce calcul rend donc la même chose sur les
    // vecteurs du dépôt : mesuré, le résultat est identique.
    //
    // `mul32` est gardée quand même, parce que l'exactitude du flottant tient à
    // un raisonnement sur l'arrondi plutôt qu'à une propriété du type — et parce
    // qu'un `Math.floor` sort du chemin rapide du moteur.
    mul32(J1, J1);
    const x = mulHi;                // ⌊J1² / 2³²⌋
    mul32(nbPossibles, x);

    return nbPossibles - 1 - mulHi; // ⌊nbPossibles·x / 2³²⌋
  }

  /**
   * Argon2id. Rend `dkLen` octets.
   *
   * @param {Uint8Array} motDePasse
   * @param {Uint8Array} sel
   * @param {{t:number, m:number, p:number, dkLen:number}} p
   *        `m` en KIBIOCTETS, comme la RFC, PHP et LUKS. Pas en octets.
   */
  function argon2id(motDePasse, sel, params) {
    const passes = params.t;
    const voies = params.p;
    const sortieLen = params.dkLen;

    if (!(passes >= 1)) { throw new Error('argon2id : t doit valoir au moins 1.'); }
    if (!(voies >= 1)) { throw new Error('argon2id : p doit valoir au moins 1.'); }
    if (!(sortieLen >= 4)) { throw new Error('argon2id : dkLen doit valoir au moins 4.'); }
    if (sel.length < 8) { throw new Error('argon2id : le sel fait au moins 8 octets.'); }
    if (params.m < 8 * voies) {
      throw new Error(`argon2id : m doit valoir au moins 8·p (${8 * voies} KiB).`);
    }

    // La mémoire est arrondie au multiple inférieur de 4·p : quatre tranches par
    // passe, une frontière de synchronisation à chacune.
    const blocs = Math.floor(params.m / (4 * voies)) * 4 * voies;
    const parVoie = blocs / voies;             // colonnes d'une voie
    const parTranche = parVoie / 4;

    // ── H0 : tout ce qui paramètre la dérivation, condensé en 64 octets ──
    // Le type (2 = Argon2id) et la version en font partie : deux dérivations
    // qui ne diffèrent que par là ne se ressemblent en rien.
    const h0 = blake2b([
      le32(voies), le32(sortieLen), le32(params.m), le32(passes),
      le32(0x13), le32(2),
      le32(motDePasse.length), motDePasse,
      le32(sel.length), sel,
      le32(0), // pas de clé
      le32(0), // pas de données associées
    ], 64);

    const memoire = new Uint32Array(blocs * MOTS_BLOC);
    const graine = new Uint8Array(72);
    graine.set(h0, 0);

    for (let voie = 0; voie < voies; voie++) {
      for (let col = 0; col < 2; col++) {
        graine.set(le32(col), 64);
        graine.set(le32(voie), 68);
        const bloc = hPrime([graine], 1024);
        const base = (voie * parVoie + col) * MOTS_BLOC;
        for (let i = 0; i < MOTS_BLOC; i++) {
          memoire[base + i] = bloc[i * 4]
            | (bloc[i * 4 + 1] << 8) | (bloc[i * 4 + 2] << 16) | (bloc[i * 4 + 3] << 24);
        }
      }
    }

    // Les blocs d'adresses du mode indépendant, réutilisés d'un segment à l'autre.
    const entree = new Uint32Array(MOTS_BLOC);
    const adresses = new Uint32Array(MOTS_BLOC);
    const zero = new Uint32Array(MOTS_BLOC);
    const tampon = new Uint32Array(MOTS_BLOC * 3);

    function bloquerAdresses(passe, voie, tranche, compteur) {
      entree.fill(0);
      entree[0] = passe; entree[1] = 0;
      entree[2] = voie; entree[3] = 0;
      entree[4] = tranche; entree[5] = 0;
      entree[6] = blocs; entree[7] = 0;
      entree[8] = passes; entree[9] = 0;
      entree[10] = 2; entree[11] = 0;      // Argon2id
      entree[12] = compteur; entree[13] = 0;

      // `adresses = G(zero, G(zero, entree))`, la double application de la RFC.
      tampon.set(zero, 0);
      tampon.set(entree, MOTS_BLOC);
      remplirBloc(tampon, 0, MOTS_BLOC, MOTS_BLOC * 2, false);
      tampon.copyWithin(MOTS_BLOC, MOTS_BLOC * 2, MOTS_BLOC * 3);
      remplirBloc(tampon, 0, MOTS_BLOC, MOTS_BLOC * 2, false);
      adresses.set(tampon.subarray(MOTS_BLOC * 2, MOTS_BLOC * 3));
    }

    for (let passe = 0; passe < passes; passe++) {
      for (let tranche = 0; tranche < 4; tranche++) {
        for (let voie = 0; voie < voies; voie++) {
          // Argon2id : indépendant des données sur les deux premières tranches
          // de la première passe seulement.
          const independant = passe === 0 && tranche < 2;
          let compteur = 0;
          if (independant) {
            compteur = 1;
            bloquerAdresses(passe, voie, tranche, compteur);
          }

          const debut = (passe === 0 && tranche === 0) ? 2 : 0;
          for (let indice = debut; indice < parTranche; indice++) {
            const col = tranche * parTranche + indice;
            const precedente = col === 0 ? parVoie - 1 : col - 1;
            const iPrec = (voie * parVoie + precedente) * MOTS_BLOC;

            let J1, J2;
            if (independant) {
              const dans = indice % ADRESSES_PAR_BLOC;
              if (dans === 0 && indice !== 0) {
                compteur++;
                bloquerAdresses(passe, voie, tranche, compteur);
              }
              J1 = adresses[dans * 2] >>> 0;
              J2 = adresses[dans * 2 + 1] >>> 0;
            } else {
              J1 = memoire[iPrec] >>> 0;
              J2 = memoire[iPrec + 1] >>> 0;
            }

            const voieRef = voies === 1 ? voie : (J2 % voies);
            const memeVoie = voieRef === voie;

            // Combien de blocs sont éligibles : tout ce qui précède dans cette
            // voie, plus les tranches déjà terminées des autres.
            let nbPossibles;
            if (passe === 0) {
              if (tranche === 0 || memeVoie) {
                nbPossibles = col - 1;
              } else {
                nbPossibles = tranche * parTranche - (indice === 0 ? 1 : 0);
              }
            } else if (memeVoie) {
              nbPossibles = parVoie - parTranche + indice - 1;
            } else {
              nbPossibles = parVoie - parTranche - (indice === 0 ? 1 : 0);
            }

            const zz = positionReference(J1, nbPossibles);
            let depart;
            if (passe === 0) {
              depart = 0;
            } else {
              depart = (tranche === 3) ? 0 : (tranche + 1) * parTranche;
            }
            const colRef = (depart + zz) % parVoie;
            const iRef = (voieRef * parVoie + colRef) * MOTS_BLOC;
            const iSortie = (voie * parVoie + col) * MOTS_BLOC;

            remplirBloc(memoire, iPrec, iRef, iSortie, passe > 0);
          }
        }
      }
    }

    // ── Finalisation : le XOR des derniers blocs de chaque voie, puis H′ ──
    const dernier = new Uint8Array(1024);
    const acc = new Uint32Array(MOTS_BLOC);
    for (let voie = 0; voie < voies; voie++) {
      const base = (voie * parVoie + parVoie - 1) * MOTS_BLOC;
      for (let i = 0; i < MOTS_BLOC; i++) {
        acc[i] ^= memoire[base + i];
      }
    }
    for (let i = 0; i < MOTS_BLOC; i++) {
      dernier[i * 4] = acc[i] & 0xff;
      dernier[i * 4 + 1] = (acc[i] >>> 8) & 0xff;
      dernier[i * 4 + 2] = (acc[i] >>> 16) & 0xff;
      dernier[i * 4 + 3] = (acc[i] >>> 24) & 0xff;
    }
    const resultat = hPrime([dernier], sortieLen);

    // ⚠️ Effacement de bonne foi, pas garantie : le ramasse-miettes a pu recopier
    // ces tableaux ailleurs pendant le calcul, et rien en JavaScript ne permet de
    // le savoir. On efface ce qu'on peut atteindre.
    memoire.fill(0);
    acc.fill(0);
    dernier.fill(0);
    R.fill(0);
    Z.fill(0);
    v.fill(0);
    m.fill(0);

    return resultat;
  }

  global.srArgon2id = argon2id;
  global.srBlake2b = (entree, longueur) => blake2b([entree], longueur);

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { argon2id, blake2b, hPrime };
  }
})(typeof globalThis !== 'undefined' ? globalThis : this);
