# SelfDataGuard — Whitepaper

**Protection des données personnelles au repos côté application**
*Dump ma base — et tu obtiens du bruit chiffré.*

*Édition du 1er octobre 2026 — décrit SelfDataGuard v0.5.0*

---

## Contexte

Le schéma est toujours le même : une faille d'autorisation donne accès aux comptes d'autrui, et ce qu'elle expose est **en clair dans la base**, sans chiffrement applicatif susceptible de le rendre inexploitable. État civil, coordonnées, statut d'identité : des données qui ne se changent pas comme un mot de passe.

Il y a là une question structurelle, complémentaire à celle qu'adresse SelfRecover : **comment rendre une fuite de base de données techniquement inutile pour l'attaquant**, indépendamment du flux d'authentification ?

SelfRecover protège l'**accès** au compte. SelfDataGuard protège les **données** stockées. Ensemble, les deux modules ferment la boucle : un attaquant qui contourne l'authentification (SelfRecover) trouve une base chiffrée (SelfDataGuard) ; un attaquant qui dump la base (SelfDataGuard) trouve des hashes Argon2id non réversibles (SelfRecover).

Ce whitepaper décrit le protocole SelfDataGuard. Il ne vise aucun acteur en particulier — c'est une proposition open-source, complémentaire à SelfRecover, que les opérateurs publics et privés peuvent auditer, intégrer ou contester librement.

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

Dans les cinq cas, un attaquant qui obtient un shell sur le serveur applicatif (RCE, escalade de privilèges, vol de clé SSH) obtient **simultanément** la base et la clé. Le chiffrement au repos n'apporte alors **aucune protection** — il protégeait uniquement contre un attaquant ayant le disque sans le serveur (cas de figure rare en pratique).

### 1.2 La vraie question

> Comment chiffrer les données d'un utilisateur de telle sorte que la clé n'existe que **lorsque cet utilisateur est présent**, et nulle part ailleurs en permanence ?

C'est exactement la question que résolvent Bitwarden (vault de mots de passe), 1Password, ProtonMail (boîte mail chiffrée). Leur architecture commune : **encapsulage de clé** (key wrapping) où la clé maîtresse de l'utilisateur n'existe en clair qu'en mémoire, le temps d'une session, et est encapsulée par un secret connu de lui seul (mot de passe maître).

SelfDataGuard porte cette architecture du **vault personnel** vers la **base utilisateurs d'une application multi-tenant** (e-commerce, SaaS, service public).

---

## 2. Le modèle SelfDataGuard

### 2.1 Principe fondamental

> *Pour chaque utilisateur, la base contient ses données chiffrées par une clé qui n'est jamais stockée en clair. Cette clé est encapsulée sous chacun de ses secrets : son mot de passe et, s'ils sont fournis, son mot mémorisé et sa passphrase SelfRecover. N'importe lequel la déballe. Au moment d'une session active, le serveur déballe la clé en RAM et l'utilise pour servir les requêtes ; à la déconnexion, la clé est purgée.*

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

Étape 3 — Dérivation des clés d'encapsulage (la deuxième et la troisième sont facultatives) :
    password_key     ← Argon2id(password, user_salt, m=65536, t=3, p=1)
    recov_key        ← Argon2id(mot_memorise, sha256(user_salt || "/dataguard")[0:16], m=65536, t=3, p=1)
    phrase_key       ← Argon2id(normalise(passphrase), sha256(user_salt || "/dataguard/passphrase")[0:16], m=65536, t=3, p=1)

Étape 4 — Encapsulage de la clé maîtresse par chacune de ces clés :
    wrap_pwd         ← XChaCha20-Poly1305-encrypt(data_master_key, key=password_key, nonce=random_192)
    wrap_recov       ← XChaCha20-Poly1305-encrypt(data_master_key, key=recov_key,    nonce=random_192)
    wrap_phrase      ← XChaCha20-Poly1305-encrypt(data_master_key, key=phrase_key,   nonce=random_192)

Étape 5 — Stockage en base (toutes les valeurs en clair listées ci-dessous) :
    user_id, user_salt, wrap_pwd, wrap_recov, wrap_admin (réservé, jamais écrit), wrap_phrase

