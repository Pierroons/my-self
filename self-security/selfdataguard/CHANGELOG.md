# Changelog

All notable changes to SelfDataGuard are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [v0.5.1] — 2026-10-02

### Fixed — PHP 8.1 and 8.2: the storage left its transaction open

0.5.0 opened its transactions with `exec('BEGIN IMMEDIATE')` and closed them with
`PDO::commit()`. Before PHP 8.4, PDO does not see a transaction opened by `exec()`:
`commit()` threw « There is no active transaction », and the transaction stayed open —
the write neither committed nor rolled back, and the next write on that connection met an
inconsistent state. Reported by an integrator on Debian 12 (PHP 8.2): the first escrow
deposit failed. The CI ran PHP 8.4 only and could not see it.

The storage now closes in SQL what it opens in SQL (`COMMIT`, `ROLLBACK`) and tracks its own
transaction itself instead of asking PDO. A caller's transaction opened in plain SQL, which
PDO before 8.4 does not report either, is recognised by SQLite's refusal of a second
`BEGIN`, and the write nests in it through a savepoint. `sanity_storage` gains both cases:
a plain-SQL caller transaction, and a second connection that must read the committed write
and take the write lock.

### Added — the suites run on PHP 8.1 and 8.2 in CI

`composer.json` declares PHP 8.1 or later. A CI job now runs the ten SelfDataGuard suites,
the SelfRecover coupling check and the cross-module recovery walk on PHP 8.1 and 8.2, next to
the main job on 8.4.

## [v0.5.0] — 2026-10-01

A vault paired with SelfRecover can now survive every recovery path, provided the integrator
re-seals it (`recover()`) or re-enrols it (`reEnroll()`). Level 1 only gives the server the
passphrase, which opened no envelope; level 3 leaves no old secret at all, and the only way
out was to delete the vault.

### Added — a third lock: the SelfRecover passphrase

- `wrap_phrase` ← XChaCha20-Poly1305(master key, Argon2id(normalised passphrase,
  sha256(user_salt ‖ "/dataguard/passphrase")[:16])), AAD = userId like the other two.
  The context differs from the memorized word's: the same string set on both locks gives
  two unrelated keys.
- The passphrase is normalised exactly as SelfRecover's `Recovery::normaliserPassphrase()`
  does — edges trimmed, whitespace runs reduced to one space, no `/u` modifier (with it,
  U+00A0 and U+2003 would fold on one side only). `bi-self/selfrecover/tests/
  sanity_couplage_dataguard.php` holds the two together.
- `PASSWORD_MIN_LEN` applies when sealing a passphrase, never when unlocking.
- `UserVault`: `register(…, ?passphrase)`, `unlock(record, Lock, secret)`,
  `unlockWithPassphrase()`, `changePassphrase()`, `removePassphrase()` — removal is its own
  method, so that a recovery result without a new passphrase cannot drop the lock.
  `VaultRecord::$wrapPhrase` is the last constructor parameter, default `null`.
- Enum `Lock`: `password`, `memorized`, `passphrase`.

### Added — `recover()`: one call per SelfRecover recovery

`SelfDataGuard::recover(userId, Lock, secret, newPassword, ?newPassphrase)` opens the vault
with whatever secret the server holds, re-seals the password — and the passphrase if a new
one is given — in one conditional write. Level 1: the old passphrase. Level 2 by code: the
memorized-word digest. Level 2 by device, where the server holds nothing that opens the
vault: at the next secret the user gives, with the current password. On its own it is an
Argon2id oracle with no rate limit: call it right after SelfRecover accepted a recovery,
behind its counters, and rate-limit the device catch-up like the login.

### Added — a replaced vault is archived, not destroyed

- `reEnroll(userId, password, ?memorized, ?passphrase)` — level 3. Every Argon2id runs
  first, then one transaction sets the live vault aside and inserts the new one
  (`StorageInterface::replaceWithArchive()`). A failure anywhere changes nothing.
- An archive keeps the envelopes, private fields and escrow exactly as stored — nothing is
  decrypted — plus the Argon2id profile in force and a format version: it cannot be
  re-sealed after a profile change, nobody holds its key in between. That profile is the
  code's, since live vaults do not store theirs: change it only after re-sealing every live
  vault. Blind indexes are
  not archived; each field keeps `was_indexed` for a restore to index the same ones.
