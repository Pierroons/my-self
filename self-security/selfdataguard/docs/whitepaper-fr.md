# SelfDataGuard — Whitepaper v0.0.1

**Protection des données personnelles au repos côté application**
*Dump ma base — et tu obtiens du bruit chiffré.*

---

## Contexte (mai 2026)

Le 15 avril 2026, le portail `moncompte.ants.gouv.fr` (Agence nationale des titres sécurisés) a subi une fuite de données via une faille IDOR : modifier un identifiant dans une requête de l'API permettait d'accéder au compte d'un autre citoyen. Le ministère de l'Intérieur a confirmé 11,7 millions de comptes impactés ; les attaquants revendiquent jusqu'à 19 millions d'enregistrements exfiltrés. Données exposées : état civil, coordonnées, statut de certification d'identité — **en clair dans la base**, sans chiffrement applicatif susceptible de les rendre inexploitables.

L'incident a posé une question structurelle complémentaire à celle adressée par SelfRecover : **comment rendre une fuite de base de données techniquement inutile pour l'attaquant**, indépendamment du flux d'authentification ?

SelfRecover protège l'**accès** au compte. SelfDataGuard protège les **données** stockées. Ensemble, les deux modules ferment la boucle : un attaquant qui contourne l'authentification (SelfRecover) trouve une base chiffrée (SelfDataGuard) ; un attaquant qui dump la base (SelfDataGuard) trouve des hashes Argon2id non réversibles (SelfRecover).

Ce whitepaper décrit le protocole SelfDataGuard. Il n'est ni une critique ad hoc d'un acteur, ni une revendication post-incident — c'est une proposition open-source, complémentaire à SelfRecover, que les opérateurs publics et privés peuvent auditer, intégrer ou contester librement.

---

## 1. Le problème

### 1.1 Pourquoi les solutions existantes échouent

Tous les produits de chiffrement des données au repos actuels partagent une faiblesse structurelle : **la clé de chiffrement réside au même endroit que les données**, accessible au même processus applicatif qui les lit en clair.

| Produit | Stockage de la clé | Compromission serveur = compromission de la clé ? |
|---------|---------------------|----------------------------------------------------|
| MySQL TDE / MariaDB encryption | Keyring plugin sur le système hôte | ✗ Oui |
| PostgreSQL pgcrypto | Variable de connexion / fichier de conf | ✗ Oui |
| MongoDB CSFLE | Fichier de clés ou KMS distant accessible à l'app | ✗ Oui (KMS donne la clé sur demande de l'app compromise) |
| AWS RDS encryption / Aurora encryption | KMS AWS, transparent à l'application | ✗ Oui |
| Application-level encryption (AES + clé en `.env`) | Variable d'environnement / Vault accessible à l'app | ✗ Oui |

Dans les six cas, un attaquant qui obtient un shell sur le serveur applicatif (RCE, escalade de privilèges, vol de clé SSH) obtient **simultanément** la base et la clé. Le chiffrement au repos n'apporte alors **aucune protection** — il protégeait uniquement contre un attaquant ayant le disque sans le serveur (cas de figure rare en pratique).

### 1.2 La vraie question

> Comment chiffrer les données d'un utilisateur de telle sorte que la clé n'existe que **lorsque cet utilisateur est présent**, et nulle part ailleurs en permanence ?

C'est exactement la question que résolvent Bitwarden (vault de mots de passe), 1Password, ProtonMail (boîte mail chiffrée). Leur architecture commune : **encapsulage de clé** (key wrapping) où la clé maîtresse de l'utilisateur n'existe en clair qu'en mémoire, le temps d'une session, et est encapsulée par un secret connu de lui seul (mot de passe maître).

SelfDataGuard porte cette architecture du **vault personnel** vers la **base utilisateurs d'une application multi-tenant** (e-commerce, SaaS, service public).

---

## 2. Le modèle SelfDataGuard

### 2.1 Principe fondamental

> *Pour chaque utilisateur, la base contient ses données chiffrées par une clé qui n'est jamais stockée en clair. Cette clé est encapsulée par deux facteurs distincts, dérivés respectivement de son mot de passe et de son mot mémorisé. Au moment d'une session active, le serveur déballe la clé en RAM et l'utilise pour servir les requêtes ; à la déconnexion, la clé est purgée.*

