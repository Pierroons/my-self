# Landing page my-self.fr

Site statique de présentation de l'écosystème MySelf, déployé sur
`https://my-self.fr`.

## Contenu

- `index.html` — page unique HTML/CSS inline, dark theme
  - Hero avec tagline "Be yourself, for yourself"
  - Manifeste "Reprendre la main"
  - Section 3 piliers (Bi-Self / Self-Right / Self-Security)
  - Section étage applicatif (SelfFarm-Lite)
  - Section auteur & coworking (Pierroons + Claude)
  - Support Viva Quickpay

## Servir cette page

Une page statique, sans dépendance : n'importe quel serveur web la rend telle
quelle. Pointe la racine de ton vhost sur ce dossier, et sers `index.html`.

Pour la publier depuis le dépôt, `deploy/my-self/deploy.sh` assemble l'arbre et
substitue les noms de domaine ; voir sa table pour déclarer le tien.

Si ton serveur pose un `expires` sur `index.html`, un changement peut mettre un
moment à se voir — un rechargement forcé du navigateur (Ctrl+Shift+R) tranche
entre « pas déployé » et « en cache ».

## Design

- Pas de framework (HTML/CSS vanilla inline, zéro JS autre que le lien Viva)
- Dark theme par défaut (`--bg: #0f1419`, `--accent: #7ab7ff`)
- Responsive mobile-first (breakpoint `@media (max-width: 500px)`)
- Fonts système (pas de Google Fonts, pas de CDN typographie)

## Licence

AGPL-3.0-or-later (comme tout MySelf).
