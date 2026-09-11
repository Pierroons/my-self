# Changelog

Tous les changements notables de l'écosystème MySelf sont documentés ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et
l'écosystème respecte un versionnement sémantique au niveau de chaque module.
Ce changelog agrège les jalons transversaux du projet.

---

## [Non publié]

### SelfRecover fournit son schéma et l'implémentation de son propre contrat — 11 septembre 2026

`StorageInterface` posait 39 questions et laissait chaque application y répondre. C'était
délibéré — imposer des tables obligerait un déploiement en service à migrer sa base — mais
celui qui part de zéro devait écrire 500 lignes avant sa première ligne utile.

La bibliothèque livre désormais `schema.sql` et `src/Storage/StockagePdo.php` :
**fournis, jamais imposés**. Qui a déjà ses tables continue d'écrire son adaptateur et ne
migre rien ; qui part de zéro les prend tels quels. Il sert le facteur « cet appareil »,
que les adaptateurs de démonstration ne servent pas tous.

Deux défauts corrigés au passage :

- 🔴 **`PRAGMA foreign_keys` est propre à la connexion, pas à la base.** Le poser dans un
  fichier de schéma ne sert que la connexion qui le charge — souvent `sqlite3(1)`, jetée
  aussitôt. Mesuré : effacer un compte laissait derrière lui ses codes, ses clés
  d'appareil et le texte qu'il avait écrit à un arbitre, sans qu'aucune contrainte ne
  proteste. Le constructeur de l'adaptateur le repose sur la connexion de l'application.
- 🔴 **Les gardes de transaction validaient celle de l'appelant.** « Ne rien faire si une
  transaction existe déjà » traite le symptôme et fabrique pire : le `commit()` suivant
  rendait durable le travail à moitié fait de l'appelant, qui recevait ensuite « There is
  no active transaction » sur son propre rollback. Remplacé par des points de reprise
  (`SAVEPOINT`) : la transaction extérieure reste la sienne, nos écritures s'annulent sans
  y toucher.

  ⚠️ **Le correctif ne vaut que pour cet adaptateur.** Les trois autres porteurs du
  contrat gardent le motif : les deux démos, et le double en mémoire des bancs — dont
  `commencerTransaction()` écrase l'instantané précédent, de sorte qu'une annulation
  imbriquée ne restaure rien. La cause est en amont : `StorageInterface` ne dit pas si
  ces trois méthodes sont ré-entrantes, et les quatre implémentations y répondent
  différemment. À trancher au contrat, pas porteur par porteur.

Le banc `tests/banc_stockage_pdo.php` relit la base plutôt que la valeur rendue, et son
plancher compte **par section** : un plancher global laissait disparaître 22 contrôles —
dont ceux du facteur « cet appareil » — en rendant le même vert.

⚠️ **Un banc ne peut pas se garder contre la falsification de son propre verdict.** Mesuré :
débrancher le compteur d'échecs lui faisait afficher les ❌ et sortir à 0. La seconde
source est donc dehors — l'étape de CI cherche le caractère ❌ dans la sortie, en plus du
code de retour.

### Le contrôle des chemins ne regardait aucun lien ancré — 9 septembre 2026

`scripts/check-paths.sh` porte depuis sa création une classe `[^)#]` qui **exclut le
`#`** : `](#section)` et `](guide.md#section)` sortaient de son périmètre. Quatre badges
des README SelfRecover pointaient `#quickstart`, qu'aucun titre ne produit, et le script
rendait vert — la forme la plus discrète du faux vert, un contrôle qui passe parce qu'il
ne regarde pas.

Un quatrième contrôle vérifie désormais les fragments, en reproduisant l'algorithme de
`github-slugger`. ⚠️ **Son ordre d'opérations n'est pas intuitif et un seul écart fabrique
des faux positifs** : `trim()` s'applique AVANT la suppression de la ponctuation, jamais
après. `## Facteur « cet appareil »` produit donc `facteur--cet-appareil-`, tiret final
compris — la première version du contrôle le retirait et condamnait un lien parfaitement
valide. Un garde-fou qui crie à tort finit désactivé.

Le verdict porte son **contre-témoin** : « les 29 ancres vérifiées résolvent », et non un
zéro nu qui ne distinguerait pas « tout résout » de « le motif n'a rien trouvé à
regarder ». Éprouvé dans les trois sens : ancre morte plantée → rougit ; fichier absent
derrière une ancre → rougit en nommant la cause ; ancre valide chargée de ponctuation →
acceptée.

Les quatre `#quickstart` pointent maintenant la section de démarrage qu'ils visaient.

### SelfRecover était déployé et non déployé, à sept lignes d'intervalle — 9 septembre 2026

`bi-self/README.md:57` annonçait « deployed and self-audited implementation » et la
section Statut, sept lignes plus bas, « no real-world production deployment yet ». Le
lecteur devait trancher seul entre deux affirmations opposées du même fichier.

**C'est la ligne 57 qui dit vrai**, vérifié sur la machine par la conv GitHub : le
backend d'authentification d'un service de messagerie sert la bibliothèque en conditions
réelles. La section Statut le dit désormais, dans les deux langues, et garde ce qui reste
exact — la démo est auto-auditée, aucun audit externe n'a été mené.

### Le secret de LUKS portait le vocabulaire de l'autre niveau — 9 septembre 2026

Quatre fichiers de `selfrecover-luks/` appelaient « mot de récupération » — le terme du
**niveau 2**, qui est par compte et se combine à un code — ce qui est une **passphrase de
niveau 1**, diceware, propre à la machine. Douze occurrences ; deux fichiers seulement
disaient juste, dont le keyscript, c'est-à-dire le seul qui tourne au démarrage.