Conséquences directes :

- Un dump de base seul → **soupe chiffrée**, aucune donnée personnelle exploitable
- Un dump pendant qu'un utilisateur est connecté → exposition limitée à **cet utilisateur uniquement**, pas de fan-out cross-user
- Une compromission de l'admin → expose les **champs opérationnels** (en mode Hybrid) ou rien du tout (en mode Full)

### 2.2 Architecture détaillée

À la création d'un compte utilisateur :

```
Étape 1 — Génération aléatoire de la clé maîtresse de l'utilisateur :
    data_master_key  ← random(256 bits)        # CSPRNG côté serveur

Étape 2 — Génération du sel utilisateur (identifiant cryptographique) :
    user_salt        ← random(128 bits)        # stocké en clair dans la base

Étape 3 — Dérivation des deux clés d'encapsulage :
    password_key     ← Argon2id(password, user_salt, m=65536, t=3, p=4)
    recov_key        ← HMAC-SHA256(mot_memorise, user_salt || "/dataguard")

Étape 4 — Encapsulage de la clé maîtresse par chacune des deux clés :
    wrap_pwd         ← AES-256-GCM-encrypt(data_master_key, key=password_key, nonce=random_96)
    wrap_recov       ← AES-256-GCM-encrypt(data_master_key, key=recov_key,    nonce=random_96)

Étape 5 — Stockage en base (toutes les valeurs en clair listées ci-dessous) :
    user_id, user_salt, wrap_pwd, wrap_recov, [optional] wrap_admin

Étape 6 — Chiffrement champ par champ des données personnelles :
    email_encrypted        ← AES-256-GCM-encrypt(email,    key=data_master_key, nonce=random_96)
    address_encrypted      ← AES-256-GCM-encrypt(address,  key=data_master_key, nonce=random_96)
    phone_encrypted        ← AES-256-GCM-encrypt(phone,    key=data_master_key, nonce=random_96)
    [...]

Étape 7 — Purge de data_master_key et password_key et recov_key de la mémoire serveur.
```

À la connexion par mot de passe (cas standard, ~99 % du temps) :

```
1. Le serveur reçoit (username, password) sur HTTPS
2. Il récupère user_salt et wrap_pwd depuis la base
3. password_key   ← Argon2id(password, user_salt, ...)
4. data_master_key ← AES-256-GCM-decrypt(wrap_pwd, key=password_key)
5. data_master_key est conservée dans la session (mémoire, jamais persistée)
6. À chaque requête : déchiffrement à la volée des champs personnels
7. Au logout : purge data_master_key
```

À la connexion par mot mémorisé (cas dégradé, mot de passe oublié) :

```
1. Le serveur reçoit (username, mot_memorise) sur HTTPS
2. Il récupère user_salt et wrap_recov depuis la base
3. recov_key       ← HMAC-SHA256(mot_memorise, user_salt || "/dataguard")
4. data_master_key ← AES-256-GCM-decrypt(wrap_recov, key=recov_key)
5. L'utilisateur peut accéder à ses données et redéfinir un nouveau password
6. Régénération du wrap_pwd avec la nouvelle password_key (sans re-chiffrer les données)
```

### 2.3 Pourquoi cette architecture résiste à un dump

Un attaquant qui exfiltre la table utilisateurs obtient :

