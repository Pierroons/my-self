# Changelog

All notable changes to SelfDataGuard are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The AAD becomes a required argument, and a ciphertext bound to nothing says so. This is a hardening
by construction, not the fix of an exploitable flaw: the uses that shared a flat AAD were sealed under
different keys, and the only same-key case, a field named `escrow`, was already refused by name.

Every write to a live vault now names the vault it is meant for. The check was optional: a storage
implementation could leave it out, and a write from a replaced vault then landed in the new one, under
a key that cannot read it. It is now a required parameter of the contract, and the façade reads back
the salt, the revision and every wrap it wrote. An indexed value now names one account: a second
account can no longer take the address of the first, and a lookup no longer picks between them.
A vault's Argon2id profile and envelopes are sealed under a server key: changed in the database, they
are reported as tampering before any derivation, instead of failing every lock as a wrong secret —
which sent the holder down to level 3. A database records which blind-index key and which shared
fields it was first opened with, and refuses a façade that brings others. No object that holds a key has an accessor for it any more. Two first escrow
deposits made at once no longer lose one of them. A ceremony checks the audit log against the last head
its witness took before it writes anything. A deleted account's name no longer brings its archives to
whoever registers it next.

Code that calls `Primitives`, builds an `EncryptedBlob`, implements `StorageInterface` or `HeadWitness`,
or calls `reEnroll()`, `delete()`, `listArchives()`, `deleteArchive()`, `purgeArchives()`, `hasEscrow()`
or `readArchive()` changes; `findUserByField()`, `setFields()`, `register()` and `reEnroll()` throw where
they did not.

### Changed

- **BREAKING** — `Primitives::encrypt(string $plaintext, string $key, Aad $aad)` and
  `Primitives::decrypt(EncryptedBlob $blob, string $key, Aad $aad)`: the AAD was `?string $aad = null`,
  and `null` became an empty string. The safe call was the one you had to remember to write, and a
  ciphertext bound to nothing could not be told from one bound to an empty context.
  `legacyDecryptSodium()` and `legacyDecryptOpenssl()` take an `Aad` too.
- **BREAKING** — `EncryptedBlob::__construct()` requires `int $format` (`FORMAT_V1`, `FORMAT_V2`,
  `FORMAT_V3`). The nonce length no longer tells v2 from v3.
- `encrypt()` writes format v3 only (`SDG3.`). v1 and v2 blobs are still read, and are rewritten as v3
  only when the value is written again.
- Each use binds its own domain: `vault-wrap`, `escrow-wrap`, `field`, `escrow-field`, and the sealed
  admin key. Each segment is prefixed with its length, so `(alice, escrow|email)` and
  `(alice, escrow, email)` no longer give the same bytes.
- **BREAKING** — `StorageInterface`: every write to a live vault takes a `VaultGeneration` first
  (`saveFields`, `createEscrow`, `replaceEscrow`, `saveEscrowFields`, `deleteVault`, `deleteArchive`), compared inside the
  write's own transaction. It was `?string $vaultSalt = null`, and `null` skipped the check.
  `replaceWithArchive(VaultGeneration $replacing, VaultRecord $new): string` archives exactly that
  generation: two re-enrolments that read the same vault no longer both succeed, each archiving what
  the other made. `updateVault()` returns the revision it stored; `saveVault()` throws
  `VaultExistsException`, never an upsert.
- **BREAKING** — `StorageInterface::saveEscrow()`, an upsert, becomes `createEscrow()` — insert only,
  `EscrowExistsException` — and `replaceEscrow()`, which needs an escrow there (`EscrowNotFoundException`)
  and re-seals the same escrow key. Measured before: two first `setEscrowFields()` at once each drew an
  escrow key, the second replaced the first, and the fields of the first deposit no longer opened, for
  the holder or through the ceremony — both calls having returned. The deposit that loses the creation
  now reads the escrow that won, and writes under its key.
- **BREAKING** — `reEnroll(VaultGeneration $replacing, …)`: the generation comes from
  `generationOf($userId)`. A userId with no live vault goes through `register()`, and the archive id is
  never null. The vault it sets aside goes through the seal check of an opening: one without a seal, or
  whose seal does not hold, is refused (`VaultTamperedException`) until an operator runs `sealProfile()`
  or `repairProfile()`. Measured before: a level 3 archived a vault under a profile changed in the
  database, and nothing could repair the archive.
