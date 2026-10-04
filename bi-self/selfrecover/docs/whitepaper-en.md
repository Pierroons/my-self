# SelfRecover — Whitepaper v1.3

**Zero-Email Account Recovery Protocol**
*Your word. Your sites. No email.*

*Edition of 4 October 2026 — v1.3 — describes SelfRecover 0.10.0*

---

## Context

The costliest pattern is a familiar one, and it keeps recurring: a trivial authorization flaw — changing an identifier in an API request is enough to read someone else's account — on a service whose account recovery runs through an email channel. The scale then comes down to nothing more than the number of rows in the table.

The question is structural: why does a service need to index a mailbox to establish that you are you? As long as a third-party mailbox is in the recovery chain, its compromise becomes the dominant attack vector. SelfRecover offers a technical answer: remove the email channel (**Full** mode, the only one implemented). A **Lite** mode that kept it optional was shown as a demonstration in v0.1.1; that demonstration was removed on 18 August 2026. Published in April 2026 (v0.1.0), under AGPL-3.0-or-later since 19 April 2026.

This whitepaper describes the protocol. It targets no actor in particular — it is an open-source proposal that public and private operators may audit, integrate, or contest freely.

---

## Abstract

SelfRecover is a split-knowledge account recovery protocol that eliminates the dependency on email for password recovery. It relies on a HMAC-SHA256 derivation performed client-side, keyed by the recovery word itself, with the derivation material, the format version and the account salt carried in the message: the shipped deriver lets only the recovery word's fingerprint out of the browser, and the server stores only an Argon2id hash of it, specific to the service and the account, which prevents correlating stored fingerprints across services. The password and the passphrase are server-generated and do pass through the server. This document describes the protocol, its three-level escalation, the threat model, and mandatory deployment rules.

---

## 1. The Problem

Every web application faces the same question: *what happens when a user forgets their password?*

For the past twenty years, the industry's answer has been: **send an email**. This creates a chain of dependencies:

- The application must integrate an SMTP service (SendGrid, Mailgun, AWS SES, or self-hosted)
- The user must have a valid email address and share it with the service
- The email must actually arrive (spam filters, greylisting, deliverability issues)
- The reset link must be clicked within a time window (15-60 minutes)
- The security model is externalized to a third party (Gmail, Outlook, ProtonMail)

**Why does a website need an email address to establish who someone is?**

SelfRecover proposes a different answer: trust stays between the user and the site. No intermediary. No email. No third party.

---

## 2. The SelfRecover Model

**Core principle:**

> Recovery word alone = nothing.
> Algorithm alone = nothing.
> Recovery word + Algorithm = a fingerprint the server can verify — one of level 2's two factors.

SelfRecover is a split-knowledge recovery system. The user remembers one word. The system provides the algorithm. Neither has value without the other.

**What the user remembers:** a single word, written down nowhere.

**What the user keeps on paper:** their recovery codes (level 2) and their diceware passphrase (level 1). They are drawn at random — by the server, or with dice by the user for the passphrase (§5.5); nobody has to remember them.

The word alone reopens no account: level 2 also requires a recovery code or the enrolled device (§5.4).

---

## 3. How It Works

### 3.1 Registration

When a new account is created, the recovery word is immediately processed through HMAC-SHA256 derivation, in the browser. With the shipped deriver, the raw word does not reach the server.

```
derived_key = HMAC-SHA256(key = recovery_word, message = material + "|v2" + account_salt)
```

`material` depends on a mandatory derivation mode, described in §4. The output is 64 lowercase hex characters.

The server receives and stores:

- `Argon2id(password)` — the login password
- `Argon2id(passphrase)` — a diceware passphrase, generated server-side or brought by the user, rolled with dice (§5.5) (6 words or more, ≈ 77.5 bits for six uniformly drawn words; a shorter passphrase issued before the move to six words stays valid until used)
- `Argon2id(derived_key)` — the HMAC-derived recovery key
- `account_salt` — the account salt: 16 random bytes rendered as 32 lowercase hex characters, one per account, browser-generated, not a secret
- the first batch of 10 recovery codes, each stored in two forms (§5.4)

Every server-side Argon2id hash (`Hashing::hash`) uses one profile: 64 MiB, 4 iterations, 2 threads (`Hashing::ARGON2`). Codes also carry an HMAC lookup keyed by the deployment salt (§5.4), and the level-3 tracking code a SHA-256 fingerprint.

The user receives the passphrase and the codes once, and keeps them on paper, offline.

### 3.2 Authentication

Ordinary login belongs to the application: the library provides none. It only requires a way to revoke an account's sessions (`revoquerSessions()`), which every successful recovery does.

