# Changelog

All notable changes to SelfDataGuard are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
