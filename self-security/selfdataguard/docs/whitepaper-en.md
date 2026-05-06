# SelfDataGuard — Whitepaper v0.0.1

**Application-layer data-at-rest protection that survives a database exfiltration**
*Dump my database — and get encrypted noise.*

---

## Context (May 2026)

On April 15, 2026, the `moncompte.ants.gouv.fr` portal (Agence nationale des titres sécurisés — France's national agency for secure documents) suffered a data breach via an IDOR vulnerability: changing an identifier in an API request granted access to another citizen's account. The Ministry of the Interior confirmed 11.7 million accounts affected; attackers claim up to 19 million records exfiltrated. Exposed data: civil status, contact details, identity certification status — **stored in plain text**, with no application-layer encryption that could have rendered them unusable.

The incident raised a structural question complementary to the one addressed by SelfRecover: **how to make a database leak technically useless to the attacker**, independently of the authentication flow?

SelfRecover protects **account access**. SelfDataGuard protects **stored data**. Together, the two modules close the loop: an attacker bypassing authentication (SelfRecover) finds an encrypted database (SelfDataGuard); an attacker dumping the database (SelfDataGuard) finds non-reversible Argon2id hashes (SelfRecover).

This whitepaper describes the SelfDataGuard protocol. It is neither an ad-hoc critique of any single actor nor a post-incident claim — it is an open-source proposal complementary to SelfRecover, that public and private operators may audit, integrate, or contest freely.

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

In all six cases, an attacker who obtains a shell on the application server (RCE, privilege escalation, SSH key theft) **simultaneously** obtains the database and the key. Data-at-rest encryption then provides **no protection at all** — it only protected against an attacker holding the disk without the server (a rare scenario in practice).

### 1.2 The real question

> How to encrypt a user's data such that the key only exists **when that user is present**, and nowhere else permanently?

This is exactly the question solved by Bitwarden (password vault), 1Password, ProtonMail (encrypted mailbox). Their common architecture: **key wrapping**, where the user's master key only exists in plain text in memory for the duration of a session, and is wrapped by a secret known only to them (master password).

SelfDataGuard ports this **personal vault** architecture to the **user database of a multi-tenant application** (e-commerce, SaaS, public service).

---

## 2. The SelfDataGuard model

### 2.1 Fundamental principle

> *For each user, the database stores their data encrypted with a key that is never stored in plain text. This key is wrapped by two distinct factors, derived respectively from their password and their memorized recovery word. During an active session, the server unwraps the key in RAM and uses it to serve requests; on logout, the key is purged.*

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

Step 3 — Derive the two wrap keys:
    password_key     ← Argon2id(password, user_salt, m=65536, t=3, p=4)
    recov_key        ← HMAC-SHA256(memorized_word, user_salt || "/dataguard")

Step 4 — Wrap the master key with each of the two keys:
    wrap_pwd         ← AES-256-GCM-encrypt(data_master_key, key=password_key, nonce=random_96)
    wrap_recov       ← AES-256-GCM-encrypt(data_master_key, key=recov_key,    nonce=random_96)

Step 5 — Database storage (all values listed below stored in plain):
    user_id, user_salt, wrap_pwd, wrap_recov, [optional] wrap_admin

Step 6 — Field-by-field encryption of personal data:
    email_encrypted        ← AES-256-GCM-encrypt(email,    key=data_master_key, nonce=random_96)
    address_encrypted      ← AES-256-GCM-encrypt(address,  key=data_master_key, nonce=random_96)
    phone_encrypted        ← AES-256-GCM-encrypt(phone,    key=data_master_key, nonce=random_96)
    [...]

Step 7 — Wipe data_master_key, password_key and recov_key from server memory.
```

On password login (standard case, ~99% of the time):

```
1. Server receives (username, password) over HTTPS
2. Fetch user_salt and wrap_pwd from the database
3. password_key   ← Argon2id(password, user_salt, ...)
4. data_master_key ← AES-256-GCM-decrypt(wrap_pwd, key=password_key)
5. data_master_key kept in session memory (never persisted)
6. On each request: on-the-fly decryption of personal fields
7. On logout: wipe data_master_key
```

On memorized-word login (degraded case, password forgotten):

```
1. Server receives (username, memorized_word) over HTTPS
2. Fetch user_salt and wrap_recov from the database
3. recov_key       ← HMAC-SHA256(memorized_word, user_salt || "/dataguard")
4. data_master_key ← AES-256-GCM-decrypt(wrap_recov, key=recov_key)
5. User can access their data and set a new password
6. Regenerate wrap_pwd with the new password_key (no need to re-encrypt the data fields)
```

### 2.3 Why this architecture survives a dump

An attacker who exfiltrates the user table obtains:

- `username` (plain, identifier)
- `user_salt` (plain, identifier-grade)
- `wrap_pwd` (encrypted with `password_key`, unknown to attacker)
- `wrap_recov` (encrypted with `recov_key`, unknown to attacker)
- `email_encrypted, address_encrypted, ...` (encrypted with `data_master_key`, unknown to attacker)

To decrypt, the attacker has two paths:

1. **Bruteforce a target user's password** → cost of Argon2id per attempt (~250 ms on top-tier GPU with recommended parameters). For an 8-character random password: ~10^14 attempts × 0.25 s = ~10^6 years in massive parallel. For a weak password (`123456` or similar), still feasible. **Recommendation**: the library refuses passwords below 12 characters or present in blocklists.

2. **Bruteforce the memorized word** → HMAC-SHA256 is fast (~10^9 / s on GPU), but the search space depends on the word. If the memorized word is a dictionary word (~30,000 common French words), bruteforce is trivial. **Recommendation**: the memorized word must be a combination of at least two words or a rare word (entropy ≥ 30 bits). To document in the registration UX.

A leak therefore yields **nothing immediately exploitable**. Bruteforce cost is per-user (impossible to bruteforce the whole database in parallel because each user has their own `user_salt`).

---

## 3. Coupling with SelfRecover

### 3.1 Shared memorized word, two isolated derivations

SelfRecover and SelfDataGuard use **the same memorized word** on the user side, but derive it into two **strictly disjoint** cryptographic keys via contextual HMAC:

```
raw_secret = user_memorized_word
             (never transmitted in plain, never stored)

         ┌──────────────────────────────────────────────────────┐
         │     HMAC-SHA256(raw_secret, domain + "/recover")    │  →  recover_key  (SelfRecover)
         ├──────────────────────────────────────────────────────┤
         │     HMAC-SHA256(raw_secret, salt + "/dataguard")    │  →  data_key    (SelfDataGuard)
         └──────────────────────────────────────────────────────┘
```

Cryptographic properties:

- **Independence**: knowledge of `recover_key` reveals no information about `data_key`, and vice versa (HMAC-SHA256 is a PRF, its outputs on different labels are indistinguishable from random)
- **No crossover**: a leak on the SelfRecover side (e.g., compromise of the Argon2id hash store) does not expose SelfDataGuard, and vice versa
- **Simplified UX**: the user memorizes a single secret, derives two purposes from it

### 3.2 The word can be regenerated independently

If the user changes their memorized word (per SelfRecover rule: maximum 2-3 regenerations via current password), SelfDataGuard must re-wrap `data_master_key` with the new `recov_key`. This does **not** require re-encrypting the personal data — only recomputing a new `wrap_recov`.

### 3.3 Use case: combined recovery

Scenario: a user has lost their password.

- **Without SelfDataGuard**: SelfRecover lets them set a new password. But could they have lost access to their personal data with it in plain text in the database? No: the database was in plain text, so the admin could always re-provide them.
- **With SelfDataGuard alone** (no SelfRecover): impossible, their data is encrypted with their `password_key`, which they no longer remember.
- **With both together**: they enter their memorized word. SelfRecover derives `recover_key` and authenticates them. SelfDataGuard derives `data_key`, unwraps `wrap_recov`, and restores `data_master_key`. The user simultaneously regains account access and data readability.

This is the exact mechanism found in Bitwarden (recovery code) or ProtonMail (recovery phrase).

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
- All cryptography executed in the browser via WebCrypto SubtleCrypto
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
| Password derivation | **Argon2id** (m=65536 KiB, t=3, p=4) | Memory-hard, resistant to GPUs and ASICs. Modern standard (RFC 9106) |
| Memorized-word derivation | **HMAC-SHA256** | Fast (UX-compatible), proven PRF. No memory-hardening because the memorized word must have sufficient entropy by construction |
| Envelope encryption | **AES-256-GCM** | Authenticated encryption, universal hardware acceleration, NIST standard |
| Field encryption | **AES-256-GCM** with random 96-bit nonce per field | Idem |
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
| Coercion of an admin | Forces admin to provide their keys | Lite mode: no permanent admin key, so nothing. Hybrid mode: operational fields only. Full mode: nothing (admin has no key) |

### 6.2 Out-of-scope adversaries

In line with ANSSI's transparency best practices for threat models, SelfDataGuard explicitly declares:

- **User endpoint compromise** (keylogger, info-stealer, RAT): OUT OF SCOPE. If the user enters their password and memorized word on a compromised machine, their data on that site is exposed. Recommendation: Tails / Qubes for high-assurance use cases.
- **Browser compromise** (malicious extension, 0-day exploit): OUT OF SCOPE in Full mode as well. WebCrypto operations are only as secure as the browser.
- **Theoretical cryptanalysis of SHA-256, AES-256-GCM, Argon2id**: OUT OF SCOPE. Cryptographic migration aligned with ANSSI / NIST recommendations when algorithms are declared weak.
- **Bruteforce of a weak password**: OUT OF SCOPE. The library must enforce a minimum password policy. Without policy, the weakest factor dominates.
- **Denial of service**: OUT OF SCOPE. SelfDataGuard does not address availability, only confidentiality.

---

## 7. Mandatory deployment rules

For a SelfDataGuard deployment to actually deliver the listed guarantees, it must respect:

1. **Password policy**: minimum 12 characters, refusal of passwords present in breach lists (HaveIBeenPwned, top 10000 commons)
2. **Memorized-word policy**: minimum 2 words or one rare word (entropy ≥ 30 bits estimated by zxcvbn)
3. **Mandatory TLS**: no HTTP fallback allowed (strict HSTS)
4. **Short sessions**: `data_master_key` purged from session after inactivity (15 min recommended for Hybrid, 5 min for Full)
5. **No sensitive logging**: `password_key`, `recov_key`, `data_master_key` must never appear in logs (even at debug level)
6. **Admin access auditing**: in Hybrid mode, every admin access to operational fields must be logged (without the data itself)
7. **Regular updates**: track Argon2id recommendations to adjust `m`, `t`, `p` as hardware progresses

Failure to respect any of these rules significantly degrades the guarantees. The reference SelfDataGuard library automatically enforces rules 1, 2, 5, 6; rules 3, 4, 7 are deployment configuration.

---

## 8. Limitations and future work

### 8.1 Known limitations of v0.0.1

- **Full-text search** on encrypted fields: impossible without advanced techniques (partial homomorphic encryption, secure indexes like CipherSweet)
- **Asynchronous transactional notifications**: require admin_op_key (Hybrid mode) or redesign toward push (Full mode)
- **Schema migration**: if an encrypted field is added to an existing account, it must be populated during an active user session
- **Performance**: each encrypted field adds ~50-100 µs overhead on recent GPU. For queries listing many accounts, this cost compounds. To evaluate case by case.

### 8.2 Roadmap

- **v0.1.0** (Q3 2026): reference PHP implementation, ~600 auditable lines, Eloquent / Doctrine trait integration via adapter
- **v0.2.0** (Q4 2026): advanced blind index extension for searchable encryption, multi-tenant support
- **v0.3.0** (2027): formal community cryptographic audit, ANSSI Visa de sécurité submission (industries@ssi.gouv.fr), test vector pack publication

---

## 9. License and author

**AGPL-3.0-or-later**. Code, documentation, and whitepapers published in the `Pierroons/my-self` repository.

Any deployed version, modified or not, must publish its sources under the same license. No SaaS capture possible. Mechanism consistent with Nextcloud, Mastodon, ProtonMail, Ekylibre.

Author: Pierroons. Contact details accessible via the public repository.

Technical feedback, community audits, and cryptographic critiques are welcome, especially from researchers and practitioners who have already integrated wrapped-key vault architectures (Bitwarden, 1Password, ProtonMail, Cryptee).

---

*Document v0.0.1 — May 2026. This whitepaper is a draft specification open for comment ahead of the v0.1.0 reference implementation.*
