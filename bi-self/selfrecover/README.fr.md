# SelfRecover

> 🇬🇧 **[Read in English →](./README.md)**

**Protocole de récupération de compte sans email** — connaissance partagée, HMAC par domaine, pas de SMTP, pas de tiers.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Status: v0.1.0](https://img.shields.io/badge/status-v0.1.0-green.svg)](#statut)
[![Production tested](https://img.shields.io/badge/production%20tested-ARC%20PVE%20Hub-green.svg)](https://arc.rpi4server.ovh)
[![Part of: Bi-Self](https://img.shields.io/badge/part%20of-Bi--Self-blue.svg)](../README.fr.md)
[![Self-hosted](https://img.shields.io/badge/self--hosted-Raspberry%20Pi-blue.svg)](#quickstart)
[![Zero dependencies](https://img.shields.io/badge/dependencies-zero-brightgreen.svg)](#quickstart)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

> **Un mot. Chaque site. Pas d'email requis.**

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

L'utilisateur se souvient d'**un mot de son choix** (n'importe quel mot, n'importe quelle longueur — même `bob`). C'est tout.

Quand il le saisit, le navigateur effectue une **dérivation HMAC-SHA256** en utilisant le domaine courant comme clé, produisant une clé cryptographique spécifique au site avant que quoi que ce soit quitte le client. Le serveur ne voit jamais le mot brut, et un site de phishing dériverait une clé complètement différente.

```
derived_key = HMAC-SHA256(recovery_word, domain + site_salt)
```

**Anti-phishing natif.** **Pas de SMTP.** **Pas de tiers.** **Même UX sur chaque site.**

---

## Spécification cryptographique

### Primitives

| Rôle | Algorithme | Paramètres |
|------|-----------|------------|
| Dérivation de clé côté client | HMAC-SHA256 | clé = recovery_word, message = domain &#124;&#124; site_salt |
| Stockage des secrets côté serveur | bcrypt | coût = 12 (≈ 250 ms sur serveur moderne) |
| Hachage de l'identifiant public | SHA-256 | tronqué à 16 octets, puis encodé en hex |
| Génération de passphrase (L1) | EFF Diceware | 4 mots, ≥ 51 bits d'entropie |
| Sel du site | 32 octets aléatoires | généré à l'installation, jamais rotaté |

### Modèle de stockage

Pour chaque compte, le serveur stocke exactement trois secrets :

```sql
CREATE TABLE account (
  id           INTEGER PRIMARY KEY,
  identifier   TEXT UNIQUE,              -- public, choisi par l'utilisateur
  password     TEXT,                     -- bcrypt(password)
  pass_hash    TEXT,                     -- bcrypt(diceware_passphrase)  [L1]
  recovery     TEXT,                     -- bcrypt(derived_key)          [L2]
  created_at   INTEGER
);
```

Le serveur ne voit jamais : le mot de passe brut, la passphrase brute, le mot de récupération brut. Chaque comparaison est une vérification bcrypt contre la valeur dérivée soumise par le client.

### Chaîne de renforcement de clé (récupération niveau 2)

```
saisie user  → recovery_word
client       → derived_key  = HMAC-SHA256(recovery_word, domain ‖ site_salt)
réseau       → POST /recover { identifier, derived_key }
serveur      → verify        = bcrypt_verify(derived_key, stored_recovery_hash)
```

Le réseau ne transporte jamais le mot de récupération. Le serveur ne le stocke jamais. Même une fuite complète de la base de données + du code source ne l'expose pas — seulement des hachages bcrypt de clés dérivées par site.

### Pourquoi HMAC-SHA256 (et pas PBKDF2 / Argon2)

HMAC est volontairement **rapide** côté client car l'objectif est la liaison au domaine, pas la résistance au brute-force. La résistance au brute-force est assurée côté serveur par **bcrypt** sur la clé dérivée. Séparer les rôles garde l'UX instantanée sur mobile tout en imposant quand même ≥ 250 ms par tentative de vérification côté serveur.

---

## Escalade de récupération à trois niveaux

| Niveau | Secret requis | Résultat |
|-------|----------------|---------|
| **L1** | Passphrase (diceware, 4 mots) | Nouveau mot de passe |
| **L2** | Identifiant public + mot de récupération | Nouveau mot de passe |
| **L3** | Formulaire de scoring multi-facteur | Nouveau mot de passe ou chat admin |

Limites de débit, système de litige, et détection d'abus à chaque niveau.

---

## Quickstart — lancer la démo en 30 secondes

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

Aucune dépendance au-delà de PHP CLI. SQLite comme base. Configuration zéro.

> **⚠ Note :** La démo ne couvre que les **niveaux 1 et 2** du protocole. Le **niveau 3** (récupération par scoring multi-facteur avec chat de litige admin) n'est **pas** inclus dans la démo car il nécessite une interface admin, un système de litige, et un tableau de bord — trop pour une démo à page unique. Voir l'**[implémentation de référence en production sur ARC PVE Hub](https://arc.rpi4server.ovh)** pour L3 en action, et lire le **[whitepaper](docs/whitepaper-fr.md#5-escalade-de-recuperation-a-trois-niveaux)** pour la spec complète L3.

---

## Architecture

```
┌──────────────┐           ┌──────────────┐
│  Navigateur  │           │    Serveur   │
└──────┬───────┘           └──────┬───────┘
       │                          │
       │   GET /salt              │
       │─────────────────────────>│
       │<─────────────────────────│
       │   salt                   │
       │                          │
       │  [dérive HMAC local]     │
       │                          │
       │   POST /recover          │
       │   { derived_key }        │
       │─────────────────────────>│
       │                          │
       │        [verif. bcrypt]   │
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
| **Serveur à connaissance nulle** | Le serveur ne voit que des hachages bcrypt de valeurs dérivées par site. Une compromission de la base ne révèle aucun mot de récupération. |
| **Anti-phishing natif** | Un site de phishing à `pas-le-vrai-domaine.tld` dérive une clé HMAC différente, qui échoue à correspondre à n'importe quel bcrypt stocké. Aucune formation utilisateur requise. |
| **Résistance au rejeu** | Chaque requête de récupération est limitée par un rate limit côté serveur + système de litige. Le L3 ajoute un scoring multi-facteur. |
| **Forward secrecy contre fuite** | Le sel du site est par déploiement, jamais réutilisé, jamais transmis hors du serveur. Une fuite du code client seul est inutile. |
| **Pas de dépendance centrale** | Chaque déploiement est autonome. Pas de SPOF, pas de vendor lock-in, pas d'opérateur qui peut révoquer des comptes à travers l'écosystème. |
| **Secret mémorisable** | Un mot au choix de l'utilisateur. Pas une seed de 24 mots, pas une passphrase à écrire sur papier, pas un QR code. |

---

## Modèle de menace en bref

**Protège contre :**
- Compromission de la base de données (stockage bcrypt seul, pas de secrets réversibles)
- Phishing (dérivation liée au domaine)
- Attaques SMTP, SIM swapping, prise de contrôle de boîte mail (pas d'email dans la boucle)
- Brute force de compte (coût bcrypt + rate limits + scoring L3)

**Ne prétend pas protéger contre :**
- Code client malveillant (si l'attaquant contrôle la page que votre navigateur charge, c'est fini — vrai pour n'importe quel protocole in-browser)
- Mots de récupération faibles (`password`, `123`, `bob`) — le **scoring L3** mitige en exigeant une vérification multi-facteur si L2 échoue
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

## Statut

**Phase de concept — testé en production sur [ARC PVE Hub](https://arc.rpi4server.ovh)**

Ce dépôt contient :
- La **spécification du protocole** (whitepapers v1.1)
- Une **démo autonome fonctionnelle** pour essayer le concept localement
- Une **implémentation de référence** extraite du code de production d'ARC PVE Hub

**Ce que ce dépôt n'est PAS (encore) :**
- Une bibliothèque PHP/JS installable (prévue, une fois le protocole éprouvé)
- Un produit fini avec audits de sécurité (retours et audits bienvenus)

Le protocole tourne actuellement en production sur ARC PVE Hub avec de vrais utilisateurs. Les retours de déploiements réels façonneront la future bibliothèque.

---

## Roadmap

- [x] Spécification du protocole
- [x] Implémentation de référence (ARC PVE Hub)
- [x] Whitepapers EN + FR
- [x] Démo autonome (ce dépôt)
- [ ] Audit de sécurité (communauté bienvenue)
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

[MIT](LICENSE) — fais ce que tu veux, mais ne me blâme pas si ton chat te réveille à 4h du matin.

---

## Auteur

**Pierroons** — un agriculteur qui code sur son temps libre.

*SelfRecover — parce que votre identité ne devrait pas dépendre d'une boîte mail.*
