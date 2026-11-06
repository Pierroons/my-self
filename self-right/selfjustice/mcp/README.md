# SelfJustice — serveur MCP

Donne à ton assistant IA un accès en lecture au droit français et aux textes de
conventionnalité, pour qu'il arrête de citer de mémoire.

## Le problème

Pose une question de droit à un modèle de langage : il te répondra avec un
numéro d'article, un texte, une date. Tout sera plausible. Rien ne distinguera,
dans sa réponse, ce qu'il sait de ce qu'il a reconstitué — et il ne te préviendra
pas, parce qu'il ne fait pas la différence lui-même.

Ce serveur remplace la mémoire par une lecture. Chaque article rendu vient d'une
requête à une base construite depuis les dumps officiels DILA, et arrive
accompagné de la date à laquelle cette base a été synchronisée.

## Ce qu'il expose

| Outil | Ce qu'il fait |
|---|---|
| `statut` | État des bases : volumétrie, sources, date de synchronisation |
| `article_francais` | Texte intégral d'un article français, avec son état de vigueur |
| `rechercher_droit_francais` | Cherche des références par numéro ou fragment |
| `article_europeen` | Article CEDH, Charte UE, TUE, TFUE, RGPD ou règlement IA |
| `rechercher_conventionnalite` | Cherche dans les textes de conventionnalité |
| `verifier_jurisprudence` | Dit si un numéro d'arrêt existe réellement, avant qu'on le cite |
| `rechercher_jurisprudence` | Cherche des décisions par thème, avec filtre de date |
| `texte_decision` | Texte intégral d'une décision, à partir de son identifiant |
| `catalogue_actes` | Cherche modèles de lettres, CERFA et démarches officiels |
| `actes_pour_situation` | Quelles démarches existent pour une situation donnée |
| `calculer_echeance` | Calcule un délai de procédure et rend son raisonnement |
| `gabarit_document` | Adresse d'un modèle de courrier à trous et champs à compléter ; mention « NON OFFICIEL » quand il imite la forme d'un acte |

Le règlement (UE) 2024/1689 sur l'intelligence artificielle est inclus sous le
nom `AI_ACT`. Son article 50 porte les obligations de transparence : le marquage
des contenus de synthèse par leurs fournisseurs (§2), et pour les déployeurs le
signalement des hypertrucages et des textes **publiés dans le but d'informer le
public sur des questions d'intérêt public** (§4). Ce dernier paragraphe prévoit
ses propres exemptions, dont l'examen humain avec responsabilité éditoriale
assumée — demande-lui son texte plutôt que de te fier à ce résumé.

## Le contrôle de fraîcheur

Un proxy relaierait la base sans rien dire de son âge.

**Une base juridique périmée est plus dangereuse qu'une absence de base.** Elle
répond, elle a l'air juste, et elle sert du droit abrogé sans que rien ne le
signale. Ce n'est pas théorique : de juillet 2025 à août 2026, une instance de ce
projet a servi le droit au 13 juillet 2025 pendant treize mois. La
synchronisation tournait à l'heure tous les 1er et 15 ; c'est son résultat qui
était mort, et aucun contrôle portant sur l'exécution ne pouvait le voir. Le
défaut a été corrigé en août 2026, et ce chapitre existe parce qu'il a eu lieu.

Les dix outils qui lisent une base portent donc un état de fraîcheur dans leur
réponse. `calculer_echeance` et `gabarit_document` n'en portent pas : ils ne
servent aucun texte — l'un calcule un délai, l'autre donne une adresse. Cet état
repose sur **deux dates que l'instance expose séparément** :

- `last_sync` — quand la synchronisation de l'instance a réussi ;
- `last_update` — jusqu'où va le contenu qu'elle sert.

Le retard se juge sur la première, le contenu se dit avec la seconde, et les
deux se confrontent. Les confondre a un coût : la date du contenu est fixée par l'amont, pas par
l'instance. Celle de LEGI est celle du dernier diff publié par la DILA — elle
précède forcément la synchronisation et n'avance plus jusqu'à la suivante. La
comparer au calendrier des synchronisations la condamne à ne jamais l'atteindre,
et le serveur annonce alors un retard permanent sur une base parfaitement à
jour. Un outil qui crie au loup à chaque réponse cesse d'être lu au moment où il
a raison.

