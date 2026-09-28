# Changelog

Tous les changements notables de l'écosystème MySelf sont documentés ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et
l'écosystème respecte un versionnement sémantique au niveau de chaque module.
Ce changelog agrège les jalons transversaux du projet.

---

## [Non publié]

### HSTS et CSP pour la vitrine, la démo SelfDataGuard et SelfFarm — 28 septembre 2026

Ces trois vhosts envoyaient les quatre en-têtes du socle, sans HSTS ni CSP. Trois fragments
s'ajoutent à `deploy/my-self/snippets/` :

- `hsts.conf` : un an, sans `includeSubDomains` ni `preload`. Posée sur le domaine parent, la
  première option engagerait pour un an tout sous-domaine, y compris ceux que le DNS résoudrait sans
  vhost ni certificat valide. Chaque vhost inclut donc le fragment pour lui-même.
- `csp-accueil.conf` : la vitrine est statique et sans saisie. Son script inline passe par
  `'unsafe-inline'` ; la politique ferme les ressources et connexions hors de l'origine, les objets
  embarqués et l'encadrement par un autre site.
- `csp-dataguard.conf` : aucun script inline permis, les deux scripts de la démo sont des fichiers.

SelfFarm reçoit HSTS seul. L'application charge des bibliothèques et des polices depuis des services
tiers : sa CSP se décide dans `selffarm-lite`.

Les deux CSP ont été éprouvées dans un navigateur sans interface, contre les pages servies : aucune
violation, et une version plus stricte y déclenche bien les blocages attendus.

### La console SU exige sept mots de la liste EFF — 28 septembre 2026

La passphrase du super-utilisateur chiffre les sauvegardes du journal : elle s'attaque hors ligne,
sans compteur d'essais. La console acceptait « quatre mots de la liste EFF, ou vingt caractères ».
Trois mots de la liste font déjà vingt caractères en moyenne : la seconde voie rendait la première
décorative. Elle exige maintenant sept mots de la liste (≈ 90,5 bits, le tirage par défaut de
SelfRecover-LUKS), et rien d'autre.

⚠️ **Geste à prévoir.** Le contrôle s'applique à `change-passphrase` et à chaque `backup-log`. Une
passphrase SU posée avant, qui ne fait pas sept mots de la liste, s'authentifie toujours mais refuse
la prochaine sauvegarde du journal : il faut d'abord un `change-passphrase`. Les sauvegardes déjà
scellées se restaurent sous leur passphrase d'origine, que rien ne contrôle à la lecture.

Le banc SU passe de 23 à 26 cas : six mots, quarante caractères sans mot, sept mots dont un hors de
la liste, tous refusés sans poser de secret. L'ancienne règle remise, les trois rougissent.

### SelfRecover v0.8.0 — reprendre un compte le referme vraiment, et un accord rendu a une fin — 28 septembre 2026

Deux chemins laissaient une personne devant une porte qu'aucun geste ne pouvait ouvrir ou
fermer. Ils se corrigent ensemble parce qu'ils vivent au même endroit : la reprise de compte
au niveau 3.

**Les appareils enrôlés survivaient à tout.** Aucune méthode du contrat de stockage ne savait
en retirer un. `reposerSecrets()` réécrit les empreintes et le sel ; les appareils restaient,
et `Device::cloreDefi()` ouvre le compte sur une **signature seule** — il ne vérifie pas le mot
mémorisé. L'appareil de qui détenait le compte avant signait donc encore et recevait encore un
mot de passe neuf, pendant que le titulaire croyait avoir refermé sa porte.

On atteint le niveau 3 après avoir tout perdu, et « tout perdu » veut souvent dire « quelqu'un
d'autre l'a ». C'est le seul niveau où ce qui existait avant doit être tenu pour suspect : les
niveaux 1 et 2 ne révoquent rien, et un contre-témoin du banc de récupération garde cette
frontière. Qui présente un papier, ou un code **et** son mot mémorisé, a prouvé quelque chose.

**Un accord rendu n'avait pas de fin.** Un litige accepté ne périmait jamais, `ouvrir()` refuse
tant qu'un litige est actif, et la seule clôture passait par `reEnroler()`, qui exige le sésame.
Qui perdait son sésame entre l'accord et son retour voyait le niveau 3 se fermer définitivement,
avec un message lui demandant précisément ce qu'il n'avait plus. La sortie était un `UPDATE` en
base.

Deux réponses, et il fallait les deux. `Escalade::abandonner()` rend la main tout de suite à qui
le demande : un arbitre clôt le litige, la place se libère, l'arbitrage est à refaire — il ne
décide pas à la place de celui qui tranchera. Et `ttlAccepte`, sept jours par défaut, sert de
filet quand personne ne demande rien. Le délai seul aurait laissé quelqu'un dehors une semaine ;
l'abandon seul aurait laissé la place prise pour toujours si personne n'y pensait.

⚠️ **Migration.** Le contrat de stockage passe de 41 à 42 méthodes : `revoquerAppareils(int
$compteId): int`. Un adaptateur tiers ne s'instancie plus sans elle. Les implémentations d'un
déploiement sans facteur « cet appareil » rendent 0 plutôt que de lever — cette méthode est
appelée dans la transaction de reprise, et lever y ferait échouer une récupération pour une
fonction que le déploiement n'offre pas. Les défis en vol se retirent **avant** les appareils :
ils désignent un `credential_id` et non un compte.

Trois messages disent maintenant ce qu'ils taisaient : l'acceptation annonce son délai, le refus
« une procédure est déjà en cours » dit quoi faire quand le sésame est perdu, et la reprise
annonce le nombre d'appareils retirés. `appareils_retires` est rendu à l'application.

**La passphrase du niveau 1 passe de quatre à six mots**, soit de ≈ 51,7 à ≈ 77,5 bits : le
minimum que l'entropy-lab et le guide diceware recommandent déjà. Seule la génération change. La
vérification compare une empreinte et ne compte pas les mots : une passphrase de quatre mots déjà
délivrée reste valide, et la prochaine récupération la remplace par six mots. Les trois démos qui
écrivaient `4` en dur lisent maintenant `Recovery::MOTS_PASSPHRASE`. Un intégrateur qui affiche
ou valide un nombre de mots fixe doit le relire.

