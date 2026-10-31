# selfrecover.fr

Le site de **SelfRecover** pour le grand public : on y **essaie** le protocole dans son navigateur, on y
**vit** sur un forum dont les comptes reposent sur SelfRecover et SelfDataGuard, et les **coulisses** montrent
chaque étape en direct. Les développeurs, eux, vont sur my-self.fr et sur le dépôt.

> **Maquette.** Le forum est une façade : ce que ferait le serveur est simulé dans la page, aux formats du code,
> et rien n'est envoyé. Le backend viendra quand sa place dans le dépôt sera décidée.

## Fichiers

| Fichier | Rôle |
|---|---|
| `index.html` | l'accueil : le problème, l'essai, le fonctionnement, les trois niveaux, SelfDataGuard, les limites |
| `comprendre/index.html` | toute la crypto, mécanisme par mécanisme ; l'interrupteur public/dev ajoute formules, paramètres et code |
| `forum/index.html` | le forum : fils, et le panneau compte (rejoindre, connexion, accès perdu aux trois niveaux) |
| `forum/fil/index.html` | un fil : répondre, signaler |
| `en/index.html` | une page d'attente, jusqu'à ce que la version française soit arrêtée |
| `css/site.css` | la feuille commune |
| `js/pluie.js` | la pluie des modules Self* derrière le hero |
| `js/coulisses.js` | l'interrupteur, le panneau et son journal ; les pages y versent leurs étapes par `Coulisses.emit()` |
| `js/essai.js` | l'essai de l'accueil : le même mot dérivé ici et sur une copie piégée |
| `js/forum.js` | les parcours du forum, et leur simulation du serveur |
| `js/fil.js` | répondre et signaler dans un fil |
| `js/niveau.js` | le niveau de lecture de « Comprendre », chargé en tête pour être posé avant le rendu |
| `js/derivation.js` | la dérivation pas à pas de « Comprendre » |
| `js/mesure.js`, `js/mesure-travail.js` | un Argon2id mesuré sur l'appareil du visiteur, dans un worker ; le profil affiché est lu dans `SR_KDF_PROFIL` |
| `js/sr-derive.js`, `js/argon2id.js`, `js/sr-kdf.js`, `data/eff_en.json` | **des liens** vers les fichiers de la bibliothèque, pas des copies |
| `.well-known/security.txt` | où signaler une faille (RFC 9116) ; son champ `Expires` est à repousser avant le 1er octobre 2027 |
| `favicon.svg`, `og-image.png` | la clé du logo, et l'aperçu de partage tiré du hero |

## Ce que les pages s'interdisent

Elles sont servies sous la CSP de la vitrine (`deploy/my-self/snippets/csp-accueil.conf`) : **aucun script en
ligne, aucun attribut `on*`, aucune ressource hors de l'origine**. Le validateur de la CI (`html-validate`,
`.htmlvalidate.json`) refuse aussi l'attribut `style`.

Le mot que tape le visiteur ne sort pas de la fonction qui le dérive : ni réseau, ni stockage, ni coulisses.
Le seul état gardé dans le navigateur est l'interrupteur des coulisses (`sr_coulisses`).

## Les coulisses

Une console en bas de l'écran, sur toute la largeur. La page garde sous elle une marge de sa hauteur, et
`scroll-padding` dit au navigateur que ce bas est occupé : un champ amené à l'écran se pose au-dessus. Elle se
replie en une ligne qui montre la dernière étape ; sur téléphone, toucher un champ la replie (le clavier prend
la place), et valider un formulaire la déplie.

Chaque étape porte son étage : `nav>` le navigateur, `wire>` le réseau, `srv>` le serveur, `db>` la base,
`vault>` le coffre SelfDataGuard. Une étape simulée porte la marque `maquette`. La phrase se lit sans jargon ;
le détail technique s'ouvre au clic. La vue « dump volé » montre la ligne du compte telle qu'un voleur de la
base la lirait.

## Ce que le texte affirme

Chaque phrase sur le protocole s'appuie sur le README de SelfRecover, son modèle de menace et son code
(`bi-self/selfrecover/`). Quand la bibliothèque change, c'est là que tu relis avant de toucher au texte d'ici.
Les affirmations ont été relues contre le code de SelfRecover 0.13.1 le 31 octobre 2026.

## Les liens vers la bibliothèque

`js/sr-derive.js`, `js/argon2id.js`, `js/sr-kdf.js` et `data/eff_en.json` sont des liens symboliques. Le déploiement de `main` les résout dans
l'arbre posé ; une copie vers un autre serveur doit les suivre (`rsync -L`), sinon elle pose des liens morts.

## Version

`maquette` tant que le site n'est pas arrêté. Patch `0.0.X` libre, mineure et majeure sur accord de Pierroons.
