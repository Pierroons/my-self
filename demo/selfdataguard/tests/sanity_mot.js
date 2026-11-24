/*
 * Banc — le mot mémorisé ne quitte pas la page de la démo SelfDataGuard.
 *
 * Usage : node demo/selfdataguard/tests/sanity_mot.js
 * Sort 0 si tout passe, 1 sinon. Demande `php` avec sodium, pour l'oracle.
 *
 * Ce qui est éprouvé est le VRAI code, chargé comme dans la page : sr-derive.js (le
 * lien vers le composant de SelfRecover), puis mot.js, puis app.js ou coffre.js,
 * chacun dans son propre contexte, avec un document et un `fetch` de banc. Le banc
 * soumet les formulaires et lit ce que la page envoie.
 *
 * 🔑 L'empreinte attendue est recalculée par PHP (hash_hmac, hash) : deux
 * implémentations sans une ligne commune. Puis la bibliothèque dit si elle la
 * prend pour la serrure du mot (UserVault::assertMemorizedForm).
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { spawnSync } = require('child_process');

const webcrypto = globalThis.crypto ?? require('crypto').webcrypto;

const demo = path.join(__dirname, '..');
const racine = path.join(demo, '..', '..');
const HOTE = 'dataguard.example.org';
const MOT = 'couchant-fleuve-marbre';
const MDP = 'mot-de-passe-alice';

let echecs = 0; let total = 0;
const verdict = (quoi, ok, detail = '') => {
  total++;
  console.log(`  ${ok ? '✅' : '❌'} ${quoi}${!ok && detail ? ' — ' + detail : ''}`);
  if (!ok) echecs++;
};

/** L'empreinte que SelfRecover dérive, recalculée par PHP, et ce qu'en dit la bibliothèque. */
function oracle(mot, userId) {
  const code = `require $argv[4] . '/self-security/selfdataguard/src/autoload.php';
    $sel = substr(hash('sha256', 'selfdataguard-demo/sel|' . $argv[2]), 0, 32);
    $e = hash_hmac('sha256', $argv[3] . '|v2' . $sel, $argv[1]);
    try { Pierroons\\SelfDataGuard\\Vault\\UserVault::assertMemorizedForm($e); $pris = 'pris'; }
    catch (InvalidArgumentException) { $pris = 'refusé'; }
    echo $e, ' ', $pris;`;
  const r = spawnSync('php', ['-r', code, mot, userId, HOTE, racine], { encoding: 'utf8' });
  if (r.status !== 0) {
    console.log(r.stderr);
    console.log('  ❌ l\'oracle PHP ne tourne pas — ce banc n\'a rien établi');
    process.exit(1);
  }
  const [empreinte, pris] = r.stdout.trim().split(' ');
  return { empreinte, pris };
}

/** Un élément de banc : ce que les scripts des pages en touchent. */
function element(id) {
  return {
    id, value: '', placeholder: '', textContent: '', innerHTML: '', className: '', _champs: {},
    firstChild: { textContent: '' },
    classList: { add() {}, remove() {} },
    _ecoutes: {},
    addEventListener(type, fn) { (this._ecoutes[type] ??= []).push(fn); },
    append() {}, reset() {},
    contact_secours: { value: '' }, indice_recup: { value: '' },
  };
}

/**
 * Charge une page : ses scripts, dans l'ordre, dans un contexte neuf. `reponse` dit ce
 * que le serveur de banc répond. Rend le contexte, les éléments et les envois.
 */
function page(scripts, reponse = () => ({ ok: true })) {
  const elements = {};
  const envois = [];
  const contexte = {
    location: { hostname: HOTE },
    crypto: webcrypto,
    TextEncoder, console, setTimeout: () => 0,
    confirm: () => true,
    document: {
      querySelector: (sel) => (elements[sel] ??= element(sel)),
      createElement: () => element(''),
    },
    FormData: class { constructor(form) { this.champs = form._champs; } forEach(fn) { for (const [k, v] of Object.entries(this.champs)) fn(v, k); } },
    fetch: async (url, opts) => {
      envois.push({ url, corps: opts.body === undefined ? null : opts.body });
      const data = reponse(url);
      return { ok: data.ok !== false, status: 200, json: async () => data };
    },
    addEventListener() {},
  };
  contexte.window = contexte;
  vm.createContext(contexte);
  for (const s of scripts) {
    if (!fs.existsSync(path.join(demo, s))) {
      manquants.add(s);
      continue;
    }
    vm.runInContext(fs.readFileSync(path.join(demo, s), 'utf8'), contexte, { filename: s });
  }
  return { elements, envois };
}

