# SelfRecover

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Zero-email account recovery protocol** — split knowledge, HMAC per domain, no SMTP, no third party.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Status: v0.1.0](https://img.shields.io/badge/status-v0.1.0-green.svg)](#status)
[![Production tested](https://img.shields.io/badge/production%20tested-ARC%20PVE%20Hub-green.svg)](https://arc.rpi4server.ovh)
[![Part of: Bi-Self](https://img.shields.io/badge/part%20of-Bi--Self-blue.svg)](../README.md)
[![Self-hosted](https://img.shields.io/badge/self--hosted-Raspberry%20Pi-blue.svg)](#quickstart)
[![Zero dependencies](https://img.shields.io/badge/dependencies-zero-brightgreen.svg)](#quickstart)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

> **One word. Every site. No email required.**

---

## The problem

Every web application faces the same question: *what happens when a user forgets their password?*

For twenty years, the industry's answer has been: **send an email**. But this creates a chain of dependencies — SMTP providers, deliverability issues, spam folders, third-party mailboxes, expiring tokens — and it externalizes the security model to a service you don't control.

**Why does a website need your email to prove you are you?**

---

## The solution

SelfRecover is a **split-knowledge** recovery protocol:

- **Recovery word alone** = nothing.
- **Algorithm alone** = nothing.
- **Recovery word + algorithm** = identity proven.

The user remembers **one word of their choice** (any word, any length — even `bob`). That's it.

When they type it, the browser performs a **HMAC-SHA256 derivation** using the current domain as a key, producing a site-specific cryptographic key before anything leaves the client. The server never sees the raw word, and a phishing site would derive a completely different key.

```
derived_key = HMAC-SHA256(recovery_word, domain + site_salt)
```

**Anti-phishing is native.** **No SMTP.** **No third party.** **Same UX on every site.**

---

## Cryptographic specification

### Primitives

| Role | Algorithm | Parameters |
|------|-----------|------------|
| Client-side key derivation | HMAC-SHA256 | key = recovery_word, message = domain &#124;&#124; site_salt |
| Server-side secret storage | bcrypt | cost = 12 (≈ 250 ms on modern server) |
| Public identifier hashing | SHA-256 | truncated to 16 bytes, then hex-encoded |
| Passphrase generation (L1) | EFF Diceware | 4 words, ≥ 51 bits of entropy |
| Site salt | 32 random bytes | generated at install, never rotated |

### Storage model

For each account, the server stores exactly three secrets:

```sql
CREATE TABLE account (
  id           INTEGER PRIMARY KEY,
  identifier   TEXT UNIQUE,              -- public, user-chosen
  password     TEXT,                     -- bcrypt(password)
  pass_hash    TEXT,                     -- bcrypt(diceware_passphrase)  [L1]
  recovery     TEXT,                     -- bcrypt(derived_key)          [L2]
  created_at   INTEGER
);
```

The server never sees: the raw password, the raw passphrase, the raw recovery word. Every comparison is a bcrypt verification against the client-submitted derived value.

### Key-stretching chain (Level 2 recovery)

```
user input   → recovery_word
client       → derived_key  = HMAC-SHA256(recovery_word, domain ‖ site_salt)
wire         → POST /recover { identifier, derived_key }
server       → verify        = bcrypt_verify(derived_key, stored_recovery_hash)
```

The wire never carries the recovery word. The server never stores the recovery word. Even a full database dump + source code leak does not expose it — only bcrypt hashes of per-site-derived keys.

### Why HMAC-SHA256 (and not PBKDF2 / Argon2)

HMAC is intentionally **fast** client-side because the goal is domain binding, not brute-force resistance. The brute-force resistance is provided server-side by **bcrypt** on the derived key. Splitting the roles keeps the UX instant on mobile while still imposing ≥ 250 ms per server-side verification attempt.

---

## Three-level recovery escalation

| Level | Secret required | Outcome |
|-------|----------------|---------|
| **L1** | Passphrase (diceware, 4 words) | New password |
| **L2** | Public identifier + recovery word | New password |
| **L3** | Multi-factor scoring form | New password or admin chat |

Rate limits, dispute system, and anti-abuse detection at every level.

---

## Quickstart — run the demo in 30 seconds

**Requirements:** PHP 8.0+ with `pdo_sqlite` (on Debian/Ubuntu: `sudo apt install php-cli php-sqlite3`).

```bash
git clone https://github.com/Pierroons/selfrecover.git
cd selfrecover/demo
./run.sh
# → http://localhost:8080
```

The demo is a standalone single-page web app that lets you:
1. **Register** an account (passphrase diceware generated automatically)
2. **Log in** with your username + password
3. **Recover L1** — forgot your password → enter your passphrase → new password
4. **Recover L2** — forgot passphrase too → enter your identifier + recovery word → new password

No dependencies beyond PHP CLI. SQLite as database. Zero configuration.

> **⚠ Note:** The demo only covers **Level 1 + Level 2** of the protocol. **Level 3** (multi-factor scoring recovery with admin dispute chat) is **not** included in the demo because it requires an admin interface, a dispute system, and a dashboard — too much for a standalone single-page demo. See the **[full reference implementation running in production on ARC PVE Hub](https://arc.rpi4server.ovh)** for L3 in action, and read the **[whitepaper](docs/whitepaper-en.md#5-three-level-recovery-escalation)** for the full L3 specification.

---

## Architecture

```
┌──────────────┐           ┌──────────────┐
│   Browser    │           │    Server    │
└──────┬───────┘           └──────┬───────┘
       │                          │
       │   GET /salt              │
       │─────────────────────────>│
       │<─────────────────────────│
       │   salt                   │
       │                          │
       │  [derive HMAC locally]   │
       │                          │
       │   POST /recover          │
       │   { derived_key }        │
       │─────────────────────────>│
       │                          │
       │          [bcrypt verify] │
       │                          │
       │<─────────────────────────│
       │   new password           │
       │                          │
```

The raw recovery word never leaves the browser.

---

## Security properties

| Property | How it's achieved |
|----------|------------------|
| **Zero-knowledge server** | The server only ever sees bcrypt hashes of per-site-derived values. Compromise of the database reveals no recovery words. |
| **Native anti-phishing** | A phishing site at `not-the-real-domain.tld` derives a different HMAC key, which fails to match any stored bcrypt record. No user training required. |
| **Replay resistance** | Each recovery request is gated by a server-side rate limit + dispute system. L3 adds a multi-factor scoring check. |
| **Forward secrecy against leak** | Site salt is per-deployment, never reused, never transmitted outside the server. Leaked client code alone is useless. |
| **No central dependency** | Each deployment is autonomous. No SPOF, no vendor lock-in, no operator who can revoke accounts across the ecosystem. |
| **Human-memorable secret** | One word of the user's choice. Not a 24-word seed, not a passphrase you write on paper, not a QR code. |

---

## Threat model at a glance

**Protected against:**
- Database compromise (bcrypt-only storage, no reversible secrets)
- Phishing (domain-bound derivation)
- SMTP attacks, SIM swapping, email account takeover (no email in the loop)
- Account brute force (bcrypt cost + rate limits + L3 scoring)

**Not claimed to protect against:**
- Malicious client code (if the attacker controls the page your browser loads, game over — true for any in-browser protocol)
- Weak recovery words (`password`, `123`, `bob`) — the **L3 scoring** mitigates by requiring multi-factor verification if L2 fails
- Physical coercion of the user (see SelfGuard in this ecosystem for duress-aware storage)
- Targeted malware with keylogging

Full analysis: **[docs/threat-model.md](docs/threat-model.md)**

---

## Documentation

- **[Whitepaper (EN)](docs/whitepaper-en.md)** — full technical specification, threat model, deployment checklist
- **[Whitepaper (FR)](docs/whitepaper-fr.md)** — version française
- **[Architecture](docs/architecture.md)** — detailed flow diagrams
- **[Threat model](docs/threat-model.md)** — what SelfRecover protects against, and what it doesn't

---

## Status

**Concept stage — tested in production on [ARC PVE Hub](https://arc.rpi4server.ovh)**

This repository contains:
- The **protocol specification** (whitepapers v1.1)
- A **standalone working demo** to try the concept locally
- A **reference implementation** lifted from the production code of ARC PVE Hub

**What this repo is NOT (yet):**
- An installable PHP/JS library (planned, once the protocol is battle-tested)
- A finished product with security audits (feedback and audits are welcome)

The protocol is currently running in production on ARC PVE Hub with real users. Feedback from real-world deployments will shape the future lib.

---

## Roadmap

- [x] Protocol specification
- [x] Reference implementation (ARC PVE Hub)
- [x] Whitepapers EN + FR
- [x] Standalone demo (this repo)
- [ ] Security audit (community welcome)
- [ ] PHP library extraction (`composer require pierroons/selfrecover`)
- [ ] JS library extraction (`npm install selfrecover`)
- [ ] WordPress plugin
- [ ] Laravel package
- [ ] Ports to Python, Go, Rust, Node

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Feedback, audits, implementation experience, and ports welcome.

Security disclosures: see [SECURITY.md](SECURITY.md).

---

## License

[MIT](LICENSE) — do whatever you want, but don't blame me if your cat wakes you up at 4am.

---

## Author

**Pierroons** — a farmer who codes in his spare time.

*SelfRecover — because your identity shouldn't depend on an inbox.*
