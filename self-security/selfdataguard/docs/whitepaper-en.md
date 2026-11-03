# SelfDataGuard — Whitepaper

**Application-layer data-at-rest protection that survives a database exfiltration**
*Dump my database — and get encrypted noise.*

*Edition of 3 November 2026 — describes SelfDataGuard v0.9.0. The French edition is authoritative where the two differ.*

---

## Context (May 2026)

The pattern is always the same: an authorization flaw grants access to other people's accounts, and what it exposes sits **in plain text in the database**, with no application-layer encryption that could have rendered it unusable. Civil status, contact details, identity status: data you cannot change the way you change a password.

There is a structural question here, complementary to the one SelfRecover addresses: **how to make a database leak technically useless to the attacker**, independently of the authentication flow?

SelfRecover protects **account access**. SelfDataGuard protects **stored data**. In a stolen database, authentication secrets are Argon2id hashes (SelfRecover) and personal data are encrypted values (SelfDataGuard). Neither covers the data the recovery writes itself — the address of attempts when the deployment sees one, the answers and thread of a level-3 case — nor what the dump tells about the encrypted values (§6.1).

This whitepaper describes the SelfDataGuard protocol. It targets no actor in particular — it is an open-source proposal complementary to SelfRecover, that public and private operators may audit, integrate, or contest freely.

---

## 1. The problem

### 1.1 Why existing solutions fail

All current data-at-rest encryption products share a structural weakness: **the encryption key resides in the same place as the data**, accessible to the same application process that reads it in plain text.

| Product | Key storage | Server compromise = key compromise? |
|---------|-------------|--------------------------------------|
| MySQL TDE / MariaDB encryption | Keyring plugin on host system | ✗ Yes |
| PostgreSQL pgcrypto | Connection variable / config file | ✗ Yes |
| MongoDB CSFLE | Key file or remote KMS accessible to the app | ✗ Yes (KMS hands out the key on demand from the compromised app) |
| AWS RDS encryption / Aurora encryption | AWS KMS, transparent to the application | ✗ Yes |
| Application-level encryption (AES + key in `.env`) | Environment variable / Vault accessible to the app | ✗ Yes |

In all five cases, an attacker who obtains a shell on the application server (RCE, privilege escalation, SSH key theft) **simultaneously** obtains the database and the key. Data-at-rest encryption then provides **no protection at all** — it only protected against an attacker holding the disk without the server (a rare scenario in practice).

### 1.2 The real question

> How to encrypt a user's data such that the key only exists **when that user is present**, and nowhere else permanently?

This is exactly the question solved by Bitwarden (password vault), 1Password, ProtonMail (encrypted mailbox). Their common architecture: **key wrapping**, where the user's master key only exists in plain text in memory for the duration of a session, and is wrapped by a secret known only to them (master password).

SelfDataGuard ports this **personal vault** architecture to the **user database of a multi-tenant application** (e-commerce, SaaS, public service).

---

## 2. The SelfDataGuard model

### 2.1 Fundamental principle

> *For each user, the database stores their data encrypted with a key that is never stored in plain text. This key is wrapped under each of their secrets: their password and, when provided, their memorized word and their SelfRecover passphrase. Any one of them unwraps it. During an active session, the server unwraps the key in RAM and uses it to serve requests; on logout, the key is purged.*

Direct consequences:

- A database dump alone → **encrypted values**; still readable: each account's field names, their modification dates, the length of values and the equality of indexed values (§6.1)
- A dump while a user is logged in → exposure limited to **that single user**, no cross-user fan-out
- An admin compromise → exposes **operational fields** (Hybrid mode) or nothing at all (Full mode); in Lite, the only mode in service, the escrow admin key with its passphrase opens the escrow compartment of every account that has one, never a private vault

### 2.2 Detailed architecture

At account creation:

```
Step 1 — Generate the user's master key randomly:
    data_master_key  ← random(256 bits)        # Server-side CSPRNG

Step 2 — Generate the user salt (cryptographic identifier):
    user_salt        ← random(128 bits)        # stored in plain text in the database

Step 3 — Derive the wrap keys (the second and third are optional):
    password_key     ← Argon2id(password, user_salt, m=65536, t=3, p=1)
    recov_key        ← Argon2id(memorized_word, SHA-256(user_salt || "/dataguard")[:16], m=65536, t=3, p=1)
    phrase_key       ← Argon2id(normalise(passphrase), SHA-256(user_salt || "/dataguard/passphrase")[:16], m=65536, t=3, p=1)

Step 4 — Wrap the master key with each of these keys:
    wrap_pwd         ← XChaCha20-Poly1305-encrypt(data_master_key, key=password_key, nonce=random_192)
    wrap_recov       ← XChaCha20-Poly1305-encrypt(data_master_key, key=recov_key,    nonce=random_192)
    wrap_phrase      ← XChaCha20-Poly1305-encrypt(data_master_key, key=phrase_key,   nonce=random_192)

Step 5 — Database storage (all values listed below stored in plain):
    user_id, user_salt, wrap_pwd, wrap_recov, wrap_admin (reserved, never written), wrap_phrase,
    kdf_opslimit, kdf_memlimit     # the Argon2id profile of the envelopes, stored since 0.6.0

Step 6 — Field-by-field encryption of personal data:
    email_encrypted        ← XChaCha20-Poly1305-encrypt(email,    key=data_master_key, nonce=random_192)
    address_encrypted      ← XChaCha20-Poly1305-encrypt(address,  key=data_master_key, nonce=random_192)
    phone_encrypted        ← XChaCha20-Poly1305-encrypt(phone,    key=data_master_key, nonce=random_192)
    [...]

Step 7 — Wipe data_master_key and the wrap keys from server memory.
```

On password login (standard case, ~99% of the time):

