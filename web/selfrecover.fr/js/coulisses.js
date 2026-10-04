/* selfrecover.fr — les coulisses : ce que fait le système, étage par étage, pour les actions de ce visiteur seul.
 * Les pages y versent leurs étapes par Coulisses.emit(). Le journal se remplit même panneau fermé ; le bouton
 * compte ce qui n'a pas été vu. Toute valeur entre en texte, jamais en HTML. */
(function () {
  'use strict';
  var CLE = 'sr_coulisses';
  var PREFIXE = { nav: 'nav>', wire: 'wire>', srv: 'srv>', db: 'db>', vault: 'vault>' };
  var NOM = { nav: 'ton navigateur', wire: 'le réseau', srv: 'le serveur', db: 'la base', vault: 'le coffre SelfDataGuard' };
  var GLYPHES = '0123456789abcdefアカサタナハマヤラワ';
  var racine = document.documentElement;
  var reduit = window.matchMedia('(prefers-reduced-motion: reduce)');
  var bouton = document.querySelector('.jack');
  var panneau, journal, dump, boutonDump, nonVus = 0;

  function lire() { try { return window.localStorage.getItem(CLE) === '1'; } catch (e) { return false; } }
  function ecrire(v) { try { window.localStorage.setItem(CLE, v ? '1' : '0'); } catch (e) { /* stockage refusé : l'état ne survit pas à la page */ } }
  function ouvert() { return racine.classList.contains('coulisses-on'); }

  function el(nom, classe, texte) {
    var e = document.createElement(nom);
    if (classe) e.className = classe;
    if (texte !== undefined) e.textContent = texte;
    return e;
  }

  // Une valeur arrive en glyphes et se fige caractère par caractère, de gauche à droite.
  // Dans le panneau fermé, inutile d'animer ; sur la page, `toujours` force l'animation.
  function decrypter(cible, finale, toujours) {
    if (reduit.matches || (!toujours && !ouvert())) { cible.textContent = finale; return; }
    var debut = null, duree = Math.min(900, 240 + finale.length * 8);
    function pas(t) {
      if (debut === null) debut = t;
      var fixe = Math.floor(finale.length * Math.min(1, (t - debut) / duree)), s = finale.slice(0, fixe);
      for (var i = fixe; i < finale.length; i++) {
        s += finale[i] === ' ' ? ' ' : GLYPHES[(Math.random() * GLYPHES.length) | 0];
      }
      cible.textContent = s;
      if (fixe < finale.length) window.requestAnimationFrame(pas);
    }
    window.requestAnimationFrame(pas);
  }

  function construire() {
    panneau = el('aside', 'coulisses');
    panneau.id = 'coulisses';
    panneau.setAttribute('aria-label', 'Coulisses');
    var tete = el('div', 'coul-tete');
    tete.appendChild(el('span', 'direct', 'coulisses · direct'));
    var outils = el('div', 'coul-outils');
    boutonDump = el('button', null, 'dump volé');
    boutonDump.type = 'button';
    boutonDump.setAttribute('aria-pressed', 'false');
    boutonDump.title = 'Ce qu\'un voleur de la base obtiendrait de ton compte';
    var effacer = el('button', null, 'effacer');
    effacer.type = 'button';
    var fermer = el('button', null, 'fermer');
    fermer.type = 'button';
    outils.appendChild(boutonDump); outils.appendChild(effacer); outils.appendChild(fermer);
    tete.appendChild(outils);
    journal = el('ol', 'journal');
    journal.setAttribute('aria-live', 'polite');
    dump = el('div', 'dump');
    panneau.appendChild(tete); panneau.appendChild(journal); panneau.appendChild(dump);
    document.body.appendChild(panneau);

    boutonDump.addEventListener('click', function () {
      var actif = boutonDump.getAttribute('aria-pressed') !== 'true';
      boutonDump.setAttribute('aria-pressed', String(actif));
      panneau.classList.toggle('vue-dump', actif);
    });
    effacer.addEventListener('click', function () { journal.textContent = ''; vide(); });
    fermer.addEventListener('click', function () { basculer(false); bouton.focus(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && ouvert()) { basculer(false); bouton.focus(); } });
    vide();
    afficherDump(null);
  }

  function vide() {
    if (journal.children.length) return;
    journal.appendChild(el('li', 'vide', 'En attente. Tape un mot dans l\'essai, ou inscris-toi sur le forum : chaque étape s\'affichera ici.'));
  }

  function compter() {
    var c = bouton.querySelector('.compte');
    if (c) c.textContent = nonVus ? String(nonVus) : '';
  }

  function basculer(etat) {
    racine.classList.toggle('coulisses-on', etat);
    bouton.setAttribute('aria-pressed', String(etat));
    bouton.querySelector('.etat').textContent = etat ? '▓ ON' : '░ OFF';
    panneau.setAttribute('aria-hidden', String(!etat));
    if (etat) { nonVus = 0; compter(); }
    ecrire(etat);
  }

  /* emit('srv', 'phrase en clair', [['libellé', 'valeur', true si à décrypter], …], {maquette: true}) */
  function emit(couche, phrase, details, opts) {
    var v = journal.querySelector('.vide');
    if (v) v.remove();
    var li = el('li'), conteneur, tete;
    if (details && details.length) {
      conteneur = el('details');
      tete = el('summary');
      conteneur.appendChild(tete);
    } else {
      conteneur = el('div');
      tete = conteneur;
    }
    var prefixe = el('span', 'couche c-' + couche, PREFIXE[couche] || couche + '>');
    prefixe.title = NOM[couche] || '';
    tete.appendChild(prefixe);
    tete.appendChild(document.createTextNode(' ' + phrase));
    if (opts && opts.maquette) tete.appendChild(el('span', 'maq', 'maquette'));
    if (details && details.length) {
      var dl = el('dl');
      details.forEach(function (d) {
        dl.appendChild(el('dt', null, d[0]));
        var dd = el('dd');
        dl.appendChild(dd);
        if (d[2]) decrypter(dd, String(d[1])); else dd.textContent = String(d[1]);
      });
      conteneur.appendChild(dl);
    }
    li.appendChild(conteneur);
    journal.appendChild(li);
    journal.scrollTop = journal.scrollHeight;
    if (!ouvert()) { nonVus++; compter(); }
  }

  /* afficherDump({titre, lignes: [['libellé', 'valeur'], …], note}) — ou null : pas encore de compte. */
  function afficherDump(d) {
    dump.textContent = '';
    dump.appendChild(el('h3', null, 'vue dump volé'));
    if (!d) {
      dump.appendChild(el('p', 'note', 'Pas encore de compte. Inscris-toi sur le forum : tu verras ici ce qu\'un voleur de la base obtiendrait de ta ligne.'));
      return;
    }
    dump.appendChild(el('p', 'note', d.titre));
    var t = el('div', 'term');
    d.lignes.forEach(function (l) {
      var r = el('div', 'ligne');
      r.appendChild(el('span', 'cle-l', l[0]));
      r.appendChild(el('span', 'val', l[1]));
      t.appendChild(r);
    });
    dump.appendChild(t);
    if (d.note) dump.appendChild(el('p', 'note', d.note));
  }

  if (!bouton) return;
  construire();
  bouton.hidden = false;
  bouton.addEventListener('click', function () { basculer(!ouvert()); });
  basculer(lire());

  window.Coulisses = { emit: emit, dump: afficherDump, decrypter: function (c, f) { decrypter(c, f, true); } };

  var hote = window.location.hostname.toLowerCase();
  emit('nav', 'Cette page lit son adresse : ' + hote + '. C\'est elle qui liera ton empreinte à ce site.',
    [['matériel de dérivation', hote + '|v2']]);
  emit('wire', 'Tout ce que la page charge vient de ' + hote + ' : sa politique de sécurité interdit les ressources d\'un autre site.');
})();
