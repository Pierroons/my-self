# SelfModerate

> 🇬🇧 **[Read in English →](./README.md)**

**Moteur de modération communautaire autonome par raisonnement social**

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.2.0](https://img.shields.io/badge/status-v0.2.0-yellow.svg)](#statut)
[![Part of: Bi-Self](https://img.shields.io/badge/part%20of-Bi--Self-blue.svg)](../README.fr.md)
[![Companion of: SelfRecover](https://img.shields.io/badge/companion-SelfRecover-green.svg)](../selfrecover/)
[![Self-hosted](https://img.shields.io/badge/self--hosted-yes-blue.svg)](#)
[![Zero dependencies](https://img.shields.io/badge/dependencies-zero-brightgreen.svg)](#)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

> *La modération la plus efficace n'est pas imposée. Elle émerge naturellement quand le système est bien conçu.*

Fait partie de [Bi-Self](../README.fr.md) — peut aussi être utilisé en standalone.

## Qu'est-ce que c'est ?

SelfModerate est un moteur de modération qui permet aux communautés en ligne de s'auto-réguler sans modérateurs dédiés. Au lieu d'un seul admin qui décide qui est mute ou banni, ce sont les dynamiques sociales naturelles de la communauté qui font le travail.

**Principe cœur :** Tu joues avec quelqu'un, tu le notes. Si tu es toxique, personne ne veut jouer avec toi. L'isolation sociale est la sanction. Naturellement.

## Comment ça marche

> **Cette page décrit la conception cible.** Le moteur vit dans
> [`src/Moderate.php`](./src/Moderate.php) et couvre une partie de ce qui suit ;
> [`demo/lab/`](../../demo/lab/) l'importe et s'en sert. Ce qui manque est marqué
> *pas encore codé* ; ce qui n'est tenu qu'à moitié, *partiel*.


### Système de vote
- Les votes sont liés aux **invitations acceptées** (vraies interactions, pas reports anonymes) — *partiel : le lab est un forum, il n'a pas d'invitations*
- 👍 (+1) ou 👎 (-1) avec une raison obligatoire — *partiel : la raison n'est jamais validée dans la démo duo, et le lab n'en a pas de champ*
- Voter est une **recommandation, pas une obligation** — ça aide à reconnaître les bons coéquipiers ou signaler les comportements problématiques
- Raisons configurables par plateforme (toxique, no-show, triche, bon coéquipier, habile…) — *pas encore codé*
- Votes anonymes : la cible voit son score et les raisons, pas qui a voté — *partiel : l'anonymat est tenu, aucun écran ne rend ses raisons à la cible*

### Score de réputation
- Chaque utilisateur démarre à **20** (configurable)
- Score plafonné à **30** (configurable) — pas d'accumulation de crédit social
- Monter est lent, descendre est rapide — *pas encore codé : un vote vaut ±1 dans les deux sens*
- Régénération passive : +1/semaine si le score tombe sous 5 — *pas encore codé*

### Boucle auto-régulatrice
```
Joueur toxique → reçoit des downvotes → le score descend
→ personne ne veut jouer avec lui → pas d'invitations acceptées
→ ne peut pas voter (pas d'invitation = pas de droit de vote) → socialement isolé
→ seule option : faire profil bas et reconstruire
```

La punition n'est pas technique — elle est sociale.

### Escalade des sanctions
- Score < 5 → **perte du droit de vote**
- Score = 0 → **ban temporaire** (24 h → 7 j → 30 j, progressif)
- 3 bans temporaires exécutés → **ban permanent**
- Après un ban purgé : score reset à 20 (seconde chance), compte de strikes préservé
- 3 mois clean : reset total (score + strikes) — *partiel : la démo duo remet les strikes à zéro pour tout le monde d'un coup, le lab ne le fait pas*

### Anti-manipulation
- **Anti-Sybil** : intégration SelfRecover (optionnel) + cooldown 7 jours sur les nouveaux comptes — *partiel : l'anti-Sybil est là, le cooldown non*
- **Pack voting** : recoupement invitations / votes pour détecter les downvotes coordonnés — *pas encore codé : la détection repose sur un seuil de temps, sans vérifier que les votants se connaissent*
- **Upvote farming** : votes positifs mutuels bloqués après 3 occurrences en 2 mois
- **Cross-voting** : A vs B et B vs A sur la même invitation → les deux annulés — *pas encore codé*
- **Protection des victimes** : un abus signalé suspend le ban pour revue admin — *pas encore codé*

## Documentation

- Whitepaper technique (FR) — rédigé, pas encore publié dans le dépôt
- Modèle de menace — à écrire

## Statut

🟢 **v0.2.0** — le moteur est ici, dans `src/`, et le lab l'importe comme il
importe SelfRecover et SelfDataGuard. Six mécanismes du protocole restent à
écrire : ils sont marqués dans les listes ci-dessus.

Contrôles : [`demo/lab/tests/sanity_moderate.php`](../../demo/lab/tests/sanity_moderate.php)
— huit, chacun vu rougir. Ils vivent encore côté lab parce qu'ils ont besoin d'un
schéma de base ; ils rejoindront `tests/` quand le module portera le sien.

## Licence

AGPL-3.0-or-later — voir le fichier [`LICENSE`](../../LICENSE) à la racine.

## Auteur

**Pierroons** — [github.com/Pierroons](https://github.com/Pierroons)
