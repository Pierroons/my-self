# SelfDataGuard

> 🇬🇧 **[Read in English →](./README.md)**

**Protection des données au repos côté application, qui survit à une exfiltration de base de données.**

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../../LICENSE)
[![Statut : v0.10.0 disponible](https://img.shields.io/badge/statut-v0.10.0%20disponible-brightgreen.svg)](#statut)
[![Tests : 1 401 passants](https://img.shields.io/badge/tests-1%20401%20passants-brightgreen.svg)](#tests)
[![Pilier : Self-Security](https://img.shields.io/badge/pilier-Self--Security-blue.svg)](../README.fr.md)
[![Compagnon : SelfRecover](https://img.shields.io/badge/compagnon-SelfRecover-green.svg)](../../bi-self/selfrecover/README.fr.md)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

> **Dump ma base de données — et tu obtiens du bruit chiffré.**

---

## Le problème

Tous les produits actuels de chiffrement des données au repos (MySQL TDE, MongoDB CSFLE, AWS RDS encryption) répondent au même modèle de menace : **l'attaquant a le disque, mais pas l'application**. La clé de chiffrement se trouve à côté des données — dans un fichier de configuration, une variable d'environnement, ou un service de gestion de clés que l'application peut lire.

Ce modèle s'effondre dès que **le serveur d'application est compromis**. L'attaquant exfiltre la base de données ET la clé — le chiffrement n'était qu'une case cochée, pas une défense. Les fuites récentes à grande échelle ont montré la même chose à chaque fois : ce sont les données personnelles exposées en clair qui constituent le coût dominant de l'incident, parce qu'elles ne se révoquent pas.

Les outils actuels soit ignorent complètement le chiffrement au repos, soit l'implémentent d'une manière qui n'apporte aucune valeur contre une compromission côté serveur. SelfDataGuard choisit une troisième voie : **dériver la clé de chiffrement d'un secret connu uniquement de l'utilisateur**, de sorte qu'un dump de base ne livre aucune valeur en clair.

---

## Principe central : chiffrement par enveloppe par utilisateur

SelfDataGuard implémente un **encapsulage de clé à plusieurs serrures** inspiré des architectures Bitwarden, 1Password et ProtonMail vault, adapté au chiffrement par utilisateur côté application. La même clé de données est scellée sous chacun des secrets de l'utilisateur, et n'importe lequel la rouvre :

```
                 ┌─────────────────────────────────────┐
                 │      data_master_key par user       │  ← 256 bits aléatoires
                 │      (jamais stockée en clair)      │  ← en mémoire uniquement quand user connecté
                 └──────┬─────────────┬─────────────┬──┘
                        │             │             │
                           encapsulée avec chacune de
                        │             │             │
        ┌───────────────▼┐  ┌─────────▼──────┐  ┌───▼──────────────────┐
        │  password_key  │  │   recov_key    │  │      phrase_key      │
        │ Argon2id(      │  │ Argon2id(      │  │ Argon2id(            │
        │   password,    │  │  mot_memorise, │  │  passphrase,         │
        │   user_salt)   │  │  SHA-256(      │  │  SHA-256(user_salt + │
        │                │  │   user_salt +  │  │   "/dataguard/       │
        │                │  │  "/dataguard"))│  │    passphrase"))     │
        └────────────────┘  └────────────────┘  └──────────────────────┘
```

Argon2id prend un sel de 16 octets : les 16 premiers octets de ce SHA-256. Les deux contextes diffèrent : la même chaîne posée comme mot mémorisé et comme passphrase donne deux clés sans rapport. Les trois clés d'encapsulage coûtent autant, et c'est voulu : des enveloppes ne valent que la moins chère à ouvrir. Le profil Argon2id (3 passes, 64 Mio) est enregistré avec chaque coffre : un coffre garde le sien à chaque rescellement, un coffre neuf prend le profil courant, et changer les constantes ne ferme plus aucun coffre existant. Ce que tu dérives toi-même par `Primitives` n'enregistre pas de profil.

Chaque utilisateur dispose de :

- Un `user_salt` aléatoire unique, stocké en clair (équivalent à un identifiant)
- Un `data_master_key_pwd_wrap` : ciphertext XChaCha20-Poly1305 de la clé maîtresse, chiffré avec la clé dérivée du mot de passe
- Un `data_master_key_recov_wrap` : le même, avec la clé dérivée du mot mémorisé (facultatif)
- Un `data_master_key_phrase_wrap` : le même, avec la clé dérivée de la passphrase (facultatif)
- Des champs de données personnelles chiffrés un par un avec `data_master_key`

**Dump de la base → valeurs chiffrées, pas de clé maîtresse.** Aucune combinaison des valeurs en clair présentes dans le dump ne permet d'obtenir la clé maîtresse. L'attaquant aurait besoin d'un des secrets de l'utilisateur — mot de passe, mot mémorisé ou passphrase, chacun durci par Argon2id et isolé par sel — pour déchiffrer quoi que ce soit. ⚠️ **Ce que le dump dit quand même** : le nom de chaque champ que porte chaque compte et la date à laquelle ce champ a changé, la longueur de chaque valeur (un chiffré n'a pas de remplissage), et, pour un champ indexé, les comptes qui partagent une même valeur. Donne à tes champs des noms neutres, et complète à une même longueur les champs qui ne prennent que quelques valeurs.

---

## Couplage avec SelfRecover

SelfDataGuard scelle la clé de données sur les secrets que SelfRecover donne déjà à l'utilisateur : le mot mémorisé et la passphrase du niveau 1. **Aucun secret de plus à retenir.**

Le mot mémorisé ne quitte jamais le navigateur : celui-ci en calcule une empreinte, et c'est elle que le serveur reçoit. L'intégrateur la passe à SelfDataGuard comme « mot mémorisé ». Elle coûte autant à attaquer que le mot lui-même — HMAC, puis Argon2id —, et les deux dérivations restent isolées :

```
mot_memorise (dans le navigateur seulement)
    │
    └─ HMAC-SHA256(clé = mot, msg = matériel + "|v2" + sel du compte)  →  empreinte (reçue par le serveur)
           │
           ├─ Argon2id(empreinte), sel aléatoire de password_hash()         →  vérification SelfRecover
           │
           └─ Argon2id(empreinte, SHA-256(user_salt + "/dataguard")[:16])   →  recov_key (SelfDataGuard)
```

La passphrase, elle, arrive en clair au serveur au niveau 1. SelfDataGuard la normalise exactement comme SelfRecover : bords retirés, espaces réduits à un seul.

Chaque récupération SelfRecover remplace des secrets. Le coffre suit si l'intégrateur le re-scelle **après** l'acceptation de la récupération, avec le secret que le serveur tient à ce moment-là :

| Récupération SelfRecover | Le serveur tient | Appel SelfDataGuard |
|---|---|---|
| Niveau 1 — passphrase | l'ancienne passphrase | `recover($user, Lock::Passphrase, $ancienne, $nouveauMdp, $nouvellePassphrase)` |
| Niveau 2 — code + mot mémorisé | l'empreinte du mot | `recover($user, Lock::Memorized, $empreinte, $nouveauMdp, $nouvellePassphrase)` |
| Niveau 2 — appareil enrôlé | une signature, rien qui ouvre le coffre | au prochain secret donné : `recover($user, $serrure, $secret, $motDePasseActuel)`, derrière le frein de la façade, comme la connexion |
| Niveau 3 — escalade humaine | aucun ancien secret | `reEnroll($dg->generationOf($user), $mdp, $empreinte, $passphrase)` : l'ancien coffre est **archivé** |

`recover()` ouvre et re-scelle en une seule écriture conditionnelle. Le même appel rattrape un coffre dont l'enveloppe du mot de passe a pris du retard. Il passe par le frein de la façade, comme les connexions : appelle-le juste après une récupération acceptée par SelfRecover, derrière ses compteurs.

**Au niveau 3, l'ancien coffre est archivé.** `reEnroll()` le met de côté, scellé sous ses anciennes serrures, sans péremption, et crée un coffre neuf. Si l'utilisateur retrouve plus tard une ancienne serrure, `openArchive($session, $id, Lock::Passphrase, $ancienne)` puis `readArchive()` lui rendent ses données. La session doit être celle du coffre actuel : par le service, une ancienne serrure seule n'ouvre rien, parce que ces secrets sont justement ceux qui ont pu fuir. Ils peuvent encore arrêter une lecture : `openForObjection($user, $serrure, $ancienSecret, $idArchive)` rend, sans session et derrière le frein, la seule clé d'objection de l'archive — elle s'oppose à une lecture du séquestre de l'archive et ne lit rien —, et sous un gardien `pinArchiveEscrow($user, $idArchive, $serrure, $ancienSecret, $clePubliqueAdmin)` scelle le séquestre de l'archive au gardien sous la clé d'objection propre à l'archive, pour que ses lectures soient annoncées au titulaire et qu'on puisse s'y opposer (depuis la 0.10.0). Qui détient un ancien secret, y compris un secret qui a fui, s'oppose avec lui. Avec un dump de la base, une ancienne serrure ouvre l'archive hors ligne. Restaure les champs par `setFields()` avec les champs que ton application indexe — `readArchive()` ne rend aucune liste d'index, puisque qui écrit la base pourrait l'étendre. Tant que l'archive peut revenir — pendant `RecoveryTickets::RETURN_WINDOW` après sa création (30 jours, la plus longue fenêtre qu'ouvre SelfRecover), ou tant que le coffre du compte est gelé —, aucun autre compte ne peut indexer l'une de ses valeurs : `DuplicateIndexException`, comme pour une valeur tenue en service. `reservedIndexes()` liste à l'exploitant les réservations en cours — compte, archive, champ, échéance —, sans aucun index. Passé ce délai, la valeur est de nouveau libre. Une archive dont le séquestre ne se lit plus — une ligne illisible, une enveloppe qui ne s'ouvre pas, un champ qui ne se déchiffre pas — se lit quand même : `readArchive()` rend ses champs privés, un `escrow` vide et `escrowUnreadable` à vrai. Une archive se détruit par `deleteArchive()`, qui la demande ouverte par une de ses anciennes serrures, par `eraseAccount()` quand aucun dossier ne la garde, ou par `eraseArchive()`, le geste de l'exploitant ; `delete()` la laisse, et `register()` refuse le nom tant qu'il reste une archive : le suivant à l'inscrire tiendrait les archives.

**Si le titulaire revient après un niveau 3, son coffre revient aussi.** SelfRecover 0.13.0 garde les secrets d'avant un niveau 3 valables pendant une fenêtre : un titulaire qui revient avec l'un d'eux reprend le compte. `reinstateArchive($dg->generationOf($user), $idArchive, $ticket, $restoredWithoutIndex)` remet alors l'archive en service — enveloppes, champs avec leurs index, séquestre — et range le coffre donné au demandeur en archive, en une transaction. `freeze($generation, $par, $ticket)` le tient ensuite en lecture seule : il s'ouvre et se lit, toute écriture lève `VaultFrozenException`, jusqu'à `unfreeze()`, que l'intégrateur appelle quand l'arbitre a clos la fenêtre (`Escalade::cloreFenetre()`). `$restoredWithoutIndex` nomme les champs revenus sans leur index. Une valeur qu'un autre compte a indexée quand aucune réservation ne tenait — avant la 0.9.1, ou passé la fenêtre — reste à ce compte, et son champ garde une trace — `lostIndexes($session)` pour le titulaire, `allLostIndexes()` pour l'exploitant — jusqu'à ce que tu aies réglé à qui appartient la valeur et que tu l'indexes de nouveau ; une archive écrite avant la 0.8.0 n'a gardé aucun index, et ses champs indexés sont nommés aussi. Une archive dont la ligne de séquestre ne se lit plus revient aussi : la vue du titulaire lit cette ligne comme réécrite (`escrow-replaced`), ses champs ne s'ouvrent plus à personne, et un dépôt est refusé tant que l'exploitant n'a pas remis la ligne de séquestre depuis une sauvegarde. Si le compte a été effacé entre-temps, `generationOf()` rend `null` : `restoreArchive($user, $idArchive, $restoredWithoutIndex)` remet l'archive en service, et `freeze()` prend le ticket de retour.

**Effacer un compte après un niveau 3 est une tâche de l'exploitant, en deux temps.** `eraseAccount($user, $kept)` efface le coffre vivant, sans session. Dès qu'un ticket de niveau 3 a été appliqué au compte, il garde toutes les archives — le coffre du titulaire comme celui du demandeur — et `$kept` les liste ; aucun ticket n'est effacé, si bien qu'un rejeu reste refusé même après une nouvelle inscription du nom. Tant que le coffre vivant est gelé, il n'efface rien et lève `VaultFrozenException`, comme toute écriture sur un coffre gelé, et `eraseArchive()` de même : tant que le coffre est gelé, rien n'est effacé. Avant le retour du titulaire rien n'est gelé, et `eraseArchive()` efface : le moment de l'appeler est ta décision. Garde la demande, et exécute-la une fois que `unfreeze()` a clos la fenêtre. Une fois le dossier clos, efface chaque archive gardée :

```php
$dg->eraseAccount($user, $kept);
foreach ($kept as $archive) {
    $dg->eraseArchive($user, $archive['id']);   // une fois clos le dossier pour lequel elle était gardée
}
$dg->keptArchives();   // chaque compte dont l'effacement n'est pas fini
```

Une demande d'effacement n'est finie qu'à ce moment-là : jusqu'à `eraseArchive()`, l'archive et son séquestre restent en base, lisibles par la cérémonie, et `register()` refuse le nom. Sans source `RecoveryTickets`, un ticket de niveau 3 n'est inscrit que si ton code en passe un à `reEnroll()` ; sans lui, les archives partent avec le compte. `eraseAccount()` et `eraseArchive()` ne demandent aucun secret : ne les expose qu'à l'exploitant.

> ⚠️ **Un coffre scellé par le seul mot de passe ne survit pas à une récupération SelfRecover.** Les niveaux 1 et 2 remplacent le mot de passe du compte (`Recovery::parPassphrase()`, `Recovery::parCode()`). Un coffre créé par `register($user, $password)` seul n'a qu'une enveloppe, scellée sur l'ancien mot de passe : après la récupération, plus personne ne l'ouvre, et aucune des deux bibliothèques ne le dit. Quand les deux modules partagent un compte :
> - passe `$memorized` et `$passphrase` à `register()` ;
> - après chaque récupération acceptée, appelle `recover()` comme dans le tableau ci-dessus ;
> - au niveau 3, appelle `reEnroll()`. La serrure « mot mémorisé » de l'archive dépend du sel SelfRecover de l'époque, que le niveau 3 remplace : range-le, par exemple comme champ du coffre neuf, si cette serrure doit rester utilisable.

Les récupérations ne sont pas les seuls chemins qui changent un secret : quand ton service change le mot de passe ou renouvelle le mot mémorisé, appelle aussi `setLock()`, **après** l'écriture de SelfRecover. Un chemin oublié laisse une serrure sur un secret que SelfRecover a déjà remplacé.

> ⚠️ **`userId` est comparé octet pour octet.** Il entre dans l'AAD de chaque enveloppe et sert de clé en base : « Alice » et « alice » sont deux coffres. Si tes comptes ignorent la casse, ramène le nom à celui du compte avant chaque appel ; sinon, un coffre créé sous une graphie échappe aux rescellements appelés sous l'autre, et rien ne le signale.

Sans SelfRecover, SelfDataGuard fonctionne quand même, avec les serrures que l'application lui donne.

---

## Modes opérationnels — la bibliothèque n'en implémente qu'un

| Mode | Accès serveur aux données | Compromis | Dans le code |
|------|---------------------------|-----------|--------------|
| **Lite** *(transparent pour les piles legacy)* | Le serveur déchiffre uniquement pendant les sessions utilisateur | Compromission du serveur = les coffres des sessions ouvertes pendant qu'il est compromis, et de qui s'y connecte ; pas ceux des comptes qui ne se connectent pas. Une clé lue pendant la compromission reste valable ensuite : ni un changement de mot de passe ni une récupération ne la font tourner | ✅ c'est ce que fait la bibliothèque |
| **Hybrid** *(visé pour l'e-commerce)* | Champs opérationnels (`email`, `adresse_livraison`) encapsulés avec une clé opérationnelle admin. Champs sensibles (`tel`, `doc_KYC`) nécessitent une session utilisateur | L'admin peut traiter les commandes ; les données sensibles restent zero-knowledge | ❌ spécifié, pas écrit — le `wrap_admin` du coffre vaut `null` (`UserVault::register()`) |
| **Full** *(zero-knowledge pour services à forte exigence)* | Le serveur ne déchiffre JAMAIS. Toute la crypto tourne dans le navigateur, par libsodium compilé en WebAssembly — WebCrypto n'offre ni Argon2id ni XChaCha20-Poly1305 | Certains workflows à redessiner (pas de mails transactionnels asynchrones, notifications push à la place) | ❌ spécifié, pas écrit — le module ne porte aucun code client |

Un déploiement qui l'installe est donc en **Lite**, quel que soit le mode visé : les deux autres sont décrits au whitepaper (§4.2, §4.3) comme une cible, et aucun paramètre de l'API ne les choisit.

Une voie administrateur existe pourtant, hors de ce tableau : le **séquestre** (`src/Escrow/`), compartiment à clé propre qu'un administrateur ne rouvre que par `EscrowCeremony` — le verdict d'un arbitre que tu écris, l'intention dans un journal signé, la tête du journal sortie de la machine par un témoin, et seulement ensuite la passphrase de séquestre. Il ne rend que ce compartiment, jamais le coffre privé, et il n'est pas le mode Hybrid : rien n'y est déchiffré au fil de l'eau. Le séquestre d'un coffre archivé part avec l'archive, et l'administrateur le rouvre de la même façon (`readArchiveEscrow()`).

Ce que le séquestre tient :
- **aucun arbitre n'est livré** : `EscrowArbiter` rend un `Verdict` nommé, et seuls `Open` et `OpenWithConsent` descellent. Un arbitre écrit pour tous les déploiements répondrait oui à tous ; SelfRecover n'en livre pas non plus : un arbitre peut lire si sa fenêtre d'après un niveau 3 permet la lecture du séquestre (`Escalade::fenetre()`, `lecture_admin_permise`). La classe de l'arbitre, son verdict, celle du témoin et celle du gardien vont au journal. `HeadWitness` est obligatoire : il rend la dernière tête qu'il a prise, et la cérémonie vérifie le journal contre elle avant d'écrire quoi que ce soit — un journal coupé ou réécrit depuis l'arrête. `NoHeadWitness` dit par son nom que la tête reste sur cette machine. Un témoin passe `bin/witness-conformance.php` avant de servir (depuis la 0.10.0) : une réserve qui prend une tête de quiconque peut y écrire laisse une seule tête forgée fermer toutes les cérémonies. Un arbitre qui lève une exception répond `Unavailable`. La cérémonie nomme les champs qu'elle lit (`unlock <utilisateur> <dossier> <champ>…`), l'arbitre les reçoit, et rien d'autre ne s'ouvre (depuis la 0.10.0) ; `compare <utilisateur> <dossier> <champ>…` dit, champ par champ, si le séquestre tient une valeur que l'exploitant tape, sans la rendre — « concorde », « ne concorde pas sous la normalisation *n* » ou « indisponible », les mots mêmes de l'outil —, et chaque comparaison est une lecture, sous le même arbitre, le même délai et la même objection. Cela réduit ce que voit un exploitant honnête ; cela n'arrête pas qui détient la clé admin, qui ouvre la boîte hors de l'outil. Sans gardien, contre l'administrateur seul — le fichier de clé scellée et sa passphrase —, rien de tout cela ne tient : il ouvre le séquestre hors de la bibliothèque ;
- **un gardien, quand le déploiement en tient un** (`EscrowKeeper`, depuis la 0.9.0) : le séquestre lui est scellé de nouveau, et l'administrateur seul ne l'ouvre plus. Le gardien rend la boîte intérieure après avoir inscrit la remise, et signe pour le titulaire ce qu'il a remis : `escrowReads($session)`, à l'ouverture du coffre, rend les remises, ou « non établi » avec son motif — jamais une liste vide pour une attestation qui n'est pas arrivée. Chaque dépôt tire une clé de séquestre neuve et scelle une boîte à lui : une clé obtenue par une cérémonie, ou gardée d'une copie de la base, n'ouvre rien de ce qui est déposé après, et une lecture annoncée ne rend que la boîte qu'elle a annoncée (depuis la 0.10.0) — un dépôt fait depuis, la boîte d'une archive ou celle d'une copie attend son propre délai, et le titulaire la voit en attente. La vue nomme la boîte de chaque remise et de chaque annonce, et celle que tient la ligne (`box`). Un délai, réglé par le gardien, change toute lecture — d'archive comprise — en annonce que le titulaire voit. Lui seul peut s'y opposer (`objectToEscrowRead()`), avant comme après un dépôt : l'objection est signée depuis la session sous la clé d'objection du séquestre, dérivée de la clé maîtresse, dont l'escrowId est l'empreinte — une ligne de séquestre remise d'une sauvegarde, réécrite ou supprimée n'ôte au titulaire ni l'objection ni la vue (`escrow-replaced`). Le titulaire peut aussi consentir à une lecture (`consentToEscrowRead()`, depuis la 0.10.0) : l'accord est signé depuis la session sous la même clé, pour le gardien auquel le séquestre répond, et inscrit chez lui ; un arbitre qui répond `Verdict::OpenWithConsent` ne laisse la cérémonie lire qu'une fois cet accord attesté par ce gardien — une lecture à laquelle le titulaire ne consent pas ne passe pas par la cérémonie, et un séquestre scellé à aucun gardien ne s'ouvre pas sous ce verdict. Un accord ne lève aucune objection. Le délai est celui du gardien, pas de la bibliothèque : qui joint le gardien hors de la cérémonie l'attend aussi, et le titulaire voit ce que le gardien a remis. La réserve du gardien vit hors de la machine qui sert. SelfDataGuard n'en livre aucun : `KeeperSeal` donne ses formats, `bin/keeper-conformance.php` joue son contrat. Donné, `new SelfDataGuard($storage, $blindKey, keeper: $keeper)` l'inscrit dans la base, et une façade sans lui, ou avec un autre, est refusée — la cérémonie aussi. Un gardien que le déploiement remplace (`SelfDataGuard::changeKeeper()`) reste inscrit dans la base comme ancien gardien, et leur est donné dès lors : chaque dépôt lui retire le séquestre, et un dépôt sans lui est refusé ; un gardien remplacé n'est jamais repris, et celui qui a disparu pour de bon sort du registre par `SelfDataGuard::forgetFormerKeeper()`, l'exploitant cessant de le donner aux façades comme à la cérémonie. Un séquestre scellé avant reste lisible par l'administrateur seul jusqu'à la passe de l'exploitant (`bin/escrow-ceremony.php seal-to-keeper`, sans aucun secret en main) ou au prochain dépôt de son titulaire (`sealEscrowToKeeper()`) ;
- **le scellé administrateur nomme son compte** : recopié dans la ligne d'un autre compte, il est refusé. Il n'authentifie pas pour autant le contenu du séquestre : qui écrit en base peut, avec la clé publique, sceller et remplir un compartiment neuf. Un scellé d'avant la 0.6.0 s'ouvre encore ; `rebindEscrowAdmin($session, $clePublique)` le rescelle, vers la clé que tu lui passes. Depuis la 0.9.0, chaque dépôt rescelle le compartiment : la base inscrit la clé publique admin au premier dépôt, et un dépôt ou un rescellement fait avec une autre est refusé — il prendrait le compartiment à l'administrateur qui détient celle inscrite ;
- **la passphrase admin compte au moins 12 octets** au scellement (`UserVault::PASSWORD_MIN_LEN`) ; une clé scellée plus tôt sous une passphrase plus courte s'ouvre encore. La clé scellée porte son profil Argon2id (`v2:…`) ;
- **le journal de la cérémonie peut s'ancrer hors de la machine** : `bin/escrow-ceremony.php verify-log` affiche sa tête `seq:hmac`, que tu notes ailleurs, et `verify-log --ancre seq:hmac` refuse ensuite un journal tronqué avant l'ancre ou réécrit jusqu'à elle. Sans ancre, la vérification ne voit pas qu'on a coupé la fin du journal. Le secret du journal vit sur la machine de la cérémonie, et qui le détient peut réécrire ce qui suit la dernière ancre : le témoin sort la tête à chaque cérémonie. Des lignes qui n'ont pas la forme d'une entrée — une écriture coupée — sont mises en quarantaine par l'entrée suivante, qui les consigne ; une entrée dont la signature ne tient pas, ou une entrée signée hors de sa place, est une rupture sur laquelle rien ne s'écrit. Depuis la 0.10.0, le journal peut tourner (`new AuditLog($path, $secret, rotateAfter: n, mode: 0o640)`, ou `DATAGUARD_AUDIT_ROTATE_AFTER` et `DATAGUARD_AUDIT_MODE` pour l'outil) : quand son fichier tient `n` entrées, la suivante le renomme `journal.1.log`, `journal.2.log`, … — numérotés une fois, le numéro avant l'extension, rien d'effacé — et ouvre un fichier neuf par une entrée chaînée sur la dernière de l'archive, `seq` continuant. `verify-log` sans ancre parcourt chaque fichier et chaque liaison (`verifyAll()`). Range le journal, ses archives et son fichier `.lock` hors de tout dossier qu'un serveur web sert, et garde au moins la dernière archive : une ancre prise juste avant une rotation s'y relit ;
- **le nom de champ `escrow` est réservé** : c'est le contexte de l'enveloppe du séquestre. `setFields()` le refuse ; `getFields()` le refuse quand on le demande par son nom, et le laisse de côté, sans le déchiffrer, dans une lecture de tous les champs.

---

## Modèle de menace en un coup d'œil

| Adversaire | Sans SelfDataGuard | Avec SelfDataGuard |
|------------|---------------------|---------------------|
| SQL injection / IDOR / dump DB | Données personnelles en clair exposées | Valeurs chiffrées. Lisibles : noms des champs, dates de modification, longueurs, comptes qui partagent une valeur d'un champ déclaré partagé |
| Base volée sans le fichier de poivre | Données personnelles en clair exposées | Les serrures « mot de passe » et « mot mémorisé » sont sous le poivre : sans son fichier, aucun mot de passe ni aucun mot ne s'essaie hors ligne. **Limite** : la serrure « passphrase » n'y est pas — tirée par SelfRecover, une passphrase tient environ 77,5 bits ; choisie par son titulaire, ce que tient un mot de passe. Un fichier de poivre écrit à la main, et non tiré par `bin/pepper.php new`, se force avec la seule base, au prix d'un HMAC par candidat, contre le témoin de la base et contre chaque couche |
| Bande de sauvegarde volée | Données personnelles en clair exposées | Idem, tant que la sauvegarde ne contient pas la clé du blind index (un secret du serveur, commun à tous les comptes). Avec elle, les valeurs indexées qui s'énumèrent (numéros de téléphone, adresses e-mail) se retrouvent par dictionnaire, pour tous les comptes |
| DBA malveillant | Lit tout | Comme pour un dump : valeurs chiffrées, rien à déballer sans un des secrets d'un utilisateur ; noms des champs, dates de modification et longueurs lisibles |
| Compromission root applicative (RCE) | Lit tout | En mode Lite, le seul en service : lit les coffres des sessions ouvertes pendant la compromission, et garde leurs clés, qui ne tournent pas ensuite. Avec la clé du blind index, retrouve par dictionnaire les valeurs indexées qui s'énumèrent, pour tous les comptes |
| Endpoint utilisateur compromis (keylogger) | Identifiants utilisateur capturés | Identifiants capturés → données de cet utilisateur uniquement (pas de fan-out) |
| Papier de passphrase volé | Accès au compte par le niveau 1 | Le même, plus les données de cet utilisateur — hors ligne aussi, avec un dump. La passphrase est consommée à sa première utilisation légitime, qui la remplace |
| Coercition d'un admin pour déchiffrer | Toutes les données à la discrétion de l'admin | Les coffres privés n'ont pas de clé admin : il faudrait un secret de chaque utilisateur. Le séquestre en a une, permanente : qui contraint le détenteur de sa passphrase obtient, cérémonie après cérémonie, le compartiment de séquestre de chaque compte qui en a un — jamais le coffre privé. Sous un gardien, seulement ce que le gardien remet, et chaque remise est montrée à son titulaire |
| Administrateur seul, avec la base | Lit tout | Sans gardien, ouvre le séquestre hors de la bibliothèque, et personne ne le voit. Sous un gardien, n'ouvre aucun séquestre qui lui est scellé ; peut encore faire faire une lecture sous un dossier qu'il ouvre lui-même — le gardien l'inscrit, et le titulaire la voit. Il choisit le gardien : un déploiement qui veut être cru le nomme, et le titulaire voit son empreinte |
| Clé du blind index perdue, ou remplacée par une neuve | — | **Limite.** La base garde un témoin de sa clé : une façade qui en apporte une autre est refusée, et le refus le dit ; une base écrite avant la 0.7.0 n'adopte sa clé que sur preuve (`adoptBlindKey()`), ou sur la parole de l'exploitant quand aucun champ ne porte d'index. Chaque sceau et chaque index sont calculés sous cette clé : tant qu'elle n'est pas revenue, rien ne s'ouvre. Garde-la avec les sauvegardes de la base |
| Un tiers qui connaît un nom | Apprend si le compte existe | Plus par le temps de réponse, depuis la 0.10.0 : un nom inconnu paie la dérivation que paie un secret faux. Pas les serrures qu'il porte (whitepaper, §8.1) |
| Une copie de la base, ou la remise d'une ancienne ligne de coffre | — | **Limite.** Aucune écriture n'est nécessaire : une copie de la base et un secret que son titulaire a remplacé depuis — passphrase consommée comprise — ouvrent le coffre tel que la copie le tient, et une ligne restaurée rouvre avec les secrets qu'elle portait. Rien dans le coffre ne distingue une révision restaurée : la parade est l'intégrité de la base et de ses sauvegardes (whitepaper, §8.1) |
| Poivre perdu | — | **Limite.** Tout coffre sans serrure « passphrase » se ferme pour de bon. Garde une copie du fichier de poivre hors du serveur, et hors des sauvegardes de la base |
| Écriture en base : le titulaire empêché de s'opposer | — | **Limite.** La porte d'objection s'ouvre hors du sceau : un profil réécrit ou un sceau retiré n'empêche plus le titulaire de s'opposer. Une enveloppe réécrite ferme sa serrure, et le sel du coffre réécrit ferme les trois : entre-temps, le titulaire ne peut pas s'opposer à une lecture annoncée, qui passe une fois son délai échu. L'exploitant remet la ligne depuis une sauvegarde |
| Code modifié sur la machine qui sert | Lit tout | **Limite.** Le frein, la porte d'objection et la vérification d'un accord (`Verdict::OpenWithConsent`) tournent sur le serveur : ils tiennent contre qui écrit la base, pas contre qui change le code qu'il sert. Sous un gardien, une lecture attend encore le délai du gardien, et le titulaire la voit annoncée |

---

## Statut

**v0.10.0 — un poivre gardé hors de la base sous les serrures « mot de passe » et « mot mémorisé », un frein que la bibliothèque exige, une objection qui se passe de session, un gardien dont l'annonce tient sa boîte et qui inscrit l'accord de son titulaire, une clé administrateur que l'exploitant inscrit, et un journal d'audit qui tourne**, 24 novembre 2026, sur la **v0.9.1 — le retour du titulaire après un niveau 3 ne se ferme plus**, 20 novembre 2026, la **v0.9.0 — un séquestre que l'administrateur seul n'ouvre plus : un gardien en tient la seconde clé, et son titulaire voit chaque lecture**, 6 novembre 2026, et la **v0.8.0 — un coffre archivé revient en service, un coffre se gèle en lecture seule, et aucune écriture décidée par une récupération ne passe sans son ticket**, 29 octobre 2026.

Whitepaper complet (spécification + modèle de menace). Bibliothèque PHP de référence implémentée (15 502 lignes réparties sur 78 fichiers, comptées le 24 novembre 2026 ; PSR-4, PHP 8.1+, libsodium). Primitives cryptographiques (Argon2id, HMAC-SHA256, XChaCha20-Poly1305, et AES-256-GCM pour relire les blobs écrits avant la 0.4.0) couvertes par **1 401 contrôles répartis sur 18 suites**, comptés en les lançant sous PHP 8.4, le 24 novembre 2026. La CI lance les mêmes suites sous PHP 8.1 et 8.2, où quatre contrôles de trace deviennent des limites déclarées. Les clés des trois serrures sont vérifiées contre des vecteurs figés, recalculés en CI par une seconde implémentation (argon2-cffi), et la forme du poivre l'est de même, par une seconde implémentation en Python. Une démo HTML cliquable est incluse pour inspecter la base chiffrée en temps réel.

Une base 0.9.x est montée par l'exploitant avant qu'une façade de la 0.10.0 la serve (*Monter une base 0.9.x*, plus bas). Une base 0.5.x se migre en place à sa première ouverture par la 0.6.0 (colonnes `kdf_opslimit` et `kdf_memlimit`, au profil d'avant), une base 0.4.0 aussi, en une fois. ⚠️ Un retour à la 0.5.x garde l'accès aux coffres et au séquestre côté titulaire, mais l'administrateur n'ouvre plus un séquestre que la 0.6.0 a créé ou rescellé, et la 0.5.x ne descelle pas une clé admin générée par la 0.6.0 : [CHANGELOG](./CHANGELOG.md). Les blobs écrits par la 0.3.0 restent lisibles, par OpenSSL (`ext-openssl`) là où libsodium refuse AES.

Le module tourne sur des déploiements réels. Il **n'a pas été audité par un cryptographe extérieur** : sa conception n'est vérifiée à ce jour que par son auteur et par les lecteurs de ce dépôt.

Les revues sont recherchées, sur la conception comme sur l'implémentation — les chercheurs en sécurité disposent d'une cible exécutable plutôt que d'une spécification à contester. Les intégrateurs en aval, en particulier les utilisateurs de SelfRecover, lisent le modèle de menace avant de le brancher.

Un audit cryptographique communautaire formel est prévu avant la v1.0.0. Soumission ANSSI Visa de sécurité prévue au même jalon.

---

## Démarrage rapide

### Lancer la démo standalone (zéro install)

```bash
# depuis la racine du dépôt
demo/selfdataguard/run.sh
# ouvrir http://127.0.0.1:8081 dans un navigateur
```

La démo permet d'inscrire un utilisateur, se connecter, changer de mot de passe, et inspecter la base SQLite brute en parallèle — démontrant que les champs personnels (email, tél, IBAN, adresse) ne sont jamais lisibles sur disque.

### Utiliser la bibliothèque dans ton app

```php
use Pierroons\SelfDataGuard\Pepper\LocalPepperFile;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\PepperForms;

require 'vendor/autoload.php';

$dg = new SelfDataGuard(
    storage:  new SqliteAdapter('sqlite:/chemin/vers/db.sqlite'),
    blindKey: file_get_contents('/chemin/vers/secret-serveur.bin'),  // ≥32 octets
    pepper:   new LocalPepperFile('/chemin/vers/poivre'),            // tiré par : php bin/pepper.php new /chemin/vers/poivre
    forms:    PepperForms::LayeredOnly,                              // une base neuve ; AcceptBare pendant la montée d'une 0.9.x
    throttle: $throttle,                                             // ton Throttle, ou new NoThrottle() pour dire qu'il n'y en a pas
);

// Nouvel utilisateur : mot de passe, mot mémorisé (son empreinte), passphrase
$session = $dg->register('alice', 'correct horse battery staple', $empreinte, $passphrase);
$dg->setFields($session, ['email' => 'a@b.c', 'iban' => 'FR76...'], indexed: ['email']);

// Connexion classique
$session = $dg->loginWithPassword('alice', 'correct horse battery staple');
$fields  = $dg->getFields($session);  // ['email' => 'a@b.c', 'iban' => 'FR76...']

// Après une récupération SelfRecover de niveau 1 acceptée
$session = $dg->recover('alice', Lock::Passphrase, $anciennePassphrase, $nouveauMdp, $nouvellePassphrase);

// Niveau 3 : l'ancien coffre est archivé, un neuf est créé
['unlocked' => $session, 'archiveId' => $id] = $dg->reEnroll($dg->generationOf('alice'), $mdp, $empreinte, $passphrase);

// Recherche par champ indexé, sans déchiffrer aucun row
$userId = $dg->findUserByField('email', 'a@b.c');  // 'alice' ou null
```

Trois classes principales exposées : `SelfDataGuard` (façade), `SqliteAdapter` (stockage ; implémente `StorageInterface` pour MariaDB / Postgres), `Primitives` (crypto brute si tu veux bâtir au-dessus). Une implémentation de `StorageInterface` à toi est conforme quand `php bin/storage-conformance.php <connexion.php> <transaction-appelant.php> <ligne-illisible.php>` passe contre elle, et pas autrement — sans les deux derniers fichiers, les cas qui en ont besoin sont sautés, et un cas sauté ne passe pas : un stockage peut respecter chaque signature et perdre un coffre sans un mot. Chaque échec a son type d'exception : `WrongSecretException` (et `MissingEnvelopeException`, au même message : une serrure absente répond comme un secret faux), `VaultTamperedException` (un profil ou une enveloppe que le sceau ne couvre pas tels quels, ou un sceau absent), `VaultNotFoundException`, `StaleVaultException` (une session ouverte sur un coffre remplacé depuis), `DuplicateIndexException`, `AmbiguousLookupException`.

**Le poivre** (depuis la 0.10.0) est un secret du serveur gardé hors de la base. Les serrures « mot de passe » et « mot mémorisé » sont scellées sous lui en plus du secret de l'utilisateur : une base volée, seule, ne permet plus d'essayer hors ligne ni un mot de passe ni un mot. La serrure « passphrase » n'est pas sous lui. `php bin/pepper.php new <fichier>` le tire — 32 octets aléatoires, mode 0600, jamais par-dessus un fichier existant. `LocalPepperFile` refuse un fichier absent, illisible, qui n'est pas un fichier ordinaire, placé sous la racine que sert le serveur web, lisible par les autres ou modifiable par son groupe, mal formé, ou qui compte moins de 16 octets distincts. La base garde un témoin du poivre, et une façade qui en reçoit un autre est refusée, le refus nommant les deux. `PepperSource` est l'interface : une source distante rend le calcul pour un usage, jamais le poivre, et quand elle ne répond pas il n'y a pas de repli. Garde une copie du poivre hors du serveur, et hors des sauvegardes de la base : perdu, il ferme tout coffre qui n'a pas de serrure « passphrase ».

**Le frein** (depuis la 0.10.0) est obligatoire. À chaque porte où un secret venu de l'extérieur coûte un Argon2id — `loginWithPassword()`, `loginWithMemorized()`, `recover()`, `openArchive()`, `openForObjection()`, `pinArchiveEscrow()`, `setLockWhileFrozen()` —, la façade le consulte avant de lire quoi que ce soit (`Throttle::admit()`), et lui dit comment chaque tentative admise s'est terminée (`settle()`). La bibliothèque ne livre aucune politique : implémente `Throttle` sur ta propre réserve, ou passe `new NoThrottle()`, qui dit par son nom qu'il n'y en a pas. Un frein par compte seul ferme la porte à son titulaire : freine aussi par adresse. Un compte inconnu paie la dérivation que paie un secret faux, si bien que le temps de réponse ne dit plus si un nom a un coffre. `UserVault::unlock()`, public et sans état, contourne le frein comme il contourne le sceau : appelle la façade.

**Le secret mémorisé ne se scelle que sous la forme de l'empreinte que dérive SelfRecover** — 64 caractères hexadécimaux en minuscules (depuis la 0.10.0). `register()`, `setLock()`, `recover()` et `reEnroll()` refusent tout le reste, le mot lui-même ou l'empreinte en capitales ; l'ouverture ne refuse rien, si bien qu'un coffre scellé auparavant sur une autre chaîne s'ouvre encore avec elle. `lockState($user, $serrure)` donne la forme d'une serrure — native, en couche, nue, absente ou illisible — sans aucun Argon2id.

**Sous un gel**, `setLockWhileFrozen($user, $motMemorise, $serrure, $secret)` rescelle une serrure à partir du mot mémorisé actuel, derrière le frein ; toute autre écriture reste refusée jusqu'à `unfreeze()`. Un titulaire qui a oublié le mot mémorisé ne rescelle rien d'ici là : le mot de passe ouvre encore le coffre, et le niveau 2 de SelfRecover ne suit pas.

### Monter une base 0.9.x

Une façade de la 0.10.0 refuse une base qui tient des coffres et n'a jamais inscrit son poivre. La montée revient à l'exploitant, sans aucun secret des utilisateurs en main, pendant que rien d'autre n'écrit dans la base :

1. Tire le poivre — `php bin/pepper.php new <fichier>` — et garde-en une copie hors du serveur.
2. Lance `php bin/pepper.php adopt <fichier>`, avec `DATAGUARD_DB` et `DATAGUARD_BLINDKEY_PATH` qui nomment la base et la clé du blind index qu'elle a inscrite. Il pose une couche sous le poivre sur chaque serrure « mot de passe » et « mot mémorisé », coffres en service comme archives, et inscrit le témoin du poivre en dernier. Relancé, il ne pose que ce qui est encore nu. Un coffre gelé est sauté et nommé : relance-le une fois la fenêtre close.
3. Sers sous `PepperForms::AcceptBare` : une serrure nue s'ouvre encore, et tout nouveau sceau prend la forme en couche.
4. `php bin/pepper.php status <fichier>` compte les formes, et rend 0 une fois que plus rien n'est nu.
5. Sers sous `PepperForms::LayeredOnly` : tout nouveau sceau est natif — le poivre à l'intérieur de la dérivation —, et une serrure nue remise depuis une copie est refusée après sa dérivation, au prix d'un secret faux. Une serrure « mot mémorisé » encore en couche a été scellée sur une chaîne qui n'est pas l'empreinte canonique : son titulaire redéfinit le mot.

**Le retour à la 0.9.x** est possible jusqu'à l'étape 5 : `php bin/pepper.php unlayer <fichier>` retire chaque couche. Une serrure native n'a pas de couche sous elle : son coffre est nommé, et reste fermé à la 0.9.x. Tant que `unlayer` n'a pas tourné, la 0.9.x n'ouvre aucune serrure sous le poivre : ferme ta voie de récupération pendant que tu sers la 0.9.x.

Ensuite, avant le premier dépôt, `php bin/escrow-ceremony.php adopt-admin` inscrit la clé de l'administrateur dans la base, sur preuve de sa passphrase : un dépôt ne l'inscrit plus, et une base qui ne l'a pas refuse tout dépôt. Une implémentation de `StorageInterface` à toi ajoute `accountIds()`, `replaceArchiveVault()`, l'écriture étroite sous un gel, et le libellé de la clé admin du séquestre : `bin/storage-conformance.php` les joue.

### Les outils de `bin/` et leurs codes de sortie

| Outil | Codes de sortie |
|---|---|
| `pepper.php new\|adopt\|status\|unlayer <fichier>` | 0 fait (`status` : rien de nu) · 2 refusé, ou il reste à poser (`status` : des serrures nues) · 3 usage, configuration, ou un fichier qui ne s'écrit pas |
| `escrow-ceremony.php <commande>` | 0 fait (`compare` : chaque champ concorde) · 1 journal rompu · 2 refusé · 3 configuration · 4 lecture annoncée, à relancer une fois son délai échu · 5 `compare` : un champ ne concorde pas ou est indisponible ; `reseal-admin` : passe faite, un séquestre laissé sous l'ancienne clé |
| `storage-conformance.php <connexion.php> <transaction-appelant.php> <ligne-illisible.php>` | 0 conforme · 1 un cas a échoué, ou a été sauté faute d'un fichier · 3 un fichier qui ne se charge pas |
| `keeper-conformance.php <fabrique.php>` | 0 conforme · 1 un cas a échoué · 3 un fichier qui ne rend pas de fabrique |
| `witness-conformance.php <fabrique.php>` | 0 conforme · 1 un cas a échoué · 3 un fichier qui ne rend pas de fabrique, ou une fabrique qui ne rend pas un témoin et son faussaire · 4 rien à éprouver : `NoHeadWitness` ne prend aucune tête |

> ⚠️ **Après la montée d'une base en 0.7.0, avant de servir** : appelle `sealProfile($userId)` une fois pour chaque compte. Un coffre écrit avant la 0.7.0 n'a pas de sceau de profil, et ne s'ouvre pour personne tant qu'il n'est pas scellé — comme un coffre altéré ; un niveau 3 (`reEnroll()`) le refuse de la même façon. Ensuite, n'indexe une valeur qu'une fois que le compte a prouvé qu'il la détient : qui l'indexe le premier la garde.

---

## Tests

Dix-huit suites de tests sanity, exécutables directement avec `php` (pas besoin de PHPUnit) :

```bash
php tests/sanity_primitives.php   # 56 tests — Argon2id, HMAC, XChaCha20-Poly1305 + vecteur IETF, AES-GCM historique, aléatoire, AAD
php tests/sanity_vault.php        # 64 tests — trois serrures, rotation, séparation des contextes, liaison AAD, génération, bornes du profil, serrure absente
php tests/sanity_fields.php       # 26 tests — chiffrement de champs + blind index
php tests/sanity_storage.php      # 77 tests — adaptateur SQLite, transactions imbriquées, écriture conditionnelle (génération, révision), révisions réécrites dans un autre type, comptes listés page par page, test "soupe DB", délai de verrou et mode de journal
php tests/sanity_migration.php    # 46 tests — la clé adoptée sur preuve, bases 0.4.0, 0.5.x et 0.8.0 migrées en place, index aveugle unique, profil scellé par l'exploitant
php tests/sanity_archive.php      # 121 tests — un paquet qui ne se lit plus, une ligne de séquestre qui revient entière, archive : contenu, cloisonnement, tout-ou-rien, écrivain concurrent attendu, une archive remise en service, les valeurs qu'une archive garde contre les autres comptes, une réservation qui finit avec la fenêtre ou le gel, une valeur tenue par deux comptes revenue avec sa trace, un séquestre gardé sous toute forme illisible, un coffre gelé, un effacement refusé sous un gel, un ticket appliqué une fois, ce que l'effacement garde et que l'exploitant efface
php tests/sanity_facade.php       # 199 tests — une base lue sans écriture, API complète bout en bout, recover(), niveau 3 sous le sceau, sceau du profil et des enveloppes, réparation, clé et champs partagés de la base, index unique, course à l'écriture, un nom qui garde ses archives, reinstateArchive(), freeze(), restoreArchive(), tickets de récupération, une archive au séquestre illisible — ligne, enveloppe ou champ — lue et objectée, un stockage qui efface sous gel refusé par la façade, les champs nommés revenus sans leur index, ce que l'effacement garde sous un niveau 3 et refuse sous un gel, le retour après un effacement, une serrure rescellée sous un gel depuis le mot mémorisé
php tests/sanity_conformance.php  # 123 tests — la suite de conformité du stockage, contre l'adaptateur fourni et un stockage fautif par clause, chaque course jouée dix fois
php tests/sanity_keeper.php       # 263 tests — le gardien : double sceau, une clé neuve à chaque dépôt, remises signées pour le titulaire, délai et objection pour toute lecture, une annonce qui tient sa boîte, l'accord du titulaire, un escrowId calculé sur la clé d'objection, une objection sans session, le séquestre d'une archive épinglé par son titulaire, une tête de journal inscrite sans être jugée, un gardien en panne, un gardien remplacé et retiré, un retrait qui nomme son gardien, la passphrase vérifiée avant le gardien, un dépôt entre deux lectures, un gardien à fichier sous huit processus, un ancien gardien connu de la façade quoi que dise le registre, un gardien muet qui ne cache que lui-même, une ligne qui ne s'ouvre pas, une panne du stockage distinguée d'une ligne illisible, un ancien gardien oublié, la suite de conformité du gardien contre des gardiens fautifs
php tests/sanity_keys.php         # 30 tests — aucun objet qui tient une clé n'a d'accesseur pour elle
php tests/sanity_audit.php        # 64 tests — journal d'audit, ancre contre la troncature, forcé sur disque, quarantaine de ce que laisse une panne, rotation : archives, liaisons, une archive absente ou étrangère, écrivains simultanés
php tests/sanity_ceremony.php     # 69 tests — CLI de cérémonie, arbitre, témoin et gardien de l'exploitant, témoin amorcé, archives, les champs nommés, comparaison sans divulgation, options de verify-log et un journal qui tourne, une lecture annoncée, la passe de l'exploitant, qui continue après un compte en échec, les fichiers des anciens gardiens
php tests/sanity_escrow.php       # 119 tests — un témoin qui a oublié ses têtes, compartiment escrow, premiers dépôts simultanés, clé admin v2, scellé lié au compte, la clé admin inscrite par l'exploitant, son libellé vérifié avant le gardien, un changement de clé admin et sa passe, deux rescellements d'une même lecture, ordre de la cérémonie, journal vérifié contre le témoin
php tests/sanity_vecteurs.php     # 9 tests — clés des trois serrures face aux vecteurs figés
php tests/sanity_witness.php      # 19 tests — la suite de conformité du témoin de tête, chaque témoin fautif échouant sur son devoir, et ses codes de sortie
php tests/sanity_pepper.php       # 43 tests — la forme du poivre face à une seconde implémentation, le fichier de poivre et ses refus, usages et sources, un poivre tiré et jamais écrasé
php tests/sanity_upgrade.php      # 29 tests — le poivre à l'intérieur de la dérivation, le secret mémorisé scellé sous sa seule empreinte, la passe de l'exploitant depuis la 0.9.x, son statut, le retour à la 0.9.x, la passe qui refuse ce qui fermerait les serrures
php tests/sanity_throttle.php     # 44 tests — le frein obligatoire, admis avant toute lecture et soldé une fois, un compte inconnu au prix d'un secret faux
# Total : 1 401 tests, 0 échec — relevé par exécution le 24/11/2026
python3 tests/vecteurs_argon2.py  # recalcule les vecteurs par argon2-cffi, seconde implémentation
python3 tests/vecteurs_poivre.py  # recalcule les vecteurs du poivre, seconde implémentation
```

La suite `sanity_storage.php` inclut un "BIG TEST" qui dumpe le fichier SQLite et vérifie qu'aucune donnée personnelle en clair n'apparaît nulle part dans le blob binaire. Côté SelfRecover, `bi-self/selfrecover/tests/sanity_parcours_dataguard.php` fait traverser à un coffre chaque récupération, sur les vrais chemins des deux bibliothèques.

---

## Documentation

- [Modèle de menace](./docs/threat-model.md) — où entrent les données non sûres, ce qui compte le plus, comment jouer les gardes, comment un constat se classe (en anglais)
- [Whitepaper FR (spécification complète)](./docs/whitepaper-fr.md)
- [Whitepaper EN (full specification)](./docs/whitepaper-en.md)
- [Walkthrough de la démo](../../demo/selfdataguard/README.md)

---

## Licence

**AGPL-3.0-or-later**. Voir [LICENSE](../../LICENSE).

Si tu modifies SelfDataGuard et que tu offres ta version à des utilisateurs à travers un réseau, tu dois leur donner accès à son code source, sous la même licence (AGPL-3.0, article 13).