Étape 6 — Chiffrement champ par champ des données personnelles :
    email_encrypted        ← XChaCha20-Poly1305-encrypt(email,    key=data_master_key, nonce=random_192)
    address_encrypted      ← XChaCha20-Poly1305-encrypt(address,  key=data_master_key, nonce=random_192)
    phone_encrypted        ← XChaCha20-Poly1305-encrypt(phone,    key=data_master_key, nonce=random_192)
    [...]

Étape 7 — Purge de data_master_key et des clés d'encapsulage de la mémoire serveur.
```

À la connexion par mot de passe (cas standard, ~99 % du temps) :

```
1. Le serveur reçoit (username, password) sur HTTPS
2. Il récupère user_salt et wrap_pwd depuis la base
3. password_key   ← Argon2id(password, user_salt, ...)
4. data_master_key ← XChaCha20-Poly1305-decrypt(wrap_pwd, key=password_key)
5. data_master_key est conservée dans la session (mémoire, jamais persistée)
6. À chaque requête : déchiffrement à la volée des champs personnels
7. Au logout : purge data_master_key
```

À la connexion par mot mémorisé (cas dégradé, mot de passe oublié) :

```
1. Le serveur reçoit (username, mot_memorise) sur HTTPS
2. Il récupère user_salt et wrap_recov depuis la base
3. recov_key       ← Argon2id(mot_memorise, sha256(user_salt || "/dataguard")[0:16])
4. data_master_key ← XChaCha20-Poly1305-decrypt(wrap_recov, key=recov_key)
5. L'utilisateur peut accéder à ses données et redéfinir un nouveau password
6. Régénération du wrap_pwd avec la nouvelle password_key (sans re-chiffrer les données)
```

Couplé à SelfRecover, ce « mot mémorisé » est l'empreinte que le navigateur calcule, jamais le mot lui-même (§3.1).

À la récupération par passphrase (niveau 1 de SelfRecover, une fois la passphrase acceptée) :

```
1. SelfRecover émet un nouveau mot de passe et une nouvelle passphrase ; l'ancienne est consommée
2. phrase_key      ← Argon2id(normalise(ancienne_passphrase), sha256(user_salt || "/dataguard/passphrase")[0:16])
3. data_master_key ← XChaCha20-Poly1305-decrypt(wrap_phrase, key=phrase_key)
4. wrap_pwd et wrap_phrase régénérés sur les nouveaux secrets, en une seule écriture, conditionnée à user_salt
```

`normalise` retire les espaces de bord et réduit toute suite d'espaces à un seul, exactement comme SelfRecover avant de comparer : la chaîne que SelfRecover accepte est celle qui ouvre le coffre.

### 2.3 Pourquoi cette architecture résiste à un dump

Un attaquant qui exfiltre la table utilisateurs obtient :

- `username` (en clair, identifiant)
- `user_salt` (en clair, équivalent à un identifiant)
- `wrap_pwd` (chiffré par `password_key`, qu'il ne connaît pas)
- `wrap_recov` (chiffré par `recov_key`, qu'il ne connaît pas)
- `wrap_phrase` (chiffré par `phrase_key`, qu'il ne connaît pas)
- `email_encrypted, address_encrypted, ...` (chiffrés par `data_master_key`, qu'il ne connaît pas)

Pour déchiffrer, il a trois voies :

1. **Bruteforcer le mot de passe** d'un utilisateur ciblé → coût Argon2id par tentative (~250 ms avec les paramètres recommandés ; 213,7 ms mesurées sur une machine de déploiement, cf. la note du point 2). Pour un mot de passe à 8 caractères aléatoires : ~10^14 tentatives × 0,25 s ≈ 8·10^5 années, un essai à la fois ; l'attaquant divise ce temps par le nombre d'essais qu'il mène en parallèle, chacun demandant 64 Mio de mémoire. Pour un mot de passe faible (`123456` ou similaire), ça reste faisable. **Appliqué depuis 0.3.0** : `UserVault::register()` refuse les mots de passe de moins de 12 octets (`PASSWORD_MIN_LEN`). ⚠️ La longueur n'est pas de l'entropie — douze lettres identiques franchissent cette barre. C'est un plancher contre le pire, pas une mesure. **Aucune blocklist n'est embarquée** : une version antérieure de ce paragraphe annonçait un refus par listes de breach qu'aucune ligne de code n'appliquait, et une promesse sans mécanisme derrière est pire que pas de promesse.

2. **Bruteforcer le mot mémorisé** → depuis 0.3.0, même coût que la voie mot de passe : Argon2id, ~250 ms par tentative.

   > **Corrigé le 06/09/2026.** Jusqu'à 0.3.0, `recov_key` était dérivée par un seul passage de HMAC-SHA256, sur l'hypothèse écrite ici même que « l'entropie du mot mémorisé doit être suffisante par construction », avec un plancher recommandé de 30 bits. Mesuré sur une machine de déploiement : **213,7 ms par tentative Argon2id contre 0,0027 ms par tentative HMAC, soit un facteur 78 100**. Les deux clés déballent la MÊME `data_master_key`, et `wrap_recov` s'attaque hors ligne, sans compteur d'essais : la sécurité de la paire était donc celle de la porte la moins chère, quel que soit le coût de l'autre. Un sel interdit le précalcul mais n'ajoute **aucun bit** contre une personne ciblée ; le tag AEAD dit à l'attaquant quelle tentative était la bonne, il ne le ralentit pas. Seul le coût par essai achète du temps, et il achète un facteur, jamais de l'entropie.

   ⚠️ **Nécessaire, pas suffisant — et la bibliothèque n'impose AUCUN plancher d'entropie.**
   Argon2id multiplie le coût par essai ; il n'ajoute pas d'entropie. Un mot faible reste un mot
   faible : ~13 bits de devinette plus ~13 bits de coût ajouté font ~26 bits de travail sur un
   dump, ce qui reste atteignable. La recommandation « ≥ 30 bits » des versions précédentes n'était
   appliquée par aucune ligne de code, et elle ne l'est toujours pas — la différence est qu'elle ne
   se présente plus comme une garantie.

   **Question ouverte, et elle est de nature produit, pas technique.** Un plancher assez haut pour
   compter (77,5 bits, soit six mots sur une liste diceware longue) mettrait fin au partage du mot
   mémorisé entre SelfRecover et le coffre (§3.1). Le mot mémorisé de
   SelfRecover est **choisi** et protégé par un second facteur et un compteur d'essais ; celui-ci
   est **seul** et s'attaque hors ligne. Les deux ne peuvent pas être soumis aux mêmes exigences.
   Trancher revient à choisir entre la commodité du couplage et la solidité de `wrap_recov` — c'est
   une décision de conception, pas un réglage, et elle n'est pas prise à ce jour.

   Il faut noter, enfin, qu'aucune inspection d'une chaîne ne dit si elle a été tirée au sort :
   « maison-maison-maison-maison-maison-maison » est composé de six mots d'une liste de 7 776 et ne
   vaut que 12,9 bits. Un plancher, le jour où il serait posé, devrait donc se prouver à la source
   et non se mesurer à l'arrivée.

3. **Bruteforcer la passphrase** → même coût Argon2id par tentative. Ici, l'entropie est connue, parce que SelfRecover la tire au sort : six mots d'une liste de 7 776, soit environ 77,5 bits. C'est la porte la plus solide par le calcul. Sa faiblesse est ailleurs : elle est écrite sur papier (§6.1).

Une fuite ne donne donc **rien d'exploitable directement**. Le coût de bruteforce est par utilisateur (impossible de bruteforcer la base entière en parallèle puisque chaque user a son propre `user_salt`).

---

## 3. Couplage avec SelfRecover

### 3.1 Les secrets partagés, des dérivations isolées

SelfRecover et SelfDataGuard utilisent **les mêmes secrets** côté utilisateur — le mot mémorisé et la passphrase du niveau 1 —, mais les dérivent vers des clés cryptographiques **strictement disjointes**.

Le mot mémorisé ne quitte jamais le navigateur. Celui-ci en calcule une empreinte, que le serveur reçoit, et c'est elle que l'intégrateur passe à SelfDataGuard :

```
mot_memorise (dans le navigateur seulement, jamais stocké)
    │
    └─ HMAC-SHA256(mot, domaine + "|v2" + sel du compte)            →  empreinte (reçue par le serveur)
           │
           ├─ Argon2id(empreinte), sel aléatoire de password_hash()     →  vérification SelfRecover
           └─ Argon2id(empreinte, sha256(user_salt+"/dataguard")[:16])  →  recov_key (SelfDataGuard)

