# Changelog

Tous les changements notables de l'écosystème MySelf sont documentés ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et
l'écosystème respecte un versionnement sémantique au niveau de chaque module.
Ce changelog agrège les jalons transversaux du projet.

---

## [Non publié]

### SelfJustice / SelfAct — ce que le module affirme de lui-même — 22 août 2026

**Serveur MCP `selfright-mcp` 0.4.0.** Un contrôle extérieur mené le 21 août a
mesuré que le module était rigoureux sur ce qu'il affirme du texte, et faible
sur ce qu'il affirme de lui-même. Quatre défauts, tous corrigés et tous mis en
production.

- **Deux corpus portaient le même nom.** La vérification d'une référence lisait
  un index local ; la recherche et le texte intégral venaient d'une base amont
  qui va plus loin dans le temps. Trois arrêts sur trois étaient servis
  intégralement par un outil et déclarés absents par celui dont la fonction est
  d'empêcher l'invention. La vérification interroge désormais l'amont dans le
  seul cas où elle sait regarder trop court, et distingue quatre issues — dont
  l'amont muet, qui garde la réserve prudente au lieu de conclure sur la foi
  d'un réseau coupé
- **« Ce numéro existe » ne répondait pas à « cette décision existe ».** Un rôle
  général n'est unique qu'au sein d'une cour : interrogé sur l'arrêt d'une cour
  d'appel daté, le module rendait huit décisions du même numéro rendues
  ailleurs, et concluait « trouvée ». Les homonymes sortent à part, et l'absence
  de correspondance vaut absence. Mesuré en production, après la pose du
  correctif précédent qui n'en traitait que la moitié
- **Un numéro d'article recyclé ne se signalait pas.** Sur un article abrogé le
  module crie ; sur l'article 1382 du code civil — qui porte les présomptions
  judiciaires depuis 2016, quand la responsabilité délictuelle qu'on y cherche
  est passée au 1240 — il rendait un texte en vigueur, exact, daté, et hors
  sujet. Le renvoi se déduit de la base plutôt que d'une table écrite à la main :
  le texte qu'un numéro portait autrefois vit-il sous un autre numéro du même
  code ? Rare — 73 cas pour tout le code civil — donc informatif. Ce que la
  déduction ne sait pas faire, elle ne l'invente pas : un successeur réécrit lui
  échappe, et c'est éprouvé
- **Le service affirmait une provenance qu'il n'avait pas.** Le calcul de délai
  rendait « Textes relus dans la base LEGI, et non cités de mémoire » sans
  ouvrir aucune base : au passé composé et sans sujet, la phrase décrivait le
  travail d'écriture et se lisait comme la provenance de la réponse. Le
  contrôleur l'a recopiée comme une déclaration sur son propre processus. Elle
  décrit maintenant ce que le code fait, et la version LEGI retenue est devenue
  une constante — elle était écrite à deux endroits
- **Un type de document inconnu rendait 200** et un gabarit générique. Le refus
  n'existait que dans l'outil MCP, alors que l'adresse est publiée à
  l'utilisateur : un garde-fou posé chez l'appelant ne protège que l'appelant
  qui le porte

**Ajouté — SelfAct nomme le modèle officiel au lieu d'envoyer le chercher.** Le
pied de chaque gabarit disait « utilise le modèle service-public.fr
correspondant » sans jamais dire lequel, alors que le module indexe 1 895
ressources officielles dont 340 modèles de lettre.

- Six gabarits sur sept portent désormais leurs ressources officielles, chacune
  avec le cas qu'elle vise. Pour la saisine du conciliateur, le catalogue porte
  un **formulaire officiel** : il vaut mieux que tout ce que ce module peut
  produire, et l'outil le met en tête
- **Curé à la main**, comme le rapprochement situation → acte et pour la même
  raison : par mots-clés, « conciliateur » rend une attestation sur l'honneur et
  « Défenseur des droits » ne rend rien. Un renvoi juridique deviné coûte plus
  qu'un renvoi absent
