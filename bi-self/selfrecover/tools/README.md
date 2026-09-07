# SelfRecover — outils

Ce dossier rassemble ce qui ne dépend pas du serveur : des pages autonomes, un
moteur de calcul client, et deux fichiers gardés pour référence. Rien ici n'est
servi en production ; tout s'ouvre depuis un disque.

| dossier | quoi | état |
|---|---|---|
| `entropy-lab/` | méthode Diceware aux dés, tables imprimables | page statique |
| `offline-validator/` | vérification d'une passphrase hors ligne | autonome, zéro requête |
| `comparison.html` | comparatif des méthodes de récupération | page statique |
| `reference/` | une implémentation conservée pour lecture | non exécuté |

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

`device_handlers.php` vient de la démo supprimée. Rien ne l'inclut, et aucun
banc ne l'exécute.

Il porte le flux d'enrôlement d'appareil en deux temps — défi de 32 octets à
usage unique, vérification de signature ECDSA P-256. `src/Device/Device.php`
porte désormais ce mécanisme dans la bibliothèque, et `demo/lab/lib/device.php`
l'implémente au-dessus : la comparaison pour laquelle ce fichier était gardé a
eu lieu.

Ce qu'il garde de propre, et pourquoi il reste : **le récit écrit de la prise de
compte du 13/08/2026**, en tête de `handleDeviceEnroll()` — la chaîne
`enroll → auth-begin → auth-finish` qui réécrivait un mot de passe sans qu'aucun
secret soit vérifié, le correctif appliqué au lab le 02/08 et jamais ici, et les
deux contraintes qui la ferment. C'est le compte rendu le plus complet de cet
incident dans le dépôt, attaché au code qu'il concerne.

Le code est corrigé, pas seulement commenté : l'enrôlement exige une session et
refuse un nom de compte divergent. Il hache par `Hashing::hash()`, comme le reste
du dépôt — il a longtemps appelé une constante `ARGON2_OPTIONS` que rien ne
définissait, défaut qu'aucun contrôle ne voyait puisque la ligne ne s'exécute
nulle part.

`su_audit.php` a quitté ce dossier le 20/08/2026 : le journal SU redevient du
code vivant, dans `demo/lab/lib/su_audit.php`. Il n'en reste pas de copie ici —
une implémentation figée à côté d'une corrigée finit par être reprise à la place
de la bonne.

La chronologie du correctif d'enrôlement qu'il documentait a été reportée dans
`demo/lab/lib/device.php`, à l'endroit où on la relira.