### 3.3 Recovery

Three levels, each with its own guarantees and failure modes:

| Level | Input | Outcome on success |
|-------|-------|---------------------|
| **L1** | Account name + diceware passphrase | New password and new passphrase |
| **L2** | Recovery code + recovery word (HMAC-derived) | New password and new passphrase |
| **L2, device path** | Enrolled device + recovery word | New password; passphrase and codes unchanged |
| **L3** | Account name + context answers | Raw facts for a human arbitrator; on grant, the user sets their password and memorized word, the server issues a fresh passphrase and 10 codes and removes enrolled devices |

---

## 4. HMAC Derivation — One Word, Unique Everywhere

This is the core innovation of SelfRecover.

When the user types their recovery word, the browser computes a service-specific derived key **before anything leaves the client**. The shipped component that performs this computation is `client/sr-derive.js`; the frozen vectors any reimplementation must reproduce live in `tests/vecteurs-derivation.json`.

```javascript
// Abridged form of client/sr-derive.js. The memorized word is the KEY, never the message.
const VERSION = 'v2';

function material(mode, label) {
    // Read in the browser, never received from the network.
    if (mode === 'hostname') return location.hostname.toLowerCase();
    if (mode === 'label' && label) return label;      // taken verbatim; empty, it throws
    throw new Error("mode is mandatory: 'hostname' or 'label' — there is no default");
}

async function srDerive(word, salt, { mode, label }) {
    if (!/^[0-9a-f]{32}$/.test(salt)) {
        throw new Error('salt is mandatory — 32 hex characters, one per account');
    }
    const enc = new TextEncoder();
    const message = material(mode, label) + '|' + VERSION + salt;
    const key = await crypto.subtle.importKey(
        'raw', enc.encode(word),
        { name: 'HMAC', hash: 'SHA-256' },
        false, ['sign']
    );
    const sig = await crypto.subtle.sign('HMAC', key, enc.encode(message));
    return Array.from(new Uint8Array(sig))
        .map(b => b.toString(16).padStart(2, '0')).join('');
}
```

**Key properties:**

- The same word yields a different fingerprint per account (the salt) and per material: each hostname in `'hostname'` mode, each label in `'label'` mode
- With the shipped deriver, the raw word does not leave the browser
- The server only receives the derived key. It is also the server that serves the deriver: a compromised server could serve a different page (§10.2)
- Output is always 256 bits regardless of input length
- Works on any device — same math, same result
- The version lives **inside the message**: during a migration a service can derive under two versions and accept both
- Each account has its own salt, browser-generated and not secret: it prevents two people who chose the same word from producing the same fingerprint

### 4.1 The derivation mode — mandatory, no default

`material` decides what the fingerprint is bound to, and the trade-off depends on how the service is served. The library does not settle it on the integrator's behalf, and above all not through a default: a default is chosen by everyone, so it is settling the question without saying so. A call without a mode throws.

| Mode | `material` is… | What it buys | What it costs |
|------|---------------|--------------|---------------|
| `'hostname'` | `location.hostname`, **read in the browser**, lowercased | Real anti-phishing: a page imitating the service gets a fingerprint derived from **its own** address, worthless against the real server | Losing the address means losing L2 recovery for every account. The price is not the same everywhere: on the ordinary web a domain expires, lapses, gets re-registered; on a v3 hidden service the address **is** the service's public key and depends on no registrar |
| `'label'` | a stable label supplied by the integrator, taken **verbatim** | Survives a change of address | **No anti-phishing at all.** A copy served elsewhere, with the same label, produces exactly the fingerprint the server stored |

The material must be **read** in the browser, never received from the network: material that a server supplies is material that any server can supply, including that of a page imitating the service.

**Passive-phishing resistance — in `'hostname'` mode only.** The clone copies the page, therefore copies the library, which then reads the clone's own address: the resulting key is worthless against the real server, and the clone had to do nothing wrong for that to happen. In `'label'` mode this resistance does not exist — the label travels with the copy. The honest limit, in both modes: an active phishing site controlling its own page harvests the raw word and derives whatever it wants afterwards (out of scope, as for any in-browser protocol), and a raw word reused elsewhere stays reusable — the derivation does not save a known, reused secret.

### 4.2 The salt route — it always answers

At level 2 the browser derives before the account is identified: the code is what identifies it. So it first needs the account salt, which it asks for by presenting the code, on a public, unauthenticated route. The route **always** returns a salt: the account's for a known code, consumed or not; otherwise a fake one, of the same shape, stable from one try to the next, keyed by the deployment salt and computed on both paths. A route that refused an unknown code would let anyone test codes for the price of a request, without paying a single Argon2id.

