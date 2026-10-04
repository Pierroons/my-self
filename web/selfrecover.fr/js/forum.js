/* selfrecover.fr — la façade du forum (maquette). Le calcul du navigateur est le vrai : le dériveur de la
 * bibliothèque. Ce que ferait le serveur est simulé ici, aux formats du code (Recovery, Device), et le compte ne
 * vit qu'en mémoire de la page : rien n'est envoyé, rien n'est stocké. */
(function () {
  'use strict';
  var C = window.Coulisses || { emit: function () {}, dump: function () {}, decrypter: function (c, f) { c.textContent = f; } };
  var ALPHABET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Device::engendrerMotDePasse
  var LONGUEUR_MDP = 16, MOTS = 6, CODES = 10;
  var compte = null, liste = null;
  var $ = function (id) { return document.getElementById(id); };
  var pause = function (ms) { return new Promise(function (r) { window.setTimeout(r, ms); }); };

  // ── aléa uniforme (rejet) : la maquette tire comme le serveur, sans biais de modulo ──
  function hasard(n) {
    var limite = Math.floor(0x100000000 / n) * n, x = new Uint32Array(1);
    do { window.crypto.getRandomValues(x); } while (x[0] >= limite);
    return x[0] % n;
  }
  function hex(n) {
    var b = window.crypto.getRandomValues(new Uint8Array(n));
    return Array.prototype.map.call(b, function (o) { return o.toString(16).padStart(2, '0'); }).join('');
  }
  function b64(n) {
    var b = window.crypto.getRandomValues(new Uint8Array(n));
    return window.btoa(String.fromCharCode.apply(null, b)).replace(/=+$/, '');
  }
  function fauxArgon() { return '$argon2id$v=19$m=65536,t=4,p=2$' + b64(16) + '$' + b64(32); }
  function motDePasse() { var s = ''; for (var i = 0; i < LONGUEUR_MDP; i++) s += ALPHABET[hasard(ALPHABET.length)]; return s; }
  function code() { var h = hex(5); return h.slice(0, 5) + '-' + h.slice(5); }
  async function passphrase() {
    if (!liste) liste = await (await window.fetch('/data/eff_en.json')).json();
    var m = [];
    for (var i = 0; i < MOTS; i++) m.push(liste[hasard(liste.length)]);
    return m.join(' ');
  }

  function retour(id, texte, ko) { var r = $(id); r.textContent = texte; r.classList.toggle('ko', !!ko); }

  function montrerFeuille(complet) {
    $('feuille').hidden = false;
    $('fe-pseudo').textContent = compte.pseudo;
    C.decrypter($('fe-mdp'), compte.mdp);
    C.decrypter($('fe-phrase'), compte.phrase);
    $('fe-codes-l').hidden = !complet;
    var zone = $('fe-codes');
    zone.textContent = '';
    if (complet) compte.codes.forEach(function (c) {
      var s = document.createElement('span');
      s.textContent = c.code + (c.utilise ? '  (utilisé)' : '');
      zone.appendChild(s);
    });
  }

  function majDump() {
    C.dump({
      titre: 'La ligne de « ' + compte.pseudo + ' », telle qu\'un voleur de la base la lirait. Valeurs simulées par la maquette : la forme est exacte, le contenu tiré au hasard.',
      lignes: [
        ['pseudo', compte.pseudo],
        ['sel', compte.sel + '  (en clair : ce n\'est pas un secret)'],
        ['mot de passe', compte.hMdp],
        ['passphrase', compte.hPhrase],
        ['empreinte du mot', compte.hEmpreinte],
        ['codes', CODES + ' × (index HMAC + Argon2id)'],
        ['clé de données', 'scellée 3 fois : XChaCha20-Poly1305'],
        ['messages privés', 'blobs chiffrés']
      ],
      note: 'Ce qui n\'y est pas : ton mot, ta passphrase, ton mot de passe, tes codes. Pour retrouver chacun, il faut payer un Argon2id par essai — un mot faible reste attaquable.'
    });
  }

  // ── onglets ──
  var onglets = Array.prototype.slice.call(document.querySelectorAll('[role="tab"]'));
  function choisir(o) {
    onglets.forEach(function (x) {
      var actif = x === o;
      x.setAttribute('aria-selected', String(actif));
      x.tabIndex = actif ? 0 : -1;
      $(x.getAttribute('aria-controls')).hidden = !actif;
    });
  }
  onglets.forEach(function (o, i) {
    o.addEventListener('click', function () { choisir(o); });
    o.addEventListener('keydown', function (e) {
      var j = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : null;
      if (j === null) return;
      var cible = onglets[(j + onglets.length) % onglets.length];
      choisir(cible); cible.focus(); e.preventDefault();
    });
  });

  // ── rejoindre ──
  $('f-rejoindre').addEventListener('submit', async function (e) {
    e.preventDefault();
    var pseudo = $('r-pseudo').value.trim(), mot = $('r-mot').value;
    if (!/^[a-z0-9_-]{3,24}$/i.test(pseudo)) { retour('r-retour', 'Pseudo : 3 à 24 caractères, lettres, chiffres, - ou _.', true); return; }
    if (mot.length < 8) { retour('r-retour', 'Ton mot : au moins 8 caractères.', true); return; }
    retour('r-retour', 'Création…');
    var sel = window.srEngendrerSel();
    C.emit('nav', 'Ton navigateur tire un sel pour ton compte : 16 octets au hasard. Il n\'est pas secret.', [['sel', sel, true]]);
    var empreinte = await window.srDerive(mot, sel, { mode: 'hostname' });
    C.emit('nav', 'Il dérive ton mot en empreinte, liée à cette adresse.', [
      ['formule', 'HMAC-SHA256(clé = ton mot, message = « ' + window.location.hostname.toLowerCase() + '|v2 » + sel)'],
      ['empreinte', empreinte, true]]);
    C.emit('wire', 'Envoi au serveur : ton pseudo, ton sel, ton empreinte. Ton mot n\'y est pas.', [
      ['requête', 'POST /forum/inscription'],
      ['corps', JSON.stringify({ pseudo: pseudo, sel: sel, empreinte: empreinte })]], { maquette: true });
    await pause(350);
    var mdp = motDePasse(), phrase = await passphrase(), codes = [];
    for (var i = 0; i < CODES; i++) codes.push({ code: code(), utilise: false });
    C.emit('srv', 'Le serveur engendre ton mot de passe, ta passphrase et dix codes papier.', [
      ['mot de passe', LONGUEUR_MDP + ' caractères tirés au hasard'],
      ['passphrase', MOTS + ' mots de la liste diceware de l\'EFF, ≈ 77,5 bits'],
      ['codes', CODES + ' codes xxxxx-xxxxx, à usage unique']], { maquette: true });
    await pause(450);
    compte = { pseudo: pseudo, sel: sel, empreinte: empreinte, mdp: mdp, phrase: phrase, codes: codes,
      hMdp: fauxArgon(), hPhrase: fauxArgon(), hEmpreinte: fauxArgon() };
    C.emit('db', 'Il ne range que des hachages Argon2id, jamais les secrets eux-mêmes.', [
      ['mot de passe', compte.hMdp, true],
      ['passphrase', compte.hPhrase, true],
      ['empreinte du mot', compte.hEmpreinte, true],
      ['codes', CODES + ' × (index HMAC + Argon2id)'],
      ['sel', sel + ' (en clair)']], { maquette: true });
    await pause(300);
    C.emit('vault', 'SelfDataGuard tire une clé pour tes données et la scelle sous chacun de tes secrets : trois serrures.', [
      ['serrures', 'mot de passe · mot mémorisé · passphrase'],
      ['coût par serrure', 'Argon2id 64 Mio']], { maquette: true });
    majDump();
    montrerFeuille(true);
    $('r-mot').value = '';
    retour('r-retour', 'Compte créé (maquette). Note ta feuille ci-dessous.');
  });

  // ── connexion ──
  $('f-connexion').addEventListener('submit', async function (e) {
    e.preventDefault();
    var pseudo = $('c-pseudo').value.trim(), mdp = $('c-mdp').value;
    C.emit('nav', 'Le mot de passe n\'est pas dérivé : il part tel quel, chiffré par TLS jusqu\'au serveur.');
    C.emit('wire', 'Envoi : ton pseudo et ton mot de passe, sous TLS.', [['requête', 'POST /forum/connexion']], { maquette: true });
    await pause(400);
    var ok = !!compte && compte.pseudo === pseudo && compte.mdp === mdp;
    C.emit('srv', 'Le serveur recalcule l\'Argon2id du mot de passe et compare. Il répond pareil, que le pseudo ou le mot de passe soit faux.', [
      ['coût', 'Argon2id · 64 Mio · t=4 · p=2'], ['résultat', ok ? 'accepté' : 'refusé']], { maquette: true });
    $('c-mdp').value = '';
    retour('c-retour', ok ? 'Connecté (maquette).' : 'Identifiant ou mot de passe inconnu.', !ok);
  });

  // ── accès perdu ──
  var radios = Array.prototype.slice.call(document.querySelectorAll('input[name="niveau"]'));
  function niveau() { var r = radios.filter(function (x) { return x.checked; })[0]; return r ? r.value : '1'; }
  function champs() {
    var n = niveau();
    Array.prototype.forEach.call(document.querySelectorAll('[data-niveau]'), function (c) {
      c.hidden = c.getAttribute('data-niveau').split(' ').indexOf(n) < 0;
    });
    retour('p-retour', '');
  }
  radios.forEach(function (r) { r.addEventListener('change', champs); });

  async function renouveler(raison) {
    compte.mdp = motDePasse();
    compte.phrase = await passphrase();
    compte.hMdp = fauxArgon(); compte.hPhrase = fauxArgon();
    C.emit('srv', raison + ' Le serveur te rend un nouveau mot de passe et une nouvelle passphrase : qui récupère ne sait pas ce qui a fuité.', null, { maquette: true });
    C.emit('vault', 'Le service re-scelle ta clé de données sous tes nouveaux secrets ; tes données restent lisibles.', null, { maquette: true });
    majDump();
    montrerFeuille(false);
  }

  $('f-perdu').addEventListener('submit', async function (e) {
    e.preventDefault();
    var n = niveau();
    if (n === '1') {
      var pseudo = $('p-pseudo').value.trim(), phrase = $('p-phrase').value.trim().toLowerCase().replace(/\s+/g, ' ');
      C.emit('wire', 'Envoi : ton pseudo et ta passphrase, sous TLS. Le serveur la voit à ce moment-là.', [['requête', 'POST /forum/recuperation/1']], { maquette: true });
      await pause(400);
      var ok1 = !!compte && compte.pseudo === pseudo && compte.phrase === phrase;
      C.emit('srv', 'Il compare l\'Argon2id de la passphrase. Réponse identique en cas d\'échec, quel qu\'il soit.', [['résultat', ok1 ? 'acceptée, puis effacée' : 'refusée']], { maquette: true });
      $('p-phrase').value = '';
      if (!ok1) { retour('p-retour', 'Pseudo ou passphrase inconnus.', true); return; }
      await renouveler('La passphrase ne sert qu\'une fois.');
      retour('p-retour', 'Accès rendu (maquette). Note tes nouveaux secrets.');
    } else if (n === '2') {
      var saisi = $('p-code').value.trim().toLowerCase(), mot = $('p-mot').value;
      C.emit('wire', 'D\'abord, le code seul : ton navigateur demande le sel du compte.', [['requête', 'POST /forum/sel']], { maquette: true });
      await pause(300);
      var c = compte && compte.codes.filter(function (x) { return x.code === saisi; })[0];
      var sel = c ? compte.sel : hex(16);
      C.emit('srv', 'Le serveur rend un sel pour n\'importe quel code : le vrai s\'il existe, un faux sinon, au même coût. Personne ne peut sonder quels codes existent.', [['sel rendu', sel, true]], { maquette: true });
      var empreinte = await window.srDerive(mot, sel, { mode: 'hostname' });
      C.emit('nav', 'Ton navigateur dérive ton mot avec ce sel.', [['empreinte', empreinte, true]]);
      C.emit('wire', 'Envoi : le code et l\'empreinte. Ton mot n\'y est pas.', [['requête', 'POST /forum/recuperation/2']], { maquette: true });
      await pause(400);
      var ok2 = !!c && !c.utilise && empreinte === compte.empreinte;
      C.emit('srv', 'Il retrouve le compte par l\'index HMAC du code, vérifie le code et l\'empreinte en Argon2id. Une seule erreur générique si l\'un des deux échoue.', [['résultat', ok2 ? 'accepté, code consommé' : 'refusé']], { maquette: true });
      $('p-mot').value = '';
      if (!ok2) { retour('p-retour', 'Code ou mot incorrect.', true); return; }
      c.utilise = true;
      await renouveler('Le code ne sert qu\'une fois.');
      retour('p-retour', 'Accès rendu (maquette). Il te reste ' + compte.codes.filter(function (x) { return !x.utilise; }).length + ' codes.');
    } else {
      var p3 = $('p-pseudo').value.trim();
      C.emit('srv', 'Le serveur ouvre un dossier et te remet un sésame à usage unique : lui seul rouvrira ce dossier.', [['sésame', hex(16), true]], { maquette: true });
      await pause(300);
      C.emit('srv', 'Tu réponds à quelques questions. Un administrateur lit les faits bruts et tranche : pas de score, rien d\'automatique. Un refus ne touche pas au compte.', null, { maquette: true });
      retour('p-retour', 'Dossier ouvert' + (p3 ? ' pour « ' + p3 + ' »' : '') + ' (maquette). Un humain le lira.');
    }
  });

  champs();
})();
