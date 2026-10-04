# MySelf — architecture actuelle (phase 0)

*3 octobre 2026 — 19:48 · état du dépôt au commit `1b91edf` ; SelfDataGuard, SelfRecover, leurs démos et
les constats 20 et 23 revus le 4 octobre 2026 au commit `4149238` ; Self-Right, la CI, le déploiement, l'intégration et SelfFarm Lite relus
par leurs mainteneurs au même commit ; constats 17, 18, 19 et 21 revus au commit `e97460e` ;
§9.7, faiblesses d'instance corrigées, ajouté le 4 octobre 2026*

> **Statut : relu.** Le 4 octobre 2026, le mainteneur de chaque module a relu, dans toutes les
> sections, ce qui touche son module. Un constat corrigé depuis porte 🟢.

## 0. Objet et méthode

Ce document est la phase 0 d'une proposition d'architecture microkernel, dont les invariants
sont repris au §10 : cartographier l'existant avant toute décision. Il recense, module par
module :
- les primitives cryptographiques et leurs paramètres ;
- les secrets, leur stockage et leur détenteur ;
- les flux et les frontières de confiance ;
- les dépendances entre modules ;
- les tests qui tiennent tout cela.

**Méthode.**
- Trois relevés indépendants, en lecture seule, sur le dépôt.
- Une revérification à la ligne des affirmations de sécurité retenues.
- Une relecture contradictoire du document entier contre le code.
- Une affirmation marquée **✔** a été relue dans le code le 3 octobre 2026. Les autres sont des
  relevés, sourcés à la ligne là où un `chemin:ligne` est cité, à confirmer par le mainteneur du
  module.
- Les chemins sont relatifs à la racine du dépôt.

**Hors champ :**
- l'instance servie : vhosts réels, environnements, droits sur disque, horaires et réglages des
  tâches planifiées (les tâches elles-mêmes, versionnées, sont cartographiées) ;
- `vendor/` et les binaires ;
- le dépôt voisin `selffarm-lite`, qui n'a aucun couplage de code avec celui-ci.

Les faiblesses propres aux instances en service ne sont pas publiées ici tant qu'elles sont
ouvertes : elles suivent la divulgation coordonnée de `SECURITY.md`. Corrigées et mesurées sur
l'instance, elles rejoignent le §9.7. Pour la
même raison, ce document ne détaille pas les faiblesses du lab, qui est une cible de red team
en cours.

---

## 1. Vue d'ensemble

