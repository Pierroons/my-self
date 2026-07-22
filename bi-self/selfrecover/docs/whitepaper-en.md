# SelfRecover — Whitepaper v1.1

**Zero-Email Account Recovery Protocol**
*One word. Every site. Forever.*

---

## Context (May 2026)

On April 15, 2026, the `moncompte.ants.gouv.fr` portal (Agence nationale des titres sécurisés — France's national agency for secure documents: ID cards, passports, driver's licenses, vehicle registrations) suffered a data breach via an IDOR (*Insecure Direct Object Reference*) vulnerability: changing an identifier in an API request granted access to another citizen's account. The Ministry of the Interior confirmed **11.7 million accounts** affected; attackers claim up to **19 million records** exfiltrated. Exposed data: civil status, contact details, identity certification status — no passwords, no biometrics.

The incident raised a structural question: why does a sovereign service need to index an email channel to reset an account? As long as a third-party mailbox is in the recovery chain, its compromise becomes the dominant attack vector. SelfRecover was published under AGPL-3.0-or-later **before this incident** (April 2026, v0.1.0) precisely to offer a technical answer: make the email channel optional (**Lite** mode, v0.1.1) or remove it entirely (**Full** mode).

This whitepaper describes the protocol. It is neither an ad-hoc critique of any single actor nor a post-incident claim — it is a prior open-source proposal that public and private operators may audit, integrate, or contest freely.

---

## Abstract

SelfRecover is a split-knowledge account recovery protocol that eliminates the dependency on email for password recovery. It relies on a HMAC-SHA256 derivation performed client-side using the current domain as key material, so the raw recovery word never leaves the browser, and a captured word is useless on any other domain (native anti-phishing). This document describes the protocol, its three-level escalation, the threat model, and mandatory deployment rules.

---

## 1. The Problem

Every web application faces the same question: *what happens when a user forgets their password?*

For the past twenty years, the industry's answer has been: **send an email**. This creates a chain of dependencies:

- The application must integrate an SMTP service (SendGrid, Mailgun, AWS SES, or self-hosted)
- The user must have a valid email address and share it with the service
- The email must actually arrive (spam filters, greylisting, deliverability issues)
- The reset link must be clicked within a time window (15-60 minutes)
- The security model is externalized to a third party (Gmail, Outlook, ProtonMail)

**The real question nobody asks:** why does a website need your email to prove you are you?

SelfRecover proposes a different answer: trust stays between the user and the site. No intermediary. No email. No third party.

---

## 2. The SelfRecover Model

**Core principle:**

> Recovery word alone = nothing.
> Algorithm alone = nothing.
> Recovery word + Algorithm = identity proven.

SelfRecover is a split-knowledge recovery system. The user remembers one word. The system provides the algorithm. Neither has value without the other.

**What the user remembers:**

- A public identifier (username, phone, gamer tag, customer ID — any label)
- A recovery word of their choice (any length, any complexity — even `bob`)

That's it. Two things. For every site. Forever.

---

## 3. How It Works

### 3.1 Registration

When a new account is created, the recovery word is immediately processed through HMAC-SHA256 derivation. The raw word never reaches the server.

```
derived_key = HMAC-SHA256(service_label ‖ user_salt, recovery_word)
```

The server receives and stores:

- `Argon2id(password)` — classic password hash
- `Argon2id(passphrase)` — a diceware passphrase generated server-side (4 words, ~51 bits of entropy)
- `Argon2id(derived_key)` — the HMAC-derived recovery key
- `user_salt` — the per-user salt (client-generated, not a secret)

The user receives the passphrase once and is asked to save it offline.

### 3.2 Authentication

Login uses the classic `username + password` → JWT token flow. Token is bound to a browser fingerprint so it invalidates when the session changes device.

### 3.3 Recovery

Three levels, each with its own guarantees and failure modes:

| Level | Input | Outcome on success |
|-------|-------|---------------------|
| **L1** | Username + diceware passphrase | New password |
| **L2** | Identifier + recovery word (HMAC-derived) | New password |
| **L3** | Public identifier + context signals | Raw facts for a human admin → self-re-enrollment on grant |

---

## 4. HMAC Derivation — One Word, Unique Everywhere

This is the core innovation of SelfRecover.

When the user types their recovery word, the browser computes a service-specific derived key **before anything leaves the client**:

```javascript
const SERVICE_LABEL = 'selfrecover.my-self.fr'; // stable, configured label (not the URL)
async function hmacDerive(word, userSalt) {
    const enc = new TextEncoder();
    const keyMaterial = enc.encode(SERVICE_LABEL + userSalt);
    const key = await crypto.subtle.importKey(
        'raw', keyMaterial,
        { name: 'HMAC', hash: 'SHA-256' },
        false, ['sign']
    );
    const sig = await crypto.subtle.sign('HMAC', key, enc.encode(word));
    return Array.from(new Uint8Array(sig))
        .map(b => b.toString(16).padStart(2, '0')).join('');
}
```

**Key properties:**

- The same input produces a different output on every service
- The raw word **never** leaves the browser
- The server never sees the word — only the derived key
- Output is always 256 bits regardless of input length
- Works on any device — same math, same result
- The service label is a stable configured constant (not the live URL); each account also has its own salt

**Passive-phishing resistance.** A passive clone that copies the page without adapting it derives a useless key, and per-account salts defeat cross-account correlation. The honest limit: an active phishing site controlling its own page can reproduce the key (out of scope, as for any in-browser protocol), and a reused raw word stays reusable — the derivation does not save a known, reused secret.

---

## 5. Three-Level Recovery Escalation

### 5.1 Level 1 — Forgotten Password

- User provides: `username` + `diceware passphrase` (exact match)
- On success: new password generated, masked by default, shown once
- Password stays on screen until the user confirms "I've saved it"
- Rate limit: 3 attempts / 15 minutes per username, 3 blocks → ejected to L2
- Anti-bot: honeypot field (hidden in CSS) + timing check (< 2 seconds = bot)

### 5.2 Level 2 — Lost Passphrase (identifier-less 2FA)

L2 is a **real 2FA** — possession **and** knowledge — **with no identifier to remember**:

- **Possession**: a *recovery code* (one of the 10 issued at registration). It **locates** the account via an HMAC lookup (no more enumeration) and acts as the possession factor.
- **Knowledge**: the *memorized word*, HMAC-derived client-side (the raw word never leaves the browser).

The server verifies **both** (Argon2id) and returns a **generic error** that never reveals which one failed. On success, the user picks their new password and the code is marked used. An optional variant — the **"this device" factor** — provides a third L2 path (see §5.4).

After 3 L2 failures (sliding window), automatic escalation to L3. All attempts are tracked.

### 5.3 Level 3 — All Access Lost

- Entry: discreet "Lost all access" link on the login page
- User provides their public identifier; their browser generates a **tracking code** (claim) whose fingerprint (SHA-256) alone is sent to the server (anti-timing: forced delay)
- A dispute with a **non-guessable** number (`LIT-<random>`) is opened. If a dispute is already open for that account, the number is **not re-disclosed** and the concurrent attempt is flagged to the admin ("multi-requester")
- The user answers a few **context questions** (account creation year, last-login period, usage frequency) — **no secret is requested**
- The server assembles a **bundle of signals** presented to the administrator:
  - **Passive signals** (not falsifiable by the user): IP already known to the account, browser fingerprint already seen
  - **Declarative signals** (what the user claims, compared against reality): creation year, last-login month, usage frequency
- **No numeric score is computed.** The signals are **raw facts**: they **never** unlock the account automatically, they only help a **human administrator** decide in the chat
- Cooldown: 1 hour between submissions
- The tracking code gates access to the chat thread and to the reset; it expires with the dispute (24h TTL) and is single-use

### 5.4 L2 possession factors — recovery codes & the "this device" factor