```
1. Server receives (username, password) over HTTPS
2. Fetch user_salt and wrap_pwd from the database
3. password_key   ← Argon2id(password, user_salt, ...)
4. data_master_key ← XChaCha20-Poly1305-decrypt(wrap_pwd, key=password_key)
5. data_master_key kept in session memory (never persisted)
6. On each request: on-the-fly decryption of personal fields
7. On logout: wipe data_master_key
```

On memorized-word login (degraded case, password forgotten):

```
1. Server receives (username, memorized_word) over HTTPS
2. Fetch user_salt and wrap_recov from the database
3. recov_key       ← Argon2id(memorized_word, SHA-256(user_salt || "/dataguard")[:16], m=65536, t=3)
4. data_master_key ← XChaCha20-Poly1305-decrypt(wrap_recov, key=recov_key)
5. User can access their data and set a new password
6. Regenerate wrap_pwd with the new password_key (no need to re-encrypt the data fields)
```

Paired with SelfRecover, this "memorized word" is the digest the browser computes, never the word itself (§3.1).

On passphrase recovery (SelfRecover level 1, once the passphrase is accepted):

```
1. SelfRecover issues a new password and a new passphrase, drawn or brought; the old one is consumed
2. phrase_key      ← Argon2id(normalise(old_passphrase), SHA-256(user_salt || "/dataguard/passphrase")[:16])
3. data_master_key ← XChaCha20-Poly1305-decrypt(wrap_phrase, key=phrase_key)
4. wrap_pwd and wrap_phrase regenerated on the new secrets, in one write, conditional on user_salt
```

`normalise` trims the edges and reduces every whitespace run to one space, exactly as SelfRecover does before comparing: the string SelfRecover accepts is the string that opens the vault.

### 2.3 Why this architecture survives a dump

An attacker who exfiltrates the user table obtains:

- `username` (plain, identifier)
- `user_salt` (plain, identifier-grade)
- `wrap_pwd` (encrypted with `password_key`, unknown to attacker)
- `wrap_recov` (encrypted with `recov_key`, unknown to attacker)
- `wrap_phrase` (encrypted with `phrase_key`, unknown to attacker)
- `email_encrypted, address_encrypted, ...` (encrypted with `data_master_key`, unknown to attacker)

To decrypt, the attacker has three paths:

1. **Bruteforce a target user's password** → cost of Argon2id per attempt (~250 ms with the recommended parameters; 213.7 ms measured on a deployment machine, see the note under point 2). For an 8-character random password: ~10^14 attempts × 0.25 s ≈ 8·10^5 years, one attempt at a time; the attacker divides that time by the number of attempts run in parallel, each needing 64 MiB of memory. For a weak password (`123456` or similar), still feasible. **Applied since 0.3.0**: `UserVault::register()` refuses passwords under 12 bytes (`PASSWORD_MIN_LEN`). ⚠️ Length is not entropy — twelve identical letters clear the bar. It is a floor against the worst case, not a measure. **No blocklist ships**: an earlier edition of this paragraph announced a refusal against breach lists that no line of code applied, and a promise with no mechanism behind it is worse than no promise.

2. **Bruteforce the memorized word** → since 0.3.0, the same cost as the password path: Argon2id, ~250 ms per attempt.

   > **Fixed on 2026-09-06.** Before 0.3.0, `recov_key` was derived by a single HMAC-SHA256 pass, on the assumption written in this very document that "the memorized word must have sufficient entropy by construction", with a recommended floor of 30 bits. Measured on a deployment host: **213.7 ms per Argon2id attempt against 0.0027 ms per HMAC attempt, a factor of 78,100**. Both keys unwrap the SAME `data_master_key`, and `wrap_recov` is attacked offline with no attempt counter: the security of the pair was therefore that of its cheaper door, whatever the cost of the other. A salt forbids precomputation but adds **no bit** against a targeted person; the AEAD tag tells the attacker which attempt was the right one, it does not slow them down. Only the cost per attempt buys time, and it buys a factor, never entropy.
   >
   > **No entropy floor is enforced.** Argon2id buys a multiplier, not entropy: a weak word remains ~13 bits of guessing plus ~13 bits of cost. A floor high enough to matter (77.5 bits) would end the sharing of the memorized word between SelfRecover and the vault (§3.1) — a design decision, not a setting. It is stated here as an open question rather than answered silently in either direction.

3. **Bruteforce the passphrase** → the same Argon2id cost per attempt. When SelfRecover draws it at random, the entropy is known: six words from a 7,776-word list, about 77.5 bits — the strongest door by computation, whose weakness lies elsewhere: it is written on paper (§6.1). When the user brings it, rolled with dice, it is only as random as those dice, which nothing checks: six words picked by hand would make it the cheapest door.

A leak therefore yields **no value in plain text**; what it does tell — field names, modification dates, lengths, equality of indexed values — is listed in §6.1. Bruteforce cost is per-user (impossible to bruteforce the whole database in parallel because each user has their own `user_salt`).

---

## 3. Coupling with SelfRecover

### 3.1 Shared secrets, isolated derivations

SelfRecover and SelfDataGuard use **the same secrets** on the user side — the memorized word and the level-1 passphrase —, but derive them into **strictly disjoint** cryptographic keys.

The memorized word never leaves the browser. The browser computes a digest of it, the server receives that digest, and the integrator hands it to SelfDataGuard:

```
memorized_word (in the browser only, never stored)
    │
    └─ HMAC-SHA256(word, material + "|v2" + account salt)             →  digest (received by the server)
           │
           ├─ Argon2id(digest), random salt from password_hash()          →  SelfRecover verification
           └─ Argon2id(digest, SHA-256(user_salt+"/dataguard")[:16])      →  recov_key (SelfDataGuard)

passphrase (drawn by SelfRecover or brought, rolled with dice; received in plain at level 1)
    │
    ├─ Argon2id(normalise(passphrase)), random salt                        →  SelfRecover verification
    └─ Argon2id(normalise(passphrase), SHA-256(user_salt+"/dataguard/passphrase")[:16])  →  phrase_key
```