La source est la docstring de `selfrecover_derive.py`, et c'est de là que la formulation a
essaimé jusqu'au README racine du monorepo, où elle était devenue « une seule passphrase
mémorisée » — un terme qui n'existe dans aucun des deux niveaux.

Corrigé, et la docstring porte maintenant les deux avertissements qui manquaient : la
nature du secret d'entrée, et le fait que **`--label` est une capacité du dérivateur, pas
une architecture déployée** — seul `disk` a un consommateur, les étiquettes `auth` et
`data-enc` citées en documentation n'existent dans aucun code du monorepo.

Aucune clé, aucune dérivation, aucun format n'est touché : c'est de la prose dans du code.
Le nom de l'option `--word` est conservé — le renommer romprait un contrat.

### Les secrets du lab refusent au lieu de servir — 9 septembre 2026

Trois fonctions posaient chacune leur secret sans jamais lire le retour de leur
écriture. Sur un répertoire non inscriptible — l'état exact de la production ce
jour-là — l'écriture échouait, la relecture rendait `false`, et `(string) false`
donnait la chaîne vide.

Les trois ne dégradaient pas de la même façon, et c'est ce qui rend le premier cas
sérieux :

| | ce qui arrivait |
|---|---|
| `Security::csrfSecret()` | 🔴 rendait la constante `csrf\|`. **Aucun garde en aval** : `hash_hmac` accepte n'importe quelle clé, l'application continuait de servir des jetons anti-CSRF que quiconque lit le dépôt pouvait recalculer |
| `DataGuard::blindKey()` | rendait `''`, mais `Primitives::deriveFromMemorized` lève sur une clé vide : panne bruyante, pas de chiffrement affaibli |
| `Auth::siteSalt()` | rien — elle portait déjà la garde, écrite après un incident du 27/08 |

**Le correctif n'est pas de réparer les trois, c'est qu'il n'y en ait plus qu'un.**
`SecretInstance::lire()` (`demo/lab/lib/secret_instance.php`) reprend ce que
`siteSalt()` faisait seule : `mkdir` contrôlé, retour d'écriture lu, longueur
minimale exigée, `RuntimeException` nommant le fichier et la cause à chaque étape.
Les trois appelants deviennent des façades d'une ligne. La même garde entre dans
`demo/selfdataguard/api/_bootstrap.php`, où la clé publique de récupération admin
pouvait devenir vide sans que rien ne le voie.

**Le secret CSRF cesse de partager `.blindkey` avec le chiffrement des coffres** et
prend `.serversecret`, un fichier que le déployeur protégeait depuis le 22/08 sans
qu'aucun code ne le lise. Un même secret pour signer et pour chiffrer mélangeait
deux contextes, et rien n'obligeait à le faire.

⚠️ **Migration, et elle demande un geste AVANT le déploiement.** Sur une instance où
`data/` n'est pas inscriptible, l'ancien code lisait le `.blindkey` déjà présent et
servait des jetons faibles ; le nouveau lève, et l'exception n'est rattrapée nulle part
dans le lab — toute page d'un utilisateur connecté devient fatale. Le refus est le
comportement voulu, mais il faut **ouvrir `data/` en écriture, ou y poser `.serversecret`
d'au moins 32 caractères, avant de déployer**, sinon la mise à jour est une panne et non
un durcissement. Sur une instance saine, le seul effet est que les formulaires ouverts à
l'instant du redémarrage sont refusés une fois. Les coffres ne bougent pas : `.blindkey`
garde son rôle et son contenu.

### Le profil du secret SuperUser se contrôle enfin — 9 septembre 2026

`Crypto\Hashing::needsRehash()` entre dans la bibliothèque, sur le modèle de
`dummyHashSuitProfil()`. Un hash rangé en base voit son profil comparé à chaque
connexion réussie ; un hash qui vit seul dans un fichier ne recevait la question de
personne, et c'est ainsi que le secret SU est resté trois semaines en `p=1` pendant
que le protocole annonçait `p=2`.

`selfrecover-su` le dit désormais après une authentification réussie — **et ne le
réécrit pas** : reposer un secret est un geste humain, et `change-passphrase` le
journalise, là où une réécriture automatique ne laisserait aucune trace. Les trois
réponses restent trois jusqu'au message : « conforme », « périmé », et « bibliothèque
introuvable, donc non vérifié » — taire la troisième rendrait le silence de la première.

### Le journal voit qu'on a remplacé le secret qu'il protège — 9 septembre 2026

Changer la passphrase SU sans connaître l'ancienne est trivial pour qui a le shell,
et c'est assumé. Ce qui ne l'était pas : l'opération ne laissait **aucune trace**, et
`verify-log` pouvait attester que la chaîne était intacte tout en ignorant que le
porteur avait changé.

L'entrée `change-passphrase` porte maintenant une empreinte du secret déposé — dans
`extra`, donc sous la chaîne et sous le HMAC. **Un HMAC et non un condensat nu** : le
secret attendu n'est pas toujours un hash Argon2id, la console accepte aussi une valeur
en clair, et un SHA-256 non salé d'un secret mémorisé se casse hors ligne à coût nul par
essai — or le journal, lui, part en sauvegarde hors site.

`verify-log` compare le secret en place à la dernière empreinte journalisée, avec
**trois issues qui portent trois CODES DE SORTIE distincts** : conforme (0) · désaccord
(5) · rien à comparer (6). Le premier jet de ce correctif écrivait les trois messages
mais sortait à 0 dans deux cas sur trois : une tâche planifiée qui alerte sur un code non
nul n'aurait jamais rien vu, et le faux vert que ce mécanisme ferme se serait logé dans
le mécanisme. Trouvé en revue, avant publication.

