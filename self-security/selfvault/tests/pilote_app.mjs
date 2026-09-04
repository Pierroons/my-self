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
// Usage : node pilote_app.mjs <coffre.selfvault> <secret>
// Sortie : 0 si le coffre s'ouvre, 1 sinon. Le message de la page est imprimé
//          tel qu'il s'affiche — c'est lui qui est jugé, pas un code interne.
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const MODULE = dirname(dirname(fileURLToPath(import.meta.url)));
const page = readFileSync(join(MODULE, 'pli', 'selfvault.html'), 'utf8');
const script = page.slice(page.indexOf('<script>') + 8, page.lastIndexOf('</script>'));

// Le DOM minimal dont la page se sert : cinq éléments, quatre propriétés.
const faire = () => ({ textContent: '', className: '', disabled: false, value: '',
                       style: {}, onchange: null, onclick: null, files: null });
const elements = Object.fromEntries(['#etat', '#f', '#c', '#go', '#sortie'].map(s => [s, faire()]));
const document = { querySelector: s => elements[s] ?? faire() };

// La page sonde `self.crypto` au chargement ; Node n'expose pas `self`.
globalThis.self = globalThis;

new Function('document', script)(document);

// La page désactive ses champs quand WebCrypto manque. Dans un navigateur, cela
// arrête le lecteur ; ici, appeler les gestionnaires à la main passerait outre.
if (elements['#f'].disabled) {
  console.log('ÉCHEC — ' + elements['#etat'].textContent);
  process.exit(1);
}

const [, , chemin, secret] = process.argv;
const contenu = readFileSync(chemin, 'utf8');

// 1 — le dépositaire choisit le fichier.
await elements['#f'].onchange({ target: { files: [{ text: async () => contenu }] } });
if (elements['#go'].disabled) {
  console.log('ÉCHEC — ' + elements['#etat'].textContent);
  process.exit(1);
}

// 2 — il saisit le secret et clique.
elements['#c'].value = secret ?? '';
await elements['#go'].onclick();

const ouvert = elements['#sortie'].style.display === 'block' && elements['#sortie'].textContent;
console.log((ouvert ? 'OUVERT — ' : 'ÉCHEC — ') + elements['#etat'].textContent);
if (!ouvert) process.exit(1);
const octets = new TextEncoder().encode(elements['#sortie'].textContent);
const emp = Buffer.from(await crypto.subtle.digest('SHA-256', octets)).toString('hex');
console.log('empreinte du clair : ' + emp);
