# SelfDataGuard

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Application-layer data-at-rest protection that survives a database exfiltration.**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.4.0 in service](https://img.shields.io/badge/status-v0.4.0%20in%20service-brightgreen.svg)](#status)
[![Tests: 219 passing](https://img.shields.io/badge/tests-219%20passing-brightgreen.svg)](#testing)
[![Part of: Self-Security](https://img.shields.io/badge/part%20of-Self--Security-blue.svg)](../README.md)
[![Companion of: SelfRecover](https://img.shields.io/badge/companion-SelfRecover-green.svg)](../../bi-self/selfrecover/)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

> **Dump my database — and get encrypted noise.**

---

## The problem

Every encrypted-data-at-rest product today (MySQL TDE, MongoDB CSFLE, AWS RDS encryption) answers the same threat model: **the attacker has the disk, but not the application**. The encryption key sits next to the data — in a config file, an environment variable, a key management service the application can read.

That model breaks the moment the **application server is compromised**. The attacker dumps the database AND the key — the encryption was a checkbox, not a defense. Recent breaches at scale have shown the same thing every time: personal data exposed in plain text is the dominant cost of the incident, because it cannot be revoked.

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
        │ Argon2id(    │      │ Argon2id(      │
        │   password,  │      │  memorized,    │
        │   user_salt) │      │  SHA-256(      │
        │              │      │   user_salt +  │
        │              │      │  "/dataguard"))│
        └──────────────┘      └────────────────┘
```

Argon2id takes a 16-byte salt: the first 16 bytes of this SHA-256. Both wrap keys cost the same: two wraps are only as strong as the cheaper one.

Each user has:

- A unique random `user_salt` stored in plain (identifier-grade)
- A `data_master_key_pwd_wrap`: XChaCha20-Poly1305 ciphertext of the master key, encrypted with the password-derived key
- A `data_master_key_recov_wrap`: XChaCha20-Poly1305 ciphertext of the master key, encrypted with the recovery-word-derived key
- Personal data fields encrypted field-by-field with `data_master_key`

**Database dump → cryptographic soup.** No combination of plain-text values in the dump yields the master key. The attacker would need either the user's password (Argon2id-hardened, salt-isolated) or the user's recovery word (never transmitted in plain) to decrypt anything.

---

## Coupling with SelfRecover

SelfDataGuard reuses the SelfRecover memorized-recovery-word as one of its two unwrap factors, with **two distinct derivations** — different functions, different salts — to prevent crossover:

```
recovery_word (user secret, never transmitted in plain)
    │
    ├─ HMAC-SHA256(key = secret, msg = material + "|v2" + user_salt)  →  recover_key  (SelfRecover auth)
    │
    └─ Argon2id(secret, SHA-256(user_salt + "/dataguard")[:16])       →  data_key     (SelfDataGuard wrap)
```

Practical consequence: a user who forgets their password keeps a way into each of their two halves. Their memorized word opens the SelfDataGuard vault **on its own**. For account access they also need what SelfRecover requires — their paper *recovery code* at level 2, or their diceware passphrase at level 1: the memorized word is **one factor out of two** there. One word to remember, two derived purposes, mathematically isolated.

Without SelfRecover, SelfDataGuard still works — it falls back to a password-only wrap (single-factor recovery, weaker UX). But the natural pairing is: **SelfRecover protects authentication, SelfDataGuard protects data, and the same memorized word serves in both** — alone to open the vault, alongside the *recovery code* to reopen the account.

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

**v0.4.0 — XChaCha20-Poly1305 on every CPU, versioned blob format**, 26 September 2026.

Whitepaper complete (specification + threat model). PHP reference library implemented (2 607 lines across 18 files, PSR-4, PHP 8.1+, libsodium). Cryptographic primitives (Argon2id, HMAC-SHA256, XChaCha20-Poly1305, and AES-256-GCM to read blobs written before 0.4.0) covered by **219 checks across 8 suites**, all passing. A clickable HTML demo is included to inspect the encrypted database in real time.

Blobs written by 0.3.0 stay readable, through OpenSSL (`ext-openssl`) where libsodium refuses AES. A blob written by 0.4.0 cannot be read by 0.3.0, which refuses it as invalid base64: roll back only a database that 0.4.0 has not written to. Why AES-256-GCM was dropped, and on which CPUs it failed: see the [CHANGELOG](./CHANGELOG.md).

The module runs on real deployments. It has **not been audited by an external cryptographer**: its design is verified today by its author and by the readers of this repository, and by no one else.

Reviews are wanted, on the design and on the implementation alike — security researchers get a runnable target rather than a specification to argue with. Downstream integrators, SelfRecover users in particular, should read the threat model before wiring it in.

A formal community cryptographic audit is planned before v1.0.0. ANSSI Visa de sécurité submission planned for the same milestone.

---

## Quick start

### Run the standalone demo (no install needed)

```bash
# from the repository root
demo/selfdataguard/run.sh
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

Eight sanity test suites, runnable directly with `php` (no PHPUnit required):

```bash
php tests/sanity_primitives.php   # 45 tests — Argon2id, HMAC, XChaCha20-Poly1305 + IETF vector, legacy AES-GCM, randomness
php tests/sanity_vault.php        # 36 tests — register, unlock, rotation, AAD binding, legacy wraps
php tests/sanity_fields.php       # 26 tests — field encrypt/decrypt + blind index
php tests/sanity_storage.php      # 36 tests — SQLite adapter, "DB dump = soup" test
php tests/sanity_facade.php       # 34 tests — full API end-to-end
php tests/sanity_audit.php        # 11 tests — audit log
php tests/sanity_ceremony.php     # 14 tests — key ceremony
php tests/sanity_escrow.php       # 17 tests — escrow compartment
# Total: 219 tests, 0 failures — counted by running them, 2026-09-26
```

The `sanity_storage.php` suite includes a "BIG TEST" that dumps the SQLite file and verifies that no plaintext personal data appears anywhere in the binary blob.

---

## Documentation

- [Whitepaper EN (full specification)](./docs/whitepaper-en.md)
- [Whitepaper FR (specification complète)](./docs/whitepaper-fr.md)
- [Demo walkthrough](../../demo/selfdataguard/README.md)

---

## License

**AGPL-3.0-or-later**. See [LICENSE](../../LICENSE).

If you modify SelfDataGuard and offer your version to users over a network, you must give them access to its source code, under the same license (AGPL-3.0, section 13).