Le message du désaccord **n'affirme pas de cause** : trois chemins y mènent — un
remplacement hors console, un `SELFRECOVER_SU_SECRET_FILE` différent, un
`change-passphrase` lancé depuis une copie antérieure du script — et n'en nommer qu'un
ferait accuser à tort un journal complet.

`record-seal` note l'empreinte du secret en place sans le changer, que celui-ci vienne
d'un fichier ou de `SELFRECOVER_SU_SECRET`. C'est le geste qui amorce le contrôle sur une
console dont le secret a été posé avant que ce champ existe, et celui qui acquitte un
désaccord — il le dit alors explicitement, l'ancien sceau restant au journal. Sans lui, la
première vérification démarre rouge, et une alarme qui démarre rouge s'ignore.

### Une base introuvable dit pourquoi — 9 septembre 2026

`Db::pdo()` vérifie l'accès avant d'ouvrir et nomme le chemin, l'utilisateur à qui le
droit manque, et la prise `LAB_DB_PATH`. SQLite rend « unable to open database file »
aussi bien pour un disque plein que pour un répertoire fermé, et la trace PDO ne dit
ni sous quelle identité on tourne ni comment dérouter la base — elle a coûté une nuit
d'enquête. `Db::path()` ne crée plus rien : elle calcule un chemin, la vérification
est ailleurs.

### Quatre porteurs qui ne suivaient plus — 9 septembre 2026

Sortis de l'inventaire exhaustif des endroits qui nomment ces trois secrets, une fois
`.serversecret` devenu réel. Les deux premiers sont des trous, pas des redites :

- **`demo/lab/.gitignore`** — le second filet, celui qui existe « au cas où `data/` bouge »,
  couvrait `.blindkey` et `.sitesalt` mais pas `.serversecret`. Mesuré : un `.serversecret`
  déposé ailleurs que dans `data/` était **suivi par git**. Le motif est ajouté.
- **`deploy/my-self/tests/test_deploy.sh`** — le cas qui éprouve la liste `INTERDITS` hors du
  lab ne posait qu'un `.blindkey`, quand la liste porte trois motifs. Retirer l'un des deux
  autres laissait le banc vert ; il les éprouve maintenant tous les trois, et **il a été vu
  rougir sur chacun**.
- `demo/lab/docs/PENTEST-MISSION-ctf.md` — la liste des fichiers à chercher en exposition
  nommait deux secrets sur trois.
- `demo/lab/tests/sanity_timing.php` — deux références périmées : `Auth::DUMMY_HASH`, qui vit
  dans `Crypto\Hashing` depuis sa remontée, et un chemin de fichier jumeau qui n'existe plus.

### Vérification

`demo/lab/tests/sanity_secrets_instance.php` — 36 contrôles, six sections, entré dans
`structure.yml`. Sa sixième section lance la console pour de bon et lit ses **codes de
sortie** : sans elle, les trois issues de `verify-log` n'étaient éprouvées par rien.

Vu rougir sur **sept** défauts replantés : écriture non contrôlée · longueur minimale
retirée · prise d'environnement vide ignorée · `csrfSecret()` ramené à son ancien corps ·
« aucune empreinte » déguisé en sceau vide · l'`exit(6)` de `verify-log` remplacé par un
`break` · l'empreinte redevenue un SHA-256 nu.

🔑 **Un de ces sept a rougi trop tard.** Le contrôle de la prise vide passait pour une
mauvaise raison : sans la garde, la chaîne vide part comme chemin et l'échec survient plus
loin, au renommage — le banc voyait une exception et concluait que la propriété était
tenue. Il vérifie maintenant le message, pas seulement la levée.

Le banc **avoue son périmètre** : il n'exerce pas `csrfSecret()` ni `blindKey()` sur un
vrai répertoire fermé — ni l'une ni l'autre n'a de prise pour dérouter son chemin. Il
éprouve la mécanique commune pour de bon, contrôle sur le source que les trois y
passent, et cherche le motif fautif dans tout `lib/` pour attraper le jumeau suivant.

Le garde-fou de CI vérifie deux compteurs plutôt que le seul code de sortie : deux
sections ne s'éprouvent pas sous root, le banc les saute **en le disant** et sort quand
même à zéro. Sans ces compteurs, un runner qui passerait root rendrait le même vert en
ayant renoncé aux contrôles qui touchent au système.

---

## [SelfRecover v0.5.1] — 8 septembre 2026

### SelfRecover L1 — une date qui informe, et l'écrit qu'elle n'expire rien — 8 septembre 2026

Question sortie d'une séance de lecture de code : faut-il faire expirer la
passphrase de niveau 1 ? Deux pistes avaient été proposées — une échéance
calendaire, ou un durcissement automatique d'une récupération ancienne présentée
depuis un « contexte inconnu ».

**Les deux sont écartées, et l'arbitrage est écrit plutôt que sous-entendu.**

L'échéance tuerait le secours au moment précis où il sert : une passphrase de
récupération s'emploie quand tout le reste est perdu, parfois des années après, et
son détenteur ne pourrait pas savoir qu'elle est morte avant d'essayer — il n'y a
pas d'email pour le prévenir, c'est tout le propos du protocole.

Le durcissement automatique suppose un contexte. Derrière un service caché, il
n'y en a aucun : toutes les requêtes partagent une adresse, `$ip` vaut `null`
pour tout le monde. La bibliothèque refuserait un titulaire légitime sur un
signal qui n'existe pas.

Ce qui borne le vol d'un papier était déjà là et n'avait pas besoin d'être
ajouté : **l'usage unique**. `parPassphrase()` consomme la passphrase et en émet
une neuve.

