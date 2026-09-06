// Pilote l'atelier assemblé comme une personne le ferait : il remplit les champs
// de la page et appelle ses gestionnaires. Aucun accès direct aux fonctions de
// fabrication — ce qui est éprouvé ici, c'est le chemin que Mme Lambda emprunte,
// câblage compris.
//
//   node pilote_atelier.mjs fabriquer <directives.txt> <coffre.selfvault>
//       — écrit aussi `meta.json` à côté du coffre : l'identité s'imprime en
//         page 1 du pli et n'entre pas dans le coffre, qui est chiffré.
//   node pilote_atelier.mjs ouvrir    <coffre.selfvault> <secret>
//   node pilote_atelier.mjs tirage-mots    [casse]
//   node pilote_atelier.mjs tirage-lettres [casse]
//   node pilote_atelier.mjs alea-mort
//
// 🔑 Les deux derniers verbes éprouvent le REJET des tirages biaisés. Un rejet ne
// s'observe pas : deux générateurs, l'un avec rejet et l'autre sans, rendent des
// suites qui se ressemblent trait pour trait. On injecte donc un générateur qui
// rend TOUTES les valeurs possibles une fois chacune, et on compte — chaque issue
// doit sortir exactement le même nombre de fois. L'argument « casse » retire le
// rejet du code avant de l'évaluer : c'est le contre-témoin, et il doit rougir.
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const MODULE = dirname(dirname(fileURLToPath(import.meta.url)));
const ATELIER = join(MODULE, 'sortie', 'selfvault-atelier.html');

const [verbe, a1, a2] = process.argv.slice(2);

let page;
try { page = readFileSync(ATELIER, 'utf8'); }
catch { console.error("ÉCHEC — l'atelier n'est pas assemblé : lancer outils/faire_atelier.py"); process.exit(1); }

let script = page.slice(page.indexOf('<script>') + 8, page.lastIndexOf('</script>'));

// Le contre-témoin : sans ces deux lignes, `octet % 30` et `entier % 7776`
// favorisent les premières issues. Le remplacement est fait sur le texte du
// script, donc il échoue bruyamment le jour où le rejet change de forme.
if (a1 === 'casse' || a2 === 'casse') {
  const avant = script;
  script = script
    .replace('const limite=256-(256%ALPHABET.length), u=new Uint8Array(1);',
             'const limite=256, u=new Uint8Array(1);')
    .replace('const limite=65536-(65536%mots.length), u=new Uint16Array(1);',
             'const limite=65536, u=new Uint16Array(1);');
  if (script === avant) { console.error("ÉCHEC — le rejet n'a pas la forme attendue, rien n'a été cassé"); process.exit(1); }
}

// 🔑 `alea-mort` remplace `crypto.getRandomValues` par une fonction qui ne remplit
// rien — la forme exacte d'une extension bancale ou d'un portage raté. C'est la
// panne qui ne se voit pas : la page tirait alors `abdominal-abdominal-…` en
// affichant la mesure d'un tirage qui n'a pas eu lieu, et le banc restait vert. Le verbe exige que la page
// se déclare inutilisable ; sans la garde, elle fabrique et ce contrôle rougit.
const ALEA_MORT = verbe === 'alea-mort';
if (ALEA_MORT) {
  const avant = script;
  script = script.replace('crypto.getRandomValues(u);', '/* générateur mort */');
  if (script === avant) { console.error("ÉCHEC — l'appel au générateur n'a pas la forme attendue, rien n'a été cassé"); process.exit(1); }
}

// Un DOM factice. Les éléments naissent à la demande et sont MÉMORISÉS : le même
// sélecteur rend toujours le même objet. Une liste figée d'éléments connus ferait
// disparaître en silence les écritures vers tout élément ajouté à la page depuis —
// un pilote qui rend vert sur un écran cassé.
const faire = () => ({ textContent: '', className: '', disabled: false, hidden: false,
                       value: '', checked: false, style: {}, href: '', download: '',
                       onchange: null, onclick: null, oninput: null, files: null,
                       click(){}, setAttribute(){}, });
const elements = {};
const el = s => (elements[s] ??= faire());
const document = { querySelector: el, createElement: faire };
globalThis.self = globalThis;

// Le script est évalué tel quel, puis on récupère de quoi éprouver les tirages.
// Les `const` de premier niveau vivent dans la portée de la fonction créée : il
// suffit de les rendre.
const atelier = new Function('document',
  script + '\nreturn {tirerMot, tirerLettre, MOTS, ALPHABET};')(document);

// La page se déclare inutilisable au chargement si WebCrypto manque, ou si son
// générateur d'aléa ne fonctionne pas. Sans cette porte, appeler les
// gestionnaires à la main passerait outre.
if (ALEA_MORT) {
  const msg = el('#etatf').textContent;
  if (!el('#faire').disabled) {
    console.log("ÉCHEC — le générateur d'aléa est mort et la page fabrique quand même");
    process.exit(1);
  }
  if (!/aléa|hasard|générateur/i.test(msg)) {
    console.log('ÉCHEC — la page refuse sans nommer la cause : ' + msg);
    process.exit(1);
  }
  console.log('REFUSÉ — ' + msg);
  process.exit(0);
}
if (el('#faire').disabled) {
  console.log('ÉCHEC — ' + el('#etatf').textContent);
  process.exit(1);
}

