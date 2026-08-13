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

Le règlement (UE) 2024/1689 sur l'intelligence artificielle est inclus sous le
nom `AI_ACT`. Son article 50 porte les obligations de transparence applicables
aux contenus générés par IA — utile si tu comptes joindre une analyse produite
par un modèle à une saisine ou la publier.

## Le contrôle de fraîcheur

C'est le point qui distingue ce serveur d'un simple proxy HTTP.

**Une base juridique périmée est plus dangereuse qu'une absence de base.** Elle
répond, elle a l'air juste, et elle sert du droit abrogé sans que rien ne le
signale. Ce n'est pas théorique : entre juillet 2025 et août 2026, l'instance
publique a servi le droit au 13 juillet 2025 pendant treize mois. La
synchronisation tournait à l'heure tous les 1er et 15 ; c'est son résultat qui
était mort, et aucun contrôle portant sur l'exécution ne pouvait le voir.

Chaque réponse de chaque outil porte donc un état de fraîcheur calculé sur la
date du **contenu**, comparée à la dernière échéance de synchronisation
attendue. Quand la base décroche, le texte le dit avant de donner l'article, et
demande au modèle de te le répercuter.

Quand l'API est injoignable, l'outil ne rend pas une erreur discrète : il
demande explicitement au modèle de ne rien citer de mémoire et de te renvoyer
vers Légifrance. Une erreur silencieuse produirait exactement l'hallucination
que ce serveur existe pour empêcher.

## Installation

```bash
pip install selfright-mcp
```

Ou depuis les sources :

```bash
git clone https://github.com/Pierroons/my-self
cd my-self/self-right/selfjustice/mcp
pip install -e .
```

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

Aucune clé d'API, aucun compte : il te faut seulement désigner l'instance à
interroger.

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
| `SELFRIGHT_ACT_URL` | Racine des routes de démarches. Déduite de `SELFRIGHT_API_URL` en remplaçant son dernier segment par `act/api` — à renseigner seulement si ton instance les dispose autrement |

Les deux dernières servent à qui exploite une instance et veut être alerté
quand sa propre base décroche. Elles sont vides par défaut, et le resteront :
inscrire un jeton dans un dépôt public reviendrait à le publier.

## Héberger ta propre instance

L'API est un fichier PHP et deux bases SQLite ; le dossier parent contient les
scripts de construction et de synchronisation. Rien n'oblige à passer par
l'instance publique — pointe `SELFRIGHT_API_URL` où tu veux.

## Trois outils de démarches, pas quatre

L'API expose aussi une route qui produit un acte pré-rempli. Elle **n'est pas
exposée ici**, et la raison est technique avant d'être juridique : son garde-fou
est un filigrane « NON OFFICIEL — IRRECEVABLE » appliqué à une page imprimable.
Un serveur MCP rend du texte à un modèle — le filigrane ne survivrait pas au
passage, et le modèle recevrait un brouillon propre qu'il recopierait tel quel.
L'exposer reviendrait à retirer sa protection en la croyant intacte.

Ce qui est exposé indexe, oriente et calcule. `calculer_echeance` rend sa date
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