- `openArchive(current session, archiveId, Lock, oldSecret)` needs a session on the
  **current** vault and an old lock: old secrets are what may have leaked before a level-3
  recovery. `readArchive()` returns private fields, escrow fields, and the indexed names.
- `getArchiveEscrowFieldsAsAdmin()` mirrors `getEscrowFieldsAsAdmin()` for an archive.
- `listArchives()`, `deleteArchive(current session, id)`, `purgeArchives(userId)`. Any
  number of archives per account: a second level 3 does not destroy the first. Archive
  ids are random (128 bits).

### Changed — BREAKING (API and storage contract)

- `UnlockedVault` carries the `user_salt` of the vault it was opened on, a required
  constructor parameter. Every façade read and write, and every `UserVault::change*()`,
  refuses a session from another vault of the same userId (`StaleVaultException`) — for
  writes, inside the write's own transaction: `saveFields()`, `saveEscrow()`,
  `saveEscrowFields()` and `deleteArchive()` take the expected `user_salt`, and the façade
  passes it.
- `StorageInterface` gains `replaceWithArchive()`, `listArchives()`, `loadArchive()`,
  `deleteArchive()`, `purgeArchives()`. An implementation without them fails at load
  time. It must also persist `wrap_phrase`: the façade reads it back after every write
  and throws if it was dropped.
- `updateVault()` writes only where `user_salt` and `revision` still match the record's,
  never rewrites the salt, and bumps the revision: a record read before a re-enrolment
  cannot overwrite the new vault, and of two requests that read the same vault, the second
  to write gets `StaleVaultException` instead of silently undoing the first.
- `delete()` / `deleteVault()` leave archives. Erasing an account calls `purgeArchives()`
  too — a separate decision, so that a fraudulent level 3 followed by an account deletion
  cannot erase the legitimate holder's archives.
- Typed exceptions, all `RuntimeException` subclasses, so existing catches still work:
  `WrongSecretException`, `MissingEnvelopeException`, `VaultNotFoundException`,
  `StaleVaultException`.

### Changed — stored format (schema)

- `selfdataguard_vaults.wrap_phrase` and `revision`, as the **last** columns, in that
  order. A 0.4.0 database is migrated in place on first opening: `PRAGMA table_info`, then
  `ALTER TABLE … ADD COLUMN`, checked again under `BEGIN IMMEDIATE` so that two processes
  opening the same old database do not both add it. Inside a caller's transaction, a
  savepoint — and if the caller rolls back, the migration goes with it: build a new
  adapter.
- `archived_at` is stored in UTC, so that the order of archives survives a DST change.
- New table `selfdataguard_archives`, created like the 0.2.0 escrow tables were.

### Migration risk — rolling back to 0.4.0

- 0.4.0 does not see the archives, and its `deleteVault()` does not remove them.
- 0.4.0 rewrites vaults without naming `wrap_phrase`, which keeps its value: once
  SelfRecover replaces the passphrase, the consumed one keeps opening the vault.
  Roll back only a database with neither a passphrase lock nor an archive, or remove both
  first.

### Removed

- `Primitives::aesGcmEncrypt()` and `aesGcmDecrypt()`, deprecated in 0.4.0. Reading blobs
  written before 0.4.0 does not use them and is kept: it cannot be removed while an archive
  may hold such a blob.

### Security

- `#[\SensitiveParameter]` on the admin secret key (`getEscrowFieldsAsAdmin()`,
  `getArchiveEscrowFieldsAsAdmin()`, `EscrowVault::unlockAsAdmin()`): with
  `zend.exception_ignore_args=0`, PHP's built-in default, it appeared in plain in
  `getTrace()`.

### Fixed — concurrent audit appends broke the chain

`AuditLog::append()` read the chain outside the lock that guarded its write. Two
processes appending at the same moment took the same `seq` and `prev`, and `verify()`
failed from that entry on — measured: four writers of 150 appends each broke the chain
at its second entry, on every run. The read and the append now happen under one
exclusive lock. `tests/sanity_audit.php` gains the four-writer case. No format change:
existing logs verify as before.

### Fixed — the storage left a transaction open, could not write inside one, and failed under a concurrent writer

`saveFields()` raised a `RuntimeException` inside a transaction that only a `PDOException`
closed: the next `beginTransaction()` on the connection threw. And no write could happen
inside a caller's transaction. Writes now go through one helper that rolls back on any
`Throwable` and, inside a foreign transaction, uses a savepoint named after the instance.

