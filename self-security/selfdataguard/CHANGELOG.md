# Changelog

All notable changes to SelfDataGuard are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

## [v0.0.1] — 2026-05-06

### Added

- Whitepaper EN + FR (specification, threat model, three operational modes)
- Initial README EN + FR
- Repository structure under `self-security/selfdataguard/`

[v0.1.0-beta]: https://github.com/Pierroons/my-self/tree/main/self-security/selfdataguard
[v0.0.1]: https://github.com/Pierroons/my-self/blob/v0.0.1/self-security/selfdataguard/