Passing the digest instead of the word costs nothing in security: an attacker guessing the word must compute the HMAC, then the Argon2id. What protects is the word's entropy and the Argon2id cost, not the shape of the string.

Cryptographic properties:

- **No crossover between the stores**: SelfRecover's database keeps an Argon2id of the digest under a random salt, the vault keeps another under a salt derived from `user_salt`. Neither is deduced from the other: a leak on one side does not open the other, and each is attacked at its own Argon2id cost.
- ⚠️ **The digest itself is password-equivalent for the vault.** Whoever holds it opens `wrap_recov`: the server, during a level-2 recovery by code, receives it. The same goes for every secret received server-side in Lite mode (§4.1), the password included — a server compromised in service sees them all go by.
- ⚠️ **The two paths do not carry the same risk and are not hardened the same way.** The SelfRecover verification controls an ACCESS: a server counts attempts, and SelfRecover also requires a recovery code — two factors. `recov_key` decrypts DATA: it is attacked offline on a dump, with a single factor and no counter. The same memorized word therefore cannot be held to the same requirements on both sides.
- **Simplified UX**: nothing more to remember; each secret serves both modules
- ⚠️ **A stolen passphrase opens both** — the account through level 1, the vault through `wrap_phrase` —, until its first legitimate use, which replaces it on both sides

### 3.2 The word can be regenerated independently

If the service renews the memorized word (SelfRecover provides that path only at level 3; elsewhere it belongs to the application, and neither library limits how often), SelfDataGuard must re-wrap `data_master_key` with the new `recov_key` through `setLock(…, Lock::Memorized, …)`, after SelfRecover's write. This does **not** require re-encrypting the personal data — only recomputing a new `wrap_recov`.

### 3.3 Use case: combined recovery

Scenario: a user has lost their password.

- **Without SelfDataGuard**: SelfRecover lets them set a new password. But could they have lost access to their personal data with it in plain text in the database? No: the database was in plain text, so the admin could always re-provide them.
- **With SelfDataGuard alone** (no SelfRecover): impossible, their data is encrypted with their `password_key`, which they no longer remember.
- **With both together**: they present their paper *recovery code* **and** their memorized word. SelfRecover checks both — the code locates the account and carries possession, the derived word carries knowledge — then authenticates them. SelfDataGuard needs only the word's digest, which the server has just received: it unwraps `wrap_recov`, then re-seals the vault on the password and passphrase SelfRecover has just issued. The user regains account access and data readability in the same pass.

This is the principle of the recovery phrases offered by end-to-end encrypted services: a secret kept offline restores access to the data when the password is lost.

### 3.4 Every recovery, and what the vault does with it

SelfRecover recoveries replace secrets; neither library calls the other. The integrator re-seals the vault, **after** the recovery is accepted, with the secret the server holds at that moment:

| Recovery | The server holds | SelfRecover replaces | SelfDataGuard |
|---|---|---|---|
| Level 1 — passphrase | the old passphrase | password and passphrase | `recover(Lock::Passphrase, old, new_password, new_passphrase)` |
| Level 2 — code + word | the word's digest | password and passphrase | `recover(Lock::Memorized, digest, new_password, new_passphrase)` |
| Level 2 — device | a signature | the password only | nothing at that moment; at the next secret given, `recover(lock, secret, current_password)`, rate-limited like the login |
| Level 3 — human escalation | no old secret | everything | `reEnroll(password, digest, passphrase)`: archive (§3.5) |

`recover()` unwraps the key, regenerates `wrap_pwd` — and `wrap_phrase` if a new passphrase is given — and writes it all at once, provided the vault still carries the same `user_salt`. A request holding a vault replaced in the meantime fails instead of overwriting it. Called on its own, the method is an Argon2id oracle with no rate limit: SelfRecover's acceptance, with its counters, comes first — and, for the catch-up after a level-2 device recovery, the login's rate limit.

### 3.5 Level 3: archiving, not destruction

At level 3 the user has no old secret left, and the vault cannot be re-sealed. Up to 0.4.0 the only way out was to delete it; yet a user may later find an old paper or an old word again.

`reEnroll()` therefore sets the live vault aside, **decrypting nothing** — envelopes, private fields, escrow —, and creates a new vault for the same account, in the same transaction. The AADs bind the account identifier only, so the old envelopes stay valid as they are. It sets aside only a vault whose profile seal holds: the archive keeps that seal, and one taken under a false profile, or under none, could not be repaired. A vault refused for its seal is refused here too: an unsealed one until the operator runs `sealProfile()`, a profile changed in the database until `repairProfile()` puts back the one the seal vouches for, an envelope changed until the row is restored from a backup. An archive opens under the profile its seal vouches for, whatever profile its row records: one changed in the database, while the archive was taken or since, is not followed, and nothing reports it.

