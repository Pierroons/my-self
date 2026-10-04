/* selfrecover.fr — la pluie des modules Self* derrière le hero.
 * Elle avance à l'horloge, pas au rythme de l'écran : même allure à 60 et à 120 Hz.
 * Elle s'arrête quand le hero quitte l'écran, et reste figée sous prefers-reduced-motion. */
(function () {
  'use strict';
  var c = document.getElementById('rain');
  if (!c || !c.getContext) return;
  var x = c.getContext('2d');
  var hero = document.querySelector('.hero');
  var MODULES = ['SELFRECOVER', 'SELFDATAGUARD', 'SELFMODERATE', 'SELFJUSTICE', 'SELFACT',
    'SELFFARM', 'SELFINVOICE', 'SELFAID', 'SELFGUARD', 'SELFKEYGUARD', 'SELFPOS', 'MYSELF'];
  var F = 16;        // taille d'un glyphe, en pixels CSS
  var PAS = 66;      // millisecondes par rangée
  var larg = 0, haut = 0, cols = 0, drops = [], words = [], off = [];
  var enCours = false, idRaf = 0, dernier = 0, reste = 0, heroVisible = true;
  var reduit = window.matchMedia('(prefers-reduced-motion: reduce)');

  function pick() { return MODULES[(Math.random() * MODULES.length) | 0]; }

  function semer(i, depart) {
    drops[i] = depart; words[i] = pick(); off[i] = (Math.random() * 40) | 0;
  }

  function dimensionner() {
    var w = window.innerWidth, h = window.innerHeight, r = window.devicePixelRatio || 1;
    c.width = Math.round(w * r); c.height = Math.round(h * r);
    x.setTransform(r, 0, 0, r, 0, 0);
    x.fillStyle = '#0a0f13'; x.fillRect(0, 0, w, h);
    // Seule une largeur nouvelle resème les colonnes : la barre d'adresse d'un mobile
    // qui se replie change la hauteur, et ne doit pas relancer toute la pluie.
    if (w !== larg) {
      cols = Math.ceil(w / F);
      drops = []; words = []; off = [];
      for (var i = 0; i < cols; i++) semer(i, (Math.random() * -40) | 0);
    }
    larg = w; haut = h;
  }

  function rangee() {
    x.fillStyle = 'rgba(10,15,19,0.10)'; x.fillRect(0, 0, larg, haut);
    x.font = '700 ' + F + 'px ui-monospace, Menlo, monospace'; x.textBaseline = 'top';
    for (var i = 0; i < cols; i++) {
      var w = words[i], row = drops[i];
      if (row >= 0) {
        var ch = w.charAt((row + off[i]) % w.length), px = i * F, py = row * F;
        x.fillStyle = 'rgba(108,182,255,0.80)'; x.fillText(ch, px, py);
        x.shadowColor = 'rgba(122,183,255,0.9)'; x.shadowBlur = 8;
        x.fillStyle = '#e8eaed'; x.fillText(ch, px, py); x.shadowBlur = 0;
        if (row > 1) { x.fillStyle = 'rgba(63,185,140,0.09)'; x.fillText(w.charAt((row - 2 + off[i]) % w.length), px, py - F); }
      }
      if (row * F > haut && Math.random() > 0.975) semer(i, (Math.random() * -20) | 0);
      else drops[i] = row + 1;
    }
  }

  function boucle(t) {
    if (!dernier) dernier = t;
    reste += t - dernier; dernier = t;
    // Au retour d'un onglet en arrière-plan, au plus trois rangées : pas de rafale.
    var n = Math.min(3, Math.floor(reste / PAS));
    reste = n === 3 ? 0 : reste - n * PAS;
    while (n-- > 0) rangee();
    idRaf = window.requestAnimationFrame(boucle);
  }

  function demarrer() {
    if (enCours || reduit.matches || !heroVisible) return;
    enCours = true; dernier = 0; reste = 0;
    idRaf = window.requestAnimationFrame(boucle);
  }

  function arreter() {
    if (!enCours) return;
    enCours = false; window.cancelAnimationFrame(idRaf);
  }

  // Image fixe : la pluie avancée d'un coup, sans animation.
  function figer() {
    arreter();
    for (var i = 0; i < cols; i++) drops[i] = (Math.random() * (haut / F)) | 0;
    for (var k = 0; k < 24; k++) rangee();
  }

  function selonPreference() { if (reduit.matches) figer(); else demarrer(); }

  dimensionner();
  window.addEventListener('resize', function () { dimensionner(); if (reduit.matches) figer(); });
  if (reduit.addEventListener) reduit.addEventListener('change', selonPreference);

  if (hero && 'IntersectionObserver' in window) {
    new IntersectionObserver(function (e) {
      heroVisible = e[0].isIntersecting;
      if (heroVisible) demarrer(); else arreter();
    }).observe(hero);
  }
  selonPreference();
})();
