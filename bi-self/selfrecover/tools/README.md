# SelfRecover — outils

Ce dossier rassemble ce qui ne dépend pas du serveur : des pages autonomes, un
moteur de calcul client, et deux fichiers gardés pour référence. Rien ici n'est
servi en production ; tout s'ouvre depuis un disque.

| dossier | quoi | état |
|---|---|---|
| `entropy-lab/` | méthode Diceware aux dés, tables imprimables | page statique |
| `offline-validator/` | vérification d'une passphrase hors ligne | autonome, zéro requête |
| `comparison.html` | comparatif des méthodes de récupération | page statique |
| `reference/` | deux implémentations conservées pour lecture | non exécuté |

## entropy-lab

Tutoriel de la méthode Reinhold (1995) en cinq étapes, plus les deux tables
diceware de 7 776 mots au format PDF — EFF pour l'anglais, ArthurPons pour le
français. La page ne charge rien et n'envoie rien.

`docs/generate_diceware_pdf.py` régénère les tables depuis les wordlists.

### Le moteur n'est pas branché — et c'est volontaire de le noter

`engine/` contient `entropy.js` (calcul d'entropie : Diceware, passphrase libre
via zxcvbn, mode hybride, tirage uniforme par *rejection sampling* sur
`crypto.getRandomValues`), `zxcvbn.js` et les deux wordlists au format JS.

**Aucun de ces fichiers n'est utilisé par `index.html`.** Les trois modes
interactifs ont été ajoutés le 04/05/2026 (`20d2b52`) puis retirés du HTML le
11/07/2026 (`bf67e0a`) ; le JavaScript qui les pilotait est resté dans la page,
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

## reference

`device_handlers.php` et `su_audit.php` viennent de la démo supprimée. Ils ne
sont ni servis, ni chargés, ni testés.

Ils portent le flux d'enrôlement d'appareil en deux temps — défi de 32 octets à
usage unique, vérification de signature ECDSA P-256. `my-self-lab/lib/device.php`
implémente le même mécanisme, plus complètement et à jour ; c'est **lui** la
référence. Ces deux fichiers sont gardés pour comparaison le jour où le protocole
remontera dans une bibliothèque partagée, et pour aucun autre usage.

La chronologie du correctif d'enrôlement qu'ils documentaient a été reportée dans
`my-self-lab/lib/device.php`, à l'endroit où on la relira.
