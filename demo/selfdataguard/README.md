# SelfDataGuard — Demo

A standalone clickable demo of SelfDataGuard's per-user envelope encryption, with a **split-screen view** that shows the raw encrypted database in real time as you interact with the app.

## Prerequisites

- **PHP 8.1+** with the `sodium`, `pdo`, `pdo_sqlite`, `json`, `mbstring` extensions
- A modern browser (Firefox, Chromium, Safari…)
- AES-NI-capable CPU (any x86-64 since ~2010, ARM64 since Cortex-A53)

Check with:

```bash
php --version
php -r "var_dump(extension_loaded('sodium'), sodium_crypto_aead_aes256gcm_is_available());"
```

Both must report `true`.

## Launch

```bash
cd demo
./run.sh
```

Then open **<http://127.0.0.1:8081>** in your browser.

To use a different port: `PORT=9000 ./run.sh`.

### Member area — "Mon coffre" (escrow module)

**<http://127.0.0.1:8081/coffre.html>** is the profile module demonstrating the
**two-zone vault**: a private E2E zone (only the user reads it) and a consented
**recovery-escrow** zone (an admin can recover it, but only after a litige).

- Open a coffre (a user you registered on the main demo), read the private zone.
- Deposit escrow fields (`contact_secours`, `indice_recup`) — note the explicit
  consent text: accessible to an admin **only** during a recovery litige.
- The escrow is sealed to a demo admin recovery key generated on first run
  (`storage/admin-recovery.pub` + `.sealed`, demo passphrase
  `demo-admin-recovery-passphrase-2026`). Recover it from the CLI:

  ```bash
  DATAGUARD_DB=storage/demo.sqlite \
  DATAGUARD_ADMIN_PUBKEY_FILE=storage/admin-recovery.pub \
  DATAGUARD_ADMIN_SEALED_FILE=storage/admin-recovery.sealed \
  DATAGUARD_AUDIT_LOG=storage/escrow-audit.log \
  DATAGUARD_AUDIT_SECRET=any-demo-secret-≥16-bytes \
  php ../bin/escrow-ceremony.php unlock <user> <litige_id>
  ```

  (requires a `litiges` table with an open row for `<user>` — the calling application
  wires its own; see the ceremony CLI header for details).

The demo uses PHP's built-in web server, no Apache/nginx required, no `composer install` needed.

## What you can try (left column)

1. **Register** a user with a password (≥12 chars) and an optional memorized recovery secret. Add some personal fields (email, phone, address, IBAN). The `email` and `phone` fields are automatically indexed for lookups.

2. **Login & decrypt** — pick "with password" or "with memorized secret". The form's secret label adapts to your choice. On success you'll see the four personal fields decrypted in clear text in the response box.

3. **Find user by indexed field** — proves you can locate a user by their email or phone via blind index without decrypting a single row.

4. **Rotate password** — set a new password while keeping the same encrypted data. Watch the right panel: only `wrap_pwd` changes, the `ciphertext` of each field is untouched.

5. **Delete a user** — removes the vault and cascades to all encrypted fields. Observe the right panel emptying.

## What the right column shows

The **Backend — raw DB view** auto-refreshes after every action and shows exactly what is stored on disk:

- Vaults table: `user_id`, base64 of `user_salt`, base64 of `wrap_pwd`, base64 of `wrap_recov`, timestamps
- Fields table: `user_id`, `field_name`, base64 `ciphertext`, base64 `blind_index`, timestamp

The proof: type your email in the register form, watch it disappear into a base64 blob on the right. **No combination of plaintext field values you entered will ever appear in this panel.**

## Reset the demo

```bash
rm -f demo/storage/demo.sqlite demo/storage/blindkey.bin
```

Reload the page — you'll get a clean DB and a fresh server-side blind key. The blind key is auto-generated on first run and stored in `demo/storage/blindkey.bin` (mode 0600), gitignored. **In production**, this key would live in a secret manager / Vault / HSM, not on disk next to the DB.

## What this demo doesn't do (production gaps)

- **No session management**: each request reaches the server with the full credentials. A real app would use a short-lived session token + memory-only master key. The demo is intentionally simple to make the cryptography obvious.
- **No password policy enforcement** beyond ≥12 chars. Production deployments must add HaveIBeenPwned checks, common-password blocklists, and zxcvbn entropy on the memorized secret (whitepaper §7).
- **No rate limiting**: the demo accepts unlimited authentication attempts. Production needs Argon2id-based rate limits per user, anti-bruteforce on memorized secrets, IP throttling.
- **No TLS**: the demo runs on plain HTTP localhost. Production REQUIRES HTTPS with HSTS strict.
- **No admin operational key**: only Lite mode is wired (master key in memory during session only). Hybrid mode (admin can read operational fields offline) is whitepaper §4.2 and ships with v0.2.0.
- **No 2FA**: the demo only authenticates via password OR memorized secret. Production would layer SelfRecover on top for multi-factor account recovery.

## Browser screenshot reference

```
┌──────────────────────────────────────────────────────────────────────────────┐
│  SelfDataGuard v0.1.0-beta — Dump my database — and you get encrypted noise  │
│  GitHub · Whitepaper EN · Whitepaper FR · AGPL-3.0                           │
├──────────────────────────────────────────────────────────────────────────────┤
│ How this demo works (full-width explainer)                                   │
├─────────────────────────────────────┬────────────────────────────────────────┤
│ FRONTEND — what the user does       │ 🔍 BACKEND — raw DB view (auto)        │
│                                     │                                        │
│ 1. Register                         │   vaults: [                            │
│   userId: alice                     │     {user_id: "alice",                 │
│   password: ••••••••••••            │      user_salt: "yp5xT...",            │
│   memorized: ••••••••••             │      wrap_pwd:  "ws4ZQrXze...",        │
│   email/phone/address/iban: ...     │      wrap_recov:"a7dHKp9...",          │
│                                     │      created_at: ...}                  │
│ 2. Login & decrypt                  │   ]                                    │
│   userId: alice                     │   fields: [                            │
│   method: password / memorized      │     {field_name: "email",              │
│   secret: ••••••••••••              │      ciphertext: "Xz9K3qT/Ws…",        │
│                                     │      blind_index: "ijbKGD4Q…"}         │
│ 3. Find by indexed field            │     {field_name: "phone", …},          │
│ 4. Rotate password                  │     {field_name: "iban",  …},          │
│ 5. Delete user                      │     …                                  │
│                                     │   ]                                    │
└─────────────────────────────────────┴────────────────────────────────────────┘
```

## Troubleshooting

- **"Authentication failed"** on login: check that you typed exactly the same password as registration (case + spaces matter), and that the "Login method" matches what you set (don't try memorized recovery if you didn't configure a memorized secret).
- **"Email domain could not be verified"** is unrelated to this demo (Brevo/GitHub story). Not applicable here.
- **Permission denied on `demo/storage/`**: the directory must be writable by the user running PHP. `chmod +rw demo/storage` should fix it.
- **Port already in use**: another process is on 8081. `PORT=9000 ./run.sh` to switch ports.
- **The right panel says `"vaults": [], "dbSize": 24576`**: that's normal, the empty SQLite still allocates 24 KB for its internal page structure. Register a user and it will fill up.

## Want to adapt this to your own app?

Look at `api/_bootstrap.php` for the minimal wiring, then `api/register.php` and `api/login.php` for the typical request handling. Swap `SqliteAdapter` for your own `StorageInterface` implementation if you target MariaDB or Postgres.