That helper opens its own transactions with `BEGIN IMMEDIATE`. PDO's deferred `BEGIN` read
first and wrote second, and SQLite refuses that upgrade while another connection writes:
« database is locked » at once, the busy timeout ignored. Measured on a re-enrolment
during another write; it now waits for the lock.

### Fixed — two updates of the same vault lost one

`updateVault()` rewrote the whole row: a password change landing after a passphrase
removal put the revoked passphrase back. The `revision` column makes the second writer
fail instead (see « Changed — BREAKING »).

### Fixed — a whitespace-only passphrase

It reached the derivation and failed as « Memorized secret must not be empty ». It is now
refused as an empty passphrase, under its own name.

### Documentation

- **A password-only vault does not survive a SelfRecover recovery.** `register()` with the
  password alone seals the data key under that password; SelfRecover levels 1 and 2 replace
  it. The contract of `register()` and the README « Coupling with SelfRecover » say so, with
  `recover()` per path and `reEnroll()` at level 3. Reported by an integrator running both
  modules together; no vault was lost.
- **The Argon2id profile is not stored in live vaults.** Changing `ARGON2_OPSLIMIT` or
  `ARGON2_MEMLIMIT` makes every existing envelope fail as a wrong password would. Stated on
  the constants; archives store theirs.
- The README said the memorized word is « never transmitted in plain »: true of SelfRecover,
  not of SelfDataGuard, which receives its secrets server-side. It now says what the server
  receives on each path.

## [v0.4.0] — 2026-09-26

### Changed — BREAKING (stored format) — XChaCha20-Poly1305 for every write

Up to 0.3.0, every blob was AES-256-GCM through libsodium, which serves it only with
hardware support: AES-NI on x86-64, plus AVX since libsodium 1.0.19, and the ARMv8
crypto extensions since 1.0.19. On a CPU without them — a Celeron or Atom without
AVX under a recent libsodium, a Raspberry Pi 4 under any version — the library could
neither write nor read, and its error blamed AES-NI whatever the actual cause.

- `Primitives::encrypt()` writes XChaCha20-Poly1305 (IETF), computed in software, in
  constant time, on every CPU, with a random 192-bit nonce.
- **Blobs are versioned.** A new blob is stored as `SDG2.` followed by
  `base64(nonce ‖ ciphertext ‖ tag)`. `.` is not a base64 character, so no blob
  written before 0.4.0 can begin with the prefix. An unknown `SDG<n>.` prefix is
  refused as "written by a newer version".
- **Blobs written before 0.4.0 stay readable.** `Primitives::decrypt()` reads both
  formats: AES-256-GCM through libsodium where it serves AES, through OpenSSL
  elsewhere. When neither is available it throws `LegacyCipherUnavailableException`,
  which names the causes. `UserVault`, `EscrowVault` and the ceremony CLI let it
  through instead of reporting a wrong password or a bad passphrase.
- **No migration.** An existing blob stays AES-256-GCM until one of the existing
  write paths replaces it (password change, field update).
- ⚠️ **Once 0.4.0 has written a blob, 0.3.0 cannot read it.** It refuses it as
  invalid base64: the failure is loud, not a misread. Roll back only a database that
  0.4.0 has not written to.
- `Primitives::NONCE_LEN` is now 24.

### Deprecated

- `Primitives::aesGcmEncrypt()` and `aesGcmDecrypt()` delegate to `encrypt()` and
  `decrypt()`. Despite its name, `aesGcmEncrypt()` now writes XChaCha20-Poly1305.
  Both are removed in 0.5.0.

### Added

- `#[\SensitiveParameter]` on every key, plaintext, password, memorized secret and
  passphrase parameter. From PHP 8.2 they no longer appear in stack traces, whatever
  `zend.exception_ignore_args` says. Under PHP 8.1 the attribute is inert.
- `ext-openssl` in `suggest`: it reads AES-256-GCM blobs on a CPU where libsodium
  does not serve AES.
- `sanity_primitives.php`: the IETF XChaCha20-Poly1305 test vector
  (draft-irtf-cfrg-xchacha-03, A.3.1); a frozen AES-256-GCM blob that must decrypt
  forever; libsodium and OpenSSL reading each other's blobs. `sanity_vault.php`: a
  vault whose wrap was written as AES-256-GCM by OpenSSL still unlocks.

### Fixed