- **BREAKING** — `register()` refuses a userId that still holds archives (`VaultExistsException`). After
  `delete()`, whoever registered the name next listed its archives and read them through the service
  with an old secret of the first holder. `eraseAccount()` erases them first — an operator's decision.
- **BREAKING** — `delete(VaultGeneration $generation)`: the holder passes `$session->generation()`, an
  operator `generationOf($userId)`.
- **BREAKING** — `listArchives(UnlockedVault $current)` and `hasEscrow(UnlockedVault $session)` need a
  session on the current vault. Without one, they told anyone how many times an account had been
  re-enrolled, which locks open each archive, and whether it holds an escrow.
- **BREAKING** — `deleteArchive(UnlockedArchive $opened)`: the archive must be opened with one of its old
  locks. A session on the current vault was enough, and after a fraudulent level 3 that session is the
  fraudster's.
- **BREAKING** — a blind index names one account. `StorageInterface::saveFields()` refuses a value
  another account holds for the same field (`DuplicateIndexException`, naming every such field of the
  batch; nothing of it is written), and the shipped adapter enforces it with a unique index.
  A third party who knew the victim's address indexed it on their own account, and after the
  victim's level 3 the lookup named them. `findUserIdByBlindIndex(): ?string` becomes
  `findUserIdsByIndex(): list<string>`: the storage no longer picks a row.
- **BREAKING** — `findUserByField()` throws `AmbiguousLookupException` when several accounts hold the
  value — a database written before 0.7.0, or a storage without the constraint — and
  `InvalidArgumentException` on a field declared shared.
- **BREAKING** — `readArchive()` no longer returns `indexed`. It came from the archive package, which
  whoever writes the database can extend to a field never meant to be looked up: restore with the
  fields your application indexes.
- **BREAKING** — a vault carries a seal (`profile_seal`, an HMAC over the userId, the salt, the
  Argon2id profile and every envelope, present or absent, under a key derived from the blind-index key
  by HKDF). The façade checks it before any derivation and throws `VaultTamperedException` — not
  `WrongSecretException` — when it does not hold, or is missing; every write seals the envelopes it
  leaves. Measured before: a profile changed within the bounds made password, memorized word and
  passphrase each fail as a wrong secret, and the archive made at level 3 kept the false profile; an
  envelope removed from the row, or from an archive, answered as an absent lock while the others
  opened. An archive keeps the row as it was, and so a seal over all of it.
  **A vault written before 0.7.0 opens to no one until an operator runs `sealProfile()` on it.** A
  vault or an archive with an envelope in format v3 was written by a version that seals everything:
  without a seal, the seal was removed — tampering, which `sealProfile()` refuses to seal over.
- **BREAKING** — a database records a check of its blind-index key (an HKDF output, from which the key
  is not computed back) and its `sharedIndexes`, through `StorageInterface::claimSetting()`. A façade
  that brings another key, or another list, is refused when it is built (`InvalidArgumentException`,
  naming which). An empty database records those of the first façade opened on it. **A database that
  already holds vaults and recorded no key — written before 0.7.0 — is not opened until an operator
  adopts its key with `SelfDataGuard::adoptBlindKey()`**, which proves it first: by a lookup the
  database answers (this value, indexed under this key, names this account), by a seal that holds
  under it, or — for a database where no field carries an index — on the operator's word. A database
  that indexes a field refuses that word, and refuses a `sharedIndexes` that omits a field carrying
  shared indexes; a refused lookup names its four possible causes — the key, the field, the value or
  the account.
  Measured before: under another key, every vault and every archive reported tampering one after the
  other, and lookups answered "absent"; under another list, one field was written under two rules; and
  a first façade brought to a 0.6 database with the wrong key recorded it, its lookups answered
  "absent" without an error, and the right key was refused afterwards. A façade reads the settings of a
  database that holds them, and writes nothing there: a read-only copy opens.
- **BREAKING** — no derivation runs under a profile outside `Primitives::PROFILE_FLOOR_*` (the lowest
  profile ever written) and `PROFILE_CEILING_*` (four times the current one): `VaultTamperedException`,
  or `InvalidArgumentException` for a sealed admin key. A stored profile was unbounded, and its cost
  escapes PHP's limits: measured, 3 / 256 MiB took the process from 33 to 296 MiB of resident memory
  under `memory_limit=32M`, and `max_execution_time` only fired once the derivation was over. That
  profile is the ceiling itself: size the host for it, per concurrent derivation.
