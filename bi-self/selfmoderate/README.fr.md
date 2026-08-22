# SelfModerate

> 🇬🇧 **[Read in English →](./README.md)**

**Moteur de modération communautaire autonome par raisonnement social**

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.3.0](https://img.shields.io/badge/status-v0.3.0-yellow.svg)](#statut)
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
- 👍 (+1) ou 👎 (-1) avec une raison — **obligatoire au downvote, facultative à l'upvote** : une raison existe pour que la personne sanctionnée sache ce qu'on lui reproche, et un pouce en l'air ne sanctionne personne
- La raison doit **dire quelque chose** : 40 caractères, 3 mots distincts, aucun mot répété plus de deux fois, au moins 12 caractères différents, pas de rafale d'un même caractère. Cinq règles parce qu'une seule se contourne — on atteint n'importe quelle longueur en bloquant une touche
- Voter est une **recommandation, pas une obligation** — ça aide à reconnaître les bons coéquipiers ou signaler les comportements problématiques
- Raisons configurables par plateforme via `setReasonCodes()` ; le jeu par défaut convient à un forum (hors-sujet, agressif, désinformation, entraide, contribution utile, autre)
- Votes anonymes : la cible voit son score et les raisons, pas qui a voté. Les raisons lui sont rendues **datées au jour**, dans un ordre non chronologique — à la seconde près, recoupées avec les présences, elles désigneraient leur auteur. Limite qu'aucun tri ne lève : sur un unique downvote reçu, la personne devine souvent qui l'a émis

### Score de réputation
- Chaque utilisateur démarre à **20** (configurable)
- Score plafonné à **30** (configurable) — pas d'accumulation de crédit social
- Monter est lent, descendre est rapide : un downvote retire un point immédiatement, la remontée passive en rend un par intervalle de calme
- **Convalescence** : sous 5, l'état est posé et le score remonte tout seul **jusqu'à 20**, son point de départ — jamais au-delà. Le droit de vote revient à 5, l'état se lève à 20
- L'état est **visible**, sur son propre profil et sur celui que voient les autres : il annonce qu'il y a eu bêtise, et que le score varie par la patience, pas par le mérite
- C'est un **état**, pas un seuil. Conditionner la remontée à « score < 5 » l'arrête pile au seuil qui rend le droit de vote, et laisse le compte à vie sur le fil du rasoir

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

### Escalade pour les votants d'une meute
Le rang appartient au **votant**, pas à la cible. Compté sur la cible, un groupe
qui change de proie resterait au premier palier indéfiniment, et une victime
visée par plusieurs groupes ferait punir des gens dont c'est le premier écart.

| Épisode | Ce qu'il en coûte |
|---|---|
| 1er | **Rien** — les votes sont annulés, comme à tous les paliers, et le votant est averti |
| 2e | Droit de vote **suspendu 7 jours** |
| 3e | Suspendu **30 jours** et **5 points** de réputation en moins |
| 4e et suivants | Suspension maintenue et **revue humaine** — aucune exclusion automatique |

- Le premier épisode ne coûte rien parce que le critère de meute est le message
  privé réciproque : **deux amis qui réagissent de bonne foi au même message
  pénible le remplissent**. Annuler leurs votes se défait et protège la victime ;
  leur retirer le droit de vote, non. C'est la récidive qui fait la peine
- La suspension vit dans son **propre compteur**, pas dans le droit de vote lié à
  la réputation : sinon la convalescence la lèverait au bout de quelques jours
  calmes, et la peine ne durerait pas ce qu'elle annonce
- Un votant ne monte **qu'un seul rang par 24 heures**. Sans cette borne, un
  groupe qui frappe trois personnes dans le même passage franchirait trois
  paliers d'un coup et personne ne verrait jamais l'avertissement. Les cibles
  supplémentaires sont enregistrées quand même : l'admin voit l'ampleur
- Le rang est visible **sur son propre profil seulement**. Une pastille publique
  serait une peine de plus, que personne n'a décidée

### Anti-manipulation
- **Anti-Sybil** : intégration SelfRecover (optionnel) + cooldown **24 h** sur les nouveaux comptes, aligné sur la période d'échauffement décrite par [Bi-Self](../README.fr.md) — *partiel : l'anti-Sybil est là ; le lab abaisse le cooldown à 120 s pour rester testable*
- **Meute** : deux votants **liés entre eux** qui frappent la même cible sur 30 jours → leurs votes sont annulés, la réputation restituée, et les votants entrent dans l'escalade décrite plus haut. Le lien se propage par transitivité — A–B et B–C liés forment une meute de trois, car une meute a un meneur
- Ce qui lie deux comptes dépend de la plateforme. Sur un forum : un **message privé dans chaque sens**, l'équivalent le plus proche d'une invitation acceptée. Exiger la réciprocité empêche un spammeur de se rendre invulnérable en écrivant à tout le monde. Le contenu des messages n'est **jamais lu** — seulement qui a écrit à qui
- **Salve rapide** : plusieurs votants **sans aucun lien** dans la même minute. Ce n'est pas une meute, c'est le plus souvent la même réaction au même message : rien n'est annulé, la cible part en revue humaine. Annuler ici protégerait un message d'autant mieux qu'il choque plus de monde à la fois
- **Ce que ni l'une ni l'autre ne voit** : une coordination organisée ailleurs, entre comptes qui ne se sont jamais écrit sur la plateforme. Elle tombe en salve rapide — donc signalée, jamais annulée
- **Upvote farming** : votes positifs mutuels bloqués après 3 occurrences en 2 mois
- **Cross-voting** : A vs B et B vs A sur la même invitation → les deux annulés — *pas encore codé*
- **Protection des victimes** : un abus signalé suspend le ban pour revue admin — *pas encore codé*

## Documentation

- Whitepaper technique (FR) — rédigé, pas encore publié dans le dépôt
- Modèle de menace — à écrire

## Statut

🟢 **v0.3.0** — le moteur est ici, dans `src/`, et le lab l'importe comme il
importe SelfRecover et SelfDataGuard.

Cette version livre le recoupement des votants liés, la convalescence, le motif
de vote, et l'escalade qui fait payer la meute à ceux qui la forment. **Deux
mécanismes restent à écrire** — cross-voting et protection des victimes — plus
trois tenus à moitié, tous marqués dans les listes ci-dessus.

Contrôles : [`demo/lab/tests/sanity_moderate.php`](../../demo/lab/tests/sanity_moderate.php)
— vingt-quatre, chacun vu rougir : le mécanisme est neutralisé, la mesure
refaite, le code restauré. L'un d'eux ne mesurait rien à sa première mutation —
il calculait son attendu depuis la constante qu'il surveillait, et se décalait
donc avec elle ; il lit maintenant une valeur en clair. Les quatre règles du motif sont éprouvées **séparément**, chaque
cas n'en violant qu'une : sinon la défense en profondeur rattrape le trou, le
contrôle reste vert, et on ne sait plus laquelle mesure encore quelque chose.
Ils vivent encore côté lab parce qu'ils ont besoin d'un schéma de base ; ils
rejoindront `tests/` quand le module portera le sien.

## Licence

AGPL-3.0-or-later — voir le fichier [`LICENSE`](../../LICENSE) à la racine.

## Auteur

**Pierroons** — [github.com/Pierroons](https://github.com/Pierroons)
