# Landing page my-self.fr

Site statique de présentation de l'écosystème MySelf, déployé sur
`https://my-self.fr`.

## Contenu

- `index.html` — la page d'accueil, HTML et CSS en ligne, thème sombre :
  - hero : portrait de Descartes en pixel art, « Je pense, donc j'héberge. » ;
  - manifeste « Reprendre la main » ;
  - les trois piliers (Bi-Self, Self-Right, Self-Security) et leurs modules ;
  - ce qui se construit dessus : SelfFarm-Lite, dont le bloc de modules est
    généré par `scripts/check-ecosysteme.sh --ecrire`, et MySelf-Lab ;
  - auteur et méthode, puis le soutien par Viva Wallet.
- `accueil.js` — le seul script : il pixelise le portrait au chargement et,
  quand la page est servie sous `dev.<domaine>`, réécrit ses liens vers
  `dev-<sous-domaine>.<domaine>`.
- `pentest.html` — l'audit de sécurité publié (cycle R9). Chaque ligne corrigée
  depuis porte sa date en pied de page.
- `assets` — lien vers le dossier `assets/` de la racine du dépôt (portrait,
  logos ; le favicon est `logo/myself-mark-C-mono.svg`).

## Servir cette page

Une page statique, sans dépendance : n'importe quel serveur web la rend telle
quelle. Pointe la racine de ton vhost sur ce dossier, et sers `index.html`.

Pour la publier depuis le dépôt, `deploy/my-self/deploy.sh` assemble l'arbre et
substitue les noms de domaine ; voir sa table pour déclarer le tien.

Si ton serveur pose un `expires` sur `index.html`, un changement peut mettre un
moment à se voir — un rechargement forcé du navigateur (Ctrl+Shift+R) tranche
entre « pas déployé » et « en cache ».

## Design

- Pas de framework : HTML et CSS en ligne, un seul script, `accueil.js`, servi
  depuis la même origine — la CSP du site n'admet aucun script en ligne.
- Thème sombre (`--bg: #0f1419`, `--accent: #7ab7ff`).
- Deux points de rupture : `@media (max-width: 640px)` et `(max-width: 500px)`.
- Polices système (ni Google Fonts ni CDN typographique).

## Licence

AGPL-3.0-or-later (comme tout MySelf).