L2 always combines **knowledge** (the memorized word) and **possession**. Two possession factors are offered; the user holds at least one.

**Recovery codes — the universal paper factor.**
- A batch of **10 codes** is generated at registration and shown **only once** (format `xxxxx-xxxxx`, ~40 bits each).
- Stored twice, never in clear: `code_lookup = HMAC-SHA256(SERVER_SECRET, code)` (O(1) lookup with no identifier, *pepper* role) **and** `code_hash = Argon2id(code)` (verification + resistance to a database leak).
- **Single-use**, regenerable on demand (the new batch replaces the old one). Universal: paper, password manager, or a second device — the user's choice.

**"This device" factor — the cryptographic factor, optional.**
- An **ECDSA P-256** keypair is generated in the browser. The private key is **encrypted at rest** by an AES-256-GCM key derived from the **memorized word** via **Argon2id** (client-side WASM), and stored in **IndexedDB**.
- The server holds **only the public key**. Recovery means **signing a challenge** (32 bytes, 5-min TTL, single-use): the browser decrypts the private key with the word, signs, the server verifies.
- It is a cryptographic **device + knowledge** 2FA, with no TPM or hardware. **Software** protection (assumed), device-bound. **Automatically disabled on Tor/onion profiles** (WebCrypto/IndexedDB unreliable) — the paper recovery code remains the floor.

---

## 6. Dispute System & Admin Interface

Every failed recovery session above L1 opens a dispute (`LIT-XXXX`) visible in the admin dashboard.

- Each dispute has a **non-guessable** number, the bundle of signals (raw facts, never a score), attempt and refusal counters, a concurrent-attempt counter ("multi-requester"), and a status (`open`, `awaiting_admin`, `granted`, `resolved`, `refused`, `closed`)
- The admin finds open disputes in their dashboard
- A bidirectional chat channel is available between admin and user, with access gated by the tracking code (polling, not real-time WebSocket to keep it simple)
- Resolved disputes are auto-purged after 24 hours to keep the database clean

### 6.1 Dispute Closure — Admin Decision

When the admin reviews a dispute, two paths exist:

**Option 1 — Grant recovery (unblock):**

- Admin verifies identity via the chat exchange
- The dispute moves to `granted`. **The server neither generates nor transmits any password**: no secret travels through the chat
- The user **re-defines their own** secrets from their recovery page (re-enrollment model, consistent with the MySelf principle: the server never sees the password, even during recovery). The tracking code is then consumed (single-use) and the dispute moves to `resolved`

**Option 2 — Refuse recovery:**

- Admin doesn't believe the user is legitimate
- Temporary 24h ban applied — no new dispute can be opened during this window
- Refusal counter increments (1/3, 2/3, 3/3)
- **At the 3rd refusal: the account is permanently deleted.** The public identifier becomes available for fresh registration.

**Rationale:** a malicious actor cannot spam disputes indefinitely. Each refusal costs 24h of downtime, and three strikes erase the record completely. The legitimate owner, if blocked by mistake, can still retry after each ban window or re-register from scratch if totally locked out.

### 6.2 Super-user (SU) — governing the administrators

SelfRecover distinguishes three roles: **SU → Admin → User**. The administrator settles L3 disputes; the **super-user** governs the administrators themselves.

**Anchoring and secret.** The SU **does not exist in the database**: it is server-anchored (server access is authorization). Its secret lives **outside the database and outside the code** — a file outside the webroot, or an environment variable. This is a **Kerckhoffs model**: security rests on the secret, never on the obscurity of a code that is public. The SU is a **command-line interface**, never exposed on the web or remotely.

**Separation of powers.** An administrator **does not promote themselves**: they **propose** a promotion, the SU **decides** (mandatory note). The SU can promote/revoke administrators (a revocation cuts sessions), **audit** the state (cross-check DB rights against the log → detect **ghost admins** and put them in **automatic quarantine**), and, if its passphrase is lost, start from an "empty shell" (revoke all administrators, freeze the log).

