/*
 * Éprouve `client/sr-derive.js` — l'implémentation RÉELLEMENT LIVRÉE — contre
 * les vecteurs figés.
 *
 * Usage : node tests/derivation.js
 * Sort 0 si tout passe, 1 sinon. Aucune dépendance : node nu.
 *
 * 🔑 C'est la moitié qui compte. L'oracle PHP de `derivation.php` prouve que les
 * vecteurs sont calculables ; celle-ci prouve que le fichier que les
 * intégrateurs chargent dans leur page produit bien les mêmes. Les deux sondes
 * confrontées à la même vérité écrite une fois : si l'une bouge, elle seule
 * rougit, et le vecteur dit laquelle.
 *
 * Le mode est éprouvé jusqu'à son refus : `srDerive` sans mode doit LEVER. Un
 * défaut silencieux serait choisi par tous les intégrateurs, ce qui reviendrait
 * à trancher à leur place un arbitrage qui dépend de leur transport.
 */
const fs = require('fs');
const path = require('path');

const racine = path.join(__dirname, '..');
const doc = JSON.parse(fs.readFileSync(path.join(__dirname, 'vecteurs-derivation.json'), 'utf8'));

// Le navigateur que le dériveur attend. `location` est posé par cas : c'est la
// seule façon d'éprouver le mode hostname hors d'un navigateur, et le fait qu'il
// LISE location plutôt que de recevoir son matériel est justement la propriété.
globalThis.location = { hostname: '' };
require(path.join(racine, 'client', 'sr-derive.js'));

/** Un sel valide, pour les contrôles qui n'éprouvent pas le sel. */
const SEL = 'a1'.repeat(16);

let echecs = 0;
const verdict = (quoi, ok, detail = '') => {
    console.log(`  ${ok ? 'ok    ' : 'RATE  '} ${quoi}${detail ? ' — ' + detail : ''}`);
    if (!ok) echecs++;
};

(async () => {
    console.log("── L'implémentation livrée retrouve-t-elle les vecteurs ? ──");
    for (const v of doc.vecteurs) {
        let obtenu;
        if (v.mode === 'hostname') {
            globalThis.location.hostname = v.materiel;
            obtenu = await srDerive(v.mot, v.sel, { mode: 'hostname' });
        } else {
            obtenu = await srDerive(v.mot, v.sel, { mode: 'label', label: v.materiel });
        }
        verdict(v.quoi, obtenu === v.empreinte,
            obtenu === v.empreinte ? '' : `${obtenu.slice(0, 16)}… attendu ${v.empreinte.slice(0, 16)}…`);
    }

    console.log('\n── Le mode est obligatoire, et son absence LÈVE ──────────');
    globalThis.location.hostname = 'exemple.test';
    const leve = async (quoi, appel) => {
        try {
            await appel();
            verdict(quoi, false, "n'a pas levé — un défaut silencieux tranche à la place de l'intégrateur");
        } catch (e) {
            verdict(quoi, true, e.message.slice(0, 52) + '…');
        }
    };
    await leve('sans options du tout',      () => srDerive('mot', SEL));
    await leve('avec un objet vide',        () => srDerive('mot', SEL, {}));
    await leve('avec un mode inconnu',      () => srDerive('mot', SEL, { mode: 'domaine' }));
    await leve("mode label sans label",     () => srDerive('mot', SEL, { mode: 'label' }));
    await leve('mode label vide',           () => srDerive('mot', SEL, { mode: 'label', label: '' }));

    console.log('\n── Ce que le dériveur doit REFUSER ──────────────────────');
    // 🔑 Un jeu de vecteurs qui ne contient que des cas valides n'éprouve jamais
    // qu'une chose se ferme. La première version du dériveur tolérait un sel vide
    // — `(sel || '')` — et rendait une empreinte parfaitement valide, non conforme
    // à la spécification, sans un mot. Ces cas-là sont la sonde de ce défaut.
    for (const r of doc.refus) {
        globalThis.location.hostname = r.materiel;
        const opts = r.mode === 'hostname' ? { mode: 'hostname' } : { mode: 'label', label: r.materiel };
        await leve(r.quoi, () => srDerive(r.mot, r.sel, opts));
    }

    console.log('\n── Le mode hostname LIT le navigateur, il ne le reçoit pas ─');
    // 🔑 Le cœur de l'anti-hameçonnage, et le défaut exact trouvé dans une des
    // intégrations : un matériel que le serveur fournit est un matériel que
    // N'IMPORTE QUEL serveur peut fournir — celui d'une page qui vous imite
    // compris. On vérifie donc qu'aucune option ne permet de l'imposer.
    globalThis.location.hostname = 'vrai.test';
    const attendu = await srDerive('mot', SEL, { mode: 'hostname' });
    const tente = await srDerive('mot', SEL, { mode: 'hostname', label: 'imitateur.test' });
    verdict('🔑 un label fourni NE PEUT PAS remplacer le hostname', attendu === tente,
        'sinon un site hostile imposerait le matériel de sa cible');

    globalThis.location.hostname = 'imitateur.test';
    const ailleurs = await srDerive('mot', SEL, { mode: 'hostname' });
    verdict("🔑 changer d'hôte change l'empreinte", attendu !== ailleurs,
        'la propriété entière');

    console.log("\n── Sans navigateur, le mode hostname refuse ─────────────");
    const sauve = globalThis.location;
    globalThis.location = undefined;
    await leve('hors contexte navigateur', () => srDerive('mot', SEL, { mode: 'hostname' }));
    globalThis.location = sauve;

    console.log('\n── Le sel ────────────────────────────────────────────────');
    globalThis.location.hostname = 'exemple.test';
    const sels = new Set();
    for (let i = 0; i < 200; i++) sels.add(srEngendrerSel());
    verdict('200 sels engendrés, 200 distincts', sels.size === 200, `${sels.size} distincts`);
    verdict('chacun fait 32 hexadécimaux',
        [...sels].every((s) => /^[0-9a-f]{32}$/.test(s)));

    const a = await srDerive('mot', srEngendrerSel(), { mode: 'hostname' });
    const b = await srDerive('mot', srEngendrerSel(), { mode: 'hostname' });
    verdict('deux sels différents donnent deux empreintes', a !== b);

    console.log('\n── La version vit dans le message ────────────────────────');
    verdict(`la version exposée est celle des vecteurs (${doc.version})`,
        SR_DERIVE_VERSION === doc.version, SR_DERIVE_VERSION);

    console.log('');
    if (echecs === 0) {
        console.log(`  ${doc.vecteurs.length} vecteurs, l'implémentation livrée les retrouve tous.`);
        process.exit(0);
    }
    console.log(`  ${echecs} contrôle(s) en échec.`);
    process.exit(1);
})();