- **Reopening**: `openArchive()` needs an old lock **and** a session on the current vault. Old secrets are precisely what may have leaked before level 3: on their own, they do not reach the old data through the service — given a dump, they do (§6.1). Derivation follows the Argon2id profile recorded in the archive, because an archive is not re-sealed when the profile changes.
- **Restoring**: `readArchive()` returns the fields, which the application writes back into the new vault with the fields it indexes — not a list read from the archive, which whoever writes the database could extend. Since 0.8.0 the indexes go into the archive's package, where no lookup and no unique index reaches them — live, they would keep answering equality lookups for data that is no longer live, and hold the value against its next holder. A value another account indexed since is refused at the restore (`DuplicateIndexException`): the field comes back without its index, and who owns the value is the operator's to settle.
- **Reinstating**: SelfRecover 0.13.0 keeps the secrets from before a level 3 valid for a window, and a holder who returns with one of them takes the account back. `reinstateArchive()` brings their archive back live, indexes included, and sets the requester's vault aside as an archive, in one transaction; a value another account indexed since refuses the whole swap. `freeze()` then holds the vault read-only — it opens and reads, every write refuses inside its own transaction — until the integrator calls `unfreeze()` once the arbiter has closed the window. The holder reads it meanwhile with an old lock, and re-seals it under their new secrets after. `eraseAccount()` still erases a frozen account. An archive taken before 0.8.0 comes back without its indexes.
- **The recovery's ticket**: these gestures take no secret. A façade given a `RecoveryTickets` source therefore refuses `reEnroll()`, `reinstateArchive()`, `freeze()` and `unfreeze()` without a ticket of their kind — level 3, holder's return, closure — issued for that account by the recovery that was accepted, and the refusal comes before any Argon2id (`RecoveryTicketException`). A ticket applies once, recorded in the write's transaction: a replay throws `TicketAlreadyAppliedException` and writes nothing. `archiveOfTicket()` names the archive a level-3 ticket set aside: a holder who comes back after two level-3 recoveries in the window gets the first one's. SelfRecover stores its tickets (`StorageInterface::ticketEmis()`); the source that reads them for the façade is the integrator's to write.
- **The escrow** goes with the archive. The administrator reopens it as for a live vault, through the same ceremony and with the same key: nothing more is exposed.
- **The keeper** (0.9.0): under a keeper — a second holder the integrator runs off the machine that serves —, `wrap_admin` is the box of before, sealed again to it, and the administrator alone no longer opens it. The ceremony hands it the outer box after the intention and the witness's head: it records the release, hands back the inner box, and signs for the holder what it released. `escrowReads()` asks, for the escrowId the session computes, every keeper — the one the holder's own deposit named in `wrap_user`, which the administrator does not rewrite, then the façade's and every former keeper, recorded by the database or given to the façade — and verifies each attestation under its keeper's key; an attestation that is missing or false reads "not established", never "no release", and masks only what its own keeper holds: the view names that keeper and lists what the others attest, each entry naming the keeper that attests it. An escrow row that is absent, restored from a backup, rewritten or unreadable does not decide what the holder sees (`escrow-replaced`). Each deposit draws a new escrow key: a key once obtained opens nothing deposited after it; but a read still announced hands back the escrow as it stands then, recorded and seen. A delay, set by the keeper, turns every read into an announcement the holder sees and cancels by an objection. The objection key belongs to the escrow, not to a box: its seed is derived from the holder's master key, and the escrowId is the fingerprint of its public half — so the keeper refuses a box that carries another key, and checks an objection against the escrowId alone: only the holder objects, from their session and whatever the database row holds, to any box of the escrow, before or after a deposit, before the read falls due or after a release. Nothing the keeper sees tells an archive's box from a live vault's, so an archive's read waits too; an archive has its own master key, hence its own objection key, which an impostor holding the live vault after a fraudulent level 3 does not have. The announcement holds the case: announced again with any box of the escrow, it keeps its time, and from then until it lapses, a week later, the keeper releases any box of the escrow, each release recorded. An objection holds for its case with no time limit. An escrow the operator's pass sealed reads `not-pinned` until its holder's next deposit, which computes its escrowId. When the deployment changes keeper, each deposit retires the escrow from every former keeper, recorded by the database or given to the façade — which writes the latter into the record first —, before writing: the retirement token names the keeper it is for, so a former keeper cannot replay it at the new one. On a frozen vault, or when a former keeper is missing from the façade, the deposit is refused and nothing is retired; it is refused too when a former keeper refuses the retirement. The attestation says when an escrow is retired, and `escrowReads()` says so (`retired`).
- **Destroying**: on explicit decision only. Deleting the live vault does not delete its archives, and deleting an archive takes it opened with one of its old locks: otherwise a fraudulent level 3 would let its author erase the legitimate holder's data. `eraseAccount()` erases everything, as the operator's act. Until it runs, `register()` refuses a userId that keeps archives: whoever registered it next would list them, and open them with an old secret. After `delete()`, no session can reach them — the holder has no live vault left to open them from — and `restoreArchive()`, an operator's step, brings one back live.
- ⚠️ An archive's memorized lock depends on the SelfRecover salt of that time, which level 3 replaces. The integrator keeps it — in the new vault, say — if that lock is to stay usable. The password and passphrase locks do not need it.

---

## 4. Three operational modes

Not all deployments share the same constraints. SelfDataGuard offers three modes depending on the desired zero-knowledge level.

### 4.1 Lite mode — transparent for legacy stacks

```
- All fields encrypted with data_master_key
- Server unwraps the key during user sessions only
- Admin operations possible only when user is logged in
```

**Use case**: B2B SaaS with low asynchronous admin needs, applications where the user stays logged in continuously (browser extensions, mobile apps in background).

**Limit**: no automatic transactional notifications. If a user places an order then logs out, and a cron wants to send a reminder 24h later, it cannot read the email.

### 4.2 Hybrid mode — recommended for e-commerce

```
- Operational fields (email, shipping_address): additionally wrapped with admin_op_key
- Sensitive fields (phone, KYC_doc, detailed_history): data_master_key only
- Admin can perform routine operations (orders, deliveries) without user presence
```

**Use case**: classic e-commerce, B2C SaaS with customer relationships.

**Trade-off**: application server compromise → exposure of operational fields only. Truly sensitive data (KYC, taxation, medical history) remains zero-knowledge even under RCE.

### 4.3 Full mode — strict zero-knowledge