**Tamper-evident audit log.** Every SU action is logged outside the database and outside the webroot, in four layers: **append-only** at the filesystem level (`chattr +a`), a **hash chain** (any tampering breaks the chain), a **per-entry HMAC** (key derived from the SU passphrase), and **externalization** to a notification channel (action + target + time only, never the forensic context).

---

## 7. Anti-Abuse Detection

- **Honeypot**: hidden CSS field — if filled, it's a bot
- **Timing**: form submitted in less than 2 seconds → bot
- **Suspicious fingerprints**: 5 attempts from the same browser fingerprint (any identifier) → flagged
- **Flagged + linked to a known user**: admin notified, user contacted
- **Flagged + unknown**: IP blocked 24h
- **Cross-account patterns**: detected at L2/L3 via fingerprint tracking

---

## 8. Diagnostic & Bug Reporting (Privacy-Safe)

Every failure generates a structured error code:

```
SR-L1-PASS-001   Level 1, passphrase mismatch, attempt 1
SR-L2-HMAC-003   Level 2, HMAC validation failed, attempt 3
SR-L3-SIGN-OK    Level 3, signal bundle forwarded to admin
SR-L3-FING-BLK   Level 3, fingerprint blocked
SR-SYS-SALT-ERR  System error, salt retrieval failed
```

**What IS included in diagnostic reports:**

- Error code, library version, browser/OS, level reached, attempt count
- Installation identifier (reveals no secret)

**What is NEVER included:**

- Recovery word (raw or derived), username, identifier, IP, fingerprint
- Passphrase, password, any personal data

---

## 9. Protection Against Active Attacks

If a legitimate user logs in normally and the server detects suspicious activity (failed L1 attempts, open disputes, suspicious fingerprints linked to their account), a modal is shown:

> **Security check**
> An unusual activity has been detected on your account.
> *Did you try to recover your account recently?*
> `[ Yes, it was me ]`  `[ No, it wasn't me ]`

- **Yes** → silent cleanup of failed attempts and disputes, user continues normally
- **No** → enhanced protection activated behind the scenes:
  - New password generated and shown to user
  - All existing JWT tokens invalidated
  - 7-day protection mode enabled (L2 recovery locked)
  - Suspicious fingerprints blocked 24h
  - Admin notified via push

The user sees a reassuring "Your account is now secured" message — not a technical log. The admin handles the investigation behind the scenes.

---

## 10. Threat Model & Limitations

### 10.1 Threats addressed

- **Passive phishing** — HMAC per service means a clone that doesn't adapt its code derives a different key (active phishing controlling its own page is out of scope)
- **Email account takeover** — there's no email involved, anywhere
- **SMTP provider failures** — no SMTP dependency
- **Third-party trust** — only the site and the user are involved
- **Rate-limited brute force** — per-username limits + L2/L3 escalation
- **Bot enumeration** — honeypot + timing + forced delays
- **Social reputation laundering** — public identifier locked after registration, cannot be changed by the user

### 10.2 CRITICAL — Server Root Access (sudo)

**This is the single most important limitation.**

SelfRecover protects recovery data through HMAC derivation, Argon2id hashing, and split knowledge. However, **none of these protections matter if an attacker gains root access to the server**.

**The vulnerability:**

- Some Linux environments grant passwordless sudo by default (`NOPASSWD: ALL` in sudoers). Notable cases: **Raspberry Pi OS** (user `pi`) and **cloud images** (AWS, DigitalOcean, GCP Ubuntu AMIs for the default `ubuntu` user, Amazon Linux for `ec2-user`, etc.). Most desktop/server installs (Debian, Ubuntu iso, Fedora, Arch) do **not** have this issue by default — but always verify your `/etc/sudoers.d/` on installation.
- If an attacker compromises the user account (SSH key leak, web vulnerability, etc.), they escalate to root with zero friction
- With root: direct database access, password hash replacement, code modification, key extraction — SelfRecover becomes decorative

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
echo "user:your-diceware-passphrase" | sudo chpasswd

