# Contributing to Bi-Self demo

Short guide for anyone wanting to contribute to the live interactive demos.

## Philosophy & constraints

- **Zero external dependencies.** No composer, no npm, no framework. Pure PHP 8.2+, vanilla JS (Web Crypto API where needed), SQLite.
- **Self-hosted.** Runs on a Raspberry Pi 4. Think in MB of RAM, not GB.
- **Open book.** Every secret is either user-provided (passphrase, recovery word) or session-scoped (UUID, site_salt). The Redactor censors anything remaining before it reaches the browser.
- **Transparent by design.** No hidden state. If it happens, it's in the logs.

## Code style

- PSR-12 friendly (braces, indentation, whitespace).
- `declare(strict_types=1);` mandatory at the top of every PHP file.
- Final classes for libs (`final class XYZ`). Static helpers when stateless.
- Type hints on parameters and return types.
- Single-file PHP scripts for endpoints (no routing framework).
- JS vanilla, no build step. `fetch` + `EventSource` + Web Crypto API are the allowed primitives.

## Repo layout (demo-backend/)

```
lib/              Class-based libraries, reused across modules
api/              HTTP endpoints (one PHP file per route)
  ├── session.php Session lifecycle
  ├── events.php  SSE log streaming
  ├── bypass.php  Rate-limit bypass
  ├── recover/    SelfRecover endpoints
  ├── moderate/   SelfModerate endpoints
  └── duo/        Binôme synergy demo
frontend/         Static HTML pages (one per demo)
schemas/          SQLite init scripts per module
tools/            Bash scripts (cron cleanup, etc.)
tests/            integration.sh end-to-end tests
deploy/           nginx vhost config
```

## Adding a new demo module

1. **Schema** : create `schemas/<module>.sql` with the initial tables and any preloaded data (e.g. bots).
2. **Helper** : in `lib/<module>_helper.php`, encapsulate the domain logic (reputation math, crypto derivations, etc.).
3. **Endpoints** : under `api/<module>/`, one PHP file per action. Each should:
   - `require_once` the session manager and your helper
   - Call `DemoSession::current()` → 401 if absent
   - Call `RateLimit::checkAndIncrementActions($s->dir)` → 429 if quota hit
   - Do the work, log via `$s->logger()->info(...)` / `->crypto(...)` / etc.
   - Return JSON
4. **Frontend** : `frontend/<module>.html`. Reuse the CSS palette/structure of `recover.html` or `moderate.html` for consistency.
5. **nginx route** : extend the `location ~ "^/demo/api/<module>/..."` regex in `deploy/nginx-bi-self.conf` with your action names.
6. **Rewrite** : add `location = /<module> { rewrite ^ /<module>.html last; }` to expose the HTML cleanly.
7. **Code viewer** : add the new files to the whitelist in `api/<module>/code.php`.
8. **Tests** : add a section to `tests/integration.sh` covering the happy path.

## Local testing

From LAN (`192.168.1.0/24`, auto-bypass) or with `sj_bypass` cookie :

```bash
cd bi-self/demo-backend
./tests/integration.sh                    # against prod bi-self.my-self.fr
```

To iterate locally:

```bash
# After edits, redeploy to RPI4
scp -i $SSH_KEY <file> user@rpi4:/tmp/
ssh user@rpi4 'sudo cp /tmp/<file> /var/www/bi-self/<path> && sudo chown www-data:www-data /var/www/bi-self/<path>'
```

No hot-reload — PHP-FPM picks up file changes on the next request.

## Commit conventions

- Title : short, imperative, plain text — `"Add SelfGuard demo backend"`, not `feat(guard): ...`.
- Body : explain **why** the change matters more than **what** changed. The diff already shows what.
- Co-author the machine partner when applicable: `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`.

## What we don't accept

- Dependencies (composer, npm). The whole point is minimalism.
- Framework (Laravel, Symfony, React). Vanilla PHP + vanilla JS only.
- Analytics / tracking. No Google Analytics, no Matomo, no custom fingerprinting beyond anonymous UA family counting.
- Cloud services in the loop. No Sentry, no Datadog, no S3. If it needs cloud, it doesn't belong here.

## Licence

AGPL-3.0-or-later (see repo root). By contributing, you agree your work is distributed under AGPL-3.0-or-later. Historical contributions made before 2026-04-19 remain licensed under the MIT License they were originally submitted under.