const empreinte = async t => [...new Uint8Array(await crypto.subtle.digest(
  'SHA-256', new TextEncoder().encode(t)))].map(b => b.toString(16).padStart(2, '0')).join('');

// ── Le comptage exact d'un tirage ────────────────────────────────────────────
// `alea` rend 0, 1, 2, … jusqu'à épuisement. Chaque issue doit sortir le même
// nombre de fois : c'est vrai avec rejet, faux sans.
function compter(taille, tirer, issues) {
  const vus = new Map();
  let i = 0;
  const alea = u => { if (i >= taille) throw new Error('ÉPUISÉ'); u[0] = i++; };
  try { for (;;) { const v = tirer(alea); vus.set(v, (vus.get(v) ?? 0) + 1); } }
  catch (err) { if (err.message !== 'ÉPUISÉ') throw err; }
  const comptes = [...vus.values()];
  const bas = Math.min(...comptes), haut = Math.max(...comptes);
  const total = comptes.reduce((s, n) => s + n, 0);
  console.log(`issues distinctes : ${vus.size} / ${issues}`);
  console.log(`tirages retenus   : ${total} sur ${taille} valeurs offertes`);
  console.log(`occurrences       : de ${bas} à ${haut}`);
  if (vus.size !== issues) { console.log('BIAISÉ — toutes les issues ne sortent pas'); process.exit(1); }
  if (bas !== haut) { console.log(`BIAISÉ — ${haut - bas} occurrence(s) d'écart entre issues`); process.exit(1); }
  console.log('UNIFORME');
}

if (verbe === 'tirage-mots') {
  compter(65536, alea => atelier.tirerMot(atelier.MOTS, alea), atelier.MOTS.length);

} else if (verbe === 'tirage-lettres') {
  compter(256, alea => atelier.tirerLettre(alea), atelier.ALPHABET.length);

} else if (verbe === 'fabriquer') {
  // On marche les quatre étapes comme une personne, au lieu d'appeler la
  // fabrication directement : sinon un bouton « Continuer » cassé passerait vert.
  el('#tit').value = 'Marie DUPONT';
  el('#nai').value = '12 mars 1961 à Sainte-Foy (33220)';
  el('#ref').value = 'SV-BANC-0001';
  el('#v1').onclick();
  if (el('#e2').hidden) { console.log("ÉCHEC — l'étape 2 ne s'ouvre pas"); process.exit(1); }
  el('#txt').value = readFileSync(a1, 'utf8');
  el('#v2').onclick();
  el('#v3').onclick();
  if (!el('#cnom').textContent) { console.log('ÉCHEC — le récapitulatif est vide'); process.exit(1); }
  await el('#faire').onclick();
  const json = el('#brut').value;
  if (!json) { console.log('ÉCHEC — ' + el('#etatf').textContent); process.exit(1); }
  writeFileSync(a2, json);
  // Le second fichier. Sans lui, `outils/faire_pli.py` ne peut pas composer le
  // pli : l'atelier collectait l'identité et ne l'écrivait nulle part.
  const meta = el('#meta').value;
  if (!meta) { console.log("ÉCHEC — l'atelier n'écrit pas l'identité"); process.exit(1); }
  writeFileSync(join(dirname(a2), 'meta.json'), meta);
  const phrase = el('#sL2').textContent;
  console.log('FABRIQUÉ — ' + Buffer.byteLength(json) + ' octets, récapitulatif : '
              + el('#cnom').textContent + ' — ' + el('#cver').textContent);
  console.log('L2 ' + phrase);
  console.log('L1 ' + el('#sL1').textContent);
  console.log('META ' + meta.replace(/\s+/g, ' '));

  // La dernière étape de l'écran : rouvrir le coffre qu'on vient de faire, sans
  // recharger de fichier. C'est le seul moment où la titulaire vérifie qu'elle a
  // bien recopié — si ce chemin est muet, elle part chez le notaire sans preuve.
  el('#vc').value = phrase;
  await el('#vgo').onclick();
  if (el('#vsortie').style.display !== 'block') {
    console.log('ÉCHEC — la vérification en mémoire ne rend rien : ' + el('#vetat').textContent);
    process.exit(1);
  }
  console.log('VÉRIFIÉ — empreinte du clair : ' + await empreinte(el('#vsortie').textContent));

} else if (verbe === 'ouvrir') {
  const contenu = readFileSync(a1, 'utf8');
  await el('#of').onchange({ target: { files: [{ text: async () => contenu }] } });
  if (el('#ogo').disabled) { console.log('ÉCHEC — ' + el('#oetat').textContent); process.exit(1); }
  el('#oc').value = a2 ?? '';
  await el('#ogo').onclick();
  const ouvert = el('#osortie').style.display === 'block' && el('#osortie').textContent;
  console.log((ouvert ? 'OUVERT — ' : 'ÉCHEC — ') + el('#oetat').textContent);
  if (!ouvert) process.exit(1);
  console.log('empreinte du clair : ' + await empreinte(el('#osortie').textContent));

} else {
  console.error('verbe attendu : fabriquer | ouvrir | tirage-mots | tirage-lettres');
  process.exit(1);
}
