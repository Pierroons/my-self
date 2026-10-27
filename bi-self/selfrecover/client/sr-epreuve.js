/**
 * SelfRecover — l'épreuve du niveau 3, résolue côté navigateur.
 *
 * Derrière un service caché, l'adresse ne dit rien : `Escalade` ne peut pas
 * freiner un appelant, seulement faire payer chaque ouverture de dossier. Elle
 * rend une épreuve (`Escalade::epreuve()`, ou le champ `epreuve` d'un refus) ;
 * ce script cherche une solution — de 1 à 32 caractères hexadécimaux — telle que
 * SHA-256(epreuve + ':' + solution) commence par `difficulte` bits nuls, puis
 * l'application la renvoie à `ouvrir()` avec l'épreuve.
 *
 * Le coût moyen est de 2^difficulte hachages : 16 bits, 65 536 hachages. Il
 * monte d'un bit quand la charge du service, ou l'insistance sur un même nom,
 * monte. `tools/mesure-epreuve.html` mesure ce que ce navigateur en fait par
 * seconde.
 *
 * Usage :
 *   const solution = await srResoudreEpreuve(reponse.epreuve);
 *   // puis renvoyer { epreuve: reponse.epreuve.epreuve, solution }
 */
(function (global) {
  'use strict';

  /** Hachages lancés ensemble : WebCrypto répond en promesses, un à un serait lent. */
  const LOT = 256;

  function bitsNuls(octets) {
    let bits = 0;
    for (const o of octets) {
      if (o === 0) {
        bits += 8;
        continue;
      }
      return bits + Math.clz32(o) - 24;
    }
    return bits;
  }

  /**
   * @param {{epreuve: string, difficulte: number}} e
   * @param {(essais: number) => void} [progres] appelé après chaque lot
   * @returns {Promise<string>} la solution, en hexadécimal
   */
  async function srResoudreEpreuve(e, progres) {
    const enc = new TextEncoder();
    const prefixe = e.epreuve + ':';
    for (let n = 0; ; n += LOT) {
      const lot = [];
      for (let i = 0; i < LOT; i++) {
        lot.push(global.crypto.subtle.digest('SHA-256', enc.encode(prefixe + (n + i).toString(16))));
      }
      const empreintes = await Promise.all(lot);
      for (let i = 0; i < LOT; i++) {
        if (bitsNuls(new Uint8Array(empreintes[i])) >= e.difficulte) {
          return (n + i).toString(16);
        }
      }
      if (progres) {
        progres(n + LOT);
      }
    }
  }

  global.srResoudreEpreuve = srResoudreEpreuve;

  // Pour la sonde, qui tourne sous node et n'a pas de `window`.
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { srResoudreEpreuve, bitsNuls };
  }
})(typeof globalThis !== 'undefined' ? globalThis : this);
