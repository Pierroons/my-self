// Ouvre un coffre SELFVAULT3 sous Node.
//
// ⚠️ Réimplémentation écrite depuis la seule notice imprimée du pli — les sections
// « Structure du coffre », « L'en-tête authentifié », « Le sceau » et « Comment on
// ouvre » de `pli/gabarit-pli.html`.
//
// Sa divergence de forme d'avec `pli/selfvault.html` est le seul intérêt du
// fichier : une copie hérite des défauts qu'elle devrait révéler et ne prouve que
// sa propre cohérence. Ne pas factoriser les deux, et ne pas transporter ici une
// tournure de l'autre — `tests/recouvrement.py` mesure le recouvrement et le banc
// refuse au-delà de son seuil.
//
// Ce qu'elle éprouve : que la notice SUFFIT. Tout ce qu'il a fallu deviner ici,
// et qui n'est pas écrit sur le papier, est un défaut de la notice.
//
// Usage  : node test_webcrypto.mjs <coffre.selfvault> <secret>
// Sortie : 0 si le coffre s'ouvre, 1 sinon, avec la cause nommée.
import { readFileSync } from 'node:fs';

const NOM_FORMAT = 'SELFVAULT3';
const JETONS = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
const BORNES_ITER = [100000, 10000000];
const BORNES_VERSION = [1, 99999];
const MAX_SERRURES = 8;

const depuisB64 = t => Buffer.from(t, 'base64');
const versB64 = o => Buffer.from(o).toString('base64');
const enOctets = t => Buffer.from(t, 'utf8');
const sortir = cause => { console.log('ÉCHEC — ' + cause); process.exit(1); };

const estB64 = v => typeof v === 'string' && /^[A-Za-z0-9+/]+={0,2}$/.test(v);
const estJour = v => typeof v === 'string' && /^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(v);
const estEntier = (v, [bas, haut]) => Number.isInteger(v) && v >= bas && v <= haut;
const estPoint = v => estB64(v) && depuisB64(v).length === 65 && depuisB64(v)[0] === 4;

// La notice décrit une STRUCTURE ; on la transcrit en table plutôt qu'en suite de
// tests. Chaque règle porte de quoi la lire seule : où regarder, ce qu'on exige,
// et ce qu'on dit quand ce n'est pas le cas.
// Le sceau ne couvre que les champs nommés par les deux chaînes canoniques : ce
// qui n'y figure pas n'est signé par rien, donc n'a pas sa place dans le fichier.
const CHAMPS = ['format', 'version', 'date', 'engagement', 'cle_publique',
                'serrures', 'nonce', 'contenu', 'signature'];
const CHAMPS_SERRURE = ['nom', 'sel', 'iterations', 'nonce', 'enveloppe'];
const inconnus = (o, connus) => Object.keys(o).filter(k => !connus.includes(k));

const REGLES_COFFRE = [
  [c => c.format === NOM_FORMAT, c => 'format inconnu : ' + c.format],
  [c => inconnus(c, CHAMPS).length === 0,
   c => "champ inconnu dans l'en-tête : " + inconnus(c, CHAMPS).join(', ')],
  [c => estEntier(c.version, BORNES_VERSION),
   c => `version : entier de ${BORNES_VERSION[0]} à ${BORNES_VERSION[1]} attendu, reçu ${c.version}`],
  [c => estJour(c.date), () => 'date : AAAA-MM-JJ attendu'],
  [c => estB64(c.engagement), () => "en-tête sans engagement de clé valide"],
  [c => estPoint(c.cle_publique),
   () => 'cle_publique : point P-256 non compressé attendu, 65 octets préfixés de 0x04'],
  [c => estB64(c.signature) && depuisB64(c.signature).length === 64,
   () => 'signature : 64 octets attendus — r et s sur 32 chacun'],
  [c => estB64(c.nonce), () => 'nonce du coffre : base64 attendu'],
  [c => estB64(c.contenu), () => 'contenu : base64 attendu'],
  [c => Array.isArray(c.serrures) && c.serrures.length > 0, () => 'coffre sans serrure'],
  [c => c.serrures.length <= MAX_SERRURES,
   c => `${c.serrures.length} serrures : le format en accepte ${MAX_SERRURES} au plus`],
];

const REGLES_SERRURE = [
  [s => inconnus(s, CHAMPS_SERRURE).length === 0,
   s => 'champ inconnu dans une serrure : ' + inconnus(s, CHAMPS_SERRURE).join(', ')],
  [s => typeof s.nom === 'string' && !/[|\n]/.test(s.nom),
   () => "nom de serrure invalide : « | » et le retour à la ligne y sont interdits"],
  [s => estB64(s.sel), s => `sel de « ${s.nom} » : base64 attendu`],
  [s => estB64(s.nonce), s => `nonce de « ${s.nom} » : base64 attendu`],
  [s => estB64(s.enveloppe), s => `enveloppe de « ${s.nom} » : base64 attendu`],
  [s => estEntier(s.iterations, BORNES_ITER),
   s => `nombre d'itérations hors bornes (${s.iterations}) — attendu entre `
        + `${BORNES_ITER[0]} et ${BORNES_ITER[1]}. Le fichier a été modifié.`],
];

function controlerForme(coffre) {
  for (const [tient, dire] of REGLES_COFFRE) if (!tient(coffre)) return dire(coffre);
  for (const serrure of coffre.serrures)
    for (const [tient, dire] of REGLES_SERRURE) if (!tient(serrure)) return dire(serrure);
  return null;
}