**Ce qui est ajouté est une date qui informe.** `trouverComptePourPassphrase()`
peut rendre `emise_le`, facultatif et `null` — jamais zéro, qui se lirait
« émise en 1970 » et afficherait cinquante-six ans à qui vient de s'inscrire.
`parPassphrase()` rend `age_jours` : l'âge de la passphrase **qui vient de
servir**, pas de la neuve, parce que la question utile est depuis combien de
temps ce papier traînait. Informatif, jamais bloquant. L'interface reste à 39
méthodes : aucun consommateur n'a à changer.

**Trois endroits émettent une passphrase** — l'inscription, la récupération de
niveau 1, le ré-enrôlement de niveau 3. Un seul oublié, et un papier imprimé à
l'instant s'afficherait avec l'âge de celui qu'il remplace, devant quelqu'un qui
sort précisément d'une perte totale. Les deux de la bibliothèque portent
l'obligation dans leur contrat ; le troisième appartient à l'application, et
c'est pour cela qu'il s'oublie.

Le laboratoire les tient tous les trois, montre l'âge dans l'espace personnel, et
verse le fait au faisceau du niveau 3 — « passphrase émise il y a trois ans » dit
quelque chose à un arbitre : qui perd tout après des années n'a pas le profil
d'un compte créé la semaine dernière.

Ce que le modèle de menace dit désormais, et qu'il taisait : une passphrase volée
reste utilisable jusqu'à ce que quelqu'un l'utilise, et **le titulaire n'apprend
rien au moment du vol — c'est le voleur qui reçoit la passphrase neuve.** Sans
canal hors-bande, la notification n'existe pas. C'est structurel au modèle sans
email, pas un oubli.

### Fuzzer du niveau 3 — quatre défauts, dont un qui faussait la mesure annoncée — 8 septembre 2026

Relevés par une relecture extérieure du code de la sonde, pas par un rouge :
une sonde qui se trompe rend le même vert qu'une sonde juste.

**La longueur des suites se retirait à chaque itération.** Écrite
`for ($pas = 0; $pas < mt_rand(6, 20); $pas++)`, la borne se redessine à chaque
tour et la suite s'arrête dès qu'un tirage tombe sous le compteur. Mesuré sur
200 000 tirages, à graine identique :

| | moyenne | suites ≥ 15 pas |
|---|---|---|
| borne retirée à chaque tour | 9,55 | 1,9 % |
| borne tirée une fois | 13,00 | 40,0 % |

Les états profonds vivent dans les suites longues. **Le chiffre de profondeur
annoncé jusqu'ici était donc faussé.** Sur 200 tours, la sonde corrigée atteint
28 ré-enrôlements réussis à graine 7 — contre 15 avant —, 26 à graine 42 et 29 à
graine 101, sans violation sur aucune des trois.

**L'invariant central se désarmait pour le reste de la suite.** « Rien du compte
ne bouge sans un ré-enrôlement réussi » se lisait `if (!$reussi && …)`, avec un
drapeau posé une fois pour toutes : dès le premier ré-enrôlement, tout ce qui
bougeait ensuite passait sans contrôle — et c'est précisément après un
ré-enrôlement que l'état est le plus riche. Le drapeau vaut désormais pour un pas.

**Le sésame n'était cherché que dans la colonne qui promet de ne pas le
contenir.** Une fuite par le faisceau, par un message recopié ou par un champ
ajouté plus tard passait inaperçue. Le dossier entier est sérialisé et fouillé.
Canari décisif : le même défaut — sésame glissé dans le faisceau, JSON
parfaitement valide — laisse l'ancienne sonde verte et fait rougir la nouvelle.

**La sonde mourait au lieu de rapporter.** Le calcul du détail d'une violation
passait par une fermeture `fn ($v): string => json_encode($v)`, et `json_encode`
rend `false` sur ce qu'il ne sait pas encoder : sous `strict_types`, une
`TypeError` tuait la sonde au seul moment où elle savait quelque chose. Éprouvé —
avec un défaut planté qui rend le compte non sérialisable, l'ancienne forme meurt,
la nouvelle rapporte.

### Garde-fou du profil Argon2id — trois formes lui échappaient — 8 septembre 2026

Le quatrième contrôle de `check-profil-unique.sh` cherchait
`password_hash(<un argument>, PASSWORD_ARGON2ID)` par `git grep`. Trois formes
passaient au travers, chacune plantée dans le dépôt et vérifiée verte avant
d'être fermée :

| Forme | Pourquoi elle passait |
|---|---|
| `password_hash($s, PASSWORD_ARGON2ID, [])` | des options vides valent les défauts de PHP, et le motif exigeait `)` après l'algorithme |
| l'appel réparti sur plusieurs lignes | `git grep` lit une ligne à la fois |
| `$algo = PASSWORD_ARGON2ID; password_hash($s, $algo)` | l'algorithme n'est plus dans l'appel |

`scripts/audit-password-hash.php` les voit toutes : il lit des **appels**, pas
des lignes. Un commentaire n'y est pas du code, ce qui retire au passage le
filtre à la main sur les lignes commençant par `//` — documenter le défaut ne le
fait plus rougir.

Il est **fail-closed sur ce qu'il ne peut pas lire** : un algorithme passé par
variable est signalé, non parce qu'il est fautif, mais parce que rien ne peut
prouver qu'il ne l'est pas.

