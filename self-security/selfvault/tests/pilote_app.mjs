// Pilote `pli/selfvault.html` — l'application réelle, celle qui est découpée en
// QR codes et que le dépositaire ouvrira.
//
// Elle était le seul des trois porteurs du format que rien n'éprouvait : on
// pouvait y planter trois défauts cryptographiques et le banc restait vert
// 18/18, parce qu'aucun de ses contrôles ne lisait ce fichier. La promesse « le
// banc rejoue les mêmes appels » était tenue par la main du rédacteur.
//
// Ici on ne rejoue rien : on charge le script de la page tel quel, on lui donne
// un DOM minimal, et on appelle ses deux gestionnaires dans l'ordre où un humain
// les déclenche.
//
// Usage : node pilote_app.mjs <coffre.selfvault> <secret> [empreinte du sceau]
//         Le troisième argument est ce que la personne recopie depuis son pli. Sans
//         lui, la page ouvre quand même et le dit — c'est le comportement voulu.
// Sortie : 0 si le coffre s'ouvre, 1 sinon. Le message de la page est imprimé
//          tel qu'il s'affiche — c'est lui qui est jugé, pas un code interne.
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const MODULE = dirname(dirname(fileURLToPath(import.meta.url)));
const page = readFileSync(join(MODULE, 'pli', 'selfvault.html'), 'utf8');
const script = page.slice(page.indexOf('<script>') + 8, page.lastIndexOf('</script>'));

// Un DOM factice. Les éléments naissent à la demande et sont MÉMORISÉS : le même
// sélecteur rend toujours le même objet. Une liste figée d'éléments connus faisait
// disparaître en silence toute écriture vers un élément ajouté à la page depuis —
// un pilote qui rend vert sur un écran qu'il ne touche plus.
const faire = () => ({ textContent: '', className: '', disabled: false, hidden: false,
                       value: '', style: {}, onchange: null, onclick: null, files: null });
const elements = {};
const el = s => (elements[s] ??= faire());
const document = { querySelector: el };

// La page sonde `self.crypto` au chargement ; Node n'expose pas `self`.
globalThis.self = globalThis;

new Function('document', script)(document);

// La page désactive ses champs quand WebCrypto manque. Dans un navigateur, cela
// arrête le lecteur ; ici, appeler les gestionnaires à la main passerait outre.
if (el('#f').disabled) {
  console.log('ÉCHEC — ' + el('#etat').textContent);
  process.exit(1);
}

const [, , chemin, secret, empreinte] = process.argv;
const contenu = readFileSync(chemin, 'utf8');

// 1 — le dépositaire fournit le coffre. Deux chemins, tous deux dans la page :
// le fichier, ou les lignes lues sur les QR codes et collées à la main — le seul
// chemin qui ne demande rien d'autre qu'un navigateur, sur Windows comme ailleurs.
if (chemin.endsWith('.lignes')) {
  el('#q').value = contenu;
  await el('#recoller').onclick();
} else {
  await el('#f').onchange({ target: { files: [{ text: async () => contenu }] } });
}
if (el('#go').disabled) {
  console.log('ÉCHEC — ' + el('#etat').textContent);
  process.exit(1);
}

// 2 — il compare le sceau affiché à celui de son pli, s'il l'a, puis saisit le
// secret. La comparaison est facultative par conception : un détenteur légitime
// qui n'a plus le pli doit pouvoir ouvrir.
if (empreinte !== undefined) el('#ep').value = empreinte;
el('#c').value = secret ?? '';
await el('#go').onclick();

const ouvert = el('#sortie').style.display === 'block' && el('#sortie').textContent;
console.log((ouvert ? 'OUVERT — ' : 'ÉCHEC — ') + el('#etat').textContent);
if (!ouvert) process.exit(1);
const octets = new TextEncoder().encode(el('#sortie').textContent);
const emp = Buffer.from(await crypto.subtle.digest('SHA-256', octets)).toString('hex');
console.log('empreinte du clair : ' + emp);
console.log('sceau affiché : ' + el('#emp').textContent);
