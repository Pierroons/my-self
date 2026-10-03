# SelfDataGuard — Whitepaper

**Application-layer data-at-rest protection that survives a database exfiltration**
*Dump my database — and get encrypted noise.*

*Edition of 3 October 2026 — describes SelfDataGuard v0.6.0. The French edition is authoritative where the two differ.*

---

## Context (May 2026)

The pattern is always the same: an authorization flaw grants access to other people's accounts, and what it exposes sits **in plain text in the database**, with no application-layer encryption that could have rendered it unusable. Civil status, contact details, identity status: data you cannot change the way you change a password.

There is a structural question here, complementary to the one SelfRecover addresses: **how to make a database leak technically useless to the attacker**, independently of the authentication flow?

SelfRecover protects **account access**. SelfDataGuard protects **stored data**. Together, the two modules close the loop: an attacker bypassing authentication (SelfRecover) finds an encrypted database (SelfDataGuard); an attacker dumping the database (SelfDataGuard) finds non-reversible Argon2id hashes (SelfRecover).

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

- A database dump alone → **encrypted soup**, no usable personal data
- A dump while a user is logged in → exposure limited to **that single user**, no cross-user fan-out
- An admin compromise → exposes **operational fields** (Hybrid mode) or nothing at all (Full mode)

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
1. SelfRecover issues a new password and a new passphrase; the old one is consumed
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

   > **Fixed on 2026-09-06.** Up to 0.3.0, `recov_key` was derived by a single HMAC-SHA256 pass, on the assumption written in this very document that "the memorized word must have sufficient entropy by construction", with a recommended floor of 30 bits. Measured on a deployment host: **213.7 ms per Argon2id attempt against 0.0027 ms per HMAC attempt, a factor of 78,100**. Both keys unwrap the SAME `data_master_key`, and `wrap_recov` is attacked offline with no attempt counter: the security of the pair was therefore that of its cheaper door, whatever the cost of the other. A salt forbids precomputation but adds **no bit** against a targeted person; the AEAD tag tells the attacker which attempt was the right one, it does not slow them down. Only the cost per attempt buys time, and it buys a factor, never entropy.
   >
   > **No entropy floor is enforced.** Argon2id buys a multiplier, not entropy: a weak word remains ~13 bits of guessing plus ~13 bits of cost. A floor high enough to matter (77.5 bits) would end the sharing of the memorized word between SelfRecover and the vault (§3.1) — a design decision, not a setting. It is stated here as an open question rather than answered silently in either direction.

3. **Bruteforce the passphrase** → the same Argon2id cost per attempt. Here the entropy is known, because SelfRecover draws it at random: six words from a 7,776-word list, about 77.5 bits. It is the strongest door by computation. Its weakness lies elsewhere: it is written on paper (§6.1).

A leak therefore yields **nothing immediately exploitable**. Bruteforce cost is per-user (impossible to bruteforce the whole database in parallel because each user has their own `user_salt`).

---

## 3. Coupling with SelfRecover

### 3.1 Shared secrets, isolated derivations

SelfRecover and SelfDataGuard use **the same secrets** on the user side — the memorized word and the level-1 passphrase —, but derive them into **strictly disjoint** cryptographic keys.

The memorized word never leaves the browser. The browser computes a digest of it, the server receives that digest, and the integrator hands it to SelfDataGuard:

```
memorized_word (in the browser only, never stored)
    │
    └─ HMAC-SHA256(word, domain + "|v2" + account salt)               →  digest (received by the server)
           │
           ├─ Argon2id(digest), random salt from password_hash()          →  SelfRecover verification
           └─ Argon2id(digest, SHA-256(user_salt+"/dataguard")[:16])      →  recov_key (SelfDataGuard)

passphrase (drawn at random by SelfRecover, received in plain at level 1)
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

If the user changes their memorized word (per SelfRecover rule: maximum 2-3 regenerations via current password), SelfDataGuard must re-wrap `data_master_key` with the new `recov_key`. This does **not** require re-encrypting the personal data — only recomputing a new `wrap_recov`.

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

`reEnroll()` therefore sets the live vault aside, **decrypting nothing** — envelopes, private fields, escrow —, and creates a new vault for the same account, in the same transaction. The AADs bind the account identifier only, so the old envelopes stay valid as they are.

- **Reopening**: `openArchive()` needs an old lock **and** a session on the current vault. Old secrets are precisely what may have leaked before level 3: on their own, they do not reach the old data through the service — given a dump, they do (§6.1). Derivation follows the Argon2id profile recorded in the archive, because an archive is not re-sealed when the profile changes.
- **Restoring**: `readArchive()` returns the fields, which the application writes back into the new vault. Blind indexes are not archived — they would keep answering equality lookups for data that is no longer live —, but each field records whether it was indexed.
- **The escrow** goes with the archive. The administrator reopens it as for a live vault, with the same key: nothing more is exposed.
- **Destroying**: on explicit decision only. Deleting the account does not delete its archives; otherwise a fraudulent level 3 followed by a deletion would erase the legitimate holder's.
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
| Search indexing | **HMAC-SHA256(field, server_blind_key)** | Allows `WHERE field_hash = HMAC(query)` without decrypting. Trade-off: equality search only, not full-text |

**No PBKDF2**: Argon2id is more robust against GPUs. PBKDF2 remains acceptable for interoperability with very old stacks but is discouraged for new deployments.

**No scrypt**: Argon2id covers the same properties and is now the standard recommended by OWASP, BSI, ANSSI (2023+ recommendations).

---

## 6. Threat model

### 6.1 Adversaries covered

| Adversary | Capability | Result with SelfDataGuard |
|-----------|------------|----------------------------|
| Remote attacker without server access | Sees traffic, submits API requests | No access to data (TLS + auth) |
| Attacker who exfiltrated the database (SQL dump, stolen backup) | Reads all tables in plain on disk | Encrypted soup, must bruteforce each user individually |
| Insider DBA | Read access to database, not application server | Idem, encrypted soup |
| Attacker with RCE on server | Memory and disk read of application process | Lite mode: active sessions exposed. Hybrid mode: operational fields exposed. Full mode: nothing |
| Compromise of a user account (endpoint phishing) | Captures that user's password | Data of that single user exposed. No fan-out |
| Stolen passphrase paper | A user's level-1 passphrase | That user's account and data, offline too given a dump, until the passphrase's first legitimate use, which replaces it on both sides |
| Leak of an old secret before a level 3 | One lock of the archive | Nothing through the service: the archive opens only from a session on the current vault. Given a dump, the archive opens offline with that lock |
| Coercion of an admin | Forces admin to provide their keys | Lite mode: no permanent admin key, so nothing. Hybrid mode: operational fields only. Full mode: nothing (admin has no key) |
| Database write | Change, copy or put back rows | An admin seal copied into another account's row is refused when opened (since 0.6.0). A vault row put back from an earlier revision opens with the secrets it carried, a consumed passphrase included: a limit, §8.1 |

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
4. **Short sessions**: `data_master_key` purged from session after inactivity (15 min recommended for Hybrid, 5 min for Full)
5. **No sensitive logging**: `password_key`, `recov_key`, `phrase_key`, `data_master_key` must never appear in logs (even at debug level)
6. **Admin access auditing**: in Hybrid mode, every admin access to operational fields must be logged (without the data itself)
7. **Regular updates**: track Argon2id recommendations to adjust `m` and `t` as hardware progresses (`p` is fixed at 1, see §5). Since 0.6.0 the profile is stored with each vault, each archive and the sealed admin key: changing the constants locks nothing existing out, a vault keeps its profile at every re-seal, and a new vault takes the current profile. Raising the profile of an existing vault takes its secrets; the library provides no tool for it
8. **Re-sealing at every change of secret**: `recover()` at levels 1 and 2, **after** SelfRecover's acceptance and never before; the catch-up after a level-2 device recovery and `openArchive()` rate-limited by the integrator like its login; `reEnroll()` at level 3; `changePassword()` and `changeMemorized()` when the service changes the password or renews the memorized word outside a recovery
9. **One account, one spelling**: `userId` is compared byte for byte — it enters the AAD of every envelope and keys the database row. A service whose accounts ignore case maps the name to the account's own before every call, otherwise a vault created under one spelling escapes the re-seals called under the other
10. **Escrow log anchor**: write down off the machine the head `seq:hmac` that `verify-log` prints, and verify with `--ancre`. Without an anchor, a log whose end was cut off passes verification

Failure to respect any of these rules significantly degrades the guarantees. The reference library enforces rule 1, rule 5 for its own exception traces (`#[\SensitiveParameter]`), and rule 7 for the profile it stores; the others are up to the integrator and the deployment configuration.

