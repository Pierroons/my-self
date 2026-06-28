# SelfDataGuard

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Application-layer data-at-rest protection that survives a database exfiltration.**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: beta v0.1.0](https://img.shields.io/badge/status-beta%200.1.0-yellow.svg)](#status)
[![Tests: 155 passing](https://img.shields.io/badge/tests-155%20passing-brightgreen.svg)](#testing)
[![Part of: Self-Security](https://img.shields.io/badge/part%20of-Self--Security-blue.svg)](../README.md)
[![Companion of: SelfRecover](https://img.shields.io/badge/companion-SelfRecover-green.svg)](../../bi-self/selfrecover/)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

> **Dump my database — and get encrypted noise.**

---

## The problem

Every encrypted-data-at-rest product today (MySQL TDE, MongoDB CSFLE, AWS RDS encryption) answers the same threat model: **the attacker has the disk, but not the application**. The encryption key sits next to the data — in a config file, an environment variable, a key management service the application can read.

That model breaks the moment the **application server is compromised**. The attacker dumps the database AND the key — the encryption was a checkbox, not a defense. Recent breaches at scale (ANTS, France, April 2026 — 11.7 to 19 million accounts exposed via a trivial IDOR) proved that personal data exposed in plain text is the dominant cost of these incidents.

Current tools either skip data-at-rest encryption entirely or implement it in a way that adds zero value against a server-side compromise. SelfDataGuard picks a third path: **derive the encryption key from a secret only the user knows**, so a database dump alone yields cryptographic soup.

---

## Core principle: per-user envelope encryption

SelfDataGuard implements **two-factor key wrapping** inspired by Bitwarden, 1Password, and ProtonMail vault designs, adapted for application-layer per-user encryption:

```
        ┌─────────────────────────────────────┐
        │      Per-user data_master_key       │  ← random 256 bits
        │      (never stored in plain)        │  ← in memory only when user is logged in
        └────────────┬────────────┬───────────┘
                     │            │
              wrap with       wrap with
                     │            │
        ┌────────────▼─┐      ┌──▼─────────────┐
        │ password_key │      │   recov_key    │
        │ Argon2id(    │      │ HMAC-SHA256(   │
        │   password,  │      │  memorized,    │
        │   user_salt) │      │  user_salt+    │
        │              │      │  "/dataguard") │
        └──────────────┘      └────────────────┘
```

Each user has:

- A unique random `user_salt` stored in plain (identifier-grade)
- A `data_master_key_pwd_wrap`: AES-256-GCM ciphertext of the master key, encrypted with the password-derived key
- A `data_master_key_recov_wrap`: AES-256-GCM ciphertext of the master key, encrypted with the recovery-word-derived key
- Personal data fields encrypted field-by-field with `data_master_key`

**Database dump → cryptographic soup.** No combination of plain-text values in the dump yields the master key. The attacker would need either the user's password (Argon2id-hardened, salt-isolated) or the user's recovery word (never transmitted in plain) to decrypt anything.

---

## Coupling with SelfRecover

SelfDataGuard reuses the SelfRecover memorized-recovery-word as one of its two unwrap factors, with **strict context separation** to prevent crossover:

```
recovery_word (user secret, never transmitted in plain)
    │
    ├─ HMAC-SHA256(secret, domain + "/recover")  →  recover_key  (SelfRecover auth)
    │
    └─ HMAC-SHA256(secret, salt_user + "/dataguard")  →  data_key  (SelfDataGuard wrap)
```

Practical consequence: a user who forgets their password but remembers their recovery word can simultaneously **regain account access (via SelfRecover) and decrypt their stored data (via SelfDataGuard)**. One memorized word, two derived purposes, mathematically isolated.

Without SelfRecover, SelfDataGuard still works — it falls back to a password-only wrap (single-factor recovery, weaker UX). But the natural pairing is: **SelfRecover protects authentication, SelfDataGuard protects data, the same memorized word unlocks both**.

---

## Three operational modes

| Mode | Server access to data | Trade-off |
|------|----------------------|-----------|
| **Lite** *(transparent for legacy stacks)* | Server decrypts during user sessions only | Server compromise during an active session = limited fan-out (one user at a time) |
| **Hybrid** *(default for e-commerce)* | Operational fields (`email`, `shipping_address`) wrapped with admin operational key. Sensitive fields (`tel`, `KYC_doc`) require user session | Admin can fulfill orders; sensitive data remains zero-knowledge |
| **Full** *(zero-knowledge for high-assurance services)* | Server NEVER decrypts. All crypto runs in the browser via WebCrypto SubtleCrypto | Some workflows redesigned (no async transactional emails, push notifications instead) |

Most e-commerce deployments will pick **Hybrid**. Health, banking, identity providers will pick **Full**.

---

## Threat model at a glance

| Adversary | Without SelfDataGuard | With SelfDataGuard |
|-----------|----------------------|---------------------|
| SQL injection / IDOR / DB dump | Plain-text PII exposed | Encrypted soup |
| Backup tape stolen | Plain-text PII exposed | Encrypted soup |
| Insider DBA | Reads everything | Encrypted (cannot unwrap without user password or recovery word) |
| Application root compromise (RCE) | Reads everything | Reads only currently active sessions (Lite) or operational fields (Hybrid). Zero (Full) |
| Compromised user endpoint (keylogger) | User credentials harvested | User credentials harvested → that user's data only (no fan-out) |
| Coercion of admin to decrypt | All data at admin's discretion | Admin can decrypt only operational fields (Hybrid) — for full data, they would need every user's password/recovery word |

---

## Status

**v0.1.0-beta — reference library + standalone demo**, May 8, 2026.

Whitepaper complete (specification + threat model). PHP reference library implemented (~1230 lines, PSR-4, PHP 8.1+, libsodium). Cryptographic primitives (Argon2id, HMAC-SHA256, AES-256-GCM) covered by 155 sanity tests. A clickable HTML demo is included to inspect the encrypted database in real time.

This module is **not yet production-ready**. It is published as beta to:

- Invite community review of the cryptographic design AND the implementation
- Allow security researchers to challenge the threat model with a runnable target
- Coordinate with downstream integrators (notably SelfRecover users)
- First real-world deployment target: a small production e-commerce site

A formal community cryptographic audit is planned before v1.0.0. ANSSI Visa de sécurité submission planned for the v0.3.0 milestone.

---

## Quick start

### Run the standalone demo (no install needed)

```bash
cd demo && ./run.sh
# open http://127.0.0.1:8081 in a browser
```

The demo lets you register a user, log in, rotate password, and inspect the raw SQLite database side by side — proving that personal fields (email, phone, IBAN, address) are never readable on disk.

### Use the library in your app

```php
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;

require 'vendor/autoload.php';

$dg = new SelfDataGuard(
    storage:  new SqliteAdapter('sqlite:/path/to/db.sqlite'),
    blindKey: file_get_contents('/path/to/server-secret.bin')  // ≥32 bytes
);

// New user
$session = $dg->register('alice', 'correct horse battery staple', 'sunset-river-marble');
$dg->setFields($session, ['email' => 'a@b.c', 'iban' => 'FR76...'], indexed: ['email']);

// Returning user
$session = $dg->loginWithPassword('alice', 'correct horse battery staple');
$fields  = $dg->getFields($session);  // ['email' => 'a@b.c', 'iban' => 'FR76...']

// Recovery flow (forgot password, remembers memorized secret)
$session = $dg->loginWithMemorized('alice', 'sunset-river-marble');
$dg->changePassword($session, 'a-fresh-passphrase-here');

// Indexed lookup, no plaintext required
$userId = $dg->findUserByField('email', 'a@b.c');  // 'alice' or null
```

Three primary classes exposed: `SelfDataGuard` (façade), `SqliteAdapter` (storage; implement `StorageInterface` for MariaDB / Postgres), `Primitives` (raw crypto if you need to build something on top).

---

## Testing

Five sanity test suites, runnable directly with `php` (no PHPUnit required):

```bash
php tests/sanity_primitives.php   # 27 tests — Argon2id, HMAC, AES-GCM, randomness
php tests/sanity_vault.php        #  33 tests — register, unlock, rotation, AAD binding
php tests/sanity_fields.php       # 25 tests — field encrypt/decrypt + blind index
php tests/sanity_storage.php      # 36 tests — SQLite adapter, "DB dump = soup" test
php tests/sanity_facade.php       # 34 tests — full API end-to-end
# Total: 155 tests, 0 failures
```

The `sanity_storage.php` suite includes a "BIG TEST" that dumps the SQLite file and verifies that no plaintext personal data appears anywhere in the binary blob.

---

## Documentation

- [Whitepaper EN (full specification)](./docs/whitepaper-en.md)
- [Whitepaper FR (specification complète)](./docs/whitepaper-fr.md)
- [Demo walkthrough](./demo/README.md)

---

## License

**AGPL-3.0-or-later**. See [LICENSE](../../LICENSE).

Any deployment, modified or not, must publish its source code under the same license. No SaaS capture possible.
