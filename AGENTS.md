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
API est servie, `site/` là où une page est servie, `tests/` là où il en existe,
`tools/` là où l'outillage est propre au module. SelfJustice est le seul à
porter les quatre.

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

`.claude/agents/` porte quatre agents qui lancent ces validations et relisent ce
que le dépôt donne à voir :

| agent | ce qu'il fait | quand l'appeler |
|---|---|---|
| `lint-syntaxe` | lance le tableau ci-dessus sur les fichiers modifiés, rapporte les sorties telles quelles | avant un commit ou un push |
| `vitrine-depot` | relit README, docs, structure, ton, versioning ; sur un dépôt tiers, lit sa politique avant d'écrire | avant une publication ou une release |
| `bruit-commentaires` | relit les commentaires **ajoutés par le diff** : archéologie, auto-justification, anticipation, redite, chiffres qui évoluent | après avoir écrit ou remanié du code |
| `bruit-redaction` | relit la prose **modifiée** destinée à être lue de l'extérieur, et propose une réécriture pour les phrases qui portent du ton sans porter d'information | avant une publication, une release ou une PR |

Aucun des quatre ne modifie de fichier : ils rendent des constats.

`bruit-commentaires` a une contrainte que les trois autres n'ont pas — il propose,
il ne réécrit pas. Deux lectures ne valent que si la seconde peut contredire la
première, ce qui suppose que chaque constat porte de quoi le contredire : le
commentaire cité, le signe retenu, et ce que le code fait à cet endroit. Il
écarte explicitement les commentaires qui signalent un piège, même longs — le
critère qu'il applique est « si ce commentaire disparaissait, quelqu'un
risquerait-il de casser quelque chose ? ».

`bruit-redaction` applique le même partage sur la prose, avec son propre critère :
« retire la phrase, le lecteur a-t-il perdu une information ? ». Il se distingue
de `vitrine-depot`, qui juge ce que le dépôt dit — statuts exacts, liens vivants,
promesses tenues — là où lui ne juge que la manière de le dire. Deux gardes le
bornent : ne jamais signaler sur la forme seule, et ne pas confondre une voix
d'auteur avec du bruit. Il s'éprouve sur des paragraphes anciens du dépôt avant
de rendre sa liste ; s'il les signale, son seuil est trop bas et il le dit au
lieu de conclure.

Trois autres agents complètent le dispositif — un audit de code adossé à
`REVIEW.md`, une recherche de données personnelles avant publication, et une
recette qui rejoue un corpus de non-régression contre un serveur MCP. Ils vivent
**hors du dépôt**, dans la configuration locale de leur auteur, parce qu'ils
nomment des incidents, des motifs et des chemins qui n'ont pas à être publiés.
Leur absence de ce dossier est voulue ; le dispositif n'est pas incomplet.

Le dernier couvre l'angle qu'aucun des six autres n'atteint : **il fait parler le
serveur**. Les six lisent du code ; lui appelle un outil et regarde ce qui
revient. Les défauts qu'il rejoue ont tous été trouvés en observant un
comportement, jamais en relisant une ligne.

Un plugin d'analyse de vulnérabilités s'ajoute à eux, installé côté poste de
travail. Il cherche ce qu'aucun des sept ne cherche : injections, désérialisation
non sûre, primitives mal employées. À l'inverse, il ne saura jamais qu'un script
de déploiement annonce un succès qu'il n'a pas obtenu. Les deux familles se
complètent, elles ne se remplacent pas.

### Contrôles outillés

Les agents jugent ; ces scripts mesurent. Ils rendent un code de sortie, donc ils
tiennent en intégration continue.

| script | ce qu'il mesure |
|---|---|
| `scripts/check-paths.sh` | un chemin cité quelque part a-t-il encore sa cible — liens Markdown, règles d'exclusion, chemins de workflow |
| `scripts/check-profil-unique.sh` | le profil de hachage est-il défini à un seul endroit ; ⚠️ son périmètre est l'index git, pas le disque |
| `scripts/ecart-instance.sh` | ce qui est versionné et ce qui est servi disent-ils la même chose, sur chaque destination |
| `self-right/selfjustice/tools/check_fraicheur.sh` | les bases consultées sont-elles à jour, et leur volume progresse-t-il — c'est la copie que l'instance exécute, et celle que la CI éprouve |
| `scripts/audit-opsec.sh` | les angles morts du détecteur de secrets |
| `scripts/check-surface-servie.sh` | ce qu'un serveur donne réellement à voir, répertoire par répertoire |