// Les deux chaînes canoniques de la notice. La seconde prolonge la première : on
// l'écrit donc comme telle, et non en recopiant ses lignes.
const lignesEntete = c => [
  c.format, `version=${c.version}`, `date=${c.date}`, `engagement=${c.engagement}`,
  `cle_publique=${c.cle_publique}`,
  ...c.serrures.map(s => `serrure=${s.nom}|${s.sel}|${s.iterations}`),
];
const aadDe = c => enOctets(lignesEntete(c).join('\n'));
const messageScelleDe = c => enOctets([
  ...lignesEntete(c), `nonce=${c.nonce}`, `contenu=${c.contenu}`,
  ...c.serrures.map(s => `scelle=${s.nonce}|${s.enveloppe}`),
].join('\n'));

async function sceauValide(coffre) {
  const publique = await crypto.subtle.importKey(
    'raw', depuisB64(coffre.cle_publique), { name: 'ECDSA', namedCurve: 'P-256' }, false, ['verify']);
  return crypto.subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, publique,
                              depuisB64(coffre.signature), messageScelleDe(coffre));
}

async function sommeDe(vingt) {
  const empreinte = Buffer.from(await crypto.subtle.digest('SHA-256', enOctets(vingt)));
  return [...empreinte.subarray(0, 5)].map(o => JETONS[o % JETONS.length]).join('');
}
const aLaFormeDunCode = t => t.length === 25 && [...t].every(c => JETONS.includes(c));

async function laMaitresseEstLaBonne(brute, annonce) {
  const cle = await crypto.subtle.importKey('raw', brute, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const calcule = await crypto.subtle.sign('HMAC', cle, enOctets(NOM_FORMAT + '/engagement'));
  return versB64(new Uint8Array(calcule)) === annonce;
}

// Rend { brute, nom } dès qu'une serrure cède, sinon { brute: null }. L'erreur
// d'authentification est la SEULE qui signifie « pas cette serrure-là » ; tout le
// reste doit remonter au lieu d'être maquillé en erreur de saisie.
async function essayerLesSerrures(coffre, secret, aad) {
  for (const serrure of coffre.serrures) {
    let brute;
    try {
      const dérivée = await crypto.subtle.deriveKey(
        { name: 'PBKDF2', salt: depuisB64(serrure.sel), iterations: serrure.iterations, hash: 'SHA-256' },
        await crypto.subtle.importKey('raw', enOctets(secret), 'PBKDF2', false, ['deriveKey']),
        { name: 'AES-GCM', length: 256 }, false, ['decrypt']);
      brute = await crypto.subtle.decrypt(
        { name: 'AES-GCM', iv: depuisB64(serrure.nonce), additionalData: aad }, dérivée,
        depuisB64(serrure.enveloppe));
    } catch (souci) {
      if (souci?.name === 'OperationError') continue;
      throw souci;
    }
    if (!await laMaitresseEstLaBonne(brute, coffre.engagement))
      sortir(`la serrure « ${serrure.nom} » s'ouvre mais rend une clé qui n'est pas celle de ce coffre`);
    return { brute, nom: serrure.nom };
  }
  return { brute: null };
}

// Le diagnostic de saisie de la notice : la somme de contrôle ne décide jamais
// d'essayer ou non, elle choisit seulement ce qu'on répond à quelqu'un qui a
// échoué.
async function pourquoiLeSecretNeVaPas(secret) {
  const majuscules = secret.toUpperCase();
  if (secret !== majuscules && aLaFormeDunCode(majuscules)
      && await sommeDe(majuscules.slice(0, 20)) === majuscules.slice(20))
    return "code saisi en minuscules — il s'écrit tout en majuscules";
  if (aLaFormeDunCode(secret) && await sommeDe(secret.slice(0, 20)) !== secret.slice(20))
    return 'somme de contrôle : les cinq derniers caractères ne concordent pas avec les vingt '
         + 'premiers — faute de recopie, pas mauvais code';
  return "aucune serrure ne s'ouvre — secret faux, ou en-tête modifié depuis la fabrication";
}

if (!globalThis.crypto?.subtle)
  sortir("WebCrypto indisponible dans cet environnement — le coffre n'est pas en cause");

const coffre = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const secret = [...(process.argv[3] ?? '')].filter(c => /[\p{L}\p{N}]/u.test(c)).join('');

const malForme = controlerForme(coffre);
if (malForme) sortir(malForme);

// Étape 1 de la notice : le sceau, avant toute dérivation. Il ne demande aucun
// secret, il coûte une milliseconde, et il répond à la question qui précède les
// autres — ce fichier est-il celui qui a été déposé ?
if (!await sceauValide(coffre))
  sortir('le sceau de ce coffre ne tient pas : le fichier a été modifié depuis sa fabrication. '
       + "Aucune serrure n'a été essayée.");

const aad = aadDe(coffre);
const { brute, nom } = await essayerLesSerrures(coffre, secret, aad);
if (!brute) sortir(await pourquoiLeSecretNeVaPas(secret));

let clair;
try {
  const maitresse = await crypto.subtle.importKey('raw', brute, { name: 'AES-GCM' }, false, ['decrypt']);
  clair = await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: depuisB64(coffre.nonce), additionalData: aad }, maitresse,
    depuisB64(coffre.contenu));
} catch (souci) {
  if (souci?.name === 'OperationError')
    sortir("la serrure s'est ouverte, mais le contenu n'est pas celui de cet en-tête — "
         + 'fichier abîmé, ou assemblé à partir de deux coffres');
  throw souci;
}

const texte = new TextDecoder().decode(clair);
console.log(`serrure ouverte : ${nom}`);
console.log(`empreinte du clair : ${Buffer.from(await crypto.subtle.digest('SHA-256', clair)).toString('hex')}`);
console.log(`sceau intact, non modifié depuis sa fabrication : version ${coffre.version}, du ${coffre.date}`);
console.log(`contenu : ${texte.length} caractères — première ligne : ${texte.split('\n')[0]}`);
