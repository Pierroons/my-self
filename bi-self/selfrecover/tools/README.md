# SelfRecover — outils

Ce dossier rassemble des pages autonomes, un moteur de calcul client et un outil
de purge. Aucune page n'est servie en
production : elles s'ouvrent depuis un disque. `purger.php` fait exception — il
se lance en ligne de commande, exige un DSN PDO vers la base du déploiement et
efface des lignes.

| dossier | quoi | état |
|---|---|---|
| `entropy-lab/` | méthode Diceware aux dés, tables imprimables | page statique |
| `offline-validator/` | vérification d'une passphrase hors ligne | autonome, zéro requête |
| `comparison.html` | comparatif des méthodes de récupération | page statique |
| `mesure-epreuve.html` | ce que coûte l'épreuve du niveau 3 dans le navigateur qui l'ouvre | autonome, zéro requête |
| `purger.php` | dossiers de niveau 3 périmés, lignes d'échec anciennes | CLI, `SELFRECOVER_DSN` requis, efface |

## entropy-lab

Tutoriel de la méthode Reinhold (1995) en cinq étapes, plus les deux tables
diceware de 7 776 mots au format PDF — EFF pour l'anglais, Arthur Pons pour le
français —, et les liens vers les deux listes d'origine. La page ne charge rien et
n'envoie rien.

`entropy-lab/docs/generate_diceware_pdf.py` régénère les tables depuis les wordlists.

### Le moteur n'est pas branché — et c'est volontaire de le noter

`engine/` contient `entropy.js` (calcul d'entropie : Diceware, passphrase libre
via zxcvbn, mode hybride, tirage uniforme par *rejection sampling* sur
`crypto.getRandomValues`), `zxcvbn.js` et les deux wordlists au format JS.

**Aucun de ces fichiers n'est utilisé par `index.html`.** Les trois modes
interactifs ont été ajoutés le 04/05/2026 (`93a9e8a`) puis retirés du HTML le
11/07/2026 (`a3dd060`) ; le JavaScript qui les pilotait est resté dans la page,
appelant des identifiants qui n'existaient plus. Il a été constaté mort au moment
de l'extraction : six identifiants ciblés, zéro présent ; quatre fonctions
définies, zéro appelée depuis le HTML.

Le moteur est conservé parce qu'il est correct et documenté, pas parce qu'il
tourne. Le rebrancher suppose d'écrire le HTML des trois modes — travail réel, pas
un raccordement.

## offline-validator

Page HTML autonome : aucune ressource externe, aucune requête. Elle se copie sur
une clé et s'ouvre sur une machine hors ligne, ce qui est le seul usage
défendable pour vérifier une passphrase qu'on garde sur papier.