The library exposes no route; it ships the guard, `Recovery::selDeDerivation($code)`, and the optional `SelParCodeInterface`, which only the storage serving that route has to implement.

---

## 5. Three-Level Recovery Escalation

### 5.1 Level 1 — Forgotten Password

- User provides: the account name + the diceware passphrase (exact, up to whitespace; the name is lowercased)
- On success: a new password **and** a new passphrase, shown once; the old passphrase is void and open sessions are dropped. The memorized word and enrolled devices are unchanged. The age of the passphrase just used is returned: it informs, it never refuses
- Display (masked by default, "I've saved it" confirmation) belongs to the page
- Rate limit: 5 failures / 15 minutes per account and 12 per address (defaults, set by the integrator). The per-address brake only exists under the `clearweb` deployment profile; behind a hidden service, the `tor-onion` profile keeps only the per-account brake. The profile is mandatory, with no default
- Anti-bot: out of the library's reach — a honeypot field and a form-timing check live on the page

### 5.2 Level 2 — Lost Passphrase (identifier-less 2FA)

L2 is a **real 2FA** — possession **and** knowledge — **with no identifier to remember**:

- **Possession**: a *recovery code* (one of the 10 issued at registration). It **locates** the account via an HMAC lookup (no more enumeration) and acts as the possession factor.
- **Knowledge**: the *memorized word*, HMAC-derived client-side (with the shipped deriver, the raw word does not leave the browser).

The server verifies **both** (Argon2id) and returns a **generic error** that never reveals which one failed. On success, the code is consumed; the server generates a new password and a new passphrase, shown once, and open sessions are dropped. It also returns the account name and the number of codes left. An optional variant — the **"this device" factor** — provides a second L2 path (see §5.4).

There is no automatic escalation to L3. Level 2 asks for no identifier, but the code it receives names its account, which is what its per-account brake counts: a short window (5 failures per 15 minutes by default), then, after 20 failures since the last rearm, suspension of code recovery for that account. It is lifted by a fresh batch of codes — level 3 issues one —, by a successful code recovery, or by a successful passphrase recovery. That refusal names its state, otherwise the owner would not know what to do; so it tells whoever already holds one of the account's codes that the code names a real account. The per-address counter applies on top under the `clearweb` profile. Opening a dispute is the person's own decision.

### 5.3 Level 3 — All Access Lost

- Entry: discreet "Lost all access" link on the login page
- User provides their account name (the level-1 one); their browser generates a **tracking code** (claim). At opening only its fingerprint (SHA-256) is sent, and that is all the server stores; at the following steps the code itself is presented and the server hashes it each time — so a log of request bodies would capture it (anti-timing: forced delay)
- A dispute with a **non-guessable** number (`LIT-` followed by 16 hex characters) is opened. If a dispute is already open for that account, the number is **not re-disclosed** and the concurrent attempt is flagged to the admin ("multi-requester")
- The user answers a few **context questions** (account creation year, last-login period, usage frequency) — **no secret is requested**
- The server assembles a **bundle of raw facts** presented to the administrator:
  - **Context**: what the server already holds — account creation, last login, login count, codes left, recent refusals, and whatever the deployment's adapter adds, which the library does not interpret
  - **Declarative**: each answer checked against reality, marked `concorde` (match), `diverge` (mismatch) or `indisponible` (unavailable) — the last when the server keeps no such data, which is not a mismatch
  - **Warning**: it reminds the arbitrator that these answers are guessable; they guide the conversation, they prove nothing
  - The bundle carries **no passive signal**: no address, no browser fingerprint
- **No numeric score is computed.** These facts **never** unlock the account automatically, they only help a **human administrator** decide in the chat
- Cooldown: 1 hour between submissions
- Opening is braked before any account lookup: 10 per address and 20 service-wide per hour (defaults). There is deliberately no per-account brake: a third party could otherwise wall the owner out of level 3 with nothing shown to the arbitrator. Harassing an account shows up another way: the concurrent attempt is counted and displayed
- The tracking code is presented at every step — filing the answers, the chat thread, the status, the reset. The case expires after 24h while undecided. A grant runs 7 days from the decision; past that it lapses and arbitration must be redone. The reset closes the case and erases the tracking code's hash. If the holder lost their tracking code, an arbitrator can abandon the current case: that grants no access, it frees the slot for a new case

### 5.4 L2 possession factors — recovery codes & the "this device" factor

L2 always combines **knowledge** (the memorized word) and **possession**. Two possession factors are offered; the user holds at least one.

