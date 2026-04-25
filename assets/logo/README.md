# MySelf — Logo exports

Trois SVG prêts à utiliser dans tes repos, sites, READMEs et docs.

## Fichiers

| Fichier | Usage | Couleur |
|---|---|---|
| `myself-mark-A.svg` | **Marketing / brand** : landing, OG, sticker, signature email | `currentColor` (hérite de la couleur du parent) |
| `myself-mark-C.svg` | **Infra / web app** : favicon d'app, header admin, README, dashboard, whitepaper | bicolore fixe (`#7ab7ff` + `#a380ff` + `#e8eaed`) |
| `myself-mark-C-mono.svg` | C en version mono pour favicon, sticker, contextes contraints | `currentColor` |

## Usage rapide

### En HTML (inline ou via `<img>`)

```html
<!-- Inline : la couleur suit ta CSS via currentColor -->
<span style="color: #7ab7ff">
  <object data="/static/myself-mark-A.svg" type="image/svg+xml"></object>
</span>

<!-- Ou via img (perd currentColor mais simple) -->
<img src="/static/myself-mark-A.svg" alt="MySelf" width="32" height="32">
```

### En favicon

```html
<link rel="icon" type="image/svg+xml" href="/myself-mark-C-mono.svg">
```

### Dans un README GitHub

```md
<p align="center">
  <img src="logo/myself-mark-C.svg" alt="MySelf" width="120">
</p>
```

## Récupérer les fichiers en local (pour Claude Code)

Tu as deux options :

### Option 1 — Télécharger un zip

Demande-moi : *"package les SVG en zip"* et je te livre une archive téléchargeable.

### Option 2 — Copier-coller à la main

Le contenu de chaque SVG est court (≤ 1.5 KB). Ouvre le fichier dans ce projet, copie-colle dans Claude Code :

```bash
# Sur ta Debian
mkdir -p ~/projets/my-self/assets/logo
cd ~/projets/my-self/assets/logo

# Crée chaque fichier (Claude Code peut le faire pour toi)
nano myself-mark-A.svg   # colle le contenu
nano myself-mark-C.svg
nano myself-mark-C-mono.svg
```

## Convertir en PNG (si besoin)

Pour OG images, stickers print, ou tout contexte qui ne supporte pas SVG :

```bash
# Avec rsvg-convert (paquet librsvg2-bin sur Debian)
sudo apt install librsvg2-bin

rsvg-convert -w 1200 -h 630 -b "#0f1419" myself-mark-C.svg > og-image.png
rsvg-convert -w 512 -h 512 myself-mark-A.svg > favicon-512.png
rsvg-convert -w 32  -h 32  myself-mark-A.svg > favicon-32.png
```

## Couleurs (rappel design system)

| Var | Hex | Usage |
|---|---|---|
| `--accent` | `#7ab7ff` | Bleu MySelf, entropie |
| `--crypto` | `#a380ff` | Violet, rigueur machine |
| `--text`   | `#e8eaed` | Convergence, mono blanc |
| `--bg`     | `#0f1419` | Fond sombre |

---

Co-écrit avec Claude (Anthropic) dans le cadre du Self pact humain–IA · AGPL-3.0-or-later