- **BREAKING** — `UnlockedVault` carries the profile it was opened under (`kdfOpslimit`, `kdfMemlimit`,
  required by its constructor), and a re-seal uses it instead of the profile read from the database
  at that moment. A record claiming another profile than its session stops the re-seal.
- **BREAKING** — `UnlockedVault::getMasterKey()`, `UnlockedEscrow::getEscrowKey()` and
  `UnlockedArchive::session()` are gone. The object that holds the key does the operation:
  `UnlockedVault::encryptField()` / `decryptField()` / `sealLock()` / `createEscrow()` /
  `openEscrow()`, `UnlockedEscrow::encryptField()` / `decryptField()` / `sealToAdmin()`,
  `UnlockedArchive::decryptFields()` / `openEscrow()`. None takes an AAD or a key from the caller.
  What goes is the accessor, not every way out: a key still leaves its object sealed under a secret.
  `sealLock()` under a secret of the caller's choosing, then `UserVault::keyFor()`, gives the master key
  back, as the stored wrap and the password do; `sealToAdmin()` to a key pair of the caller's gives the
  escrow key. Within the process, this is an interface, not a boundary. `FieldCrypter` and
  `EscrowFieldCrypter` keep their signatures and delegate.
- **BREAKING** — `EscrowCeremony` is the only path of the library to an escrow on the administrator's
  side. The admin key is unsealed there, in private methods, after the arbiter's verdict, after the
  intention is written to the audit log and forced to disk, and after the head witness took that
  head out of the machine. `SelfDataGuard::unsealAdminRecoveryKey()`, `getEscrowFieldsAsAdmin()`,
  `getArchiveEscrowFieldsAsAdmin()`, `EscrowVault::unlockAsAdmin()` and `AdminKey::unseal()` are
  gone. Its pieces: `EscrowArbiter` (a named `Verdict` — open, closed, expired, unknown,
  unavailable; only open unseals), of which SelfDataGuard ships none: an arbiter written for every
  deployment would say yes to all of them, and one that throws answers `Unavailable`, the exception's
  class in the intention; `HeadWitness`, required, which takes each head out of the machine and gives
  back the last one it took (`lastHead()`) — never `null` once it took one, and refusing a head that
  does not advance —, and `NoHeadWitness` naming its absence; `EscrowRefusedException`. The intention
  is appended only if the log still holds the witness's last head, checked under the write's lock: a
  log cut or rewritten since stops the ceremony, and the refusal is logged (`log-behind-witness`). A
  witness that holds no head where the log shows a witness took one — of any class but `NoHeadWitness`,
  an intention on the last line included — is refused (`witness-forgot`), and so is a head that is not
  one (`witness-failed`). `EscrowCeremony::seedWitness($anchor)` (`seed-witness --ancre` in the CLI)
  is the operator's act for a new witness, or one that lost its store: once the log is found to hold
  the anchor noted outside, the witness takes the log's head, logged as a ceremony is.
  Measured before: the next ceremony chained on what was left, and handed the witness a lower head
  that `verify-log` then found intact. The arbiter's class, its verdict and the witness's class are in
  the log. Against the administrator alone — the sealed file and its passphrase — it holds nothing:
  a second secret is a later lot.
- **BREAKING** — `bin/escrow-ceremony.php` takes the arbiter and the witness from files the operator
  names (`DATAGUARD_ARBITER_FILE`, `DATAGUARD_WITNESS_FILE`), and builds no façade: the blind-index
  key of zeros it fell back on is gone with it. It read a `litiges` table SelfRecover never had —
  every ceremony against a real SelfRecover database was refused. New commands: `archives <user>
  <case>` and `unlock-archive <user> <archive> <case>`, arbitrated and logged like a read.
- **BREAKING** — two gestures on a lock, in place of five methods that did not mirror one another:
  `setLock(UnlockedVault, Lock, string $secret)` seals a lock or replaces it, and
  `removeLock(UnlockedVault, Lock): bool` takes it off — never the password, and without a write when
  the vault does not have that lock. `changePassword()`, `changeMemorized()` (whose `null` removed),
  `changePassphrase()` and `removePassphrase()` are gone, from the façade and from `UserVault`.
