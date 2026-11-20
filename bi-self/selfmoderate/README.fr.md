# SelfModerate

> 🇬🇧 **[Read in English →](./README.md)**

**Moteur de modération communautaire autonome par raisonnement social**

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.8.0](https://img.shields.io/badge/status-v0.8.0-yellow.svg)](#statut)
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
- Votes anonymes : la cible voit son score et les raisons, pas qui a voté. Les raisons lui sont rendues **datées au jour**, dans un ordre non chronologique — à la seconde près, recoupées avec les présences, elles désigneraient leur auteur. Limite qu'aucun tri ne lève : sur un unique downvote reçu, la personne devine souvent qui l'a émis. Et l'arrondi ne protège pas d'un chiffre public : si l'hôte affiche sans session le score d'un message ou la réputation d'un auteur, qui les relève assez souvent date chaque vote à la minute, et le message visé

### Score de réputation
- Chaque utilisateur démarre à **20** (`Config`)
- Score plafonné à **30** (`Config`) — pas d'accumulation de crédit social
- Monter est lent, descendre est rapide : un downvote retire un point immédiatement, la remontée passive en rend un par intervalle de calme
- **Convalescence** : sous 5, l'état est posé et le score remonte tout seul **jusqu'à 20**, son point de départ — jamais au-delà. Le droit de vote revient à 5, l'état se lève à 20
- La remontée borne sa propre progression, pas le score : un point gagné par un vote positif pendant la convalescence reste acquis : le droit de vote revient dès que le score atteint 5, et l'état se lève dès qu'il atteint 20, sans attendre le point suivant de la remontée
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
- La perte du droit de vote se **signale à l'arbitre** (`droit_de_vote_perdu`, `signalPerteDuVote`) ; le signal tombe quand le droit revient, ou quand la chute au plancher se signale à son tour
- Score = 0 → **ban temporaire, gradué** : 24 h → 7 j → 30 j en service (`Config::prod()`), 2 → 10 → 30 min en démonstration (`Config::demo()`). Le dernier palier **se reconduit** : la machine ne prononce jamais d'exclusion définitive, au-delà c'est l'arbitre qui décide — lui seul peut poser un ban sans fin
- **Le ban automatique ne tombe que si un journal est branché** (`setJournal()`) et que l'hôte ne l'a pas éteint (`banAutomatique`), et jamais dans la transaction d'un hôte, qui pourrait encore l'annuler. Sans journal, une réputation à zéro lève un signalement, et aucun geste d'arbitre ne passe : ni la machine ni un arbitre ne sanctionnent sans trace. Avec lui, chaque début, chaque fin et chaque levée de ban s'écrit au journal, **avant** la base : une panne laisse une trace sans effet, jamais une peine sans trace. Si le journal refuse d'inscrire le début d'un ban automatique, le vote qui a fait tomber le compte tient : la chute est signalée avec le ban dû (`ban_en_attente`), et le vote négatif suivant retente le ban. Une fin de peine s'inscrit à la première écriture qui touche le compte — un vote, un geste d'arbitre, le balayage —, jamais à la lecture, qui la montre dès l'échéance. Si le journal refuse de l'inscrire, elle reste à refermer : l'écriture qui l'a tentée continue, l'arbitre est prévenu (`journal_en_panne`, et la console d'arbitre le dit pour tout le site), et la clôture suit le retour du journal. D'ici là, la page montre la peine finie, mais seul l'état inscrit autorise une écriture : le compte ne vote pas, et le refus dit pourquoi — mais `canVote()` ne le dit pas d'avance : seul un essai sait si le journal est revenu ; aucun geste d'arbitre ni aucun nouveau ban ne passe sur lui
- **Le quorum aux seuils** (`quorumAuxSeuils`) : la perte du vote et le ban automatique exigent que la chute soit portée par assez de votants **sans lien entre eux** — **6** pour la perte du vote, **7** pour le ban, autant que de votants au plafond pour descendre du départ au seuil (`voixPourSanction()`). Sous le quorum, rien n'est sanctionné : le compte garde son vote, la convalescence s'ouvre, et la chute est signalée à l'arbitre (`chute_sans_quorum`), qui décide. Le signal tombe quand la réputation repasse le seuil du vote, ou quand la sanction tombe ; classé par l'arbitre au seuil du vote, il lui revient quand la chute atteint le plancher. Seul un vote négatif décide d'une sanction : un vote de soutien qui laisse le compte sous le seuil n'en déclenche aucune. Voir [Un lien ne retire rien à personne](#un-lien-ne-retire-rien-à-personne)
- Une peine en cours **ne se rallonge pas** au vote contraire suivant ; seul un arbitre rallonge un ban, et seulement le sien
- Après un ban automatique purgé : score remis à **20**, strikes préservés — le ban suivant sera plus long
- Ce qu'un ban bloque au-delà du vote relève de la plateforme (`estBanni()`). Le lab bloque aussi la publication, et laisse ouverts la connexion et les messages privés
- 3 mois sans incident : remise à zéro des strikes — *pas encore codé*

### Gestes d'arbitre
- **Bannir**, **lever**, **gracier**, **rallonger**, **maintenir**, **classer** : chaque geste est signé du **compte** de l'arbitre, désigné par son identifiant — jamais de celui qu'il vise : un arbitre ne fait aucun geste sur son propre compte, ni aucun tant qu'un autre arbitre l'a banni — un ban d'origine inconnue compte comme tel ; banni par la machine, il garde ses gestes, et des votants ne coupent pas la modération : deux arbitres bannis par la machine peuvent donc se lever l'un l'autre, par deux gestes signés, inscrits comme levées anticipées avant le plancher — et s'écrit au journal, avec les signalements ouverts et ceux qu'il ferme ; sans journal branché, aucun ne passe. Bannir exige un motif, contrôlé comme celui d'un downvote : la personne bannie doit pouvoir lire ce qu'on lui reproche ; il ne ferme aucun signalement, et ne touche pas au droit de vote — un banni ne vote pas, et la perte du vote reste à la réputation et à son quorum. **Lever** arrête la peine : le compte sort comme à l'échéance, signalements d'état compris — après un ban automatique, la réputation repart au départ —, et les strikes restent, ceux d'un arbitre compris : le ban suivant sera plus long. **Gracier** remet au départ le ban, la réputation, les strikes, une suspension de vote posée par une version antérieure et encore en cours, et le rang de meute qu'une version antérieure a consigné — l'historique des épisodes reste lisible. La grâce ferme ce qu'elle défait : les signalements d'état, et la récidive de meute quand elle en efface le rang ; une salve ou un votant étendu restent à classer, motif écrit. **Rallonger** repousse l'échéance d'un ban d'arbitre en cours, motif exigé : même épisode, aucun strike de plus. Un ban automatique ne se rallonge pas : l'arbitre le lève, la récidive restant comptée, puis pose le sien. **Maintenir** prend acte d'un ban automatique : son signalement sort de la file, la peine court ; un ban d'arbitre n'a rien à maintenir. **Classer** ferme un signalement, nommé, sans rien toucher d'autre, motif exigé
- **Un signalement par cause** : chaque cause ouverte sur un compte a sa ligne, et aucune n'en remplace une autre. Un signalement d'état (`reputation_zero`, `ban_auto`, `journal_en_panne`, `ban_en_attente`, `droit_de_vote_perdu`, `ban_sans_fin`, `chute_sans_quorum`) tombe seul quand l'état cesse — `levee_en_attente`, qu'une base d'avant la 0.9.0 peut porter, tombe par la grâce ou par un classement ; un signalement de comportement (`salve_rapide`, `votant_etendu`, `comptes_recents`, `bans_arbitre`, et `meute_recidive` ou `votants_tronques` sur une base d'avant la 0.9.0) ne tombe que classé par un arbitre. Un fait qui se répète fait monter son compte ; un geste reçoit le signalement tel que la console l'a affiché, avec ses détections, et refuse si un vote neuf l'a redétecté depuis — même quand la détection a replié une salve d'autres votants dans le même fait : l'arbitre ne ferme pas ce qu'il n'a pas vu. Le balayage, qui relit des votes déjà détectés, ne fait refuser que sur un fait neuf. Le votant étendu et la part des comptes récents font exception : chez eux, seul un fait compté fait refuser, au plus une fois par `balayagePeriodeSecondes` depuis le précédent, sans quoi chaque vote priverait l'arbitre du classement aussi longtemps qu'il dure. La console montre une page par cause, la plus ancienne en tête : un flot de salves remplit la sienne et ne pousse rien d'autre hors de la vue
- **Bans d'arbitre répétés** : un ban d'arbitre compte comme un épisode, et alourdit le ban automatique suivant — la récidive se paie, quelle que soit la main qui l'a constatée. L'acte de début de ban dit combien de ses épisodes viennent d'un arbitre, et au **2ᵉ ban d'arbitre** sur un même compte (`bansArbitreSignal`), un signalement `bans_arbitre` part dans la file des arbitres : un enchaînement ne passe plus sans être signalé, mais rien n'empêche l'arbitre qui a banni de classer ce signal lui-même. La grâce, qui remet les strikes à zéro, le ferme ; la levée, qui les garde, ne le ferme pas. Ces bans se comptent depuis la 0.7.0 : une base migrée part de zéro
- **Plancher** : avant un tiers de la peine **en cours** (`Config::$plancherFraction`), une levée exige un motif écrit et s'inscrit comme **levée anticipée**, avec le temps qui restait. Un tiers de la peine en cours, jamais du palier maximal : sinon le plancher d'un premier ban d'un jour durerait dix jours. Le plancher ne ferme pas le favoritisme ; il le rend coûteux, parce qu'il laisse une trace signée. Il vaut pour lever comme pour gracier
- **Ban sans fin** : seul un arbitre le pose (`adminBan()`), ou y porte un ban d'arbitre en cours (`adminProlonger()`) ; un ban automatique ne devient jamais sans fin. Son échéance est `Moderate::SANS_FIN`, le 31/12/9999, et n'arrive pas ; le lever ou le gracier exige toujours un motif écrit. Une échéance à plus d'un siècle (`Moderate::DUREE_MAX`) est refusée : un ban qui ne doit pas finir se pose sans fin, plutôt que de s'afficher comme tel sans en porter la règle. Il reste parmi les signalements ouverts (`ban_sans_fin`), hors de la file : l'arbitre qui l'a posé l'a vu
- **L'historique des peines** : chaque peine a sa ligne dans `mod_peines`, écrite à son début — qui l'a prononcée, sur quel motif, son échéance initiale et la courante, la dernière décision qui la porte (`decide_le`) et son auteur (`decide_par`), et à sa fin l'instant, la cause (échéance, levée, grâce, `hors_moteur` quand `migrerBans()` ferme une ligne laissée ouverte, ou meute reconnue sur une base d'avant la 0.9.0) et l'arbitre. Ses causes y sont **figées** : les votes à effet négatif en cours quand elle a commencé — pour un ban automatique, ceux qui l'ont causé ; pour un ban d'arbitre, le contexte. La fin d'un ban automatique remet ces effets à zéro ; la copie reste, et dit après l'échéance qui avait fait tomber le compte. Supprimer un compte efface son historique ; le journal garde les actes

