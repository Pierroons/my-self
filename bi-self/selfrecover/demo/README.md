# SelfRecover demo

A standalone, zero-dependency demo of the SelfRecover protocol.

## Requirements

- **PHP 8.0+** CLI
- **PHP SQLite driver** (`pdo_sqlite` + `sqlite3`)

On Debian/Ubuntu:
```bash
sudo apt install php-cli php-sqlite3
```

On macOS:
```bash
brew install php
```

That's it. No composer, no npm, no Docker.

## Run it

```bash
./run.sh
```

Or manually:

```bash
php -S localhost:8080 -t . router.php
```

Then open **http://localhost:8080** in your browser.

## What you can test

1. **Register** — Create an account. A diceware passphrase is generated server-side and shown once. Your recovery word is HMAC-derived client-side (check the browser console).
2. **Login** — Log in with username + password.
3. **Recover L1** — Simulate "I forgot my password" → enter the passphrase → get a new password.
4. **Recover L2** — Simulate "I forgot my passphrase too" → enter your identifier + recovery word → get a new password. The recovery word is HMAC-derived in the browser, never sent raw.

## Where's the data?

A `selfrecover.sqlite` file is created in the `demo/` directory on first run. Delete it to reset the demo. `run.sh` resets it automatically on each launch.

## What's missing vs production

This demo is intentionally minimal. Compared to the reference implementation running on [ARC PVE Hub](https://arc.rpi4server.ovh), this demo omits:

- Level 3 multi-factor scoring recovery
- Dispute system
- Admin dashboard
- Push notifications
- Advanced anti-abuse (honeypot, timing checks, fingerprint tracking)
- Rate limiting per IP (only per username)

Read the [whitepaper](../docs/whitepaper-en.md) for the full specification.

## Security note

**This is a demo.** The `SITE_SALT` in `api/db.php` is hardcoded for convenience. In production, you MUST generate a unique cryptographically random salt per deployment and store it securely. See the [deployment security checklist](../docs/whitepaper-en.md) in the whitepaper.