- An absent lock answers as a wrong secret does: `MissingEnvelopeException` now extends
  `WrongSecretException`, carries the same message byte for byte, and is raised after the same
  derivation. Measured before: no derivation and a message naming the missing lock — whoever knew a
  name learned which locks the account carries, from the reply or from its time (42 ms against
  42 ms now). The class still tells them apart for the integrator's own log; a reply that differs on
  it gives the oracle back. Code that caught `MissingEnvelopeException` after `WrongSecretException`
  no longer reaches that branch.
- **BREAKING** — `AuditLog::verify(string $anchor)` takes its anchor; `verifyUnanchored()` names the
  check that cannot see a cut tail. Both report `quarantined`.
- `AuditLog::append()` puts in quarantine a tail of lines that do not have the shape of an entry — what
  a write cut short by a crash leaves —: a signed entry, chained on the last verified one, that records
  how many lines it sets aside and their SHA-256, then its act. It first writes the end of line the cut
  write lacks. Measured before: a line that was not JSON made every append throw, and a JSON line signed
  with the wrong key, at the end, had the next acts chain on it. An entry whose signature does not hold,
  or a signed entry where the chain does not lead, is a break: never quarantined, `append()` refuses,
  `verify()` says `chain`. Bytes changed after their quarantine break it too. The event key
  `AuditLog::RESERVED_KEY` is the log's own.
- `AuditLog::append(array $event, ?string $anchor = null)`: given a head, it appends only if the log
  still holds it, checked under the write's lock, and throws `AuditAnchorException` (`truncated` or
  `rewritten`) otherwise. `readAll()` returns the chain `verify()` walks —
  quarantined lines are not entries — and refuses a log broken in the middle.
- `StorageInterface::updateVault()` writes the record's profile and seal as well as its wraps.
- The façade's read-back compares the salt first, then the revision, then every wrap (`wrap_pwd`,
  `wrap_recov`, `wrap_phrase`) and the Argon2id profile, after every write — `changePassword()` and
  `changeMemorized()` were not read back. A vault returned for another userId than the one asked for
  is refused: a case-insensitive collation on `user_id` opens it under another spelling.

### Added

- `Aad::of(string ...$segments)` binds the ciphertext; it refuses no segment at all. `Aad::none()` says
  explicitly that nothing binds it, and differs from `Aad::of('')`. `Aad::legacy(string $raw)` rebuilds
  the flat AAD of a v1 or v2 blob in order to read it; `encrypt()` refuses it.
- The v3 AAD carries a domain: rewriting a stored blob's prefix to another format makes its tag fail.
- `decrypt()` refuses an `Aad` that does not match the blob's format, and names the mismatch.
- `Primitives::decryptAcrossFormats($blob, $key, $current, $legacyAad)` routes a v3 blob to `$current`
  and an older one to `Aad::legacy($legacyAad)`.
- `EncryptedBlob::bindsItsContext()`, true for a format v3 blob, whose AAD carries a domain.
- `FieldCrypter::aadFor()`, `EscrowFieldCrypter::aadFor()` and `EscrowVault::wrapAad()` are public, for
  tools that read or migrate stored blobs.
- `VaultGeneration` (userId + user_salt), from `UnlockedVault::generation()`, `VaultRecord::generation()`
  or `SelfDataGuard::generationOf()` — the last one for an operator or a level-3 flow, never for a
  route a visitor reaches.
- `eraseAccount(string $userId): int` erases the live vault and every archive in one transaction, and
  works on a userId left with archives only.
- `sealProfile(string $userId): bool` — the operator's step after upgrading: it seals a vault written
  before 0.7.0, trusting the row as it stands. `repairProfile(string $userId): bool` puts back the
  profile a seal vouches for, trying `Primitives::PUBLISHED_PROFILES` — an HMAC each, no Argon2id, no
  secret of the holder. An archive keeps the seal of its vault; one taken before 0.7.0 has none, and
  is opened under its recorded profile as before. `openArchive()` derives under the profile the seal
  vouches for, trying the published profiles when the recorded one does not hold: an archive whose
  profile was changed in the database, while it was taken or since, opens under the true one, and
  nothing reports the change. `listArchives()` reads the locks of an
  archive from the archive itself, the bytes its opening uses; a package that no longer reads is
  refused as tampering, naming the archive, where a `JsonException` or a `TypeError` reached even
  `register()`. `adoptBlindKey()`, `StorageInterface::loadSetting()`, `hasVaults()` and
  `indexedFields()` are new.