```
- No encryption key is ever accessible to the server
- All cryptography executed in the browser, by libsodium compiled to WebAssembly: WebCrypto provides neither Argon2id nor XChaCha20-Poly1305
- Server only stores and serves encrypted blobs
```

**Use case**: health, banking, identity providers, activist networks, journalists exfiltrating sources.

**Trade-off**: redesign of several workflows. No more asynchronous transactional emails (push notifications instead). No more classic customer support (admin sees NOTHING of user data). Full-text search impossible (only blind indexes for equality search).

### 4.4 Default recommendation

Most e-commerce sites should pick **Hybrid**. High-assurance services (health, banking, sovereign services) should pick **Full** and accept the UX trade-offs.

---

## 5. Cryptographic primitives

| Use | Primitive | Rationale |
|-----|-----------|-----------|
| Password derivation | **Argon2id** (m=65536 KiB, t=3, p=1) | Memory-hard, resistant to GPUs and ASICs. Modern standard (RFC 9106). ⚠️ `p=1`, not `p=4`: `sodium_crypto_pwhash` **exposes no** parallelism parameter — its signature is `length, password, salt, opslimit, memlimit, algo`. Earlier versions of this table announced a parameter the chosen API cannot carry |
| Memorized-word derivation | **Argon2id** (same parameters) | Same cost as the password path since 0.3.0. Both keys unwrap the same `data_master_key`, and `wrap_recov` is attacked offline with no attempt counter — so the set was only ever as strong as its cheapest door. The free-length context is condensed into the 16-byte salt Argon2id requires |
| Passphrase derivation | **Argon2id** (same parameters), context `/dataguard/passphrase` | Same cost, for the same reason. Input normalised as SelfRecover does before comparing |
| Envelope encryption | **XChaCha20-Poly1305** | AEAD — ChaCha20-Poly1305 (RFC 8439) extended to a 192-bit nonce (draft-irtf-cfrg-xchacha). Computed in software, in constant time, on every CPU. Blobs written before 0.4.0 are AES-256-GCM and remain readable |
| Field encryption | **XChaCha20-Poly1305** with random 192-bit nonce per field | Idem. At 192 bits, a random nonce needs no counter |
| Search indexing | **HMAC-SHA256(key = HMAC-SHA256(key = server_blind_key, message = field name), message = value)**, Base64-encoded: one key per field, the value indexed as is | Allows `WHERE field_hash = HMAC(query)` without decrypting. Trade-off: strict equality search only, not full-text |

**No PBKDF2**: Argon2id is more robust against GPUs. PBKDF2 remains acceptable for interoperability with very old stacks but is discouraged for new deployments.

**No scrypt**: Argon2id covers the same properties and is now the standard recommended by OWASP, BSI, ANSSI (2023+ recommendations).

---

## 6. Threat model

### 6.1 Adversaries covered

| Adversary | Capability | Result with SelfDataGuard |
|-----------|------------|----------------------------|
| Remote attacker without server access | Sees traffic, submits API requests | No access to data (TLS + auth) |
| Attacker who exfiltrated the database (SQL dump, stolen backup) | Reads all tables in plain on disk | Encrypted values, to bruteforce user by user. Readable without a key: field names, modification dates, length of values (no padding), accounts sharing an indexed value |
| Insider DBA | Read access to database, not application server | Idem |
| Attacker with RCE on server | Memory and disk read of application process | Lite mode: the vaults of sessions open during the compromise, whose keys stay valid afterwards (they do not rotate); with the blind-index key, the indexed values that can be enumerated, for every account. Hybrid mode: operational fields exposed. Full mode: nothing |
| Compromise of a user account (endpoint phishing) | Captures that user's password | Data of that single user exposed. No fan-out |
| Stolen passphrase paper | A user's level-1 passphrase | That user's account and data, offline too given a dump, until the passphrase's first legitimate use, which replaces it on both sides |
| Leak of an old secret before a level 3 | One lock of the archive | Nothing through the service: the archive opens only from a session on the current vault. Given a dump, the archive opens offline with that lock |
| Coercion of an admin | Forces admin to provide their keys | Lite mode: no admin key on private vaults. The escrow has a permanent admin key: whoever coerces the holder of its passphrase obtains the escrow compartment of every account that has one — under a keeper, only what the keeper releases, each release recorded and shown to the holder. Hybrid mode: operational fields only. Full mode: nothing (admin has no key) |
| The administrator alone, with the database | The sealed admin key, its passphrase, writes to the database | Without a keeper: every account's escrow, outside the library, with no trace. Under a keeper: no escrow sealed to it, but through a release of the keeper, which waits the delay, is recorded, and is seen by the holder — a read under a case he opens himself included |
| Database write | Change, copy or put back rows | The escrow content is not authenticated against it: with the admin public key, stored in the clear, it seals a new compartment for an account and fills it, and the ceremony shows what it wrote. The admin seal naming its account (0.6.0) stops only a verbatim copy from one account to another. A vault row put back from an earlier revision opens with the secrets it carried, a consumed passphrase included: a limit, §8.1 |

### 6.2 Out-of-scope adversaries

In line with ANSSI's transparency best practices for threat models, SelfDataGuard explicitly declares:

- **User endpoint compromise** (keylogger, info-stealer, RAT): OUT OF SCOPE. If the user enters their password and memorized word on a compromised machine, their data on that site is exposed. Recommendation: Tails / Qubes for high-assurance use cases.
- **Browser compromise** (malicious extension, 0-day exploit): OUT OF SCOPE in Full mode as well. WebCrypto operations are only as secure as the browser.
- **Theoretical cryptanalysis of SHA-256, XChaCha20-Poly1305, AES-256-GCM, Argon2id**: OUT OF SCOPE. Cryptographic migration aligned with ANSSI / NIST recommendations when algorithms are declared weak.
- **Bruteforce of a weak password**: OUT OF SCOPE. The library must enforce a minimum password policy. Without policy, the weakest factor dominates.
- **Denial of service**: OUT OF SCOPE. SelfDataGuard does not address availability, only confidentiality.

