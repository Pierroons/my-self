# SelfModerate

**Moteur de modération communautaire autonome par raisonnement social**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Status: v0.1.0](https://img.shields.io/badge/status-v0.1.0%20whitepaper-orange.svg)](#statut)
[![Part of: Bi-Self](https://img.shields.io/badge/part%20of-Bi--Self-blue.svg)](../README.fr.md)
[![Companion of: SelfRecover](https://img.shields.io/badge/companion-SelfRecover-green.svg)](../selfrecover/)
[![Self-hosted](https://img.shields.io/badge/self--hosted-Raspberry%20Pi-blue.svg)](#)
[![Zero dependencies](https://img.shields.io/badge/dependencies-zero-brightgreen.svg)](#)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

> *La modération la plus efficace n'est pas imposée. Elle émerge naturellement quand le système est bien conçu.*

Fait partie de [Bi-Self](../README.fr.md) — peut aussi être utilisé en standalone.

## Qu'est-ce que c'est ?

SelfModerate est un moteur de modération qui permet aux communautés en ligne de s'auto-réguler sans modérateurs dédiés. Au lieu d'un seul admin qui décide qui est mute ou banni, ce sont les dynamiques sociales naturelles de la communauté qui font le travail.

**Principe cœur :** Tu joues avec quelqu'un, tu le notes. Si tu es toxique, personne ne veut jouer avec toi. L'isolation sociale est la sanction. Naturellement.

## Comment ça marche

### Système de vote
- Les votes sont liés aux **invitations acceptées** (vraies interactions, pas reports anonymes)
- 👍 (+1) ou 👎 (-1) avec une raison obligatoire
- Voter est une **recommandation, pas une obligation** — ça aide à reconnaître les bons coéquipiers ou signaler les comportements problématiques
- Raisons configurables par plateforme (toxique, no-show, triche, bon coéquipier, habile…)
- Votes anonymes : la cible voit son score et les raisons, pas qui a voté

### Score de réputation
- Chaque utilisateur démarre à **20** (configurable)
- Score plafonné à **30** (configurable) — pas d'accumulation de crédit social
- Monter est lent, descendre est rapide
- Régénération passive : +1/semaine si le score tombe sous 5

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
- 3 mois clean : reset total (score + strikes)

### Anti-manipulation
- **Anti-Sybil** : intégration SelfRecover (optionnel) + cooldown 7 jours sur les nouveaux comptes
- **Pack voting** : recoupement invitations / votes pour détecter les downvotes coordonnés
- **Upvote farming** : votes positifs mutuels bloqués après 3 occurrences en 2 mois
- **Cross-voting** : A vs B et B vs A sur la même invitation → les deux annulés
- **Protection des victimes** : un abus signalé suspend le ban pour revue admin

## Documentation

- [Whitepaper technique (FR)](./docs/) — bientôt (DOCX disponible)
- Modèle de menace — bientôt

## Statut

🟡 **Phase whitepaper (v0.1.0)** — protocole complet rédigé, démo en développement.

## Licence

MIT

## Auteur

**Pierroons** — [github.com/Pierroons](https://github.com/Pierroons)