### Un lien ne retire rien à personne
Deux comptes sont liés quand chacun a écrit à l'autre en privé sur les **30 derniers jours** (`fenetreMeuteJours`). Le
lien est le seul indice d'une coordination, et il ne distingue d'une meute ni deux amis de bonne foi qui réagissent au
même message pénible, ni un lien **fabriqué par la cible elle-même** — un compte jetable à qui l'on a répondu une fois.
Tout pouvoir donné au lien sur les votes d'un autre est donc à la main de la cible : un compte lié d'avance à chacun de
ses critiques ferait tomber leurs votes. Le moteur n'annule aucun vote pour un lien, et ne punit personne pour un lien.
Le lien ne compte qu'aux seuils.

| Ce qui arrive | Ce que fait le moteur |
|---|---|
| des votants liés visent la même cible | leurs votes portent, comme ceux de n'importe qui |
| la cible passe sous un seuil | il cherche le plus grand nombre de votants dont aucun n'est lié à un autre |
| ce nombre atteint le quorum | la sanction tombe |
| il ne l'atteint pas | rien n'est sanctionné, et la chute est signalée à l'arbitre (`chute_sans_quorum`) ; classée, elle lui revient quand elle s'aggrave — à l'arrivée au plancher, ou quand, au plancher, elle retire le droit de vote |

- **Le prix assumé** : sept amis qui se sont tous écrit ne pèsent qu'une voix. Leur cible peut descendre à zéro sans ban
  ni perte du vote ; elle est signalée, et l'arbitre la voit. Une sanction méritée vient alors de l'arbitre, ou de
  votants qui ne se connaissent pas
