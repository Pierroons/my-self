# SelfRecover

> 🇬🇧 **[Read in English →](./README.md)**

**Protocole de récupération de compte sans email** — connaissance partagée, HMAC par service, pas de SMTP, pas de tiers.

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.1.1](https://img.shields.io/badge/status-v0.1.1-green.svg)](#statut)
[![Part of: Bi-Self](https://img.shields.io/badge/part%20of-Bi--Self-blue.svg)](../README.fr.md)
[![Self-hosted](https://img.shields.io/badge/self--hosted-yes-blue.svg)](#quickstart)
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

**Essayer :** la démo (Full, Lite, comparatif) est **auto-hébergeable en 30 secondes** — voir le [Quickstart](#quickstart--lancer-la-démo-en-30-secondes). Pages : `demo/index.html` (Full), `demo/lite.html` (Lite), `tools/comparison.html` (comparatif 8 adversaires × 3 modèles).

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

| Niveau | Ce qu'il faut fournir | Résultat |
|-------|----------------|---------|
| **L1** | Passphrase (diceware EFF, 4 mots ≈ 51 bits) | Nouveau mot de passe |
| **L2** | **2FA sans identifiant** : un *recovery code* papier (possession) **+** le mot mémorisé (connaissance) — ou, en option, une preuve *« cet appareil »* | Nouveau mot de passe |
| **L3** | Faisceau de faits bruts + échange humain | Décision d'un admin humain, puis ré-enrôlement **par l'utilisateur** |

- **L2 = vrai 2FA, sans identifiant à retenir.** Le *recovery code* **localise** le compte (via un lookup HMAC — plus d'énumération) et fait office de facteur de **possession** ; le mot mémorisé (dérivé HMAC côté client) est le facteur de **connaissance**. Les deux sont vérifiés, avec une **erreur générique** qui ne révèle jamais lequel a échoué. Voir [recovery codes](#recovery-codes) et [facteur « cet appareil »](#facteur--cet-appareil-).
- **L3 = jugement humain.** Un **faisceau de faits bruts** est présenté à un admin — jamais un score automatique. L'accès au litige est protégé par un **sésame propriétaire** (jamais l'identifiant semi-public). En cas d'accord, l'utilisateur **redéfinit lui-même** son secret : le serveur n'émet aucun mot de passe.

Limites de débit, système de litige et détection d'abus à chaque niveau ; **escalade automatique** L1→L2→L3 après 3 échecs.

---

## Recovery codes

Le **foyer de possession de L2**. À l'inscription, un lot de **10 codes** est généré et **affiché une seule fois** (format `xxxxx-xxxxx`, ~40 bits chacun).

Chaque code est stocké **deux fois**, jamais en clair :

| Colonne | Rôle |
|---|---|
| `code_lookup` = `HMAC-SHA256(SERVER_SECRET, code)` | recherche **O(1) sans identifiant** (le code localise le compte) + rôle de *pepper* non réversible |
| `code_hash` = `Argon2id(code)` | vérification + résistance à une fuite de base |

- **Usage unique** (marqué `used` après un reset réussi).
- **Régénérables** à la demande (auth = username + mot mémorisé) — le nouveau lot remplace l'ancien.
- Un avertissement `low_codes` remonte quand il en reste ≤ 2.

C'est ce qui permet un **L2 sans identifiant à retenir** : le code fait à la fois « qui » et « une preuve de possession ».

---

## Facteur « cet appareil »

Une **troisième voie optionnelle de L2**, entièrement côté navigateur — un vrai 2FA cryptographique appareil + connaissance, **sans TPM ni matériel**.

- Une **paire ECDSA P-256** est générée dans le navigateur.
- La **clé privée est chiffrée au repos** par une clé AES-256-GCM dérivée du **mot mémorisé** via **Argon2id** (WASM côté client). Le blob chiffré vit dans **IndexedDB** — la clé nue et le mot ne sont jamais persistés.
- Le **serveur ne stocke que la clé publique** (`device_credentials`), plus un `credential_id` aléatoire qui localise le compte (comme un recovery code).
- La récupération = **signer un challenge** (32 octets, TTL 5 min, usage unique) : le navigateur déchiffre la clé privée avec le mot, signe, le serveur vérifie (`openssl_verify`, SHA-256).

Impossible sans **l'appareil** (le blob) **ET** le **mot** (pour déchiffrer la clé). Protection **logicielle** (pas TPM), device-bound, assumée comme telle. **Désactivé automatiquement sur Tor / profil onion** (WebCrypto/IndexedDB non fiables) — le recovery code papier reste le plancher universel.

---

## Super-utilisateur (SU) — le cran au-dessus de l'admin

SelfRecover distingue trois rôles : **SU → Admin → User**. Un **admin** peut trancher les litiges L3 ; le **SU** gouverne les admins eux-mêmes.

**Principes :**
- **Le SU n'est pas en base.** Il est ancré au serveur : accès au serveur = autorisation. Son secret est **hors base et hors code** — dans un fichier hors webroot ou une variable d'environnement (**modèle Kerckhoffs** : la sécurité tient au secret, pas à l'obscurité du code, qui est public).
- **CLI uniquement**, jamais exposé sur le web ni en distant.
- **Séparation des pouvoirs** : un admin ne se promeut pas lui-même — il **propose** une promotion, le SU **tranche** (avec observation obligatoire).

**Ce que le SU peut faire :** promouvoir/révoquer des admins (révocation = coupe les sessions), approuver/rejeter les demandes de promotion, **auditer** (croise `is_admin` en base ↔ journal → détecte les **admins fantômes** et les met en **quarantaine automatique**), vérifier l'intégrité du journal, changer sa passphrase, sceller/restaurer une sauvegarde du journal (AES-256-GCM), et une commande « coquille vide » (`reset-shell`) si la passphrase SU est perdue (révoque tous les admins, fige le journal, repart propre).

**Journal d'audit** (hors base, hors webroot) — quatre couches :
1. **Append-only** au niveau filesystem (`chattr +a` en prod)
2. **Chaîne de hachage** (`prev_hash → entry_hash`, SHA-256) — toute altération casse la chaîne
3. **HMAC par entrée** (clé dérivée de la passphrase SU)
4. **Externalisation** vers un canal de notification (action + cible + heure uniquement, **jamais** le contexte forensique)

> ⚠️ La page `demo/su.html` est une **simulation pédagogique 100 % côté client** (« tout est FAKE ») : elle rejoue l'expérience du terminal SU sans jamais toucher l'API ni la vraie base. La vraie console SU est le CLI serveur.

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
4. **Récupérer L2** — passphrase aussi oubliée → saisir un recovery code + le mot mémorisé → nouveau mot de passe
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

**Implémentation de référence déployée + auto-auditée**

Ce dépôt contient :
- La **spécification du protocole** (whitepapers v1.1)
- Une **implémentation de référence complète** : récupération L1/L2/L3, recovery codes, facteur « cet appareil », super-utilisateur (SU) avec journal d'audit
- Une **démo autonome fonctionnelle** pour tout essayer localement

**Déploiement réel :** au-delà de la démo, l'implémentation tourne en conditions réelles — notamment comme **backend d'authentification d'un service de messagerie** (auth XMPP via `mod_auth_http`), qui réutilise tel quel le stockage de comptes SelfRecover (table `users` + Argon2id).

**Ce que ce dépôt n'est PAS (encore) :**
- Une bibliothèque PHP/JS installable (prévue en V1.0)
- Un produit avec audit de sécurité **externe** (un audit adverse interne a été mené ; les retours red-team externes sont bienvenus)

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

Si la vérification d'une passphrase fraîchement tirée est souhaitée, utiliser **l'outil HTML autonome offline** (`tools/offline-validator/index.html`) sur une machine déconnectée.

---

## Roadmap

### V0.1 (juillet 2026)

- [x] Spécification du protocole + whitepapers EN + FR
- [x] Implémentation de référence complète (L1/L2/L3)
- [x] Démo autonome + validateur offline HTML (zéro requête externe, vérifiable par `grep`)
- [x] Wordlist EFF 7776 mots intégrée (EN + FR) + PDF de référence diceware
- [x] **Recovery codes** — foyer de possession de L2 (10 codes, HMAC lookup + Argon2id, usage unique)
- [x] **Facteur « cet appareil »** — ECDSA P-256, clé privée sous enveloppe Argon2id, clé publique seule côté serveur
- [x] **Super-utilisateur (SU)** — modèle SU→Admin→User, journal d'audit append-only + hash-chaîné + HMAC, détection d'admins fantômes
- [x] **Déploiement réel** — backend d'authentification d'un service de messagerie (auth XMPP via `mod_auth_http`)

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