**Deux d'entre eux tournent en intégration continue** (`structure.yml`) : les
chemins cités et l'unicité du profil de hachage. Les quatre autres ne le peuvent
pas, et ce n'est pas un oubli — l'écart d'instance et la surface servie ont
besoin d'un accès à la machine, la fraîcheur des bases a besoin de ses API, et
l'audit OPSEC a besoin de motifs qui vivent hors dépôt exprès. Leur place est
une tâche planifiée sur la machine, et leur code de sortie n'est lu que là. En
conséquence, un envoi vert ne dit rien de ces quatre-là.

🔑 **Chacun doit avoir été vu rougir.** Un contrôle qu'on n'a jamais fait échouer
ne se distingue pas d'un contrôle qui ne mesure rien : les deux rendent vert. Un
jeu de défauts plantés vit hors dépôt pour cet usage — la question posée à toute
sonde neuve n'est pas « est-ce que ça passe », c'est « est-ce que ça échoue quand
ça doit ».

🔑 **Une recherche rend une liste, jamais une absence.** Un résultat court se lit
comme « il ne reste rien » tant que trois choses ne l'accompagnent pas : le
périmètre parcouru, le motif cherché, et ce que l'outil n'a pas pu lire. Quatre
inventaires successifs ont rendu quatre listes courtes le 19/08 — un périmètre
qui omettait la racine servie, `grep -r` qui ne suit pas les liens et n'a montré
qu'un vhost sur dix-neuf, un motif qui couvrait un sous-domaine sur quatre.
Chacun a répondu exactement à la question posée.

## Conventions

- Langue : suivre celle du module. `self-security/` est en anglais — code crypto
  destiné à l'audit externe. Les autres modules sont en français. README
  bilingues, whitepapers descriptifs et impersonnels.
- Les chiffres qui évoluent ne vont pas dans un commentaire : renvoyer vers ce
  qui fait autorité (`/api/status`), donner une borne, ou dater la mesure.
- Un commentaire décrit le code tel qu'il est. L'histoire d'un correctif va dans
  le message de commit.
- SQL sans commentaire à l'intérieur des requêtes.

🔑 **Un vérificateur peut être rapide ; une clé, jamais.** Tout secret mémorisé
par un humain qui sert à **chiffrer** — et non à contrôler un accès — passe par
une KDF mémoire-dure avec sel, ou se combine à un facteur absent de la base
(pepper hors base, TPM). Hors ligne, un tag AEAD est un oracle de validité
gratuit : aucun compteur d'essais ne s'applique, et le coût par essai est tout
ce qui reste.

Le motif s'est produit deux fois, dans deux modules écrits séparément, chaque
fois de la même façon : le chemin d'authentification correctement durci en
Argon2id, et le chemin de chiffrement laissé sur un hachage simple, à quelques
lignes d'écart. Deux occurrences indépendantes ne sont pas deux oublis. Devant
un `hash()` ou un `hash_hmac()`, la question est donc : est-ce que sa sortie
finit en paramètre de clé d'un `openssl_encrypt` ou d'un `sodium_crypto_aead_*` ?

Corollaire quand plusieurs enveloppes protègent la même clé de données : la
sécurité de l'ensemble est celle de la moins chère à ouvrir, pas de la plus
solide. Une KDF coûteuse sur un chemin ne protège rien si un second chemin mène
à la même clé plus vite.

## Déployer

Le script de déploiement vit hors dépôt : ses correctifs n'apparaissent dans
aucun commit, et ces règles sont le seul endroit où elles se lisent.

**Un transfert en échec fait échouer le déploiement** depuis le 19/08 : le code
de sortie était auparavant celui du verrouillage final, qui réussit toujours, et
le script concluait « Déployé » sur une copie partielle. Le verrouillage a lieu
quoi qu'il arrive — sortir avant lui laisserait la production modifiable en
silence.

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