- Deux réserves dites plutôt que tues : le Défenseur des droits n'a **aucun**
  modèle de lettre au catalogue, et il faut commencer par vérifier sa compétence
  — une saisine mal adressée ne suspend aucun délai ; aucun modèle générique de
  mise en demeure n'existe, les trois proposés visant des situations précises
- La table ne porte que des identifiants, résolus au catalogue à chaque appel.
  Un identifiant qu'il ne connaît plus est **nommé**, pas tu : sans quoi une
  ressource disparue passerait pour une démarche sans équivalent
- La liste des gabarits était écrite à deux endroits et divergeait déjà d'une
  entrée. Une seule table, servie par `/act/api/gabarits`

Garde-fous portés de 15 à 21, dont trois neufs, chacun vu rougir avant d'être
cru. Le banc du déploiement annonçait « 10/10 » depuis un chiffre écrit en dur :
il en éprouvait douze, il en compte quatorze.

### SelfModerate v0.3.0 — 21-22 août 2026

**Corrigé — la détection de meute avait le sens inverse.** Le moteur annulait les
downvotes dès que trois votants frappaient la même cible en moins d'une minute,
sans jamais vérifier qu'ils se connaissaient. Trois personnes réagissant
indépendamment au même message voyaient leurs votes annulés, la réputation de
l'auteur restituée, son bannissement levé et ses strikes retirés — un message
était donc d'autant mieux protégé qu'il choquait plus de monde à la fois.

- **Meute** — deux votants liés entre eux sur 30 jours ; le lien se propage par
  transitivité. Sur un forum, « liés » signifie un message privé dans chaque
  sens ; le contenu n'est jamais lu, seulement qui a écrit à qui
- **Salve rapide** — plusieurs votants sans lien dans la même minute : plus
  aucune annulation, la cible part en revue humaine avec sa cause (`review_reason`)
- **Convalescence** — sous 5, le score remonte d'un point par intervalle de calme
  jusqu'à 20, son point de départ. Un **état**, pas un seuil : le conditionner à
  « score < 5 » arrêterait la remontée pile au seuil qui rend le droit de vote.
  Visible sur le profil, des deux côtés
- **Motif de vote** — obligatoire au downvote, facultatif à l'upvote, validé par
  cinq règles anti-remplissage, rendu à la personne visée sans identité de votant
  et daté au jour
- Contrôles portés de 8 à 16, chacun vu rougir ; les quatre règles du motif sont
  éprouvées séparément, chaque cas n'en violant qu'une

**Corrigé — deux seuils SQL comparés à des chaînes.** `HAVING COUNT(...) >= ?`
avec un paramètre PDO : SQLite range tout INTEGER avant tout TEXT, donc la
comparaison était toujours fausse et aucune meute n'aurait été détectée.

**Ajouté — une meute démasquée ne coûtait rien à ceux qui la formaient.** La
détection annulait leurs votes et restituait la réputation de la cible, sans
jamais rien écrire sur les votants : même réputation, même droit de vote, libres
de recommencer le lendemain. Le seul coût de l'attaque était qu'elle échoue.

- **Le rang appartient au votant**, pas à la cible. Compté sur la cible, un
  groupe changeant de proie resterait au premier palier indéfiniment, et une victime visée par plusieurs groupes ferait punir des gens
  dont c'était le premier écart
- **Quatre paliers** — 1er : rien, hors l'annulation des votes et un
  avertissement ; 2e : droit de vote suspendu 7 jours ; 3e : 30 jours et 5 points
  de moins ; 4e et suivants : suspension maintenue et revue humaine. Aucune
  exclusion automatique, à aucun rang
- **Le premier épisode est gratuit** parce que le critère de meute est le message
  privé réciproque : deux amis réagissant de bonne foi au même message pénible le
  remplissent. Annuler leurs votes se défait ; leur retirer le droit de vote, non