---

## 7. Mandatory deployment rules

For a SelfDataGuard deployment to actually deliver the listed guarantees, it must respect:

1. **Password policy**: minimum 12 bytes, **enforced by the library** (`UserVault::PASSWORD_MIN_LEN`). Refusal through breach lists is left to the integrator — the library ships no list and no longer claims to
2. **Memorized-word policy**: **left to the integrator — the library enforces nothing**. It has hardened the cost per attempt (Argon2id since 0.3.0); it does not measure entropy and does not claim to. An integrator who wires `loginWithMemorized()` to a word chosen by the user must know that `wrap_recov` is then attacked offline, with no counter, on that single secret. See §2.3, open question
3. **Mandatory TLS**: no HTTP fallback allowed (strict HSTS)
4. **Short sessions**: in Lite, the only mode written, the library keeps `data_master_key` only for the lifetime of the object that opened it (`UnlockedVault` wipes it on destruction and refuses serialization). An application that keeps it from one request to the next sets the duration itself and purges it after inactivity. Target: 15 min for Hybrid, 5 min for Full
5. **No sensitive logging**: `password_key`, `recov_key`, `phrase_key`, `data_master_key` must never appear in logs (even at debug level)
6. **Admin access auditing**: in Hybrid mode, every admin access to operational fields must be logged (without the data itself)
7. **Regular updates**: track Argon2id recommendations to adjust `m` and `t` as hardware progresses (`p` is fixed at 1, see §5). Since 0.6.0 the profile is stored with each vault, each archive and the sealed admin key: changing the constants locks out no vault, no archive and no admin key, a vault keeps its profile at every re-seal, and a new vault takes the current profile. Raising the profile of an existing vault takes its secrets; the library provides no tool for it. What an integrator derives itself through `Primitives::deriveFromPassword()` or `deriveFromMemorized()` records no profile, and stops opening when the constants change. No derivation runs under a stored profile outside `Primitives::PROFILE_FLOOR_*` (the lowest ever written) and `PROFILE_CEILING_*` (four times the current one), and the profile of a vault is sealed under a server key, with its envelopes: changed in the database, either is reported as tampering before any derivation, and `repairProfile()` puts back the one the seal vouches for. libsodium allocates outside PHP's `memory_limit`: size the host for the ceiling, per concurrent derivation
8. **Re-sealing at every change of secret**: `recover()` at levels 1 and 2, **after** SelfRecover's acceptance and never before; the catch-up after a level-2 device recovery and `openArchive()` rate-limited by the integrator like its login; `reEnroll()` at level 3, with the generation read just before — after a `StaleVaultException`, read it again before retrying: the account may already hold the vault meant; `setLock()` when the service changes the password or renews the memorized word outside a recovery
9. **One account, one spelling**: `userId` is compared byte for byte — it enters the AAD of every envelope and keys the database row. A service whose accounts ignore case maps the name to the account's own before every call, otherwise a vault created under one spelling escapes the re-seals called under the other
10. **Escrow log anchor**: a head witness takes the head `seq:hmac` out of the machine at every ceremony and gives back the last one it took; the ceremony refuses a log cut or rewritten since. Once it has taken a head, a witness never answers that it holds none — the ceremony refuses one that does, whichever witness the log shows took a head —, and it refuses a head that does not advance. Its store lives off the machine, append-only, and is never restored from a backup. A new witness, or one that lost its store, is seeded by the operator (`seed-witness --ancre`): once the log is found to hold the anchor noted outside, the witness takes its head, and the act is logged. Verify by hand with `--ancre`. Without an anchor, a log whose end was cut off passes verification. A new log secret opens a new log: under it, the old one breaks at its first line. The old log is verified a last time against its anchor and kept; the new one starts in a new file, with the witness's store emptied — a log with no intention takes a witness that holds none
11. **A conformant storage**: an implementation of `StorageInterface` of your own passes `bin/storage-conformance.php` before it serves anyone. A storage can match every signature, receive the vault's generation and not compare it, and the write lands in a vault whose key cannot read it
12. **Sealed profiles**: after upgrading a database to 0.7.0, and before serving, the operator runs `sealProfile()` once for every account. Until then a vault written earlier opens to no one
13. **Indexing a value the account holds**: index a value — an address, a number — once the account has proven it holds it. Whoever indexes it first keeps it, and the second account is told it is taken
14. **Writing through the façade**: every write of a vault row goes through the façade (`setLock()`, `removeLock()`, `recover()`, `reEnroll()`), which seals what it writes. A row written beside it — through `UserVault` and the storage — is refused as tampering, and only a backup restores it
15. **A ticket source**: a deployment coupled with SelfRecover gives the façade a `RecoveryTickets` source (§3.5). Without one, `reEnroll()`, `reinstateArchive()`, `freeze()` and `unfreeze()` take no ticket: a `reEnroll()` on the generation `generationOf()` returns goes through as often as it is called, with no recovery behind it
16. **A keeper off the machine that serves**: a deployment that wants its administrator not to open the escrow alone runs an `EscrowKeeper` whose store lives elsewhere, append only, never restored from a backup, and held by someone other than the administrator. It plays it through `bin/keeper-conformance.php`, gives it to every façade and to the ceremony, then runs `seal-to-keeper`. The library refuses another keeper than the one the database recorded, in the façade and in the ceremony. To replace it, the operator calls `SelfDataGuard::changeKeeper()` and gives the former keeper to every façade and to the ceremony from then on — the database keeps it on record: they read through it, each deposit retires the escrow there, a deposit without it is refused, and a keeper replaced is never taken back. Only keepers the deployment replaced are given to a façade as former ones: each deposit writes them into the record, and `changeKeeper()` refuses as the new keeper any keeper that took retirements — a keeper given as former without having been replaced included. A former keeper gone for good leaves the record by `SelfDataGuard::forgetFormerKeeper()`, and the operator stops giving it to the façades and to the ceremony — otherwise each deposit still asks it, and the ceremony refuses every read; what is still sealed to it then no longer reads through the ceremony. A keeper the administrator holds himself protects nothing

