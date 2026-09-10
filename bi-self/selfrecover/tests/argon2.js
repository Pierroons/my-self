/*
 * Éprouve `client/sr-kdf.js` — l'implémentation RÉELLEMENT LIVRÉE — contre les
 * vecteurs figés, et contre ce qu'elle doit refuser.
 *
 * Usage : node tests/argon2.js
 * Sort 0 si tout passe, 1 sinon. Aucune dépendance : l'implémentation éprouvée
 * est `client/argon2id.js`, écrite dans ce dépôt.
 *
 * 🔑 C'est la moitié qui compte. `argon2.php` prouve que les vecteurs sont
 * calculables ; celle-ci prouve que le fichier chargé par les intégrateurs
 * produit les mêmes — avec une implémentation qui ne partage pas une ligne avec
 * libsodium. Deux sondes, deux langages, une vérité écrite une fois : si l'une
 * bouge, elle seule rougit.
 *
 * Le refus est éprouvé autant que le calcul. Un jeu qui ne contiendrait que des
 * cas valides ne dirait jamais qu'une porte se ferme.
 */
const fs = require('fs');
const path = require('path');

const racine = path.join(__dirname, '..');
const doc = JSON.parse(fs.readFileSync(path.join(__dirname, 'vecteurs-argon2.json'), 'utf8'));

// Chargé AVANT le vendor, délibérément : le premier contrôle éprouve ce que fait
// `sr-kdf.js` quand aucune implémentation n'est là.
const kdf = require(path.join(racine, 'client', 'sr-kdf.js'));

let echecs = 0;
const verdict = (quoi, ok, detail = '') => {
    console.log(`  ${ok ? 'ok    ' : 'RATE  '} ${quoi}${detail ? ' — ' + detail : ''}`);
    if (!ok) echecs++;
};

/**
 * Un contrôle qui peut lever sans que ce soit le but.
 *
 * 🔑 Sans ce garde, une exception inattendue TUE la sonde : le processus sort en
 * 1 — donc la CI voit rouge — mais aucune ligne ne dit quel contrôle a cédé, et
 * tous les suivants ne sont jamais exécutés. Un seul défaut en masque alors une
 * dizaine.
 */
async function abrite(quoi, fn) {
    try {
        return await fn();
    } catch (e) {
        verdict(quoi, false, `a levé : ${String(e.message).slice(0, 120)}`);

        return undefined;
    }
}

/** Vrai si `fn` lève, et si le message porte `motif` quand il est donné. */
async function leve(quoi, fn, motif) {
    try {
        await fn();
        verdict(quoi, false, "n'a pas levé");
    } catch (e) {
        const ok = !motif || new RegExp(motif, 'i').test(e.message);
        verdict(quoi, ok, ok ? '' : `message inattendu : ${e.message}`);
    }
}

const hex = (u8) => [...u8].map((b) => b.toString(16).padStart(2, '0')).join('');
const SEL = 'a1'.repeat(16);