- **Les voix** : le quorum lit tous les votants dont le solde des votes qui portent contre le compte est négatif, sur
  la fenêtre des plafonds (`fenetreFarmingJours`) ouverte après la dernière chute soldée — la fin d'un ban automatique,
  échu ou levé, ou une grâce : les votants d'une chute soldée ne portent pas la suivante. La sortie de convalescence ne
  solde rien : des soutiens qui hâtent la remontée n'effacent pas les voix des critiques. Un vote suivi de son contraire
  ne fait pas de voix, ni un vote d'avant la colonne `effet`
- **Aucune borne** sur le nombre de votants lus : une borne remplie dans un ordre d'arrivée se remplirait des comptes
  de la cible
- **Les liens d'une paire se lisent depuis `fenetreMeuteJours` avant le plus ancien vote compté de ses deux membres** :
  une clique qui cesse de s'écrire reste une clique tant que ses votes comptent, et personne hors de la paire ne règle
  sa profondeur — le vote ancien d'un compte de la cible n'allonge que les liens de ce compte
- **Les comptes récents** font une voix à eux tous, comme ils font une part : un votant dont chaque vote négatif a été
  émis pendant qu'il était récent sort du graphe, et sa classe y ajoute une voix
- **La recherche est exacte** jusqu'au quorum : le votant le moins lié d'abord, une branche arrêtée par une couverture
  en cliques. Son travail est borné à **200000** sommets lus (`quorumTravailMax`), et la lecture des liens à **200000**
  messages entre votants (`quorumMessagesMax`) : au-delà, seuls les votants liés à personne font des voix, cherchés
  compte par compte. À court avant de décider, elle ne sanctionne que ce que portent les voix déjà trouvées, et la
  composition de la chute le dit (`indecis`)
- **Rien ne se rejuge en arrière** : un lien formé après une sanction n'y change rien. Le quorum se cherche à chaque
  vote qui laisse le compte sous le seuil du vote, tant qu'une sanction reste à décider
- **Ce que l'arbitre voit** : `compositionChute()` rend les votants que le quorum lit, leurs voix sans lien, les paires
  liées (aucune valeur quand leurs messages dépassent la borne de lecture), les comptes récents, et si la recherche a
  décidé ; la console du lab l'affiche pour un ban automatique comme pour une chute sans quorum. Seule la dernière chute
  soldée est gardée : relue après la fin d'un ban qui suivait une autre chute soldée, sa composition compte aussi les
  votants de celle-ci
- Les épisodes de meute qu'une version antérieure consignait restent lisibles (`rangDe()`, sur son propre profil
  seulement) ; aucun ne s'inscrit plus, et la grâce efface encore un rang
- **Limite : ce que le quorum ne voit pas.** Une coordination sans lien sur la plateforme — organisée ailleurs, ou une
  étoile dont seul le meneur a écrit à chacun — fait autant de voix que de comptes, et bannit dès qu'elle en a sept
- **Limite : les critiques compensés.** Sans chute soldée, un vote négatif compensé par des votes de soutien reste une
  voix : des critiques sans lien qu'autant de soutiens ont compensés, puis une clique, font le quorum du ban
- **Limite : ce que le droit de vote dit.** Sous le seuil du vote, le droit de vote d'un compte dit si le quorum est
  atteint : à qui sait qui a voté contre lui, il dit si deux de ses critiques se sont écrit en privé — un bit par paire,
  au prix de comptes établis qui votent. La réputation exacte le laissait déjà lire
- **Limite : le graphe trop lourd.** Au-delà de `quorumMessagesMax` messages privés entre les votants d'une chute, seuls
  les votants liés à personne font des voix. Deux comptes établis de la cible qui votent contre elle et s'écrivent autant
  de messages y suffisent : un critique qui a échangé avec un autre votant, ou avec l'un de ces comptes, ne compte plus.
  La chute se signale indécise. Elle ne coûte rien de durable à la cible : ses comptes peuvent lui rendre sa réputation
  sans cesser d'être des votants — deux votes contre, un pour, leur solde reste négatif. La borne protège le temps d'un
  vote, que le moteur tient sous le verrou d'écriture
- **Limite : la meute qui soutient.** Le quorum ne lit que les chutes. Des comptes liés qui votent tous pour un même
  membre le portent jusqu'au plafond, ce qui le rend plus long à faire tomber ; le plafond par paire les borne chacun,
  pas leur nombre, et seule la part des comptes récents borne ceux-là ensemble
- **Limite : la plateforme sans messages privés.** Le lien ne se lit que dans `dm`. Vide, aucun votant n'est lié :
  chaque votant fait une voix, et une meute autant que ses membres réunis
- **Limite : les comptes récents.** La part borne les comptes jeunes, pas les comptes
  vieillis : des comptes créés d'avance et laissés dormants pèsent chacun de tout leur poids
  une fois établis. Sur un site jeune, où tout le monde est récent, aucun membre ne perd plus
  que la part pendant `ageEtabliSecondes` : un hôte qui ouvre règle 0, ou laisse l'arbitre
  décider seul ce premier mois. Un vote tombé dans la part ne compte pas dans les plafonds de
  son auteur : il n'a rien déplacé, et une cible qui remplirait la part de ses propres comptes, le temps de les
  croiser, ne ferait pas taire les comptes récents qui votent entre-temps pour toute la fenêtre. Et la suppression d'un compte
  efface ses votes : elle libère la part sans rendre ce qu'ils ont pris. **Ce que la part laisse lire** : le vote
  neutralisé répond comme un vote compté, mais le score du message visé ne bouge pas, et la liste publique des votes
  neutralisés porte la cause `comptes_recents` : au rechargement, son auteur lit que d'autres comptes récents ont déjà
  voté contre ce membre
- **Limite : l'existence d'un compte.** Le motif est facultatif au vote positif : un vote
  positif sans motif sur un identifiant passe s'il existe et refuse sinon, et donne un
  point à la cible. Il dit donc si un compte existe ; le plafond par paire le borne, pas
  le nombre d'identifiants sondés. Un vote négatif, lui, exige un motif valide avant de
  regarder la cible