**Une seule fabrique de codes de secours.** La démo bi-self-duo fabriquait les siens (boucle,
`random_bytes(5)`, `10` en dur) et le lab gardait son propre `10` : un changement de nombre ou de
format dans la bibliothèque ne leur parvenait pas. Les deux passent par `Recovery::emettreCodes()`
et `Recovery::CODES_PAR_LOT`. `Recovery::estFormeCode()` porte seule la forme `xxxxx-xxxxx` ; les
trois copies de l'expression dans les démos l'appellent.

**Ce que la documentation taisait aux intégrateurs.** Le tableau des niveaux disait « Nouveau
mot de passe » pour L1 et L2 : les deux rendent aussi une nouvelle passphrase et effacent
l'ancienne, et une application qui ne l'affiche pas fait perdre le niveau 1 à son utilisateur.
`emettreCodes()` efface le lot en place avant d'écrire le suivant. Changer le sel du déploiement
rend tous les codes émis introuvables, sans réindexation possible : la seule procédure (changer,
puis faire réémettre chaque feuille) est écrite dans le README et `SECURITY.md`. Un coffre
SelfDataGuard créé sans mot mémorisé ne survit pas à une récupération de niveau 1 ou 2 : le
README de SelfDataGuard, le contrat de `register()` et `SECURITY.md` le disent, avec la séquence
de re-scellement. Signalés par une intégration qui fait tourner les deux modules ; aucune donnée
perdue. L'enrôlement d'un appareil dit maintenant où vit sa clé.

**Les messages disent le délai réglé, pas un délai recopié.** « Réessaie dans 15 minutes » était
écrit en dur six fois dans `Recovery` et `Device`, alors que la fenêtre est un paramètre du
constructeur ; le lab la règle. Le refus des freins vient maintenant d'une seule méthode par
classe, et `Duree::enClair()` dit la fenêtre en clair — le texte reste identique entre le frein par
compte et le frein par origine. `Escalade` fait de même pour l'accord, le gel et le dépôt trop
rapproché (qui dit maintenant combien de temps attendre) ; le gel dit qu'un administrateur peut le
lever, et le refus `deja_ouvert` donne la date où la procédure en cours tombe d'elle-même.
`Escalade::reglesDuGel()` rend seuil, fenêtre et durée aux écrans d'arbitrage, qui les recopiaient.
Le lab transmet sa fenêtre de connexion au module et ses textes lisent ses constantes.

Le banc de l'escalade passe de 107 à 124 cas, celui de la récupération de 58 à 61. Le contrôle
qui affirmait qu'un accord reste actif « bien après son TTL » n'a pas été réparé mais **scindé** :
la propriété qu'il défendait tient sur la fenêtre où elle vaut, l'échéance la borne au-delà.
Trois canaris : révocation neutralisée, échéance retirée, abandon qui ne clôt plus.

### L'image Docker de SelfRecover est retirée, et ce qu'on publie hors du dépôt entre sous contrôle — 28 septembre 2026

`ghcr.io/pierroons/selfrecover` servait encore, sous les étiquettes `latest` et `v0.4.0`, l'image
construite le 28 juillet 2026. Le rangement du 18 août avait supprimé d'un même geste son
`Dockerfile` et le workflow qui la construisait à chaque tag ; les quatre tags suivants — 0.5.0,
0.5.1, 0.6.0, 0.7.0 — sont donc passés sans rien reconstruire, sans que rien ne le signale. Ce que
`latest` donnait à qui la tirait précédait trois correctifs décrits plus bas dans ce fichier :
l'ouverture de dossier de niveau 3 qui énumérait les comptes (0.5.0), la démo restée sur PBKDF2
quand la documentation annonçait Argon2id (0.6.0), le frein par compte sans effet au niveau 2
(0.7.0).

**L'image est retirée plutôt que reconstruite.** Ce dépôt ne propose pas d'essayer SelfRecover par
un conteneur : « Essayer SelfRecover » renvoie à la démo servie et aux pages autonomes, et la démo
du duo écrit qu'elle se passe de Docker par principe. L'image était le dernier reste d'une démo
autonome retirée en août, et aucune page du dépôt n'y menait.

**Ce qui empêchera la même dérive.** Un artefact publié sur un registre est un porteur de version
qu'aucune recherche dans l'arbre n'atteint. `modules.json` déclare désormais, sous
`artefacts_retires`, ce qu'un registre ne doit plus servir, et `scripts/check-versions.sh` gagne
deux modes qui partent de l'extérieur : `--publications` vérifie chaque matin que la version
courante d'un module a sa release et qu'un artefact retiré ne répond plus, sans secret dédié ;
`--artefacts-orphelins` liste les paquets publiés au nom de ce dépôt que plus aucun module ne
revendique.

### Le lab enrôle de nouveau un appareil — 27 septembre 2026

L'enrôlement « cet appareil » refusait le bon mot mémorisé (« Compte ou mot mémorisé
incorrect »), sur tout compte créé depuis le salage par compte du 27 août. `/api/sel.php` devait
rendre à la session le sel de son compte ; il lisait ce sel sur le compte de session, qui ne le
porte pas, et rendait le sel de repli. Le navigateur dérivait donc une autre empreinte. Le sel est
désormais relu en base. Le banc `sanity_sel_session.php` passe par la vraie route, de
l'inscription à l'enrôlement ; le banc client simulait le serveur et ne pouvait pas le voir.

Le décor du lab (`seed.php`) referme la session que l'inscription ouvre : ses trois comptes
laissaient chacun un jeton valide 24 heures en base.

Le coffre mémo conseillait de reprendre la passphrase reçue à l'inscription, et son champ de mot de
passe s'intitulait « Ton mot de passe ». Or le serveur reçoit les secrets du compte : le mot de passe
à chaque connexion, la passphrase à chaque récupération. Un coffre scellé avec eux n'est plus hors de
portée du serveur. Les deux champs demandent désormais des secrets propres au coffre, en français et
en anglais.