| Module | Version | Nature | Langage | S'exécute dans | Porte des secrets d'utilisateur |
|---|---|---|---|---|---|
| SelfRecover | 0.9.0 | bibliothèque + client JS | PHP 8.1+, JS (WebCrypto) | processus PHP de l'intégrateur ; navigateur | oui |
| SelfDataGuard | 0.6.0 | bibliothèque | PHP 8.1+, libsodium | processus PHP de l'intégrateur ; CLI admin (séquestre) | oui |
| SelfRecover-LUKS | 0.6.2 | outillage système | sh, bash, C, Python | **initramfs, avant l'ouverture du disque** ; root | oui (passphrase de disque) |
| SelfModerate | 0.4.0 | bibliothèque | PHP 8.1+ | processus PHP de l'intégrateur | non |
| SelfJustice | 0.4.2 | service web + outils de collecte | PHP (API), Python, bash | PHP-FPM ; tâches planifiées | non (secrets d'exploitation seulement) |
| selfright-mcp | 0.4.6 | serveur MCP (stdio), client HTTP des API | Python | **poste de l'utilisateur** | non |
| SelfAct | 0.1.3 | service web + collecte | PHP, bash | PHP-FPM ; tâches planifiées | non |

Source des versions : `modules.json`.

**Démos et surfaces**, sans version propre :
- `demo/bi-self-duo` : SelfRecover et une modération maison ;
- `demo/lab` : le lab red team, qui consomme SelfRecover, SelfDataGuard et SelfModerate ;
- `demo/selfdataguard` ;
- `web/` et `deploy/`.

🔑 **SelfRecover et SelfDataGuard, les deux modules web qui portent des secrets d'utilisateur,
sont des bibliothèques embarquées dans le processus PHP de l'intégrateur, et non des services
isolés.** SelfRecover-LUKS, le troisième, s'exécute à part, dans l'initramfs. Chez un intégrateur
qui couple SelfRecover et SelfDataGuard, la frontière entre les deux est une frontière d'API dans
un même processus.

---

## 2. Qui exécute quoi

```
Navigateur ─────────── sr-derive.js (HMAC), sr-kdf.js (Argon2id + AES-GCM), clés d'appareil ECDSA
   │ HTTPS
Processus PHP de l'intégrateur ── SelfRecover (Argon2id des empreintes) ── SelfDataGuard (coffre)
   │                                               └─ même processus, même base SQLite possible
CLI admin ─────────── escrow-ceremony.php (séquestre)

Démarrage machine ─── initramfs : keyscript → selfrecover_derive_c (C) → cryptsetup
                      dropbear (option) : selfrecover-secours.sh en command= si l'opérateur a
                      fermé le shell d'amorçage, sinon shell root busybox
Après update-initramfs ─ garde-fou post-update (root)

Public anonyme ────── API SelfJustice (lecture seule hors dépôt de retours, CORS ouvert) et SelfAct (lecture seule)
Tâches planifiées ─── collecte DILA, CELLAR, Conseil de l'Europe, Judilibre, service-public.gouv.fr
Poste utilisateur ─── selfright-mcp (stdio) → API SelfJustice / SelfAct d'une instance choisie
```

---

## 3. Primitives cryptographiques

| Module | Usage | Primitive et paramètres | Contexte / séparation | Source |
|---|---|---|---|---|
| SelfRecover | empreintes stockées (mot de passe, passphrase, mot dérivé, codes) | `password_hash` Argon2id, m=64 Mio, t=4, p=2 ✔ | profil unique `Hashing::ARGON2` | `bi-self/selfrecover/src/Crypto/Hashing.php:25-29` |
| SelfRecover | mot mémorisé, dans le navigateur | HMAC-SHA256, clé = mot, message = matériau + `'\|v2'` + sel de compte | matériau obligatoire : `hostname` ou `label` | `bi-self/selfrecover/client/sr-derive.js:71, 147-154` |
| SelfRecover | index d'un code de niveau 2 | HMAC-SHA256(code normalisé, sel de déploiement) | aucun préfixe | `bi-self/selfrecover/src/Recovery/Recovery.php:410-413` |
| SelfRecover | sésame du niveau 3 | SHA-256 sans sel ✔, adapté à un sésame de 32 o aléatoires ; la bibliothèque n'impose pas cette entropie | — | `bi-self/selfrecover/src/Recovery/Escalade.php:194-197` |
| SelfRecover | facteur « cet appareil » | signature ECDSA (P-256 côté client ; la courbe n'est pas imposée côté serveur), défi de 32 o, 300 s, usage unique | — | `bi-self/selfrecover/src/Device/Device.php:112-120, 192-264` |
| SelfRecover | codes de niveau 2 | `random_bytes(5)` : 40 bits par code ✔ | — | `bi-self/selfrecover/src/Recovery/Recovery.php:378` |
| SelfRecover | passphrase de niveau 1 | 6 mots EFF (≈ 77,5 bits) ✔ ; normalisée avant hachage, comme dans SelfDataGuard | — | `bi-self/selfrecover/src/Recovery/Recovery.php:90, 117` |
| SelfRecover (client) | chiffrement local (`sr-kdf.js`) | Argon2id t=3, m=64 Mio, p=1 (implémentation JS propre au projet, `bi-self/selfrecover/client/argon2id.js`) → AES-256-GCM | blob versionné `{v:1, kdf}` portant son profil ; plancher contrôlé à la relecture | `bi-self/selfrecover/client/sr-kdf.js:211-235, 270-285` |
| SelfDataGuard | trois serrures du coffre | Argon2id t=3, m=64 Mio (libsodium, p=1), 32 o ✔ ; profil enregistré avec chaque coffre (`kdf_opslimit`, `kdf_memlimit`) depuis la 0.6.0 | sels : `user_salt` ; `sha256(user_salt‖"/dataguard")[:16]` ; `…"/dataguard/passphrase"` ✔ | `self-security/selfdataguard/src/Crypto/Primitives.php:53-63` ; `self-security/selfdataguard/src/Vault/VaultRecord.php` ; `self-security/selfdataguard/src/Vault/UserVault.php` |
| SelfDataGuard | enveloppes et champs | XChaCha20-Poly1305 ; AAD enveloppes = `userId` ✔ ; AAD champs = `userId\|nom` ✔ ; format `SDG2.` + base64(nonce‖chiffré‖tag) | — | `self-security/selfdataguard/src/Vault/UserVault.php:360` ; `self-security/selfdataguard/src/Fields/FieldCrypter.php:105-108` |
| SelfDataGuard | séquestre | `wrap_user` : XChaCha20 sous la clé maîtresse, AAD `userId\|escrow` ✔ ; `wrap_admin` : `crypto_box_seal(étiquette ‖ longueur ‖ userId ‖ clé)` vers la clé publique admin, lié à son compte depuis la 0.6.0 ✔ | — | `self-security/selfdataguard/src/Escrow/EscrowVault.php:50, 129-160, 196-207` |
| SelfDataGuard | index aveugle | HMAC-SHA256 à deux étages sous la `blindKey` | par nom de champ | `self-security/selfdataguard/src/Fields/BlindIndex.php:54-73` |
| SelfDataGuard | journal d'audit du séquestre | chaîne HMAC-SHA256 (symétrique) ; ancre `seq:hmac` facultative depuis la 0.6.0 | — | `self-security/selfdataguard/src/Escrow/AuditLog.php:112` |
| SelfRecover-LUKS | clé de slot | Argon2id t=3, m=64 Mio, **p=4**, 32 o, sortie hex ✔ | sel = `sha256("<sel>:<label>")[:16]`, label `disk` ✔ | `self-security/selfrecover-luks/selfrecover_derive.c:95, 122-128` |
| SelfRecover-LUKS | volumes secondaires | fichier-clé de 4096 o d'urandom | — | `self-security/selfrecover-luks/install.sh:475-481` |
| SelfJustice, SelfAct, MCP | — | aucune primitive de sécurité (SHA-256 n'y sert que d'identifiant) ; TLS client avec vérification par défaut (curl, urllib, httpx) | — | `self-right/selfjustice/api/api.php:864-869` |

**Les profils Argon2id coexistants : quatre jeux de paramètres**

| Profil | Paramètres | Où | Tenu par |
|---|---|---|---|
| Empreintes serveur (`password_hash`) | m=64 Mio, t=4, p=2 | SelfRecover ; le secret super-utilisateur du lab | `scripts/check-profil-unique.sh` (littéraux, hash `$argon2id$`, appels sans profil explicite) |
| Dérivation de clé (libsodium) et chiffrement client | m=64 Mio, t=3, p=1 | SelfDataGuard ; `sr-kdf.js` | `bi-self/selfrecover/tests/sanity_couplage_dataguard.php` |
| Disque | m=64 Mio, t=3, p=4 | SelfRecover-LUKS | vecteur de `self-security/selfrecover-luks/INSTALL.md`, rejoué en C et en Python |
| Sauvegarde du journal super-utilisateur | MODERATE de libsodium (256 Mio) | `demo/lab/selfrecover-su` | aucun contrôle |

---

## 4. Secrets

| Secret | Détenteur | Stockage | Protection | Module |
|---|---|---|---|---|
| Mot de passe de connexion | utilisateur ; vu en clair par le serveur à l'émission et à la soumission | `accounts.pw_hash` | Argon2id | SelfRecover |
| Passphrase de niveau 1 | utilisateur (papier) ; reçue en clair par le serveur à l'usage | `accounts.passphrase_hash` | Argon2id, consommée à l'usage | SelfRecover |
| Mot mémorisé | utilisateur ; **jamais transmis** : le serveur reçoit son empreinte HMAC | `accounts.recovery_hash` | Argon2id de l'empreinte | SelfRecover |
| Codes de niveau 2 | utilisateur (papier) | `recovery_codes` | HMAC (index) + Argon2id | SelfRecover |
| Sésame du niveau 3 | demandeur | `disputes.claim_hash` | SHA-256, vidé à la clôture | SelfRecover |
| Sel de déploiement | serveur | fourni par l'intégrateur, hors racine servie | permissions | SelfRecover |
| Clé maîtresse du coffre | **serveur**, qui la tire et la matérialise le temps d'une requête ; l'utilisateur détient les secrets qui la déballent | jamais en clair ; trois enveloppes en base | AEAD sous Argon2id | SelfDataGuard |
| `blindKey` | serveur | au choix de l'intégrateur (fichier, environnement) | — | SelfDataGuard |
| Clé du séquestre | utilisateur et admin | `wrap_user`, `wrap_admin` | AEAD ; boîte scellée | SelfDataGuard |
| Clé secrète admin | admin | fichier scellé par Argon2id d'une passphrase, format `v2:` qui porte son profil | plancher de 12 octets au scellement depuis la 0.6.0 ✔ | SelfDataGuard |
| Secret du journal d'audit | opérateur | variable d'environnement | longueur minimale | SelfDataGuard |
| Passphrase Recover-LUKS | opérateur de la machine | papier, gestionnaire | Argon2id puis KDF LUKS | SelfRecover-LUKS |
| Sel de déploiement LUKS | public mais irremplaçable | `/etc/selfkeyguard/selfrecover_salt`, **copié dans l'initrd sur `/boot` en clair** | aucune (non secret) ; copie hors site exigée | SelfRecover-LUKS |
| Clé d'hôte dropbear | machine | initrd sur `/boot` en clair | aucune | SelfRecover-LUKS (comportement Debian) |
| Secrets d'exploitation (clé PISTE, jeton ntfy, jeton du panneau de veille) | exploitant | fichiers et environnement de l'instance | permissions | SelfJustice, SelfAct |
| Jeton ntfy du MCP (facultatif) | utilisateur du MCP | environnement de son poste | — | MCP |
| Secrets d'instance des démos | serveur de la démo | fichiers de la démo | permissions | démos |

---

## 5. Flux principaux

**SelfRecover : quatre chemins vers le compte.**
```
Niveau 1   passphrase papier ──(clair)──> serveur : Argon2id ok → nouveau mot de passe + nouvelle passphrase
Niveau 2   code papier + empreinte HMAC du mot ──> serveur → nouveau mot de passe + nouvelle passphrase
Appareil   défi 32 o ──> signature ECDSA du téléphone ──> serveur → nouveau mot de passe
Niveau 3   dossier + sésame (SHA-256) ──> arbitrage humain ──> ré-enrôlement complet
```

**SelfDataGuard : le coffre suit chaque récupération.**
- `recover()` ré-enveloppe la clé maîtresse au niveau 1 et au niveau 2.
- Au niveau 3, `reEnroll()` archive l'ancien coffre sous ses anciennes serrures.
- Le couplage SelfRecover ↔ SelfDataGuard est tenu par des constantes et une normalisation de
  passphrase communes, gardées par `bi-self/selfrecover/tests/sanity_couplage_dataguard.php`. Ce
  n'est pas un appel de code : `bi-self/selfrecover/src` n'importe rien de SelfDataGuard.

**SelfRecover-LUKS : le démarrage.**
```
crypttab (embarquée dans l'image) → keyscript → askpass → selfrecover_derive_c → hex sur stdout
  → cryptsetup lit exactement keyfile-size octets quand la borne est posée, tout le flux sinon
  → slot recover
Repli : passphrase native (clavier, ou choix 2 de selfrecover-secours.sh par dropbear)
```

**SelfJustice, SelfAct, MCP.**
```
Sources publiques ──(tâches planifiées)──> bases SQLite, catalogue JSON ──> API en lecture seule ──> public, IA, MCP
                                                           └─> Judilibre en relais (clé serveur)
```

---

## 6. Frontières de confiance

1. **Navigateur → serveur (SelfRecover).**
   - Le mot mémorisé ne franchit pas la frontière, seule son empreinte la franchit.
   - La passphrase de niveau 1 et les mots de passe la franchissent en clair, sous TLS.
2. **Requête → clé maîtresse (SelfDataGuard).** Par contrat, la clé ne vit que le temps de la
   requête : `UnlockedVault` refuse la sérialisation. Ce n'est pas une contrainte :
   `getMasterKey()` est publique, et rien n'empêche un intégrateur de la conserver.
3. **Serveur → base.**
   - Les écritures sont conditionnelles (génération du coffre, révision) et transactionnelles.
   - Le sel du compte entre dans la dérivation : une enveloppe d'une autre génération, ou
     déplacée vers une autre serrure, ne s'ouvre pas.
   - En revanche, les AAD ne lient pas la **révision** : qui écrit en base peut remettre une
     enveloppe antérieure de la même génération (constat 3).
4. **Utilisateur → admin (séquestre).**
   - La clé du séquestre n'ouvre que le compartiment séquestre de l'utilisateur, jamais son
     coffre privé.
   - Le verrou « litige ouvert » n'est pas dans la bibliothèque : l'intégrateur le tient, en
     général dans une base que le même processus écrit. Qui écrit en base le contrôle.
5. **Intégrateur ↔ bibliothèque.** Restent à la charge de l'intégrateur :
   - le rôle d'arbitre du niveau 3 ;
   - l'authentification avant l'enrôlement d'un appareil ;
   - le freinage de tous les chemins de déverrouillage de SelfDataGuard.

   Les bibliothèques le documentent.
6. **`/boot` en clair → racine chiffrée (LUKS).**
   - Le keyscript, le binaire, le sel, la crypttab embarquée et la clé d'hôte dropbear sont lisibles.
   - Le contrôle d'empreinte des images s'exécute à chaud, donc après la saisie.
   - L'« evil maid » est déclarée hors périmètre.
   - **Le shell d'amorçage n'est fermé que si l'opérateur l'accepte** (`self-security/selfrecover-luks/install.sh:328-342`).
     Laissé ouvert, il donne un shell root avant le déverrouillage.
7. **Public → API Self-Right.** Lecture seule, sauf le dépôt anonyme de retours (`/api/feedback`), qui conserve un
   fichier côté serveur ; l'API SelfJustice est en CORS ouvert.
8. **Tâches planifiées → réseau.** Les sources publiques sont téléchargées et intégrées par des
   tâches planifiées de l'instance.
9. **API → modèle de langage (MCP, page de directives).** Du texte venu de sources tierces est
   relayé à des IA, par l'API à toute IA qui la lit comme par le MCP, sans filtrage.

---

## 7. Dépendances entre modules

| ↓ dépend de → | SelfRecover | SelfDataGuard | SelfModerate | SelfJustice | SelfAct |
|---|---|---|---|---|---|
| **SelfRecover** | — | constantes et normalisation partagées, gardées par un banc | — | — | — |
| **SelfDataGuard** | normalisation de passphrase identique | — | — | — | — |
| **SelfRecover-LUKS** | liste diceware (`bi-self/selfrecover/assets`) | — | — | — | — |
| **SelfAct** | — | — | — | état, statistiques, jeton ntfy, repli de chemins | — |
| **MCP** | — | — | — | API | API |
| **Lab** | bibliothèque + client JS | primitives, `AuditLog` | bibliothèque | — | — |
| **Duo** | bibliothèque + client JS | — | **non** (moteur propre, déclaré) | répertoire d'état partagé | — |

Les README de Self-Right disent que SelfAct et SelfJustice « n'échangent aucun appel », ce qui
est exact. Ils ne mentionnent pas que SelfAct lit l'état, les statistiques et le jeton ntfy de
SelfJustice, et se replie sur ses chemins.

---

## 8. Tests et vecteurs

- **Vecteurs de référence :**
  - dérivation du mot mémorisé : `bi-self/selfrecover/tests/vecteurs-derivation.json` ;
  - Argon2id produits par libsodium : `bi-self/selfrecover/tests/vecteurs-argon2.json` ;
  - XChaCha20, vecteur IETF : `self-security/selfdataguard/tests/sanity_primitives.php` ;
  - clé de slot LUKS, rejouée en C et en Python par
    `self-security/selfrecover-luks/tests/test_preuve_binaire_boot.sh`.
- Argon2id des trois serrures de SelfDataGuard : `self-security/selfdataguard/tests/vecteurs-argon2.json`,
  recalculés en CI par une seconde implémentation
  (`self-security/selfdataguard/tests/vecteurs_argon2.py`, argon2-cffi). Pas de
  coffre `SDG2.` figé.
- **CI, à chaque envoi** (`.github/workflows/structure.yml`) :
  - les bancs de SelfRecover, SelfDataGuard et SelfRecover-LUKS doivent afficher leur nombre
    exact de contrôles passés ;
  - une partie d'entre eux est doublée d'un canari : la CI plante un défaut connu et exige que le
    banc échoue ;
  - les bancs de Self-Right tournent sur leur code de sortie ;
  - SelfDataGuard tourne aussi sous PHP 8.1 et 8.2 ;
  - des contrôles structurels bloquent l'envoi comme les bancs : chemins cités
    (`scripts/check-paths.sh`), profil de hachage défini une seule fois
    (`scripts/check-profil-unique.sh`), gabarits de vhost (`scripts/check-vhost.sh`, sur un nginx
    installé par le job), liens vers les bibliothèques (`scripts/check-liens-bibliotheque.sh`),
    plancher des secrets de déploiement (`scripts/check-plancher-secret.sh`), porteurs de version
    (`scripts/check-versions.sh`).
- **Autres workflows :** `.github/workflows/gitleaks.yml` et `.github/workflows/trivy.yml` à chaque
  envoi ; `.github/workflows/suivi.yml` aux envois sur `main` et `dev`, et chaque jour : versions
  annoncées et leurs tags, publications, version du dépôt voisin citée par les README.
- **Hors CI :**
  - l'audit OPSEC complet, dont les motifs vivent hors dépôt, et l'écart dépôt/instance tournent
    chaque semaine sur une tâche planifiée, contre la branche publiée ;
  - les autres sondes qui demandent l'instance (fraîcheur, surface servie) ; le code de la sonde de
    fraîcheur est, lui, éprouvé en CI ;
  - une vraie construction d'initramfs, et l'installateur LUKS de bout en bout.

---

## 9. Constats

Ce sont des limites de conception et des écarts de documentation. Les exploiter suppose un accès
en écriture à la base, un accès local, ou rien du tout quand il s'agit de documentation. **✔** :
revérifié à la ligne. Aucun n'est corrigé par ce document. **🟢 corrigé** : fermé depuis, avec la
version qui le ferme ; le détail est dans le CHANGELOG racine. Le §9.7 range à part les faiblesses
d'instance corrigées.

### 9.1 Séparation des domaines et cryptographie

1. 🟢 **SelfDataGuard — un nom de champ pouvait rejoindre le contexte du séquestre.** Corrigé en
   0.6.0 : le nom `escrow` est réservé ; `setFields()` le refuse, et `getFields()` le refuse par son
   nom ou le laisse de côté sans le déchiffrer
   (`self-security/selfdataguard/src/Fields/FieldCrypter.php:112`).
2. 🟢 **SelfDataGuard — `wrap_admin` n'était lié à aucun utilisateur.** Corrigé en 0.6.0 : il nomme
   son compte, et un échange entre comptes est refusé à l'ouverture. Un `wrap_admin` d'avant reste
   lu ; `rebindEscrowAdmin()` le rescelle. Le contenu du séquestre n'est pas authentifié pour autant
   contre qui écrit en base.
3. **SelfDataGuard — les AAD ne lient pas la révision.** Qui écrit en base peut remettre une
   enveloppe antérieure de la même génération. Exemple : le titulaire change un mot de passe qui
   a fui ; l'ancien `wrap_pwd`, remis en place, rouvre le coffre avec le mot de passe compromis.
   Même chose pour une serrure retirée. **Documenté comme limite en 0.6.0** (whitepaper §8.1) : la
   parade est l'intégrité de la base, pas le coffre.
4. 🟢 **SelfDataGuard — ni le coffre vivant ni la clé admin scellée n'enregistraient leur profil
   Argon2id.** Corrigé en 0.6.0 : chaque coffre et la clé admin scellée (`v2:`) portent le leur, et
   `VaultRecord` l'exige. Ce qu'un intégrateur dérive lui-même par `Primitives` n'en enregistre
   toujours pas.
5. **Quatre jeux de paramètres Argon2id coexistent** (§3). Trois sont tenus par un contrôle ; le
   profil MODERATE de la sauvegarde super-utilisateur ne l'est pas.
6. **SelfRecover-LUKS — l'enrôlement et le démarrage ne lisent pas la passphrase de la même
   façon.** L'enrôlement passe par `read`, qui retire les blancs de bord
   (`self-security/selfrecover-luks/setup-add-selfrecover-slot.sh:79`). Le démarrage passe par
   `askpass`, qui les garde (`self-security/selfrecover-luks/selfrecover-keyscript.sh:30`). Une
   passphrase saisie avec une espace finale donne deux clés différentes : le slot Recover refuse,
   et seul le repli sur la passphrase native reste. Les modules web, eux, normalisent tous deux à
   l'identique.
7. 🟢 **SelfDataGuard — pas de vecteur Argon2id figé.** Corrigé en 0.6.0 : vecteurs figés pour les
   trois serrures, recalculés en CI par une seconde implémentation.

### 9.2 Secrets et journaux

8. 🟢 **La passphrase admin du séquestre n'avait pas de plancher.** Corrigé en 0.6.0 : 12 octets au
   scellement (`self-security/selfdataguard/src/Escrow/AdminKey.php:61`) ; une clé scellée plus tôt
   s'ouvre encore.
9. 🟢 **Le journal d'audit du séquestre n'était pas ancré.** Corrigé en 0.6.0 : `verify-log` affiche
   la tête `seq:hmac`, et `--ancre` refuse ensuite un journal tronqué, réécrit ou supprimé
   (`self-security/selfdataguard/src/Escrow/AuditLog.php:112`). L'ancrage reste un geste de
   l'opérateur, et le HMAC reste symétrique : le détenteur du secret forge au-delà de la dernière
   ancre.
10. **Les secrets d'exploitation de Self-Right se chargent selon plusieurs conventions.**
    - Le jeton ntfy en a trois : un fichier pour les quatre pilotes de collecte, une variable
      d'environnement pour la sonde de fraîcheur, une autre pour le MCP, côté poste.
    - La clé PISTE a trois emplacements : une variable d'environnement côté API, et un fichier
      côté collecte dont le défaut diffère entre le script et son pilote.

### 9.3 Points d'entrée

11. **SelfRecover — `ouvrirDefi` insère un défi par appel, sans authentification ni frein**
    (`bi-self/selfrecover/src/Device/Device.php:192-201`). La table grossit pendant 300 s. Les
    méthodes protégées par un sésame ou une signature n'appellent pas `verifierOrigine()`, et c'est
    voulu.
12. ✔ **SelfRecover — une trace du niveau 3 porte le nom du compte en clair**
    (`bi-self/selfrecover/src/Recovery/Escalade.php:383`). Elle n'est écrite qu'après un sésame
    valide et rien ne la relit. Le niveau 1 écrit déjà le nom en clair dans la même table.
13. **SelfDataGuard — aucun chemin de déverrouillage n'a de frein**, connexion comprise. Chaque
    appel est un essai Argon2id, à freiner par l'intégrateur (c'est documenté). La démo publique, elle,
    donne depuis le 04/10/2026 une base à chaque visiteur et passe derrière un frein nginx.
14. **Deux voies d'injection d'instructions vers des IA tierces** : la page de directives de
    SelfJustice, et le texte des sources, relayé par l'API à toute IA qui la lit comme par le MCP.

### 9.4 Chaîne d'approvisionnement

15. ✔ **Les binaires gitleaks et trivy sont téléchargés en CI sans vérification de somme.** Leurs
    versions sont épinglées (`.github/workflows/gitleaks.yml:53`, `.github/workflows/trivy.yml:43`) :
    le risque est la substitution de l'archive, pas la dérive de version.
16. **Les dépendances ne sont pas verrouillées pour l'utilisateur.**
    - Les bibliothèques PHP n'ont aucune dépendance tierce à l'exécution : rien à verrouiller de
      ce côté.
    - Le serveur MCP déclare `mcp` et `httpx` en planchers, alors que la CI les épingle.
    - `openpgp.min.mjs` (vendorisé) et `bi-self/selfrecover/client/argon2id.js` échappent à trivy.

### 9.5 Documentation et code qui divergent

17. 🟢 `bi-self/selfrecover/SECURITY.md` annonçait 0.6.x comme version prise en charge. Il ne porte
    plus de numéro de version : la ligne publiée prise en charge est la dernière mineure.
18. 🟢 `bi-self/selfrecover/docs/architecture.md` décrivait un signal passif au niveau 3 que le code
    n'a pas. Le schéma montre le faisceau tel que l'assemble `Escalade`.
19. 🟢 Le whitepaper de SelfRecover-LUKS était en 0.5.0 quand le module était en 0.6.2. Il suit
    le module, et `scripts/check-versions.sh` le compte désormais parmi les porteurs de version,
    comme les deux whitepapers de SelfRecover.
20. 🟢 Les deux gabarits nginx de SelfJustice divergeaient (version de PHP, TLS). Le second,
    `nginx-api-patch.conf`, est retiré : il ne reste que `deploy/selfjustice/nginx.conf`, qui décrit
    le vhost servi au domaine près.
21. 🟢 Les README de Self-Right ne mentionnaient pas le couplage de SelfAct à SelfJustice par état
    partagé (§7). Ils le décrivent.

### 9.6 Doublons

22. La dérivation `srDerive` est réécrite en PHP dans `demo/lab/lib/derive_cli.php`, dont
    l'en-tête avertit qu'elle doit suivre `sr-derive.js`. Le banc `demo/lab/tests/sanity_derive_cli.php`
    lui fait rejouer les vecteurs figés de la bibliothèque, et un canari le tient, mais le contrôle
    des réimplémentations (`scripts/check-liens-bibliotheque.sh`) ne reconnaît que l'idiome WebCrypto.
23. 🟢 Le faux sel anti-oracle était écrit deux fois, avec deux formules (duo et lab). Depuis
    SelfRecover 0.9.0, la bibliothèque le fournit (`Recovery::selDeDerivation`) et la démo duo
    l'emploie pour le chemin « code ». Le lab garde sa copie, de même formule, jusqu'à la fin de la
    saison du CTF.
24. La démo `bi-self-duo` réimplémente la modération sans consommer SelfModerate. C'est déclaré.
25. `alerter()` existe en trois copies dans les outils de SelfJustice, plus une dans SelfAct. La
    substitution de domaine vit dans les trois `deploy/*/deploy.sh`. Les motifs User-Agent des IA
    sont écrits quatre fois.

### 9.7 Faiblesses d'instance corrigées

Relevées sur les instances en service, tenues privées tant qu'elles étaient ouvertes, puis
corrigées et mesurées sur l'instance le 4 octobre 2026. Celles qui tenaient au code versionné
valent pour toute instance installée depuis un `main` antérieur : la mettre à jour les ferme.

26. 🟢 **La démo SelfDataGuard n'avait qu'une base pour tous ses visiteurs.** Chacun voyait les
    coffres des autres, testait leurs index aveugles et essayait leurs mots de passe, sans frein,
    alors que chaque essai coûte un Argon2id mémoire-dur. Désormais : une base par visiteur, créée
    par une inscription valide seulement et effacée 30 minutes après sa dernière action, et une
    limite de débit nginx devant la démo (`demo/selfdataguard/`, `deploy/selfdataguard/`).
27. 🟢 **Le jeton du panneau de veille de SelfJustice circulait dans l'URL**, donc dans les journaux
    d'accès, qu'un outil recopiait là où d'autres comptes de la machine pouvaient les lire. Le
    panneau n'écrit plus de journal d'accès, les copies ne sont lisibles que par le service, et le
    jeton a été changé (`deploy/selfjustice/nginx.conf`, `self-right/selfjustice/tools/admin_feed.sh`).
28. 🟢 **Un fichier de débogage du même panneau, lisible par tous les comptes de la machine,
    portait un jeton en clair.** Supprimé ; le jeton a été changé.
29. 🟢 **Le dépôt anonyme de retours de SelfJustice n'avait ni limite de débit ni quota.** Une
    limite nginx le freine, et un quota de stockage le borne : au-delà, le dépôt est refusé sans
    rien écrire (`self-right/selfjustice/api/quota_feedback.php`).
30. 🟢 **L'en-tête `Host` du client était reflété dans des réponses mises en cache** par SelfJustice
    et SelfAct. Les URL rendues partent de `SELFJUSTICE_BASE_URL`, sinon du nom que nginx fixe
    (`self-right/selfjustice/api/api.php`, `self-right/selfact/api/find.php`). L'exploitation
    supposait un cache partagé qui n'aurait pas mis `Host` dans sa clé.
31. 🟢 **Deux scripts de collecte de SelfAct, sans garde, vivaient dans le répertoire de l'API
    servie.** Le vhost ne les joignait pas ; ils refusent désormais de tourner hors de la ligne de
    commande (`self-right/selfact/api/scraper.php`, `self-right/selfact/api/reclassify.php`).
32. 🟢 **La collecte du corpus européen contactait CELLAR en HTTP**, et deux services de collecte
    pouvaient écrire dans le répertoire de leur propre code. CELLAR passe en HTTPS
    (`self-right/selfjustice/tools/build_eu_db.py`) ; ces services n'écrivent plus que dans leur
    répertoire de données, et la sonde de fraîcheur est durcie comme eux (`deploy/selfjustice/`).
33. 🟢 **Les routes d'administration et de SelfAct ne vivaient que dans le vhost de l'instance** :
    le gabarit versionné ne décrivait pas ce qui était servi. Il le décrit, au domaine près
    (`deploy/selfjustice/nginx.conf` ; voir aussi le constat 20).

---

## 10. La proposition microkernel face à l'existant

| Invariant proposé | État actuel | Où |
|---|---|---|
| 1. Aucun privilège implicite | **non** : les bibliothèques partagent le processus de l'intégrateur | §1, §2 |
| 2. Capacité limitée à un contexte et une ressource | **en partie** : contextes de dérivation et AAD par utilisateur, mais AAD sans révision (le nom de champ qui rejoignait le séquestre est fermé en 0.6.0) | §3, constats 1 à 3 |
| 3. Les secrets restent chez leur propriétaire | **en partie** : la clé maîtresse ne vit que pendant la requête, mais dans la mémoire de l'intégrateur, et SelfDataGuard reçoit le mot de passe en clair pour dériver côté serveur | §6 (point 2) |
| 4. Compromettre un module ne livre pas les secrets d'un autre | **non** dans un même processus ; **oui** entre LUKS et le web, qui n'ont aucun couplage cryptographique | §7 |
| 5. Primitive remplaçable sans réécrire les applications | **en partie** : les empreintes, les blobs client, les archives, le coffre vivant et la clé admin scellée portent leur profil (ces deux derniers depuis la 0.6.0) ; ce qu'un intégrateur dérive lui-même par `Primitives` non | constat 4 |
| 6. Une erreur de politique ne devient pas une autorisation | **en partie** : refus en cas de doute dans LUKS et dans les écritures conditionnelles ; le verrou de litige est tenu par l'intégrateur, hors de la bibliothèque ; certaines expositions ne tiennent qu'à la configuration de l'instance | §6 (point 4) |
| 7. Audit sans contenu sensible | **en partie** : les journaux chaînés n'écrivent pas de secret, mais une trace porte un nom de compte ; le journal du séquestre s'ancre depuis la 0.6.0, par un geste de l'opérateur | constats 9, 12 |
| 8. Aucune logique métier dans le noyau | sans objet : il n'y a pas de noyau | — |
| 9. Chaque augmentation du TCB est justifiée | non formalisé | — |
| 10. Une abstraction n'est pas une frontière | **non** : les frontières entre modules sont des API, dans un même processus | §1 |