# 2. Edit sudoers: replace "user ALL=(ALL) NOPASSWD: ALL" with "user ALL=(ALL) ALL"
sudo visudo -f /etc/sudoers.d/010_user-nopasswd

# 3. Verify: this command must fail with "a password is required"
sudo -k && sudo -n whoami
```

A SelfRecover deployment without hardened sudo is a lock on a door with no wall. **This rule is non-negotiable.**

### 10.3 The Recovery Word Is the Master Key

If the recovery word is compromised (social engineering, shoulder surfing, written down carelessly), an attacker who also knows the public identifier can recover the account. This is by design and cannot be mitigated without an external communication channel — which SelfRecover explicitly rejects.

No system can protect against a stolen secret. A leaked SSH private key gives server access. A leaked seed phrase empties a wallet. A leaked recovery word opens the account. The security model is identical.

SelfRecover assumes:

- The user treats the recovery word like a house key — not written on a sticky note, not shared in a chat
- The HMAC derivation limits damage to a single site (the word is useless on other domains)
- Rate limiting and L2→L3 escalation slow down brute-force attempts
- The server cannot compensate for human carelessness — no system can

**A protected secret stays safe; a neglected one is exposed.** This is not a flaw — it is the fundamental contract of any secret-based security system.

### 10.4 Other limitations (by design)

- If the user forgets both the recovery word and the passphrase and fails L3 scoring, the admin is the only fallback
- Users who change devices frequently lose fingerprint-based passive bonuses

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

- [ ] HTTPS mandatory — HMAC derivation uses the domain, HTTP would expose it to MITM
- [ ] Rate limiting on all recovery endpoints (nginx `limit_req` or application-level)
- [ ] Security headers: CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy
- [ ] PHP: `disable_functions`, `open_basedir`, `expose_php off`
- [ ] Init and migration scripts blocked in production (deny all or remove)

### 11.4 Monitoring

- [ ] Log all recovery attempts (level, success/fail, IP — never the recovery word)
- [ ] Alert on repeated L2/L3 failures for the same account
- [ ] Automated backup verification (test restore periodically)

A deployment that skips this checklist is not a SelfRecover deployment — it is a liability.

---

## 12. Integration Guide

### 12.1 Requirements

- PHP 8.0+ or Node.js 18+
- Any SQL database (MySQL, MariaDB, PostgreSQL, SQLite)
- Modern browser with JavaScript and Web Crypto API
- HTTPS mandatory in production

### 12.2 Planned distribution

```bash
composer require pierroons/selfrecover   # future PHP lib
npm install selfrecover                  # future JS lib
```

Not yet published. See the [demo](../demo/) for a working standalone implementation to study.

---

## 13. Comparison with Existing Solutions

| Feature | Email-based reset | WebAuthn / Passkey | **SelfRecover** |
|---------|:---:|:---:|:---:|
| No SMTP | ✗ | ✓ | ✓ |
| No third party | ✗ | ✗ (vendor lock-in) | ✓ |
| Works on any device | ✓ | ~ (device-bound) | ✓ |
| Recovery is offline-possible | ✗ | ✗ | ~ (user holds the secret) |
| Anti-phishing by design | ✗ | ✓ | ✓ |
| Per-site isolation | ✓ | ✓ | ✓ |
| Zero user cost | ✓ | ✓ | ✓ |
| Implementation complexity | high (SMTP) | high (FIDO2) | low |

SelfRecover is not a replacement for WebAuthn. It is a complement, especially for sites that don't want to ship device-bound authentication and don't want to rely on SMTP either.

---

## 14. Roadmap

- [x] Protocol specification (v1.1)
- [x] Reference implementation (this repo)
- [x] Whitepapers EN + FR
- [x] Standalone demo (L1 + L2)
- [ ] Security audit (community welcome)
- [ ] PHP library extraction (`composer require pierroons/selfrecover`)
- [ ] JS library extraction (`npm install selfrecover`)
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

*SelfRecover — because your identity shouldn't depend on an inbox.*