### Le CTF annonce sa Saison 2 : deux drapeaux, et des promesses exactes — 26 septembre 2026

La page red team présentait un seul drapeau, dans le mémo, chiffré « AES-256-GCM ». Elle en
porte désormais deux, nommés avec leurs comptes, et dit pour chacun ce qui le protège et ce qui
le fait tomber :
- **FLAG-E2E** est chiffré dans le navigateur (Argon2id, HKDF puis AES-GCM) : ni un dump, ni un
  administrateur, ni le serveur ne le révèlent.
- **FLAG-DM** est chiffré au repos par SelfDataGuard : un dump seul ne suffit pas, la clé serveur
  si.

Quatre affirmations fausses de l'interface sont corrigées :
- **les rapports red team** étaient dits chiffrés par SelfDataGuard ; ils le sont en PGP, dans le
  navigateur ;
- **les messages privés** étaient dits « de bout en bout » ; ils sont chiffrés au repos ;
- **le mémo** était attribué à SelfDataGuard ;
- **les profils et DM** étaient dits lisibles par un administrateur, alors que c'est la clé serveur
  qui permet de les lire.

La feuille de route n'annonce plus Argon2id comme à venir.

Deux corrections suivent :
- la notification d'un nouveau rapport affiche la sévérité validée ; elle lisait un champ
  inexistant et affichait toujours « ? » ;
- le compteur public de drapeaux capturés compte aussi FLAG-DM.

### Le mémo chiffré du lab scelle en Argon2id ; PBKDF2 quitte le dépôt — 26 septembre 2026

Le mémo du lab était le dernier code à dériver une clé par PBKDF2 : 600 000 tours, et un
coût presque nul en mémoire. Il était exempté nommément de `check-profil-unique.sh` depuis
le 10/09, à la condition qu'il migre. Il a migré.

- **`e2e-memo.js` dérive par `srKdfDeriver`**, le porteur Argon2id de SelfRecover : même
  profil figé, même plancher de lecture. Les étiquettes HKDF `data-enc` / `data-recover` et
  les deux enveloppes ne changent pas.
- **Le coffre inscrit ses paramètres** (`kdf`, en JSON) ; le serveur exige leur forme et la
  normalise. Un coffre sans `kdf` est refusé à l'ouverture, avec un message qui dit de le
  recréer. Aucun n'existait : 0 coffre en prod comme en local, mesuré avant la migration.
- **L'exemption est retirée** : aucune dérivation de mot de passe en JavaScript hors du
  porteur, sans exception.
- **Corrigé au passage** : la page attendait que la création rende la clé du coffre pour
  enchaîner sur l'écriture ; elle ne la rendait pas, et le premier enregistrement répondait
  « verrouillé ».