Le contenu se compare donc à la synchronisation, jamais au calendrier. Quand une
synchronisation récente sert un contenu de plus d'un mois plus ancien qu'elle,
elle tourne sans plus rien rapporter de sa source — la panne des treize mois — et
la réponse l'annonce « contenu figé ».

Quand l'état des bases ne peut pas être lu, la réponse le dit aussi, au lieu de
servir l'article sans date.

Une instance qui n'annonce pas `last_sync` ne permet pas de distinguer une
synchronisation morte d'un amont silencieux. Le serveur le dit, plutôt que de
trancher au hasard.

Quand la base décroche vraiment, le texte le signale avant de donner l'article,
et demande au modèle de te le répercuter.

## Ce que la recherche dit d'elle-même

Une liste de décisions a toujours l'air pertinente : le sommaire est rédigé par
la Cour, la mention « publié au Bulletin » l'accompagne, et une requête sans
queue ni tête rend des arrêts d'apparence irréprochable.

L'outil mesure donc la part des termes de ta question que les décisions
reprennent, et **la dit sans conclure**. Il annonçait auparavant « hors sujet
probable » ; c'était une affirmation qu'un recouvrement de mots ne permet pas —
mesuré sur douze questions réelles et six absurdes, « une amende de
stationnement reçue par erreur » obtenait 28 % et « ma banane peut-elle
saxophoner » 25 %. Aucun seuil ne les sépare, parce que la jurisprudence
n'emploie pas les mots de celui qui la cherche.

Ce qui se mesure vraiment, ce sont deux faits : la proportion reprise, et les
termes qu'**aucune** décision de la liste ne surligne. De ceux-là, l'outil
demande à l'index s'ils y figurent, sur le même périmètre que la recherche —
les sommaires des arrêts publiés par défaut, le texte intégral si tu le
demandes — et « saxophoner » n'apparaît nulle part. Le fait sert dans les deux
sens : que « cryptoactifs » n'y figure pas non plus est peut-être la réponse que
tu cherchais.

Ce contrôle ne coûte des requêtes que lorsqu'il a quelque chose à dire : au plus
quatre de plus, sur la clé Judilibre de l'instance.

Quand l'API est injoignable, l'outil ne rend pas une erreur discrète : il
demande explicitement au modèle de ne rien citer de mémoire et de te renvoyer
vers Légifrance. Une erreur silencieuse produirait exactement l'hallucination
que ce serveur existe pour empêcher.

## Installation

Le paquet n'est pas encore publié sur PyPI : l'installation se fait depuis les
sources.

```bash
git clone https://github.com/Pierroons/my-self
cd my-self/self-right/selfjustice/mcp
pip install -e .
```

Ce paquet installe le serveur, pas l'instance qu'il interroge. Il te faut une
API SelfJustice, et c'est à toi de l'héberger : voir « Héberger ta propre
instance » plus bas.

## Configuration du client

Pour Claude Code (`~/.claude.json`) ou tout autre client MCP :

```json
{
  "mcpServers": {
    "selfjustice": {
      "command": "selfright-mcp",
      "env": { "SELFRIGHT_API_URL": "https://exemple.test/api" }
    }
  }
}
```

Aucune clé d'API, aucun compte côté client : il te faut seulement désigner
l'instance à interroger.

`SELFRIGHT_API_URL` est **obligatoire et sans valeur par défaut**. Le serveur
ne suppose aucune instance : coder une adresse en dur ferait porter à celui qui
l'héberge le trafic de toutes les installations, à son insu. Sans cette
variable, le serveur démarre, annonce ses outils, et les refuse tous.

### Variables d'environnement

