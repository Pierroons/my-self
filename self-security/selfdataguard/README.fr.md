# SelfDataGuard

> 🇬🇧 **[Read in English →](./README.md)**

**Protection des données au repos côté application, qui survit à une exfiltration de base de données.**

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../../LICENSE)
[![Statut : v0.4.0 en service](https://img.shields.io/badge/statut-v0.4.0%20en%20service-brightgreen.svg)](#statut)
[![Tests : 219 passants](https://img.shields.io/badge/tests-219%20passants-brightgreen.svg)](#tests)
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

SelfDataGuard implémente un **encapsulage de clé à deux facteurs** inspiré des architectures Bitwarden, 1Password et ProtonMail vault, adapté au chiffrement par utilisateur côté application :

```
        ┌─────────────────────────────────────┐
        │      data_master_key par user       │  ← 256 bits aléatoires
        │      (jamais stockée en clair)      │  ← en mémoire uniquement quand user connecté
        └────────────┬────────────┬───────────┘
                     │            │
          encapsulée avec    encapsulée avec
                     │            │
        ┌────────────▼─┐      ┌──▼─────────────┐
        │ password_key │      │   recov_key    │
        │ Argon2id(    │      │ Argon2id(      │
        │   password,  │      │  mot_memorise, │
        │   user_salt) │      │  SHA-256(      │
        │              │      │   user_salt +  │
        │              │      │  "/dataguard"))│
        └──────────────┘      └────────────────┘
```

Argon2id prend un sel de 16 octets : les 16 premiers octets de ce SHA-256. Les deux clés d'encapsulage coûtent autant : deux enveloppes ne valent que la moins chère à ouvrir.

Chaque utilisateur dispose de :

- Un `user_salt` aléatoire unique, stocké en clair (équivalent à un identifiant)
- Un `data_master_key_pwd_wrap` : ciphertext XChaCha20-Poly1305 de la clé maîtresse, chiffré avec la clé dérivée du mot de passe
- Un `data_master_key_recov_wrap` : ciphertext XChaCha20-Poly1305 de la clé maîtresse, chiffré avec la clé dérivée du mot mémorisé
- Des champs de données personnelles chiffrés un par un avec `data_master_key`

**Dump de la base → soupe cryptographique.** Aucune combinaison des valeurs en clair présentes dans le dump ne permet d'obtenir la clé maîtresse. L'attaquant aurait besoin soit du mot de passe de l'utilisateur (durci par Argon2id, isolé par sel), soit du mot mémorisé de l'utilisateur (jamais transmis en clair) pour déchiffrer quoi que ce soit.

---

## Couplage avec SelfRecover

SelfDataGuard réutilise le mot mémorisé de récupération de SelfRecover comme l'un de ses deux facteurs de désencapsulage, avec **deux dérivations distinctes** — fonctions et sels différents — pour empêcher tout crossover :

```
mot_memorise (secret utilisateur, jamais transmis en clair)
    │
    ├─ HMAC-SHA256(clé = secret, msg = matériel + "|v2" + user_salt)  →  recover_key  (auth SelfRecover)
    │
    └─ Argon2id(secret, SHA-256(user_salt + "/dataguard")[:16])       →  data_key     (encapsulage SelfDataGuard)
```

Conséquence pratique : un utilisateur qui oublie son mot de passe garde une voie vers chacune de ses deux moitiés. Son mot mémorisé ouvre **à lui seul** le coffre SelfDataGuard. Pour l'accès au compte, il lui faut en plus ce que SelfRecover exige — son *recovery code* papier au niveau 2, ou sa passphrase diceware au niveau 1 : le mot mémorisé n'y est **qu'un facteur sur deux**. Un seul mot à retenir, deux usages dérivés, mathématiquement isolés.

Sans SelfRecover, SelfDataGuard fonctionne quand même — il bascule alors sur un encapsulage uniquement par mot de passe (récupération à un seul facteur, UX dégradée). Mais l'appariement naturel est : **SelfRecover protège l'authentification, SelfDataGuard protège les données, et le même mot mémorisé sert dans les deux** — seul pour ouvrir le coffre, accompagné du *recovery code* pour rouvrir le compte.

---

## Trois modes opérationnels

| Mode | Accès serveur aux données | Compromis |
|------|---------------------------|-----------|
| **Lite** *(transparent pour les piles legacy)* | Le serveur déchiffre uniquement pendant les sessions utilisateur | Compromission serveur pendant une session active = fan-out limité (un utilisateur à la fois) |
| **Hybrid** *(par défaut pour e-commerce)* | Champs opérationnels (`email`, `adresse_livraison`) encapsulés avec une clé opérationnelle admin. Champs sensibles (`tel`, `doc_KYC`) nécessitent une session utilisateur | L'admin peut traiter les commandes ; les données sensibles restent zero-knowledge |
| **Full** *(zero-knowledge pour services à forte exigence)* | Le serveur ne déchiffre JAMAIS. Toute la crypto tourne dans le navigateur via WebCrypto SubtleCrypto | Certains workflows à redessiner (pas de mails transactionnels asynchrones, notifications push à la place) |

La majorité des déploiements e-commerce choisiront **Hybrid**. Santé, banque, fournisseurs d'identité choisiront **Full**.

---

## Modèle de menace en un coup d'œil

| Adversaire | Sans SelfDataGuard | Avec SelfDataGuard |
|------------|---------------------|---------------------|
| SQL injection / IDOR / dump DB | Données personnelles en clair exposées | Soupe chiffrée |
| Bande de sauvegarde volée | Données personnelles en clair exposées | Soupe chiffrée |
| DBA malveillant | Lit tout | Chiffré (impossible de déballer sans mot de passe ou mot mémorisé d'un utilisateur) |
| Compromission root applicative (RCE) | Lit tout | Lit uniquement les sessions actives (Lite) ou les champs opérationnels (Hybrid). Rien (Full) |
| Endpoint utilisateur compromis (keylogger) | Identifiants utilisateur capturés | Identifiants capturés → données de cet utilisateur uniquement (pas de fan-out) |
| Coercition d'un admin pour déchiffrer | Toutes les données à la discrétion de l'admin | L'admin ne peut déchiffrer que les champs opérationnels (Hybrid) — pour le reste, il faudrait le mot de passe ou le mot mémorisé de chaque utilisateur |

---

## Statut

**v0.4.0 — XChaCha20-Poly1305 sur tout processeur, format de blob versionné**, 26 septembre 2026.

Whitepaper complet (spécification + modèle de menace). Bibliothèque PHP de référence implémentée (2 607 lignes réparties sur 18 fichiers, PSR-4, PHP 8.1+, libsodium). Primitives cryptographiques (Argon2id, HMAC-SHA256, XChaCha20-Poly1305, et AES-256-GCM pour relire les blobs écrits avant la 0.4.0) couvertes par **219 contrôles répartis sur 8 suites**, tous passants.

Le chiffrement ne dépend plus du processeur. Jusqu'à la 0.3.0, il reposait sur AES-256-GCM, que libsodium ne sert qu'avec un support matériel — AES-NI, plus AVX depuis libsodium 1.0.19 — et jamais sur un Raspberry Pi 4. Les blobs écrits par la 0.3.0 restent lisibles, par OpenSSL là où libsodium refuse AES. Une fois qu'un blob a été écrit par la 0.4.0, revenir à la 0.3.0 le rend illisible : cette version le refuse comme base64 invalide au lieu de le lire de travers. Une démo HTML cliquable est incluse pour inspecter la base chiffrée en temps réel.

Le module tourne sur des déploiements réels. Il **n'a pas été audité par un cryptographe extérieur** : sa conception n'est vérifiée à ce jour que par son auteur et par les lecteurs de ce dépôt.

Les revues sont recherchées, sur la conception comme sur l'implémentation — les chercheurs en sécurité disposent d'une cible exécutable plutôt que d'une spécification à contester. Les intégrateurs en aval, en particulier les utilisateurs de SelfRecover, lisent le modèle de menace avant de le brancher.

Un audit cryptographique communautaire formel est prévu avant la v1.0.0. Soumission ANSSI Visa de sécurité prévue au même jalon.

---

## Démarrage rapide

### Lancer la démo standalone (zéro install)

```bash
cd demo && ./run.sh
# ouvrir http://127.0.0.1:8081 dans un navigateur
```

La démo permet d'inscrire un utilisateur, se connecter, changer de mot de passe, et inspecter la base SQLite brute en parallèle — démontrant que les champs personnels (email, tél, IBAN, adresse) ne sont jamais lisibles sur disque.

### Utiliser la bibliothèque dans votre app

```php
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;

require 'vendor/autoload.php';

$dg = new SelfDataGuard(
    storage:  new SqliteAdapter('sqlite:/chemin/vers/db.sqlite'),
    blindKey: file_get_contents('/chemin/vers/secret-serveur.bin')  // ≥32 octets
);

// Nouvel utilisateur
$session = $dg->register('alice', 'correct horse battery staple', 'sunset-river-marble');
$dg->setFields($session, ['email' => 'a@b.c', 'iban' => 'FR76...'], indexed: ['email']);

// Connexion classique
$session = $dg->loginWithPassword('alice', 'correct horse battery staple');
$fields  = $dg->getFields($session);  // ['email' => 'a@b.c', 'iban' => 'FR76...']

// Récupération (mot de passe oublié, mot mémorisé connu)
$session = $dg->loginWithMemorized('alice', 'sunset-river-marble');
$dg->changePassword($session, 'nouvelle-passphrase-solide-ici');

// Recherche par champ indexé, sans déchiffrer aucun row
$userId = $dg->findUserByField('email', 'a@b.c');  // 'alice' ou null
```

Trois classes principales exposées : `SelfDataGuard` (façade), `SqliteAdapter` (stockage ; implémentez `StorageInterface` pour MariaDB / Postgres), `Primitives` (crypto brute si vous voulez bâtir au-dessus).

---

## Tests

Huit suites de tests sanity, exécutables directement avec `php` (pas besoin de PHPUnit) :

```bash
php tests/sanity_primitives.php   # 45 tests — Argon2id, HMAC, XChaCha20-Poly1305 + vecteur IETF, AES-GCM historique, aléatoire
php tests/sanity_vault.php        # 36 tests — register, unlock, rotation, liaison AAD, wraps historiques
php tests/sanity_fields.php       # 26 tests — chiffrement de champs + blind index
php tests/sanity_storage.php      # 36 tests — adaptateur SQLite, test "soupe DB"
php tests/sanity_facade.php       # 34 tests — API complète bout en bout
php tests/sanity_audit.php        # 11 tests — journal d'audit
php tests/sanity_ceremony.php     # 14 tests — cérémonie de clés
php tests/sanity_escrow.php       # 17 tests — compartiment escrow
# Total : 219 tests, 0 échec — relevé par exécution le 26/09/2026
```

La suite `sanity_storage.php` inclut un "BIG TEST" qui dumpe le fichier SQLite et vérifie qu'aucune donnée personnelle en clair n'apparaît nulle part dans le blob binaire.

---

## Documentation

- [Whitepaper FR (spécification complète)](./docs/whitepaper-fr.md)
- [Whitepaper EN (full specification)](./docs/whitepaper-en.md)
- [Walkthrough de la démo](../../demo/selfdataguard/README.md)

---

## Licence

**AGPL-3.0-or-later**. Voir [LICENSE](../../LICENSE).

Tout déploiement, modifié ou non, doit publier son code source sous la même licence. Aucune capture SaaS possible.
