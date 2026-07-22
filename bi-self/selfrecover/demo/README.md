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
4. **Recover L2** — Simulate "I forgot my passphrase too" → enter a recovery code + the memorized word → get a new password. The memorized word is HMAC-derived in the browser, never sent raw.
5. **Recover L3** — Simulate "I lost everything" → context questions build a bundle of raw signals for a human admin (no numeric score). On grant, you re-define your own secret (the server never issues a password).

## Where's the data?

A `selfrecover.sqlite` file is created in the `demo/` directory on first run. Delete it to reset the demo. `run.sh` resets it automatically on each launch.

## Scope

This demo implements the core protocol end-to-end: L1/L2/L3 recovery, dispute system, admin decision flow, and anti-abuse (honeypot, per-IP blocking, suspicious-fingerprint tracking). The seed data and copy are demo-grade; the cryptographic flow is the real one.

Read the [whitepaper](../docs/whitepaper-en.md) for the full specification.

## Security note

**This is a demo.** The fallback secrets in `api/db.php` are hardcoded for convenience. In production you MUST provide `SELFRECOVER_SERVER_SECRET` and `SELFRECOVER_ADMIN_TOKEN` via the environment (never commit them); each account also gets its own client-generated salt. See the [deployment security checklist](../docs/whitepaper-en.md) in the whitepaper.