### Anti-manipulation
- **Anti-Sybil** : délai sur les nouveaux comptes, sauf s'ils ont déjà publié : **24 h** en service, aligné sur la période d'échauffement décrite par [Bi-Self](../README.fr.md), **2 min** en démonstration. Le refus dit l'attente restante
- **Comptes récents** : un compte de moins de **30 jours** en service, **1 h** en démonstration (`ageEtabliSecondes`), est récent au moment de son vote. Ensemble, les comptes récents ne déplacent pas la réputation d'un même membre de plus qu'un votant seul : ce que leurs votes qui portent ont déplacé se compte en solde net sur 60 jours, et une fois le plafond d'un votant atteint dans un sens, le vote suivant d'un compte récent dans ce sens est neutralisé (`comptes_recents`) et le membre visé est signalé à l'arbitre. Le vote répond « Vote enregistré », comme un vote compté : la part se remplit des votes d'autres comptes, et la dire pleine leur apprendrait ces votes. Le solde est net : un vote suivi de son contraire, ou un vote que la cible fait tomber en le croisant, n'occupe pas la part. Un vote qui porte sans rien déplacer — à 30 ou à 0, ou après une remise — y compte pour un dans son sens et pour rien dans l'autre : aux bornes, la part borne encore les scores des messages et les motifs. Un vote neutralisé par la part n'entre ni dans les plafonds de son auteur ni dans une salve
- **Liens** : des votants liés entre eux gardent tous leurs votes ; au quorum des seuils, ils ne comptent que pour les voix qu'ils font sans lien entre eux. Un lien ne se propage pas : A–B et B–C liés, A et C ne se sont jamais écrit, et font deux voix
- Ce qui lie deux comptes dépend de la plateforme. Sur un forum : un **message privé dans chaque sens**, l'équivalent le plus proche d'une invitation acceptée. Un message resté sans réponse ne dit aucune entente : écrire à des votants ne les lie à personne. Le contenu des messages n'est **jamais lu** — seulement qui a écrit à qui
- **Salve rapide** : plusieurs votants contre la même cible dans une fenêtre courte (5 min en service, 1 min en démonstration), liés ou non ; un vote que la part des comptes récents a neutralisé n'y entre pas. Ce n'est pas une coordination, c'est le plus souvent la même réaction au même message : rien n'est annulé, la cible part en revue humaine. Annuler ici protégerait un message d'autant mieux qu'il choque plus de monde à la fois. Le signal d'une salve n'est jamais écrasé par celui d'un ban
- **Ce que ni le quorum ni la salve ne voient** : une coordination organisée ailleurs, entre comptes qui ne se sont jamais écrit sur la plateforme, et qui espace ses votes au-delà de la fenêtre de salve. Rien ne la reconnaît. Ce que l'arbitre en voit : la **composition de chaque ban automatique** — les votants que le quorum lisait à son début, l'âge de leurs comptes, en combien de temps, et ce que le quorum en tirait
- **Farming** : au-delà de 3 votes positifs d'un même votant vers un même membre en 60 jours, les suivants sont neutralisés ; même plafond pour les votes négatifs, contre l'érosion lente d'un votant patient qui vise chaque message. Et un votant ne vise pas plus de 10 membres distincts sur la même fenêtre (`ciblesParVotantMax`) : au-delà, ses votes négatifs vers un membre de plus sont neutralisés, et il est signalé à l'arbitre
- **Vote croisé** : deux membres qui votent l'un contre l'autre en moins de **72 h** en service, **10 min** en démonstration (`fenetreCroiseeSecondes`), quel que soit le message visé, se croisent : les deux votes tombent, dans la transaction du second — un vote contre un vote, le plus récent de l'autre. Chacun rend le point qu'il avait retiré, et **aucune peine n'est levée** : une peine causée entre-temps reste à l'arbitre. Une **trêve** de la même durée suit le croisement : aucun des deux ne vote plus contre l'autre, et ces votes tombent. Tous comptent dans les plafonds de leur auteur — sans quoi un membre qui riposte contre chacun de ses critiques n'aurait aucune borne. **Sous le seuil du vote, plus de croisement, dans aucun sens** : un compte sous le seuil n'est croisé par personne et ne croise personne, trêve comprise — il effacerait sinon chacun de ses critiques avant qu'il fasse une voix. Ses propres votes portent donc à plein : un membre qui se tient sous le seuil sans quorum vote sans pouvoir être croisé, dans les plafonds de tout votant. Remonté au seuil, il croise de nouveau les votes reçus pendant sa chute qui sont encore dans `fenetreCroiseeSecondes` : c'est la riposte en série ci-dessous. **Ce que cela laisse lire** : tant que la réputation publique est exacte, chacun des deux lit le croisement dans les réputations — le point de l'autre ne bouge pas, le sien revient —, et de même dans le score des messages, au rechargement : celui que l'autre visait revient, celui qu'on visait ne bouge pas. La riposte tombe sous sa propre raison (`riposte`), le vote qu'elle croise sous `vote_croise` : seul ce dernier, qui avait porté, garde son motif et sa place parmi les votes reçus de sa cible (`reasonsFor()`, `votesRecus()`) ; ni la riposte ni un vote de trêve ne s'y montrent : au croisement, ni les motifs ni le compte des votes reçus de l'un ou de l'autre ne changent. Le vote répond « Vote enregistré », comme un vote compté, `userVote()` le rend à son auteur, la route de vote du lab ne rend que `ok` et le message, et la page publique du lab ne le liste pas. **Limite : la riposte en série.** Un membre qui riposte à chacun de ses critiques fait tomber un vote de chacun, et ceux qu'il reçoit d'eux pendant la trêve : au plus **10** critiques distincts sur **60 jours** (`ciblesParVotantMax`, `fenetreFarmingJours`), et **3** votes contre chacun d'eux (`farmingDownvotesMax`)
- **Protection des victimes** : une chute que des votants liés portent seuls ne sanctionne pas, et l'arbitre en est saisi — *partiel : un recours de la victime auprès de l'arbitre est prévu, pas encore codé*

## Intégrer

Le moteur est une classe statique ; l'hôte fournit la connexion `PDO` à chaque appel.