**Recovery codes — the universal paper factor.**
- A batch of **10 codes** is generated at registration and shown **only once** (format `xxxxx-xxxxx`: 10 lowercase hex characters, 40 bits each).
- Stored twice, never in clear: `code_lookup = HMAC-SHA256(key = deployment salt, code)` (lookup with no identifier) **and** `code_hash = Argon2id(code)` (verification + resistance to a database leak). The deployment salt is kept like a service secret, outside the webroot, and is never rotated without reissuing every sheet: changing it makes every issued code unfindable.
- **Single-use**, regenerable on demand (the new batch replaces the old one). They are kept on paper, offline.

**"This device" factor — the cryptographic factor, optional.**
- An **ECDSA P-256** keypair is generated in the browser. The private key is **encrypted at rest** by an AES-256-GCM key derived from the **memorized word** via **Argon2id** in JavaScript (t=3, m=64 MiB, p=1, under a per-blob salt), written in the library and checked against libsodium's vectors — no vendored binary. The resulting blob carries its version and derivation parameters; a blob declaring parameters below this floor is refused. Where it is stored is an integration choice: the reference implementation uses `localStorage`, IndexedDB being preferable and still to be done.
- The server holds **only the public key**. Recovery means **signing a challenge** (32 bytes, 5-min TTL, single-use): the browser decrypts the private key with the word, signs, the server verifies.
- On this path the server does not check the word, it checks a signature. The word is held only by the blob's encryption — a stolen blob can be attacked offline, with no attempt counter, at Argon2id's cost alone. On success a new password is returned; the passphrase and the codes do not change.
- It is a cryptographic **device + knowledge** 2FA, with no TPM or hardware. **Software** protection (assumed), device-bound. **Should be disabled on Tor/onion profiles**, where local storage does not survive the session — an integration choice, not an automatic behaviour. The paper recovery code remains the floor.
- ⚠️ **Enrolling a device does not add a factor: at that moment, the memorized word is enough.** Whoever knows it can enroll **their own** key, then authenticate with it — the path goes through neither a recovery code nor the passphrase. Enrollment therefore belongs to an **already-open session**, and it is up to the application to take the account name from that session rather than from the request body. The protocol requires the application to **assert** this explicitly (`Titulaire::AUTHENTIFIE`), and rate-limits the path per account and per address (5 and 12 failures per 15 minutes, defaults); it cannot verify the session itself — **this is an assertion, not a proof**. An already-enrolled device does remain two real factors: its encrypted blob and the word.
- A level-3 reset removes every enrolled device; levels 1 and 2 do not.

### 5.5 A passphrase the user brings — the user's choice

Wherever a passphrase is issued — at registration, and at the level 1, 2 and 3 renewals —, the user
may bring their own, rolled with dice, instead of receiving one drawn by the server. Nothing brought:
the server draws, as before.

- **The check** (`Recovery::validerPassphraseApportee()`): six words or more, each from the EFF English
  list or from Arthur Pons's French list, none repeated, a byte ceiling. The passphrase is stored in a
  single form: lowercase, one space between words. That form is the one hashed, returned and written
  down, because verification does not lowercase.
- **A refusal** gives a word's position, never the word. It is judged before any brake, with no trace
  and no delay: it depends only on the input, not on the account, and tracing it would let anyone
  charge someone else's brake. It consumes neither the level-2 code nor the level-3 case.
- **The old passphrase does not come back**: at every level, a brought passphrase equal to the one it
  replaces is refused. At level 3 it also cannot equal the chosen password.
- **What the check does not measure: randomness.** Six words picked by hand pass, and are worth less
  than six rolled words. The same passphrase seals the "passphrase" lock of the SelfDataGuard vault,
  which is attacked offline: a guessable passphrase becomes the cheapest way in.

At registration, which belongs to the application, it passes the input to the check and hashes the
returned form. At renewals, it passes it as `nouvellePassphrase` to `parPassphrase()`, `parCode()`
or `Escalade::reEnroler()`.

---

## 6. Dispute System & Admin Interface

A dispute (`LIT-` followed by 16 hex characters) opens when the person asks for one, never automatically. With the shipped adapter it appears in the arbitration console as soon as it is opened; its bundle, once the answers are filed (`awaiting_admin`).

- Each dispute has a **non-guessable** number, the bundle of facts (raw, never a score), attempt and refusal counters, a concurrent-attempt counter ("multi-requester"), and a status (`open`, `awaiting_admin`, `accepted`, `refused`, `closed`)
- The admin finds open disputes in their dashboard
- A bidirectional chat channel is available between admin and user, with access gated by the tracking code (polling, not real-time WebSocket to keep it simple)
- `purger()` erases expired disputes — neither the refused ones, on which the freeze is counted, nor the accepted ones, which a holder may still come back to consume. ⚠️ The library exposes the method; **it has no clock**. Calling it is the deployment's job, from a scheduled task.