passphrase (tirée au sort par SelfRecover, reçue en clair au niveau 1)
    │
    ├─ Argon2id(normalise(passphrase)), sel aléatoire                   →  vérification SelfRecover
    └─ Argon2id(normalise(passphrase), sha256(user_salt+"/dataguard/passphrase")[:16])  →  phrase_key
```

Passer l'empreinte plutôt que le mot ne coûte rien en sécurité : un attaquant qui devine le mot doit calculer le HMAC, puis l'Argon2id. C'est l'entropie du mot et le coût d'Argon2id qui protègent, pas la forme de la chaîne.

Propriétés cryptographiques :

- **Pas de crossover entre les stores** : la base de SelfRecover garde un Argon2id de l'empreinte sous un sel aléatoire, le coffre en garde un autre sous un sel dérivé de `user_salt`. Aucun des deux ne se déduit de l'autre : une fuite d'un côté n'ouvre pas l'autre, et chacun s'attaque à son propre coût Argon2id.
- ⚠️ **L'empreinte, elle, vaut un mot de passe pour le coffre.** Qui la tient ouvre `wrap_recov` : le serveur, au moment d'un niveau 2 par code, qui la reçoit. C'est le cas de tout secret reçu côté serveur en mode Lite (§4.1), mot de passe compris — un serveur compromis en service les voit tous passer.
- ⚠️ **Les deux chemins ne portent pas le même risque et ne se durcissent pas pareil.** La vérification SelfRecover contrôle un ACCÈS : un serveur compte les essais, et SelfRecover exige en plus un recovery code — deux facteurs. `recov_key` déchiffre des DONNÉES : elle s'attaque hors ligne sur un dump, à un seul facteur, sans compteur. Le même mot mémorisé ne peut donc pas être soumis aux mêmes exigences des deux côtés.
- **UX simplifiée** : aucun secret de plus à retenir ; chacun sert aux deux modules
- ⚠️ **La passphrase volée ouvre les deux** — le compte par le niveau 1, le coffre par `wrap_phrase` —, jusqu'à sa première utilisation légitime, qui la remplace des deux côtés

### 3.2 Le mot peut être régénéré indépendamment

Si l'utilisateur change son mot mémorisé (cf. règle SelfRecover : maximum 2-3 régénérations via mot de passe actuel), SelfDataGuard doit re-encapsuler la `data_master_key` avec la nouvelle `recov_key`. Ceci ne nécessite **pas** de re-chiffrer les données personnelles — seulement de recalculer un nouveau `wrap_recov`.

### 3.3 Cas d'usage : récupération combinée

Scénario : un utilisateur a perdu son mot de passe.

- **Sans SelfDataGuard** : SelfRecover lui permet de redéfinir un mot de passe. Mais il aurait pu, avec ses données personnelles en clair dans la base, perdre l'accès à ces données ? Non : la base était en clair, donc l'admin pouvait toujours les lui re-fournir.
- **Avec SelfDataGuard seul** (sans SelfRecover) : impossible, ses données sont chiffrées par sa `password_key` qu'il ne se rappelle plus.
- **Avec les deux ensemble** : il présente son *recovery code* papier **et** son mot mémorisé. SelfRecover vérifie les deux — le code localise le compte et porte la possession, le mot dérivé porte la connaissance — puis l'authentifie. SelfDataGuard, lui, n'a besoin que de l'empreinte du mot, que le serveur vient de recevoir : il déballe `wrap_recov`, puis re-scelle le coffre sur le mot de passe et la passphrase que SelfRecover vient d'émettre. L'utilisateur retrouve l'accès à son compte et la lisibilité de ses données dans le même passage.

C'est le principe des phrases de récupération des services chiffrés de bout en bout : un secret gardé hors ligne rend l'accès aux données quand le mot de passe est perdu.

### 3.4 Chaque récupération, et ce qu'en fait le coffre

Les récupérations SelfRecover remplacent des secrets ; aucune des deux bibliothèques n'appelle l'autre. C'est l'intégrateur qui re-scelle le coffre, **après** l'acceptation de la récupération, avec le secret que le serveur tient à ce moment-là :

| Récupération | Le serveur tient | SelfRecover remplace | SelfDataGuard |
|---|---|---|---|
| Niveau 1 — passphrase | l'ancienne passphrase | mot de passe et passphrase | `recover(Lock::Passphrase, ancienne, nouveau_mdp, nouvelle_passphrase)` |
| Niveau 2 — code + mot | l'empreinte du mot | mot de passe et passphrase | `recover(Lock::Memorized, empreinte, nouveau_mdp, nouvelle_passphrase)` |
| Niveau 2 — appareil | une signature | le mot de passe seul | rien à cet instant ; au prochain secret donné, `recover(serrure, secret, mdp_actuel)`, freiné comme la connexion |
| Niveau 3 — escalade humaine | aucun ancien secret | tout | `reEnroll(mdp, empreinte, passphrase)` : archive (§3.5) |

`recover()` déballe la clé, régénère `wrap_pwd` — et `wrap_phrase` si une passphrase neuve est donnée — et écrit le tout en une seule fois, à condition que le coffre porte toujours le même `user_salt`. Une requête qui tiendrait un coffre remplacé entre-temps échoue au lieu de l'écraser. Appelée seule, la méthode est un oracle Argon2id sans frein : c'est l'acceptation de SelfRecover, avec ses compteurs, qui la précède — et, pour le rattrapage après un niveau 2 par appareil, le frein de la connexion.

### 3.5 Niveau 3 : l'archive, pas la destruction

Au niveau 3, l'utilisateur n'a plus aucun ancien secret, et le coffre ne peut pas être re-scellé. Jusqu'à la 0.4.0, la seule issue était de le supprimer ; or un utilisateur peut retrouver plus tard un ancien papier ou un ancien mot.

`reEnroll()` met donc le coffre vivant de côté, **sans rien déchiffrer** — enveloppes, champs privés, séquestre —, et crée un coffre neuf pour le même compte, dans la même transaction. Les AAD ne liant que l'identifiant du compte, les anciennes enveloppes restent valides telles quelles.

- **Rouvrir** : `openArchive()` exige une ancienne serrure **et** une session sur le coffre actuel. Les anciens secrets sont précisément ceux qui ont pu fuir avant le niveau 3 : seuls, ils n'atteignent pas les anciennes données par le service — avec un dump, si (§6.1). La dérivation suit le profil Argon2id enregistré dans l'archive, parce qu'une archive ne se re-scelle pas quand le profil change.
- **Restaurer** : `readArchive()` rend les champs, que l'application réécrit dans le coffre neuf. Les index aveugles ne sont pas archivés — ils répondraient encore à des recherches d'égalité sur des données qui ne sont plus vivantes —, mais chaque champ garde la trace de son indexation.
- **Le séquestre** part avec l'archive. L'administrateur le rouvre comme celui d'un coffre vivant, par la même clé : rien de plus n'est exposé.
- **Détruire** : sur décision explicite seulement. Supprimer le compte ne supprime pas ses archives, sinon un niveau 3 frauduleux suivi d'une suppression effacerait celles du titulaire légitime.
- ⚠️ La serrure « mot mémorisé » d'une archive dépend du sel SelfRecover de l'époque, que le niveau 3 remplace. L'intégrateur le conserve — dans le coffre neuf, par exemple — si cette serrure doit rester utilisable. Les serrures mot de passe et passphrase n'en ont pas besoin.

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
- Toute la cryptographie est exécutée dans le navigateur, par libsodium compilé en WebAssembly : WebCrypto ne fournit ni Argon2id ni XChaCha20-Poly1305
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
| Dérivation depuis mot de passe | **Argon2id** (m=65536 KiB, t=3, p=1) | Memory-hard, résistant aux GPU et ASICs. Standard moderne (RFC 9106). ⚠️ `p=1` et non `p=4` : `sodium_crypto_pwhash` **n'expose pas** de paramètre de parallélisme — signature `length, password, salt, opslimit, memlimit, algo`. Les versions antérieures de ce tableau annonçaient un paramètre que l'API choisie ne peut pas porter |
| Dérivation depuis mot mémorisé | **Argon2id** (mêmes paramètres) | Même coût que la voie mot de passe, parce que les deux ouvrent la même clé de données et que l'ensemble ne vaut que sa porte la moins chère. Cf. §2.3 pour la mesure qui a motivé le changement |
| Dérivation depuis passphrase | **Argon2id** (mêmes paramètres), contexte `/dataguard/passphrase` | Même coût, pour la même raison. Entrée normalisée comme SelfRecover le fait avant de comparer |
| Chiffrement par enveloppe | **XChaCha20-Poly1305** | Chiffrement authentifié : ChaCha20-Poly1305 (RFC 8439) étendu à un nonce de 192 bits (draft-irtf-cfrg-xchacha). Calculé en logiciel, en temps constant, sur tout processeur. Les blobs écrits avant la 0.4.0 sont en AES-256-GCM et restent lisibles |
| Chiffrement de champs | **XChaCha20-Poly1305** avec nonce aléatoire 192 bits par champ | Idem. À 192 bits, un nonce tiré au hasard ne demande aucun compteur |
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
| Vol du papier de passphrase | La passphrase de niveau 1 d'un utilisateur | Le compte et les données de cet utilisateur, hors ligne aussi avec un dump, jusqu'à la première utilisation légitime de la passphrase, qui la remplace des deux côtés |
| Fuite d'un ancien secret avant un niveau 3 | Une serrure de l'archive | Rien par le service : l'archive ne s'ouvre que depuis une session sur le coffre actuel. Avec un dump, l'archive s'ouvre hors ligne par cette serrure |
| Coercition d'un admin | Force l'admin à fournir ses clés | Mode Lite : aucune clé permanente côté admin, donc rien. Mode Hybrid : champs opérationnels seulement. Mode Full : rien (l'admin n'a pas de clé) |

### 6.2 Adversaires hors-périmètre

Conformément aux bonnes pratiques recommandées par l'ANSSI en matière de transparence, SelfDataGuard déclare explicitement :

- **Compromission du poste utilisateur** (keylogger, info-stealer, RAT) : HORS PÉRIMÈTRE. Si l'utilisateur entre son password et son mot mémorisé sur une machine compromise, ses données de ce site sont exposées. Recommandation : Tails / Qubes pour les usages à forte exigence.
- **Compromission du navigateur** (extension malveillante, exploit 0-day) : HORS PÉRIMÈTRE en mode Full également. Les opérations crypto WebCrypto sont aussi sûres que le navigateur.
- **Cryptanalyse théorique de SHA-256, XChaCha20-Poly1305, AES-256-GCM, Argon2id** : HORS PÉRIMÈTRE. Migration cryptographique conforme aux recommandations ANSSI / NIST quand les algorithmes seront déclarés faibles.
- **Bruteforce d'un mot de passe faible** : HORS PÉRIMÈTRE. La lib doit imposer une politique de mot de passe minimale. Sans politique, le facteur le plus faible domine.
- **Déni de service** : HORS PÉRIMÈTRE. SelfDataGuard ne traite pas la disponibilité, seulement la confidentialité.

---

## 7. Règles de déploiement obligatoires

Pour qu'un déploiement SelfDataGuard apporte effectivement les garanties listées, il doit respecter :

1. **Politique de mot de passe** : minimum 12 octets, **appliqué par la lib** (`UserVault::PASSWORD_MIN_LEN`). Le refus par listes de breach reste à la charge de l'intégrateur — la lib n'embarque aucune liste et ne prétend plus le faire
2. **Politique de mot mémorisé** : **à la charge de l'intégrateur — la bibliothèque n'impose
   rien**. Elle a durci le coût par essai (Argon2id depuis 0.3.0) ; elle ne mesure pas l'entropie et
   ne prétend pas le faire. Un intégrateur qui branche `loginWithMemorized()` sur un mot choisi par
   l'utilisateur doit savoir que `wrap_recov` s'attaque alors hors ligne, sans compteur, sur ce seul
   secret. Cf. §2.3, question ouverte
3. **TLS obligatoire** : aucune dégradation HTTP autorisée (HSTS strict)
4. **Sessions courtes** : `data_master_key` purgée de la session après inactivité (15 min recommandé pour Hybrid, 5 min pour Full)
5. **Pas de logging sensible** : `password_key`, `recov_key`, `phrase_key`, `data_master_key` ne doivent jamais apparaître dans les logs (même en niveau debug)
6. **Audit des accès admin** : en mode Hybrid, chaque accès aux champs opérationnels par l'admin doit être logué (sans la donnée elle-même)
7. **Mise à jour régulière** : suivre les recommandations Argon2id pour ajuster `m` et `t` à mesure que le hardware progresse (`p` est fixé à 1, cf. §5). Le profil n'est pas rangé dans un coffre vivant : le changer exige de re-sceller chaque coffre vivant d'abord, et la bibliothèque n'en fournit pas l'outil. Une archive enregistre le profil en vigueur à son archivage, et se rouvre avec lui
8. **Re-scellement à chaque récupération** : `recover()` aux niveaux 1 et 2, **après** l'acceptation de SelfRecover et jamais avant ; le rattrapage après un niveau 2 par appareil et `openArchive()` freinés par l'intégrateur comme sa connexion ; `reEnroll()` au niveau 3

Le non-respect d'une de ces règles dégrade significativement les garanties. La bibliothèque de référence applique la règle 1, et la règle 5 pour ses propres traces d'exception (`#[\SensitiveParameter]`) ; les autres relèvent de l'intégrateur et de la configuration de déploiement.