- `sanity_fields.php` flipped a byte after `base64_decode()` of the whole stored
  string. With a prefix, that decode also reads the letters of `SDG2`, and the
  "tampered blob" check passed because the blob was garbage, not because the tag
  failed. It now alters the ciphertext through `EncryptedBlob` and checks the reason.

## [v0.3.0] — 2026-09-07

### Changed — BREAKING (stored format) — the memorized secret is derived with Argon2id

`Primitives::deriveFromMemorized()` used a single HMAC-SHA256 pass, and its output
went straight in as the AES-256-GCM key that unwraps `data_master_key`. Measured
before and after on the same host: **0.0064 ms per attempt against 47.9 ms — a
factor of 7 492, about 13 bits of added work**. On a slower deployment host the
factor measured 78 100. Both keys open the same `data_master_key`, and `wrap_recov`
is attacked offline with no attempt counter, so the pair was only ever as strong as
its cheaper door. The password path is unchanged at 44.8 ms: the two doors now cost
the same, which was the point.

- `deriveFromMemorized()` now uses Argon2id with the same profile as
  `deriveFromPassword()`. The free-length context is condensed into the 16-byte salt
  Argon2id requires; a salt is not a secret and only has to be unique per target.
- **`wrap_recov` produced before this change cannot be opened by this version.**
  The public API is unchanged — callers still pass a plain string — but the stored
  format is not. No migration ships because none was needed: every deployment
  measured held zero recovery wraps. **This was not verified on every host**; see
  below.
- `deriveFromMemorizedLegacyV1()` is kept for DIAGNOSIS ONLY. It lets
  `unlockWithMemorized()` answer "this vault predates the Argon2id derivation,
  re-seal it" instead of "invalid memorized secret", which would send someone
  hunting for a typo in a secret that is correct. It never grants access, and a
  sanity control fails if it ever does.

### Added

- `UserVault::PASSWORD_MIN_LEN = 12`, enforced in `register()` and
  `changePassword()`. The whitepaper had promised this refusal since v0.1 and no
  line of code applied it. Length is not entropy — twelve identical letters clear
  the bar — and the error message says so rather than overselling the rule.

### Not done, on purpose

**No entropy floor is enforced on the memorized secret.** Argon2id buys a
multiplier, not entropy: a weak word is still ~13 bits of guessing plus ~13 bits of
cost. A floor high enough to matter (77 bits) would end the "one memorized word,
two uses" pairing with SelfRecover that the whitepaper sells elsewhere — a design
decision, not a setting. It is now stated as an open question in whitepaper §2.3
instead of being answered silently in either direction.

### Fixed — documentation that described something else than the code

`docs/whitepaper-fr.md` announced `Argon2id … p=4`, a parameter
`sodium_crypto_pwhash` does not expose; a refusal of passwords under 12 characters
and against breach lists that no code applied; and a 30-bit floor presented as a
recommendation with nothing behind it. The `p=4` claim is corrected with the reason,
the password rule now exists in code, no blocklist is claimed since none ships, and
the memorized-secret paragraph says plainly what is and is not enforced.

### Migration risk, and what was actually measured

Changing the derivation changes the stored format of `wrap_recov`. Whether that
costs anyone anything depends on one number — how many exist — so it was counted
rather than assumed, on 2026-09-06:

| host | vaults | `wrap_recov` | how |
|---|---|---|---|
| dev | 3 | **0** | `sqlite3 -readonly`, witness `SUM(wrap_pwd IS NOT NULL)` = 3 |
| public demo | 0 | **0** | `sqlite3 -readonly`, witness `pragma_table_info` = 1, no journal created |
| production NAS | — | **inferred 0** | no caller passes a memorized secret (grep with positive witness). `sqlite3` is absent from that host and its container, and opening a production database by other means can write a journal — so this one is a deduction, not a measurement, and is stated as such |

No migration therefore ships. Should a recovery wrap exist somewhere unmeasured,
its holder loses that door on upgrade and must re-seal via `changeMemorized()`
after unlocking by password — `unlockWithMemorized()` names that case explicitly
instead of reporting a wrong secret.

### Fixed before tagging — 2026-09-26

The version shipped on 2026-09-07 and was never tagged; the tag carries these too.

- **The public demo's API answered 500 on every call.** `demo/selfdataguard/api/_bootstrap.php`
  required an autoloader left behind when the library moved to `self-security/selfdataguard/`.
  It now loads the library where it lives; checked end to end on a copy (register, unlock by
  password and by memorized word, wrong word refused).