- **La suspension a son propre compteur** (`vote_muted_until`) et ne passe pas
  par `voting_rights` : sinon la convalescence la lèverait dès 5 points, et la
  peine ne durerait pas ce qu'elle annonce
- **Un rang par 24 heures et par votant** — sans cette borne, un groupe frappant
  trois cibles dans le même passage de détection franchirait trois paliers d'un
  coup et l'avertissement ne serait jamais vu. Les cibles supplémentaires sont
  tout de même enregistrées
- **Corrigé au passage** — la remontée de réputation à 20 effaçait `needs_review`
  quelle qu'en soit la cause. Une récidive de meute disparaissait donc du tableau
  de l'admin en attendant simplement quelques jours calmes. Seul le signalement
  provoqué par la chute (`reputation_zero`) s'en va désormais avec elle
- **Corrigé au passage** — au quatrième épisode, aucune suspension n'était posée :
  le pire récidiviste retrouvait son droit de vote pendant que l'admin regardait.
  Trouvé par le contrôle n° 22, pas par relecture
- Contrôles portés de 16 à 24, chacun vu rougir. Le n° 19 ne mesurait rien à sa
  première mutation : il calculait son attendu depuis `MEUTE_PENALTY_3`, la
  constante même qu'il surveillait, et se décalait avec elle

## [v0.3.0] — 24-25 avril 2026

### Ajouté — Étage applicatif SelfFarm-Lite complet

- **Hub comptable central** (`self_agri_book`) — table SQLite
  `ecritures_comptables` alimentée par tous les modules métier
- **Journal** (`/compta`) — écritures chronologiques avec balance par compte,
  stats globales, filtres par source
- **Compte de résultat** (`/compta/resultat`) — produits classe 7 / charges
  classe 6 → résultat net (bénéfice ou déficit)
- **Bilan comptable** (`/compta/bilan`) — actif (classes 2/3/5 + 4xx débiteur)
  ↔ passif (classe 1 + 4xx créditeur + résultat) avec vérification automatique
  de l'équilibre
- **Export FEC DGFIP** (`/compta/export-fec`) — fichier 18 colonnes
  tab-separated conforme BOI-CF-IOR-60-40-10 (art. L47 A-I LPF)
- **Facture Factur-X du journal** (`/compta/facture-du-journal`) — consolide
  les ventes B2B 411/701 du journal en un seul PDF/A-3 + XML CII EN16931
- **4 sources d'auto-écritures** branchées sur le hub :
  - `self_invoice` → facture Factur-X → 411/701
  - `self_compta_manuel` → vente rapide → 411/701 (B2B facturable vs B2C non-facturable)
  - `self_achats` → achat fournisseur → 6xxx/401
  - `self_banking` → import relevé → lettrage auto 512/411, prélèvements,
    frais bancaires
- **Dédup idempotente** par `(source_module, source_id)` — retenter la même
  pièce ne crée aucun doublon
- **Validation équilibre D/C** automatique via Pydantic model validator
- **PCG Agricole 2026** officiel (ANC + arrêté 1986 + règlement ANC 2019-01) —
  9 classes, 396 comptes, 133 agri-spécifiques

### Ajouté — SelfInvoice multi-régime

- Générateur Factur-X live avec **3 régimes distincts** sur `/invoice` :
  - Franchise TVA (art. 293 B CGI) — mention obligatoire
  - Micro-BA (TVA normale)
  - Réel (simplifié ou normal — même facture légale)
- Pool B2B facturable vs B2C non-facturable
- Articles séparés du libellé comptable (nom + détail + quantité + unité + PU HT)
- Profils Factur-X dynamiques selon régime (BASIC / EN16931)

### Ajouté — self_parcelles IGN live

- Bascule vers la nouvelle API Géoplateforme IGN (`source_ign=BDP`)
- Vraie géométrie des parcelles cadastrales (fin des polygones inventés)
- Calcul de surface géodésique depuis la géométrie (si IGN ne la fournit plus)
- Mode sélection + mode déplacement + recherche par code INSEE/section/numéro

### Ajouté — Landing my-self.fr