### 6.1 Dispute Closure — Admin Decision

When the admin reviews a dispute, two paths exist:

**Option 1 — Grant recovery (unblock):**

- Admin verifies identity via the chat exchange
- The dispute moves to `accepted`. **The server neither generates nor transmits any password**: no secret travels through the chat
- The user **re-defines their own** password and memorized word from their recovery page (re-enrollment model). The password is 12 to 4,096 characters; it is submitted in the clear, and the server files it without issuing it. The browser generates a new salt and derives the memorized word, which never arrives in the clear. The server generates 10 codes, and a passphrase unless the holder brings their own (§5.5), shown once, drops open sessions and removes every enrolled device — the holder re-enrolls the one they use. The dispute moves to `closed` and the tracking code's hash is erased

**Option 2 — Refuse recovery:**

- The admin does not find the proof of identity sufficient
- The case moves to `refused`, carrying the date and the name of whoever decided
- **The account is not touched**: not deleted, not banned, not stripped of its codes. It stays usable
- On the **3rd refusal within a rolling 30-day window**, *opening* new cases freezes for 7 days on that account. An administrator can lift the freeze, and the record of who lifted it is kept

🔑 **What hardens is the procedure, never the account.** An earlier edition of this document announced a 24h ban and permanent deletion at the 3rd refusal; the implementation closest to it deleted the account on the **first**. Both were wrong for the same reason: a refusal says "this requester did not convince me", not "this account is illegitimate". If the requester was an impostor, deleting destroys the victim's account; if they were the mis-judged owner, it punishes an innocent. And an attacker unable to steal an account could get it erased by piling up refusals — **failure became a weapon**.

**Rationale:** the freeze costs whoever insists without convincing, and costs the owner nothing — they keep signing in normally throughout. Counting is on **refused cases**, not submissions: three submissions within one case remain one refusal, otherwise an honest owner's persistence would trip the freeze as fast as a hostile campaign.

### 6.2 Super-user (SU) — governing the administrators

The super-user is not part of the library: the lab implements it (`demo/lab/selfrecover-su`, a command-line console). What follows describes that reference model.

SelfRecover governs **a single right**: settling level-3 disputes. Two roles carry it — the **administrator** decides, the **super-user** governs the administrators themselves.

**The protocol does not verify this right.** The reference schema carries no rights column, and the arbitration component knows nothing of HTTP, sessions, or the caller's role. Being an administrator is therefore an **assertion made by the application**, taken as given — the same limit as for enrolling a device (§ 5.4).

**A deployment may define roles SelfRecover knows nothing about.** Room moderation, for instance, deleting messages and issuing temporary bans without ever coming near a recovery case: the protocol is unaware of it, and that separation is the application's routes to enforce.

**Anchoring and secret.** The SU **does not exist in the database**: it is server-anchored (server access is authorization). Its secret lives **outside the database and outside the code** — a file outside the webroot, or an environment variable. This is a **Kerckhoffs model**: security rests on the secret, never on the obscurity of a code that is public. The SU is a **command-line interface**, never exposed on the web or remotely.