(async () => {
    // 🔑 Une boucle sur un tableau vide ne rougit pas. Sans ce plancher, un
    // fichier de vecteurs vidé rendrait exactement le vert d'un dépôt sain.
    console.log('── Le jeu de vecteurs est-il seulement là ? ───────────────');
    verdict('au moins 7 vecteurs', (doc.vecteurs || []).length >= 7,
        `${(doc.vecteurs || []).length} présent(s)`);
    verdict('au moins 8 cas de refus', (doc.refus || []).length >= 8,
        `${(doc.refus || []).length} présent(s)`);

    console.log('\n── Sans implémentation, la dérivation refuse ──────────────');
    // 🔑 Le refus doit ENSEIGNER : un « erreur interne » laisserait l'intégrateur
    // chercher dans son code un défaut qui est un fichier non chargé.
    await leve("l'absence d'Argon2id lève, et dit quoi charger",
        () => kdf.srKdfDeriver('chaise-nuage-tambour', SEL), 'argon2id\\.js');

    // En navigateur, `argon2id.js` pose `srArgon2id` sur le global par une balise
    // script. Sous node il rend son objet : on pose le global à la main.
    globalThis.srArgon2id = require(path.join(racine, 'client', 'argon2id.js')).argon2id;

    // ⚠️ Le banc annonce l'implémentation qu'il a réellement exercée, et la CI
    // grep cette ligne. Sans elle, une implémentation absente ou remplacée par
    // un repli laisserait le banc mesurer autre chose que ce que les
    // intégrateurs chargent — en rendant exactement le même vert.
    console.log(`▸ implémentation exercée : ${typeof globalThis.srArgon2id === 'function'
        ? 'argon2id.js (écrite dans ce dépôt)' : "AUCUNE — argon2id.js ne fournit rien"}`);

    console.log("\n── L'implémentation livrée retrouve-t-elle les vecteurs ? ──");
    for (const v of doc.vecteurs) {
        const brut = await abrite(v.quoi, () => kdf.srKdfDeriver(v.mot, v.sel,
            { alg: doc.alg, t: v.t, m: v.m, p: v.p, dkLen: v.dkLen }));
        if (brut === undefined) continue;
        const obtenu = hex(brut);
        verdict(v.quoi, obtenu === v.cle,
            obtenu === v.cle ? '' : `${obtenu.slice(0, 16)}… attendu ${v.cle.slice(0, 16)}…`);
    }

    console.log('\n── Ce que la dérivation doit refuser ──────────────────────');
    for (const r of doc.refus) {
        const profil = r.t !== undefined || r.alg !== undefined
            ? { alg: r.alg || doc.alg, t: r.t, m: r.m, p: r.p, dkLen: 32 }
            : undefined;
        await leve('« ' + r.quoi + ' »', () => kdf.srKdfDeriver(r.mot, r.sel, profil));
    }

    console.log('\n── Ces refus sont-ils tenus ICI, ou par le vendor ? ───────');
    // 🔑 Le contrôle d'au-dessus ne dit pas QUI refuse. Mesuré en retirant la
    // garde sur le mot vide : la sonde restait verte, parce que l'implémentation lève
    // de son côté (« Password must be specified »). Le contrôle visait donc la
    // propriété du vendor, et serait resté vert si `sr-kdf.js` n'avait rien gardé.
    //
    // On pose ici une implémentation qui accepte TOUT. Ce qui lève encore ne peut
    // venir que de `sr-kdf.js` — et si l'un de ces refus disparaissait, il
    // rougirait ici sans rougir plus haut.
    kdf.srKdfPoserImplementation(async (_mot, _sel, p) => new Uint8Array(p.dkLen).fill(1));
    for (const r of doc.refus) {
        const profil = r.t !== undefined || r.alg !== undefined
            ? { alg: r.alg || doc.alg, t: r.t, m: r.m, p: r.p, dkLen: 32 }
            : undefined;
        await leve('« ' + r.quoi + ' » — tenu par sr-kdf.js',
            () => kdf.srKdfDeriver(r.mot, r.sel, profil), 'srKdf');
    }
    kdf.srKdfPoserImplementation(null);

    console.log('\n── Le profil livré est-il celui des vecteurs ? ────────────');
    for (const [champ, attendu] of Object.entries(doc.profil_ecriture)) {
        verdict(`profil d'écriture ${champ} = ${attendu}`, kdf.PROFIL[champ] === attendu,
            String(kdf.PROFIL[champ]));
    }
    verdict(`algorithme = ${doc.alg}`, kdf.PROFIL.alg === doc.alg, kdf.PROFIL.alg);
    for (const [champ, attendu] of Object.entries(doc.plancher_lecture)) {
        verdict(`plancher de lecture ${champ} = ${attendu}`, kdf.PLANCHER[champ] === attendu,
            String(kdf.PLANCHER[champ]));
    }

    console.log('\n── Le sel ────────────────────────────────────────────────');
    const sels = new Set();
    for (let i = 0; i < 200; i++) sels.add(kdf.srKdfEngendrerSel());
    verdict('200 sels engendrés, 200 distincts', sels.size === 200, `${sels.size} distincts`);
    verdict('chacun fait 32 hexadécimaux', [...sels].every((s) => /^[0-9a-f]{32}$/.test(s)));

    console.log('\n── Le blob : aller-retour, et ce qu\'il porte ─────────────');
    const secret = new TextEncoder().encode('clé privée factice');
    const blob = await abrite('le chiffrement aboutit',
        () => kdf.srKdfChiffrer('chaise-nuage-tambour', secret));
    const relu = blob && await abrite('le déchiffrement aboutit',
        () => kdf.srKdfDechiffrer('chaise-nuage-tambour', blob));
    verdict('ce qui est chiffré se relit à l\'identique',
        !!relu && Buffer.compare(Buffer.from(relu), Buffer.from(secret)) === 0);
    if (!blob) {
        console.log("  (blob absent : les contrôles qui en dépendent sont sautés)");
    }

    verdict(`le blob porte sa version (v=${kdf.BLOB_VERSION})`, !!blob && blob.v === kdf.BLOB_VERSION);
    verdict('le blob porte ses paramètres de KDF',
        !!blob && !!blob.kdf && blob.kdf.alg === 'argon2id' && blob.kdf.t === kdf.PROFIL.t
        && blob.kdf.m === kdf.PROFIL.m && blob.kdf.p === kdf.PROFIL.p,
        JSON.stringify(blob && blob.kdf));
    const second = blob && await abrite('un second chiffrement aboutit',
        () => kdf.srKdfChiffrer('chaise-nuage-tambour', secret));
    verdict('le sel du blob est neuf à chaque chiffrement',
        !!second && second.sel !== blob.sel);
    verdict('le blob est sérialisable en JSON',
        !!blob && typeof JSON.parse(JSON.stringify(blob)).ct === 'string');

    console.log('\n── Ce que le déchiffrement doit refuser ───────────────────');
    await leve('un autre mot mémorisé ne déchiffre pas',
        () => kdf.srKdfDechiffrer('chaise-nuage-fenetre', blob));

    // 🔑 Le blob de `demo/lab/` tel qu'il existe aujourd'hui. Il ne doit pas
    // produire un message générique : la seule issue est un ré-enrôlement, et
    // l'utilisateur doit l'apprendre plutôt que de croire son mot oublié.
    const ancien = { credentialId: 'ab'.repeat(16), salt: SEL, iv: '00'.repeat(12), ct: 'AAAA' };
    await leve("un blob d'avant le versionnage est nommé pour ce qu'il est",
        () => kdf.srKdfDechiffrer('chaise-nuage-tambour', ancien), 'enrôl');

    await leve('une version de blob inconnue lève',
        () => kdf.srKdfDechiffrer('chaise-nuage-tambour', { ...blob, v: 99 }), 'version');
    // 🔑 Écrire une version qu'on ne sait pas relire rendrait illisible tout ce
    // qui vient d'être enrôlé. Rien dans le code ne l'empêche : seule cette
    // sonde le dit.
    verdict('la version écrite fait partie des versions lues',
        kdf.VERSIONS_LUES.includes(kdf.BLOB_VERSION),
        `écrit ${kdf.BLOB_VERSION}, lit ${kdf.VERSIONS_LUES.join(', ')}`);
    await leve('un blob versionné sans paramètres de KDF lève',
        () => kdf.srKdfDechiffrer('chaise-nuage-tambour', { ...blob, kdf: undefined }), 'refus de deviner');
    await leve('un blob dont la KDF est sous le plancher lève',
        () => kdf.srKdfDechiffrer('chaise-nuage-tambour', { ...blob, kdf: { ...blob.kdf, m: 8 } }), 'plancher');
    await leve('un nonce mal formé lève',
        () => kdf.srKdfDechiffrer('chaise-nuage-tambour', { ...blob, iv: 'ff' }), 'nonce');
    await leve('un blob absent lève', () => kdf.srKdfDechiffrer('mot', null));

    console.log('\n── Une implémentation posée à la main est bien appelée ────');
    // Le contrat annonce qu'un intégrateur peut fournir la sienne. Sans ce
    // contrôle, l'annonce ne serait qu'une phrase de documentation.
    let vue = null;
    kdf.srKdfPoserImplementation(async (mot, sel, p) => {
        vue = { mot: new TextDecoder().decode(mot), sel: hex(sel), ...p };

        return new Uint8Array(p.dkLen).fill(7);
    });
    const bidon = await kdf.srKdfDeriver('chaise-nuage-tambour', SEL);
    verdict("l'implémentation posée reçoit le mot, le sel et les paramètres",
        vue && vue.mot === 'chaise-nuage-tambour' && vue.sel === SEL
        && vue.t === kdf.PROFIL.t && vue.m === kdf.PROFIL.m && vue.dkLen === 32,
        JSON.stringify(vue));
    verdict('sa sortie est bien celle qui est rendue', hex(bidon) === '07'.repeat(32));
    await leve('poser autre chose qu\'une fonction lève', () => kdf.srKdfPoserImplementation(42));
    kdf.srKdfPoserImplementation(null);
    verdict("null rend la main à argon2id.js",
        hex(await kdf.srKdfDeriver(doc.vecteurs[0].mot, doc.vecteurs[0].sel)) === doc.vecteurs[0].cle);

    console.log('');
    if (echecs === 0) {
        console.log(`  ${doc.vecteurs.length} vecteurs et ${doc.refus.length} refus, l'implémentation livrée les tient tous.`);
        process.exit(0);
    }
    console.log(`  ${echecs} contrôle(s) en échec.`);
    process.exit(1);
})();