Bancs : `sanity_memo_client.js` (11 cas, dont un oracle — PHP rouvre avec libsodium,
`hash_hkdf` et openssl l'enveloppe produite par le JavaScript) et `sanity_memo_vault.php`
(9 cas). Défauts plantés : une étiquette HKDF changée n'est vue que par l'oracle.

### Le lab décrit ses clés telles qu'elles sont — 26 septembre 2026

La page sécurité du lab et le PDF d'architecture qu'en tire `docs/gen_doc_mapping.py`
décrivaient un seul secret racine, dont HKDF aurait tiré trois clés filles : `auth`,
`data-enc`, `data-recover`. Aucun code ne dérive ainsi. L'étiquette `auth` n'a aucun
consommateur, et SelfDataGuard ne consomme aucune clé de SelfRecover.

- **La section 3 de la page sécurité**, en français et en anglais, dit ce que fait le code.
  L'accès passe par SelfRecover : une empreinte `HMAC-SHA256` du mot, liée au site et salée
  par compte, dont le serveur garde un Argon2id. Le mémo tire deux clés filles par étiquette
  HKDF, `data-enc` depuis le mot de passe et `data-recover` depuis la passphrase de secours.
  La récupération n'est unifiée que si cette passphrase est aussi celle de SelfRecover.
- **Le PDF d'architecture (v1.1)** redessine le schéma A en deux branches qui ne se croisent
  pas. Il précise aussi que « le serveur ne peut rien déchiffrer » vaut pour le mémo, pas pour
  les messages et profils que SelfDataGuard chiffre côté serveur.
- **`DataGuard` du lab ne dérive plus sa clé à chaque appel.** Chaque dérivation coûtait un
  Argon2id de 64 Mio, environ 40 ms : lister 20 messages en payait 20. La clé est désormais
  gardée par contexte le temps de la requête. La dérivation ne change pas, donc les données
  existantes restent lisibles.

### SelfRecover-LUKS n'installe plus un keyscript que le slot n'ouvrirait pas — 26 septembre 2026

Un slot enrôlé en `raw` n'est pas ouvert par un keyscript qui produit de l'hexadécimal.
Le keyscript livré produit de l'hex depuis le passage du 12/09, et une machine installée
avant garde un slot `raw` : y déposer le nouveau keyscript la rend inamorçable, au
redémarrage suivant ou à la prochaine mise à jour du noyau. Seul `INSTALL.md` §15 le
disait ; le format enrôlé n'était écrit nulle part, donc rien ne pouvait s'y opposer.

- **Le format enrôlé est inscrit, par volume** : `setup-add-selfrecover-slot.sh` écrit
  `<UUID LUKS> <hex|raw>` dans `$SKG/format-slot` une fois le slot prouvé ouvrant. Le
  marqueur est indexé par volume : un slot hex sur un volume de données ne dit rien de la
  racine.
- **`install.sh` refuse de poser un keyscript d'un autre format** que celui enrôlé pour la
  racine. Il refuse aussi un keyscript déjà en place sans marqueur, et dit comment établir
  le format réel puis l'inscrire.
- `INSTALL.md` §15 compte les slots avant la migration, et passe le dépôt manuel du
  keyscript par le même contrôle.

Banc `tests/test_format_slot.sh`, 14 cas sur des conteneurs LUKS de 32 Mo, sans root. Un
canari en CI retire la comparaison des formats, et le banc doit rougir sur le cas slot
`raw` / keyscript `hex`. Quatre défauts plantés ont chacun fait rougir leur cas.

### La console SU n'écrit plus au journal ce que la base a refusé — 26 septembre 2026

- **La base et le journal réussissent ou échouent ensemble.** `first-admin`, `revoke-admin`,
  le remplacement, `approve-request`, `reject-request` et la quarantaine d'`audit` écrivaient au
  journal **avant** la base : une écriture refusée par la base laissait au journal un acte qui
  n'avait pas eu lieu. Chaque verbe écrit désormais en base, puis au journal, dans une seule
  transaction ; si le journal refuse, la base revient en arrière.
- **Les déclencheurs du dernier admin suivent le code.** Posés en `CREATE TRIGGER IF NOT EXISTS`,
  ils gardaient sur une base existante le corps qu'elle avait reçu à sa création. Leur texte est
  comparé à l'ouverture et réécrit s'il diffère ; à texte égal, rien n'est écrit.

Banc `sanity_first_admin.php` : 30 cas. Chacun des quatre ajoutés a été vu rougir sur son défaut
planté.

### La console SU garde toujours un administrateur — 23 septembre 2026

`selfrecover-su` nommait autant d'administrateurs qu'on voulait par `add-admin`, révoquait le
dernier, et `audit` pouvait mettre en quarantaine tous les admins d'un coup. Plus rien ne
permettait alors d'en nommer un.

- **`first-admin` nomme le premier admin, une fois par cycle.** La voie est « consommée » dès
  qu'un octroi figure au journal après le dernier reset. `add-admin` ne promeut plus : les admins
  suivants passent par une demande, que le SU tranche avec `approve-request`.
- **La base garde toujours au moins un admin.** La console refuse de révoquer le dernier
  (code 6), et deux triggers SQLite refusent la même chose derrière elle, pour tout chemin de code
  qui l'oublierait. `revoke-admin <x> --remplacant <y>` promeut puis révoque dans une seule
  transaction.
- **`audit` ne vide plus la base de ses admins.** Quand tous les admins en base sont absents du
  journal, l'entrée est plus probablement cassée qu'envahie : il refuse, sort en code 7 et ne met
  personne en quarantaine.
- **Deux resets, et eux seuls rouvrent `first-admin`.** `reset-shell` (passphrase SU perdue)
  révoque les admins et garde les comptes. `reset-db` (compromission) fige la base et le secret SU
  sans les détruire, puis repart d'une base vide. Le journal est gardé : il affiche le reset en
  bandeau, et `list-admins`, `audit` et `verify-log` le rappellent en tête.

Banc `sanity_first_admin.php`, 26 cas, qui lisent chacun le code de sortie ET la base. Un canari
retire les deux gardes du dernier admin, et le banc doit rougir sur ce cas.

### La clé du journal SU se tourne sans casser la chaîne — 23 septembre 2026

Poser une nouvelle `SELFRECOVER_SU_AUDIT_SECRET` à côté d'un journal existant le rendait
« signature invalide » dès l'entrée 1, parce que `verify()` recalcule chaque HMAC avec la clé
courante. `rotate-audit-key --nouvelle-cle <fichier>` vérifie la chaîne sous l'ancienne clé,
recalcule seulement les HMAC, puis vérifie le fichier neuf avant de le mettre en place.
`entry_hash` et `prev_hash` ne changent pas, donc les témoins déjà externalisés restent
valables. L'ancien journal est figé, et l'entrée de rotation repose le sceau du secret SU.

Il refuse une chaîne rompue, une ligne qu'il ne sait pas relire, et une clé courte, identique à
celle en place ou lisible par d'autres que son propriétaire. La clé se lit dans un fichier et
jamais en argument : `sudo` recopie la ligne de commande dans le journal système.

🔑 **La vérification préalable n'est pas une précaution.** Sans elle, une entrée forgée par
quelqu'un qui n'a pas la clé (chaînage juste, HMAC faux) ressort authentique sous la nouvelle.
C'est le cas que vise le canari. Banc `sanity_rotation.php`, 18 cas.

### Le lab et les textes rattrapent la console — 23 septembre 2026

La console simulée du lab n'offre plus `add-admin`. Elle montre `first-admin`,
`approve-request`, le refus du dernier admin et le remplacement. Les READMEs et les whitepapers
de SelfRecover attribuaient la clé HMAC du journal à la passphrase SU : c'est
`SELFRECOVER_SU_AUDIT_SECRET`, distincte d'elle.

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

## [SelfRecover v0.7.0] — 27 septembre 2026

### SelfRecover v0.7.0 — les chemins qui mènent au compte freinent par compte, et le déploiement déclare son profil — 27 septembre 2026

`Recovery::parCode()` ne consultait que le compteur par adresse, et seulement si une adresse lui était
passée. Il écrivait pourtant un compteur par compte à chaque échec, sous l'étiquette `code:<compte>`,
que personne ne relisait : le paramètre `maxEchecsCompte` n'avait aucun effet au niveau 2. Derrière un
service caché ou un proxy mutualisé, où l'adresse ne veut rien dire et vaut `null`, il ne restait donc
aucun frein — qui détenait une feuille de codes volée pouvait essayer des mots mémorisés sans limite.

Deux seuils désormais, tous deux paramètres de construction : `maxEchecsCompte` échecs sur
`fenetreEchecs` font attendre, et `maxEchecsL2AvantSuspension` depuis le dernier réarmement suspendent
la récupération par code de ce compte. Réarmer, c'est émettre un lot de codes ou réussir une
récupération par code. Le contrôle a lieu avant les deux Argon2id : un essai freiné ne consomme ni code
ni aucun des compteurs de la bibliothèque — le quota que la démo du duo tient par session, lui, est
pris avant l'appel. Le compteur est lu avant l'essai et écrit après, donc des requêtes simultanées passent
ensemble, au plus le seuil plus le nombre de requêtes servies en parallèle moins une — la même borne
qu'au niveau 1.

L'étiquette de ce compteur est un HMAC sous le sel du déploiement, comme celle du niveau 3 depuis
qu'une étiquette en clair y avait été mesurée remplissable depuis la page de connexion. La table des
tentatives est partagée : un nom de compte soumis y arrive tel quel. En clair, le compteur du niveau 2
d'un tiers se remplissait en échouant six fois sous son nom, et ses codes papier cessaient de
fonctionner. `etiquetteEchecsL2()` est publique pour qu'un intégrateur qui pose son propre frein lise
l'étiquette au lieu de la recopier.

Un code introuvable ne se rattache plus à aucun compte : l'étiquette est absente, là où elle valait
`code:inconnu`. Un compte de ce nom héritait du frein de toutes les fautes de frappe du service.
`login_attempts.username` devient donc facultatif, dans les trois schémas et pour les bases existantes
du lab.

Enfin `consommerCode()` écrivait `used = 1` sans condition sur l'état, alors que `parCode()` lit
`deja_utilise` avant deux Argon2id : deux requêtes portant le même code valide réussissaient toutes les
deux. La garde vit maintenant dans l'écriture, qui refuse de porter sur autre chose qu'une ligne encore
libre, et lève un `CodeDejaConsomme` — un type à elle, pour que `parCode()` rende le refus ordinaire au
perdant de la course sans confondre cette course avec une panne de la base. Un double-clic ne produit
plus d'erreur de serveur sur une route qu'on n'atteint qu'avec les deux facteurs bons.

**Ce que le refus dit, et ce qu'il taît.** Le frein par fenêtre rend le message du frein par adresse, au
mot près, et paie le même délai : nommer le compte apprendrait à qui détient un code que ce code en vise
un vrai, et l'apprendrait sans payer les deux Argon2id. La suspension, elle, doit se dire — sinon son
titulaire ne sait pas quoi faire — et c'est le seul refus de cette classe qui nomme un état. Le modèle de
menace et les whitepapers portent la concession.

⚠️ **Migration.** `login_attempts.username` devient facultatif dans les trois schémas. Sur une base créée
avant, l'ancien `NOT NULL` refuse l'insertion d'une tentative sans étiquette, et la route rend une erreur
de serveur au premier code introuvable. Le lab reprend sa base seul ; `bi-self/selfrecover/schema.sql`
porte la note pour les autres. Le contrat de stockage passe de 39 à 41 méthodes : deux lectures de date,
à implémenter dans un adaptateur tiers.

Le banc `sanity_recovery.php` passe de 31 à 51 cas et annonce son compte, que l'intégration continue
exige — l'étape ne lisait que son code de sortie. Un canari débranche le frein et vérifie que le banc
rougit sur le bon cas. Le fuzzer du niveau 3, qui construit `Recovery` et qu'aucun job ne lançait, entre
en intégration continue.

**Le profil de déploiement devient obligatoire**, sans valeur par défaut : `clearweb` ou `tor-onion`.
Il ne décrit pas un réseau mais une clé — deux appelants différents arrivent-ils sous deux origines que
cette bibliothèque peut lire ? Un service caché répond non ; une base qui ne porte pas de colonne
d'adresse répond non aussi, et se déclare pareil, ce que fait la démo `bi-self-duo` en étant servie sur
le web ordinaire.

Le profil refuse l'argument qui le contredit, aux points d'entrée de la récupération, de l'enrôlement et
de l'ouverture d'un dossier. Sans ce refus il ne serait qu'une déclaration, et les deux erreurs qu'il
attrape laissent un service qui a l'air de fonctionner : une origine partagée par tous freine tout le
monde ensemble dès les premiers échecs de n'importe qui, et une origine absente rend le frein par adresse
inerte sans que rien ne le signale. Le second a été trouvé en posant ce profil, dans notre propre lab :
son aide à l'enrôlement acceptait une origine facultative, et un appelant qui l'oubliait perdait le
frein. Elle l'exige désormais.

Le banc de la récupération se joue sous les deux profils, dans la même étape d'intégration continue : le
corps est le même, seule l'origine change. Ce que le frein par compte doit tenir des deux côtés se
mesure donc des deux côtés. Un canari neutralise le refus du profil et vérifie que le banc rougit.

**L'enrôlement d'un appareil rejoint les chemins freinés, et cesse de s'intégrer en silence.** Il mène au
compte avec le seul mot mémorisé — mesuré sur le fil le 13 août 2026, en trois requêtes, l'attaquant
apportant sa propre clé : il enrôle, s'authentifie, et reçoit un mot de passe neuf, les sessions du
titulaire coupées. Les codes de secours et la passphrase n'y servent à rien, le chemin les contourne.

`enroler()` prend désormais un `Titulaire`, obligatoire et sans défaut, et refuse `NON_VERIFIE`. Ce n'est
pas une preuve : le contrat de stockage sait révoquer des sessions, jamais en lire une, et la
bibliothèque ne vérifie pas plus l'autorisation ici qu'au niveau 3. C'est une affirmation, que
l'intégrateur doit écrire, et qui se relit en revue. Ce qu'aucune valeur ne remplace est dit dans le
type : le nom du compte vient de la session ouverte, jamais du corps de la requête.

Il reçoit aussi le frein par compte qui lui manquait — cinq échecs sur la même fenêtre, sur une étiquette
sous HMAC, sans seuil de suspension puisqu'il n'y a pas de feuille de codes à plafonner. Le refus est
celui du frein par adresse, au mot près, et paie le même délai. Sous `tor-onion`, où l'adresse ne freine
rien, ce chemin n'avait jusqu'ici aucun frein du tout.

🔑 **Son étiquette vient du nom SOUMIS, pas du compte trouvé**, et c'est ce qui distingue ce chemin de la
récupération par code. Tirée du compte, elle n'aurait existé que pour les comptes réels : le frein
n'aurait mordu que sur eux, et six requêtes sur un nom choisi auraient dit s'il existe — l'oracle que le
message unique de cette méthode existe pour refuser. La première version de ce correctif l'ouvrait ; il a
été mesuré, puis fermé. Le prix, assumé et déjà celui du niveau 1 : qui soumet un nom en boucle ferme
l'enrôlement de ce nom pendant la fenêtre. C'est un confort, pas une récupération. L'étiquette valait
`enroll:inconnu` pour tout nom introuvable, donc un compte de ce nom héritait du frein de tout le service.

⚠️ **La console du lab perd l'attribution de ces échecs** : elle affichait `enroll:<compte>`, elle affiche
maintenant un HMAC. C'est le prix que le niveau 2 paie déjà, et il se paie ici pour la même raison — une
étiquette lisible est une étiquette qu'une route publique peut écrire.

Le calcul des étiquettes quitte `Recovery` pour `Etiquette` : un seul `hash_hmac` subsiste dans la
bibliothèque, et les trois chemins qui écrivent dans ces compteurs y arrivent. Les signatures publiques ne bougent pas : `indexRecherche()` et `etiquetteEchecsL2()`
délèguent. Recopier ce calcul aurait laissé diverger deux définitions de la même règle, et le frein qui
relit serait devenu muet du côté qui bouge.

**Reste ouvert** : l'étiquette du niveau 1 est le nom saisi, donc falsifiable ; son compteur ne freine que
le compte visé, et la déplacer remettrait à zéro le frein de tous les déploiements en service.

---

## [selfright-mcp 0.4.6] — 27 septembre 2026

### selfright-mcp 0.4.6 — le renvoi « Voir « homonymes » » trouve sa cible — 27 septembre 2026

La réserve que rend `/jurisprudence/verifier` renvoie à un champ de la réponse : « Voir
« homonymes » ». L'API le peuple, et tout client HTTP le reçoit ; le serveur MCP relayait la
réserve mot pour mot et jetait le champ. L'invitation arrivait donc au modèle sans rien derrière
elle, dans les deux cas où la réserve la porte — décision trouvée à la date annoncée, et numéro
existant dont aucune décision ne porte cette date. Ce second cas est celui où la liste sert le
plus : elle montre les dates disponibles sous le même numéro à qui a mal daté la sienne.

`verifier_jurisprudence` rend désormais les homonymes, cour et date, dans l'ordre de l'index —
décroissant — et borné à dix. Aucun total n'y est recompté : la liste que rend l'API est bornée par
la limite de sa requête et peut être plus courte que le nombre annoncé par la réserve, seule à le
connaître — 49 rendus pour 60 annoncés sur `23/00039` du 5 janvier 2023.

Un garde-fou tient les deux moitiés de la phrase ensemble : tant que `api.php` renvoie à ce champ,
chaque réponse du client qui relaie une réserve doit le rendre.

---

## [selfright-mcp 0.4.5] — 27 septembre 2026

### selfright-mcp 0.4.5 — le texte d'une décision administrative sort enfin — 27 septembre 2026

`texte_decision` lisait toute réponse dans la forme de Judilibre. Pour une décision du Conseil
d'État, d'une cour administrative d'appel ou d'un tribunal administratif, il rendait « texte non
fourni » et l'attribuait à Judilibre, alors que l'API servait le texte entier depuis l'index JADE.
Il lit désormais les deux formes, nomme JADE comme provenance, relaie la réserve qui accompagne une
date aberrante, et renvoie vers Légifrance quand le texte est coupé.

L'âge annoncé de la jurisprudence ne compte plus les fonds sans décision depuis plus d'un an : les
tribunaux administratifs (arrêtés en 2009 dans JADE) et la Cour de discipline budgétaire et
financière (en 2000) lui faisaient afficher « 9657 jours ». Ces fonds restent nommés, à part, avec
leur borne. Les juridictions administratives sont nommées en toutes lettres au lieu de leur code.

---

## [SelfJustice v0.4.1] — 27 septembre 2026

### SelfJustice v0.4.1 — le Conseil d'État se vérifie, et `/verifier` ne nie plus une décision présente — 27 septembre 2026

`/verifier` prenait les cinquante décisions les plus récentes d'un numéro, puis filtrait par la date
annoncée. Une décision présente derrière plus d'homonymes récents n'était jamais examinée, et la
route la déclarait absente : mesuré en production sur `23/00039` du 5 janvier 2023, porté par 61
décisions. Les décisions du jour annoncé passent désormais en tête du tri, et le nombre d'homonymes
que lit le modèle vient du total, non de la liste bornée.

Le fonds JADE, servi depuis le 10 septembre, n'était pas cherchable par numéro : son collecteur
n'écrivait jamais dans `numeros`, la seule table que lit `/verifier`. La décision du Conseil d'État
n° 519395 du 9 septembre 2026, présente en base, était déclarée absente, avec la réserve que la
justice administrative « n'y figurera jamais ». `build_jade_db.py` inscrit maintenant chaque
numéro, sous la règle de normalisation de l'API, et `--numeros` rattrape une base existante. La
réserve « ArianeWeb » ne s'affiche plus que si l'index ne sert pas la justice administrative, et la
recherche par thème filtrée sur une juridiction que Judilibre ne connaît pas refuse avec une
indication, au lieu de rendre l'erreur de l'amont comme une panne.

Les textes disent ce que l'instance garde — le journal d'accès, IP comprise, quatorze jours ; les
retours de mise en page, trente jours — là où ils promettaient « aucune donnée personnelle ». La date
du pied de page, que la page demande aux IA de citer, vient du fichier servi. Les articles 750-1 du
code de procédure civile et 54 de la loi n° 71-1130 sont cités d'après la base LEGI.


## [SelfAct v0.1.3] — 27 septembre 2026

### SelfAct v0.1.3 — le module se décrit comme il est — 27 septembre 2026

Depuis la 0.1.2 du 23 août, l'avertissement « NON OFFICIEL » suit ce que le document imite, la
section des faits porte le titre que son gabarit annonce, et l'adresse d'exemple du brouillon passe
sur un domaine réservé.

Les textes présentaient SelfAct comme un générateur de documents « conformes » qui lit une analyse,
dit quoi signer et livre un dossier. Le code fait l'inverse, volontairement : un modèle à trous,
rempli dans le navigateur, et un `POST` refusé sans lire le corps. README, whitepaper, pages servies
et conditions d'utilisation disent maintenant ce qu'il fait et ce qu'il ne fait pas. La saisine du
conciliateur de justice n'est plus dite « obligatoire » à elle seule : l'article 750-1 laisse le
choix entre conciliation, médiation et procédure participative.


## [selfright-mcp 0.4.4] — 27 septembre 2026

### selfright-mcp 0.4.4 — le périmètre de la jurisprudence suit la couverture — 27 septembre 2026

Aucune version n'a été publiée depuis la 0.4.0 du 22 août. Les 0.4.1 et 0.4.2 ont corrigé ce que
le contrôle extérieur du 22 août avait relevé, montré d'où vient un texte, et rendu obligatoire
`SELFRIGHT_ACT_URL`. Le serveur a ensuite suivi l'élargissement de la base à tout le droit publié au
Journal officiel, nommé ses 108 codes, dit ce que la base ne contient pas, et transmis au modèle les
seuils d'une situation. Le numéro a rattrapé ces changements le 26 septembre, en 0.4.3.

En 0.4.4, le bandeau de la jurisprudence tire son périmètre de la couverture que rend `/status` :
la justice administrative n'y est plus dite absente dès que l'index la sert, et
`verifier_jurisprudence` nomme les juridictions administratives qu'il accepte.


## [SelfDataGuard v0.4.0] — 26 septembre 2026

### SelfDataGuard v0.4.0 — le chiffrement ne dépend plus du processeur — 26 septembre 2026

Jusqu'à la 0.3.0, SelfDataGuard chiffrait en AES-256-GCM par libsodium, qui ne le sert
qu'avec un support matériel : AES-NI, plus AVX depuis libsodium 1.0.19. Sur un Celeron
sans AVX avec une libsodium récente, ou sur un Raspberry Pi 4, la bibliothèque ne pouvait
ni écrire ni relire, et son message accusait AES-NI à tort.

- **Toute écriture passe en XChaCha20-Poly1305**, calculé en logiciel et en temps constant
  sur tout processeur, dans un format versionné : `SDG2.` suivi du base64.
- **Les blobs écrits avant restent lisibles**, par libsodium là où il sert AES, par OpenSSL
  ailleurs. Éprouvé sur un Raspberry Pi 4 sans AES matériel : les 8 bancs passent, et une
  base écrite par la 0.3.0 se relit entièrement.
- ⚠️ **Un blob écrit par la 0.4.0 ne se relit pas en 0.3.0** : un retour arrière ne vaut
  que pour une base où la 0.4.0 n'a rien écrit.
- Les clés et les clairs n'apparaissent plus dans les traces d'exception
  (`#[\SensitiveParameter]`).

Bancs : 219 contrôles, dont le vecteur de test officiel de XChaCha20-Poly1305 et un blob
AES figé que libsodium et OpenSSL produisent à l'identique. Détail dans le CHANGELOG du
module.

## [SelfRecover v0.6.0] — 22 septembre 2026

### SelfRecover écrit Argon2id dans la bibliothèque, la démo quitte PBKDF2 — 10 septembre 2026

Six documents annonçaient Argon2id pour le facteur « cet appareil » ; la seule
implémentation faisait PBKDF2, avec un blob qui ne disait ni sa version ni son algorithme.
`client/argon2id.js` porte désormais la KDF et `client/sr-kdf.js` le format : version et
paramètres **dans** le blob, et un blob d'avant le versionnage reconnu pour ce qu'il est.

Vérifié contre `tests/vecteurs-argon2.json` — sept empreintes produites par libsodium, une
implémentation écrite par d'autres. Deux défauts invisibles à la lecture y ont été
attrapés, qui rendaient un résultat bien formé et faux. Coût mesuré : 1 115 ms par
dérivation contre 163 pour le WebAssembly retiré ; le profil ne baisse pas, c'est la
mémoire qui coûte à un attaquant.

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

### Un instant du contrat doit en être un — 10 septembre 2026

⚠️ **Changement de comportement pour les adaptateurs.** `Litige` refuse désormais, à la
construction, tout instant inférieur à 1 000 000 000 (`InvalidArgumentException`, qui
nomme le champ). Un adaptateur dont une colonne d'instant est restée en `TEXT` lève là où
il passait en silence : vérifier ses colonnes avant de monter de version.

Les instants de `StorageInterface` sont typés `int`, et aucun ne disait en quelle unité.
Le typage ne suffit pas : `(int) '2026-07-12 08:00:00'` vaut `2026`. Aucune ligne
n'échouait, et l'arbitre recevait un dossier daté de janvier 1970 — donc expiré, donc
invisible. Le contrat énonce maintenant l'unité, la seconde Unix. `deposeLe = 0` et
`trancheLe = null` restent légitimes ; c'est la zone entre zéro et le plancher qui est
refusée.

Huit contrôles ajoutés à `sanity_escalade`, dont trois contre-témoins : sans eux, un
constructeur qui refuserait tout rendrait les autres verts.

`Crypto\Hashing::needsRehash()` entre aussi dans la bibliothèque — son usage est décrit
dans la section « Non publié », à l'entrée du secret SuperUser du lab.

### SelfRecover était déployé et non déployé, à sept lignes d'intervalle — 9 septembre 2026

`bi-self/README.md:57` annonçait « deployed and self-audited implementation » et la
section Statut, sept lignes plus bas, « no real-world production deployment yet ». Le
lecteur devait trancher seul entre deux affirmations opposées du même fichier.

**C'est la ligne 57 qui dit vrai**, vérifié sur la machine par la conv GitHub : le
backend d'authentification d'un service de messagerie sert la bibliothèque en conditions
réelles. La section Statut le dit désormais, dans les deux langues, et garde ce qui reste
exact — la démo est auto-auditée, aucun audit externe n'a été mené.

---

## [SelfRecover-LUKS v0.5.0] — 22 septembre 2026

### SelfRecover-LUKS v0.5.0 — plus de shell avant l'ouverture du disque, et trois plateformes — 12 au 22 septembre 2026

Neuf commits depuis `selfrecover-luks-v0.4.0`, sans rupture de contrat.

- 🔴 **Le SSH d'amorçage ne rend plus de shell.** La clé posée par `install.sh` était nue :
  elle ouvrait un busybox root **avant** le déverrouillage de `/`. Comme `/boot` est en
  clair et que le sel y voyage, ce shell suffisait à déposer un initrd modifié et à capturer
  la passphrase suivante. `selfrecover-secours.sh` devient le `command=` de la clé : il
  propose la passphrase recover **ou** la passphrase native, rien d'autre — le filet
  anti-verrouillage reste atteignable à distance, ce que `command="cryptroot-unlock"` seul
  aurait retiré. `install.sh` pose la question (`SHELL_AMORCAGE`) et consigne un refus dans
  `renoncements.log`. `verifie-initramfs.sh` compare les images de `/boot` à une empreinte
  consignée sur le volume chiffré : la classe n'est pas fermée, elle devient visible.
- 🔴 **Les sauvegardes irréversibles se vérifient.** `verifie-sauvegardes.sh`, lancé par
  `install.sh` avant le premier geste qui écrit dans l'en-tête, refuse une copie d'en-tête
  ou de sel rangée sur le volume qu'elle sert à ouvrir, ou dont le nombre de slots ne
  correspond plus au disque.
- 🔴 **Le sel n'était jamais vérifié sur une machine à microcode.** Une image Intel ou AMD
  s'extrait en `early/` + `main/` ; le garde-fou cherchait le sel à la racine, annonçait
  « SEL NON VERIFIE » et sortait en 0.
- **ARM (Raspberry Pi 4, Debian 13).** L'installeur ne suppose plus x86 + GRUB : `rootdelay`
  passe par `/etc/default/raspi-extra-cmdline`, `python3-argon2` est exigé au préambule,
  libargon2 se trouve par `ldconfig`, et le garde-fou juge l'image que l'amorceur
  **charge** (`config.txt`), pas celle qu'`update-initramfs` vient de produire.
- Les scripts du module sont exécutables depuis un clone frais ; les textes déclarent les
  trois plateformes éprouvées (serveur, portable, racine en LVM chiffré).

Bancs ajoutés : `test_secours_sans_shell.sh`, `test_sauvegardes.sh`,
`test_garde_fou_image_chargee.sh`.

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

---

## [SelfJustice v0.4.0] — 11 septembre 2026

### SelfJustice v0.4.0 — la jurisprudence administrative, et ce qu'il a fallu défaire pour l'atteindre — 11 septembre 2026

La roadmap réservait la v0.4.0 au Conseil d'État et aux juridictions administratives. Le chantier
avait été annoncé comme tenant « à une ligne » : ajouter `ce` à la liste des juridictions du
moissonneur Judilibre, et remoisonner. **C'était faux**, et l'affirmation venait d'une lecture du
code du *client*, jamais d'un appel au *serveur*. L'amont a répondu :
`Value of the jurisdiction parameter must be in [cc,ca,tj,tcom]`. Judilibre ne sert pas l'ordre
administratif ; il n'a jamais eu ce paramètre. Sept heures de calcul y sont passées, sur un
intervalle allant de l'an 100 à 2027 — sans erreur, sans code de sortie, un processus vivant qui
paraissait travailler.

Deux défauts en sont sortis, corrigés avant la fonctionnalité elle-même :

- **Un paramètre refusé ne se découpe plus.** `appel()` rendait la même valeur pour « cette tranche
  pèse trop lourd » — où couper la fenêtre en deux est la bonne réponse, et c'est ainsi qu'on
  traverse 1,19 million de décisions à travers un guichet plafonné — et pour « ce paramètre
  n'existe pas », où couper ne corrige rien : la moitié d'un intervalle est tout aussi invalide.
  L'amont rend les deux en HTTP 400 ; la cause était effacée avant d'atteindre celui qui décide.
  Un refus définitif lève désormais son exception propre. Le banc juge sur le **nombre d'appels** :
  1 sur un paramètre refusé, plus de 340 000 sur un refus de volume.
- **La couverture juridictionnelle se dérive de l'index**, au lieu d'être redéclarée à trois
  endroits. Le guichet s'ouvre donc sur ce que la base contient réellement.

**La source est le fonds JADE de la DILA** : un dump global et ses incréments quotidiens, sans API.
Un collecteur distinct le lit (`tools/build_jade_db.py`), avec la robustesse prise sur le
moissonneur Judilibre et non sur le collecteur LEGI — journal horodaté, marqueur de fraîcheur écrit
seulement sur passage complet, code de sortie non nul sur moisson partielle. **Le global seul est un
piège, et il avait déjà servi** : LEGI a été construit pendant treize mois sur un dump figé dont les
diffs étaient téléchargés et jamais appliqués, servant honnêtement une date qui ne bougeait pas.
`--depuis auto` refuse de s'arrêter tant qu'un incrément postérieur reste à appliquer.

**La table des juridictions a été écrite sur l'inventaire du fonds entier, pas sur un échantillon.**
Quatre incréments récents portaient dix libellés, tous dans la même graphie ; le fonds en porte
**113** — `Conseil d'Etat` et `Conseil d'État`, `CAA de MARSEILLE` et `Cour Administrative d'Appel
de Marseille`, des capitales, des accents absents. Une table bâtie sur l'échantillon aurait classé
le présent et laissé soixante ans de décisions sous des juridictions fantômes, sans erreur ni
total qui le montre. La normalisation **refuse** ce qu'elle ne reconnaît pas plutôt que de le ranger
sous un code par défaut, et le jeu de test est l'inventaire lui-même.

Mesuré sur l'instance après collecte : **570 896 décisions administratives**, texte intégral
compris, 320 archives appliquées, 0 refusée, 0 illisible.

⚠️ **Deux limites qui se disent plutôt qu'elles ne se devinent.** Le fonds ne porte des tribunaux
administratifs qu'une sélection **arrêtée en 2009**, et la Cour de discipline budgétaire et
financière s'arrête **en 2000** : un jugement de TA récent n'est pas dans la base. Et aucun
incrément ne porte de liste de suppression — une décision retirée du fonds par la DILA y restera.

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

## [SelfModerate v0.3.0] — 22 août 2026

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
