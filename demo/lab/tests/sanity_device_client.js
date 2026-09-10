/*
 * Éprouve `public/js/sr-device.js` — le facteur « cet appareil » du lab — en
 * exécutant réellement l'enrôlement puis la récupération.
 *
 * Usage : node demo/lab/tests/sanity_device_client.js
 * Sort 0 si tout passe, 1 sinon. Aucune dépendance hors du dépôt.
 *
 * Les autres bancs du lab sont en PHP et s'arrêtent à l'API : le chemin client —
 * chiffrer la clé privée, la ranger, la relire — n'est couvert que par celui-ci.
 *
 * Le navigateur est remplacé par le strict nécessaire : `localStorage`, `fetch`
 * et `window`. Ce qui est éprouvé est le VRAI fichier, pas une réécriture.
 */
const fs = require('fs');
const path = require('path');

const lab = path.join(__dirname, '..');
const racine = path.join(lab, '..', '..');

let echecs = 0;
const verdict = (quoi, ok, detail = '') => {
    console.log(`  ${ok ? 'ok    ' : 'RATE  '} ${quoi}${detail ? ' — ' + detail : ''}`);
    if (!ok) echecs++;
};

// ── Le navigateur, réduit à ce que sr-device.js touche ───────────────────────

const magasin = new Map();
globalThis.localStorage = {
    getItem: (c) => (magasin.has(c) ? magasin.get(c) : null),
    setItem: (c, v) => magasin.set(c, String(v)),
    removeItem: (c) => magasin.delete(c),
};
globalThis.location = { hostname: 'lab.exemple.test' };
globalThis.window = globalThis;

/**
 * Le serveur, réduit à ce que le facteur attend de lui.
 *
 * ⚠️ Il ne VÉRIFIE rien : la signature ECDSA est contrôlée par
 * `sanity_device.php`, côté bibliothèque. Ce qu'on éprouve ici est ce que le
 * client envoie et ce qu'il fait de la réponse — pas le protocole lui-même.
 */
const vus = { enroles: [], defis: [], finis: [] };
globalThis.fetch = async (url, options) => {
    const corps = options && options.body ? JSON.parse(options.body) : {};
    if (url === '/api/sel.php') {
        return { json: async () => ({ ok: true, sel: 'a1'.repeat(16) }) };
    }
    if (url === '/api/device_enroll.php') {
        vus.enroles.push(corps);

        return { json: async () => ({ ok: true, message: 'enrôlé' }) };
    }
    if (url === '/api/device_auth_begin.php') {
        // ⚠️ On mémorise le défi RENDU : la requête, elle, ne porte pas de champ
        // `challenge`. Pousser `corps` seul rendrait le contrôle aval tautologique,
        // en comparant deux `undefined`.
        const defi = 'defi-' + corps.credential_id.slice(0, 8);
        vus.defis.push({ ...corps, challenge: defi });

        return { json: async () => ({ ok: true, challenge: defi }) };
    }
    if (url === '/api/device_auth_finish.php') {
        vus.finis.push(corps);

        return { json: async () => ({ ok: true, message: 'récupéré' }) };
    }
    throw new Error('route inattendue : ' + url);
};

// L'ordre de chargement est celui des pages : Argon2id, la KDF, puis le facteur.
globalThis.srArgon2id = require(path.join(racine, 'bi-self/selfrecover/client/argon2id.js')).argon2id;
require(path.join(racine, 'bi-self/selfrecover/client/sr-kdf.js'));
require(path.join(racine, 'bi-self/selfrecover/client/sr-derive.js'));
require(path.join(lab, 'public/js/sr-device.js'));

const MOT = 'chaise-nuage-tambour';