- Section "étage applicatif" au-dessus des 3 piliers
- Module `self_agri_book` promu "hub live"
- Module `self_invoice` promu "démo live"
- Bouton "🌻 Essayer la démo" vers `https://your-instance.example`

### Ajouté — Haute disponibilité DEVSERVER

- Watchdog matériel SOC activé avec timeout 14s
- Démon `watchdog` userspace installé + configuré
- Reboot automatique garanti en < 30 s si kernel panic ou driver réseau figé
- Test de non-régression passé (kill -STOP daemon → reboot auto effectif)

---

## [v0.2.0] — 19-23 avril 2026

### Ajouté — SelfInvoice beta

- Template visuel canonique (HTML/CSS factures)
- Code Python : core (Invoice, Party, Tax, Payment), builders Factur-X CII XML,
  API FastAPI (routes invoices + payments), intégration Viva Wallet (OAuth2)
- Tests unitaires (Invoice + Factur-X builder)

### Ajouté — Modules SelfFarm-Lite individuels

- `self_dnja` — moteur prévisionnel DNJA 4 ans avec PDF CDOA
- `self_aid` — catalogue d'aides JA (V1, élargissement NA/AGRI/PME en V2)
- `self_banking` — parser SG Particuliers (approche fake-first)
- `self_agri_book` (squelette) — plan comptable + modèles Pydantic
- `self_factur_x_agri` (squelette) — à fusionner avec `self_invoice`

### Ajouté — Méta-repo MySelf

- Passage de `bi-self` (nom temporaire) à `my-self` (nom définitif)
- Licence bascule MIT → **AGPL-3.0-or-later** sur tout le repo
- Référentiel sources officielles MySelf (Légifrance, BOFiP, service-public,
  FranceAgriMer, GEVES…) — ordre d'autorité strict
- Convention cadence législative bimensuelle (1er + 15 du mois)
- Convention "pattern CAF" pour les engagements sensibles
- Convention "règles IA-robustes" (pas de contre-exemples qui perversent)

### Ajouté — Self-Right opérationnel

- `SelfJustice` en prod sur `justice.my-self.fr`
- `SelfAct` index des 334 modèles officiels service-public.fr
- Compatibilité multi-IA testée (Kimi, DeepSeek, Grok, Mistral, Claude natif)

---

## [v0.1.0] — 1-18 avril 2026

### Ajouté — Les 3 piliers conceptuels

- **Bi-Self** — SelfRecover (récup sans email, HMAC par service) + SelfModerate
  (modération par raisonnement social)
- **Self-Right** — SelfJustice (directives juridiques 5 catégories droit FR) +
  SelfAct (courriers, saisines, CERFA)
- **Self-Security** — SelfGuard (destruction garantie sous contrainte) +
  SelfKeyGuard (2FA matérielle objets physiques)

### Ajouté — Tooling

- nginx reverse proxy multi-vhosts
- Auto-update opt-in via `version.json` (pattern générique)
- Versionnage systématique (toute app MySelf doit bumper version avant deploy)
- Logging full + toggle console côté user

---

## Avant v0.1.0

Projet en incubation privée — recherches, prototypes, whitepapers.
Pas de version publique.

---

## Conventions de versioning

- **vX.0.0** : jalon majeur (nouvelle dimension, rupture d'architecture)
- **vX.Y.0** : feature release (nouveau module ou refonte significative)
- **vX.Y.Z** : patch (fix, enrichissement mineur)

Chaque module individuel a son propre versionnement sémantique (voir
leurs README respectifs). Ce changelog racine agrège uniquement les
jalons transversaux de l'écosystème.

---

## Auteur

[Pierroons](https://github.com/Pierroons) — mainteneur.
Bricole des outils libres pour l'agriculture, pour que les données poussent pas dans le cloud.
Contact : contact@my-self.fr

Co-écrit avec **Claude** (Anthropic) dans le cadre du « Self pact » humain–IA
décrit dans le [README](./README.md).
