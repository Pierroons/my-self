# SelfDataGuard

> 🇬🇧 **[Read in English →](./README.md)**

**Protection des données au repos côté application, qui survit à une exfiltration de base de données.**

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../../LICENSE)
[![Statut : v0.5.1 disponible](https://img.shields.io/badge/statut-v0.5.1%20disponible-brightgreen.svg)](#statut)
[![Tests : 319 passants](https://img.shields.io/badge/tests-319%20passants-brightgreen.svg)](#tests)
[![Pilier : Self-Security](https://img.shields.io/badge/pilier-Self--Security-blue.svg)](../README.fr.md)
[![Compagnon : SelfRecover](https://img.shields.io/badge/compagnon-SelfRecover-green.svg)](../../bi-self/selfrecover/README.fr.md)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

> **Dump ma base de données — et tu obtiens du bruit chiffré.**

---

## Le problème

Tous les produits actuels de chiffrement des données au repos (MySQL TDE, MongoDB CSFLE, AWS RDS encryption) répondent au même modèle de menace : **l'attaquant a le disque, mais pas l'application**. La clé de chiffrement se trouve à côté des données — dans un fichier de configuration, une variable d'environnement, ou un service de gestion de clés que l'application peut lire.

Ce modèle s'effondre dès que **le serveur d'application est compromis**. L'attaquant exfiltre la base de données ET la clé — le chiffrement n'était qu'une case cochée, pas une défense. Les fuites récentes à grande échelle ont montré la même chose à chaque fois : ce sont les données personnelles exposées en clair qui constituent le coût dominant de l'incident, parce qu'elles ne se révoquent pas.

Les outils actuels soit ignorent complètement le chiffrement au repos, soit l'implémentent d'une manière qui n'apporte aucune valeur contre une compromission côté serveur. SelfDataGuard choisit une troisième voie : **dériver la clé de chiffrement d'un secret connu uniquement de l'utilisateur**, de sorte qu'un dump de base ne donne que de la soupe cryptographique.

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

Argon2id prend un sel de 16 octets : les 16 premiers octets de ce SHA-256. Les deux contextes diffèrent : la même chaîne posée comme mot mémorisé et comme passphrase donne deux clés sans rapport. Les trois clés d'encapsulage coûtent autant, et c'est voulu : des enveloppes ne valent que la moins chère à ouvrir.

Chaque utilisateur dispose de :

- Un `user_salt` aléatoire unique, stocké en clair (équivalent à un identifiant)
- Un `data_master_key_pwd_wrap` : ciphertext XChaCha20-Poly1305 de la clé maîtresse, chiffré avec la clé dérivée du mot de passe
- Un `data_master_key_recov_wrap` : le même, avec la clé dérivée du mot mémorisé (facultatif)
- Un `data_master_key_phrase_wrap` : le même, avec la clé dérivée de la passphrase (facultatif)
- Des champs de données personnelles chiffrés un par un avec `data_master_key`

**Dump de la base → soupe cryptographique.** Aucune combinaison des valeurs en clair présentes dans le dump ne permet d'obtenir la clé maîtresse. L'attaquant aurait besoin d'un des secrets de l'utilisateur — mot de passe, mot mémorisé ou passphrase, chacun durci par Argon2id et isolé par sel — pour déchiffrer quoi que ce soit.

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
| Niveau 2 — appareil enrôlé | une signature, rien qui ouvre le coffre | au prochain secret donné : `recover($user, $serrure, $secret, $motDePasseActuel)`, freiné comme la connexion |
| Niveau 3 — escalade humaine | aucun ancien secret | `reEnroll($user, $mdp, $empreinte, $passphrase)` : l'ancien coffre est **archivé** |

`recover()` ouvre et re-scelle en une seule écriture conditionnelle. Le même appel rattrape un coffre dont l'enveloppe du mot de passe a pris du retard. Appelé seul, c'est un oracle Argon2id sans frein : appelle-le juste après une récupération acceptée par SelfRecover, derrière ses compteurs, et freine le rattrapage du niveau 2 par appareil comme la connexion.

**Au niveau 3, l'ancien coffre est archivé.** `reEnroll()` le met de côté, scellé sous ses anciennes serrures, sans péremption, et crée un coffre neuf. Si l'utilisateur retrouve plus tard une ancienne serrure, `openArchive($session, $id, Lock::Passphrase, $ancienne)` puis `readArchive()` lui rendent ses données. La session doit être celle du coffre actuel : par le service, une ancienne serrure seule n'ouvre rien, parce que ces secrets sont justement ceux qui ont pu fuir. Avec un dump de la base, elle ouvre l'archive hors ligne. Une archive ne se détruit que par `deleteArchive()` ou `purgeArchives()`, et `delete()` la laisse.

> ⚠️ **Un coffre scellé par le seul mot de passe ne survit pas à une récupération SelfRecover.** Les niveaux 1 et 2 remplacent le mot de passe du compte (`Recovery::parPassphrase()`, `Recovery::parCode()`). Un coffre créé par `register($user, $password)` seul n'a qu'une enveloppe, scellée sur l'ancien mot de passe : après la récupération, plus personne ne l'ouvre, et aucune des deux bibliothèques ne le dit. Quand les deux modules partagent un compte :
> - passe `$memorized` et `$passphrase` à `register()` ;
> - après chaque récupération acceptée, appelle `recover()` comme dans le tableau ci-dessus ;
> - au niveau 3, appelle `reEnroll()`. La serrure « mot mémorisé » de l'archive dépend du sel SelfRecover de l'époque, que le niveau 3 remplace : range-le, par exemple comme champ du coffre neuf, si cette serrure doit rester utilisable.

Sans SelfRecover, SelfDataGuard fonctionne quand même, avec les serrures que l'application lui donne.

---

## Modes opérationnels — la v0.5.0 n'en implémente qu'un

| Mode | Accès serveur aux données | Compromis | Dans le code |
|------|---------------------------|-----------|--------------|
| **Lite** *(transparent pour les piles legacy)* | Le serveur déchiffre uniquement pendant les sessions utilisateur | Compromission serveur pendant une session active = fan-out limité (un utilisateur à la fois) | ✅ c'est ce que fait la bibliothèque |
| **Hybrid** *(visé pour l'e-commerce)* | Champs opérationnels (`email`, `adresse_livraison`) encapsulés avec une clé opérationnelle admin. Champs sensibles (`tel`, `doc_KYC`) nécessitent une session utilisateur | L'admin peut traiter les commandes ; les données sensibles restent zero-knowledge | ❌ spécifié, pas écrit — le `wrap_admin` du coffre vaut `null` (`UserVault::register()`) |
| **Full** *(zero-knowledge pour services à forte exigence)* | Le serveur ne déchiffre JAMAIS. Toute la crypto tourne dans le navigateur, par libsodium compilé en WebAssembly — WebCrypto n'offre ni Argon2id ni XChaCha20-Poly1305 | Certains workflows à redessiner (pas de mails transactionnels asynchrones, notifications push à la place) | ❌ spécifié, pas écrit — le module ne porte aucun code client |

Un déploiement qui installe la v0.5.0 est donc en **Lite**, quel que soit le mode visé : les deux autres sont décrits au whitepaper (§4.2, §4.3) comme une cible, et aucun paramètre de l'API ne les choisit.

Une voie administrateur existe pourtant, hors de ce tableau : le **séquestre** (`src/Escrow/`), compartiment à clé propre qu'un administrateur rouvre sous cérémonie — dossier ouvert, passphrase de séquestre, journal signé. Il ne rend que ce compartiment, jamais le coffre privé, et il n'est pas le mode Hybrid : rien n'y est déchiffré au fil de l'eau. Le séquestre d'un coffre archivé part avec l'archive, et l'administrateur le rouvre de la même façon (`getArchiveEscrowFieldsAsAdmin()`).

---

## Modèle de menace en un coup d'œil

| Adversaire | Sans SelfDataGuard | Avec SelfDataGuard |
|------------|---------------------|---------------------|
| SQL injection / IDOR / dump DB | Données personnelles en clair exposées | Soupe chiffrée |
| Bande de sauvegarde volée | Données personnelles en clair exposées | Soupe chiffrée |
| DBA malveillant | Lit tout | Chiffré (impossible de déballer sans un des secrets d'un utilisateur) |
| Compromission root applicative (RCE) | Lit tout | Lit les sessions actives — c'est le mode Lite, le seul en service |
| Endpoint utilisateur compromis (keylogger) | Identifiants utilisateur capturés | Identifiants capturés → données de cet utilisateur uniquement (pas de fan-out) |
| Papier de passphrase volé | Accès au compte par le niveau 1 | Le même, plus les données de cet utilisateur — hors ligne aussi, avec un dump. La passphrase est consommée à sa première utilisation légitime, qui la remplace |
| Coercition d'un admin pour déchiffrer | Toutes les données à la discrétion de l'admin | En Lite, aucune clé admin permanente n'existe : il faudrait un secret de chaque utilisateur. Le séquestre s'ouvre sous cérémonie et ne rend que son compartiment |

---

## Statut

**v0.5.1 — la 0.5.0 (une troisième serrure, et l'archive au lieu de la destruction) tourne aussi sous PHP 8.1 et 8.2**, 2 octobre 2026.

Whitepaper complet (spécification + modèle de menace). Bibliothèque PHP de référence implémentée (3 601 lignes réparties sur 25 fichiers, PSR-4, PHP 8.1+, libsodium). Primitives cryptographiques (Argon2id, HMAC-SHA256, XChaCha20-Poly1305, et AES-256-GCM pour relire les blobs écrits avant la 0.4.0) couvertes par **319 contrôles répartis sur 10 suites**, joués sous PHP 8.1, 8.2 et 8.4, tous passants. Une démo HTML cliquable est incluse pour inspecter la base chiffrée en temps réel.

Une base créée par la 0.4.0 se migre en place à sa première ouverture par la 0.5.0 (colonnes `wrap_phrase` et `revision`). Un retour à la 0.4.0 ne voit pas les archives, et laisse en place une serrure passphrase que SelfRecover a pu remplacer depuis : [CHANGELOG](./CHANGELOG.md). Les blobs écrits par la 0.3.0 restent lisibles, par OpenSSL (`ext-openssl`) là où libsodium refuse AES.

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
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Vault\Lock;

require 'vendor/autoload.php';

$dg = new SelfDataGuard(
    storage:  new SqliteAdapter('sqlite:/chemin/vers/db.sqlite'),
    blindKey: file_get_contents('/chemin/vers/secret-serveur.bin')  // ≥32 octets
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
['unlocked' => $session, 'archiveId' => $id] = $dg->reEnroll('alice', $mdp, $empreinte, $passphrase);

// Recherche par champ indexé, sans déchiffrer aucun row
$userId = $dg->findUserByField('email', 'a@b.c');  // 'alice' ou null
```

Trois classes principales exposées : `SelfDataGuard` (façade), `SqliteAdapter` (stockage ; implémente `StorageInterface` pour MariaDB / Postgres), `Primitives` (crypto brute si tu veux bâtir au-dessus). Chaque échec a son type d'exception : `WrongSecretException`, `MissingEnvelopeException`, `VaultNotFoundException`, `StaleVaultException` (une session ouverte sur un coffre remplacé depuis).

---

## Tests

Dix suites de tests sanity, exécutables directement avec `php` (pas besoin de PHPUnit) :

```bash
php tests/sanity_primitives.php   # 46 tests — Argon2id, HMAC, XChaCha20-Poly1305 + vecteur IETF, AES-GCM historique, aléatoire
php tests/sanity_vault.php        # 51 tests — trois serrures, rotation, séparation des contextes, liaison AAD, génération du coffre
php tests/sanity_fields.php       # 26 tests — chiffrement de champs + blind index
php tests/sanity_storage.php      # 60 tests — adaptateur SQLite, transactions imbriquées, écriture conditionnelle (génération, révision), test "soupe DB"
php tests/sanity_migration.php    # 10 tests — base 0.4.0 migrée en place, deux migrateurs simultanés
php tests/sanity_archive.php      # 26 tests — archive : contenu, cloisonnement, tout-ou-rien, écrivain concurrent attendu
php tests/sanity_facade.php       # 57 tests — API complète bout en bout, recover(), niveau 3, course à l'écriture
php tests/sanity_audit.php        # 12 tests — journal d'audit
php tests/sanity_ceremony.php     # 14 tests — cérémonie de clés
php tests/sanity_escrow.php       # 17 tests — compartiment escrow
# Total : 319 tests, 0 échec — relevé par exécution le 02/10/2026
```

La suite `sanity_storage.php` inclut un "BIG TEST" qui dumpe le fichier SQLite et vérifie qu'aucune donnée personnelle en clair n'apparaît nulle part dans le blob binaire. Côté SelfRecover, `bi-self/selfrecover/tests/sanity_parcours_dataguard.php` fait traverser à un coffre chaque récupération, sur les vrais chemins des deux bibliothèques.

---

## Documentation

- [Whitepaper FR (spécification complète)](./docs/whitepaper-fr.md)
- [Whitepaper EN (full specification)](./docs/whitepaper-en.md)
- [Walkthrough de la démo](../../demo/selfdataguard/README.md)

---

## Licence

**AGPL-3.0-or-later**. Voir [LICENSE](../../LICENSE).

Si tu modifies SelfDataGuard et que tu offres ta version à des utilisateurs à travers un réseau, tu dois leur donner accès à son code source, sous la même licence (AGPL-3.0, article 13).
