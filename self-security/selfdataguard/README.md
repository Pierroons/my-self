# SelfDataGuard

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Application-layer data-at-rest protection that survives a database exfiltration.**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.10.0 available](https://img.shields.io/badge/status-v0.10.0%20available-brightgreen.svg)](#status)
[![Tests: 1 401 passing](https://img.shields.io/badge/tests-1%20401%20passing-brightgreen.svg)](#testing)
[![Part of: Self-Security](https://img.shields.io/badge/part%20of-Self--Security-blue.svg)](../README.md)
[![Companion of: SelfRecover](https://img.shields.io/badge/companion-SelfRecover-green.svg)](../../bi-self/selfrecover/)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

> **Dump my database — and get encrypted noise.**

---

## The problem

Every encrypted-data-at-rest product today (MySQL TDE, MongoDB CSFLE, AWS RDS encryption) answers the same threat model: **the attacker has the disk, but not the application**. The encryption key sits next to the data — in a config file, an environment variable, a key management service the application can read.

That model breaks the moment the **application server is compromised**. The attacker dumps the database AND the key — the encryption was a checkbox, not a defense. Recent breaches at scale have shown the same thing every time: personal data exposed in plain text is the dominant cost of the incident, because it cannot be revoked.

Current tools either skip data-at-rest encryption entirely or implement it in a way that adds zero value against a server-side compromise. SelfDataGuard picks a third path: **derive the encryption key from a secret only the user knows**, so a database dump alone yields no value in plain text.

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

Argon2id takes a 16-byte salt: the first 16 bytes of this SHA-256. The two contexts differ: the same string set as memorized word and as passphrase yields two unrelated keys. The three wrap keys cost the same, on purpose: wraps are only as strong as the cheapest one. The Argon2id profile (3 passes, 64 MiB) is stored with each vault: a vault keeps its own at every re-seal, a new vault takes the current profile, and changing the constants no longer locks out any existing vault. What you derive yourself through `Primitives` records no profile.

Each user has:

- A unique random `user_salt` stored in plain (identifier-grade)
- A `data_master_key_pwd_wrap`: XChaCha20-Poly1305 ciphertext of the master key, encrypted with the password-derived key
- A `data_master_key_recov_wrap`: the same, with the memorized-word-derived key (optional)
- A `data_master_key_phrase_wrap`: the same, with the passphrase-derived key (optional)
- Personal data fields encrypted field-by-field with `data_master_key`

**Database dump → encrypted values, no master key.** No combination of plain-text values in the dump yields the master key. The attacker would need one of the user's secrets — password, memorized word or passphrase, each Argon2id-hardened and salt-isolated — to decrypt anything. ⚠️ **What the dump still tells**: the name of each field each account holds and the date that field last changed, the length of each value (a ciphertext carries no padding), and, for an indexed field, which accounts share a value. Give your fields neutral names, and pad to one length the fields that take only a few values.

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
| Level 2 — enrolled device | a signature, nothing that opens the vault | at the next secret given: `recover($user, $lock, $secret, $currentPassword)`, behind the façade's throttle, as the login is |
| Level 3 — human escalation | no old secret | `reEnroll($dg->generationOf($user), $password, $digest, $passphrase)`: the old vault is **archived** |

`recover()` opens and re-seals in a single conditional write. The same call catches up a vault whose password wrap has fallen behind. It goes through the façade's throttle, as the logins do: call it right after a recovery SelfRecover has accepted, behind its counters.

**At level 3, the old vault is archived.** `reEnroll()` sets it aside, sealed under its old locks, with no expiry, and creates a new one. If the user later finds an old lock again, `openArchive($session, $id, Lock::Passphrase, $old)` then `readArchive()` give the data back. The session must be one on the current vault: through the service, an old lock alone opens nothing, because those secrets are precisely the ones that may have leaked. They can still stop a read: `openForObjection($user, $lock, $oldSecret, $archiveId)` returns, with no session and behind the throttle, the archive's objection key alone — it objects to a read of the archive's escrow and reads nothing —, and under a keeper `pinArchiveEscrow($user, $archiveId, $lock, $oldSecret, $adminPublicKey)` seals the archive's escrow to the keeper under the archive's own objection key, so that its reads are announced to the holder and can be objected to (since 0.10.0). Whoever holds an old secret, a leaked one included, objects with it. Given a database dump, an old lock opens the archive offline. Restore the fields with `setFields()` and the fields your application indexes — `readArchive()` gives no index list, since whoever writes the database could extend it. While the archive can come back — for `RecoveryTickets::RETURN_WINDOW` after it was made (30 days, the longest window SelfRecover opens), or while the account's vault is frozen —, no other account can index one of its values: `DuplicateIndexException`, as for a value held live. `reservedIndexes()` lists the reservations in force for the operator — account, archive, field, until when —, with no index. Past that, the value is free again. An archive whose escrow no longer reads — a row that does not parse, an envelope that does not open, a field that does not decrypt — still reads: `readArchive()` returns its private fields, an empty `escrow` and `escrowUnreadable` true. An archive is destroyed by `deleteArchive()`, which takes it opened with one of its old locks, by `eraseAccount()` when no case keeps it, or by `eraseArchive()`, the operator's act; `delete()` leaves it, and `register()` refuses the name while an archive remains: whoever registered it next would hold the archives.

**If the holder comes back after a level 3, their vault comes back too.** SelfRecover 0.13.0 keeps the secrets from before a level 3 valid for a window: a holder who returns with one of them takes the account back. `reinstateArchive($dg->generationOf($user), $archiveId, $ticket, $restoredWithoutIndex)` then brings the archive back live — envelopes, fields with their indexes, escrow — and sets the vault the requester was given aside as an archive, in one transaction. `freeze($generation, $by, $ticket)` then holds it read-only: it opens and reads, and every write throws `VaultFrozenException`, until `unfreeze()`, which the integrator calls once the arbiter has closed the window (`Escalade::cloreFenetre()`). `$restoredWithoutIndex` names the fields that came back without their index. A value another account indexed when no reservation stood — before 0.9.1, or past the window — stays with that account, and its field keeps a trace — `lostIndexes($session)` for the holder, `allLostIndexes()` for the operator — until you have settled who owns the value and indexed it again; an archive written before 0.8.0 kept no index, and its indexed fields are named too. An archive whose escrow row no longer parses comes back as well: the holder's view reads that row as rewritten (`escrow-replaced`), its fields open to no one, and a deposit is refused until the operator puts the escrow row back from a backup. If the account was erased meanwhile, `generationOf()` returns `null`: `restoreArchive($user, $archiveId, $restoredWithoutIndex)` brings the archive back live, and `freeze()` takes the return ticket.

**Erasing an account after a level 3 is the operator's task, in two steps.** `eraseAccount($user, $kept)` erases the live vault, with no session. Once a level-3 ticket was applied to the account, it keeps every archive — the holder's vault and the requester's alike — and `$kept` lists them; no ticket is erased, so a replay is refused even after the name is registered again. While the live vault is frozen, it erases nothing and throws `VaultFrozenException`, as every write to a frozen vault does, and so does `eraseArchive()`: while the vault is frozen, nothing is erased. Before the holder comes back nothing is frozen, and `eraseArchive()` erases: when to run it is your decision. Keep the request, and run it once `unfreeze()` has closed the window. Once the case is closed, erase each archive kept:

```php
$dg->eraseAccount($user, $kept);
foreach ($kept as $archive) {
    $dg->eraseArchive($user, $archive['id']);   // once the case it was kept for is closed
}
$dg->keptArchives();   // every account whose erasure is not finished
```

An erasure request is finished only then: until `eraseArchive()`, the archive and its escrow stay in the database, readable to the ceremony, and `register()` refuses the name. Without a `RecoveryTickets` source, a level-3 ticket is recorded only when your code hands one to `reEnroll()`; without one, the archives go with the account. `eraseAccount()` and `eraseArchive()` take no secret: expose them to the operator only.

> ⚠️ **A password-only vault does not survive a SelfRecover recovery.** Levels 1 and 2 replace the account password (`Recovery::parPassphrase()`, `Recovery::parCode()`). A vault registered as `register($user, $password)` alone has a single envelope, sealed on the old password: after the recovery it can no longer be opened, by anyone, and nothing in either library says so. When the two modules share an account:
> - pass `$memorized` and `$passphrase` to `register()`;
> - after every accepted recovery, call `recover()` as in the table above;
> - at level 3, call `reEnroll()`. The archive's memorized lock depends on the SelfRecover salt of that time, which level 3 replaces: keep it — as a field of the new vault, say — if that lock is to stay usable.

Recoveries are not the only paths that change a secret: when your service changes the password or renews the memorized word, call `setLock()` as well, **after** SelfRecover's write. A forgotten path leaves a lock on a secret SelfRecover has already replaced.

> ⚠️ **`userId` is compared byte for byte.** It enters the AAD of every envelope and keys the database row: "Alice" and "alice" are two vaults. If your accounts ignore case, map the name to the account's own before every call; otherwise a vault created under one spelling escapes the re-seals called under the other, and nothing says so.

Without SelfRecover, SelfDataGuard still works, with whatever locks the application gives it.

---

## Operational modes — the library implements one of them

| Mode | Server access to data | Trade-off | In the code |
|------|----------------------|-----------|-------------|
| **Lite** *(transparent for legacy stacks)* | Server decrypts during user sessions only | Server compromise = the vaults of sessions open while it is compromised, and of whoever logs in; not those of accounts that do not log in. A key read during the compromise stays valid afterwards: neither a password change nor a recovery rotates it | ✅ this is what the library does |
| **Hybrid** *(targeted at e-commerce)* | Operational fields (`email`, `shipping_address`) wrapped with admin operational key. Sensitive fields (`tel`, `KYC_doc`) require user session | Admin can fulfill orders; sensitive data remains zero-knowledge | ❌ specified, not written — the vault's `wrap_admin` is `null` (`UserVault::register()`) |
| **Full** *(zero-knowledge for high-assurance services)* | Server NEVER decrypts. All crypto runs in the browser, through libsodium compiled to WebAssembly — WebCrypto offers neither Argon2id nor XChaCha20-Poly1305 | Some workflows redesigned (no async transactional emails, push notifications instead) | ❌ specified, not written — the module carries no client-side code |

A deployment installing it therefore runs in **Lite**, whatever mode it aims for: the other two are described in the whitepaper (§4.2, §4.3) as a target, and no API parameter selects them.

One admin path does exist, outside this table: the **escrow** (`src/Escrow/`), a compartment with its own key that an administrator reopens through `EscrowCeremony` alone — the verdict of an arbiter you write, the intention in a signed log, the log's head taken off the machine by a witness, and only then the escrow passphrase. It yields that compartment only, never the private vault, and it is not Hybrid mode: nothing there is decrypted as a matter of routine. The escrow of an archived vault goes with the archive, and the administrator reopens it the same way (`readArchiveEscrow()`).

What the escrow guarantees:
- **no arbiter ships**: `EscrowArbiter` returns a named `Verdict`, and only `Open` and `OpenWithConsent` unseal. One written for every deployment would say yes to all of them; SelfRecover ships none either: an arbiter can read whether its window after a level 3 lets the escrow be read (`Escalade::fenetre()`, `lecture_admin_permise`). The arbiter's class, its verdict, the witness's and the keeper's are in the log. `HeadWitness` is required: it gives back the last head it took, and the ceremony checks the log against it before writing anything — a log cut or rewritten since stops it. `NoHeadWitness` says by name that the head stays on this machine. A witness passes `bin/witness-conformance.php` before it serves (since 0.10.0): a store that takes a head from anyone who can write to it lets a single forged head close every ceremony. An arbiter that throws answers `Unavailable`. The ceremony names the fields it reads (`unlock <user> <case> <field>…`), the arbiter is given them, and nothing else is opened (since 0.10.0); `compare <user> <case> <field>…` tells, field by field, whether the escrow holds a value the operator types, without returning it — « concorde », « ne concorde pas sous la normalisation *n* » or « indisponible », the tool's own words —, and each comparison is a read, under the same arbiter, delay and objection. It narrows what an honest operator sees; it does not stop whoever holds the admin key, who opens the box outside the tool. Without a keeper, against the administrator alone — the sealed key file and its passphrase — none of this holds: they open the escrow outside the library;
- **a keeper, when the deployment runs one** (`EscrowKeeper`, since 0.9.0): the escrow is sealed again to it, and the administrator alone no longer opens it. The keeper hands back the inner box after recording the release, and signs for the holder what it released: `escrowReads($session)`, at the opening of the vault, returns the releases, or "not established" with its reason — never an empty list for an attestation that did not arrive. Each deposit draws a new escrow key and seals a box of its own: a key obtained by a ceremony, or kept from a copy of the database, opens nothing deposited after it, and a read announced hands back only the box it announced (since 0.10.0) — a deposit made since, the box an archive keeps, or one put back from a copy waits a delay of its own, and the holder sees it pending. The view names the box of each release and each announcement, and the box the row holds (`box`). A delay, set by the keeper, turns every read — an archive's too — into an announcement the holder sees. The holder alone can object to it (`objectToEscrowRead()`), before or after a deposit: the objection is signed from the session under the escrow's objection key, derived from the master key, of which the escrowId is the fingerprint — an escrow row restored from a backup, rewritten or deleted takes neither the objection nor the view from the holder (`escrow-replaced`). The holder can also consent to a read (`consentToEscrowRead()`, since 0.10.0): the consent is signed from the session under the same key, for the keeper the escrow answers to, and recorded there; an arbiter that answers `Verdict::OpenWithConsent` lets the ceremony read only once that keeper attests it — a read its holder does not agree to does not go through the ceremony, and an escrow sealed to no keeper does not open under that verdict. A consent lifts no objection. The delay is the keeper's, not the library's: whoever reaches the keeper outside the ceremony waits it too, and the holder sees what the keeper released. The keeper's store lives off the machine that serves. SelfDataGuard ships none: `KeeperSeal` gives its formats, `bin/keeper-conformance.php` plays its contract. Given a keeper, `new SelfDataGuard($storage, $blindKey, keeper: $keeper)` records it in the database, and a façade without it, or with another, is refused — the ceremony too. A keeper the deployment replaces (`SelfDataGuard::changeKeeper()`) stays on the database's record of former keepers, and is given to them from then on: each deposit retires the escrow from it, and a deposit without it is refused; a keeper replaced is never taken back, and one gone for good leaves the record by `SelfDataGuard::forgetFormerKeeper()`, the operator no longer giving it to the façades nor to the ceremony. An escrow sealed before stays readable by the administrator alone until the operator's pass (`bin/escrow-ceremony.php seal-to-keeper`, no secret in hand) or its holder's next deposit (`sealEscrowToKeeper()`);
- **the admin seal names its account**: copied into another account's row, it is refused. It does not authenticate the escrow content: whoever writes to the database can, with the public key, seal and fill a new compartment. A seal from before 0.6.0 still opens; `rebindEscrowAdmin($session, $publicKey)` re-seals it, to the key you pass. Since 0.9.0 each deposit re-seals the compartment, so the database records the admin public key at the first deposit, and a deposit or a re-sealing given another one is refused: it would take the compartment from the administrator who holds the recorded one;
- **the admin passphrase is at least 12 bytes** when sealing (`UserVault::PASSWORD_MIN_LEN`); a key sealed earlier under a shorter passphrase still opens. The sealed key carries its Argon2id profile (`v2:…`);
- **the ceremony log can be anchored off the machine**: `bin/escrow-ceremony.php verify-log` prints its head `seq:hmac`, which you write down elsewhere, and `verify-log --ancre seq:hmac` then refuses a log truncated before the anchor or rewritten up to it. Without an anchor, verification cannot see that the end of the log was cut off. The log secret lives on the ceremony machine, and whoever holds it can rewrite what follows the last anchor: the witness takes the head out at every ceremony. Lines without the shape of an entry — a write cut short — are quarantined by the next entry, which records them; an entry whose signature does not hold, or a signed entry out of place, is a break that nothing is appended over. Since 0.10.0 the log can rotate (`new AuditLog($path, $secret, rotateAfter: n, mode: 0o640)`, or `DATAGUARD_AUDIT_ROTATE_AFTER` and `DATAGUARD_AUDIT_MODE` for the tool): once its file holds `n` entries, the next one renames it `journal.1.log`, `journal.2.log`, … — numbered once, the number before the extension, nothing deleted — and opens a new file with an entry chained on the archive's last one, `seq` going on. `verify-log` without an anchor walks every file and every link (`verifyAll()`). Keep the log, its archives and its `.lock` file outside any directory a web server serves, and keep at least the last archive: an anchor taken just before a rotation is read again there;
- **the field name `escrow` is reserved**: it is the context of the escrow envelope. `setFields()` refuses it; `getFields()` refuses it when asked for by name, and leaves it out, undecrypted, of a read of every field.

---

## Threat model at a glance

| Adversary | Without SelfDataGuard | With SelfDataGuard |
|-----------|----------------------|---------------------|
| SQL injection / IDOR / DB dump | Plain-text PII exposed | Encrypted values. Readable: field names, modification dates, lengths, accounts that share a value of a field declared shared |
| Database stolen without the pepper file | Plain-text PII exposed | The password and memorized-word locks are under the pepper: without its file, no password and no word can be tried offline. **Limit**: the passphrase lock is not under it — drawn by SelfRecover, a passphrase holds about 77.5 bits; chosen by its holder, what a password holds. A pepper file written by hand, not drawn by `bin/pepper.php new`, is forced with the database alone, at the price of one HMAC per candidate, against the database's check and against each layer |
| Backup tape stolen | Plain-text PII exposed | The same, as long as the backup does not hold the blind-index key (a server-side secret, shared by every account). With it, indexed values that can be enumerated (phone numbers, email addresses) are found by dictionary, for every account |
| Insider DBA | Reads everything | As for a dump: encrypted values, no unwrapping without one of a user's secrets; field names, modification dates and lengths readable |
| Application root compromise (RCE) | Reads everything | Lite, the only mode in service: reads the vaults of sessions open during the compromise, and keeps their keys, which do not rotate afterwards. With the blind-index key, finds by dictionary the indexed values that can be enumerated, for every account |
| Compromised user endpoint (keylogger) | User credentials harvested | User credentials harvested → that user's data only (no fan-out) |
| Stolen passphrase paper | Account access through level 1 | The same, plus that user's data — offline too, given a dump. The passphrase is consumed at its first legitimate use, which replaces it |
| Coercion of admin to decrypt | All data at admin's discretion | Private vaults have no admin key: opening them would take a secret from every user. The escrow has one, and it is permanent: whoever coerces the holder of its passphrase obtains, ceremony after ceremony, the escrow compartment of every account with an escrow compartment — never the private vault. Under a keeper, only what the keeper releases, and each release is shown to its holder |
| Administrator alone, with the database | Reads everything | Without a keeper, opens the escrow outside the library, and nobody sees it. Under a keeper, opens no escrow sealed to it; can still have a read made under a case he opens himself — the keeper records it, and the holder sees it. He chooses the keeper: a deployment that wants to be believed names it, and the holder sees its fingerprint |
| Blind-index key lost, or replaced by a new one | — | **Limit.** The database records a check of its key: a façade that brings another is refused, naming it; a database written before 0.7.0 adopts its key only on proof (`adoptBlindKey()`), or on the operator's word where no field carries an index. Every seal and every index is computed under that key: until it is back, nothing opens. Keep it with the database's backups |
| A third party who knows a name | Learns whether the account exists | Not from the response time, since 0.10.0: an unknown name pays the derivation a wrong secret pays. Not which locks it carries (whitepaper, §8.1) |
| A copy of the database, or an old vault row put back | — | **Limit.** No write is needed: a copy of the database and a secret its holder has replaced since — a passphrase consumed included — open the vault as the copy holds it, and a restored row opens with the secrets it carried. Nothing in the vault tells a restored revision apart: the defence is the integrity of the database and its backups (whitepaper, §8.1) |
| Pepper lost | — | **Limit.** Every vault without a passphrase lock closes for good. Keep a copy of the pepper file off the server, and out of the database's backups |
| Database write: the holder kept from objecting | — | **Limit.** The objection door opens outside the seal: a profile rewritten or a seal removed no longer stops the holder from objecting. An envelope rewritten closes its lock, and the vault's salt rewritten closes all three: meanwhile the holder cannot object to an announced read, which goes through once due. The operator puts the row back from a backup |
| Code changed on the machine that serves | Reads everything | **Limit.** The throttle, the objection door and the check of a consent (`Verdict::OpenWithConsent`) run on the server: they hold against whoever writes the database, not against whoever changes the code it serves. Under a keeper, a read still waits the keeper's delay, and the holder sees it announced |

---

## Status

**v0.10.0 — a pepper kept outside the database under the password and memorized-word locks, a throttle the library requires, an objection that needs no session, a keeper whose announcement holds its box and that records its holder's consent, an administrator key the operator records, and an audit log that rotates**, 24 November 2026, on top of **v0.9.1 — the holder's return after a level 3 no longer closes**, 20 November 2026, **v0.9.0 — an escrow the administrator alone no longer opens: a keeper holds its second key, and its holder sees every read**, 6 November 2026, and **v0.8.0 — an archived vault comes back into service, a vault freezes read-only, and no recovery-driven write happens without a ticket**, 29 October 2026.

Whitepaper complete (specification + threat model). PHP reference library implemented (15 502 lines across 78 files, counted 24 November 2026; PSR-4, PHP 8.1+, libsodium). Cryptographic primitives (Argon2id, HMAC-SHA256, XChaCha20-Poly1305, and AES-256-GCM to read blobs written before 0.4.0) covered by **1 401 checks across 18 suites**, counted by running them on PHP 8.4, 24 November 2026. CI runs the same suites under PHP 8.1 and 8.2, where four trace checks become declared limits. The keys of the three locks are checked against frozen test vectors, recomputed in CI by a second implementation (argon2-cffi), and so is the pepper's form, by a second implementation in Python. A clickable HTML demo is included to inspect the encrypted database in real time.

A 0.9.x database is upgraded by the operator before a façade of 0.10.0 serves it (*Upgrading a 0.9.x database*, below). A 0.5.x database migrates in place the first time 0.6.0 opens it (`kdf_opslimit` and `kdf_memlimit` columns, at the former profile), and so does a 0.4.0 one, in one go. ⚠️ Rolling back to 0.5.x keeps access to the vaults and to the escrow on the holder's side, but the administrator can no longer open an escrow that 0.6.0 created or re-sealed, and 0.5.x cannot unseal an admin key generated by 0.6.0: see the [CHANGELOG](./CHANGELOG.md). Blobs written by 0.3.0 stay readable, through OpenSSL (`ext-openssl`) where libsodium refuses AES.

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
use Pierroons\SelfDataGuard\Pepper\LocalPepperFile;
use Pierroons\SelfDataGuard\SelfDataGuard;
use Pierroons\SelfDataGuard\Storage\SqliteAdapter;
use Pierroons\SelfDataGuard\Vault\Lock;
use Pierroons\SelfDataGuard\Vault\PepperForms;

require 'vendor/autoload.php';

$dg = new SelfDataGuard(
    storage:  new SqliteAdapter('sqlite:/path/to/db.sqlite'),
    blindKey: file_get_contents('/path/to/server-secret.bin'),  // ≥32 bytes
    pepper:   new LocalPepperFile('/path/to/pepper'),           // drawn by: php bin/pepper.php new /path/to/pepper
    forms:    PepperForms::LayeredOnly,                         // a new database; AcceptBare while a 0.9.x one upgrades
    throttle: $throttle,                                        // your Throttle, or new NoThrottle() to say there is none
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
['unlocked' => $session, 'archiveId' => $id] = $dg->reEnroll($dg->generationOf('alice'), $password, $digest, $passphrase);

// Indexed lookup, no plaintext required
$userId = $dg->findUserByField('email', 'a@b.c');  // 'alice' or null
```

Three primary classes exposed: `SelfDataGuard` (façade), `SqliteAdapter` (storage; implement `StorageInterface` for MariaDB / Postgres), `Primitives` (raw crypto if you need to build something on top). An implementation of `StorageInterface` of your own is conformant when `php bin/storage-conformance.php <connection.php> <caller-transaction.php> <unreadable-row.php>` passes against it, and not otherwise — without the last two files, the cases that need them are skipped, and a skipped case does not pass: a storage can match every signature and lose a vault in silence. Each failure has its own exception type: `WrongSecretException` (and `MissingEnvelopeException`, with the same message: an absent lock answers as a wrong secret), `VaultTamperedException` (a profile or an envelope that does not match the seal, or a seal missing), `VaultNotFoundException`, `StaleVaultException` (a session opened on a vault that has since been replaced), `DuplicateIndexException`, `AmbiguousLookupException`.

**The pepper** (since 0.10.0) is a server secret kept outside the database. The password and memorized-word locks are sealed under it as well as under the user's secret: a stolen database alone no longer lets anyone try a password or a word offline. The passphrase lock is not under it. `php bin/pepper.php new <file>` draws it — 32 random bytes, mode 0600, never over an existing file. `LocalPepperFile` refuses a file that is missing, unreadable, not a regular file, under the web server's document root, readable by others or writable by its group, malformed, or holding fewer than 16 distinct bytes. The database records a check of the pepper, and a façade given another is refused, naming both. `PepperSource` is the interface: a remote source returns the computation for a use, never the pepper, and when it does not answer there is no fallback. Keep a copy of the pepper off the server, and out of the database's backups: lost, it closes every vault that has no passphrase lock.

**The throttle** (since 0.10.0) is required. At every door where a secret from outside costs an Argon2id — `loginWithPassword()`, `loginWithMemorized()`, `recover()`, `openArchive()`, `openForObjection()`, `pinArchiveEscrow()`, `setLockWhileFrozen()` — the façade asks it before reading anything (`Throttle::admit()`), and tells it how each admitted attempt ended (`settle()`). The library ships no policy: implement `Throttle` against your own store, or pass `new NoThrottle()`, which says by its name that there is none. A throttle keyed on the account alone locks its holder out: key it on the address too. An unknown account pays the derivation a wrong secret pays, so the response time no longer tells whether a name has a vault. `UserVault::unlock()`, public and stateless, goes around the throttle as it goes around the seal: call the façade.

**The memorized secret is sealed only as the digest SelfRecover derives** — 64 lower-case hex (since 0.10.0). `register()`, `setLock()`, `recover()` and `reEnroll()` refuse anything else, the word itself or the digest in capitals; opening refuses nothing, so a vault sealed before on another string still opens with it. `lockState($user, $lock)` gives the form of a lock — native, layer, bare, absent or unreadable — without any Argon2id.

**Under a freeze**, `setLockWhileFrozen($user, $memorized, $lock, $secret)` re-seals one lock from the current memorized word, behind the throttle; every other write stays refused until `unfreeze()`. A holder who forgot the memorized word re-seals nothing before then: the password still opens the vault, and SelfRecover's level 2 does not follow.

### Upgrading a 0.9.x database

A façade of 0.10.0 refuses a database that holds vaults and never recorded its pepper. The upgrade is the operator's, with no secret of the users in hand, while nothing else writes to the database:

1. Draw the pepper — `php bin/pepper.php new <file>` — and keep a copy off the server.
2. Run `php bin/pepper.php adopt <file>`, with `DATAGUARD_DB` and `DATAGUARD_BLINDKEY_PATH` naming the database and the blind-index key it recorded. It lays a layer under the pepper over every password and memorized lock, live vaults and archives alike, and records the pepper's check last. Run again, it lays only what is still bare. A frozen vault is skipped and named: run it again once the window has closed.
3. Serve under `PepperForms::AcceptBare`: a bare lock still opens, and every new seal takes the layer form.
4. `php bin/pepper.php status <file>` counts the forms, and exits 0 once nothing is bare.
5. Serve under `PepperForms::LayeredOnly`: every new seal is native — the pepper inside the derivation —, and a bare lock put back from a copy is refused after its derivation, at the cost of a wrong secret. A memorized lock still in the layer form was sealed from a string that is not the canonical digest: its holder sets the word again.

**Rolling back to 0.9.x** is possible until step 5: `php bin/pepper.php unlayer <file>` removes every layer. A native lock has no layer under it: its vault is named, and stays closed to 0.9.x. Until `unlayer` has run, 0.9.x opens no lock under the pepper: close your recovery route while you serve 0.9.x.

Then, before the first deposit, `php bin/escrow-ceremony.php adopt-admin` records the administrator's key in the database, on proof of its passphrase: a deposit no longer records it, and a database without it refuses every deposit. A `StorageInterface` of your own adds `accountIds()`, `replaceArchiveVault()`, the narrow write under a freeze, and the escrow's admin key label: `bin/storage-conformance.php` plays them.

### The tools in `bin/` and their exit codes

| Tool | Exit codes |
|---|---|
| `pepper.php new\|adopt\|status\|unlayer <file>` | 0 done (`status`: nothing bare) · 2 refused, or something left to lay (`status`: bare locks) · 3 usage, configuration, or a file that cannot be written |
| `escrow-ceremony.php <command>` | 0 done (`compare`: every field matches) · 1 log broken · 2 refused · 3 configuration · 4 read announced, to run again once due · 5 `compare`: a field does not match or is unavailable; `reseal-admin`: pass done, an escrow left under the old key |
| `storage-conformance.php <connection.php> <caller-transaction.php> <unreadable-row.php>` | 0 conformant · 1 a case failed, or was skipped for want of a file · 3 a file that does not load |
| `keeper-conformance.php <factory.php>` | 0 conformant · 1 a case failed · 3 a file that does not return a factory |
| `witness-conformance.php <factory.php>` | 0 conformant · 1 a case failed · 3 a file that does not return a factory, or a factory that does not return a witness and its forger · 4 nothing to test: `NoHeadWitness` takes no head |

> ⚠️ **After upgrading a database to 0.7.0, before serving**: call `sealProfile($userId)` once for every account. A vault written before 0.7.0 carries no profile seal, and opens to no one until it is sealed — as a tampered one would; a level 3 (`reEnroll()`) refuses it the same way. Then index a value only once the account has proven it holds it: whoever indexes it first keeps it.

---

## Testing

Eighteen sanity test suites, runnable directly with `php` (no PHPUnit required):

```bash
php tests/sanity_primitives.php   # 56 tests — Argon2id, HMAC, XChaCha20-Poly1305 + IETF vector, legacy AES-GCM, randomness, AAD
php tests/sanity_vault.php        # 64 tests — three locks, rotation, context separation, AAD binding, generation, profile bounds, absent lock
php tests/sanity_fields.php       # 26 tests — field encrypt/decrypt + blind index
php tests/sanity_storage.php      # 77 tests — SQLite adapter, nested transactions, conditional write (generation, revision), revisions rewritten in another type, accounts listed page by page, "DB dump = soup" test, lock timeout and journal mode
php tests/sanity_migration.php    # 46 tests — the key adopted on proof, 0.4.0, 0.5.x and 0.8.0 databases migrated in place, unique blind index, profile sealed by the operator
php tests/sanity_archive.php      # 121 tests — a package that no longer reads, an escrow row that comes back whole, archive: content, isolation, all-or-nothing, concurrent writer waited for, an archive brought back live, the values an archive keeps from other accounts, a reservation that ends with the window or the freeze, a value two accounts held brought back with its trace, an escrow kept in any form that does not parse, a frozen vault, an erasure refused under a freeze, a ticket applied once, what erasure keeps and the operator erases
php tests/sanity_facade.php       # 199 tests — a database read without a write, full API end-to-end, recover(), level 3 under the seal, seal over profile and envelopes, repair, the database's key and shared fields, unique index, write race, a name that keeps its archives, reinstateArchive(), freeze(), restoreArchive(), recovery tickets, an archive whose escrow does not read — row, envelope or field — read and objected to, a storage that erases under a freeze refused by the façade, the fields named back without their index, what erasure keeps under a level 3 and refuses under a freeze, the return after an erasure, a lock re-sealed under a freeze from the memorized word
php tests/sanity_conformance.php  # 123 tests — the storage conformance suite, against the shipped adapter and a broken storage for each clause, each race played ten times
php tests/sanity_keeper.php       # 263 tests — the keeper: double seal, a new key at each deposit, releases signed for the holder, delay and objection for every read, an announcement that holds its box, the holder's consent, an escrowId computed from the objection key, an objection with no session, an archive's escrow pinned by its holder, a log head recorded and not judged, a keeper down, a keeper replaced and retired, a retirement that names its keeper, a passphrase checked before the keeper, a deposit between two reads, a file keeper under eight processes, a former keeper known to the façade whatever the record says, a silent keeper that hides only itself, a row that does not open, a storage outage told from a row that does not parse, a former keeper forgotten, the keeper conformance suite against broken keepers
php tests/sanity_keys.php         # 30 tests — no object that holds a key has an accessor for it
php tests/sanity_audit.php        # 64 tests — audit log, anchor against truncation, forced to disk, quarantine of what a crash leaves, rotation: archives, links, a missing or foreign archive, writers at once
php tests/sanity_ceremony.php     # 69 tests — ceremony CLI, operator arbiter, witness and keeper, a witness seeded, archives, the fields named, comparison without disclosure, verify-log options and a rotating log, a read announced, the operator's pass, which goes on after an account that fails, the former keepers' files
php tests/sanity_escrow.php       # 119 tests — a witness that forgot its heads, escrow compartment, first deposits at once, v2 admin key, seal bound to its account, the admin key recorded by the operator, its label checked before the keeper, a change of admin key and its pass, two re-sealings from one read, ceremony order, log checked against the witness
php tests/sanity_vecteurs.php     # 9 tests — keys of the three locks against frozen vectors
php tests/sanity_witness.php      # 19 tests — the head witness conformance suite, each broken witness failing on its duty, and its exit codes
php tests/sanity_pepper.php       # 43 tests — the pepper's form against a second implementation, the pepper file and its refusals, usages and sources, a pepper drawn and never written over
php tests/sanity_upgrade.php      # 29 tests — the pepper inside the derivation, the memorized secret sealed as its digest only, the operator's pass from 0.9.x, its status, the return to 0.9.x, the pass refusing what would close the locks
php tests/sanity_throttle.php     # 44 tests — the throttle required, admitted before anything is read and settled once, an unknown account at the cost of a wrong secret
# Total: 1 401 tests, 0 failures — counted by running them, 2026-11-24
python3 tests/vecteurs_argon2.py  # recomputes the vectors with argon2-cffi, a second implementation
python3 tests/vecteurs_poivre.py  # recomputes the pepper's vectors, a second implementation
```

The `sanity_storage.php` suite includes a "BIG TEST" that dumps the SQLite file and verifies that no plaintext personal data appears anywhere in the binary blob. On the SelfRecover side, `bi-self/selfrecover/tests/sanity_parcours_dataguard.php` takes a vault through every recovery, on the real paths of both libraries.

---

## Documentation

- [Threat model](./docs/threat-model.md) — where untrusted input enters, what matters most, how to run the guards, how a finding is rated
- [Whitepaper EN (full specification)](./docs/whitepaper-en.md)
- [Whitepaper FR (specification complète)](./docs/whitepaper-fr.md)
- [Demo walkthrough](../../demo/selfdataguard/README.md)

---

## License

**AGPL-3.0-or-later**. See [LICENSE](../../LICENSE).

If you modify SelfDataGuard and offer your version to users over a network, you must give them access to its source code, under the same license (AGPL-3.0, section 13).