**L'exemption se réduit d'une famille à un chemin.** Le contrôle écartait tous
les `tests/sanity_*`. Passé sur les 182 fichiers sans aucune exclusion,
l'analyseur ne remonte qu'un seul appel, et c'est le seul légitime : un banc qui
pose délibérément une empreinte à l'ancien profil pour vérifier qu'elle se
vérifie encore. Exempter la famille couvrait aussi celui qui deviendrait fautif
demain.

**L'absence de l'analyseur est bruyante.** Sans ce garde, un fichier effacé
faisait échouer `php`, le `|| true` avalait son code, la sortie était vide et le
contrôle rendait vert : un dépôt amputé se serait lu comme un dépôt sain.
Éprouvé dans les deux cas — fichier retiré, fichier cassé.

### MySelf-Lab — le dégel devient atteignable, et la trace dit qui — 8 septembre 2026

Deux whitepapers promettaient qu'un arbitre lève le gel de procédure et que la
trace du dégel est conservée. `Escalade::degeler()` existait, `RecoverL3::adminUnfreeze()`
aussi — et **aucun appelant ne les atteignait**. La promesse était vraie dans la
bibliothèque et fausse partout où quelqu'un aurait pu s'en servir. Un compte gelé
le restait sept jours quoi qu'en pense l'arbitre.

`demo/lab/public/api/admin_unfreeze.php` ferme le chemin, avec les trois gardes
de ses voisins — méthode, qualité d'arbitre, jeton CSRF. La console d'arbitrage
porte le bouton, et il n'apparaît que sur un gel qui court.

**Qui agit est pris dans la session, jamais dans le corps de la requête.**
`admin_dispute_decide.php` passait le défaut `'admin'` : toutes les décisions du
service étaient signées du même nom, alors que la console affiche cette signature
à l'arbitre suivant. Une trace qui ne distingue personne n'en est pas une. Les
deux endpoints prennent désormais le nom dans la session, et la sonde refuse
qu'il puisse revenir du corps.

**Un gel échu n'est plus un gel.** `listerLitiges` remontait la colonne brute,
si bien que la console affichait « ouverture gelée jusqu'au <date passée> » sur
un compte redevenu libre — et aurait proposé de lever un gel qui n'existait plus.
`gelJusqua()` appliquait déjà la borne ; les deux lectures disent maintenant la
même chose.

Éprouvé de bout en bout sur le schéma réel — le gel court, le dégel passe par
l'adaptateur, `degele_par` porte le nom, l'ouverture repasse — et quatre défauts
plantés à la main, quatre attrapés.


## [SelfRecover-LUKS v0.4.0] — 8 septembre 2026

Le tag `selfrecover-luks-v0.3.0` date du 7 juin. Vingt-trois commits l'ont suivi,
et le module a changé sur des points qu'une release ne devrait pas taire : un
défaut qui rend une machine non amorçable, un script de secours qui affichait la
passphrase en clair, et un guide décrivant une installation que personne n'avait
faite de bout en bout.

### La clé livrée à cryptsetup — un défaut réel, et un diagnostic faux avant lui

Le format de la clé passe du brut à l'**hexadécimal**, et la raison retenue n'est
pas celle qui avait été annoncée.

`b5047e9` avait conclu de la page de manuel que `--key-file=-` tronquait la clé au
premier saut de ligne, donc qu'une installation sur huit produisait un slot qui
s'enrôle, se vérifie, et échoue définitivement au redémarrage. Le raisonnement
s'était propagé dans cinq fichiers, deux bancs d'essai et le guide.

`5a3feb7` le dément, mesuré sur **deux machines indépendantes** (cryptsetup 2.7.5)
avec témoins négatifs : `--key-file=-` est la lecture d'un *keyfile* dont la source
est stdin, et le flux est lu **en entier**. La clause « from stdin » de la page de
manuel ne vise que la lecture d'une passphrase, sans `--key-file` — chemin que
l'amorçage Debian n'emprunte jamais.

**Le seul mode de défaillance de cette lecture est un saut de ligne final** ajouté
à la clé — un `echo` au lieu d'un `printf '%s'`. Il casse l'hexadécimal comme le
brut, par tube comme par fichier, et rien ne le gardait.
`tests/test_lecture_keyfile.sh` le garde désormais : conteneur LUKS2 jetable sur
fichier, **sans root**, `--test-passphrase` seulement, avec le cas qui le fait
rougir et deux témoins négatifs.

Le banc éprouve **un** dérivateur par exécution — le binaire C s'il est compilé,
sinon celui en Python — et il annonce lequel à chaque essai. La CI ne compilait
pas le `.c` : elle n'éprouvait donc que le Python, alors que le binaire appelé à
l'amorçage est le C. **Elle le compile désormais**, et une ligne relit
l'annonce du banc pour refuser un vert obtenu sur l'autre chemin — motif éprouvé
dans les deux sens, il reconnaît le binaire et refuse la ligne `python3 …`.

⚠️ Ce qu'aucun vert ne couvre encore : `selfrecover-keyscript.sh` lui-même. Le
banc éprouve la chaîne dérivateur → tube → cryptsetup, pas le script qui les
assemble à l'amorçage.

L'hexadécimal est conservé pour la **portabilité** : une clé hex survit à une
lecture en tant que passphrase — secours tapé à la main, `cryptsetup open` sans
`--key-file=-`, amorceur non Debian. Une clé brute non : 11,8 % d'entre elles
contiennent un `0x0A`, et là, la troncature est réelle. `install.sh` pose en outre
`keyfile-size=64` sur la ligne racine de crypttab **lors d'une installation
neuve**, pour qu'une lecture bornée ignore un `\n` final plutôt que de rendre la
machine non amorçable. Il la pose **aussi sur une ligne qui porte déjà
`keyscript=`** — donc sur les machines SelfRecover existantes, précisément le
public de la migration. Un simple avertissement y laissait la borne absente,
c'est-à-dire là où elle sert le plus : celui qui vient de changer le format de sa
clé. La question est posée avant d'écrire, comme pour toute modification de
`/etc/crypttab` dans ce script, et la sauvegarde datée est prise avant. La mesure commentée et l'historique
du démenti vivent dans `docs/cryptsetup-lecture-cle.md`, pour que personne ne
refasse ce diagnostic depuis la page de manuel.