- The demo page announced v0.2.0 and described the memorized path as HMAC-SHA256.
- `composer.json` described "memorized HMAC".
- `docs/whitepaper-en.md` trailed the French edition: `p=4`, the SelfRecover formula in
  `/recover` instead of `|v2` + salt, independence argued from HMAC on both sides, and
  deployment rules (breach lists, a 30-bit floor) the library does not apply.

## [v0.2.0] — 2026-08-21

### Added — Escrow compartment (recovery-escrow sub-vault)

Consented, admin-recoverable subset of a user's vault (integration design cases "B'").
Lets a locked-out user's account be recovered by an admin — without ever
exposing the private zone.

- **Escrow layer** (`src/Escrow/`)
  - `EscrowVault::create/unlockAsUser/unlockAsAdmin` — a **dedicated escrow_key**
    (distinct from the vault master key), double-wrapped: `wrap_user` =
    AES-256-GCM(escrow_key, master_key) for daily user access; `wrap_admin` =
    libsodium anonymous sealed box to an admin recovery public key.
  - `AdminKey::generate/unseal` — admin recovery keypair whose **secret key is
    passphrase-sealed** (Argon2id), SU-secret model. Stored on the deployment
    server but useless cold without the admin passphrase.
  - `EscrowRecord` immutable envelope, `UnlockedEscrow` ephemeral session
    (auto-zeroize, anti-serialize), `EscrowFieldCrypter` (AAD `userId|escrow|field`).
- **Persistence** — `selfdataguard_escrow` + `selfdataguard_escrow_fields` tables,
  FK cascade on vault delete; `StorageInterface` extended with
  `saveEscrow/loadEscrow/saveEscrowFields/loadEscrowFields`.
- **Façade** — `generateAdminRecoveryKey`, `unsealAdminRecoveryKey`, `hasEscrow`,
  `setEscrowFields`, `getEscrowFieldsAsUser`, `getEscrowFieldsAsAdmin`.
- **Sanity tests** — `sanity_escrow.php` (16 tests): passphrase seal/unseal,
  user + 2FA read, admin recovery, **compartmentalisation** (escrow_key cannot
  read the private zone), **cold-seizure** resistance, at-rest opacity, delete cascade.

### Added — Recovery ceremony CLI + tamper-evident audit

- **`AuditLog`** (`src/Escrow/AuditLog.php`) — append-only, **hash-chained,
  HMAC-signed** journal of privileged escrow acts (SU-journal bar). Any delete,
  reorder or edit breaks `verify()`. Recommended ops hardening: `chattr +a`.
- **`bin/escrow-ceremony.php`** — recovery CLI (`su-cli` style). `unlock <user>
  <litige_id> [fields…]` enforces the cumulated policy: (1) an **open litige**
  for the user (anti-curieux), (2) the **admin passphrase** unseals the recovery
  key. Every act — success **or** refusal (`no-open-litige`, `bad-passphrase`) —
  is written to the audit log with operator + IP forensic. `verify-log`
  subcommand. Config via env; the litige gate reads a `litiges` table (the calling application
  wires its own). The library stays policy-free — gates live here.
- **Sanity tests** — `sanity_audit.php` (6) chain + tamper detection;
  `sanity_ceremony.php` (14, subprocess e2e) happy path, litige gate, passphrase
  gate, audit logging, `verify-log`, tamper caught.

### Added — Member-area profile module (escrow front)

- **`demo/coffre.html` + `demo/coffre.js`** — "Mon coffre" profile page showing
  the **two-zone** model: private E2E zone (read-only) + consented recovery-
  escrow zone with the explicit **consent text** (accessible to an admin only
  after a litige) and a deposit form for `contact_secours` / `indice_recup`.
  In-memory session only (secret never touches localStorage), 2FA aware.
- **Endpoints** `demo/api/coffre_open.php` (one auth → private + escrow) and
  `demo/api/escrow_set.php` (whitelisted escrow deposit). `_bootstrap.php` now
  provisions a demo admin recovery key (`storage/admin-recovery.pub` + `.sealed`).
- Verified end-to-end: web deposit → CLI admin recovery share the same admin key;
  DB dump stays plaintext-free.

### Design note — divergence from whitepaper §4.2 "Hybrid mode"

The reserved `VaultRecord::wrap_admin` slot (wrap of the **whole** data_master_key
for an admin) is intentionally **left unused**: it would let an admin read the
entire vault, breaking compartmentalisation. The escrow compartment supersedes it
with a **dedicated sub-key** — the admin recovers only the consented escrow
fields, never the private zone. Policy gates (open litige, SU audit logging) live
in the application/adapter, not in this library.

