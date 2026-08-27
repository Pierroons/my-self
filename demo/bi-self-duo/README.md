# Bi-Self Demo Backend

Infrastructure for the live interactive demos of SelfRecover and SelfModerate
hosted at [bi-self.my-self.fr](https://bi-self.my-self.fr). Provides
per-session sandboxes, real-time log streaming to the frontend, rate-limiting
and abuse bans — without any external dependency.

> This backend is the plumbing. The demo logic for each module (register,
> login, vote, etc.) lives under its own module in the repo and is layered
> on top of the session primitives provided here.

---

## 1. What it does

A visitor who opens `/recover` or `/moderate` in the browser gets:

1. An ephemeral **session** (UUID v4, 30 min TTL) with a private SQLite file.
2. A **live log feed** via Server-Sent Events showing every backend step
   (HMAC derivation, Argon2id, SQL queries, vote scoring, etc.) in real time,
   on the right half of a split-screen UI.
3. Rate-limit protection: max 10 concurrent sessions site-wide, 3 sessions
   per IP per hour before warnings, 7th request = 30-day IP ban logged for
   CrowdSec. API request throttling is enforced by nginx, per IP — see
   `deploy/bi-self/nginx-bi-self.conf`, not this README.

Nothing is persisted beyond 30 minutes. The cleanup cron runs every 5 minutes.

---

## 2. Flow of a session

```
Browser (/recover)                 nginx                       PHP-FPM
      │                              │                            │
      │  POST /demo/api/session      │                            │
      │  body: {"module":"selfrec…"} │                            │
      │─────────────────────────────>│                            │
      │                              │  FastCGI → session.php     │
      │                              │───────────────────────────>│
      │                              │                            │
      │                              │        [RateLimit::check]  │
      │                              │        [DemoSession::create]│
      │                              │        → UUID generated    │
      │                              │        → mkdir sandbox/    │
      │                              │        → sqlite init       │
      │                              │        → cookie Set-Cookie │
      │                              │        → first log written │
      │                              │<───────────────────────────│
      │<─────────────────────────────│                            │
      │  201 { session_id: "…"}      │                            │
      │                              │                            │
      │  EventSource(/demo/api/      │                            │
      │               events)        │                            │
      │─────────────────────────────>│  FastCGI → events.php      │
      │                              │───────────────────────────>│
      │                              │        [tail log.jsonl]    │
      │                              │        keep-alive 30 min   │
      │                              │<═══════════════════════════│
      │<═════════════════════════════│  text/event-stream         │
      │                              │                            │
      │ [module-specific actions →   │                            │
      │  logs appear live on right]  │                            │
```

---

## 3. Directory layout

```
bi-self/demo-backend/
├── README.md                     ← you are here
├── lib/                          ← classes réutilisables
│   ├── redactor.php              ← censure secrets avant envoi au client
│   ├── logger.php                ← écrit JSONL dans log.jsonl
│   ├── rate_limit.php            ← quotas, bans, bypass LAN+cookie
│   └── session_manager.php       ← cycle de vie d'une session
├── api/                          ← endpoints HTTP (mappés par nginx)
│   ├── session.php               ← POST create / GET read
│   ├── events.php                ← GET SSE stream
│   └── bypass.php                ← GET /bypass/<token>
├── schemas/                      ← init SQLite par module
│   ├── selfrecover.sql
│   └── selfmoderate.sql
└── tools/
    └── cleanup_demo_sessions.sh  ← cron toutes les 5 min
```

Sur le serveur en prod :

⚠️ **L'arborescence du dépôt est PRÉSERVÉE sous la racine de déploiement** —
`demo/bi-self-duo/frontend/` sert les pages, `demo/bi-self-duo/api/` sert les
routes, et `bi-self/selfrecover/` vit à côté. Ce n'est pas un détail
d'organisation : `frontend/js/sr-derive.js` est un **lien symbolique** qui
remonte quatre crans pour atteindre `bi-self/selfrecover/client/`. Une
disposition qui aplatirait `frontend/` à la racine, ou qui déplacerait la
bibliothèque, poserait un lien mort — `/js/sr-derive.js` en 404, `srDerive`
indéfini, inscription et récupération cassées sans qu'aucun log ne le dise.

Déployer la bibliothèque **avant ou en même temps** que les pages, jamais après.

```
<racine>/
├── demo/bi-self-duo/frontend/    ← pages servies (root du vhost)
│   └── js/sr-derive.js           ← lien → ../../../../bi-self/selfrecover/client/
├── demo/bi-self-duo/api/         ← routes PHP
├── demo/bi-self-duo/lib/
├── demo/bi-self-duo/schemas/
└── bi-self/selfrecover/client/   ← la cible du lien

/var/lib/selfjustice/
├── admin/
│   ├── bypass_token.txt          ← 32 hex chars, mode 0600, www-data
│   └── token.txt                 ← (endpoint watch, autre usage)
└── demo-sessions/                ← root des sessions
    ├── .counters/                ← compteurs IP anonymisés
    │   └── ip-<hash16>.log
    ├── .banned_ips               ← IP banned + expiry
    └── <uuid>/                   ← une par session
        ├── meta.json             ← created_at, expires_at, module, bypass
        ├── demo.sqlite           ← DB isolée
        ├── log.jsonl             ← événements (tailés par SSE)
        └── actions.counter       ← quota actions (50 max)
```

---

## 4. Installation pas-à-pas

### 4.1 Déployer le code

Depuis ton poste de dev :

```bash
cd bi-self/demo-backend
tar cf /tmp/backend.tar lib api schemas
scp /tmp/backend.tar <utilisateur>@<hôte>:/tmp/
ssh <hôte> '
  sudo mkdir -p /var/www/bi-self/{lib,api,schemas}
  sudo tar xf /tmp/backend.tar -C /var/www/bi-self/
  sudo chown -R www-data:www-data /var/www/bi-self
  sudo chmod 644 /var/www/bi-self/lib/*.php /var/www/bi-self/api/*.php
'
```

### 4.2 Créer l'état runtime

```bash
ssh <hôte> '
  sudo mkdir -p /var/lib/selfjustice/demo-sessions/.counters
  sudo mkdir -p /var/lib/selfjustice/admin
  sudo chown -R www-data:www-data /var/lib/selfjustice/demo-sessions
  sudo chmod 775 /var/lib/selfjustice/demo-sessions
'
```

### 4.3 Générer le bypass token

```bash
BYPASS_TOKEN=$(openssl rand -hex 16)
ssh <hôte> "
  echo '$BYPASS_TOKEN' | sudo tee /var/lib/selfjustice/admin/bypass_token.txt
  sudo chown www-data:www-data /var/lib/selfjustice/admin/bypass_token.txt
  sudo chmod 600 /var/lib/selfjustice/admin/bypass_token.txt
"
echo "Bypass URL: https://bi-self.my-self.fr/bypass/$BYPASS_TOKEN/"
# → sauvegarder l'URL dans un gestionnaire de mots de passe
```

### 4.4 nginx

Copier `deploy/nginx-bi-self.conf` (dans `bi-self/deploy/`) vers
`/etc/nginx/sites-available/bi-self`, créer le symlink dans `sites-enabled/`,
puis `sudo nginx -t && sudo systemctl reload nginx`.

**Important** : les location blocks pour les endpoints démo **n'utilisent pas**
`snippets/fastcgi-php.conf` car son `try_files $fastcgi_script_name =404`
échouerait sur des URLs comme `/demo/api/session` qui n'existent pas comme
fichier. On passe directement par `fastcgi.conf` + `SCRIPT_FILENAME` absolu.

### 4.5 Cleanup cron

```bash
ssh <hôte> '
  sudo cp /tmp/cleanup_demo_sessions.sh /opt/selfrecover-demo/
  sudo chmod +x /opt/selfrecover-demo/cleanup_demo_sessions.sh
'
ssh <hôte> 'crontab -l; echo "*/5 * * * * /opt/selfrecover-demo/cleanup_demo_sessions.sh"' | ssh <hôte> crontab -
```

### 4.6 Vérifier

```bash
# Crée une session sans bypass (simule un visiteur)
curl -c /tmp/c.txt -X POST -H "Content-Type: application/json" \
  -d '{"module":"selfrecover"}' https://bi-self.my-self.fr/demo/api/session

# Lire la session
curl -b /tmp/c.txt https://bi-self.my-self.fr/demo/api/session

# Streamer les logs 5 s
timeout 5 curl -b /tmp/c.txt -N https://bi-self.my-self.fr/demo/api/events
```

Attendu : HTTP 201 avec UUID v4 bien formé `xxxxxxxx-xxxx-4xxx-[89ab]xxx-xxxxxxxxxxxx`,
puis HTTP 200 en GET, puis stream SSE avec l'événement `session opened`.

Puis le parcours complet, qui est le seul contrôle à emprunter les routes réelles
de bout en bout :

```bash
BASE=https://bi-self.my-self.fr bash tests/integration.sh
```

Il crée un compte, dérive sous le nom d'hôte lu, puis sous un nom d'hôte imité, et
**exige que le second soit refusé** — c'est la propriété anti-hameçonnage, mesurée
plutôt qu'affirmée. Il confronte aussi sa propre formule aux vecteurs figés de
`bi-self/selfrecover/tests/vecteurs-derivation.json` : une recopie qui dérive
rougit avant tout le reste.

⚠️ Aucun job de CI ne le lance — il lui faut une instance qui répond. C'est un
contrôle de déploiement, à passer après chaque envoi.

---

## 5. Ajouter un nouveau module démo

1. Créer `schemas/<module>.sql` avec le schéma SQLite initial du module.
2. Dans le frontend, appeler `POST /demo/api/session` avec `{"module":"<module>"}`.
3. Le backend init automatiquement la session avec ce schéma (voir
   `DemoSession::create`).
4. Créer les endpoints applicatifs du module sous `api/<module>_*.php`
   qui utilisent `DemoSession::current()` pour accéder à la DB et au logger.
5. Ajouter les nouvelles routes dans `deploy/nginx-bi-self.conf`.

Pattern type d'endpoint applicatif :

```php
<?php
require_once __DIR__ . '/../lib/session_manager.php';
header('Content-Type: application/json; charset=utf-8');

$s = DemoSession::current();
if ($s === null) { http_response_code(401); exit(json_encode(['ok' => false, 'error' => 'no_session'])); }

// Check action quota
if (!RateLimit::checkAndIncrementActions($s->dir)) {
    http_response_code(429);
    exit(json_encode(['ok' => false, 'error' => 'quota_exceeded']));
}

// Business logic here — use $s->db() and $s->logger()
$s->logger()->info('step_name', 'Human-readable message', ['key' => 'value']);

echo json_encode(['ok' => true]);
```

---

## 6. Modèle de sécurité

### 6.1 Rate-limiting

| Contrainte | Seuil | Action |
|---|---|---|
| Sessions concurrentes globales | 10 | 503 Service Unavailable |
| Sessions par IP / heure | 3 | OK |
| Sessions par IP / heure | 4 | Warning jaune dans la réponse |
| Sessions par IP / heure | 5 | Warning rouge "dernier avertissement" |
| Sessions par IP / heure | 6+ | IP ajoutée à `.banned_ips` pour 30 jours + log CrowdSec |
| Actions par session | 50 | 429 Too Many Requests |

### 6.2 Bypass

Deux mécanismes indépendants :

- **LAN** : IP dans `votre plage LAN privée`, `10.x`, `127.x`, `::1`, `fe80::…` → bypass automatique.
- **Cookie `sj_bypass`** : contient un token comparé avec hash_equals
  au contenu de `bypass_token.txt`. Obtenu via `/bypass/<token>/`.

### 6.3 Redactor

Avant qu'un log ou un extrait de code atteigne le frontend, `Redactor::redactLog`
et `Redactor::redactSource` remplacent :

- Les paths absolus sensibles par des placeholders : `/var/lib/…/` →
  `{session_dir}/`, `{admin_dir}/`, `{state_dir}/`
- Les secrets inline : `$siteSalt = "…"` → `$siteSalt = [REDACTED — set at install]`
- Les hash longs : tronqués à 16 chars avec suffixe `…truncated`

---

## 7. Rotation du bypass token

Si le token fuit (screenshot partagé, PC compromis, doute général) :

Le jeton se génère **sur le serveur**, et ne transite jamais par le poste :

```bash
ssh <hôte> "openssl rand -hex 16 | sudo tee /var/lib/selfjustice/admin/bypass_token.txt >/dev/null \
            && sudo chmod 600 /var/lib/selfjustice/admin/bypass_token.txt \
            && sudo chown www-data:www-data /var/lib/selfjustice/admin/bypass_token.txt"

# le relire quand on en a besoin, plutôt que de le garder quelque part :
ssh <hôte> "sudo cat /var/lib/selfjustice/admin/bypass_token.txt"
```

⚠️ **Ne fais pas transiter le jeton par ton poste.** La version précédente de
cette procédure le générait localement, le passait en argument et l'affichait :
il entrait alors dans l'historique du shell, puis dans un presse-papier, puis —
c'est arrivé le 17/04/2026 — dans un message de commit publié. Un jeton généré
sur la machine qui l'utilise ne connaît qu'un seul chemin.

L'ancien token est invalidé **immédiatement** à la prochaine requête — aucun
redémarrage nécessaire. Les cookies `sj_bypass` déjà posés deviennent caducs
puisque PHP relit le fichier à chaque check.

---

## 8. Observabilité

- **Logs applicatifs** de chaque session : `/var/lib/selfjustice/demo-sessions/<uuid>/log.jsonl`
- **Log nginx** : `/var/log/nginx/biself-access.log` et `biself-error.log`
- **Log cleanup** : `/var/log/selfjustice-demo-cleanup.log`
- **Log abuse detected** : `/var/log/selfjustice-demo-abuse.log`

Un dashboard admin est prévu pour agréger ça (voir roadmap SPEC.md).

---

## 9. Ce qu'il n'y a pas (par principe)

- **Pas de Docker** : sandbox au niveau fichier (dossier isolé + SQLite fichier),
  pas de container par visiteur. Moins coûteux, moins complexe, suffit pour
  le modèle de menace.
- **Pas de Redis / Memcached** : compteurs en fichiers texte flockés. Bas
  volume, pas besoin de cache mémoire.
- **Pas de WebSocket** : SSE suffit, one-way, compatible PHP-FPM sans daemon.
- **Pas de framework** : PHP natif, classes finales, zero deps composer.
- **Pas de cloud** : tout vit sur le le serveur. Le jour où le le serveur tombe, la démo
  tombe. C'est le prix de la souveraineté.

---

## 10. Licence & contribution

AGPL-3.0-or-later, comme le reste du repo MySelf (bascule depuis MIT le
19/04/2026). Les contributions via PR GitHub sont bienvenues après la
release de la session 7 (polish final). D'ici là, ouvre plutôt une issue
pour les remarques — le code bouge beaucoup.

*Partie de [MySelf](https://github.com/Pierroons/my-self) — Be yourself, for yourself.*