| Appel | Rôle |
|---|---|
| `setHorloge(?callable)` · `maintenant()` | l'heure que lit le moteur : `time()` par défaut, la tienne si tu simules le temps (une démonstration, un banc) ; `maintenant()` la rend, pour que tes pages affichent ce que le moteur décide. Une horloge qui rend autre chose qu'un instant Unix entier, positif et avant `Moderate::SANS_FIN` lève une `LogicException` ; une horloge qui recule fait reculer le moteur avec elle — une chute soldée « plus tard » ne borne plus les voix, et ses votants portent la suivante — : à toi de la tenir monotone. Le journal date ses entrées à sa manière |
| `setConfig(Config)` | les seuils : `Config::demo()`, `Config::prod()` ou les tiens. **Seule source** des seuils : le moteur les lit là, et tes pages aussi, par `Moderate::config()` |
| `setJournal(Journal)` | branche le journal, et avec lui le ban automatique et les gestes d'arbitre. L'interface [`Journal`](./src/Journal.php) liste les actes et leurs clés ; l'écriture doit être durable et non réécrivable. Un refus remonte toujours, sauf quand un vote ou un geste constate une peine échue : sa clôture attend alors, et l'arbitre est prévenu (`journal_en_panne`) ; et sauf au début d'un ban automatique : le vote qui l'a causé tient, et l'arbitre est prévenu (`ban_en_attente`). Un refus se lève par une exception ; une `\Error` levée sur le chemin du balayage est un défaut, et remonte. Un hôte qui n'a pas pu construire son journal ne sert ni vote ni geste : sans journal, un vote referme une peine échue sans l'inscrire |
| `balayerBansEchus(PDO)` | inscrit en une passe ce que le temps a fait — les fins de peine, au journal d'abord, et les remontées de convalescence —, pour un planificateur ou la console d'arbitre, qui lit la base et l'appelle avant de la lire. Sans planificateur, le premier vote négatif de chaque tranche de balayage le fait ensuite, pour **50** comptes au plus (`balayageFiletMax`), dans sa propre requête et sous le verrou d'écriture : un planificateur épargne ce coût au votant. Un vote fait dans la transaction d'un hôte prend la tranche sans balayer, et un hôte qui fait voter dans ses transactions a besoin d'un planificateur. Sinon, une fin s'inscrit à la première écriture qui touche le compte. Après un premier refus du journal, la passe ne l'appelle plus : elle inscrit encore les convalescences, laisse les fins à la passe suivante — où les comptes déjà refusés passent en dernier, le plus anciennement refusé d'abord —, et le refus remonte en fin de passe. Un compte qui attend la reprise d'avant la 0.7.0 est sauté, et nommé en fin de passe ; un refus du journal dans la même passe reste alors signalé sur son compte (`journal_en_panne`), sans remonter. Hors de la transaction d'un hôte seulement : une fin y serait inscrite au journal et survivrait à son annulation. Le journal porte l'échéance ET l'instant de l'inscription |
| `applyVote(PDO, votant, type, cible, valeur, ?motif)` | un vote, qui rend `ok`, `blocked` et `message` — jamais la réputation de la cible, que le profil tait sous le seuil du vote. Il s'inscrit tout ou rien : dans une transaction que le moteur ouvre (`BEGIN IMMEDIATE`) quand l'hôte n'en a pas, derrière un point de reprise (`SAVEPOINT`) dans celle de l'hôte sinon, à qui il revient alors de valider ou d'annuler le reste. Dans la transaction de l'hôte, il n'arme pas le ban automatique : une réputation à zéro y est signalée, comme sans journal. Une clôture de peine faite là, puis défaite avec elle, se refait au passage suivant : le journal porte alors l'acte deux fois. Le premier `applyVote()` d'une transaction d'hôte y prend le verrou d'écriture, refus compris — sauf celui de paramètres invalides —, et l'hôte le garde jusqu'à sa fin ; une transaction différée qui a déjà lu ne peut plus le prendre sous un autre écrivain, et échoue aussitôt : l'ouvrir en `BEGIN IMMEDIATE`, ou faire voter avant toute lecture. Le premier vote négatif d'une tranche balaie le site : ce qu'il trouve sur un autre compte que sa cible — une reprise oubliée, tout refus par une exception — ne le fait pas tomber ; ce compte est sauté, et le balayage suivant le reprend. Un vote qui porte et un vote croisé répondent tous deux « Vote enregistré », et `userVote()` rend à son auteur son vote, qu'il porte ou non : sa page ne dit pas ce qu'en a fait le moteur. La route de l'hôte ne rend au votant que `ok` et `message` : `blocked`, `blocked_reason` ou le score à jour lui diraient ce qu'en a fait le moteur — et, pour un vote croisé, que sa cible venait de voter contre lui |
| `getReputation(PDO, id)` · `canVote(PDO, id)` | lisent sans rien écrire, ni en base ni au journal, et sans prendre de verrou : l'état rendu est celui de l'instant — remontée de convalescence et fin de peine comprises —, même quand la base ne les porte pas encore, et `fin_en_attente` dit qu'une fin attend son inscription. Un compte sans ligne se lit au départ, sans qu'une ligne soit créée. Un votant absent d'`accounts`, ou dont `created_at` n'est pas un horodatage entier en secondes, ne vote pas, au refus de `canVote()` comme à celui d'`applyVote()`. Les colonnes de `member_moderation` sont l'état que le moteur a inscrit : un hôte les lit, il ne les écrit pas. **Limite** : pour un ban automatique, `ban_debut` est la seconde du vote qui l'a causé ; une page que voit le compte banni l'arrondit, comme `peinesDe()` le fait au jour. Sa fin s'arrondit dès la pose, au multiple suivant de **1 h** en service, **1 min** en démonstration (`arrondiFinBanSecondes`) : ni la page ni l'instant où le compte revote ne redonnent cette seconde. `finAffichee(fin, origine)` rend la fin qu'une page montre — arrondie de même pour un ban posé avant cette règle —, que la page dit « au plus tard » ; le refus de `canVote()` passe par elle. Un ban posé avant cette règle, ou lu après un changement de pas, cède plus tôt que la page ne le dit |
| `estBanni(PDO, id)` · `bansEnCours(PDO)` | ce que l'hôte bloque, et ce que l'arbitre doit voir : origine, motif, fin, temps restant, plancher, et pour un ban automatique la composition de sa chute ; `sans_fin` dit un ban sans échéance, que `Moderate::estSansFin()` reconnaît aussi d'après `banned_until` ; `arbitre` le compte qui l'a posé et `decide_par` celui de sa dernière décision (`{id, nom}`, null pour la machine ou une peine reprise), `peine` sa ligne d'historique, `echeance_initiale` et `prolongations` ce qu'il a été avant d'être rallongé |
| `peinesDe(PDO, id, ?limite, ?precis)` | l'historique des peines d'un compte, les 50 plus récentes par défaut, la plus récente en tête : origine, épisode, motif, arbitre, début, échéances, dernière décision et son auteur, fin et sa cause, et le **nombre** de votes et de votants de ses causes. Des nombres, pas des noms ; et des **jours**, pas des secondes : le début d'une peine automatique est l'instant du vote qui l'a causée, et à la seconde il désignerait son auteur. Les dates sont ramenées à minuit, au fuseau de l'hôte ; `precis: true` les rend à la seconde, pour la console d'un arbitre — jamais pour une page que voit le compte banni. `fin` reste null tant qu'une peine échue n'est pas inscrite, quand `getReputation()` la dit déjà finie |
| `votesRecus(PDO, id)` | le nombre de votes reçus qui ont porté — comptés, ou croisés après avoir compté : ceux dont `reasonsFor()` rend le motif. Une page qui affiche des votes reçus compte ceux-ci : une riposte ou un vote de trêve, comptés, désigneraient leur auteur |
| `blockedVotes(PDO, ?limite, ?saufRaisons)` | les votes bloqués, les plus récents d'abord, votant et cible compris : une vue d'arbitre. Une page publique en retire les noms et écarte `Moderate::RAISONS_DU_CROISEMENT` dans la requête (`saufRaisons`), avant la limite — retirées après, elles feraient baisser le compte, qui daterait chaque croisement |
| `adminBan(PDO, id, arbitre, motif)` · `adminLever(PDO, id, arbitre, ?motif)` · `adminPardon(PDO, id, arbitre, ?motif)` · `adminProlonger(PDO, id, arbitre, motif, secondes)` · `adminMaintenir(PDO, id, arbitre, motif, ?vu)` · `adminClasser(PDO, id, arbitre, motif, ?vu)` | les gestes d'arbitre, qui rendent `['ok' => bool, 'message' => string]`. `arbitre` est l'**identifiant** du compte qui signe (`accounts.id`, un entier) — jamais un nom : un nom se prend, et un tiers inscrit sous celui d'un modérateur gèlerait ses gestes ou les signerait. L'identifiant se vérifie à l'exécution : une chaîne, un flottant ou un booléen lèvent une `TypeError`, même chez un hôte hors du mode strict, où PHP les convertirait. Sont refusés un identifiant sans compte, un geste sur le compte de l'arbitre lui-même, et tout geste d'un arbitre qu'un autre arbitre a banni — lu sous le verrou du geste ; banni par la machine, il garde ses gestes. L'acte porte le nom (`arbitre`) et l'identifiant (`arbitre_id`). `adminBan()` prend aussi une durée, et `sansFin` ; `adminProlonger()` ne rallonge qu'un ban d'arbitre, d'une durée ou par `sansFin` ; une échéance à plus d'un siècle (`Moderate::DUREE_MAX`) est refusée. Chacun relit l'état et décide sous le verrou d'écriture, pris d'emblée. Ils refusent dans la transaction d'un hôte : le journal ne se défait pas avec elle. `vu` est le signalement affiché, `['signal' => id, 'detections' => n]` ; sans lui, maintenir et classer visent le seul signalement de leur ressort, et refusent s'il y en a plusieurs |
| `migrerBans(PDO)` | met `mod_peines` d'accord avec les bans : à appeler juste après avoir créé la table, et de nouveau après une montée faite service en marche. Un ban sans ligne en reçoit une — la ligne fermée qui porte son début est rouverte, sinon une ligne s'ouvre, où ce que la base ignorait reste inconnu (`reprise`) : l'arbitre, l'échéance d'avant une prolongation, un début non enregistré (`debut = 0`). Une ligne ouverte qui ne dit plus le ban en cours — aucun ban, ou un ban d'un autre début, levé puis reposé hors du moteur — se ferme, cause `hors_moteur` ; une échéance prolongée hors du moteur se recale, et sa ligne prend `reprise`, la prolongation n'étant pas connue. Rejouée sur une base d'accord, elle ne fait rien. Tant qu'ils divergent, le moteur refuse — à la lecture d'un compte banni sans ligne, ou dont la ligne ouverte porte un autre début ou une autre échéance, au ban d'un compte qui porte une ligne ouverte, aux listes de l'arbitre — par une `RepriseManquante` qui nomme `migrerBans()` |
| `migrerSignaux(PDO)` | reprend dans `mod_signaux` la case unique d'avant la 0.7.0 : à appeler une fois, juste après avoir créé la table ; la marque qui l'empêche de se rejouer est à l'hôte. Tant qu'elle n'a pas tourné, le moteur refuse de lire ou de signaler un compte dont la case est ouverte, par une `RepriseManquante` (une `LogicException`) qui la nomme ; les listes de l'arbitre la lèvent aussi |
| `flaggedForReview(PDO)` · `signauxOuverts(PDO)` | les comptes à arbitrer, le plus ancien en tête, une ligne par compte avec tous ses signalements ; et les signalements à voir, une page par cause, la plus ancienne en tête, avec le total de chaque cause |
| `dureeEnClair(secondes)` | une durée lisible, unités passées par ton traducteur (`setTranslator()`) |

