# SelfDataGuard

> 🇬🇧 **[Read in English →](./README.md)**

**Protection des données au repos côté application, qui survit à une exfiltration de base de données.**

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../../LICENSE)
[![Statut : concept v0.0.1](https://img.shields.io/badge/statut-concept%200.0.1-lightgrey.svg)](#statut)
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

**v0.0.1 — stade concept**, mai 2026.

Brouillon de spécification, modèle de menace et architecture documentés. Une bibliothèque PHP de preuve de concept est prévue pour la v0.1.0 (cible : ~600 lignes, comme la démo SelfRecover). Première cible de déploiement en production : un vrai site e-commerce comme banc d'essai ([exploitation].fr, propriété de l'auteur).

Ce module **n'est pas encore prêt pour la production**. Il est publié sous forme de concept pour :

- Inviter une revue communautaire de la conception cryptographique avant implémentation
- Permettre aux chercheurs en sécurité de challenger le modèle de menace
- Coordonner avec les intégrateurs en aval (notamment les utilisateurs de SelfRecover)

---

## Documentation

- [Whitepaper FR (spécification complète)](./docs/whitepaper-fr.md)
- [Whitepaper EN (full specification)](./docs/whitepaper-en.md)

---

## Licence

**AGPL-3.0-or-later**. Voir [LICENSE](../../LICENSE).

Tout déploiement, modifié ou non, doit publier son code source sous la même licence. Aucune capture SaaS possible. Même mécanisme que Nextcloud, Mastodon, ProtonMail.