| Variable | Effet |
|---|---|
| `SELFRIGHT_API_URL` | **Obligatoire.** Racine de l'API à interroger |
| `SELFRIGHT_TIMEOUT` | Délai réseau en secondes (défaut : 15) |
| `SELFRIGHT_PLAFOND_TEXTE` | Longueur au-delà de laquelle le texte d'une décision est réduit (défaut : 20000). Les moyens annexés sont écartés en priorité ; à défaut, le début et la fin sont conservés |
| `SELFRIGHT_NTFY_URL` | Topic ntfy prévenu quand la base est en retard |
| `SELFRIGHT_NTFY_TOKEN` | Jeton du topic |
| `SELFRIGHT_ACT_URL` | Racine des routes de démarches. Déduite de `SELFRIGHT_API_URL` **à la seule condition que son dernier segment soit `api`**, qu'elle remplace alors par `act/api`. Toute autre forme ne se déduit pas : renseigne-la, sinon les outils de démarches refusent de répondre |

Les deux dernières servent à qui exploite une instance et veut être alerté
quand sa propre base décroche. Elles sont vides par défaut, et le resteront :
inscrire un jeton dans un dépôt public reviendrait à le publier.

## Héberger ta propre instance

Ce paquet ne fournit aucune instance et n'en désigne aucune : tu héberges la
tienne. Coder une adresse en dur ferait porter à son exploitant le trafic de
toutes les installations, à son insu.

Ce qu'il te faut, et où c'est dans le dépôt :

- l'API — un fichier PHP, `self-right/selfjustice/api/api.php` ;
- **trois** bases SQLite — le droit français, la conventionnalité, l'index de
  jurisprudence —, construites par les scripts de
  `self-right/selfjustice/tools/` depuis les dumps officiels de la DILA et
  d'EUR-Lex ;
- la configuration de référence — vhost nginx, unités systemd de
  synchronisation — dans `deploy/selfjustice/` ;
- pour la recherche de jurisprudence par thème et le texte intégral des
  décisions **judiciaires** : une clé Judilibre, obtenue par un compte sur
  api.piste.gouv.fr, à placer dans `SELFJUSTICE_JUDILIBRE_KEY` côté instance.
  Sans elle, les dix autres outils fonctionnent, la jurisprudence
  administrative reste servie par l'index local, et les deux outils concernés
  le disent au lieu de se taire.

C'est une configuration de référence, pas un guide pas-à-pas. Une fois ton API
servie, pointe `SELFRIGHT_API_URL` sur sa racine — `https://ton-domaine/api`.

## Ce que le serveur fait, et ce qu'il te laisse

Il donne des outils qui disent l'âge de ce qu'ils servent ; il ne formule pas
d'avis. Il rend un article avec sa
date de vigueur, une échéance avec le raisonnement qui y mène, l'adresse d'un
formulaire officiel — jamais une recommandation sur ce qu'il faut faire.

`gabarit_document` illustre la limite. Il **ne rédige pas et ne rend pas le
document** : il donne l'adresse du gabarit et la liste des champs à compléter.
Le document s'ouvre dans un navigateur, porte ses avertissements — la mention
« NON OFFICIEL » dans le corps quand il imite la forme d'un acte, le rappel en
pied dans tous les cas — et laisse en crochets les faits, montants et noms — ceux
qui n'appartiennent qu'à la personne concernée et qu'aucun outil ne connaît. La
date, elle, est pré-remplie au jour de la génération : l'annoncer « à compléter »
enverrait chercher un trou qui n'existe pas.

Le reste indexe, oriente et calcule. `calculer_echeance` rend sa date
**avec le raisonnement article par article** qui y mène : une échéance sans ses
règles ne se vérifie pas, et un délai de procédure manqué ne se rattrape pas.

## Ce que ce serveur n'est pas

Ce n'est pas un conseil juridique, et il ne remplace ni un avocat ni la
consultation directe de Légifrance. Il donne à un modèle de quoi citer un texte
exact plutôt qu'un texte vraisemblable — ce qui déplace le risque sans le
supprimer. L'interprétation d'un article, son articulation avec la
jurisprudence et son application à une situation restent hors de portée d'une
base de textes.

## Licence

AGPL-3.0-or-later.