La migration reste décrite (`INSTALL.md` §15) mais perd son urgence : une machine
en clé brute qui démarre continuera de démarrer. Ce qui reste vrai, c'est qu'on ne
dépose pas le nouveau keyscript sur un ancien slot. Et sa première étape n'est pas
l'ajout de slot mais la **sauvegarde de l'en-tête** : c'est elle qui protège pendant
que `luksAddKey` y écrit. Celle de la fin sert à autre chose — empêcher l'ancienne
de ressusciter le slot qu'on vient de retirer.

### Le script de secours affichait la passphrase en clair

`selfrecover-unlock.sh` lisait la saisie par `read -rsp`, puis appelait le
dérivateur en `--stdin` sans rien lui passer. Le tube n'existait pas : `--stdin`
lisait donc le terminal, alors que la fin du `read -rs` venait de rétablir l'écho.
Le déverrouillage restait bloqué sur ce qui ressemblait à une invite muette,
l'administrateur retapait sa passphrase, et elle s'affichait.

Le tube manquant est **ajouté** — `printf '%s' "$WORD" |`, et non un `--word`, qui
rendrait la passphrase lisible dans `/proc/<pid>/cmdline`. Il n'avait pas été
retiré : l'appel passait auparavant par `--word`, et le passage à `--stdin` a
oublié le tube. Plus tôt dans la même fenêtre, le script avait gagné une
confirmation explicite, un `trap` qui restaure l'écho et libère la variable
(`unset`) quelle que soit la sortie, et une limite de trois essais.

### Chaque régénération d'initramfs rend maintenant un verdict

`initramfs-post-update-verifie-selfrecover` s'exécute après **chaque** génération
d'initramfs et vérifie que les pièces SelfRecover y sont. Quand `unmkinitramfs` est
disponible, il compare en outre le sel embarqué à celui du disque — un sel présent
mais périmé dérive une autre clé, et un contrôle de simple présence afficherait
« complet ».

Cette comparaison peut ne pas s'exécuter, et **son absence s'entend**. Elle était
d'abord enfermée dans une condition muette : `unmkinitramfs` manquant, extraction
impossible, répertoire temporaire indisponible, et le script imprimait « complet »
en n'ayant établi que la présence des pièces — le faux vert que ce hook existe
pour fermer, logé dans le hook. Trois états sont maintenant distingués, concorde,
diffère, pas vérifiable, et le mot « complet » est réservé au premier. Un contrôle
sauté nomme sa raison sur la sortie d'erreur, sans faire échouer la génération :
une capacité manquante n'est pas une image cassée, et faire tomber
`update-initramfs` pousserait à désinstaller le hook.

Il alerte, il n'empêche pas : posé en `post-update.d`, il tourne après l'écriture
de l'image et rend 1 avec un bandeau, au milieu d'une sortie d'`apt`. L'échec se
signale donc à la régénération, avec la marche à suivre, au lieu d'apparaître au
redémarrage suivant. Il est posé là et non dans
`kernel/postinst.d`, parce qu'`update-initramfs` est aussi déclenché par
cryptsetup-initramfs, busybox, initramfs-tools ou une commande manuelle — et c'est
justement une mise à jour de cryptsetup-initramfs qui casserait ce module.

### Le guide décrit maintenant deux parcours, et une installation qui a eu lieu

Un déploiement réel sur poste portable a montré que le guide décrivait une
procédure que personne n'avait suivie de bout en bout sur une Debian 13 neuve.
Deux étapes échouaient d'entrée : `python3-argon2` manquait aux prérequis alors que
l'ajout de slot en dépend, et `xxd` n'est plus installé par défaut depuis que Debian
l'a détaché de `vim-common` — remplacé par `od`, qui vient de coreutils, donc une
dépendance de moins pour un outil qui vise l'auto-hébergement.

Un mode de défaillance n'était couvert par aucun filet : les sauvegardes
d'initramfs et de crypttab protègent l'amorçage, aucune ne protégeait **l'en-tête
LUKS**, que `luksAddKey` écrit précisément. En-tête corrompu, plus aucun slot
n'ouvre.

Le §7 aiguille désormais entre deux parcours — serveur déverrouillé par SSH, et
poste dont on tape la passphrase au clavier. Sur un poste, dropbear et la cascade
de volumes secondaires ne servent à rien, et personne ne le disait : quelqu'un
montait un serveur SSH dans son initramfs pour rien. Le tronc commun reste unique.

### Divers

Le R&D de déverrouillage par quorum est rangé dans `quorum-rnd/` — il n'est pas
activé en v0.4.0. Un banc d'essai FIDO2 entre dans le module. Le whitepaper, qui
décrivait encore la clé brute, est régénéré depuis sa source Markdown.


## [SelfRecover v0.5.0] — 8 septembre 2026

### SelfRecover — l'ouverture d'un dossier de niveau 3 cesse d'être un oracle gratuit — 8 septembre 2026

En remontant le niveau 3 dans la bibliothèque la veille, `Escalade::ouvrir()` a
perdu le frein que l'implémentation d'origine posait devant sa route, et rien ne
l'a remplacé. La méthode répondait donc « aucun compte à ce nom » autant de fois
qu'on le lui demandait, gratuitement : de quoi dresser la liste des comptes d'un
service en la parcourant. C'était une régression introduite par la remontée, pas
un défaut hérité.

