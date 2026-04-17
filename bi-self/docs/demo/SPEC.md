# Bi-Self Demo — Spécification UX & technique

> Live interactive demo for SelfRecover and SelfModerate, hosted at
> `https://bi-self.my-self.fr`, with split-screen frontend / backend
> so visitors watch the protocol execute in real time on their own
> input.

## 1. Objectifs

- **Transparence radicale** : l'utilisateur voit *côté frontend* l'app qu'il manipule, *côté backend* les logs techniques (HMAC, bcrypt, SQL, etc.) déroulés en temps réel.
- **Pédagogique** : chaque étape est expliquée (mode tuto guidé) ou explorable librement (mode playground).
- **Authentique** : pas de script hardcodé — chaque action déclenche un vrai backend isolé par session. Ce que l'utilisateur voit est vraiment ce qui se passe.
- **Sûr** : aucun débordement possible du sandbox vers la prod. Rate-limit strict, bans IP, sessions éphémères.

## 2. Architecture technique

### 2.1 Endpoints

Tout vit sous `bi-self.my-self.fr` :

| Route | Rôle |
|---|---|
| `/` | Landing binôme : manifeste + choix SelfRecover / SelfModerate |
| `/recover` | Démo SelfRecover (page complète split-screen) |
| `/moderate` | Démo SelfModerate (page complète split-screen) |
| `/demo/api/session` (POST) | Créer une session sandbox |
| `/demo/api/session/{id}/register` (POST) | [SelfRecover] Inscription |
| `/demo/api/session/{id}/login` (POST) | [SelfRecover] Login |
| `/demo/api/session/{id}/recover-l1` (POST) | [SelfRecover] Recovery via passphrase |
| `/demo/api/session/{id}/recover-l2` (POST) | [SelfRecover] Recovery via mot + HMAC |
| `/demo/api/session/{id}/phishing-sim` (POST) | [SelfRecover] Simulation anti-phishing |
| `/demo/api/session/{id}/vote` (POST) | [SelfModerate] Vote sur un user |
| `/demo/api/session/{id}/tick` (POST) | [SelfModerate] Avancer le temps simulé |
| `/demo/api/session/{id}/attempt-farming` (POST) | [SelfModerate] Tenter upvote farming |
| `/demo/api/session/{id}/events` (GET, SSE) | Stream des logs backend |
| `/demo/api/session/{id}/code` (GET) | Extrait du code source censuré (open book) |
| `/bypass/{token}` | Pose le cookie bypass rate-limit |

### 2.2 Session sandbox

Chaque session obtient :
- Un **UUID v4** en cookie `sj_demo_session` (HttpOnly, Secure, SameSite=Lax)
- Un dossier isolé `/var/lib/selfjustice/demo-sessions/{uuid}/`
- Une base SQLite propre (`demo.sqlite`) avec schéma pré-créé
- Un fichier `log.jsonl` (une ligne JSON par événement, tailé par SSE)
- Un fichier `meta.json` (timestamp création, IP, compteurs)

TTL : **30 minutes**. Passé ce délai, le dossier est purgé par cron (`cleanup_demo_sessions.sh`, toutes les 5 minutes).

### 2.3 Rate-limit & bans

Géré par PHP + CrowdSec :
- **Max 10 sessions concurrentes** totales (vérif atomique via compteur dans `/var/lib/selfjustice/demo-sessions/.active`)
- **Max 3 sessions par IP / heure** — 4ᵉ session = avertissement visuel en gros rouge
- **6ᵉ session** dans l'heure = signalement CrowdSec custom scenario → ban IP 30 jours au niveau nginx
- **Max 50 actions par session** (register, login, vote, etc.) — au-delà, 429 + message *"Démo saturée, recharge pour une nouvelle session"*
- **LAN `192.168.1.0/24` whitelisted** systématiquement (skip rate-limit)
- **Cookie `sj_bypass`** présent (via `/bypass/{token}`) skip rate-limit (pour accès depuis 4G, ailleurs, etc.)

### 2.4 Logs — format et stream

Chaque événement loggé dans `log.jsonl` :
```json
{"ts":"2026-04-17T18:42:15.123Z","level":"info","step":"register","msg":"POST /register received","ctx":{"username":"alice"}}
{"ts":"2026-04-17T18:42:15.145Z","level":"info","step":"register","msg":"HMAC-SHA256 derivation","ctx":{"input":"alice","output":"4e7a9f...truncated"}}
{"ts":"2026-04-17T18:42:15.612Z","level":"info","step":"register","msg":"bcrypt(derived_key, cost=12)","ctx":{"duration_ms":467}}
{"ts":"2026-04-17T18:42:15.620Z","level":"info","step":"register","msg":"INSERT INTO accounts","ctx":{"id":1}}
{"ts":"2026-04-17T18:42:15.625Z","level":"success","step":"register","msg":"HTTP 201","ctx":{}}
```

