# SelfModerate

> 🇬🇧 **[Read in English →](./README.md)**

**Moteur de modération communautaire autonome par raisonnement social**

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.6.1](https://img.shields.io/badge/status-v0.6.1-yellow.svg)](#statut)
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
- La raison doit **dire quelque chose** : 40 caractères, 3 mots distincts, aucun mot ressassé (deux fois sont toujours permises ; au-delà, un mot court comme « de » ou « mon » ne dépasse pas le tiers des mots, un mot de quatre lettres ou plus pas le cinquième), au moins 12 caractères différents, pas de rafale d'un même caractère. Cinq règles parce qu'une seule se contourne — on atteint n'importe quelle longueur en bloquant une touche. Elle ne dépasse pas non plus 1000 caractères : cette borne protège la page de la personne visée, où les motifs s'affichent
- Voter est une **recommandation, pas une obligation** — ça aide à reconnaître les bons coéquipiers ou signaler les comportements problématiques
- Raisons configurables par plateforme via `setReasonCodes()` ; le jeu par défaut convient à un forum (hors-sujet, agressif, désinformation, entraide, contribution utile, autre)
- Votes anonymes : la cible voit son score et les raisons, pas qui a voté. Les raisons lui sont rendues **datées au jour**, dans un ordre non chronologique — à la seconde près, recoupées avec les présences, elles désigneraient leur auteur. Limite qu'aucun tri ne lève : sur un unique downvote reçu, la personne devine souvent qui l'a émis

### Score de réputation
- Chaque utilisateur démarre à **20** (`Config`)
- Score plafonné à **30** (`Config`) — pas d'accumulation de crédit social
- Monter est lent, descendre est rapide : un downvote retire un point immédiatement, la remontée passive en rend un par intervalle de calme
- **Convalescence** : sous 5, l'état est posé et le score remonte tout seul **jusqu'à 20**, son point de départ — jamais au-delà. Le droit de vote revient à 5, l'état se lève à 20
- L'état est **visible** sur son propre profil et pour l'arbitre : il annonce qu'il y a eu bêtise, et que le score remonte par la patience, pas par le mérite. Les autres membres ne voient qu'un palier de réputation, jamais l'état de sanction ; sous le seuil du vote, ce palier ne porte plus de chiffre
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
- Score = 0 → **ban temporaire, gradué** : 24 h → 7 j → 30 j en service (`Config::prod()`), 2 → 10 → 30 min en démonstration (`Config::demo()`). Le dernier palier **se reconduit** : la machine ne prononce jamais d'exclusion définitive, au-delà c'est l'arbitre qui décide
- **Le ban automatique ne tombe que si un journal est branché** (`setJournal()`), et jamais dans la transaction d'un hôte, qui pourrait encore l'annuler. Sans journal, une réputation à zéro lève un signalement, et aucun geste d'arbitre ne passe : ni la machine ni un arbitre ne sanctionnent sans trace. Avec lui, chaque début, chaque fin et chaque levée de ban s'écrit au journal, **avant** la base : une panne laisse une trace sans effet, jamais une peine sans trace. Une fin de peine que le journal refuse d'inscrire reste à refermer : le vote ou la page qui la constate continue, l'arbitre est prévenu (`journal_en_panne`, et la console d'arbitre le dit pour tout le site), et la clôture suit le retour du journal. D'ici là, la personne n'est plus bannie, mais son droit de vote et sa réputation remise au départ attendent la clôture, et aucun nouveau ban ne commence
- Une peine en cours **ne se rallonge pas** au vote contraire suivant
- Après un ban automatique purgé : score remis à **20**, strikes préservés — le ban suivant sera plus long
- Ce qu'un ban bloque au-delà du vote relève de la plateforme (`estBanni()`). Le lab bloque aussi la publication, et laisse ouverts la connexion et les messages privés
- 3 mois sans incident : remise à zéro des strikes — *pas encore codé*

### Gestes d'arbitre
- **Bannir**, **lever**, **maintenir**, **classer** : chaque geste porte le nom de l'arbitre et s'écrit au journal, avec les signalements ouverts et ceux qu'il ferme ; sans journal branché, aucun ne passe. Bannir exige un motif, contrôlé comme celui d'un downvote : la personne bannie doit pouvoir lire ce qu'on lui reproche ; il ne ferme aucun signalement. **Lever** (la grâce) remet au départ le ban, la réputation, les strikes, une suspension de vote posée par une version antérieure et encore en cours, et le rang de meute — l'historique des épisodes reste lisible. Elle ferme ce qu'elle défait : les signalements d'état, et la récidive de meute quand elle en efface le rang ; une salve, une troncature ou un votant étendu restent à classer, motif écrit. **Maintenir** prend acte d'un ban automatique : son signalement sort de la file, la peine court ; un ban d'arbitre n'a rien à maintenir. **Classer** ferme un signalement, nommé, sans rien toucher d'autre, motif exigé
- **Un signalement par cause** : chaque cause ouverte sur un compte a sa ligne, et aucune n'en remplace une autre. Un signalement d'état (`reputation_zero`, `ban_auto`, `journal_en_panne`) tombe seul quand l'état cesse ; un signalement de comportement (`salve_rapide`, `meute_recidive`, `votants_tronques`, `votant_etendu`) ne tombe que classé par un arbitre. Un fait qui se répète fait monter son compte ; un geste reçoit le signalement tel que la console l'a affiché, avec ses détections, et refuse si un vote neuf l'a redétecté depuis — même quand la détection a replié une salve d'autres votants dans le même fait : l'arbitre ne ferme pas ce qu'il n'a pas vu. Le balayage, qui relit des votes déjà détectés, ne fait rien refuser. Le votant étendu et la troncature font exception : chez eux, seul un fait compté fait refuser, au plus une fois par `balayagePeriodeSecondes` depuis le précédent, sans quoi chaque vote priverait l'arbitre du classement aussi longtemps qu'il dure. La console montre une page par cause, la plus ancienne en tête : un flot de salves remplit la sienne et ne pousse rien d'autre hors de la vue
- **Plancher** : avant un tiers de la peine **en cours** (`Config::$plancherFraction`), une levée exige un motif écrit et s'inscrit comme **levée anticipée**, avec le temps qui restait. Un tiers de la peine en cours, jamais du palier maximal : sinon le plancher d'un premier ban d'un jour durerait dix jours. Le plancher ne ferme pas le favoritisme ; il le rend coûteux, parce qu'il laisse une trace signée
- **Jamais de plancher sur une meute détectée** : quand le moteur reconnaît que la meute a **causé** le ban — des votes annulés, posés avant son début, avaient retiré un point —, il le lève seul et l'écrit. La levée rend ce que ces votes avaient pris et retire le strike de ce ban, rien de plus. Elle répare une injustice ; elle n'en accorde aucune. Des votes posés pendant le ban ne le lèvent pas, et un ban d'arbitre ne se lève pas ainsi : il n'est pas venu des votes

### Une meute ne pèse pas plus qu'un membre seul
Le lien par message privé est le seul indice d'une meute, et il ne distingue d'une
coordination ni deux amis de bonne foi qui réagissent au même message pénible, ni un
lien **fabriqué par la cible elle-même** — un compte jetable à qui l'on a répondu une
fois. Une peine bâtie sur lui frapperait des membres honnêtes, au choix d'un tiers : un
membre qui publie un message odieux par jour ferait récidiver ceux qui le critiquent
ensemble. Le moteur neutralise donc ce que le groupe ajoute, et ne punit personne.

| Épisode | Ce qui se passe |
|---|---|
| chacun | Dans le groupe lié qui vise une cible, le **premier votant garde ses votes**, ceux des autres sont annulés, et la cible récupère ce qu'ils lui avaient pris |
| 1er | Rien de plus — le votant est averti sur son propre profil |
| 2e et suivants | **Revue humaine** (`meute_recidive`) — aucune suspension, aucun point retiré, aucune exclusion |

- Le rang appartient au **votant**, pas à la cible. Compté sur la cible, un groupe
  qui change de proie resterait au premier rang indéfiniment. Un votant n'entre dans
  un épisode que s'il a voté contre la cible depuis son dernier épisode sur elle : un
  tiers lié qui revote ne fait pas monter le rang de qui n'a rien refait
- Un votant ne monte **qu'un seul rang par 24 heures**, comptées depuis le **début**
  de l'épisode. Sans cette borne, un groupe qui frappe trois personnes dans le même
  passage franchirait trois rangs d'un coup ; comptées depuis la dernière détection,
  une meute qui frappe à moins de 24 heures d'intervalle resterait au premier rang pour
  toujours.
  Les cibles supplémentaires sont enregistrées quand même : l'arbitre voit l'ampleur
- Le rang est visible **sur son propre profil seulement**. Une pastille publique
  serait une peine de plus, que personne n'a décidée
- **Limite : l'oracle.** Toute neutralisation se lit dans la réputation de la cible.
  Une cible qui contrôle un compte lié à un critique apprend, quand un vote est annulé,
  que ce critique a voté contre elle
- **Limite : la meute qui a contribué.** Des comptes liés qui ont réellement fait
  tomber une cible lèvent le ban qu'ils ont causé, même s'ils agissaient pour elle. Ils
  n'obtiennent que cette levée et la restitution exacte de leurs votes

### Anti-manipulation
- **Anti-Sybil** : délai sur les nouveaux comptes, sauf s'ils ont déjà publié : **24 h** en service, aligné sur la période d'échauffement décrite par [Bi-Self](../README.fr.md), **2 min** en démonstration. Le refus dit l'attente restante
- **Meute** : deux votants **liés entre eux** qui frappent la même cible sur 30 jours → le premier garde ses votes, ceux de l'autre sont annulés, et la cible récupère ce qu'ils lui avaient pris. Le lien se propage par transitivité — A–B et B–C liés forment une meute de trois, car une meute a un meneur ; un membre dont le vote a déjà été annulé relie encore les autres. Au-delà de 200 votants sur une cible (`meuteVotantsMax`), les plus récents sont examinés et la cible est signalée à l'arbitre
- Ce qui lie deux comptes dépend de la plateforme. Sur un forum : un **message privé dans chaque sens**, l'équivalent le plus proche d'une invitation acceptée. Exiger la réciprocité empêche un spammeur de se rendre invulnérable en écrivant à tout le monde. Le contenu des messages n'est **jamais lu** — seulement qui a écrit à qui
- **Salve rapide** : plusieurs votants **sans aucun lien** dans une fenêtre courte (5 min en service, 1 min en démonstration). Ce n'est pas une meute, c'est le plus souvent la même réaction au même message : rien n'est annulé, la cible part en revue humaine. Annuler ici protégerait un message d'autant mieux qu'il choque plus de monde à la fois. Le signal d'une salve n'est jamais écrasé par celui d'un ban
- **Ce que ni l'une ni l'autre ne voit** : une coordination organisée ailleurs, entre comptes qui ne se sont jamais écrit sur la plateforme, et qui espace ses votes au-delà de la fenêtre de salve. Rien ne la reconnaît. Ce que l'arbitre en voit : la **composition de chaque ban automatique** — combien de votants l'ont causé sur la fenêtre de meute qui le précède, l'âge de leurs comptes, en combien de temps
- **Farming** : au-delà de 3 votes positifs d'un même votant vers un même membre en 60 jours, les suivants sont neutralisés ; même plafond pour les votes négatifs, contre l'érosion lente d'un votant patient qui vise chaque message. Et un votant ne vise pas plus de 10 membres distincts sur la même fenêtre (`ciblesParVotantMax`) : au-delà, ses votes négatifs vers un membre de plus sont neutralisés, et il est signalé à l'arbitre
- **Cross-voting** : A vs B et B vs A sur la même invitation → les deux annulés — *pas encore codé*
- **Protection des victimes** : une meute reconnue lève le ban automatique qu'elle a causé — *partiel : un simple signalement ne suspend pas encore un ban*

## Intégrer

Le moteur est une classe statique ; l'hôte fournit la connexion `PDO` à chaque appel.

| Appel | Rôle |
|---|---|
| `setConfig(Config)` | les seuils : `Config::demo()`, `Config::prod()` ou les tiens. **Seule source** des seuils : le moteur les lit là, et tes pages aussi, par `Moderate::config()` |
| `setJournal(Journal)` | branche le journal, et avec lui le ban automatique et les gestes d'arbitre. L'interface [`Journal`](./src/Journal.php) liste les actes et leurs clés ; l'écriture doit être durable et non réécrivable. Un refus remonte toujours, sauf quand un vote ou une lecture constate une peine échue : sa clôture attend alors, et l'arbitre est prévenu |
| `balayerBansEchus(PDO)` | referme les peines échues en une passe, pour un planificateur. Sans lui, une fin se constate à la lecture suivante, et le journal porte l'échéance ET l'instant de la constatation |
| `applyVote(PDO, votant, type, cible, valeur, ?motif)` | un vote, qui rend `ok`, `blocked` et `message` — jamais la réputation de la cible, que le profil tait sous le seuil du vote. Il s'inscrit tout ou rien : dans une transaction que le moteur ouvre (`BEGIN IMMEDIATE`) quand l'hôte n'en a pas, derrière un point de reprise (`SAVEPOINT`) dans celle de l'hôte sinon, à qui il revient alors de valider ou d'annuler le reste. Dans la transaction de l'hôte, il n'arme pas le ban automatique : une réputation à zéro y est signalée, comme sans journal. Une clôture de peine ou une levée pour meute faites là, puis défaites avec elle, se refont au passage suivant : le journal porte alors l'acte deux fois |
| `estBanni(PDO, id)` · `bansEnCours(PDO)` | ce que l'hôte bloque, et ce que l'arbitre doit voir : origine, motif, fin, temps restant, plancher, et pour un ban automatique la composition de sa chute |
| `adminBan(PDO, id, arbitre, motif)` · `adminPardon(PDO, id, arbitre, ?motif)` · `adminMaintenir(PDO, id, arbitre, motif, ?vu)` · `adminClasser(PDO, id, arbitre, motif, ?vu)` | les gestes d'arbitre, qui rendent `['ok' => bool, 'message' => string]`. Chacun relit l'état et décide sous le verrou d'écriture, pris d'emblée. Ils refusent dans la transaction d'un hôte : le journal ne se défait pas avec elle. `vu` est le signalement affiché, `['signal' => id, 'detections' => n]` ; sans lui, maintenir et classer visent le seul signalement de leur ressort, et refusent s'il y en a plusieurs |
| `migrerSignaux(PDO)` | reprend dans `mod_signaux` la case unique d'avant la 0.7.0 : à appeler une fois, juste après avoir créé la table ; la marque qui l'empêche de se rejouer est à l'hôte. Tant qu'elle n'a pas tourné, le moteur refuse de lire ou de signaler un compte dont la case est ouverte, par une `LogicException` qui la nomme |
| `flaggedForReview(PDO)` · `signauxOuverts(PDO)` | les comptes à arbitrer, le plus ancien en tête, une ligne par compte avec tous ses signalements ; et les signalements à voir, une page par cause, la plus ancienne en tête, avec le total de chaque cause |
| `dureeEnClair(secondes)` | une durée lisible, unités passées par ton traducteur (`setTranslator()`) |

**Schéma attendu** — celui du lab fait référence ([`demo/lab/schema.sql`](../../demo/lab/schema.sql)) : `member_moderation` (réputation, strikes, droit de vote, `banned_until`, `ban_debut`, `ban_origine`, `ban_motif`, signalement, convalescence, suspension de vote), `mod_votes` (dont `effet`, ce que le vote a réellement changé à la réputation ; un vote antérieur à la colonne la laisse à `NULL`, sans effet connu : il ne se rend pas et ne compte comme cause d'aucun ban), `mod_pack_flags`, `mod_signaux` (une ligne par signalement ouvert, par compte et par cause ; `needs_review` et `review_reason` restent, résumé que le moteur tient pour qui lit la base et ne relit jamais — une base d'avant la 0.7.0 crée la table puis appelle `migrerSignaux()` une fois, sans quoi le moteur refuse tout compte dont la case est ouverte), `mod_sweeps` (une ligne par tranche de `balayagePeriodeSecondes` déjà balayée ; sans elle, chaque vote négatif échoue au lieu de laisser le filet s'éteindre), et trois tables de l'hôte lues sans être écrites : `accounts(id, username, created_at)`, `posts(id, account_id)`, `dm(sender_id, recipient_id, created_at)`. Le moteur écrit du SQL SQLite.

Les dates de ses messages suivent le fuseau de l'hôte (`date_default_timezone_set()`).

## Documentation

- Whitepaper technique (FR) — rédigé, pas encore publié dans le dépôt
- Modèle de menace — à écrire

## Statut

🟢 **v0.6.1** — le moteur est ici, dans `src/`, et le lab l'importe comme il
importe SelfRecover et SelfDataGuard.

La 0.6.1 ne change ni promesse ni schéma. Un vote s'inscrit tout ou rien : `applyVote()` tient
ses écritures dans une transaction — la sienne, ou un point de reprise dans celle de l'hôte —, et une
coupure ne laisse plus un vote inscrit sans son effet. Lire une réputation ne coûte qu'une requête quand l'état n'appelle ni
convalescence ni clôture de peine : une lecture n'insère plus « si absent », donc ne prend plus de
verrou d'écriture ; quand l'état appelle une écriture — ligne absente, convalescence, peine échue —, elle se
fait sous le verrou d'écriture, comme un vote, et un seul lecteur l'inscrit.

La 0.6.0 corrige les défauts qu'un audit de la 0.5.0 a relevés. Une meute ne pèse plus
que ce que pèse un membre seul, et le moteur ne punit plus sur la foi d'un lien ; elle
ne lève que le ban qu'elle a causé ; une salve qui mène au ban reste signalée, et
l'arbitre voit la composition de chaque chute ; un votant ne vise plus tout le forum ;
un journal en panne ne fait plus tomber le vote ni la page qui constate la fin d'une
peine. **Rupture** par rapport à la 0.5.0 : la colonne `mod_votes.effet` entre au
schéma attendu ; `Config` perd `meuteMute2`, `meuteMute3` et `meutePenalite3`, gagne
`ciblesParVotantMax`, et `meuteVotantsMax` passe à 200 ; `applyVote()` ne rend plus
`new_reputation` ; `detectPackVoting()` rend `premier` et `rangs` à la place de
`sanctions`. Une suspension de vote déjà en base reste lue jusqu'à son échéance.

La 0.4.0 fait revenir le ban automatique, tenu : gradué, fini, écrit au journal de
bout en bout, levable par un arbitre sous plancher, et levé seul quand une meute
est reconnue. Les seuils ont une seule source, `Config`. **Rupture** par rapport à
la 0.3.0 : les constantes de seuil du moteur sont retirées (lire `Moderate::config()`),
et `adminBan()` / `adminPardon()` prennent l'arbitre et le motif. **Deux mécanismes
restent à écrire** — le cross-voting, et la remise à zéro des strikes après trois mois
sans incident — plus deux tenus à moitié, tous marqués dans les listes ci-dessus.

Contrôles : [`demo/lab/tests/sanity_moderate.php`](../../demo/lab/tests/sanity_moderate.php)
pour le moteur à travers le lab, [`tests/sanity_motif.php`](tests/sanity_motif.php) pour le motif
seul, [`tests/sanity_promesses.php`](tests/sanity_promesses.php) pour les seuils que ce README
annonce, comparés à ceux que `Config` applique — ces deux-là sans base —, joués par la CI, chacun vu rougir : le mécanisme est
neutralisé, la mesure refaite, le code restauré. L'un d'eux ne mesurait rien à sa première mutation —
il calculait son attendu depuis la constante qu'il surveillait, et se décalait
donc avec elle ; il lit maintenant une valeur en clair. Les cinq règles du motif sont éprouvées **séparément**, chaque
cas n'en violant qu'une : sinon la défense en profondeur rattrape le trou, le
contrôle reste vert, et on ne sait plus laquelle mesure encore quelque chose.
Le banc du moteur vit encore côté lab parce qu'il a besoin d'un schéma de base ; il
rejoindra `tests/` quand le module portera le sien.

## Licence

AGPL-3.0-or-later — voir le fichier [`LICENSE`](../../LICENSE) à la racine.

## Auteur

**Pierroons** — [github.com/Pierroons](https://github.com/Pierroons)
