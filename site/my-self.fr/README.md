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

## Déploiement

Actuellement servi par nginx sur le RPI4 :
- Vhost : `/etc/nginx/sites-enabled/myself-root`
- Root : `/var/www/my-self/`
- SSL : Let's Encrypt via certbot
- DNS : `my-self.fr` + `www.my-self.fr` → IP Freebox + NAT vers RPI4

## Modifications

Pour publier un changement :

1. Éditer `index.html` dans ce dossier
2. Copier sur RPI4 : `scp index.html zelda@rpi4:/tmp/` puis
   `sudo cp /tmp/index.html /var/www/my-self/index.html && sudo chown www-data:www-data /var/www/my-self/index.html`
3. Cache nginx a `expires 1d` sur `/index.html` — ajouter un hard refresh
   navigateur (Ctrl+Shift+R) pour vérifier.

## Design

- Pas de framework (HTML/CSS vanilla inline, zéro JS autre que le lien Viva)
- Dark theme par défaut (`--bg: #0f1419`, `--accent: #7ab7ff`)
- Responsive mobile-first (breakpoint `@media (max-width: 500px)`)
- Fonts système (pas de Google Fonts, pas de CDN typographie)

## Licence

AGPL-3.0-or-later (comme tout MySelf).
