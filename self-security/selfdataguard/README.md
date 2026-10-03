# SelfDataGuard

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Application-layer data-at-rest protection that survives a database exfiltration.**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.6.0 available](https://img.shields.io/badge/status-v0.6.0%20available-brightgreen.svg)](#status)
[![Tests: 362 passing](https://img.shields.io/badge/tests-362%20passing-brightgreen.svg)](#testing)
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

SelfDataGuard implements **multi-lock key wrapping** inspired by Bitwarden, 1Password, and ProtonMail vault designs, adapted for application-layer per-user encryption. The same data key is sealed under each of the user's secrets, and any one of them opens it:

```
                 ┌─────────────────────────────────────┐
                 │      Per-user data_master_key       │  ← random 256 bits
                 │      (never stored in plain)        │  ← in memory only when user is logged in
                 └──────┬─────────────┬─────────────┬──┘
                        │             │             │
                              wrapped with each of
                        │             │             │
        ┌───────────────▼┐  ┌─────────▼──────┐  ┌───▼──────────────────┐
        │  password_key  │  │   recov_key    │  │      phrase_key      │
        │ Argon2id(      │  │ Argon2id(      │  │ Argon2id(            │
        │   password,    │  │  memorized,    │  │  passphrase,         │
        │   user_salt)   │  │  SHA-256(      │  │  SHA-256(user_salt + │
        │                │  │   user_salt +  │  │   "/dataguard/       │
        │                │  │  "/dataguard"))│  │    passphrase"))     │
        └────────────────┘  └────────────────┘  └──────────────────────┘
```

Argon2id takes a 16-byte salt: the first 16 bytes of this SHA-256. The two contexts differ: the same string set as memorized word and as passphrase yields two unrelated keys. The three wrap keys cost the same, on purpose: wraps are only as strong as the cheapest one. The Argon2id profile (3 passes, 64 MiB) is stored with each vault: a vault keeps its own at every re-seal, a new vault takes the current profile, and changing the constants no longer locks existing vaults out.

Each user has:

- A unique random `user_salt` stored in plain (identifier-grade)
- A `data_master_key_pwd_wrap`: XChaCha20-Poly1305 ciphertext of the master key, encrypted with the password-derived key
- A `data_master_key_recov_wrap`: the same, with the memorized-word-derived key (optional)
- A `data_master_key_phrase_wrap`: the same, with the passphrase-derived key (optional)
- Personal data fields encrypted field-by-field with `data_master_key`

**Database dump → cryptographic soup.** No combination of plain-text values in the dump yields the master key. The attacker would need one of the user's secrets — password, memorized word or passphrase, each Argon2id-hardened and salt-isolated — to decrypt anything.

---

## Coupling with SelfRecover

SelfDataGuard seals the data key on the secrets SelfRecover already gives the user: the memorized word and the level-1 passphrase. **Nothing more to remember.**

The memorized word never leaves the browser: the browser computes a digest of it, and the server receives that digest. The integrator hands it to SelfDataGuard as the "memorized" secret. It costs as much to attack as the word itself — HMAC, then Argon2id —, and the two derivations stay isolated:

```
memorized_word (in the browser only)
    │
    └─ HMAC-SHA256(key = word, msg = material + "|v2" + account salt)   →  digest (received by the server)
           │
           ├─ Argon2id(digest), random salt from password_hash()           →  SelfRecover verification
           │
           └─ Argon2id(digest, SHA-256(user_salt + "/dataguard")[:16])     →  recov_key (SelfDataGuard)
```

The passphrase reaches the server in plain at level 1. SelfDataGuard normalises it exactly as SelfRecover does: edges trimmed, whitespace runs reduced to one space.

Every SelfRecover recovery replaces secrets. The vault follows if the integrator re-seals it **after** SelfRecover has accepted the recovery, with the secret the server holds at that moment:

| SelfRecover recovery | The server holds | SelfDataGuard call |
|---|---|---|
| Level 1 — passphrase | the old passphrase | `recover($user, Lock::Passphrase, $old, $newPassword, $newPassphrase)` |
| Level 2 — code + memorized word | the word's digest | `recover($user, Lock::Memorized, $digest, $newPassword, $newPassphrase)` |
| Level 2 — enrolled device | a signature, nothing that opens the vault | at the next secret given: `recover($user, $lock, $secret, $currentPassword)`, rate-limited like the login |
| Level 3 — human escalation | no old secret | `reEnroll($user, $password, $digest, $passphrase)`: the old vault is **archived** |

`recover()` opens and re-seals in a single conditional write. The same call catches up a vault whose password wrap has fallen behind. Called on its own, it is an Argon2id oracle with no rate limit: call it right after a recovery SelfRecover has accepted, behind its counters, and rate-limit the level-2 device catch-up like the login.

**At level 3, the old vault is archived.** `reEnroll()` sets it aside, sealed under its old locks, with no expiry, and creates a new one. If the user later finds an old lock again, `openArchive($session, $id, Lock::Passphrase, $old)` then `readArchive()` give the data back. The session must be one on the current vault: through the service, an old lock alone opens nothing, because those secrets are precisely the ones that may have leaked. Given a database dump, it opens the archive offline. An archive is destroyed only by `deleteArchive()` or `purgeArchives()`; `delete()` leaves it.

> ⚠️ **A password-only vault does not survive a SelfRecover recovery.** Levels 1 and 2 replace the account password (`Recovery::parPassphrase()`, `Recovery::parCode()`). A vault registered as `register($user, $password)` alone has a single envelope, sealed on the old password: after the recovery it can no longer be opened, by anyone, and nothing in either library says so. When the two modules share an account:
> - pass `$memorized` and `$passphrase` to `register()`;
> - after every accepted recovery, call `recover()` as in the table above;
> - at level 3, call `reEnroll()`. The archive's memorized lock depends on the SelfRecover salt of that time, which level 3 replaces: keep it — as a field of the new vault, say — if that lock is to stay usable.

Recoveries are not the only paths that change a secret: when your service changes the password or renews the memorized word, call `changePassword()` or `changeMemorized()` as well, **after** SelfRecover's write. A forgotten path leaves a lock on a secret SelfRecover has already replaced.

> ⚠️ **`userId` is compared byte for byte.** It enters the AAD of every envelope and keys the database row: "Alice" and "alice" are two vaults. If your accounts ignore case, map the name to the account's own before every call; otherwise a vault created under one spelling escapes the re-seals called under the other, and nothing says so.

Without SelfRecover, SelfDataGuard still works, with whatever locks the application gives it.

---

## Operational modes — v0.6.0 implements one of them

| Mode | Server access to data | Trade-off | In the code |
|------|----------------------|-----------|-------------|
| **Lite** *(transparent for legacy stacks)* | Server decrypts during user sessions only | Server compromise during an active session = limited fan-out (one user at a time) | ✅ this is what the library does |
| **Hybrid** *(targeted at e-commerce)* | Operational fields (`email`, `shipping_address`) wrapped with admin operational key. Sensitive fields (`tel`, `KYC_doc`) require user session | Admin can fulfill orders; sensitive data remains zero-knowledge | ❌ specified, not written — the vault's `wrap_admin` is `null` (`UserVault::register()`) |
| **Full** *(zero-knowledge for high-assurance services)* | Server NEVER decrypts. All crypto runs in the browser, through libsodium compiled to WebAssembly — WebCrypto offers neither Argon2id nor XChaCha20-Poly1305 | Some workflows redesigned (no async transactional emails, push notifications instead) | ❌ specified, not written — the module carries no client-side code |

A deployment installing v0.6.0 therefore runs in **Lite**, whatever mode it aims for: the other two are described in the whitepaper (§4.2, §4.3) as a target, and no API parameter selects them.

One admin path does exist, outside this table: the **escrow** (`src/Escrow/`), a compartment with its own key that an administrator reopens under ceremony — open dispute, escrow passphrase, signed log. It yields that compartment only, never the private vault, and it is not Hybrid mode: nothing there is decrypted as a matter of routine. The escrow of an archived vault goes with the archive, and the administrator reopens it the same way (`getArchiveEscrowFieldsAsAdmin()`).

What the escrow holds since 0.6.0:
- **the admin seal names its account**: copied into another account's row, it is refused. A seal from before 0.6.0 still opens, and is re-sealed at its holder's next escrow write;
- **the admin passphrase is at least 12 bytes** when sealing (`UserVault::PASSWORD_MIN_LEN`); a key sealed earlier under a shorter passphrase still opens. The sealed key carries its Argon2id profile (`v2:…`);
- **the ceremony log is anchored off the machine**: `bin/escrow-ceremony.php verify-log` prints its head `seq:hmac`, which you write down elsewhere, and `verify-log --ancre seq:hmac` then refuses a truncated or rewritten log. Without an anchor, verification cannot see that the end of the log was cut off;
- **the field name `escrow` is reserved**: it is the context of the escrow envelope, and both `setFields()` and `getFields()` refuse it.

---

## Threat model at a glance

| Adversary | Without SelfDataGuard | With SelfDataGuard |
|-----------|----------------------|---------------------|
| SQL injection / IDOR / DB dump | Plain-text PII exposed | Encrypted soup |
| Backup tape stolen | Plain-text PII exposed | Encrypted soup |
| Insider DBA | Reads everything | Encrypted (cannot unwrap without one of a user's secrets) |
| Application root compromise (RCE) | Reads everything | Reads currently active sessions — that is Lite, the only mode in service |
| Compromised user endpoint (keylogger) | User credentials harvested | User credentials harvested → that user's data only (no fan-out) |
| Stolen passphrase paper | Account access through level 1 | The same, plus that user's data — offline too, given a dump. The passphrase is consumed at its first legitimate use, which replaces it |
| Coercion of admin to decrypt | All data at admin's discretion | In Lite there is no standing admin key: they would need a secret of every user. The escrow opens under ceremony and yields its compartment only |
| Database write: an old vault row put back | — | **Limit.** The restored row opens with the secrets it carried, a passphrase consumed since included. Nothing in the vault tells a restored revision apart: the defence is the integrity of the database and its backups (whitepaper, §8.1) |

---

## Status

**v0.6.0 — the Argon2id profile stored, an escrow bound to its account, an anchored log**, 3 October 2026.

Whitepaper complete (specification + threat model). PHP reference library implemented (3 883 lines across 25 files, PSR-4, PHP 8.1+, libsodium). Cryptographic primitives (Argon2id, HMAC-SHA256, XChaCha20-Poly1305, and AES-256-GCM to read blobs written before 0.4.0) covered by **362 checks across 11 suites**, run on PHP 8.1, 8.2 and 8.4, all passing. The keys of the three locks are held by frozen vectors, recomputed in CI by a second implementation (argon2-cffi). A clickable HTML demo is included to inspect the encrypted database in real time.

A 0.5.x database migrates in place the first time 0.6.0 opens it (`kdf_opslimit` and `kdf_memlimit` columns, at the former profile), and so does a 0.4.0 one, in one go. ⚠️ Rolling back to 0.5.x keeps access to the vaults and to the escrow on the holder's side, but the administrator can no longer open an escrow that 0.6.0 created or re-sealed, and 0.5.x cannot unseal an admin key generated by 0.6.0: see the [CHANGELOG](./CHANGELOG.md). Blobs written by 0.3.0 stay readable, through OpenSSL (`ext-openssl`) where libsodium refuses AES.

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
use Pierroons\SelfDataGuard\Vault\Lock;

require 'vendor/autoload.php';

$dg = new SelfDataGuard(
    storage:  new SqliteAdapter('sqlite:/path/to/db.sqlite'),
    blindKey: file_get_contents('/path/to/server-secret.bin')  // ≥32 bytes
);

// New user: password, memorized word (its digest), passphrase
$session = $dg->register('alice', 'correct horse battery staple', $digest, $passphrase);
$dg->setFields($session, ['email' => 'a@b.c', 'iban' => 'FR76...'], indexed: ['email']);

// Returning user
$session = $dg->loginWithPassword('alice', 'correct horse battery staple');
$fields  = $dg->getFields($session);  // ['email' => 'a@b.c', 'iban' => 'FR76...']

// After an accepted SelfRecover level-1 recovery
$session = $dg->recover('alice', Lock::Passphrase, $oldPassphrase, $newPassword, $newPassphrase);

// Level 3: the old vault is archived, a new one created
['unlocked' => $session, 'archiveId' => $id] = $dg->reEnroll('alice', $password, $digest, $passphrase);

// Indexed lookup, no plaintext required
$userId = $dg->findUserByField('email', 'a@b.c');  // 'alice' or null
```

Three primary classes exposed: `SelfDataGuard` (façade), `SqliteAdapter` (storage; implement `StorageInterface` for MariaDB / Postgres), `Primitives` (raw crypto if you need to build something on top). Each failure has its own exception type: `WrongSecretException`, `MissingEnvelopeException`, `VaultNotFoundException`, `StaleVaultException` (a session opened on a vault that has since been replaced).

---

## Testing

Eleven sanity test suites, runnable directly with `php` (no PHPUnit required):

```bash
php tests/sanity_primitives.php   # 46 tests — Argon2id, HMAC, XChaCha20-Poly1305 + IETF vector, legacy AES-GCM, randomness
php tests/sanity_vault.php        # 55 tests — three locks, rotation, context separation, AAD binding, vault generation and profile
php tests/sanity_fields.php       # 26 tests — field encrypt/decrypt + blind index
php tests/sanity_storage.php      # 60 tests — SQLite adapter, nested transactions, conditional write (generation, revision), "DB dump = soup" test
php tests/sanity_migration.php    # 15 tests — 0.4.0 and 0.5.x databases migrated in place, two concurrent migrators
php tests/sanity_archive.php      # 26 tests — archive: content, isolation, all-or-nothing, concurrent writer waited for
php tests/sanity_facade.php       # 60 tests — full API end-to-end, recover(), level 3, write race, storage that drops a column
php tests/sanity_audit.php        # 18 tests — audit log, anchor against truncation
php tests/sanity_ceremony.php     # 17 tests — key ceremony
php tests/sanity_escrow.php       # 30 tests — escrow compartment, v2 admin key, seal bound to its account
php tests/sanity_vecteurs.php     # 9 tests — keys of the three locks against frozen vectors
# Total: 362 tests, 0 failures — counted by running them, 2026-10-03
python3 tests/vecteurs_argon2.py  # recomputes the vectors with argon2-cffi, a second implementation
```

The `sanity_storage.php` suite includes a "BIG TEST" that dumps the SQLite file and verifies that no plaintext personal data appears anywhere in the binary blob. On the SelfRecover side, `bi-self/selfrecover/tests/sanity_parcours_dataguard.php` takes a vault through every recovery, on the real paths of both libraries.

---

## Documentation

- [Whitepaper EN (full specification)](./docs/whitepaper-en.md)
- [Whitepaper FR (specification complète)](./docs/whitepaper-fr.md)
- [Demo walkthrough](../../demo/selfdataguard/README.md)

---

## License

**AGPL-3.0-or-later**. See [LICENSE](../../LICENSE).

If you modify SelfDataGuard and offer your version to users over a network, you must give them access to its source code, under the same license (AGPL-3.0, section 13).
