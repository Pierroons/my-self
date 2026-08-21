# Bi-Self

> 🇬🇧 **[Read in English →](./README.md)**

**Identité souveraine + modération communautaire autonome.**

> *Si une communauté peut se construire, elle peut se gouverner.*

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../LICENSE)
[![SelfRecover: v0.4.0](https://img.shields.io/badge/SelfRecover-v0.4.0-green.svg)](./selfrecover/)
[![SelfModerate: v0.3.0](https://img.shields.io/badge/SelfModerate-v0.3.0-yellow.svg)](./selfmoderate/)
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

**SelfRecover sans SelfModerate** est un joli tour de passe-passe de récupération de compte, mais pas une communauté. On peut prouver qui on est, mais il n'y a pas de tissu pour la vie collective.

**SelfModerate sans SelfRecover** est de la modération par vote construite sur du sable. N'importe qui peut créer dix comptes et faire basculer n'importe quel vote. La « démocratie » communautaire devient théâtre Sybil.

**Ensemble**, la dynamique bascule :

- L'identité fiable (SelfRecover) donne du sens à chaque vote.
- Le vote collectif (SelfModerate) crée un tissu qui survit à n'importe quel mauvais acteur, y compris le fondateur.
- La classe des modérateurs disparaît. Les règles émergent de la communauté elle-même, applicables et révisables par la communauté elle-même.

Un plus un égale une communauté auto-gouvernée. Pas trois — une chose qualitativement différente.

---

## Workflows croisés

- **Nouveau membre arrive** → crée un compte avec un mot de récupération (SelfRecover). Zéro email. Les 24 premières heures de son activité sont surveillées par SelfModerate (période d'échauffement anti-spam).
- **Comportement toxique signalé** → la communauté vote (SelfModerate). L'identité des votants est garantie unique (SelfRecover). Le résultat est contraignant.
- **Mot de passe perdu** → n'importe quel membre récupère son compte via l'escalade L1/L2/L3 (SelfRecover). Pas d'email, pas de demande à un admin.
- **Changement de règle collective** → la communauté propose un nouveau seuil de modération, vote. Le seuil se met à jour sans intervention d'admin.

---

## Modules du binôme

| Module | Rôle | Statut |
|--------|------|--------|
| [SelfRecover](./selfrecover/) | Identité & récupération sans email | **v0.4.0** — implémentation de référence, démo locale auto-auditée |
| [SelfModerate](./selfmoderate/) | Modération communautaire par raisonnement collectif | v0.3.0 — moteur installable, 16 contrôles ; 2 mécanismes du protocole manquent |

---

## Statut

SelfRecover existe en implémentation de référence avec une démo auto-auditée (pas encore de déploiement en production réelle). SelfModerate dispose d'un whitepaper complet définissant le protocole ; l'implémentation de référence vit dans [`selfmoderate/src/`](./selfmoderate/src/) et le lab s'en sert. Deux mécanismes du protocole restent à écrire, marqués dans son README.

Les deux modules sont conçus pour s'imbriquer — une fois les deux en ligne, une communauté peut se bootstraper et se gouverner elle-même sans aucun service central.

---

## Auteur

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*Bi-Self — L'identité est le socle de la communauté.*
