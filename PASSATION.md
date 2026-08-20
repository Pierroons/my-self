# Passation

Ce fichier porte le **présent**, et rien d'autre. Il est écrasé à chaque
passage : son historique vit dans `git log -p PASSATION.md`, avec les dates et
le contexte de chaque commit.

Ce qui n'y va pas, parce que c'est déjà ailleurs et mieux :

- **l'état du dépôt et de l'instance** — il se lance, il ne s'écrit pas :
  `./scripts/ecart-instance.sh` et `git log` sont à jour par construction ;
- **le pourquoi d'un changement** — dans son message de commit ;
- **une règle qui vaut au-delà de ce passage** — dans `AGENTS.md` ;
- **un constat à retenir** — dans les mémoires, hors dépôt.

Si une ligne d'ici mérite de survivre au prochain passage, elle est au mauvais
endroit. Et comme ce fichier est public : rien qui n'irait pas dans un commit.

---

**Dernier passage : 19 août 2026 — 23:05**

## À faire

- Les contrôles à état de ce dépôt sont audités pour le défaut « une sonde qui
  échoue efface sa mémoire » — corrigé le 19/08. Ceux des autres projets ne le
  sont pas ; ils sont suivis hors dépôt.

## En attente de décision

- Une règle sudoers est prête pour le watchdog d'un projet voisin, à poser à la
  main faute de droits : elle est décrite hors dépôt, avec sa commande de pose
  et son contrôle. Sans elle, le watchdog signale correctement qu'il ne peut
  pas relancer — mais ne relance pas.

- Un service sans fréquentation tourne encore, base vide. **Il est conservé
  volontairement** : il servira de terrain d'essai pour un site en clair à
  l'automne. Ne pas le fermer au motif qu'il paraît mort.

- **L'équipe de contrôle a grossi le 19/08** et sa description vit dans
  `AGENTS.md`, section « Agents de vérification » : un troisième agent dans le
  dépôt, cinq scripts de mesure, et un plugin d'analyse côté poste. Y jeter un
  œil avant de relancer une revue — appeler deux fois le même contrôle sous deux
  noms fait perdre plus de temps que de n'en appeler aucun.

- **Les seuils de sécurité deviendront un profil choisi au déploiement**
  (assistant de configuration, selon le niveau de protection exigé). Décision
  prise le 20/08 sur le principe ; l'implémentation n'est pas engagée.

  Ce qui est tranché : **le plancher est le niveau d'aujourd'hui**. Un profil
  peut durcir, jamais descendre sous les valeurs actuellement en vigueur, et
  aucune option d'interface ne doit permettre d'y passer outre.

  Ce que ça demande **avant** d'écrire quoi que ce soit de l'assistant : les
  seuils sont aujourd'hui des littéraux dispersés, aucun n'est une constante
  nommée. Les nommer d'abord, à un seul endroit, sinon le profil n'aura rien à
  faire varier. Inventaire au 20/08 :

  | Seuil | Valeur | Où |
  |---|---|---|
  | Passphrase générée | 4 mots (≈51,7 bits) | `Wordlist::generate(4, …)` — **5 sites d'appel**, plus un test à 6 |
  | Mot de passe | 8 caractères | `demo/lab/lib/recover_l3.php`, `bi-self/selfrecover/tools/reference/device_handlers.php` |
  | Mot de passe (démo SelfDataGuard) | 12 caractères | `demo/selfdataguard/api/change_password.php` |
  | `blindKey` | ≥32 octets | `demo/selfdataguard/api/_bootstrap.php` |
  | Argon2id | opslimit 3, memlimit 64 Mio | `self-security/selfdataguard/src/Crypto/Primitives.php` |
  | Entropie du secret mémorisé | **aucun contrôle** | annoncée « ≥30 bits » en commentaire, jamais mesurée |

  Deux constats de cet inventaire valent indépendamment de l'assistant : le
  minimum de mot de passe **diverge déjà** (8 / 12) sans que ce soit un choix, et
  le seuil d'entropie est une phrase et non du code — c'est précisément ce qu'un
  profil rendrait exécutable, puisqu'une valeur qu'on fait choisir doit exister
  comme donnée.

  Reste à décider : où vit le profil (fichier, base, variable d'environnement),
  ce qui se passe au changement de profil sur une instance déjà peuplée, et si
  un mode dégradé existe — auquel cas il exige un geste conscient et tracé, pas
  une case décochée. Une fois tranché, la règle du plancher va dans `AGENTS.md`,
  pas ici.

## Pièges actifs

- **Les sept cibles supprimées en production le 19/08 n'ont plus de filet
  applicatif.** Retour arrière par l'archive datée du jour sur la machine, ou
  par la sauvegarde chiffrée. Le `sed` inverse sur les vhosts ne suffit plus.