(async () => {
    console.log("── Les pages chargent-elles ce qu'il faut, dans l'ordre ? ─");
    // 🔑 Un banc qui charge lui-même les bons fichiers ne dit rien des pages
    // servies : elles pourraient les avoir oubliés. On lit donc les pages.
    for (const page of ['recover.php', 'register.php']) {
        const html = fs.readFileSync(path.join(lab, 'public', page), 'utf8');
        const rang = (f) => html.indexOf(`/js/${f}`);
        verdict(`${page} charge argon2id, sr-kdf puis sr-device, dans cet ordre`,
            rang('argon2id.js') > -1
            && rang('argon2id.js') < rang('sr-kdf.js')
            && rang('sr-kdf.js') < rang('sr-device.js'));
    }
    // 🔑 La KDF étant du JavaScript ordinaire, la CSP n'a RIEN eu à ouvrir. Ce
    // contrôle rougirait si une implémentation WebAssembly revenait par la
    // bande, en exigeant sa directive.
    const csp = fs.readFileSync(path.join(lab, 'lib', 'security.php'), 'utf8');
    verdict("la CSP n'ouvre aucune directive pour la KDF",
        !/script-src[^;]*unsafe-eval/.test(csp),
        'ni wasm-unsafe-eval, ni unsafe-eval');

    console.log("\n── L'enrôlement ──────────────────────────────────────────");
    const r = await srDeviceEnroll('alice', MOT);
    verdict("l'enrôlement aboutit", r.ok === true);
    verdict('le serveur a reçu une clé publique et une dérivation, jamais le mot',
        vus.enroles.length === 1
        && typeof vus.enroles[0].public_key === 'string'
        && /^[0-9a-f]{64}$/.test(vus.enroles[0].memorized_derived_key)
        && !JSON.stringify(vus.enroles[0]).includes(MOT));

    const range = JSON.parse(localStorage.getItem('srdev_alice'));
    verdict('le blob rangé porte sa version et sa KDF',
        range.blob && range.blob.v === 1 && range.blob.kdf.alg === 'argon2id',
        JSON.stringify(range.blob && range.blob.kdf));
    verdict('et il ne porte plus les champs de l\'ancien format',
        range.salt === undefined && range.ct === undefined && range.iv === undefined);
    verdict('rien de ce qui est rangé ne contient le mot mémorisé',
        !localStorage.getItem('srdev_alice').includes(MOT));
    verdict('srDeviceHas voit cet appareil', srDeviceHas('alice') === true);
    verdict("et ne voit rien pour un autre compte", srDeviceHas('bob') === false);

    console.log('\n── La récupération ───────────────────────────────────────');
    const ok = await srDeviceRecover('alice', MOT);
    verdict('le bon mot mémorisé récupère', ok.ok === true);
    verdict('une signature a bien été envoyée sur le défi reçu',
        vus.finis.length === 1 && vus.finis[0].challenge === vus.defis[0].challenge
        && typeof vus.finis[0].signature === 'string' && vus.finis[0].signature.length > 40);

    const faux = await srDeviceRecover('alice', 'chaise-nuage-fenetre');
    verdict('un mot faux ne récupère pas', faux.ok === false);
    verdict('et le refus parle du mot, pas du contenu rangé',
        /mot mémorisé incorrect/i.test(faux.message), faux.message);

    const absent = await srDeviceRecover('bob', MOT);
    verdict("un compte sans appareil enrôlé le dit", absent.ok === false
        && /aucun appareil/i.test(absent.message));

    console.log('\n── Les blobs écrits avant le versionnage ─────────────────');
    // 🔑 Ce qu'un utilisateur du lab a réellement dans son navigateur aujourd'hui.
    // Le distinguer d'un mot faux n'est pas cosmétique : sans ça, il chercherait
    // un mot qu'il n'a pas oublié.
    magasin.set('srdev_carol', JSON.stringify({
        credentialId: 'ab'.repeat(16), salt: 'a1'.repeat(16), iv: '00'.repeat(12), ct: 'AAAA',
    }));
    const ancien = await srDeviceRecover('carol', MOT);
    verdict('un blob de l\'ancien format ne récupère pas', ancien.ok === false);
    verdict('et le refus invite à ré-enrôler, sans accuser le mot',
        /ré-enrôle/i.test(ancien.message) && !/incorrect/i.test(ancien.message),
        ancien.message);

    console.log('\n── Un blob affaibli depuis le stockage ───────────────────');
    // Le stockage local est modifiable par qui atteint le poste. Un blob dont on
    // abaisse les paramètres ne doit pas faire dériver moins cher.
    const abaisse = JSON.parse(localStorage.getItem('srdev_alice'));
    abaisse.blob.kdf = { ...abaisse.blob.kdf, m: 8, t: 1 };
    magasin.set('srdev_dave', JSON.stringify(abaisse));
    const faible = await srDeviceRecover('dave', MOT);
    verdict('un blob aux paramètres abaissés est refusé', faible.ok === false);
    verdict("et le refus dit que c'est le contenu, pas le mot",
        /inutilisable/i.test(faible.message), faible.message);

    console.log('');
    if (echecs === 0) {
        console.log('  Le facteur « cet appareil » enrôle, récupère, et refuse ce qu\'il doit.');
        process.exit(0);
    }
    console.log(`  ${echecs} contrôle(s) en échec.`);
    process.exit(1);
})();
