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
pip install selfjustice-mcp
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
      "command": "selfjustice-mcp",
      "env": { "SELFJUSTICE_API_URL": "https://exemple.test/api" }
    }
  }
}
```

Aucune clé d'API, aucun compte : il te faut seulement désigner l'instance à
interroger.

`SELFJUSTICE_API_URL` est **obligatoire et sans valeur par défaut**. Le serveur
ne suppose aucune instance : coder une adresse en dur ferait porter à celui qui
l'héberge le trafic de toutes les installations, à son insu. Sans cette
variable, le serveur démarre, annonce ses outils, et les refuse tous.

### Variables d'environnement

| Variable | Effet |
|---|---|
| `SELFJUSTICE_API_URL` | **Obligatoire.** Racine de l'API à interroger |
| `SELFJUSTICE_TIMEOUT` | Délai réseau en secondes (défaut : 15) |
| `SELFJUSTICE_NTFY_URL` | Topic ntfy prévenu quand la base est en retard |
| `SELFJUSTICE_NTFY_TOKEN` | Jeton du topic |

Les deux dernières servent à qui exploite une instance et veut être alerté
quand sa propre base décroche. Elles sont vides par défaut, et le resteront :
inscrire un jeton dans un dépôt public reviendrait à le publier.

## Héberger ta propre instance

L'API est un fichier PHP et deux bases SQLite ; le dossier parent contient les
scripts de construction et de synchronisation. Rien n'oblige à passer par
l'instance publique — pointe `SELFJUSTICE_API_URL` où tu veux.

## Ce que ce serveur n'est pas

Ce n'est pas un conseil juridique, et il ne remplace ni un avocat ni la
consultation directe de Légifrance. Il donne à un modèle de quoi citer un texte
exact plutôt qu'un texte vraisemblable — ce qui déplace le risque sans le
supprimer. L'interprétation d'un article, son articulation avec la
jurisprudence et son application à une situation restent hors de portée d'une
base de textes.

## Licence

AGPL-3.0-or-later.
