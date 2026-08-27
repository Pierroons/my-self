/**
 * SelfRecover — dérivation du mot mémorisé, côté navigateur.
 *
 * 🔑 C'est le composant qui porte la propriété centrale du protocole : le mot
 * mémorisé ne quitte jamais ce navigateur. On en calcule
 * `HMAC-SHA256(clé = mot, message = matériel | version + sel)`, et seule cette
 * empreinte transite. Le serveur en range un Argon2id ; il ne peut pas remonter
 * au mot, même s'il le voulait.
 *
 * Le secret va en CLÉ, jamais en message — et il faut être exact sur la raison,
 * parce que ce fichier a d'abord porté la mauvaise. Ce n'est PAS une question
 * d'extension de longueur : HMAC est précisément construit pour y résister, son
 * hachage externe la ferme quel que soit le contenu du message. Les deux sens
 * coûtent d'ailleurs autant à qui veut deviner le mot, puisque tout le reste est
 * public — c'est Argon2id, côté serveur, qui porte ce coût-là.
 *
 * La raison est ailleurs, et elle suffit : c'est le contrat d'usage de HMAC, et
 * c'est ce que font les implémentations et les vecteurs figés de ce dépôt. Une
 * formule d'interopérabilité se choisit une fois, se documente, et ne se rediscute
 * plus — la rediscuter ici recréerait la divergence que ce fichier vient fermer.
 *
 * ── Pourquoi ce fichier existe ──────────────────────────────────────────────
 *
 * Jusqu'au 27/08/2026, la bibliothèque ne le livrait pas. Chaque intégrateur
 * écrivait donc lui-même le seul composant sur lequel repose l'anti-hameçonnage,
 * et les trois qui l'ont fait ont produit trois formules différentes — dont deux
 * sans la propriété, et l'une des deux l'affichant à ses utilisateurs comme
 * acquise. On ne peut pas promettre une propriété portée par un fichier qu'on
 * ne livre pas.
 *
 * ── Le mode est OBLIGATOIRE, et c'est le cœur de ce fichier ─────────────────
 *
 * Le matériel de dérivation décide de tout, et l'arbitrage dépend du transport.
 * La bibliothèque ne peut pas le trancher à la place de l'intégrateur, et ne
 * doit surtout pas le trancher par un défaut — un défaut est choisi par tout le
 * monde, donc c'est trancher sans le dire.
 *
 *   'hostname'  le nom d'hôte réel, lu dans le navigateur.
 *               ✓ anti-hameçonnage RÉEL : une page qui imite ce service obtient
 *                 une empreinte dérivée de SA propre adresse, sans valeur ici.
 *               ✗ perdre l'adresse, c'est perdre la récupération de niveau 2 de
 *                 tous les comptes. Sur le web ordinaire un domaine s'expire, se
 *                 perd, se rachète. Sur un service caché v3 l'adresse EST la clé
 *                 publique du service : elle ne dépend d'aucun registrar et ne
 *                 change que si l'on regénère la clé. Le prix n'est donc pas le
 *                 même selon où l'on sert.
 *
 *   'label'     une étiquette stable que vous fournissez.
 *               ✓ survit à un changement d'adresse.
 *               ✗ AUCUN anti-hameçonnage : une copie servie ailleurs, avec le
 *                 même label, produit exactement l'empreinte que votre serveur a
 *                 enregistrée. À ne choisir qu'en le sachant, et à ne pas
 *                 décrire comme protecteur du hameçonnage.
 *
 * ── Le format est FIGÉ ──────────────────────────────────────────────────────
 *
 * `<matériel>|v<N>` puis le sel, concaténés. La version vit DANS le message,
 * ce qui rend une migration possible : pendant une bascule, un service peut
 * dériver sous deux versions et accepter les deux. Ne pas changer cette forme
 * sans plan de migration — toutes les empreintes déjà enregistrées en dépendent.
 *
 * Le sel est par compte, engendré par le navigateur à l'inscription. Il n'est
 * pas secret : il empêche que deux personnes ayant choisi le même mot produisent
 * la même empreinte, donc qu'une table précalculée serve pour tout le service.
 */

'use strict';

