# Bi-Self

> 🇬🇧 **[Read in English →](./README.md)**

**Identité souveraine + modération communautaire autonome.**

> *Si une communauté peut se construire, elle peut se gouverner.*

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../LICENSE)
[![SelfRecover: v0.10.0](https://img.shields.io/badge/SelfRecover-v0.10.0-green.svg)](./selfrecover/)
[![SelfModerate: v0.4.0](https://img.shields.io/badge/SelfModerate-v0.4.0-yellow.svg)](./selfmoderate/)
[![Part of: MySelf](https://img.shields.io/badge/part%20of-MySelf-blue.svg)](../README.fr.md)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

---

## La tension qu'il adresse

Toute communauté en ligne fait face à deux problèmes chroniques qu'aucune plateforme n'a résolus honnêtement :

1. **Qui es-tu ?** — L'identité par email est fragile, centralisée, et force la dépendance à Google/Microsoft. Le social login est pire. Pourtant toute démocratie — même de forum — commence par répondre à « une personne, une voix ».
2. **Comment maintient-on la paix ?** — La modération descendante est arbitraire. Le vote pur est manipulable via les faux comptes. La modération algorithmique est opaque. Les communautés finissent autoritaires ou chaotiques.

Bi-Self traite les deux en même temps. Il donne aux communautés les **deux primitives minimales** pour se gouverner : un moyen de reconnaître ses membres sans autorité centrale, et un moyen de réguler les comportements sans modérateur-roi.

---

## Pourquoi les deux modules se renforcent mutuellement

**SelfRecover sans SelfModerate** est un joli tour de passe-passe de récupération de compte, mais pas une communauté. On peut récupérer son compte sans email, mais il n'y a pas de tissu pour la vie collective.

**SelfModerate, avec ou sans SelfRecover**, reste de la modération par vote, et un vote ne vaut que ce que coûte un compte de plus. SelfRecover n'augmente pas ce coût : il retire l'email, une barrière faible, et ne prouve pas qu'une personne ne tient qu'un compte. Les freins sont dans SelfModerate : un compte neuf ne vote qu'après un délai — 24 h avec `Config::prod()`, 2 min par défaut — ou un premier message, des comptes liés entre eux qui votent contre la même cible voient leurs votes annulés, et une rafale de votes sans lien part en revue humaine.

**Ensemble** :

- Un compte survit à la perte de son mot de passe (SelfRecover) : sa réputation et son historique aussi, au lieu de repartir de zéro sous un autre nom.
- Le vote collectif (SelfModerate) répartit la modération entre les membres.
- Les modérateurs restent, comme arbitres de ce que les votes ne tranchent pas : chaque ban, levée ou maintien porte leur nom au journal quand l'hébergeur en branche un ; sans journal, rien ne les trace.

---

## Workflows croisés

- **Nouveau membre arrive** → crée un compte avec un mot de récupération (SelfRecover). Zéro email. Pendant ce délai, SelfModerate ne le laisse voter que s'il a publié (période d'échauffement anti-Sybil).
- **Comportement toxique signalé** → les membres votent (SelfModerate), sous les freins anti-Sybil décrits plus haut. Une réputation tombée à zéro bannit automatiquement si l'hébergeur a branché un journal ; sinon elle lève un drapeau et un arbitre décide.
- **Mot de passe perdu** → le membre récupère son compte au niveau 1 ou 2 (SelfRecover), sans email et sans rien demander à personne. Le niveau 3 passe par un admin humain, qui lit le dossier.

---

## Modules du binôme

| Module | Rôle | Statut |
|--------|------|--------|
| [SelfRecover](./selfrecover/) | Identité & récupération sans email | **v0.10.0** — bibliothèque PSR-4 + dériveur navigateur, implémentation déployée et auto-auditée |
| [SelfModerate](./selfmoderate/) | Modération communautaire par raisonnement collectif | v0.4.0 — moteur installable, ban automatique tracé au journal, 45 contrôles en CI ; 2 mécanismes du protocole manquent |

---

## Statut

SelfRecover existe en implémentation de référence et **tourne en production** — comme backend d'authentification d'un service de messagerie, qui réutilise tel quel son stockage de comptes et épingle sa propre version. Sa démo est auto-auditée ; aucun audit externe n'a été mené. Le protocole de SelfModerate est exposé dans un whitepaper rédigé, pas encore publié ; l'implémentation de référence vit dans [`selfmoderate/src/`](./selfmoderate/src/) et le lab s'en sert. Deux mécanismes du protocole restent à écrire, marqués dans son README. Les membres ne votent pas encore les seuils : c'est l'hébergeur qui les fixe (`Config`).

Les deux modules sont conçus pour s'imbriquer, mais aucun n'importe l'autre : les brancher ensemble revient à l'intégrateur. Le lab fait tourner les deux.

---

## Auteur

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*Bi-Self — L'identité est le socle de la communauté.*
