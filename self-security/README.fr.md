# Self-Security

> 🇬🇧 **[Read in English →](./README.md)**

**Chiffrer ce qui est stocké, et le garder chiffré quand le reste cède.**

> *Prends ma base — tu auras du bruit.*

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../LICENSE)
[![SelfDataGuard : v0.5.1](https://img.shields.io/badge/SelfDataGuard-v0.5.1-brightgreen.svg)](./selfdataguard/)
[![SelfRecover-LUKS : v0.6.2](https://img.shields.io/badge/SelfRecover--LUKS-v0.6.2-green.svg)](./selfrecover-luks/)
[![Part of: MySelf](https://img.shields.io/badge/part%20of-MySelf-blue.svg)](../README.fr.md)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

---

## La tension qu'il adresse

Deux croyances tiennent la sécurité de la plupart des applications, et elles cèdent le même jour :

1. **« La base ne sortira pas. »** Elle sort : une sauvegarde oubliée, le dump d'un prestataire, une injection SQL, un disque revendu. Le chiffrement de disque n'y change rien — la machine tourne, le volume est monté, les lignes se lisent en clair.
2. **« Le disque est chiffré, donc le poste est protégé. »** À froid seulement — et tant que la phrase qui l'ouvre résiste à une attaque hors ligne. Une phrase choisie pour être retenue est presque toujours courte, et un disque volé s'essaie à loisir.

Self-Security sépare les deux surfaces : **la donnée est chiffrée avant d'atteindre la base**, et **le volume s'ouvre avec une passphrase diceware tirée pour cette machine**, dérivée en Argon2id, dont la force vient des dés et non de la mémoire.

---

## Pourquoi les deux modules se renforcent

**SelfDataGuard seul** garde la donnée applicative illisible même si la base entière est exfiltrée : la clé se dérive d'un secret que seul l'utilisateur connaît, un dump seul ne donne rien. Mais il tourne sur une machine, et cette machine a un disque.

**SelfRecover-LUKS seul** garde ce disque illisible tant que la machine est éteinte. Mais dès qu'elle démarre, les volumes sont montés et la base se lit en clair.

**Ensemble**, les deux états sont couverts — à froid par LUKS2, à chaud par le chiffrement applicatif. Ils ne partagent aucun secret :

| Secret | Détenu par | Dérivé par | Ouvre | Module |
|---|---|---|---|---|
| une passphrase diceware | l'administrateur de la machine | Argon2id, étiquette `disk` | un slot LUKS2 | SelfRecover-LUKS |
| un mot de passe, un mot mémorisé, une passphrase | chaque utilisateur | Argon2id, sel de l'utilisateur | ses données | SelfDataGuard |

---

## Ce que chacun fait le jour où ça tourne mal

- **Base dumpée et publiée** → les champs chiffrés par SelfDataGuard restent du bruit. La clé maîtresse de chaque utilisateur est emballée sous chacun de ses secrets — mot de passe, mot mémorisé, passphrase —, toujours par Argon2id au même coût, puisque des enveloppes ne valent que la moins chère à ouvrir, et aucune de ces entrées ne figure dans le dump.
- **Machine éteinte, disque saisi ou revendu** → le volume LUKS2 est fermé. Les volumes secondaires s'ouvrent depuis un fichier-clé rangé *à l'intérieur* de la racine chiffrée : un disque volé seul reste illisible.
- **Redémarrage à distance** → un serveur SSH dropbear embarqué dans l'initramfs reçoit la phrase ; la racine s'ouvre, puis les volumes secondaires suivent en cascade, sans seconde saisie.
- **Le keyscript casse** → chaque volume conserve un slot LUKS natif à phrase classique, jamais retiré. Un keyscript cassé coûte un déverrouillage à la main, pas les données.

---

## Modules du binôme

| Module | Rôle | Statut |
|--------|------|--------|
| [SelfDataGuard](./selfdataguard/) | Chiffrement applicatif des données au repos, qui survit au dump de la base | **v0.5.1** — disponible, 319 contrôles sur 10 suites |
| [SelfRecover-LUKS](./selfrecover-luks/) | Racine LUKS2 **et** volumes de données ouverts par une seule phrase de récupération | **v0.6.2** — installation reproductible ; versions antérieures validées sur un serveur Debian 13 LNMP, un poste portable et une racine en LVM chiffré |

---

## Statut

Les deux modules tournent. SelfDataGuard est déployé en 0.5.0, et les dix suites de la 0.5.1 passent ; SelfRecover-LUKS a été validé sur des cycles de redémarrage complets — racine puis volumes secondaires en cascade — et son installation est documentée pas à pas dans [INSTALL.md](./selfrecover-luks/INSTALL.md).

Une piste de recherche est volontairement laissée de côté : ouvrir un volume par un **quorum de témoins du foyer** (parts de Shamir, avec secours SelfRecover quand le quorum est injoignable). Son code est rangé sous [`selfrecover-luks/quorum-rnd/`](./selfrecover-luks/quorum-rnd/) et a été validé sur images jetables, mais il n'est **pas activé** : le module ouvre par keyscript et fichier-clé.

Aucun des deux modules n'a été audité par un cryptographe extérieur. Leur conception est vérifiée aujourd'hui par leur auteur et par les lecteurs de ce dépôt, par personne d'autre. Les audits sont bienvenus — voir [SECURITY.md](../SECURITY.md).

---

## Auteur

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*Self-Security — deux secrets, deux états, lisible dans aucun des deux.*