(function (global) {
  /** Version du format de message. Change = toutes les empreintes changent. */
  const VERSION = 'v2';

  const hex = (buf) =>
    [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('');

  /**
   * Le matériel de dérivation, selon le mode. Rend une chaîne, ou lève.
   *
   * 🔑 En mode `hostname`, la valeur est lue dans `location`, JAMAIS reçue du
   * réseau. C'est toute la différence : un matériel que le serveur fournit est
   * un matériel que n'importe quel serveur peut fournir, y compris celui d'une
   * page qui vous imite. Une implémentation qui allait chercher son domaine par
   * `fetch` a existé dans ce dépôt, et affichait à ses utilisateurs qu'elle les
   * protégeait du hameçonnage.
   */
  function materiel(mode, label) {
    if (mode === 'hostname') {
      // `hostname` et non `origin` : un même service peut être servi en http et
      // en https, ou sur un autre port. Prendre l'origine complète ferait dériver
      // différemment selon le schéma, et casserait un compte créé sur l'un puis
      // récupéré sur l'autre. Le nom d'hôte porte à lui seul ce qui n'est pas
      // usurpable.
      const h = global.location && global.location.hostname;
      if (!h) {
        throw new Error("srDerive : mode 'hostname' hors d'un contexte navigateur.");
      }

      return h.toLowerCase();
    }

    if (mode === 'label') {
      if (typeof label !== 'string' || label === '') {
        throw new Error("srDerive : mode 'label' exige un label non vide.");
      }

      return label;
    }

    throw new Error(
      "srDerive : mode obligatoire — 'hostname' (anti-hameçonnage) ou 'label' " +
      "(stable, sans anti-hameçonnage). Pas de défaut : ce choix dépend de votre " +
      'transport et de ce que vous acceptez de perdre.',
    );
  }

  /**
   * Dérive le mot mémorisé. Rend (Promise) 64 caractères hexadécimaux.
   *
   * @param {string} mot    le mot mémorisé — ne sort pas de cette fonction
   * @param {string} sel    le sel du compte, 32 hexadécimaux — OBLIGATOIRE
   * @param {{mode: 'hostname'|'label', label?: string}} options
   */
  async function srDerive(mot, sel, options) {
    const opts = options || {};

    // 🔑 Le sel est EXIGÉ, pas toléré. La première version de ce fichier écrivait
    // `(sel || '')` : un appel sans sel passait en silence et rendait une
    // empreinte parfaitement valide, non conforme à la spécification, sans qu'un
    // mot le signale. C'était le défaut même que ce fichier existe pour fermer —
    // le mode exigé et levant juste au-dessus, le sel documenté et facultatif
    // deux lignes plus bas.
    //
    // Sans sel, deux personnes qui choisissent le même mot mémorisé produisent la
    // même empreinte, et une table précalculée sert alors pour tout le service.
    // Un sel par SITE ne suffit pas : il déplace la constante au lieu de saler.
    if (typeof sel !== 'string' || !/^[0-9a-f]{32}$/.test(sel)) {
        throw new Error(
          'srDerive : sel obligatoire — 32 caractères hexadécimaux, un par compte, ' +
          'engendré par le navigateur (srEngendrerSel). Il n\'est pas secret ; sans ' +
          'lui, le même mot mémorisé donne la même empreinte pour tout le monde.',
        );
    }

    const message = materiel(opts.mode, opts.label) + '|' + VERSION + sel;

    const cle = await global.crypto.subtle.importKey(
      'raw', new TextEncoder().encode(mot),
      { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'],
    );

    return hex(await global.crypto.subtle.sign('HMAC', cle, new TextEncoder().encode(message)));
  }

  /** Un sel de compte : 16 octets, rendus en 32 hexadécimaux. */
  function srEngendrerSel() {
    return hex(global.crypto.getRandomValues(new Uint8Array(16)));
  }

  global.srDerive = srDerive;
  global.srEngendrerSel = srEngendrerSel;
  global.SR_DERIVE_VERSION = VERSION;

  // Pour la sonde, qui tourne sous node et n'a pas de `window`.
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { srDerive, srEngendrerSel, VERSION, materiel };
  }
})(typeof globalThis !== 'undefined' ? globalThis : this);