---

## 8. Limitations and future work

### 8.1 Known limitations

- **Full-text search** on encrypted fields: impossible without advanced techniques (partial homomorphic encryption, secure indexes like CipherSweet)
- **Asynchronous transactional notifications**: require admin_op_key (Hybrid mode) or redesign toward push (Full mode)
- **Schema migration**: if an encrypted field is added to an existing account, it must be populated during an active user session
- **Replay of an earlier revision**: whoever can write to the database can put back an old vault row, with its old envelopes. It opens with the secrets it carried, including a passphrase SelfRecover has consumed since. The vault's revision guards against concurrent writes, not against a restored row. Binding the envelopes to the revision would take the three secrets at every write, and a password change does not hold the memorized word; a MAC over the set of envelopes does not withstand someone who restores the whole row from an old backup. The defence lies outside the vault: the integrity of the database and its backups
- **Performance**: the overhead of each encrypted field has not been measured yet. For queries listing many accounts, it compounds: to evaluate case by case.

### 8.2 Roadmap

- **v0.1.0** (shipped as beta on 2026-05-08): reference PHP implementation, SQLite storage behind `StorageInterface`; the Eloquent / Doctrine integration is still to be written — the library's current size is given by the README, which is measured at each edition
- **v0.2.0** (shipped 2026-08-21, Q3): escrow compartment, key ceremony, audit log
- **v0.3.0** (shipped 2026-09-07): Argon2id derivation of the memorized secret, password length floor enforced in code
- **v0.4.0** (shipped 2026-09-26): XChaCha20-Poly1305 for every write, versioned blob format (`SDG2.`), AES-256-GCM blobs read by libsodium or OpenSSL
- **v0.5.0** (2026-10-01): third lock (SelfRecover passphrase), `recover()` for every recovery path, archiving at level 3, in-place schema migration
- **v0.6.0** (upcoming): advanced blind index extension for searchable encryption, multi-tenant support
- **v1.0.0** (2027): formal community cryptographic audit, ANSSI Visa de sécurité submission (industries@ssi.gouv.fr), test vector pack publication

---

## 9. License and author

**AGPL-3.0-or-later**. Code, documentation, and whitepapers published in the `Pierroons/my-self` repository.

A modified version offered to users over a network must give them access to its source code, under the same license (AGPL-3.0, section 13).

Author: Pierroons. Contact details accessible via the public repository.

Technical feedback, community audits, and cryptographic critiques are welcome, especially from researchers and practitioners who have already integrated wrapped-key vault architectures (Bitwarden, 1Password, ProtonMail, Cryptee).

---

*First edition May 2026; this edition 3 October 2026, aligned on SelfDataGuard v0.6.0. ⚠️ This English edition trails the French one: the French version was revised on 23 July 2026 and is authoritative where the two differ. Its cryptographic claims were realigned on the code on 7 September 2026, then re-read against the French edition on 26 September 2026 for §2.2, §3.1, §6 and §7 (Argon2id parallelism, SelfRecover formula, deployment rules); the rest of the edition has not been re-read against the French one. The algorithms of §2.2 and §5 were realigned on the code on 26 September 2026; §2, §3, §5, §6, §7 and §8.2 were rewritten alongside the French edition on 1 October 2026 for v0.5.0; §2.2, §6.1, §7 and §8.1 on 3 October 2026 for v0.6.0. The specification described here is implemented and tested from v0.1.0 to v0.6.0 (362 checks, 11 suites, on PHP 8.1, 8.2 and 8.4).*