Failure to respect any of these rules significantly degrades the guarantees. The reference library enforces rule 1, refuses to open a vault rule 12 has not sealed or a row rule 14 did not write, refuses the four gestures of rule 15 without their ticket once given a source, refuses a façade without the keeper the database recorded (rule 16), and enforces rule 5 for its own exception traces (`#[\SensitiveParameter]`, from PHP 8.2: on 8.1, which the library supports, the attribute is ignored, and only `zend.exception_ignore_args` strips arguments from traces); for rule 7 it stores each vault's profile, but choosing and raising it is the integrator's. The other rules are up to the integrator and the deployment configuration.

---

## 8. Limitations and future work

### 8.1 Known limitations

- **Full-text search** on encrypted fields: impossible without advanced techniques (partial homomorphic encryption, secure indexes like CipherSweet)
- **Asynchronous transactional notifications**: require admin_op_key (Hybrid mode) or redesign toward push (Full mode)
- **Schema migration**: if an encrypted field is added to an existing account, it must be populated during an active user session
- **The escrow against the administrator alone**: without a keeper, the ceremony keeps everyone else out and keeps a trace, but whoever holds the sealed admin key file and its passphrase opens `wrap_admin` outside the library, with no log. Under a keeper (0.9.0) he no longer opens it, and these remain:
  - he chooses the keeper, and a keeper he holds protects nothing — its fingerprint is in every `wrap_user`, and `escrowReads()` shows it;
  - a read under a case he opens himself remains possible, recorded by the keeper and seen by the holder;
  - an escrow sealed before stays readable by him alone until the operator's pass, and a copy of the database taken before, until its holder's next deposit;
  - whoever reaches the keeper with what the database holds is handed, once due and again until the announcement lapses, a box the admin key opens outside the ceremony and its log — the delay and the objection hold, and the holder sees each release;
  - whoever writes the database can still delete an escrow row, put an older one back or rewrite it, and the holder sees the escrow vanish or change; what the keepers released and hold announced for the escrowId the session computes stays in the holder's view (`escrow-replaced`); a row that does not open its fields stops every deposit and every read of them until the row that wrote them is put back: the library does not erase them, they stay under its key; if a field alone was rewritten, deposits and reads stay stopped for good;
  - until the keeper has received, for an escrowId, a box that carries an objection key, it accepts boxes without one: whoever reads the database can have a read announced, with such a box, under an escrowId found there; the holder sees the announcement and can object to it;
  - a box the operator's pass sealed carries no objection key and keeps the escrowId the pass drew: nobody objects to its reads, and they are out of the holder's view, before and after the escrow is pinned;
  - an archive's escrow is never re-sealed nor retired from its holder's side: a copy taken before the pass opens it while it stays an archive, and a former keeper still releases an archive sealed to it;
  - a former keeper stays on the database's record until the operator forgets it (`forgetFormerKeeper()`): each deposit retires the escrow from it again, and every deposit is refused while it is not given or does not answer; a deposit that fails after the retirements — a former keeper that refuses, a write that fails, concurrent deposits that exhaust the retries (`StaleEscrowException`) — leaves the escrow retired from the former keepers that took the retirement, and the view says `retired` until a deposit goes through; a former keeper that does not answer leaves the view not established — naming it in `unanswered`, with what the others attest — and the objection reaches the others; the view asks every keeper at each opening of a vault: N former keepers make N + 1 attestations, and a slow one slows every opening;
  - a forgotten keeper that still lives keeps releasing what is still sealed to it, out of the holder's view, like a keeper replaced without `changeKeeper()`: forget only a keeper gone for good;
  - a database put back from before a keeper change takes back a keeper the escrows were retired from: the view says `retired`, the deposits go through and stay out of the ceremony's reach until the operator changes to a keeper the database never had;
  - a keeper replaced without `changeKeeper()`, and not given to the façades, still releases what it holds, out of the holder's view;
  - whoever holds the master key can object to every read of the escrow and retire it from any keeper — the façade's own included, which then releases it no more until the deployment changes keeper —, never read it through the keeper;
  - the contract requires each call of the keeper to be atomic against the others; the conformance suite, which plays one caller at a time, does not test it;
  - a key obtained by a release still opens what it was released for.