Le frontend ouvre `GET /demo/api/session/{id}/events` (SSE) et affiche chaque ligne comme un bloc coloré dans la console backend. `level` détermine la couleur (info = gris, success = vert, warning = jaune, error = rouge, crypto = bleu).

Les secrets serveur sont **systématiquement censurés** avant envoi :
- `$site_salt` → `[REDACTED — set at install]`
- `/var/lib/selfjustice/demo-sessions/abc.../` → `{session_dir}/`
- chemins contenant `token.txt`, `.env`, etc.

## 3. UX — Split-screen

### 3.1 Layout desktop (≥ 1024 px)

```
┌──────────────────────────────────┬──────────────────────────────────┐
│                                  │  BACKEND · session ac3f…         │
│  SelfRecover Demo                │  ─────────────────────────────   │
│                                  │  18:42:15  session.open          │
│  [ mode : Tuto guidé ▼ ]         │  18:42:16  POST /register         │
│                                  │  18:42:16  → username: alice      │
│  Étape 1/5 — Inscription         │  18:42:16  HMAC-SHA256("alice",   │
│                                  │              domain+salt)          │
│  Identifiant : [ alice       ]   │  18:42:16  → derived_key = 4e7a…  │
│  Password   : XyZ!7aB2q  📋       │  18:42:16  bcrypt(key, cost=12)   │
│  Passphrase : mower blue plume   │  18:42:16  → 467 ms              │
│               quiet  📋          │  18:42:16  INSERT INTO accounts    │
│                                  │  18:42:16  HTTP 201 Created        │
│  [ Valider cette étape → ]       │                                    │
│                                  │                                    │
│  Encart pédago :                 │                                    │
│  "Ce qu'il se passe ici : ton    │                                    │
│  mot de récupération n'est       │                                    │
│  jamais envoyé brut au serveur.  │                                    │
│  Le navigateur le transforme…"   │                                    │
│                                  │                                    │
│  [⌛ 27:13 restant]   [↻ Nouvelle] │  [▶ 1×] [📋 Voir code] [↓ Export] │
└──────────────────────────────────┴──────────────────────────────────┘
```

### 3.2 Layout mobile (< 1024 px)

Stack vertical. Backend en accordéon **fermé par défaut** sous un bandeau :

```
┌────────────────────────────────┐
│  SelfRecover Demo              │
│  [ mode : Tuto guidé ▼ ]       │
│                                │
│  Étape 1/5 — Inscription       │
│  …                             │
│  [ Valider →                ]  │
│                                │
├────────────────────────────────┤
│ ▶ Voir ce qui se passe côté    │
│   serveur (5 lignes)           │
└────────────────────────────────┘
```

L'accordéon s'ouvre en tap → logs streamés dedans, scrollable indépendamment. Badge rouge avec compteur quand de nouveaux logs arrivent pendant qu'il est fermé.

### 3.3 Panneau "Mes credentials de session" (sticky)

En bas à droite, toujours visible. Contient pour SelfRecover :
- Identifiant choisi
- Password généré (masqué par défaut, 👁 pour voir, 📋 pour copier)
- Passphrase diceware (idem)
- Recovery word si saisi par l'user (idem)
- Site salt affiché dans sa forme censurée `[REDACTED]` (pédagogique — montre qu'il existe mais qu'on ne le voit pas)
- Timer session `⌛ 27:13 restant`
- Bouton `↻ Nouvelle session`

Sur mobile : icône 🔑 qui ouvre un bottom-sheet avec les credentials.

### 3.4 Mode Tuto vs Playground

**Sélecteur** en haut de la page avec radio buttons :

| Tuto guidé | Playground libre |
|---|---|
| Parcours linéaire, bouton `Valider cette étape →` à chaque passage | UI complète disponible, l'user clique librement |
| Encart pédago sous l'UI active (explique ce qu'il se passe côté crypto) | Pas d'encart — logs bruts suffisent |
| Encadré actuel en surbrillance bleu | UI normale |
| Durée lecture : 10-15 min | Durée variable 5-30 min |
| Cible : débutant, journaliste, étudiant, curieux | Cible : dev qui veut vérifier |

L'user peut basculer entre les 2 à tout moment sans perdre sa session.