/** Soumet un formulaire de la page avec ces champs, et attend la fin de son traitement. */
async function soumettre(p, sel, champs) {
  const form = p.elements[sel];
  form._champs = champs;
  for (const ecoute of form._ecoutes.submit) {
    await ecoute({ preventDefault() {}, target: form });
  }
}

const manquants = new Set();
const sansLeMot = (envoi, mot) => envoi !== undefined && envoi.corps !== null && !envoi.corps.includes(mot);
const corps = (envoi) => (envoi && envoi.corps ? JSON.parse(envoi.corps) : {});

(async () => {
  const { empreinte, pris } = oracle(MOT, 'alice');

  console.log('\n→ La dérivation');
  const lien = path.join(demo, 'sr-derive.js');
  const sr = path.join(racine, 'bi-self/selfrecover/client/sr-derive.js');
  const estLien = fs.existsSync(lien) && fs.lstatSync(lien).isSymbolicLink();
  verdict('sr-derive.js de la démo est le fichier de SelfRecover, par un lien, et non une copie',
    estLien && fs.realpathSync(lien) === fs.realpathSync(sr),
    !fs.existsSync(lien) ? 'absent' : estLien ? fs.realpathSync(lien) : 'un fichier ordinaire');
  const ctx = vm.createContext({ location: { hostname: HOTE }, crypto: webcrypto, TextEncoder });
  const empreinteDe = async (mot, userId) => {
    try {
      for (const s of ['sr-derive.js', 'mot.js']) {
        if (typeof ctx.demoEmpreinte !== 'function') vm.runInContext(fs.readFileSync(path.join(demo, s), 'utf8'), ctx);
      }
      return await vm.runInContext(`demoEmpreinte(${JSON.stringify(mot)}, ${JSON.stringify(userId)})`, ctx);
    } catch (e) {
      return `(${e.message.split('\n')[0]})`;
    }
  };
  const calculee = await empreinteDe(MOT, 'alice');
  verdict('l\'empreinte de la page est celle que PHP recalcule (HMAC du mot sur l\'hôte, la version et le sel du compte)',
    calculee === empreinte, `${calculee} contre ${empreinte}`);
  verdict('la bibliothèque la prend pour la serrure du mot', pris === 'pris', pris);
  const bob = await empreinteDe(MOT, 'bob');
  verdict('le même mot sous un autre identifiant donne une autre empreinte', /^[0-9a-f]{64}$/.test(bob) && bob !== calculee, bob);
  const borde = await empreinteDe('  ' + MOT + ' ', 'alice');
  verdict('les blancs du bord ne changent pas l\'empreinte, comme dans les pages de SelfRecover', borde === empreinte, borde);

  console.log('\n→ La page principale');
  const index = page(['sr-derive.js', 'mot.js', 'app.js']);
  await soumettre(index, '#register-form', { userId: 'alice', password: MDP, memorized: MOT, email: 'alice@example.org' });
  const inscription = index.envois.find((e) => e.url === 'api/register.php');
  verdict('l\'inscription envoie l\'empreinte à la place du mot', corps(inscription).memorized === empreinte,
    JSON.stringify(corps(inscription).memorized));
  verdict('le mot n\'est nulle part dans la requête d\'inscription', sansLeMot(inscription, MOT), inscription && inscription.corps);
  await soumettre(index, '#register-form', { userId: 'bob', password: MDP, memorized: '' });
  const sansMot = index.envois.filter((e) => e.url === 'api/register.php').pop();
  verdict('une inscription sans mot n\'en envoie aucun', corps(sansMot).memorized === null, sansMot && sansMot.corps);
  await soumettre(index, '#login-form', { userId: 'alice', mode: 'memorized', secret: MOT });
  const parMot = index.envois.filter((e) => e.url === 'api/login.php').pop();
  verdict('la connexion par le mot envoie l\'empreinte', corps(parMot).secret === empreinte && corps(parMot).mode === 'memorized',
    parMot && parMot.corps);
  verdict('le mot n\'est nulle part dans la requête de connexion', sansLeMot(parMot, MOT));
  await soumettre(index, '#login-form', { userId: 'alice', mode: 'password', secret: MDP });
  const parMdp = index.envois.filter((e) => e.url === 'api/login.php').pop();
  verdict('la connexion par le mot de passe l\'envoie tel quel : c\'est le serveur qui le dérive', corps(parMdp).secret === MDP);

  console.log('\n→ Le coffre');
  const coffre = page(['sr-derive.js', 'mot.js', 'coffre.js'], (url) => (url === 'api/coffre_open.php'
    ? { ok: true, userId: 'alice', private: {}, escrow: null, hasEscrow: false, escrowError: null, escrowReads: null }
    : { ok: true }));
  await soumettre(coffre, '#open-form', { userId: 'alice', mode: 'memorized', secret: MOT });
  const ouverture = coffre.envois.find((e) => e.url === 'api/coffre_open.php');
  verdict('l\'ouverture par le mot envoie l\'empreinte', corps(ouverture).secret === empreinte, ouverture && ouverture.corps);
  verdict('le mot n\'est nulle part dans la requête d\'ouverture', sansLeMot(ouverture, MOT));
  await soumettre(coffre, '#escrow-form', { contact_secours: 'alice-secours@example.org', indice_recup: '' });
  const apres = coffre.envois.filter((e) => e !== ouverture);
  verdict('le dépôt et la relecture qui suivent l\'ouverture renvoient l\'empreinte, jamais le mot',
    apres.length === 2 && apres.every((e) => corps(e).secret === empreinte && sansLeMot(e, MOT)),
    apres.map((e) => `${e.url} ${e.corps}`).join(' · '));
  await soumettre(coffre, '#open-form', { userId: 'alice', mode: 'password', secret: MDP });
  verdict('l\'ouverture par le mot de passe l\'envoie tel quel', corps(coffre.envois.pop()).secret === MDP);

  console.log('\n→ Sans la dérivation');
  const privee = page(['mot.js', 'app.js']);
  await soumettre(privee, '#register-form', { userId: 'alice', password: MDP, memorized: MOT });
  await soumettre(privee, '#login-form', { userId: 'alice', mode: 'memorized', secret: MOT });
  verdict('sans sr-derive.js, ni l\'inscription ni la connexion par le mot n\'envoient rien',
    privee.envois.every((e) => e.url === 'api/inspect_db.php'), privee.envois.map((e) => e.url).join(', '));
  verdict('et la page le dit, sans parler de réseau',
    /sr-derive\.js/.test(privee.elements['#register-result'].textContent)
    && /sr-derive\.js/.test(privee.elements['#login-result'].textContent),
    privee.elements['#register-result'].textContent);
  const privee2 = page(['mot.js', 'coffre.js']);
  await soumettre(privee2, '#open-form', { userId: 'alice', mode: 'memorized', secret: MOT });
  verdict('sans sr-derive.js, le coffre n\'envoie rien non plus', privee2.envois.length === 0,
    privee2.envois.map((e) => e.url).join(', '));

  console.log('\n→ Les pages');
  for (const [html, script] of [['index.html', 'app.js'], ['coffre.html', 'coffre.js']]) {
    const src = fs.readFileSync(path.join(demo, html), 'utf8');
    const ordre = [...src.matchAll(/<script src="([^"]+)"><\/script>/g)].map((m) => m[1]);
    verdict(`${html} charge sr-derive.js, puis mot.js, puis ${script}`,
      JSON.stringify(ordre) === JSON.stringify(['sr-derive.js', 'mot.js', script]), ordre.join(', '));
  }
  const champ = (fs.readFileSync(path.join(demo, 'index.html'), 'utf8').match(/<input name="memorized"[^>]*>/) || [''])[0];
  verdict('le champ du mot, à l\'inscription, est masqué à l\'écran', /type="password"/.test(champ), champ);

  verdict('chaque script des pages existe', manquants.size === 0, [...manquants].join(', '));
  console.log(`\n${echecs === 0 ? 'OK' : 'ÉCHEC'} — ${total - echecs}/${total} — le mot mémorisé de la démo\n`);
  process.exit(echecs === 0 ? 0 : 1);
})().catch((e) => { console.log(`  ❌ le banc est tombé : ${e.stack}`); process.exit(1); });
