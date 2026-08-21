# Self-Security

> 🇬🇧 **[Read in English →](./README.md)**

**Chiffrer ce qui est stocké, et le garder chiffré quand le reste cède.**

> *Prends ma base — tu auras du bruit.*

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../LICENSE)
[![SelfDataGuard : v0.2.0](https://img.shields.io/badge/SelfDataGuard-v0.2.0-brightgreen.svg)](./selfdataguard/)
[![SelfRecover-LUKS : v0.3.0](https://img.shields.io/badge/SelfRecover--LUKS-v0.3.0-green.svg)](./selfrecover-luks/)
[![Part of: MySelf](https://img.shields.io/badge/part%20of-MySelf-blue.svg)](../README.fr.md)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

---

## La tension qu'il adresse

Deux croyances tiennent la sécurité de la plupart des applications, et elles cèdent le même jour :

1. **« La base ne sortira pas. »** Elle sort : une sauvegarde oubliée, le dump d'un prestataire, une injection SQL, un disque revendu. Le chiffrement de disque n'y change rien — la machine tourne, le volume est monté, les lignes se lisent en clair.
2. **« Le disque est chiffré, donc le poste est protégé. »** À froid seulement. Et la phrase qui l'ouvre est presque toujours un *second* secret à retenir, noté quelque part : c'est ce qui en fait le maillon faible plutôt que le maillon fort.

Self-Security sépare les deux surfaces : **la donnée est chiffrée avant d'atteindre la base**, et **le volume s'ouvre avec un secret déjà mémorisé**.

---

## Pourquoi les deux modules se renforcent

**SelfDataGuard seul** garde la donnée applicative illisible même si la base entière est exfiltrée : la clé se dérive d'un secret que seul l'utilisateur connaît, un dump seul ne donne rien. Mais il tourne sur une machine, et cette machine a un disque.

**SelfRecover-LUKS seul** garde ce disque illisible tant que la machine est éteinte. Mais dès qu'elle démarre, les volumes sont montés et la base se lit en clair.

**Ensemble**, les deux états sont couverts — à froid par LUKS2, à chaud par le chiffrement applicatif — et tous deux partent d'une seule phrase mémorisée, dérivée sous deux étiquettes distinctes :

| Étiquette | Ouvre | Module |
|---|---|---|
| `disk` | un slot LUKS2 | SelfRecover-LUKS |
| `data-enc` | les données applicatives | SelfDataGuard |

L'étiquette change le sel effectif : deux clés issues du même secret restent indépendantes, et compromettre l'une n'ouvre pas l'autre.

---

## Ce que chacun fait le jour où ça tourne mal

- **Base dumpée et publiée** → les champs chiffrés par SelfDataGuard restent du bruit. La clé maîtresse de chaque utilisateur est emballée deux fois — par une clé Argon2id dérivée de son mot de passe, et par une clé HMAC-SHA256 dérivée de son mot de récupération — et aucune de ces deux entrées ne figure dans le dump.
- **Machine éteinte, disque saisi ou revendu** → le volume LUKS2 est fermé. Les volumes secondaires s'ouvrent depuis un fichier-clé rangé *à l'intérieur* de la racine chiffrée : un disque volé seul reste illisible.
- **Redémarrage à distance** → un serveur SSH dropbear embarqué dans l'initramfs reçoit la phrase ; la racine s'ouvre, puis les volumes secondaires suivent en cascade, sans seconde saisie.
- **Le keyscript casse** → chaque volume conserve un slot LUKS natif à phrase classique, jamais retiré. Un keyscript cassé coûte un déverrouillage à la main, pas les données.

---

## Modules du binôme

| Module | Rôle | Statut |
|--------|------|--------|
| [SelfDataGuard](./selfdataguard/) | Chiffrement applicatif des données au repos, qui survit au dump de la base | **v0.2.0** — en service, 191 contrôles sur 8 suites |
| [SelfRecover-LUKS](./selfrecover-luks/) | Racine LUKS2 **et** volumes de données ouverts par une seule phrase de récupération | **v0.3.0** — validé sur un serveur Debian 13 LNMP, installation reproductible |

---

## Statut

Les deux modules tournent. SelfDataGuard est déployé et ses huit suites passent ; SelfRecover-LUKS a été validé sur des cycles de redémarrage complets — racine puis volumes secondaires en cascade — et son installation est documentée pas à pas dans [INSTALL.md](./selfrecover-luks/INSTALL.md).

Aucun des deux n'a été audité par un cryptographe extérieur. Leur conception est vérifiée aujourd'hui par leur auteur et par les lecteurs de ce dépôt, par personne d'autre. Les audits sont bienvenus — voir [SECURITY.md](../SECURITY.md).

---

## Conceptions publiées

Deux conceptions de ce pilier n'ont **pas de module à elles pour tourner**. Elles posent un modèle de menace, une conception cryptographique et, pour l'une, une nomenclature matérielle. Elles sont publiées pour que le raisonnement soit lu et discuté, pas parce que quelque chose serait prêt à installer.

| Conception | Question | Document |
|---|---|---|
| [SelfGuard](./selfguard/) | Que reste-t-il sous la contrainte ? | [whitepaper](./selfguard/docs/whitepaper.md) |
| [SelfKeyGuard](./selfkeyguard/) | Un objet physique peut-il exiger une 2FA matérielle ? | [whitepaper](./selfkeyguard/docs/whitepaper.md) |

SelfKeyGuard décrit **deux bras**, et seul le premier se limite à un document. Son second bras — ouvrir un disque par un quorum de témoins du foyer, avec secours SelfRecover — a du code de R&D qui tourne, rangé sous [`selfrecover-luks/quorum-rnd/`](./selfrecover-luks/quorum-rnd/) et validé sur images jetables. Il est volontairement **non activé en v0.3.0**, qui ouvre par keyscript et fichier-clé.

Une première implémentation viendrait après un audit de sécurité indépendant et une période d'essai physique. C'est du code et du matériel critiques pour la sécurité ; la vitesse n'est pas une vertu ici.

---

## Auteur

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*Self-Security — une phrase, deux états, lisible dans aucun des deux.*