### 3.5 Bouton "Voir le code" (open book)

Ouvre un panneau latéral **à droite** avec l'extrait du fichier PHP correspondant à l'étape actuelle. Secrets censurés. Scroll synchronisé avec l'exécution — la ligne qui vient de s'exécuter est surlignée.

Exemple lors d'un register :
```php
// selfrecover.php — lignes 42-58
function register(string $username, string $password, string $word): int {
    // [REDACTED] site salt load
    $site_salt = [REDACTED];
    $domain = $_SERVER['HTTP_HOST'];

→   $derived_key = hash_hmac('sha256', $word, $domain . $site_salt);   // ← ligne surlignée
    $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $recovery_hash = password_hash($derived_key, PASSWORD_BCRYPT, ['cost' => 12]);

    $stmt = $this->db->prepare('INSERT INTO accounts(username, pw_hash, recovery_hash) VALUES (?, ?, ?)');
    $stmt->execute([$username, $password_hash, $recovery_hash]);

    return (int) $this->db->lastInsertRowID();
}
```

Bouton fermeture ou `✕`. Code en lecture seule, pas d'édition.

## 4. Scénarios SelfRecover

### 4.1 Mode Tuto — 5 étapes

**Étape 1/5 — Inscription**
- Input user : identifiant (libre, validé `^[a-z0-9]{3,20}$`)
- Généré serveur : password random (16 chars alphanum+symboles), passphrase diceware (4 mots EFF)
- Opération : HMAC-SHA256 côté client, bcrypt cost=12 côté serveur, INSERT SQLite
- Encart pédago : *"Ton mot de récupération 'diceware' sera affiché une fois, tu as 15 min pour le noter en lieu sûr. Le serveur ne le garde pas en clair."*