- `Storage\Conformance` and `bin/storage-conformance.php <connection.php>`: the contract played against
  an implementation — 21 cases, six of them racing four processes and each played ten times, among them
  a first escrow created twice at once, writes that name the generation a level 3 replaces at the same
  instant, and one setting claimed by several at once. One race let a storage that checks, then writes
  without a pause, through about once in ten: a pass stays a presumption, which ten rounds make a strong
  one. A storage
  that compared the generation outside its write's transaction passed every case of the first version
  of the suite. An implementation that does not
  pass it is not conformant. It writes under random `conformance-<hex>-…` userIds and erases each of
  them, so it runs against a database that already holds accounts; the three settings it claims stay,
  under names of the same form, which nothing reads.

- `new SelfDataGuard($storage, $blindKey, sharedIndexes: ['phone'])`: the indexed fields several
  accounts may hold the same value of. They are stored as `sharedIndex`, never name one account, and
  are looked up with `findUsersByField(): list<string>`.
- `SqliteAdapter` gains `selfdataguard_fields.shared_index` and the unique index
  `selfdataguard_fields_blind_unique`, in place on opening. A database where one value is held by
  several accounts is refused, with the fields named: see below.

### Removed

- **BREAKING** — `purgeArchives()`: `eraseAccount()` replaces it.

### Tests