## [v0.1.0-beta] — 2026-05-08

First runnable release. Whitepaper-driven implementation of the SelfDataGuard envelope-encryption protocol, in PHP, with a clickable demo.

### Added

- **Cryptographic primitives** (`src/Crypto/`)
  - `Primitives::deriveFromPassword()` — Argon2id (m=64 MiB, t=3) per whitepaper §5
  - `Primitives::deriveFromMemorized()` — HMAC-SHA256 with mandatory contextual separator
  - `Primitives::aesGcmEncrypt/Decrypt()` — AES-256-GCM with 96-bit random nonce + AAD support
  - `Primitives::randomBytes()`, `secureCompare()`, `zeroize()`
  - Immutable `EncryptedBlob` value object with base64 round-trip

- **User vault layer** (`src/Vault/`)
  - `UserVault::register/unlockWithPassword/unlockWithMemorized/changePassword/changeMemorized`
  - `VaultRecord` immutable persistent state
  - `UnlockedVault` ephemeral session container with auto-zeroize, anti-serialize, lock lifecycle

- **Field encryption layer** (`src/Fields/`)
  - `FieldCrypter::encrypt/decrypt` and batch variants — per-field random nonce, AAD = `userId|fieldName`
  - `BlindIndex::compute/equals` — deterministic HMAC for SQL equality lookups, per-field key separation

- **Persistence** (`src/Storage/`)
  - `StorageInterface` contract (vault save/load/update/delete + fields batch + blind-index lookup)
  - `SqliteAdapter` reference implementation with auto-bootstrapped schema, FK cascade on delete, transactional batch upserts

- **Public façade** (`src/SelfDataGuard.php`)
  - One-line wiring: `new SelfDataGuard($storage, $blindKey)`
  - Methods: `register`, `loginWithPassword`, `loginWithMemorized`, `setFields`, `getFields`, `findUserByField`, `changePassword`, `changeMemorized`, `delete`, `userExists`

- **Standalone demo** (`demo/`)
  - HTML+CSS+vanilla JS UI with split-screen frontend / backend layout
  - Auto-refreshing raw-DB view after every action
  - 7 PHP API endpoints (register, login, find_user, change_password, inspect_db, delete, _bootstrap)
  - One-shot launcher `./run.sh` (PHP built-in server, no install)

- **Sanity tests** — 155 tests, runnable directly with `php` (no PHPUnit dependency)
  - `sanity_primitives.php` — 27 tests
  - `sanity_vault.php` — 33 tests
  - `sanity_fields.php` — 25 tests
  - `sanity_storage.php` — 36 tests, including the **"DB dump = soup"** end-to-end assertion
  - `sanity_facade.php` — 34 tests

- **Documentation**
  - `demo/README.md` — full walkthrough, troubleshooting, production gaps
  - Updated `README.{md,fr.md}` with quick-start snippets in PHP
  - Status badges bumped from `concept 0.0.1` to `beta 0.1.0`

### Notes

- `wrap_admin` field is reserved in the schema for **Hybrid mode** (whitepaper §4.2) but not yet wired through the façade. Planned for v0.2.0.
- This release is intended for **community cryptographic review** before any production use. A formal audit is targeted before v1.0.0.

## v0.0.1 — 2026-05-06 (untagged)

### Added

- Whitepaper EN + FR (specification, threat model, three operational modes)
- Initial README EN + FR
- Repository structure under `self-security/selfdataguard/`

[Unreleased]: https://github.com/Pierroons/my-self/compare/selfdataguard-v0.5.1...dev
[v0.5.1]: https://github.com/Pierroons/my-self/releases/tag/selfdataguard-v0.5.1
[v0.5.0]: https://github.com/Pierroons/my-self/releases/tag/selfdataguard-v0.5.0
[v0.4.0]: https://github.com/Pierroons/my-self/releases/tag/selfdataguard-v0.4.0
[v0.3.0]: https://github.com/Pierroons/my-self/releases/tag/selfdataguard-v0.3.0
[v0.2.0]: https://github.com/Pierroons/my-self/releases/tag/selfdataguard-v0.2.0
[v0.1.0-beta]: https://github.com/Pierroons/my-self/releases/tag/selfdataguard-v0.1.0-beta