- `username` (en clair, identifiant)
- `user_salt` (en clair, équivalent à un identifiant)
- `wrap_pwd` (chiffré par `password_key`, qu'il ne connaît pas)
- `wrap_recov` (chiffré par `recov_key`, qu'il ne connaît pas)
- `email_encrypted, address_encrypted, ...` (chiffrés par `data_master_key`, qu'il ne connaît pas)

Pour déchiffrer, il a deux voies :

1. **Bruteforcer le mot de passe** d'un utilisateur ciblé → coût Argon2id par tentative (~250 ms sur GPU haut de gamme avec les paramètres recommandés). Pour un mot de passe à 8 caractères aléatoires : ~10^14 tentatives × 0.25 s = ~10^6 années en parallèle massif. Pour un mot de passe faible (`123456` ou similaire), ça reste faisable. **Recommandation** : la lib refuse les mots de passe en dessous de 12 caractères ou présents dans les blocklists.

2. **Bruteforcer le mot mémorisé** → HMAC-SHA256 est rapide (~10^9 / s sur GPU), mais l'espace de recherche dépend du mot. Si le mot mémorisé est un mot du dictionnaire (~30 000 mots français usuels), bruteforce trivial. **Recommandation** : le mot mémorisé doit être une combinaison d'au moins deux mots ou un mot rare (entropie ≥ 30 bits). À documenter dans l'UX d'inscription.

Une fuite ne donne donc **rien d'exploitable directement**. Le coût de bruteforce est par utilisateur (impossible de bruteforcer la base entière en parallèle puisque chaque user a son propre `user_salt`).

---

## 3. Couplage avec SelfRecover

### 3.1 Le mot mémorisé partagé, deux dérivations isolées

SelfRecover et SelfDataGuard utilisent **le même mot mémorisé** côté utilisateur, mais le dérivent vers deux clés cryptographiques **strictement disjointes** via HMAC contextuel :

```
secret_brut = mot_memorise_utilisateur
              (jamais transmis en clair, jamais stocké)

         ┌──────────────────────────────────────────────────────┐
         │     HMAC-SHA256(secret_brut, domaine + "/recover")  │  →  recover_key  (SelfRecover)
         ├──────────────────────────────────────────────────────┤
         │     HMAC-SHA256(secret_brut, salt + "/dataguard")    │  →  data_key    (SelfDataGuard)
         └──────────────────────────────────────────────────────┘
```

Propriétés cryptographiques :

- **Indépendance** : la connaissance de `recover_key` ne donne aucune information sur `data_key`, et inversement (HMAC-SHA256 est une PRF, ses sorties sur des labels différents sont indistinguables d'aléatoires)
- **Pas de crossover** : une fuite côté SelfRecover (par exemple compromission du store des hashes Argon2id) n'expose pas SelfDataGuard, et inversement
- **UX simplifiée** : l'utilisateur mémorise un seul secret, en dérive deux usages

### 3.2 Le mot peut être régénéré indépendamment

Si l'utilisateur change son mot mémorisé (cf. règle SelfRecover : maximum 2-3 régénérations via mot de passe actuel), SelfDataGuard doit re-encapsuler la `data_master_key` avec la nouvelle `recov_key`. Ceci ne nécessite **pas** de re-chiffrer les données personnelles — seulement de recalculer un nouveau `wrap_recov`.

### 3.3 Cas d'usage : récupération combinée

Scénario : un utilisateur a perdu son mot de passe.

- **Sans SelfDataGuard** : SelfRecover lui permet de redéfinir un mot de passe. Mais il aurait pu, avec ses données personnelles en clair dans la base, perdre l'accès à ces données ? Non : la base était en clair, donc l'admin pouvait toujours les lui re-fournir.
- **Avec SelfDataGuard seul** (sans SelfRecover) : impossible, ses données sont chiffrées par sa `password_key` qu'il ne se rappelle plus.
- **Avec les deux ensemble** : il entre son mot mémorisé. SelfRecover dérive `recover_key` et l'authentifie. SelfDataGuard dérive `data_key`, déballe `wrap_recov` et restaure `data_master_key`. L'utilisateur retrouve simultanément l'accès à son compte et la lisibilité de ses données.

C'est le mécanisme exact qu'on retrouve sur Bitwarden (recovery code) ou ProtonMail (recovery phrase).

---

## 4. Trois modes opérationnels

Tous les déploiements n'ont pas les mêmes contraintes. SelfDataGuard propose trois modes selon le degré de zero-knowledge souhaité.

### 4.1 Mode Lite — transparent pour les piles legacy

```
- Tous les champs sont chiffrés avec data_master_key
- Le serveur déballe la clé pendant les sessions utilisateur uniquement
- Les opérations admin sont possibles uniquement quand l'utilisateur est connecté
```

**Cas d'usage** : SaaS B2B avec faible besoin admin asynchrone, applications dont l'utilisateur reste connecté en continu (extensions navigateur, apps mobile en background).

**Limite** : pas de notifications transactionnelles automatiques. Si un utilisateur passe une commande puis se déconnecte, et qu'une cron veut envoyer un rappel 24h plus tard, elle ne peut pas lire l'email.

### 4.2 Mode Hybrid — recommandé pour e-commerce

```
- Champs opérationnels (email, adresse_livraison) : encapsulés en plus avec une admin_op_key
- Champs sensibles (telephone, doc_KYC, historique_detaille) : data_master_key uniquement
- L'admin peut exécuter les opérations courantes (commandes, livraisons) sans présence utilisateur
```

**Cas d'usage** : e-commerce classique, SaaS B2C avec relations client.

**Trade-off** : compromission du serveur applicatif → exposition des champs opérationnels uniquement. Les données vraiment sensibles (KYC, fiscalité, historique médical) restent zero-knowledge même en cas de RCE.

### 4.3 Mode Full — zero-knowledge strict

```
- Aucune clé de chiffrement n'est jamais accessible au serveur
- Toute la cryptographie est exécutée dans le navigateur via WebCrypto SubtleCrypto
- Le serveur ne fait que stocker et servir des blobs chiffrés
```

**Cas d'usage** : santé, banque, fournisseurs d'identité, réseaux activistes, journalistes en exfiltration source.

**Trade-off** : refonte de plusieurs workflows. Plus de mails transactionnels asynchrones (notifications push à la place). Plus de support client classique (l'admin ne peut RIEN voir des données utilisateur). Recherche full-text impossible (seulement par blind index).

### 4.4 Recommandation par défaut

La majorité des sites e-commerce devraient choisir **Hybrid**. Les services à forte exigence (santé, banque, services régaliens) devraient choisir **Full** et accepter les contraintes UX.

---

## 5. Primitives cryptographiques

| Usage | Primitive | Rationale |
|-------|-----------|-----------|
| Dérivation depuis mot de passe | **Argon2id** (m=65536 KiB, t=3, p=4) | Memory-hard, résistant aux GPU et ASICs. Standard moderne (RFC 9106) |
| Dérivation depuis mot mémorisé | **HMAC-SHA256** | Rapide (compatible UX), PRF prouvée. Pas de memory-hardening parce que l'entropie du mot mémorisé doit être suffisante par construction |
| Chiffrement par enveloppe | **AES-256-GCM** | Authenticated encryption, accélération matérielle universelle, standard NIST |
| Chiffrement de champs | **AES-256-GCM** avec nonce aléatoire 96 bits par champ | Idem |
| Indexation de recherche | **HMAC-SHA256(field, server_blind_key)** | Permet `WHERE field_hash = HMAC(query)` sans déchiffrer. Trade-off : recherche par égalité uniquement, pas full-text |

**Pas de PBKDF2** : Argon2id est plus robuste face aux GPU. PBKDF2 reste acceptable pour l'interopérabilité avec des piles très anciennes mais déconseillé pour de nouveaux déploiements.

**Pas de scrypt** : Argon2id couvre les mêmes propriétés et est aujourd'hui le standard recommandé par l'OWASP, la BSI, l'ANSSI (recommandations 2023+).

---

## 6. Modèle de menace

### 6.1 Adversaires couverts

| Adversaire | Capacité | Résultat avec SelfDataGuard |
|------------|----------|------------------------------|
| Attaquant remote sans accès serveur | Voir le trafic, soumettre requêtes API | Aucun accès aux données (TLS + auth) |
| Attaquant ayant exfiltré la base (dump SQL, backup volé) | Lire la totalité des tables en clair sur disque | Soupe chiffrée, doit bruteforcer chaque utilisateur individuellement |
| Insider DBA | Accès lecture à la base, pas au serveur applicatif | Idem, soupe chiffrée |
| Attaquant avec RCE sur le serveur | Lecture de la mémoire et du disque applicatif | Mode Lite : sessions actives exposées. Mode Hybrid : champs opérationnels exposés. Mode Full : rien |
| Compromission d'un compte utilisateur (phishing endpoint) | Capture du password de cet utilisateur | Données de ce seul utilisateur exposées. Pas de fan-out |
| Coercition d'un admin | Force l'admin à fournir ses clés | Mode Lite : aucune clé permanente côté admin, donc rien. Mode Hybrid : champs opérationnels seulement. Mode Full : rien (l'admin n'a pas de clé) |

### 6.2 Adversaires hors-périmètre

Conformément aux bonnes pratiques recommandées par l'ANSSI en matière de transparence, SelfDataGuard déclare explicitement :

- **Compromission du poste utilisateur** (keylogger, info-stealer, RAT) : HORS PÉRIMÈTRE. Si l'utilisateur entre son password et son mot mémorisé sur une machine compromise, ses données de ce site sont exposées. Recommandation : Tails / Qubes pour les usages à forte exigence.
- **Compromission du navigateur** (extension malveillante, exploit 0-day) : HORS PÉRIMÈTRE en mode Full également. Les opérations crypto WebCrypto sont aussi sûres que le navigateur.
- **Cryptanalyse théorique de SHA-256, AES-256-GCM, Argon2id** : HORS PÉRIMÈTRE. Migration cryptographique conforme aux recommandations ANSSI / NIST quand les algorithmes seront déclarés faibles.
- **Bruteforce d'un mot de passe faible** : HORS PÉRIMÈTRE. La lib doit imposer une politique de mot de passe minimale. Sans politique, le facteur le plus faible domine.
- **Déni de service** : HORS PÉRIMÈTRE. SelfDataGuard ne traite pas la disponibilité, seulement la confidentialité.

---

## 7. Règles de déploiement obligatoires

Pour qu'un déploiement SelfDataGuard apporte effectivement les garanties listées, il doit respecter :

1. **Politique de mot de passe** : minimum 12 caractères, refus des mots de passe présents dans les listes de breach (HaveIBeenPwned, top 10000 communs)
2. **Politique de mot mémorisé** : minimum 2 mots ou un mot rare (entropie ≥ 30 bits estimée par zxcvbn)
3. **TLS obligatoire** : aucune dégradation HTTP autorisée (HSTS strict)
4. **Sessions courtes** : `data_master_key` purgée de la session après inactivité (15 min recommandé pour Hybrid, 5 min pour Full)
5. **Pas de logging sensible** : `password_key`, `recov_key`, `data_master_key` ne doivent jamais apparaître dans les logs (même en niveau debug)
6. **Audit des accès admin** : en mode Hybrid, chaque accès aux champs opérationnels par l'admin doit être logué (sans la donnée elle-même)
7. **Mise à jour régulière** : suivre les recommandations Argon2id pour ajuster `m`, `t`, `p` à mesure que le hardware progresse

Le non-respect d'une de ces règles dégrade significativement les garanties. La lib SelfDataGuard de référence fait respecter automatiquement les règles 1, 2, 5, 6 ; les règles 3, 4, 7 relèvent de la configuration de déploiement.

---

## 8. Limites et travaux futurs

### 8.1 Limites connues de v0.0.1

- **Recherche full-text** sur les champs chiffrés : impossible sans techniques avancées (chiffrement homomorphe partiel, secure indexes type CipherSweet)
- **Notifications transactionnelles asynchrones** : nécessitent l'admin_op_key (mode Hybrid) ou un re-design vers push (mode Full)
- **Migration de schéma** : si on ajoute un champ chiffré à un compte existant, il faut le populer pendant une session active de l'utilisateur
- **Performance** : chaque champ chiffré ajoute un overhead d'environ 50-100 µs sur GPU récent. Pour les requêtes listant plein de comptes, ce coût se cumule. À évaluer cas par cas.

### 8.2 Roadmap

- **v0.1.0** (Q3 2026) : implémentation de référence en PHP, ~600 lignes auditables, intégration trait Eloquent / Doctrine via adapter
- **v0.2.0** (Q4 2026) : extension blind index avancé pour searchable encryption, support multi-locataire (multi-tenant)
- **v0.3.0** (2027) : audit cryptographique communautaire formel, soumission ANSSI Visa de sécurité (industries@ssi.gouv.fr), publication d'un test vector pack

---

## 9. Licence et auteur

**AGPL-3.0-or-later**. Code, documentation et whitepapers publiés dans le dépôt `Pierroons/my-self`.

Toute version déployée publiquement, modifiée ou non, doit publier ses sources sous la même licence. Pas de capture SaaS possible.

Auteur : Pierroons. Coordonnées de contact accessibles via le dépôt public.

Les retours techniques, audits communautaires et critiques cryptographiques sont les bienvenus, en particulier de la part des chercheurs et praticiens ayant déjà intégré des architectures vault à clé encapsulée (Bitwarden, 1Password, ProtonMail, Cryptee).

---

*Document v0.0.1 — mai 2026. Ce whitepaper est un brouillon de spécification ouvert au commentaire avant l'implémentation de référence v0.1.0.*