- 605 checks across thirteen suites on PHP 8.4 (was 388 across eleven): primitives 46 → 56, escrow
  36 → 63, audit 23 → 35, ceremony 22 → 33, facade 67 → 116, storage 60 → 64, migration 15 → 37, archive 26 → 30, vault 58 → 64, `sanity_keys` (20) —
  a closed list of the public methods of each key-holding class, each of them called with a known
  key inside and the key looked for in what comes back, and no public method of the object layer
  taking a key but `adoptBlindKey()`, which takes the server key as the constructor does; seen failing
  on four planted defects —, and
  `sanity_conformance` (52), which plays the suite against the shipped adapter and against twenty-one
  storages that each break one clause: each fails on the case that names it. The racing cases catch the five that check, then
  write in a second statement — they pass every sequential case. Three storages written wrong on purpose — a case-insensitive
  `user_id`, an insert overwritten before its read-back, an update reported and never written — are
  each refused by the façade. Two properties had no test: a v2 blob written by 0.6.x still reads (a frozen string,
  also read by 0.6.2's code), and an archived ciphertext planted back into the live vault fails,
  because `reEnroll()` draws a new master key. The vaults "written before 0.7.0" of `sanity_migration`
  are written as 0.6.x wrote them, format v2 under the bare userId; they were format v3, which no
  released version wrote. Five canaries were seen failing, each on its own case, and forty more,
  one per fix made after review, each on its line.
- The libsodium/OpenSSL cross-check in `sanity_primitives` gives each read its own `try`: a regression
  in the legacy path no longer stops the suite before the checks that follow.

### Migration risk — rolling back to 0.6.2

Measured on four databases, read back by 0.6.2:

- Created by 0.6.2, then opened and read by 0.7.0: 0.6.2 still opens it. Reading writes nothing.
- One field written by 0.7.0: 0.6.2 still logs in and reads the other fields by name, but a read of
  every field fails.
- A vault created by 0.7.0, or re-sealed by it (a password change, or any other re-seal path): 0.6.2
  can no longer log in.

Each failure is loud (« Blob format SDG3 is unknown to this version of SelfDataGuard »); nothing is
misread. Do not roll back after a write.

### Before upgrading

- Code that calls `Primitives` directly passes an `Aad::of(...)` for every new write. Data written
  before 0.7.0 is read with `decryptAcrossFormats()` and the flat string used before.
- A `StorageInterface` implementation of your own takes the new signatures, and compares the
  generation inside each write's transaction — throwing `VaultNotFoundException` when the userId has
  no live vault, `StaleVaultException` when it has another. A generation received and not compared
  breaks nothing visible: run `bin/storage-conformance.php` against it before it serves anyone.
  `saveEscrow()` splits into `createEscrow()`, enforced as an insert by the storage itself (a unique
  key on `user_id`), and `replaceEscrow()`. `claimSetting()`, `loadSetting()`, `hasVaults()` and
  `indexedFields()` are new: the first value written under a name stays, enforced by a unique name,
  and a database that holds it is read without a write.
- A `HeadWitness` of your own implements `lastHead()`: the last head it took, as `seq:hmac`, or `null`
  before its first — never after. Its `witness()` refuses a head that does not advance. Its store lives
  off the machine, append-only, and is never restored from a backup: put back to an older head, it
  vouches for a log cut back to that head. A new witness, or one that lost its store, is seeded with
  `seed-witness --ancre <seq:hmac>`, the head noted at the last verification. A new log secret opens a
  new log: verify the old one a last time against its anchor and keep it, start the new one in a new
  file, and empty the witness's store — a log with no intention takes a witness that holds none.
- **A database written before 0.7.0 adopts its blind-index key first**, before any façade opens it:
  `SelfDataGuard::adoptBlindKey($storage, $blindKey, $sharedIndexes, $userId, $field, $value)`, with a
  value you know is indexed for that account, written exactly as it was indexed; `$sharedIndexes` names
  every field that carries shared indexes. Only a database where no field carries an index adopts
  `adoptBlindKey($storage, $blindKey, $sharedIndexes)` on your word. A wrong key is refused there,
  before it can be recorded.
  Keep the key with the database's backups: every seal and every index is computed under it, and a
  façade that brings another is refused. A read-only copy of a database that recorded its key opens.
- Every write of a vault row goes through the façade: one written beside it, through `UserVault` and
  the storage, is refused as tampering.
- Changing the shared fields later is a migration: move the values of the field between
  `blind_index` and `shared_index`, and write the new list, sorted, as JSON, under
  `selfdataguard.shared-indexes` in `selfdataguard_settings`, in one transaction.
- **After upgrading, before serving**: call `sealProfile($userId)` once for every account. Until then
  its vault opens to no one, as a tampered one would. The call trusts each row as it stands: run it on
  a database nobody else is writing to.
- **Before opening a database with 0.7.0**, find the values several accounts hold — the adapter
  refuses to open while any is left. On a table written before 0.7.0, add the column the second remedy
  below writes to first: `ALTER TABLE selfdataguard_fields ADD COLUMN shared_index TEXT;`

  ```sql
  SELECT field_name, blind_index, GROUP_CONCAT(user_id) FROM selfdataguard_fields
  WHERE blind_index IS NOT NULL GROUP BY field_name, blind_index HAVING COUNT(*) > 1;
  ```

  For each, either leave the value to its holder (`UPDATE selfdataguard_fields SET blind_index = NULL
  WHERE user_id = :other AND field_name = :field`), or declare the field shared — move every value of
  it (`UPDATE selfdataguard_fields SET shared_index = blind_index, blind_index = NULL WHERE field_name
  = :field`) and pass it in `sharedIndexes`. Which account owns a value is not something the library
  can decide.
- Index a value once the account has proven it holds it (a confirmation mail, a code): whoever
  indexes it first keeps it, and `DuplicateIndexException` tells the second one it is taken.
- `changePassword($s, $p)` → `setLock($s, Lock::Password, $p)`; `changeMemorized($s, $m)` →
  `setLock($s, Lock::Memorized, $m)`, and with `null` → `removeLock($s, Lock::Memorized)`;
  `changePassphrase()` and `removePassphrase()` alike.
- Code that read the master key or the escrow key calls the method that does the operation instead;
  a test that compared keys compares what they open.
- `AuditLog::verify()` without an anchor → `verifyUnanchored()`.
- An admin read goes through `new EscrowCeremony($storage, $log, $arbiter, $witness, $operator)` and
  its `readEscrow()` / `readArchiveEscrow()` / `listArchives()`; the CLI needs
  `DATAGUARD_ARBITER_FILE` and `DATAGUARD_WITNESS_FILE`, and no longer reads `DATAGUARD_BLINDKEY_FILE`.
- Façade calls: `reEnroll($dg->generationOf($id), …)`, `delete($session->generation())`,
  `listArchives($session)`, `hasEscrow($session)`, `deleteArchive($dg->openArchive(…))`,
  `eraseAccount($id)` for `purgeArchives($id)`.
- An application that gives a name back after `delete()` has an operator run `eraseAccount()` first:
  `register()` refuses a name that still holds archives.

## [v0.6.2] — 2026-10-15

A patch release. No signature changes.

### Fixed
- `AuditLog::append()` calls `fsync()` after `fflush()`. `fflush()` hands the line to the kernel, not to
  the disk: a power cut could lose the entry while the act it records stayed done. An append that
  cannot be forced to disk now raises, like a short write.

### Tests
- 388 checks across the eleven suites on PHP 8.2 and 8.4 (was 386), 384 on PHP 8.1. The new check was
  seen failing on 0.6.1 first. CI now requires each suite's exact total on PHP 8.4, not only its exit
  code: a suite that skips a check, a suite with no declared total and a total with no suite all fail
  the build. On PHP 8.1 and 8.2 it still relies on the exit code.

## [v0.6.1] — 2026-10-14

A patch release. No signature changes.

### Security
- `UnlockedVault` and `UnlockedEscrow` mask their key in `var_dump()` and `print_r()` (`__debugInfo()`)
  and refuse to be cloned: a copy would carry the key out of `lock()`'s reach. `var_export()` reads
  properties directly and is not covered; the rule is still never to log these objects.
- The facade and `AuditLog` mask `blindKey` and `auditSecret` the same way.
- `blindKey` (facade constructor, `BlindIndex::compute()`) and `auditSecret` (`AuditLog` constructor)
  carry `#[\SensitiveParameter]`: an exception raised there no longer copies them into its trace. The
  0.6.0 entry announced the attribute on every parameter that carries a secret; these three were
  missing.
- ⚠️ The attribute only takes effect from PHP 8.2. On PHP 8.1, which SelfDataGuard supports, it is
  ignored: a trace there still carries these secrets, and only `zend.exception_ignore_args` strips
  them. The trace checks say so on 8.1 instead of failing.

### Tests
- 386 checks across the eleven suites (was 373). Every new check was seen failing on 0.6.0 first. The
  trace checks force `zend.exception_ignore_args` off and first check that a public argument is in the
  trace; otherwise they would pass on an empty one.

## [v0.6.0] — 2026-10-03

A review of the project's architecture raised seven findings on SelfDataGuard. Six are
closed here; the seventh, the replay of an earlier vault revision, is documented as a
limit (whitepaper §8.1).

### Added — the Argon2id profile is stored

- `selfdataguard_vaults.kdf_opslimit` and `kdf_memlimit`, as the last columns. A 0.5.x
  database migrates in place on first opening, its rows taking the pre-0.6.0 profile
  (`Primitives::LEGACY_*`, frozen); a 0.4.0 one migrates in one go.
- `VaultRecord::$kdfOpslimit` and `$kdfMemlimit`. Unlocking uses the record's profile,
  every re-seal keeps it, and `register()` sets the current one: changing
  `Primitives::ARGON2_*` no longer locks existing vaults out.
- The sealed admin key records its profile: `v2:<ops>:<mem>:<salt>:<blob>`. The
  pre-0.6.0 `salt:blob` opens under `LEGACY_*`; an unknown format version, a
  non-numeric profile or a missing field is refused before any derivation.
- **BREAKING** — `VaultRecord` requires `kdfOpslimit` and `kdfMemlimit`, by name; there is
  no default. A default would be the current constants, and a `StorageInterface`
  implementation that does not read the columns back would open every vault under them
  instead of its own profile, unnoticed until the constants change. Such an implementation
  now fails at its first read. The façade also reads the profile back where it reads
  `wrap_phrase` back.

### Added — the escrow admin wrap names its account

- `wrap_admin` seals `tag ‖ len(userId) ‖ userId ‖ escrow_key`, and `unlockAsAdmin()`
  refuses one that names another account than its record. A sealed box has no AAD:
  before, one account's `wrap_admin` copied into another's row opened as that row's.
- A pre-0.6.0 `wrap_admin`, the bare 32-byte key, still opens. `EscrowVault::
  isAccountBound()` tells it apart by length, without the admin key.
  `SelfDataGuard::rebindEscrowAdmin($session, $adminPublicKey)` re-seals it, to the key it
  is given: nothing can check, without the admin secret key, which key the old wrap was
  sealed to, so pass the one the escrow was created with. `setEscrowFields()` leaves an
  existing `wrap_admin` as it is.
- What this does not do: authenticate the escrow content. Whoever writes to the database
  can, with the admin public key, seal a new compartment for an account and fill it.

### Added — the escrow audit log can be anchored

- `AuditLog::verify(?string $anchor)`: the anchor is `seq:hmac`, a head recorded earlier
  off the machine. A missing entry `seq`, or another HMAC there, fails with reason
  `truncated` or `rewritten`; so does a missing log. `verify()` returns the current
  `head`.
- `bin/escrow-ceremony.php verify-log [--ancre seq:hmac]` prints that head, to be written
  down elsewhere. `--ancre=seq:hmac` reads the same; any other option exits 2 rather than
  verifying without an anchor. Without an anchor, a log whose end was cut off still
  verifies.

### Added — frozen Argon2id vectors from a second implementation

- `tests/vecteurs-argon2.json`: the keys of the three locks for fixed inputs, computed by
  `tests/vecteurs_argon2.py` with argon2-cffi and the construction written out by hand.
  `sanity_vecteurs.php` replays them against the library, and CI recomputes them.

### Changed

- The field name `escrow` is reserved. It is the AAD context of the escrow user-wrap
  (`userId|escrow`), under the same master key. `setFields()` refuses it; `getFields()`
  refuses it by name and leaves it out, undecrypted, of a read of every field, as does
  `readArchive()`.
- `AdminKey::generate()` requires `UserVault::PASSWORD_MIN_LEN` (12 bytes). `unseal()`
  still opens a key sealed under a shorter passphrase.

### Fixed

- A sealed admin key whose Argon2id profile is out of range throws
  `InvalidArgumentException`, not `SodiumException`; the ceremony logs it as
  `sealed-key-unreadable`, no longer as `bad-passphrase`.
- `AdminKey::unseal()` wipes the derived key when decryption throws too.

### Documented

- `userId` is compared byte for byte; a service whose accounts ignore case maps the name
  to the account's own before every call (README, whitepaper §7, rule 9).
- `changePassword()` and `changeMemorized()` are re-seal paths too, after SelfRecover's
  write (rule 8).
- The replay of an earlier revision, and why the vault does not close it (§8.1).

### Migration risk — rolling back to 0.5.x

Measured with a database and an admin key written by 0.6.0, then read by 0.5.1: the
vault opens, the password changes, the holder reads the escrow. The administrator can no
longer open an escrow that 0.6.0 created or re-sealed (« escrowKey must be exactly 32
bytes »), and 0.5.1 cannot unseal an admin key generated by 0.6.0 (« invalid salt »).
Keep an admin key sealed before 0.6.0, and do not roll back after an escrow write.

### Before upgrading

- A private field named `escrow`, written by an earlier version, is no longer read:
  `SELECT COUNT(*) FROM selfdataguard_fields WHERE field_name = 'escrow'` must return 0.
  Rename such a field with 0.5.x first.
- A `StorageInterface` implementation of your own must pass `kdfOpslimit` and
  `kdfMemlimit` to `VaultRecord`, from the two new columns.

Tests: 373 checks across 11 suites, on PHP 8.1, 8.2 and 8.4; twelve new CI canaries, each
seen red with its fix removed.

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

### Fixed — PHP 8.1 and 8.2: the audit log named the wrong cause

`AuditLog::readAll()` refused, as it must, to read an unreachable log as « no events », but
before PHP 8.4 it named the wrong cause: a log written by the same process stayed in PHP's
stat cache after its directory was closed, `is_file()` answered « present », and the error
said « present but unreadable » instead of « not traversable ». The guarantee held, the
diagnosis did not. The cache entry for the log is now cleared before the check. Found by the
PHP 8.1/8.2 CI job below on its first run.

### Added — the suites run on PHP 8.1 and 8.2 in CI

`composer.json` declares PHP 8.1 or later. A CI job now runs the ten SelfDataGuard suites,
the SelfRecover coupling check and the cross-module recovery walk on PHP 8.1 and 8.2, next to
the main job on 8.4. Every suite runs even after a failure, so that one red suite does not
leave the next ones unproven on that version.

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
