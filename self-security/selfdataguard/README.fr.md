# SelfDataGuard

> 🇬🇧 **[Read in English →](./README.md)**

**Protection des données au repos côté application, qui survit à une exfiltration de base de données.**

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../../LICENSE)
[![Statut : beta v0.1.0](https://img.shields.io/badge/statut-beta%200.1.0-yellow.svg)](#statut)
[![Tests : 155 passants](https://img.shields.io/badge/tests-155%20passants-brightgreen.svg)](#tests)
[![Pilier : Self-Security](https://img.shields.io/badge/pilier-Self--Security-blue.svg)](../README.fr.md)
[![Compagnon : SelfRecover](https://img.shields.io/badge/compagnon-SelfRecover-green.svg)](../../bi-self/selfrecover/README.fr.md)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

> **Dump ma base de données — et tu obtiens du bruit chiffré.**

---

## Le problème

Tous les produits actuels de chiffrement des données au repos (MySQL TDE, MongoDB CSFLE, AWS RDS encryption) répondent au même modèle de menace : **l'attaquant a le disque, mais pas l'application**. La clé de chiffrement se trouve à côté des données — dans un fichier de configuration, une variable d'environnement, ou un service de gestion de clés que l'application peut lire.

Ce modèle s'effondre dès que **le serveur d'application est compromis**. L'attaquant exfiltre la base de données ET la clé — le chiffrement n'était qu'une case cochée, pas une défense. Les fuites récentes à grande échelle (ANTS, France, avril 2026 — 11,7 à 19 millions de comptes exposés via une faille IDOR triviale) ont prouvé que les données personnelles exposées en clair constituent le coût dominant de ce type d'incident.

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
        │ Argon2id(    │      │ HMAC-SHA256(   │
        │   password,  │      │  mot_memorise, │
        │   user_salt) │      │  user_salt+    │
        │              │      │  "/dataguard") │
        └──────────────┘      └────────────────┘
```

Chaque utilisateur dispose de :

- Un `user_salt` aléatoire unique, stocké en clair (équivalent à un identifiant)
- Un `data_master_key_pwd_wrap` : ciphertext AES-256-GCM de la clé maîtresse, chiffré avec la clé dérivée du mot de passe
- Un `data_master_key_recov_wrap` : ciphertext AES-256-GCM de la clé maîtresse, chiffré avec la clé dérivée du mot mémorisé
- Des champs de données personnelles chiffrés un par un avec `data_master_key`

**Dump de la base → soupe cryptographique.** Aucune combinaison des valeurs en clair présentes dans le dump ne permet d'obtenir la clé maîtresse. L'attaquant aurait besoin soit du mot de passe de l'utilisateur (durci par Argon2id, isolé par sel), soit du mot mémorisé de l'utilisateur (jamais transmis en clair) pour déchiffrer quoi que ce soit.

---

## Couplage avec SelfRecover

SelfDataGuard réutilise le mot mémorisé de récupération de SelfRecover comme l'un de ses deux facteurs de désencapsulage, avec **séparation contextuelle stricte** pour empêcher tout crossover :

```
mot_memorise (secret utilisateur, jamais transmis en clair)
    │
    ├─ HMAC-SHA256(secret, domaine + "/recover")     →  recover_key  (auth SelfRecover)
    │
    └─ HMAC-SHA256(secret, user_salt + "/dataguard")  →  data_key    (encapsulage SelfDataGuard)
```

Conséquence pratique : un utilisateur qui oublie son mot de passe mais se rappelle son mot mémorisé peut simultanément **retrouver l'accès à son compte (via SelfRecover) et déchiffrer ses données stockées (via SelfDataGuard)**. Un seul mot mémorisé, deux usages dérivés, mathématiquement isolés.

Sans SelfRecover, SelfDataGuard fonctionne quand même — il bascule alors sur un encapsulage uniquement par mot de passe (récupération à un seul facteur, UX dégradée). Mais l'appariement naturel est : **SelfRecover protège l'authentification, SelfDataGuard protège les données, le même mot mémorisé débloque les deux**.

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

**v0.1.0-beta — bibliothèque de référence + démo standalone**, 8 mai 2026.

Whitepaper complet (spécification + modèle de menace). Bibliothèque PHP de référence implémentée (~1230 lignes, PSR-4, PHP 8.1+, libsodium). Primitives cryptographiques (Argon2id, HMAC-SHA256, AES-256-GCM) couvertes par 155 tests sanity. Une démo HTML cliquable est incluse pour inspecter la base chiffrée en temps réel.

Ce module **n'est pas encore prêt pour la production**. Il est publié en beta pour :

- Inviter une revue communautaire de la conception cryptographique ET de l'implémentation
- Permettre aux chercheurs en sécurité de challenger le modèle de menace avec une cible exécutable
- Coordonner avec les intégrateurs en aval (notamment les utilisateurs de SelfRecover)
- Première cible de déploiement réel : un petit site e-commerce de production

Un audit cryptographique communautaire formel est prévu avant la v1.0.0. Soumission ANSSI Visa de sécurité prévue à l'horizon v0.3.0.

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

Cinq suites de tests sanity, exécutables directement avec `php` (pas besoin de PHPUnit) :

```bash
php tests/sanity_primitives.php   # 27 tests — Argon2id, HMAC, AES-GCM, aléatoire
php tests/sanity_vault.php        #  33 tests — register, unlock, rotation, liaison AAD
php tests/sanity_fields.php       # 25 tests — chiffrement de champs + blind index
php tests/sanity_storage.php      # 36 tests — adaptateur SQLite, test "soupe DB"
php tests/sanity_facade.php       # 34 tests — API complète bout en bout
# Total : 155 tests, 0 échec
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