- **Archives written before 0.7.0** carry no profile seal: they are opened under the profile they recorded, which nothing vouches for. One whose profile was changed in the database fails as a wrong secret, and the operator tries the published profiles on it.
- **The blind-index key holds every seal and every index**: the database records a check of its key, and a façade that brings another is refused, naming it. An empty database records the key of the first façade opened on it; a database that already holds vaults and recorded none — written before 0.7.0 — records one only when the operator adopts it with `adoptBlindKey()`, proving it by a lookup the database answers or a seal that holds; a database where no field carries an index has nothing to prove it against, and the operator vouches for it. A database that indexes a field refuses that word, and refuses shared fields that omit one carrying shared indexes. Lost, nothing opens until it is back: keep it with the database's backups. Rotating it is not provided — the blind indexes could only be recomputed from every value in clear. Recording the check is a write; a database that holds it is only read, and a read-only copy of it opens.
- **A seal removed from a vault whose envelopes all predate 0.7.0** — sealed by `sealProfile()`, never re-sealed since — reads as a vault from before 0.7.0, and `sealProfile()` seals the row as it then stands. Its next `setLock()` or `recover()` re-seals its envelopes in format v3 — `removeLock()`, `sealProfile()` and `repairProfile()` leave them as they are —, after which a seal removed is reported as tampering. Envelopes written by unreleased code between 0.6.2 and 0.7.0 are format v3 without a seal: such a vault is refused as tampered.
- **A head witness restored from a backup** vouches for a log cut back to the head it holds: nothing in the library can tell a restored witness from a faithful one. After a restore, the operator verifies the log by hand against an anchor noted elsewhere (`verify-log --ancre`) before the next ceremony.
- **One damaged envelope closes the whole vault**: the seal tells that the row changed, not which envelope. Every lock and level 3 refuse it before any derivation, until the row is restored from a backup.
- **Existence by timing**: an unknown userId, or a vault not yet sealed, is refused before any derivation; a known one after it, at the cost of one Argon2id derivation. Whoever knows a name learns whether it has a vault, and whether the instance has sealed it — not which locks it carries. `register()` tells, by its refusal, that a deleted name still holds archives.
- **Replay of an earlier revision**: whoever can write to the database can put back an old vault row, with its old envelopes. It opens with the secrets it carried, including a passphrase SelfRecover has consumed since. The vault's revision guards against concurrent writes, not against a restored row. Binding the envelopes to the revision would take the three secrets at every write, and a password change does not have the memorized word at hand; a MAC over the set of envelopes does not withstand someone who restores the whole row from an old backup. The defence lies outside the vault: the integrity of the database and its backups
- **Performance**: the overhead of each encrypted field has not been measured yet. For queries listing many accounts, it compounds: to evaluate case by case.

### 8.2 Roadmap

- **v0.1.0** (shipped as beta on 2026-05-08): reference PHP implementation, SQLite storage behind `StorageInterface`; the Eloquent / Doctrine integration is still to be written — the library's current size is given by the README, which is measured at each edition
- **v0.2.0** (shipped 2026-08-21, Q3): escrow compartment, key ceremony, audit log
- **v0.3.0** (shipped 2026-09-07): Argon2id derivation of the memorized secret, password length floor enforced in code
- **v0.4.0** (shipped 2026-09-26): XChaCha20-Poly1305 for every write, versioned blob format (`SDG2.`), AES-256-GCM blobs read by libsodium or OpenSSL
- **v0.5.0** (2026-10-01): third lock (SelfRecover passphrase), `recover()` for every recovery path, archiving at level 3, in-place schema migration
- **v0.6.0** (2026-10-03): Argon2id profile stored with each vault and the sealed admin key, admin seal bound to its account, anchorable escrow log, reserved field name `escrow`, frozen Argon2id vectors
- **v0.6.1** (2026-10-14): the unlocked keys and the server's secrets masked in `var_dump()` and `print_r()`, unlocked objects that refuse to be cloned, secret parameters kept out of exception traces
- **v0.6.2** (2026-10-15): an escrow log entry forced to disk (`fsync()`), and each suite's exact total required in CI
- **v0.7.0** (2026-10-27): every write bound to its context (AAD, blob format v3), the Argon2id profile and the envelopes sealed under the server key, keys that no object hands out, an escrow read only through a ceremony that is arbitrated, logged and witnessed, a database that records its blind-index key and adopts it only on proof, and a storage conformance suite
- **v0.8.0** (2026-10-29): an archived vault brought back live with its indexes, a vault frozen read-only — the window SelfRecover 0.13.0 opens after a level 3 —, and the gestures without a secret requiring the ticket of the accepted recovery
- **v0.9.0** (2026-11-02): an escrow sealed to a keeper that the administrator alone no longer opens, its releases signed for the holder, a delay with objection for every read, a new escrow key at each deposit, a revision on the escrow row, and the operator's pass without a secret
- **v0.10.0** (upcoming): rotation of the blind-index key through a two-key transition, advanced blind index extension for searchable encryption, multi-tenant support
- **v1.0.0** (2027): formal community cryptographic audit, ANSSI Visa de sécurité submission (industries@ssi.gouv.fr), test vector pack publication

---

## 9. License and author

**AGPL-3.0-or-later**. Code, documentation, and whitepapers published in the `Pierroons/my-self` repository.

A modified version offered to users over a network must give them access to its source code, under the same license (AGPL-3.0, section 13).

Author: Pierroons. Contact details accessible via the public repository.

Technical feedback, community audits, and cryptographic critiques are welcome, especially from researchers and practitioners who have already integrated wrapped-key vault architectures (Bitwarden, 1Password, ProtonMail, Cryptee).

---

*First edition May 2026; this edition 3 November 2026, aligned on SelfDataGuard v0.9.0. ⚠️ This English edition trails the French one: the French version was revised on 23 July 2026 and is authoritative where the two differ. Its cryptographic claims were realigned on the code on 7 September 2026, then re-read against the French edition on 26 September 2026 for §2.2, §3.1, §6 and §7 (Argon2id parallelism, SelfRecover formula, deployment rules); the rest of the edition has not been re-read against the French one. The algorithms of §2.2 and §5 were realigned on the code on 26 September 2026; §2, §3, §5, §6, §7 and §8.2 were rewritten alongside the French edition on 1 October 2026 for v0.5.0; §2.2, §6.1, §7 and §8.1 on 3 October 2026 for v0.6.0. §3.5, §6.1, §7, §8.1 and §8.2 were rewritten for v0.7.0, v0.8.0 and v0.9.0, the last time on 3 November 2026. The specification described here is implemented and tested from v0.1.0 to v0.9.0 (890 checks across 14 suites, counted on PHP 8.4 on 3 November 2026; CI also runs them under PHP 8.1 and 8.2, where four trace checks become declared limits).*
