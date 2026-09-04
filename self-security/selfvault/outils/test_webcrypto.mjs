// Ouvre un coffre SELFVAULT2 sous Node, avec les mêmes primitives WebCrypto que
// `pli/selfvault.html`.
//
// ⚠️ Ce n'est volontairement PAS une copie du code de l'application, et il ne faut
// pas les factoriser : une copie hérite des défauts qu'elle devrait révéler, et ne
// prouve que sa propre cohérence. C'est une réimplémentation écrite depuis la
// notice du format — deux implémentations qui s'interopèrent sont la seule façon
// de vérifier que la notice suffit, ce que le pli promet.
//
// Usage : node test_webcrypto.mjs <coffre.selfvault> <secret>
// Sortie : 0 si le coffre s'ouvre, 1 sinon, avec la cause nommée.
import { readFileSync } from 'node:fs';

const FORMAT = 'SELFVAULT2';
const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
const ITER_MIN = 100000, ITER_MAX = 10000000;

const b64  = s => Uint8Array.from(Buffer.from(s, 'base64'));
const b64e = u => Buffer.from(u).toString('base64');
const echec = m => { console.log('ÉCHEC — ' + m); process.exit(1); };

function enteteCanonique(c) {
  const l = [c.format, 'version=' + c.version, 'date=' + c.date, 'engagement=' + c.engagement];
  for (const s of c.serrures) l.push('serrure=' + s.nom + '|' + s.sel + '|' + s.iterations);
  return new TextEncoder().encode(l.join('\n'));
}

const leve = m => { throw new Error(m); };
const B64=/^[A-Za-z0-9+/]+={0,2}$/, DATE=/^\d{4}-\d{2}-\d{2}$/;
// 🔑 L'encodage de l'AAD doit être INJECTIF. Interdire « | » dans le seul nom de
// serrure ne suffit pas : `date` et `sel` y entrent aussi, et un « \n » glissé
// dans l'un d'eux referme une ligne et en ouvre une autre. On fabrique alors deux
// coffres de dates différentes ayant le même AAD, tous deux authentifiés. Chaque
// champ est donc contraint à une forme qui exclut les deux caractères structurants.
function verifierEntete(c){
  if(c.format!==FORMAT) leve('format inconnu : '+c.format);
  if(!Number.isInteger(c.version)||c.version<1) leve('version : entier positif attendu, reçu '+c.version);
  if(typeof c.date!=='string'||!DATE.test(c.date)) leve('date : AAAA-MM-JJ attendu');
  if(typeof c.engagement!=='string'||!B64.test(c.engagement)) leve("en-tête sans engagement de clé valide");
  if(!Array.isArray(c.serrures)||!c.serrures.length) leve('coffre sans serrure');
  for(const s of c.serrures){
    if(typeof s.nom!=='string'||s.nom.includes('|')||s.nom.includes('\n'))
      leve("nom de serrure invalide : « | » et le retour à la ligne y sont interdits");
    if(typeof s.sel!=='string'||!B64.test(s.sel)) leve('sel de « '+s.nom+' » : base64 attendu');
    if(typeof s.nonce!=='string'||!B64.test(s.nonce)) leve('nonce de « '+s.nom+' » : base64 attendu');
    if(typeof s.enveloppe!=='string'||!B64.test(s.enveloppe)) leve('enveloppe de « '+s.nom+' » : base64 attendu');
    if(!Number.isInteger(s.iterations)||s.iterations<ITER_MIN||s.iterations>ITER_MAX)
      leve("nombre d'itérations hors bornes ("+s.iterations+") — attendu entre "
           +ITER_MIN+" et "+ITER_MAX+". Le fichier a été modifié.");
  }
  if(typeof c.nonce!=='string'||!B64.test(c.nonce)) leve('nonce du coffre : base64 attendu');
  if(typeof c.contenu!=='string'||!B64.test(c.contenu)) leve('contenu : base64 attendu');
}

async function controle(corps) {
  const h = new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(corps)));
  return [...h.slice(0, 5)].map(b => ALPHABET[b % ALPHABET.length]).join('');
}
const ressembleAUnCode = s => s.length === 25 && [...s].every(c => ALPHABET.includes(c));

async function engagementTenu(brut, attendu) {
  const k = await crypto.subtle.importKey('raw', brut, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const sig = new Uint8Array(await crypto.subtle.sign('HMAC', k, new TextEncoder().encode(FORMAT + '/engagement')));
  return b64e(sig) === attendu;
}

if (!(globalThis.crypto && globalThis.crypto.subtle))
  echec('WebCrypto indisponible dans cet environnement — le coffre n\'est pas en cause');

const coffre = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const code = [...(process.argv[3] ?? '')].filter(c => /[\p{L}\p{N}]/u.test(c)).join('');
try { verifierEntete(coffre); } catch (e) { echec(e.message); }
const aad = enteteCanonique(coffre);

let maitresse = null, ouverte = null;
for (const s of coffre.serrures) {
  let brut;
  try {
    const km = await crypto.subtle.deriveKey(
      { name: 'PBKDF2', salt: b64(s.sel), iterations: s.iterations, hash: 'SHA-256' },
      await crypto.subtle.importKey('raw', new TextEncoder().encode(code), 'PBKDF2', false, ['deriveKey']),
      { name: 'AES-GCM', length: 256 }, false, ['decrypt']);
    brut = await crypto.subtle.decrypt(
      { name: 'AES-GCM', iv: b64(s.nonce), additionalData: aad }, km, b64(s.enveloppe));
  } catch (err) {
    // Seul l'échec d'authentification signifie « ce n'est pas cette serrure ».
    if (err && err.name === 'OperationError') continue;
    throw err;
  }
  if (!await engagementTenu(brut, coffre.engagement))
    echec('la serrure « ' + s.nom + " » s'ouvre mais rend une clé qui n'est pas celle de ce coffre");
  maitresse = await crypto.subtle.importKey('raw', brut, { name: 'AES-GCM' }, false, ['decrypt']);
  ouverte = s.nom;
  break;
}

if (!maitresse) {
  const maj = code.toUpperCase();
  if (code !== maj && ressembleAUnCode(maj) && await controle(maj.slice(0, 20)) === maj.slice(20))
    echec('code saisi en minuscules — il s\'écrit tout en majuscules');
  if (ressembleAUnCode(code) && await controle(code.slice(0, 20)) !== code.slice(20))
    echec('somme de contrôle : les cinq derniers caractères ne concordent pas avec les vingt '
          + 'premiers — faute de recopie, pas mauvais code');
  echec("aucune serrure ne s'ouvre — secret faux, ou en-tête modifié depuis la fabrication");
}

let clair;
try {
  clair = await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: b64(coffre.nonce), additionalData: aad }, maitresse, b64(coffre.contenu));
} catch (err) {
  if (err && err.name === 'OperationError')
    echec("la serrure s'est ouverte, mais le contenu n'est pas celui de cet en-tête — "
          + 'fichier abîmé, ou assemblé à partir de deux coffres');
  throw err;
}
const txt = new TextDecoder().decode(clair);
const emp = Buffer.from(await crypto.subtle.digest('SHA-256', clair)).toString('hex');
console.log(`serrure ouverte : ${ouverte}`);
console.log(`empreinte du clair : ${emp}`);
console.log(`en-tête authentifié : version ${coffre.version}, du ${coffre.date}`);
console.log(`contenu : ${txt.length} caractères — première ligne : ${txt.split('\n')[0]}`);