---

## 8. Limites et travaux futurs

### 8.1 Limites connues

- **Recherche full-text** sur les champs chiffrés : impossible sans techniques avancées (chiffrement homomorphe partiel, secure indexes type CipherSweet)
- **Notifications transactionnelles asynchrones** : nécessitent l'admin_op_key (mode Hybrid) ou un re-design vers push (mode Full)
- **Migration de schéma** : si on ajoute un champ chiffré à un compte existant, il faut le populer pendant une session active de l'utilisateur
- **Performance** : le surcoût de chaque champ chiffré n'est pas mesuré à ce jour. Pour les requêtes qui listent beaucoup de comptes, il se cumule : à évaluer cas par cas.

### 8.2 Roadmap

- **v0.1.0** (livrée en bêta le 08/05/2026) : implémentation de référence en PHP, stockage SQLite derrière `StorageInterface` ; l'intégration Eloquent / Doctrine reste à écrire — le volume courant de la bibliothèque est donné par le README, qui se mesure à chaque édition
- **v0.2.0** (livrée le 21/08/2026, Q3) : compartiment escrow, cérémonie de clés, journal d'audit
- **v0.3.0** (livrée le 07/09/2026) : dérivation Argon2id du secret mémorisé, plancher de longueur du mot de passe appliqué en code
- **v0.4.0** (livrée le 26/09/2026) : XChaCha20-Poly1305 pour toute écriture, format de blob versionné (`SDG2.`), relecture des blobs AES-256-GCM par libsodium ou OpenSSL
- **v0.5.0** (01/10/2026) : troisième serrure (passphrase SelfRecover), `recover()` pour chaque chemin de récupération, archive au niveau 3, migration de schéma en place
- **v0.6.0** (à venir) : extension blind index avancé pour searchable encryption, support multi-locataire (multi-tenant)
- **v1.0.0** (2027) : audit cryptographique communautaire formel, soumission ANSSI Visa de sécurité (industries@ssi.gouv.fr), publication d'un test vector pack

---

## 9. Licence et auteur

**AGPL-3.0-or-later**. Code, documentation et whitepapers publiés dans le dépôt `Pierroons/my-self`.

Une version modifiée offerte à des utilisateurs à travers un réseau doit leur donner accès à son code source, sous la même licence (AGPL-3.0, article 13).

Auteur : Pierroons. Coordonnées de contact accessibles via le dépôt public.

Les retours techniques, audits communautaires et critiques cryptographiques sont les bienvenus, en particulier de la part des chercheurs et praticiens ayant déjà intégré des architectures vault à clé encapsulée (Bitwarden, 1Password, ProtonMail, Cryptee).

---

*Édition du 1er octobre 2026, alignée sur SelfDataGuard v0.5.0 : la spécification décrite ici est implémentée et testée de la v0.1.0 à la v0.5.0 (314 contrôles, 10 suites). Première édition : mai 2026. Les révisions successives se lisent dans l'historique git de ce fichier.*
