# SelfRecover — Whitepaper v1.1

**Zero-Email Account Recovery Protocol**
*One word. Every site. Forever.*

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
derived_key = HMAC-SHA256(recovery_word, domain + site_salt)
```

The server receives and stores:

- `bcrypt(password)` — classic password hash
- `bcrypt(passphrase)` — a diceware passphrase generated server-side (4 words, ~51 bits of entropy)
- `bcrypt(derived_key)` — the HMAC-derived recovery key

The user receives the passphrase once and is asked to save it offline.

### 3.2 Authentication

Login uses the classic `username + password` → JWT token flow. Token is bound to a browser fingerprint so it invalidates when the session changes device.

### 3.3 Recovery

Three levels, each with its own guarantees and failure modes:

| Level | Input | Outcome on success |
|-------|-------|---------------------|
| **L1** | Username + diceware passphrase | New password |
| **L2** | Identifier + recovery word (HMAC-derived) | New password |
| **L3** | Multi-factor scoring form | New password or admin dispute chat |

---

## 4. HMAC Derivation — One Word, Unique Everywhere

This is the core innovation of SelfRecover.

When the user types their recovery word, the browser computes a site-specific derived key **before anything leaves the client**:

```javascript
async function hmacDerive(word, salt) {
    const enc = new TextEncoder();
    const domain = window.location.hostname;
    const keyMaterial = enc.encode(domain + salt);
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

- The same input (`"bob"`) produces a different output on every site
- The raw word **never** leaves the browser
- The server never sees `"bob"` — only the derived key
- Output is always 256 bits regardless of input length
- A 3-letter word is as secure as a 30-character one within the system
- Works on any device — same math, same result
- Domain is obtained automatically via `window.location.hostname`

**Anti-phishing is native.** A fake site (e.g. `tartenpion-fake.fr` instead of the real `tartenpion.fr`) derives a completely different key from the same word. A captured recovery word is useless on any other domain.

---

## 5. Three-Level Recovery Escalation

### 5.1 Level 1 — Forgotten Password

- User provides: `username` + `diceware passphrase` (exact match)
- On success: new password generated, masked by default, shown once
- Password stays on screen until the user confirms "I've saved it"
- Rate limit: 3 attempts / 15 minutes per username, 3 blocks → ejected to L2
- Anti-bot: honeypot field (hidden in CSS) + timing check (< 2 seconds = bot)

### 5.2 Level 2 — Lost Passphrase

- User provides: `public identifier` + `recovery word` (HMAC-derived client-side)
- 3 attempts with visible countdown (1/3, 2/3, 3/3)
- On success: new password generated
- On 3 failures: redirected to L3
- A dispute is auto-created (`LIT-0001`), admin notified, all attempts tracked
- Auto-resolved disputes are purged from the database after 24 hours

### 5.3 Level 3 — All Access Lost

- Entry: discreet "Lost all access" link on the login page
- User provides the public identifier first (anti-timing: forced 2-3 second delay)
- Fingerprint is captured and checked against the suspicious fingerprints list
- A single form, single submit, multiple fields per category:
  - Public identifier (4 fields, 20 points)
  - Recovery word (5 fields, 25 points) — HMAC-derived client-side
  - Username (3 fields, 30 points)
  - Passphrase (3 fields, 25 points)
- Passive bonuses: known IP (+5), known fingerprint (+5)
- **Score ≥ 60/100** → account recovered
- **Score < 60/100 after 3 attempts** → admin chat activates
- Cooldown: 1 hour between attempts

---

## 6. Dispute System & Admin Interface

Every failed recovery session above L1 opens a dispute (`LIT-XXXX`) visible in the admin dashboard.

- Each dispute has a unique number, a current level, attempt counters, best L3 score, and a status (`open`, `resolved`, `closed`, `attack_confirmed`)
- The admin receives a push notification at dispute creation
- A bidirectional chat channel is available between admin and the restricted user (polling, not real-time WebSocket to keep it simple)
- Resolved disputes are auto-purged after 24 hours to keep the database clean

### 6.1 Dispute Closure — Admin Decision

When the admin reviews a dispute, two paths exist:

**Option 1 — Grant recovery (unblock):**

- Admin verifies identity via the chat exchange
- Password is reset, all counters cleared, dispute marked resolved
- User receives the new password via push notification

**Option 2 — Refuse recovery:**

- Admin doesn't believe the user is legitimate
- Temporary 24h ban applied — no new dispute can be opened during this window
- Refusal counter increments (1/3, 2/3, 3/3)
- **At the 3rd refusal: the account is permanently deleted.** The public identifier becomes available for fresh registration.

**Rationale:** a malicious actor cannot spam disputes indefinitely. Each refusal costs 24h of downtime, and three strikes erase the record completely. The legitimate owner, if blocked by mistake, can still retry after each ban window or re-register from scratch if totally locked out.

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
SR-L3-SCORE-042  Level 3, scoring complete, score 42/100
SR-L3-FING-BLK   Level 3, fingerprint blocked
SR-SYS-SALT-ERR  System error, salt retrieval failed
```

**What IS included in diagnostic reports:**

- Error code, library version, browser/OS, level reached, attempt count, score
- Hash of site salt (not the salt — identifies the installation)

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

- **Phishing attacks** — HMAC per domain means a fake site derives a different key
- **Email account takeover** — there's no email involved, anywhere
- **SMTP provider failures** — no SMTP dependency
- **Third-party trust** — only the site and the user are involved
- **Rate-limited brute force** — per-username limits + L2/L3 escalation
- **Bot enumeration** — honeypot + timing + forced delays
- **Social reputation laundering** — public identifier locked after registration, cannot be changed by the user

### 10.2 CRITICAL — Server Root Access (sudo)

**This is the single most important limitation.**

SelfRecover protects recovery data through HMAC derivation, bcrypt hashing, and split knowledge. However, **none of these protections matter if an attacker gains root access to the server**.

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

**Be careful: no problem. Be careless: open bar.** This is not a flaw — it is the fundamental contract of any secret-based security system.

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
- [x] Reference implementation (ARC PVE Hub, production)
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

SelfRecover is open source under the MIT license.

- Security audits and penetration testing welcome
- Implementation feedback from production deployments
- Ports to other languages and frameworks

**GitHub:** https://github.com/Pierroons/selfrecover

---

*SelfRecover — because your identity shouldn't depend on an inbox.*