**Separation of powers.** An administrator **does not promote themselves**: they **propose** promoting another account, and the SU **decides** (mandatory note). The SU appoints the **first** administrator, once; from then on the database **always keeps at least one** — the last one can only be revoked by naming its successor in the same transaction. The SU can revoke administrators (a revocation cuts sessions), **audit** the state (cross-check the application schema's rights column against the log → detect **ghost admins** and put them in **automatic quarantine**), and, if its passphrase is lost, start from an "empty shell" (revoke all administrators, freeze the log). After a compromise, a **database reset** deletes every account and the SU secret: a tampered account cannot be recognised from the inside, so none is kept. Both resets reopen the appointment of the first administrator.

**Tamper-evident audit log.** Every SU action is logged outside the database and outside the webroot, in four layers: **append-only** at the filesystem level (`chattr +a`), a **hash chain** (any tampering breaks the chain), a **per-entry HMAC** (an instance key, `SELFRECOVER_SU_AUDIT_SECRET` — separate from the SU passphrase: changing the passphrase does not break the chain; the key itself is rotated with `rotate-audit-key`, which re-signs the log without changing its hashes), and **externalization** to a notification channel (action + target + time only, never the forensic context).

---

## 7. Anti-Abuse Detection

**What the library enforces**

- **Counters**: per account and per address at levels 1 and 2 and at device enrollment, with level-2 suspension after 20 failures; per address and service-wide when opening a level-3 dispute. The per-address brake only exists under the `clearweb` profile
- **Forced delay** on every refusal that hides a state
- **Single refusal message**, so nothing sorts the accounts that exist — except two deliberate exceptions: level-2 suspension, which must be stated, and opening a level-3 dispute (§10.1)

**What the integrator owns**, because it needs routes, pages or a browser the
library does not have: a honeypot field, a form-timing check, a proof of work in
front of the dispute-opening route, browser-fingerprint correlation, and any
notification or blocking policy built on top.

---

## 8. What the Library Returns

Each method returns `ok`. Every refusal carries a human-facing `message`, most successes too (not `etat()`, `fil()` or `ouvrirDefi()`), and some refusals a stable `error` the application can log or translate: `invalid_derived_key`, `l2_suspendu`, `trop_de_demandes`, `compte_inconnu`, `gele`, `deja_ouvert`, `sesame_invalide`, `expire`, `accord_perime`, among others.

The library reports nothing itself; it only records the attempts its brakes need. What a diagnostic report contains is the application's call; it must not include the recovery word (raw or derived), the passphrase, the password or the codes.

---

## 9. Protection Against Active Attacks

What follows is an integration pattern that neither the library nor the demos provide: it needs sessions, roles and a notification channel the library does not have.

If a legitimate user logs in normally and the server detects suspicious activity (failed L1 attempts, open disputes), a modal is shown:

> **Security check**
> An unusual activity has been detected on your account.
> *Did you try to recover your account recently?*
> `[ Yes, it was me ]`  `[ No, it wasn't me ]`

- **Yes** → failed attempts are cleared, the user continues normally; refused disputes still count toward the freeze (§6.1)
- **No** → enhanced protection activated behind the scenes:
  - New password generated and shown to user
  - Sessions revoked: whoever held the account is ejected
  - Admin notified

The user sees a reassuring "Your account is now secured" message — not a technical log. The admin handles the investigation behind the scenes.

---

## 10. Threat Model & Limitations

### 10.1 Threats addressed

- **Passive phishing** — in `'hostname'` mode, a clone derives from its own address and gets a different key. In `'label'` mode, no protection. Active phishing controlling its own page is out of scope in both cases
- **Email account takeover** — there's no email involved, anywhere
- **SMTP provider failures** — no SMTP dependency
- **Third-party trust** — only the site and the user are involved
- **Braked brute force** — per account and per address at levels 1 and 2 and at enrollment, level-2 suspension after 20 failures, an Argon2id cost per server-side attempt
- **Bot enumeration** — *partly*. Closed at levels 1 and 2 and at device enrollment: a single generic refusal at the first, no identifier asked at the second, a counter keyed by the submitted name at the third. The salt route always returns a salt, real or fake (§4.2). One level-2 refusal does name a state: suspension, which tells whoever already holds a code that it names a real account. Open at level 3, where the useful answer IS the distinction — a success returns a dispute number, an unknown name cannot. What opposes it is cost: two brakes applied before the account lookup (per address, per service), a delay on every refusal that hides a state, and a proof of work in front of the route — which the library cannot impose, having no routes
- **Social reputation laundering** — the library offers no account rename; locking the name after registration is the application's to enforce

### 10.2 CRITICAL — Server Root Access (sudo)

**This is the single most important limitation.**

SelfRecover protects recovery data through HMAC derivation, Argon2id hashing, and split knowledge. However, **none of these protections matter if an attacker gains root access to the server**.

**The vulnerability:**

- Some Linux environments grant passwordless sudo by default (`NOPASSWD: ALL` in sudoers). Notable cases: **Raspberry Pi OS** (user `pi`) and **cloud images** (AWS, DigitalOcean, GCP Ubuntu AMIs for the default `ubuntu` user, Amazon Linux for `ec2-user`, etc.). Most desktop/server installs (Debian, Ubuntu iso, Fedora, Arch) do **not** have this issue by default — but `/etc/sudoers.d/` should always be checked on installation.
- If an attacker compromises the user account (SSH key leak, web vulnerability, etc.), they escalate to root with zero friction
- With root: direct database access, password hash replacement, code modification — including the deriver served to the browser, which could then capture the memorized word —, key extraction — SelfRecover becomes decorative

This is not a theoretical risk. It is the single point of failure that bypasses the entire protocol.

**MANDATORY DEPLOYMENT RULE:**

- Remove `NOPASSWD` from sudoers immediately after OS installation
- Set a strong diceware passphrase (minimum 6 words, 8 recommended) as the sudo user password
- `sudo` must require this passphrase for every privilege escalation
- The passphrase must be stored offline only (paper, not digital)
- SSH authentication must use key-based auth (no password login)

**Implementation (Debian / Ubuntu / Raspberry Pi OS):**

```bash
# 1. Change user password to a strong diceware passphrase
echo "user:<diceware-passphrase>" | sudo chpasswd

# 2. Edit sudoers: replace "user ALL=(ALL) NOPASSWD: ALL" with "user ALL=(ALL) ALL"
sudo visudo -f /etc/sudoers.d/010_user-nopasswd

# 3. Verify: this command must fail with "a password is required"
sudo -k && sudo -n whoami
```

A SelfRecover deployment without hardened sudo is a lock on a door with no wall. **This rule is non-negotiable.**

### 10.3 L2 Requires Two Factors — Neither Suffices Alone

L2 requires **two** factors: a recovery code (possession) **and** the memorized word (knowledge). A compromised memorized word alone (social engineering, shoulder surfing, written down carelessly) is not enough — a recovery code is still missing. The real risk is the **simultaneous** compromise of both factors (the word **and** a recovery code, or the word **and** the enrolled device). This is the standard 2FA model: it cannot be mitigated without an external communication channel — which SelfRecover explicitly rejects.

On the device path the server does not check the word: a stolen blob can be attacked offline, with no counter, at Argon2id's cost alone. This path needs a strong word.

No system can protect against the simultaneous theft of all its factors. A leaked SSH private key gives server access. A leaked seed phrase empties a wallet. A recovery code **and** the memorized word stolen together open the account. The security model is identical.

SelfRecover assumes:

- The user treats the recovery word like a house key — not written on a sticky note, not shared in a chat
- In `'hostname'` mode, the derivation limits damage to the single hostname involved (the fingerprint is useless elsewhere); in `'label'` mode it only limits damage to services that do not share the same label
- Per-account and per-address brakes, and level-2 suspension, slow online brute force down; offline, only the Argon2id cost does
- The server cannot compensate for human carelessness — no system can

**A protected secret stays safe; a neglected one is exposed.** This is not a flaw — it is the fundamental contract of any secret-based security system.

### 10.4 Other limitations (by design)

- If the user forgot their memorized word and lost their passphrase, only level 3 is left: a human arbitrator. If the arbitrator refuses there is no other recourse; a new dispute stays possible until the 7-day freeze, on the 3rd refusal in 30 days
- A passphrase the user brings is only as random as its dice, which the library cannot check (§5.5)

These are by design. A system with infinite fallbacks has infinite attack surface.

---

## 11. Deployment Security Checklist

SelfRecover cannot protect accounts if the server hosting it is insecure. The following checklist is **mandatory** before any production deployment.

### 11.1 Server access

- [ ] Remove `NOPASSWD` from sudoers — enforce a diceware passphrase (6+ words) for all privilege escalation
- [ ] SSH key-based authentication only — disable password login (`PasswordAuthentication no`)
- [ ] Firewall active (UFW / iptables) — only expose ports 80, 443, and SSH

### 11.2 Database

- [ ] Prepared statements (PDO / parameterized queries) for ALL SQL queries — no exceptions
- [ ] Database user with minimal privileges (`SELECT`, `INSERT`, `UPDATE`, `DELETE` only — no `GRANT`, no `DROP`)
- [ ] No phpMyAdmin or Adminer exposed to the internet
- [ ] Backups encrypted at rest (gpg or openssl) — a plaintext dump is a liability
- [ ] Backup storage isolated from web root — not accessible via HTTP

### 11.3 Application

- [ ] HTTPS mandatory on the ordinary web — in `'hostname'` mode the derivation reads the hostname, and without TLS nothing guarantees the page served actually comes from that service; on a v3 hidden service the address is the service's public key (§4.1)
- [ ] Rate limiting on all recovery endpoints (nginx `limit_req` or application-level)
- [ ] Security headers: CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy
- [ ] PHP: `disable_functions`, `open_basedir`, `expose_php off`
- [ ] Init and migration scripts blocked in production (deny all or remove)

### 11.4 Monitoring

- [ ] Log all recovery attempts (level, success/fail, IP — never the recovery word)
- [ ] Alert on repeated L2/L3 failures for the same account
- [ ] Automated backup verification (test restore periodically)

### 11.5 Integrating the library

- [ ] Declare the deployment profile, `clearweb` or `tor-onion`: it is mandatory, with no default
- [ ] Keep the deployment salt outside the webroot, and never rotate it without reissuing every code sheet
- [ ] Serve the salt route through `Recovery::selDeDerivation()`, and brake it
- [ ] Device enrollment: take the account name from the session, never from the request body, and pass `Titulaire::AUTHENTIFIE`
- [ ] Check the arbitrator role before `trancher()`, `degeler()` and `abandonner()`: the library does not check it
- [ ] Call `purger()` from a scheduled task: the library has no clock
- [ ] Put a proof of work in front of the level-3 opening route
- [ ] With the shipped adapter, build `StockagePdo` with the derivation host
- [ ] A passphrase the user brings: pass the input to `Recovery::validerPassphraseApportee()`, hash and show the returned form, set `autocapitalize="none"` on passphrase fields

A deployment that skips this checklist is not a SelfRecover deployment — it is a liability.

---

## 12. Integration Guide

### 12.1 Requirements

- PHP 8.1+ with `ext-json`, `ext-mbstring` and `ext-openssl` — the constraints `composer.json` carries —, and a PHP build that provides `PASSWORD_ARGON2ID`, which `composer.json` cannot require. The reference implementation is PHP; there is no other server-side one
- An SQL database. The shipped schema and adapter target SQLite (`ext-pdo_sqlite`); elsewhere, the column types and three adapter constructs (four queries) are rewritten — the header of `schema.sql` names them, or the integrator writes their own `StorageInterface` adapter
- Modern browser with JavaScript and Web Crypto API: `client/sr-derive.js` derives the memorized word there, through `crypto.subtle`. The "this device" factor adds `client/argon2id.js` then `client/sr-kdf.js`, Web Crypto offering no Argon2id
- HTTPS mandatory on the ordinary web (§11.3)

### 12.2 Planned distribution

```bash
composer require pierroons/selfrecover   # future PHP lib
npm install selfrecover                  # future JS lib
```

Not yet published: the library installs from a clone of the repository, through a Composer `path` repository. To see it at work: the served demo ([`demo/bi-self-duo/`](../../../demo/bi-self-duo/)), the lab ([MySelf-Lab](../../../demo/lab/)), and [the standalone tools](../tools/) for the pages that depend on no server.

---

## 13. Comparison with Existing Solutions

| Feature | Email-based reset | WebAuthn / Passkey | **SelfRecover** |
|---------|:---:|:---:|:---:|
| No SMTP | ✗ | ✓ | ✓ |
| No third party | ✗ | ✗ (vendor lock-in) | ✓ |
| Works on any device | ✓ | ~ (device-bound) | ✓ |
| Recovery is offline-possible | ✗ | ✗ | ~ (user holds the secret) |
| Anti-phishing by design | ✗ | ✓ | ~ (passive only, and in `'hostname'` mode only — §4.1) |
| Per-site isolation | ✓ | ✓ | ✓ |
| Zero user cost | ✓ | ✓ | ✓ |
| Implementation complexity | high (SMTP) | high (FIDO2) | low |

SelfRecover is not a replacement for WebAuthn. It is a complement, especially for sites that don't want to ship device-bound authentication and don't want to rely on SMTP either.

---

## 14. Roadmap

- [x] Protocol specification (v1.3)
- [x] Reference implementation (this repo)
- [x] Whitepapers EN + FR
- [x] Served demo (`demo/bi-self-duo/`) and lab (`demo/lab/`) — the standalone demo was removed on 18 August 2026
- [x] PHP library extracted — PSR-4 with its own `composer.json`, consumed through a `path` repository
- [x] All three levels in the library, level 3 included (`Escalade`)
- [x] "This device" factor, and its local Argon2id encryption (`client/sr-kdf.js`)
- [x] Shipped storage implementation (`schema.sql` + `StockagePdo`)
- [x] Mandatory deployment profile, per-account brakes, level-2 suspension
- [x] Devices removed on a level-3 reset, grant expiry
- [x] Salt route guard (`Recovery::selDeDerivation`)
- [x] A passphrase the user brings, rolled with dice (`Recovery::validerPassphraseApportee`)
- [ ] External security audit (community welcome)
- [ ] Published on Packagist (`composer require pierroons/selfrecover`)
- [ ] JS package (`npm install selfrecover`) — the deriver ships as `client/sr-derive.js`, it is not packaged
- [ ] WordPress plugin
- [ ] Laravel package
- [ ] Ports to Python, Go, Rust, Node

---

## 15. Contributing

SelfRecover is open source under the AGPL-3.0-or-later license (switched from MIT on 2026-04-19).

- Security audits and penetration testing welcome
- Implementation feedback from production deployments
- Ports to other languages and frameworks

**GitHub:** https://github.com/Pierroons/my-self/tree/main/bi-self/selfrecover

---

*SelfRecover — because an identity shouldn't depend on an inbox.*
