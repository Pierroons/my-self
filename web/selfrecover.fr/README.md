# selfrecover.fr

Le site de **SelfRecover**, le protocole de récupération de compte sans email ni SMS de
l'écosystème MySelf. Thème « terminal » aux couleurs MySelf, distinct de `my-self.fr`, qui reste
sobre.

## Fichiers

| Fichier | Rôle |
|---|---|
| `index.html` | la page en français, servie à `/` |
| `en/index.html` | la même page en anglais, servie à `/en/` |
| `selfrecover.css` | la feuille commune aux deux pages |
| `selfrecover.js` | la pluie des modules Self* derrière le hero, et rien d'autre |
| `favicon.svg` | la clé du logo, sur fond sombre |
| `og-image.png`, `en/og-image.png` | les aperçus de partage, 1200×630, tirés du hero de chaque page |

Les deux pages ont le même squelette. Une phrase qui change dans l'une change dans l'autre.

## Ce que les pages s'interdisent

Elles sont servies sous la CSP de la vitrine (`deploy/my-self/snippets/csp-accueil.conf`) :
**aucun script en ligne, aucun attribut `on*`, aucune ressource hors de l'origine**. Un script
ajouté va dans `selfrecover.js` ; un style va dans `selfrecover.css`, en classe. Le validateur
de la CI (`html-validate`, configuration `.htmlvalidate.json`) refuse aussi l'attribut `style`.

## Les liens vers les autres services

Les adresses de la démo, de SelfDataGuard et du lab s'écrivent avec leur gabarit
(`bi-self.example.org`, `dataguard.example.org`, `ctf.example.org`). Le déploiement
(`deploy/my-self/deploy.sh assembler`) les remplace par les vrais domaines. Une copie brute de ce
dossier publierait donc des liens vers `example.org`.

## Ce que le texte affirme

Chaque phrase sur le protocole s'appuie sur le README de SelfRecover et sur son modèle de menace
(`bi-self/selfrecover/`). Quand la bibliothèque change, c'est là qu'on relit avant de toucher au
texte d'ici. La section « Limites » reprend ce que le modèle de menace déclare hors de sa portée.

## Version

`site v0.1.0`, affichée dans le pied de page des deux pages. C'est la version du **site**, pas
celle du protocole : celle-ci vit dans `modules.json`. Patch `0.0.X` libre, mineure et majeure
sur accord de Pierroons.

## Les aperçus de partage

Ils sont tirés du hero réel : une capture à 1200×630, sans la navigation ni les boutons, puis
quantifiée en 256 couleurs (octree, sans tramage). Le vert des dents de la clé est une couleur
rare dans l'image ; une quantification par coupe médiane le perd.