**Ce qui n'a pas été fait, et pourquoi.** Aucune formulation ne ferme cette
porte. Un succès rend un numéro de dossier ; un nom inconnu ne peut pas en rendre
un. Le niveau 1 s'en sort par un refus unique et le niveau 2 en ne demandant
aucun identifiant, mais au niveau 3 la distinction **est** la réponse utile.
Prétendre la taire aurait produit un texte rassurant devant un code inchangé.

Ce qui s'y oppose est donc le coût. `ouvrir()` accepte une adresse
(`?string $ip = null`, **intercalé avant `$maintenant`** — un appelant tiers en
positionnel doit passer aux arguments nommés) et pose deux freins, tous deux
**avant** la recherche du compte — après, le fait d'être freiné aurait à son tour
trié les comptes existants :

| Frein | Ce qu'il attrape |
|---|---|
| par adresse, 10 / heure | l'énumération, quand l'appelant fournit une adresse |
| par service, 20 / heure | la même, quand il n'y en a pas à fournir |

`$ip` vaut `null` quand l'adresse ne dit rien de l'appelant — derrière un service
caché, où tout arrive de la même adresse. La passer là-bas ferait d'un frein par
client un plafond global au seuil du client, plus bas que le plafond de service
et le masquant ; `null` laisse le plafond de service gouverner seul. Un
déploiement exposé pose en plus une preuve de travail devant la route, que la
bibliothèque ne peut pas imposer.

**Il n'y a délibérément pas de frein par compte.** Il aurait fermé l'ouverture à
un titulaire dès qu'un tiers avait assez sollicité son compte, sans qu'aucun
dossier n'existe — donc sans que rien n'apparaisse à l'arbitre. Le harcèlement
d'un compte est déjà borné autrement : le premier dossier tient 24 h, le suivant
reçoit `deja_ouvert`, et cette collision-là **se compte et se montre**. Un frein
silencieux aurait remplacé un fait visible par un mur muet.

**Les étiquettes de comptage sont sous HMAC du sel de déploiement.** Les
compteurs se lisent par `compterEchecsCompte()`, dans une table où les tentatives
de connexion atterrissent aussi — et un nom de compte soumis y arrive tel quel,
sans contrôle de forme, depuis une route publique. Avec une étiquette devinable,
vingt requêtes sur la page de connexion sous le nom `l3:ouvrir:*` fermaient
l'ouverture de dossier pour tout le service, et ces vingt lignes étaient
invisibles dans la console d'arbitrage. Mesuré de bout en bout avant d'être
corrigé. Sous HMAC, viser un compteur suppose le sel, qui vit hors du webroot ;
la console, elle, reconnaît les lignes de la bibliothèque à la **paire** préfixe
et adresse nulle, qu'aucune route publique ne peut produire.

**Aucune ligne de traçage ne porte l'adresse de l'appelant.** Les compteurs
d'ouverture vivent sous leurs propres étiquettes, l'adresse sous forme
d'empreinte dans l'étiquette. Une ligne qui porterait l'adresse dans sa colonne
alimenterait tous les autres compteurs par adresse du service — connexion
ordinaire, niveaux 1 et 2, enrôlement d'appareil : ouvrir des dossiers depuis une
adresse aurait fermé les quatre voies à qui la partage, et l'échec serait
redevenu l'arme que la refonte de la veille avait retirée de `trancher()`. Aucun
nom de compte n'entre dans ces étiquettes non plus — l'ouverture est comptée, pas
attribuée, et la console d'arbitrage n'a pas à lire qui a été visé.

Les refus qui taisent un état (`compte_inconnu`, `gele`, `deja_ouvert`) portent
tous le même délai. Auparavant seul le premier attendait, si bien que l'existence
d'un compte se lisait au chronomètre.

**Deux éléments de schéma morts sont retirés.** `suspicious_fingerprints` — table
sans lecteur ni écrivain, résidu d'un mécanisme de traçage retiré des signaux de
récupération le 03/07 — et `login_attempts.level`, dont le commentaire décrivait
exactement la séparation des compteurs que la bibliothèque obtient désormais par
une étiquette sous HMAC. Deux mécanismes pour un besoin, dont un jamais branché :
il en reste un. Les bases existantes gardent une colonne inutilisée, ce qui ne
coûte rien ; aucune migration ne détruit de données.

`docs/threat-model.md` et les deux whitepapers annonçaient « énumération par bot :
honeypot + timing + délais forcés » comme une menace traitée. Elle passe à
partielle, avec ce qui la ferme et ce qui reste ouvert.


### SelfRecover — le niveau 3 remonte dans la bibliothèque, et un refus cesse de détruire — 7 septembre 2026

Le 19 août, la remontée des niveaux 1 et 2 écrivait que le niveau 3 resterait
applicatif : « il suppose une interface d'arbitrage, des échanges, une notion de
litige. » Cette décision est rouverte, pour une raison qu'elle ne pouvait pas
anticiper : **deux applications le portaient, et elles avaient divergé sur ce
qu'un refus fait au compte.**

`src/Recovery/Escalade.php` porte désormais le dossier, le sésame à usage unique,
le faisceau et l'arbitrage ; `src/Recovery/Litige.php` l'objet qui les traverse ;
`StorageInterface` gagne dix-sept opérations. Ce qui reste à l'application est
nommé dans le docblock : la bibliothèque ne vérifie jamais **qui** a le droit de
trancher, parce que les rôles et les sessions ne sont pas à elle.

