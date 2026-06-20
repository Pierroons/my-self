# SelfRecover

> 🇬🇧 **[Read in English →](./README.md)**

**Protocole de récupération de compte sans email** — connaissance partagée, HMAC par service, pas de SMTP, pas de tiers.

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.1.1](https://img.shields.io/badge/status-v0.1.1-green.svg)](#statut)
[![Part of: Bi-Self](https://img.shields.io/badge/part%20of-Bi--Self-blue.svg)](../README.fr.md)
[![Self-hosted](https://img.shields.io/badge/self--hosted-Raspberry%20Pi-blue.svg)](#quickstart)
[![Zero dependencies](https://img.shields.io/badge/dependencies-zero-brightgreen.svg)](#quickstart)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

> **Un mot. Chaque site. Pas d'email requis.**

---

## Module compagnon — SelfDataGuard (concept)

Pour les déploiements e-commerce ou SaaS qui ont également besoin de **protéger les données personnelles stockées** contre une exfiltration de base, voir le module compagnon [SelfDataGuard](../../self-security/selfdataguard/). SelfDataGuard réutilise le mot mémorisé de récupération SelfRecover comme l'un de ses facteurs d'encapsulage de clé (avec un séparateur de contexte strict : `/recover` pour l'auth, `/dataguard` pour les données), de sorte qu'un utilisateur qui oublie son mot de passe peut simultanément retrouver son accès au compte ET déchiffrer ses données stockées avec le même mot mémorisé.

SelfRecover protège l'**authentification**. SelfDataGuard protège les **données au repos**. Ensemble, ils ferment la boucle contre les fuites de type ANTS avril 2026 (où à la fois les tokens d'auth ET les données personnelles ont été exposés en clair).

---

## Deux modes d'adoption — Full et Lite (v0.1.1)

SelfRecover existe en deux variantes pour permettre une adoption progressive
sans réécriture totale de la pile d'authentification existante.

| Mode | Canal email | Crypto ajoutée | Quand le choisir |
|------|-------------|----------------|------------------|
| **[Full](./demo/index.html)** | Aucun | Passphrase diceware EFF + HMAC par service | Projets greenfield, modèles de menace exigeants et post-ANTS |
| **[Lite](./demo/lite.html)** 🆕 | Conservé (lien reset SMTP) | Un mot mémorisé par l'utilisateur, dérivé HMAC côté client, jamais envoyé en clair | Stack legacy qui veut une résistance au phishing immédiate, migration vers Full plus tard |

**Démos live :** [Full](https://bi-self.my-self.fr/selfrecover/) · [Lite](https://bi-self.my-self.fr/selfrecover/lite.html) · [Comparatif côte à côte (8 adversaires × 3 modèles)](https://bi-self.my-self.fr/selfrecover/comparison.html)

---

## Le problème

Toute application web fait face à la même question : *que se passe-t-il quand un utilisateur oublie son mot de passe ?*

Depuis vingt ans, la réponse de l'industrie est : **envoyer un email**. Mais cela crée une chaîne de dépendances — fournisseurs SMTP, problèmes de délivrabilité, dossiers spam, boîtes mail tierces, tokens qui expirent — et cela externalise le modèle de sécurité à un service que vous ne contrôlez pas.

**Pourquoi un site web a-t-il besoin de votre email pour prouver que vous êtes vous ?**

---

## La solution

SelfRecover est un protocole de récupération à **connaissance partagée** :

- **Mot de récupération seul** = rien.
- **Algorithme seul** = rien.
- **Mot de récupération + algorithme** = identité prouvée.

L'utilisateur se souvient d'**un mot de son choix**. C'est tout.

Quand il le saisit, le navigateur effectue une **dérivation HMAC-SHA256** clavée par un **label de service** stable et un **sel par utilisateur**, produisant une clé spécifique au service avant que quoi que ce soit quitte le client. Le serveur ne voit jamais le mot brut.

```
derived_key = HMAC-SHA256(clé = service_label ‖ user_salt, message = recovery_word)
```

**Pas de SMTP.** **Pas de tiers.** **Même UX sur chaque site.**

---

## Spécification cryptographique

### Primitives

| Rôle | Algorithme | Paramètres |
|------|-----------|------------|
| Dérivation de clé côté client | HMAC-SHA256 | clé = service_label &#124;&#124; user_salt, message = recovery_word |
| Stockage des secrets côté serveur | Argon2id | mémoire = 64 Mio, time = 4, threads = 2 (memory-hard) |
| Hachage de l'identifiant public | SHA-256 | tronqué à 16 octets, puis encodé en hex |
| Génération de passphrase (L1) | EFF Diceware | 4 mots, ≥ 51 bits d'entropie |
| Sel par utilisateur | 16 octets aléatoires | généré côté client à l'inscription, stocké en clair (un sel n'est pas un secret) |

### Modèle de stockage

Pour chaque compte, le serveur stocke exactement trois secrets :

```sql
CREATE TABLE account (
  id           INTEGER PRIMARY KEY,
  identifier   TEXT UNIQUE,              -- public, choisi par l'utilisateur
  password     TEXT,                     -- Argon2id(password)
  pass_hash    TEXT,                     -- Argon2id(diceware_passphrase)  [L1]
  recovery     TEXT,                     -- Argon2id(derived_key)          [L2]
  user_salt    TEXT,                     -- sel par utilisateur, généré côté client (pas un secret)
  created_at   INTEGER
);
```

Le serveur ne voit jamais : le mot de passe brut, la passphrase brute, le mot de récupération brut. Chaque comparaison est une vérification Argon2id contre la valeur dérivée soumise par le client.

### Chaîne de renforcement de clé (récupération niveau 2)

```
saisie user  → recovery_word
client       → derived_key  = HMAC-SHA256(service_label ‖ user_salt, recovery_word)
réseau       → POST /recover { identifier, derived_key }
serveur      → verify        = password_verify(derived_key, stored_recovery_hash)  // Argon2id
```

Le réseau ne transporte jamais le mot de récupération. Le serveur ne le stocke jamais. Même une fuite complète de la base de données + du code source ne l'expose pas — seulement des hachages Argon2id de clés dérivées par site.

### Pourquoi HMAC-SHA256 (et pas PBKDF2 / Argon2)

HMAC est volontairement **rapide** côté client car l'objectif est la liaison au service, pas la résistance au brute-force. La résistance au brute-force est assurée côté serveur par **Argon2id** (memory-hard, 64 Mio par tentative) sur la clé dérivée. Séparer les rôles garde l'UX instantanée sur mobile tout en imposant un coût memory-hard par tentative de vérification côté serveur.

---

## Escalade de récupération à trois niveaux

| Niveau | Secret requis | Résultat |
|-------|----------------|---------|
| **L1** | Passphrase (diceware, 4 mots) | Nouveau mot de passe |
| **L2** | Identifiant public + mot de récupération | Nouveau mot de passe |
| **L3** | Identifiant public + signaux contextuels | Décision d'un admin humain, puis ré-enrôlement par l'utilisateur |

Limites de débit, système de litige, et détection d'abus à chaque niveau. Le L3 produit des **faits bruts** pour un admin humain — jamais un score automatique — et en cas d'accord l'utilisateur redéfinit lui-même son secret (le serveur n'émet aucun mot de passe).

---

## Quickstart — lancer la démo en 30 secondes

### Option A — Docker (zéro install hors docker)

```bash
git clone https://github.com/Pierroons/my-self.git
cd my-self/bi-self/selfrecover/demo
docker build -t selfrecover .
docker run -p 8080:8080 selfrecover
# → http://localhost:8080
```

Image basée sur `php:8.2-cli-alpine`, ~50 Mo, labels AGPL embarqués (`org.opencontainers.image.licenses=AGPL-3.0-or-later`). Variable `-e SELFRECOVER_FRESH_DB=1` pour réinitialiser la SQLite à chaque démarrage.

### Option B — PHP CLI natif

**Prérequis :** PHP 8.0+ avec `pdo_sqlite` (sur Debian/Ubuntu : `sudo apt install php-cli php-sqlite3`).

```bash
git clone https://github.com/Pierroons/my-self.git
cd my-self/bi-self/selfrecover/demo
./run.sh
# → http://localhost:8080
```

La démo est une application web à page unique autonome qui permet de :
1. **S'inscrire** (passphrase diceware générée automatiquement)
2. **Se connecter** avec identifiant + mot de passe
3. **Récupérer L1** — mot de passe oublié → saisir la passphrase → nouveau mot de passe
4. **Récupérer L2** — passphrase aussi oubliée → saisir identifiant + mot de récupération → nouveau mot de passe
5. **Récupérer L3** — tout perdu → des questions de contexte forment un faisceau de faits bruts pour un admin humain (aucun score) ; en cas d'accord, tu redéfinis toi-même ton secret

Aucune dépendance au-delà de PHP CLI. SQLite comme base. Configuration zéro.

> **Note :** La démo couvre les trois niveaux (L1/L2/L3), y compris le chat de litige et la décision admin. Lire le **[whitepaper](docs/whitepaper-fr.md#5-escalade-de-recuperation-a-trois-niveaux)** pour la spec complète L3.

---

## Architecture

```
┌──────────────┐           ┌──────────────┐
│  Navigateur  │           │    Serveur   │
└──────┬───────┘           └──────┬───────┘
       │                          │
       │  GET /user-salt?id=…     │
       │─────────────────────────>│
       │<─────────────────────────│
       │   user_salt              │
       │                          │
       │  [dérive HMAC local]     │
       │                          │
       │   POST /recover          │
       │   { derived_key }        │
       │─────────────────────────>│
       │                          │
       │      [verif. Argon2id]   │
       │                          │
       │<─────────────────────────│
       │   nouveau mot de passe   │
       │                          │
```

Le mot de récupération brut ne quitte jamais le navigateur.

---

## Propriétés de sécurité

| Propriété | Comment c'est obtenu |
|----------|------------------|
| **Serveur à connaissance nulle** | Le serveur ne voit que des hachages Argon2id de valeurs dérivées par site. Une compromission de la base ne révèle aucun mot de récupération. |
| **Résistance au phishing passif** | La dérivation est liée à un label de service stable, non réutilisé d'un service à l'autre. Un clone passif qui copie la page sans l'adapter dérive une clé inutile. Un site de phishing actif qui contrôle sa propre page est hors périmètre (vrai pour tout protocole in-browser). |
| **Résistance au rejeu** | Chaque requête de récupération est limitée par un rate limit côté serveur + système de litige. Le L3 ajoute une décision revue par un humain. |
| **Résistance à la fuite** | Chaque compte a son propre sel ; le serveur ne stocke que des hachages Argon2id de clés dérivées par service. Une fuite du code client seul est inutile. |
| **Pas de dépendance centrale** | Chaque déploiement est autonome. Pas de SPOF, pas de vendor lock-in, pas d'opérateur qui peut révoquer des comptes à travers l'écosystème. |
| **Secret mémorisable** | Un mot au choix de l'utilisateur. Pas une seed de 24 mots, pas une passphrase à écrire sur papier, pas un QR code. |

---

## Modèle de menace en bref

**Protège contre :**
- Compromission de la base de données (stockage Argon2id seul, pas de secrets réversibles)
- Phishing passif (dérivation liée au service)
- Attaques SMTP, SIM swapping, prise de contrôle de boîte mail (pas d'email dans la boucle)
- Brute force de compte (coût memory-hard Argon2id + rate limits + L3 revu par un humain)

**Ne prétend pas protéger contre :**
- Code client malveillant / phishing actif (si l'attaquant contrôle la page que votre navigateur charge, le protocole ne peut rien — vrai pour n'importe quel protocole in-browser)
- Mots de récupération faibles (`password`, `123`) — mitigé par le rate-limiting et l'escalade vers un L3 revu par un humain, pas par la dérivation elle-même
- Coercition physique de l'utilisateur (voir SelfGuard dans cet écosystème pour un stockage conscient de la contrainte)
- Malware ciblé avec keylogging

Analyse complète : **[docs/threat-model.md](docs/threat-model.md)**

---

## Documentation

- **[Whitepaper (FR)](docs/whitepaper-fr.md)** — spécification technique complète, modèle de menace, checklist de déploiement
- **[Whitepaper (EN)](docs/whitepaper-en.md)** — English version
- **[Architecture](docs/architecture.md)** — diagrammes de flux détaillés
- **[Modèle de menace](docs/threat-model.md)** — contre quoi SelfRecover protège, et contre quoi il ne protège pas

---

## Au-delà du web : déverrouillage de disque

Le même mot de récupération, dérivé par **label** avec Argon2id, peut aussi servir de clé de secours pour un volume chiffré **LUKS2** — permettant à une machine de déverrouiller son disque sans email ni tiers, quand son mécanisme principal (un quorum de témoins distribués) est indisponible. Le label sépare la clé web de la clé disque : aucune ne permet de dériver l'autre.

C'est un module compagnon, **[`selfrecover-luks`](../../self-security/selfrecover-luks/)**, **validé sur banc** (PoC + tests sur image jetable). L'intégration sur un disque de production réel est à venir, pas encore revendiquée comme opérationnelle.

---

## Statut

**Phase de concept — implémentation de référence + démo live, auto-audité**

Ce dépôt contient :
- La **spécification du protocole** (whitepapers v1.1)
- Une **démo autonome fonctionnelle** (L1/L2/L3) pour essayer le concept localement
- Une **implémentation de référence** du protocole complet

**Ce que ce dépôt n'est PAS (encore) :**
- Une bibliothèque PHP/JS installable (prévue, une fois le protocole éprouvé)
- Un produit avec audit de sécurité externe (un audit adverse interne a été mené ; les retours red-team externes sont bienvenus)

Pas encore de déploiement en production réelle. La démo et l'auto-audit sont les moyens de l'éprouver aujourd'hui.

---

## Modèle de menace

SelfRecover est honnête sur ce qu'il protège et ce qu'il ne protège pas. Tout protocole cryptographique a une frontière de garantie.

### Adversaires couverts

| Adversaire | Couverture |
|---|---|
| Serveur SelfRecover compromis | ✅ Connaissance partagée + HMAC client : le serveur ne voit jamais les secrets bruts |
| Phishing passif / page clonée | ✅ HMAC lié au service : un clone qui n'adapte pas son code dérive une clé différente (un phishing actif contrôlant sa page est hors périmètre) |
| Sniffeur réseau / MITM | ✅ TLS en transit + seule la dérivation HMAC est transmise |
| Fuite de base de données | ✅ Hashes Argon2id (memory-hard, GPU-resistant) |
| Brute-force online | ✅ Rate-limit par username + escalade L2/L3 progressive |

### Adversaires HORS PÉRIMÈTRE — assumés explicitement

| Adversaire | Mitigation |
|---|---|
| **Poste utilisateur compromis** (keylogger, info-stealer, RAT) | Hors périmètre. Utiliser **Tails Live USB**, **Qubes OS**, ou **MySelf-Live (V0.2)** pour les cérémonies de secrets racine. Voir [Roadmap](#roadmap). |
| Navigateur compromis (extension, 0-day) | Hors périmètre. Même mitigation. |
| Coercition (physique / rubber-hose) | Hors périmètre. Pas de plausible deniability fournie. |
| Cryptanalyse théorique de SHA-256 / Argon2id | Hors périmètre. Migration suit les recommandations ANSSI/NIST. |

### Discipline opérationnelle

La passphrase **DOIT** ne jamais exister hors du cerveau de l'utilisateur (et papier de backup). Elle ne doit jamais être saisie pour "vérification" ou "validation". Trois moments légitimes seulement :

1. Inscription du compte (une frappe, le serveur stocke un hash Argon2id)
2. Récupération L1 (une frappe, prouve la connaissance)
3. Après récupération, la passphrase n'est plus utilisée — le mot de passe régénéré la remplace

Si la vérification d'une passphrase fraîchement tirée est souhaitée, utiliser **l'outil HTML autonome offline** (`demo/offline/selfrecover-validator.html`) sur une machine déconnectée.

---

## Roadmap

### V0.1 (actuel — mai 2026)

- [x] Spécification du protocole
- [x] Implémentation de référence (ce dépôt)
- [x] Whitepapers EN + FR
- [x] Démo autonome (ce dépôt)
- [x] Wordlist EFF 7776 mots intégrée (EN + FR)
- [x] Trois modes d'entropie dans la démo : Reinhold dés / Auto random / Passphrase libre / Hybride
- [x] PDF de référence diceware (EN + FR) — méthode officielle
- [x] Validateur offline HTML autonome (zéro requête externe, vérifiable par `grep`)

### V0.2 — MySelf-Live (prévu : été 2026)

Distribution Linux minimale, signée et vérifiable, dédiée aux cérémonies SelfRecover :

- Debian/Alpine Live USB, RAM-only, sans persistance
- UEFI Secure Boot avec kernel signé MySelf
- Reproducible builds (n'importe qui peut vérifier le hash de l'image)
- Signature GPG offline (clé root sur smartcard / YubiKey)
- Distribution multi-canal (HTTPS + IPFS + torrent + GitHub releases)
- Pré-installé : daemon SelfRecover (localhost), Tor, Firefox ESR durci, PDF EFF embarqué
- Réseau : désactivé par défaut (mode air-gap au boot)
- Taille cible de l'image : ~500 MB
- Inspirée de Tails / Qubes OS / Whonix, ciblée sur les cérémonies cryptographiques

Squelette de build : voir [`tools/build-myself-live/`](../../tools/build-myself-live/) (en cours).

### V0.3 (prévu : automne 2026)

- [ ] Audit de sécurité communautaire
- [ ] Pipeline reproducible build finalisé
- [ ] Anti-Evil-Maid (Heads / TPM measurements) optionnel
- [ ] Localisations EN / FR / DE / ES

### V1.0 (prévu : 2027)

- [ ] Extraction en bibliothèque PHP (`composer require pierroons/selfrecover`)
- [ ] Extraction en bibliothèque JS (`npm install selfrecover`)
- [ ] Plugin WordPress
- [ ] Package Laravel
- [ ] Portages vers Python, Go, Rust, Node

---

## Contributions

Voir [CONTRIBUTING.md](CONTRIBUTING.md). Retours, audits, expérience d'implémentation, et portages bienvenus.

Divulgations de sécurité : voir [SECURITY.md](SECURITY.md).

---

## Licence

[AGPL-3.0-or-later](../../LICENSE) — copyleft fort. Utilise-le, modifie-le, auto-héberge-le. Si tu bâtis un service au-dessus de SelfRecover et que tu le proposes à d'autres, tu dois aussi publier tes modifications.

---

## Auteur

**Pierroons** — auteur du projet.

*SelfRecover — parce que votre identité ne devrait pas dépendre d'une boîte mail.*