**Schéma attendu** — celui du lab fait référence ([`demo/lab/schema.sql`](../../demo/lab/schema.sql)) : `member_moderation` (réputation, strikes dont ceux d'un arbitre (`strikes_admin`), droit de vote, `banned_until`, `ban_debut`, `ban_origine`, `ban_motif`, signalement, convalescence, suspension de vote, `chute_soldee_le`, l'instant de la dernière chute soldée), `mod_votes` (dont `effet`, ce que le vote a réellement changé à la réputation ; un vote antérieur à la colonne la laisse à `NULL`, sans effet connu : il ne se rend pas et ne compte comme cause d'aucun ban), `mod_pack_flags` (les épisodes de meute d'avant la 0.9.0, lus et plus écrits), `mod_peines` et `mod_peine_causes` (l'historique des peines et leurs causes figées ; une seule peine ouverte par compte, que la base garantit par un index unique partiel), `mod_signaux` (une ligne par signalement ouvert, par compte et par cause ; `needs_review` et `review_reason` restent, résumé que le moteur tient pour qui lit la base ; il ne le relit que pour refuser une case ouverte sans signal, et l'hôte ne l'écrit pas — une base d'avant la 0.7.0 crée la table puis appelle `migrerSignaux()` une fois, sans quoi le moteur refuse tout compte dont la case est ouverte), `mod_sweeps` (une ligne par tranche de `balayagePeriodeSecondes` déjà balayée ; sans elle, chaque vote négatif échoue au lieu de laisser le filet s'éteindre), et trois tables de l'hôte lues sans être écrites : `accounts(id, username, created_at)`, `posts(id, account_id)`, `dm(sender_id, recipient_id, created_at)`. Le moteur écrit du SQL SQLite. Le quorum ne lit que `dm` : vide, aucun votant n'est lié ; absente, ni table ni vue, ou datée autrement qu'en secondes Unix — une date en clair, des millisecondes, un jour julien : les messages des votants sont sondés à chaque lecture, et une date qui n'est pas du même ordre de grandeur que maintenant, à un facteur 100 près, est refusée ; un instant écrit en chiffres, `strftime('%s', …)`, se lit —, une chute sous un seuil lève une `LogicException` qui la nomme. L'index `dm(recipient_id, created_at)` sert la sonde et la lecture des liens, tant que la colonne des dates est numérique. Un message reçu sous un blocage, si l'hôte en offre un, n'entre pas dans `dm` : il y ferait un lien entre qui écrit et qui l'a bloqué — entre un harceleur et sa victime ; un blocage ne retire rien de ce qui y est déjà. Un hôte dont les tables portent d'autres noms les expose sous ceux-là par des vues temporaires, créées sur la connexion qu'il passe au moteur (`CREATE TEMP VIEW accounts AS SELECT …`), sans rien écrire dans son schéma ; les clés étrangères des tables du moteur visent alors ses vraies tables, car une clé étrangère ne vise pas une vue.

Deux index partiels servent les listes de l'arbitre et `bansEnCours()` : `member_moderation(account_id) WHERE needs_review = 1` et `member_moderation(banned_until) WHERE banned_until > 0` ; le balayage, qui lit aussi la convalescence, parcourt la table. Le module exige les extensions `pdo_sqlite` et `mbstring`.

**Ce que l'hôte règle en plus des seuils**, toujours dans `Config` : le ban automatique (`banAutomatique`, **actif** par défaut ; éteint, une réputation au plancher est signalée à l'arbitre, sans ban) ; le signal de perte du droit de vote (`signalPerteDuVote`, **actif** par défaut, entre le seuil du vote et le plancher) ; le quorum aux seuils (`quorumAuxSeuils`, **actif** par défaut ; éteint, la réputation seule décide, comme avant la 0.9.0), et ses deux bornes de travail (`quorumTravailMax`, `quorumMessagesMax`) ; les messages publiés qui dispensent de l'ancienneté anti-Sybil (`messagesPourVoterAvantAge`, **1** par défaut ; 0 n'en dispense personne) ; le nombre de votants distincts à partir duquel `reasonsFor()` rend les motifs reçus (`motifsVotantsMin`, **1** par défaut ; sous ce nombre, un motif peut désigner son auteur, et rien n'est rendu). Le seuil compte des votants distincts dont le vote a porté : deux comparses dont les votes ont porté, puis ont été croisés, le franchissent, et le croisement rend à la cible les points qu'ils lui avaient coûtés.

Les dates de ses messages, et le jour des motifs que `reasonsFor()` rend, suivent le fuseau de l'hôte (`date_default_timezone_set()`).

## Documentation

- Whitepaper technique (FR) — rédigé, pas encore publié dans le dépôt
- Modèle de menace — à écrire

## Statut

🟢 **v0.8.0** — le moteur est ici, dans `src/`, et le lab l'importe comme il
importe SelfRecover et SelfDataGuard.

**La 0.9.0 s'écrit.** Son socle :
- **l'historique des peines** (`mod_peines`, `mod_peine_causes`, `peinesDe()`) ; `bansEnCours()` dit qui a posé le ban ;
- **l'arbitre est un compte**, désigné par son identifiant, jamais celui qu'il vise ni un compte qu'un pair a banni ; l'acte porte `arbitre_id` ;
- **une horloge réglable** (`setHorloge()`, `maintenant()`) ;
- une échéance de plus d'un siècle se refuse : ce ban-là se pose sans fin ;
- **un lien n'annule plus aucun vote** : la perte du vote et le ban attendent un quorum de votants sans lien entre eux,
  et une chute sans quorum se signale à l'arbitre (`chute_sans_quorum`) ; les comptes récents pèsent ensemble comme un
  seul membre.

**Monter depuis la 0.8.0** : ajouter `member_moderation.chute_soldee_le` (`INTEGER NOT NULL DEFAULT 0`), créer `mod_peines`, `mod_peine_causes` et les deux index partiels comme le schéma du lab
**avant** de servir le code de la 0.9.0 — sans les tables, toute lecture échoue (`no such table`) ; puis appeler
`migrerBans()`, sans quoi le moteur refuse tout compte banni. Monter service arrêté, ou relancer `migrerBans()` après
la bascule : une peine que la 0.8.0 ferme, rouvre ou prolonge pendant la montée laisse une ligne qui ne la dit plus.
Passer aux gestes d'arbitre l'**identifiant** du compte qui les signe (un entier) : un nom, une chaîne numérique ou un
booléen lèvent une `TypeError`, même hors du mode strict. Un `Journal` qui filtre les
clés accepte `arbitre_id`.
`Config` refuse un palier `dureesBan`, ou un `dureeBanAdminSecondes`, de plus d'un siècle, et un pas
`arrondiFinBanSecondes` plus long que le plus court palier. Au journal, la `duree` d'un ban automatique est sa peine,
fin arrondie comprise : elle dépasse son palier de moins d'un pas.
`Config` perd `meuteLiensMin`, `meuteVotantsMax` et `meuteEpisodeCooldown`, qu'aucun code ne lit plus : une
configuration qui les passe par nom lève à sa construction — passée par position, ses valeurs glisseraient sans erreur ;
la passer par nom. Elle gagne `ageEtabliSecondes` (actif), `quorumAuxSeuils` (actif), `quorumTravailMax` et
`quorumMessagesMax`. Sans la colonne `chute_soldee_le`, la première écriture lève une `LogicException` qui la nomme. Les
dates de `dm` des votants sont sondées : une messagerie datée en millisecondes lève au lieu de ne lier personne. Un vote peut être neutralisé par la part des comptes récents (`blocked_reason` `comptes_recents`),
et le membre visé signalé sous la même cause. Un ban d'arbitre ne retire plus le droit de vote.
`detectPackVoting()` garde ses clés : `pack_detected` est toujours faux, `cancelled_votes` nul, `packs` et
`voters_truncated` vides. Une console d'arbitre nomme la cause `chute_sans_quorum`, et `compositionChute()` rend en plus
`voix_sans_lien`, `recents`, `paires_liees` et `indecis`. `mod_pack_flags` n'est plus écrit.

La 0.8.0 répond aux demandes d'un deuxième hôte, sans changer de schéma :
- **lever n'est plus gracier** : `adminLever()` arrête une peine et garde la récidive, `adminProlonger()` rallonge un
  ban d'arbitre en cours, et un arbitre peut poser un ban sans fin (`Moderate::SANS_FIN`), dont on ne sort qu'avec un motif ;
  la console du lab sépare lever, gracier et rallonger ;
- **un refus du journal au début d'un ban automatique ne défait plus le vote** : le ban dû se signale
  (`ban_en_attente`), et le vote négatif suivant le retente ;
- **un votant absent d'`accounts`, ou dont la date de création n'est pas un horodatage en secondes, ne vote plus** ;
- **une table `dm` absente lève une erreur qui la nomme** ;
- **quatre réglages** : le ban automatique peut s'éteindre (`banAutomatique`), la perte du droit de vote se signale
  (`signalPerteDuVote`), l'exemption anti-Sybil se règle (`messagesPourVoterAvantAge`), et `reasonsFor()` se tait
  sous un nombre de votants distincts (`motifsVotantsMin`).

**Monter depuis la 0.7.1** : rien dans la base. Un `Journal` qui filtre les actes accepte `ban_prolonge`, la cause
`levee` de `ban_leve` et la clé `sans_fin` ; `duree` et `reste` y valent `null` pour un ban sans fin. Une console
d'arbitre nomme les causes `ban_en_attente`, `droit_de_vote_perdu` et `ban_sans_fin`, et une page qui date une
échéance teste d'abord `Moderate::estSansFin()`. Le signal de perte du droit de vote est actif par
défaut, et ajoute des signalements à la file de l'arbitre : `signalPerteDuVote: false` garde celle de la 0.7.1.
`Config` refuse désormais un palier `dureesBan`, ou un `dureeBanAdminSecondes`, qui atteindrait `Moderate::SANS_FIN` :
une configuration qui écrivait `PHP_INT_MAX` pour dire « infini » lève à sa construction.

La 0.7.1 corrige ce qu'un audit de la 0.7.0 a relevé, sans changer de schéma :
- **une reprise oubliée se dit à l'arbitre** : `bansEnCours()`, `flaggedForReview()` et `signauxOuverts()` lèvent
  `RepriseManquante` au lieu d'afficher « rien à revoir » ; le balayage saute ce compte au lieu de s'y arrêter, et le
  vote d'un tiers dont le balayage le croise ne tombe plus ;
- **un refus du journal sur un compte qu'un vote ne vise pas ne fait plus tomber ce vote** : une levée pour meute
  refusée attend le balayage suivant, et se signale sur la victime (`levee_en_attente`) jusqu'à ce qu'elle soit faite,
  graciée ou classée ; `detectPackVoting()` nomme les comptes sautés (`comptes_sautes`). Une erreur après laquelle la
  base a défait une transaction du moteur, celle du vote ou celle du planificateur, et une `\Error`, remontent ;
- **un motif qui n'est pas de l'UTF-8 valide est refusé** par `validateReason()` ;
- **toute valeur texte d'un acte arrive au journal en UTF-8 valide** : une cause lue en base n'empêche plus de classer,
  de bannir, ni de refermer une peine ;
- **un compte que le journal refuse à chaque passe ne retient plus ceux qui le suivent** ;
- **`balayerBansEchus()` refuse la transaction d'un hôte**, et un vote refusé dans celle-ci n'écrit plus la ligne de son
  votant.

**Monter depuis la 0.7.0** : rien dans la base. Une console d'arbitre entoure ses listes comme son balayage et nomme la
cause `levee_en_attente` ; un planificateur lit `comptes_sautes` ; `balayerBansEchus()` s'appelle hors de la transaction
de l'hôte.

La 0.7.0 ferme ce que deux audits de la 0.6 ont relevé, et change ce que voit l'hôte :
- **une lecture n'écrit plus rien** : `getReputation()` et `canVote()` calculent l'état de l'instant sans
  l'inscrire ni prendre de verrou, et `fin_en_attente` dit qu'une fin de peine attend son inscription ; un
  vote, un geste d'arbitre ou le balayage l'inscrivent. Sans planificateur, le premier vote négatif de chaque
  tranche balaie ensuite au plus `balayageFiletMax` comptes. Un écrivain du moteur qui lirait par
  `getReputation()` lève `LectureDansUneEcriture` ;
- **pas de sanction sans trace** : sans journal branché, aucun geste d'arbitre ne passe ; dans la transaction
  d'un hôte, les gestes sont refusés et le ban automatique ne s'arme pas ;
- **un signalement par cause**, dans `mod_signaux` : un geste qui ferme un signal reçoit le jeton
  `$vu = {signal, detections}` et refuse si le signal a été redétecté depuis l'affichage ; `signauxOuverts()`
  les liste ; les listes d'un acte (`ouverts`, `ferme`) partent en texte JSON ;
- **les bans d'arbitre se comptent à part** (`strikes_admin`, clé `dont_arbitre` de `ban_debut`), et leur
  répétition se signale (`bans_arbitre`, `Config::bansArbitreSignal`) ;
- et : une chute à zéro pendant un ban d'arbitre se signale ; la convalescence ne rabat plus un score monté
  par des votes positifs, et rend le droit de vote dès 5 ; un geste sur un compte inexistant est refusé ; le
  jour des motifs suit le fuseau de l'hôte ; `Config` refuse un filet négatif.

**Monter depuis la 0.6** : ajouter à `member_moderation` la colonne `strikes_admin INTEGER NOT NULL DEFAULT 0`
— les bans d'arbitre se comptent alors depuis la 0.7.0 — ; créer `mod_signaux` comme le schéma du lab ; puis
appeler `migrerSignaux()` une fois, sans quoi le moteur refuse tout compte dont la case est ouverte.

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
et `adminBan()` / `adminPardon()` prennent l'arbitre et le motif. **Un mécanisme
reste à écrire** — la remise à zéro des strikes après trois mois sans incident ; le vote
croisé l'est depuis la 0.9.0 — plus deux tenus à moitié, tous marqués dans les listes ci-dessus.

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