**🔑 Un refus ne touche jamais au compte.** L'implémentation la plus avancée le
tenait déjà, l'autre supprimait le compte **dès le premier refus**, et les deux
whitepapers annonçaient encore un ban de 24 h avec suppression au troisième. Un
refus dit « ce demandeur ne m'a pas convaincu », pas « ce compte est
illégitime » : si le demandeur était un imposteur, supprimer détruit le compte de
sa victime ; s'il était le titulaire mal jugé, cela punit un innocent. Et un
attaquant incapable de voler un compte pouvait le faire effacer en accumulant des
refus — l'échec devenait une arme. Ce qui se durcit est la **procédure** : au-delà
de trois refus en trente jours, l'ouverture de nouveaux dossiers gèle sept jours ;
le compte reste connectable, et un arbitre dégèle.

**Le faisceau a trois états, et le troisième est celui qui coûtait.** `concorde`,
`diverge`, et **`indisponible`**. Sans lui, un déploiement qui n'enregistre pas
les connexions fait marquer « ne concorde pas » à une réponse honnête. C'était le
cas du laboratoire : `last_login_at` et `login_count` étaient **lus** par le
faisceau et écrits nulle part, si bien que « dernière connexion » rendait toujours
*jamais connecté* et « fréquence » toujours *rare* — un titulaire légitime
obtenait le dossier d'un imposteur. Le laboratoire les écrit désormais, et son
adaptateur rend `null` plutôt que zéro tant qu'il n'a rien enregistré.

Corrigé au passage : le docblock de `Recovery` annonçait « 77 bits pour six mots »
quand le fichier en tirait quatre depuis toujours, à deux endroits.
`engendrerPassphrase()` fixe la longueur une seule fois.

**Contrôles.** `tests/sanity_escalade.php`, 74 contrôles, entrés en intégration
continue ; le banc d'équivalence du laboratoire passe de 29 à 41 et éprouve le
niveau 3 sur le schéma réel. S'y ajoute `tests/fuzz_escalade.php`, un fuzzer à
propriétés : il tire des suites d'opérations et vérifie après chaque appel que
rien du compte n'a bougé sans ré-enrôlement réussi. Il a trouvé, à son premier
essai, qu'une réponse en UTF-8 invalide faisait ranger le faisceau **vide** —
`json_encode` rend `false`, et `(string) false` vaut la chaîne vide. L'arbitre
tranchait alors sur rien.
Le sixième a démenti la sonde elle-même : le contrôle « le sésame ne sert qu'une
fois » restait vert alors que l'invalidation était retirée, parce qu'il constatait
un refus sans vérifier son motif — c'était le statut du dossier qui refusait, pas
le sésame. Il vérifie maintenant le motif, et le fil, que le statut ne garde pas.

### SelfRecover v0.5.0, SelfRecover-LUKS v0.4.0, SelfDataGuard v0.3.0 — les versions rattrapent le code — 7 septembre 2026

Trois modules affichaient une version antérieure au travail qu'ils portent. Le
badge n'était pas seulement en retard : il désignait une release publiée qui ne
contient pas ce que le dépôt décrit.

- **SelfRecover 0.4.0 → 0.5.0.** Le tag `selfrecover-v0.4.0` date du 3 juillet ;
  quarante commits l'ont suivi, dont l'extraction de la bibliothèque `src/`
  (PSR-4) et la livraison du dériveur navigateur `client/sr-derive.js`. Le README
  annonçait pourtant, sous « ce que ce dépôt n'est PAS », une bibliothèque
  « prévue en V1.0 » — celle-là même que le dossier d'à côté contient. Ce qui
  manque n'est pas l'extraction, c'est la publication : ni Packagist, ni npm.
  Corrigé aussi : le même fichier annonçait une démo autonome vingt lignes après
  avoir écrit qu'elle avait été retirée, et renvoyait à `demo/su.html`, absent
  depuis le rangement du 19 août. La vitrine pédagogique du modèle SU existe, sur
  base jetable en mémoire : `demo/lab/public/su_console.php`.

- **SelfRecover-LUKS 0.3.0 → 0.4.0.** Le module a basculé la clé livrée à
  `cryptsetup` du format brut vers l'hexadécimal, avec sa procédure de migration
  (`INSTALL.md` §15). Le whitepaper décrivait encore la clé brute.

- **SelfDataGuard 0.2.0 → 0.3.0.** Le durcissement du 6 septembre — dérivation
  Argon2id du secret mémorisé, plancher de longueur du mot de passe — était daté
  « 0.2.0 » à quinze endroits du code, des tests et du whitepaper français, alors
  que le tag `selfdataguard-v0.2.0` du 21 août ne le contient pas : quelqu'un qui
  récupère cette release obtient la dérivation HMAC pendant que le commentaire lui
  promet Argon2id. Les quinze porteurs sont redatés sur 0.3.0.

  Au passage, le whitepaper **anglais** — l'édition que lit un auditeur externe,
  `self-security/` étant en anglais pour cette raison — décrivait encore
  `recov_key ← HMAC-SHA256(…)` à trois endroits, une liste de mots de passe
  compromis qu'aucune ligne n'applique, et un plancher de 30 bits présenté comme
  recommandation. Ces affirmations sont alignées sur le code ; le reste de
  l'édition n'a pas été relu contre la version française, et son pied de page le
  dit désormais.

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

### Ajouté — Haute disponibilité du serveur

- Watchdog matériel activé avec timeout 14 s
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
Outils libres pour l'agriculture, pour que les données ne poussent pas dans le cloud.
Contact : contact@my-self.fr

Co-écrit avec **Claude** (Anthropic) dans le cadre du « Self pact » humain–IA
décrit dans le [README](./README.md).