**Étape 2/5 — Login normal**
- Input user : identifiant + password (copiés depuis l'étape 1)
- Opération : bcrypt_verify côté serveur, session ouverte, cookie posé
- Encart pédago : *"Le login est ce qu'il y a de plus banal. Aucune magie. Ce qui compte c'est ce qui va suivre."*

**Étape 3/5 — Recovery L1 (passphrase)**
- Action : "J'ai oublié mon mot de passe" → saisie passphrase diceware
- Opération : bcrypt_verify contre `pass_hash`, nouveau password généré
- Encart pédago : *"La passphrase est comme un sceau de secours : connue de toi seul, elle rouvre ton compte sans email."*

**Étape 4/5 — Recovery L2 (mot de récupération)**
- Action : "J'ai oublié aussi ma passphrase" → saisie **libre** d'un mot de récupération
- Opération : HMAC-SHA256 côté client explicitement affiché (*"client-side: HMAC-SHA256('tontexte', domaine+salt) = 4e7a…"*), bcrypt_verify côté serveur, recovery confirmé
- Encart pédago : *"Regarde bien : ton mot 'xxx' n'est JAMAIS envoyé au serveur. Seul son HMAC dérivé l'est."*

**Étape 5/5 — Anti-phishing natif**
- Action : l'UI simule un site `phishing-my-self-fr.local` qui demande la même récupération
- Opération : HMAC dérivé avec `phishing-my-self-fr.local` au lieu de `bi-self.my-self.fr` → clé complètement différente → bcrypt_verify échoue côté serveur
- Encart pédago : *"Même mot de passe, même protocole, mais domaine différent = clé différente. Le phishing échoue sans aucune formation utilisateur."*

### 4.2 Mode Playground

UI complète présentée d'emblée. L'user peut :
- Créer plusieurs comptes
- Se logger/déconnecter
- Tester les recoveries dans l'ordre qu'il veut
- Essayer des cas limites (mauvais password 5×, lockout, etc.)
- Voir le code et les logs en permanence

## 5. Scénarios SelfModerate

### 5.1 Setup initial

Session démarre avec **5 bots** préconfigurés :
- `@alice_toxique` — balancée pour recevoir des votes négatifs
- `@bob_neutre` — neutre, pour référence
- `@charlie_upvoter` — tente des upvote farming
- `@dave_pack` — tente des votes coordonnés avec charlie
- `@eve_victim` — cible d'un "pack attack"

L'user humain joue `@visiteur_{id}` avec score initial 20.

### 5.2 Mode Tuto — 6 étapes

**Étape 1/6 — Créer ton identité**
- Username choisi, score 20, droit de vote activé
- Encart : *"Dans SelfModerate, ton droit de vote dépend de ton comportement. Tu commences neutre."*

**Étape 2/6 — Voter contre un user toxique**
- Clic sur `@alice_toxique` → propose vote -1 avec raison
- Opération : INSERT vote, recalcul reputation @alice : 18→17→16 selon les bots qui votent aussi
- Encart : *"Le vote est lié à une invitation acceptée. On ne peut pas voter contre quelqu'un qu'on ne connaît pas."*

**Étape 3/6 — Détecter le pack-voting**
- Dave et Charlie upvotent Alice coordination → détection algorithmique
- Opération : cross-reference des timestamps + graph des invitations communes, flag pack-voting, votes annulés
- Encart : *"Voter en groupe pour manipuler = détecté et annulé. Le vote reste un outil, pas une arme de coordination."*

**Étape 4/6 — Escalade des sanctions**
- Alice continue, score baisse sous 5 → perte du droit de vote
- Score = 0 → ban 24h (simulé en 24 sec pour la démo — configurable via `?speed=100`)
- 3 bans → ban permanent
- Encart : *"La sanction n'est pas morale. Elle est mécanique. La communauté décide, l'algorithme exécute."*

**Étape 5/6 — Tentative d'upvote farming**
- L'user tente d'upvoter @bob 4 fois consécutives → 4ᵉ vote bloqué
- Encart : *"Les votes positifs mutuels répétés sont limités. Pas d'économie de crédits sociaux."*

**Étape 6/6 — Reset après ban purgé**
- Après ban expiré, Alice reset à 20 (score) mais strikes conservés
- 3 mois sans incident simulés (tick rapide) → reset total
- Encart : *"Seconde chance structurelle. Le système n'est pas punitif, il protège."*

### 5.3 Mode Playground

Le visiteur voit les 5 bots et peut déclencher manuellement :
- Tick de temps (avancer 1h / 1j / 1 mois)
- Vote manuel (+1 / -1 / raison)
- Créer des "parties" (= invitations acceptées)
- Observer les métriques de la communauté en direct

## 6. Démo du binôme (synergie)

Page `/duo` (ou onglet dans `/recover` ou `/moderate`) :
- L'user crée un compte via SelfRecover (HMAC + bcrypt)
- Le même compte est **immédiatement utilisable** dans SelfModerate
- Tentative d'Sybil : l'user essaie de créer 5 comptes d'affilée pour farmer des votes → SelfRecover impose diceware unique, SelfModerate détecte pack-voting → **échec démontré en live**

## 7. Livrables à produire

1. **Backend PHP** : `/var/www/bi-self/api/demo/` avec 12 endpoints
2. **Frontend HTML/CSS/JS vanilla** : `/var/www/bi-self/` avec `index.html` (landing), `recover.html`, `moderate.html`, `duo.html`
3. **SQLite templates** pour init session (schema SelfRecover, schema SelfModerate avec 5 bots pré-chargés)
4. **Scripts** : `cleanup_demo_sessions.sh` (cron 5 min), `bypass_token_rotate.sh`
5. **nginx vhost** pour `bi-self.my-self.fr` avec SSL Let's Encrypt
6. **CrowdSec** : parser + scenario custom pour auto-ban des abuseurs demo
7. **Documentation** : `admin/README.md` pour install + token bypass

## 8. Rate-limits concrets

| Ressource | Limite | Action au dépassement |
|---|---|---|
| Sessions concurrentes globales | 10 | 429 + msg *"Démo à capacité max"* |
| Sessions par IP / heure | 3 | OK |
| Sessions par IP / heure | 4 | Warning jaune + log |
| Sessions par IP / heure | 5 | Warning rouge + CrowdSec notif |
| Sessions par IP / heure | 6+ | CrowdSec ban 30 jours |
| Actions par session | 50 | 429 + msg *"Limite atteinte, recharge"* |
| Requêtes API par session / minute | 30 | Backoff 5s |
| SSE connexion par session | 1 | Nouvelle connexion ferme l'ancienne |

## 9. Timeline de dev

1. Spec UX (ce document) — ✅
2. Backend infra commune (session mgr, SSE, rate-limit) — 1 jour
3. SelfRecover demo complète — 2 jours
4. SelfModerate demo complète — 3 jours
5. Page landing `/` bi-self.my-self.fr — 0,5 jour
6. CrowdSec scenario — 0,5 jour
7. Tests charge + tweaks + deploy — 0,5 jour

**Total : ~7-8 jours calendaires** de travail honnête.

Pour la première itération, on peut livrer **juste SelfRecover demo** (étapes 1-3 ci-dessus) comme preuve de concept, puis ajouter SelfModerate ensuite.
