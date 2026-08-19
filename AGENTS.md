# AGENTS.md

Instructions pour les agents de code travaillant sur MySelf. Lire aussi
`CONTRIBUTING.md`, qui régit tout ce qui touche aux données et aux secrets.

## Où vit quoi

| module | contenu |
|---|---|
| `bi-self/` | SelfRecover (recovery sans email/SMS), SelfModerate |
| `self-right/` | SelfJustice (consultation du droit français et européen), SelfAct |
| `self-security/` | SelfDataGuard (chiffrement enveloppé), SelfGuard, SelfKeyGuard, SelfRecover-LUKS |
| `demo/` | ce qui se lance pour montrer : `bi-self-duo/`, `selfdataguard/`, `lab/` |
| `web/` | ce qui est servi statiquement, un dossier par domaine |
| `deploy/` | nginx et systemd, un dossier par cible |
| `tools/`, `scripts/` | outillage transverse |

Quatre règles décident où va un fichier. Un **module** porte la spécification,
la documentation et le code de référence — rien qui se lance. `demo/` porte ce
qui se lance, et **consomme** un module au lieu de le réimplémenter. `web/`
porte ce qui est servi. `deploy/` porte la configuration de service.

Une cinquième les complète, et elle ne parle pas de rangement : **le dépôt et
l'instance disent la même chose, dans les deux sens** — rien de servi hors du
dépôt, rien de corrigé qui ne soit déployé. Un correctif appliqué à un seul
exemplaire d'un mécanisme dupliqué a déjà coûté 11 jours d'exposition, puis 70.

SelfInvoice ne vit plus ici : il n'avait pas de code dans ce dépôt, et sa
documentation a rejoint `selffarm-lite`, qui porte l'implémentation.

Chaque module porte son README. La structure interne varie : `api/` là où une
API est servie, `tests/` là où il en existe. SelfJustice est le seul à porter
les quatre.

## Avant de modifier

Chercher un précédent dans le module concerné : comment y déclare-t-on une
route, gère-t-on une erreur, nomme-t-on une base ? Le précédent fait autorité
sur toute convention générale.

## Validation

| ce qui change | ce qu'on lance |
|---|---|
| PHP | `php -l <fichier>` |
| Python | `python3 -m ast <fichier>` ou le test du module |
| shell | `bash -n <fichier>` **et** `shellcheck` |
| SelfJustice, API ou index | `python3 self-right/selfjustice/tests/test_jurisprudence.py <url>` |
| tout commit | le hook `pre-commit` lance gitleaks — ne pas le contourner |

Si une validation ne peut pas être lancée, l'écrire : la commande, la raison,
et le risque qui subsiste.

### Agents de vérification

`.claude/agents/` porte deux agents qui lancent ces validations et relisent ce
que le dépôt donne à voir :

| agent | ce qu'il fait |
|---|---|
| `lint-syntaxe` | lance le tableau ci-dessus sur les fichiers modifiés et rapporte les sorties telles quelles |
| `vitrine-depot` | relit README, docs, structure, ton et versioning ; sur un dépôt tiers, vérifie sa politique avant d'écrire |

Aucun des deux ne modifie de fichier : ils rendent des constats.

Deux autres agents complètent le dispositif — un audit de code adossé à
`REVIEW.md`, et une recherche de données personnelles avant publication. Ils
vivent **hors du dépôt**, dans la configuration locale de leur auteur, parce
qu'ils nomment des incidents et des motifs qui n'ont pas à être publiés. Leur
absence de ce dossier est voulue ; le dispositif n'est pas incomplet.

## Conventions

- Langue : suivre celle du module. `self-security/` est en anglais — code crypto
  destiné à l'audit externe. Les autres modules sont en français. README
  bilingues, whitepapers descriptifs et impersonnels.
- Les chiffres qui évoluent ne vont pas dans un commentaire : renvoyer vers ce
  qui fait autorité (`/api/status`), donner une borne, ou dater la mesure.
- Un commentaire décrit le code tel qu'il est. L'histoire d'un correctif va dans
  le message de commit.
- SQL sans commentaire à l'intérieur des requêtes.

## Déployer

Le script de déploiement vit hors dépôt : ses correctifs n'apparaissent dans
aucun commit, et ces règles sont le seul endroit où elles se lisent.

**Il copie l'arbre de travail, pas le dépôt.** Ce qui n'est pas commité part
quand même. Le contrôle d'écart compare l'arbre à l'instance : les deux
s'accordent pendant que le dépôt reste en retard, sans que rien ne le dise.
Vérifier `git status` avant de déployer, pas seulement après.

**Le transfert vers la production est sans `--delete`.** Renommer un fichier
servi ne retire donc pas l'ancien nom : un endpoint supprimé du dépôt reste
joignable. Après tout renommage, vérifier l'ancien nom, pas seulement le
nouveau — une porte de récupération a survécu à sa propre suppression jusqu'à
un retrait manuel.

**Il a deux destinations** : le code servi par le frontal, et les outils que le
planificateur exécute, qui ne vivent pas au même endroit. Une modification
d'outil qui n'atteint que la première ne s'exécute jamais.

**La liste blanche des routes vit dans le vhost**, que le déploiement ne touche
pas. Renommer un endpoint demande deux gestes, dans cet ordre : élargir la liste
avant de déployer, la resserrer après. L'inverse interrompt le service.

**Un dossier ne se laisse pas remplacer par un lien.** Quand le déploiement veut
poser un lien là où un dossier existe, le transfert échoue sur ce point seul et
le reste passe — une vitrine a tourné trois mois sur une dépendance périmée, à
côté du module à jour.

**Les fichiers d'état des contrôles n'ont pas tous le même sens de
défaillance.** Celui qui mémorise des volumes, perdu, rend le contrôle muet : il
se fusionne, jamais il ne s'écrase. Celui qui mémorise un silence de
notification, perdu, fait envoyer une alerte de trop : c'est acceptable, et
voulu. Ne pas uniformiser leur traitement.

## Zones sensibles

Ces changements demandent une note explicite dans la description de PR :

- schéma d'une base servie par une API
- primitives ou paramètres cryptographiques (`self-security/`)
- cadence ou périmètre d'une synchronisation de données publiques
- toute route d'API existante

## Ce qu'un agent ne fait pas sans demande explicite

- déployer vers une instance en production
- supprimer des données utilisateur
- ajouter une dépendance
- réécrire l'historique git
