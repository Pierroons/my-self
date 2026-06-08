# SelfRecover

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Zero-email account recovery protocol** — split knowledge, HMAC per domain, no SMTP, no third party.

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.1.1](https://img.shields.io/badge/status-v0.1.1-green.svg)](#status)
[![Production tested](https://img.shields.io/badge/production%20tested-ARC%20PVE%20Hub-green.svg)](https://arc.example.com)
[![Part of: Bi-Self](https://img.shields.io/badge/part%20of-Bi--Self-blue.svg)](../README.md)
[![Self-hosted](https://img.shields.io/badge/self--hosted-Raspberry%20Pi-blue.svg)](#quickstart)
[![Zero dependencies](https://img.shields.io/badge/dependencies-zero-brightgreen.svg)](#quickstart)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

> **One word. Every site. No email required.**

---

## Companion module — SelfDataGuard (concept)

For e-commerce or SaaS deployments that also need to **protect stored personal data** against database exfiltration, see the [SelfDataGuard](../../self-security/selfdataguard/) companion module. SelfDataGuard reuses the SelfRecover memorized recovery word as one of its key-wrapping factors (with a strict context separator: `/recover` for auth, `/dataguard` for data), so a user who forgets their password can simultaneously regain account access AND decrypt their stored data with the same memorized word.

SelfRecover protects **authentication**. SelfDataGuard protects **data at rest**. Together they close the loop against breaches like the April 2026 ANTS leak (where both auth tokens AND personal data were exposed in plain text).

---

## Two adoption modes — Full and Lite (v0.1.1)

SelfRecover ships in two flavors so legacy systems can adopt it progressively
without an all-or-nothing rewrite of their authentication stack.

| Mode | Email channel | Crypto added | When to pick it |
|------|---------------|--------------|-----------------|
| **[Full](./demo/index.html)** | None at all | Diceware EFF passphrase + HMAC-per-domain | Greenfield projects, paranoid-by-design, post-ANTS-leak threat models |
| **[Lite](./demo/lite.html)** 🆕 | Kept (SMTP reset link) | A user-memorized word HMAC-derived client-side, never sent raw | Existing email-based stacks that want phishing-resistance now and migrate to Full later |

**Live demos:** [Full](https://bi-self.my-self.fr/selfrecover/) · [Lite](https://bi-self.my-self.fr/selfrecover/lite.html) · [Side-by-side comparison (8 adversaries × 3 models)](https://bi-self.my-self.fr/selfrecover/comparison.html)

---

> **The locksmith metaphor.**
> When you change the lock on your front door, you don't hand your home address
> to the locksmith. So why do you accept handing your email address to every
> website just to reset a password? SelfRecover keeps the whole operation
> inside your head.

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
| Server-side secret storage | Argon2id | memory = 64 MiB, time = 4, threads = 2 (memory-hard) |
| Public identifier hashing | SHA-256 | truncated to 16 bytes, then hex-encoded |
| Passphrase generation (L1) | EFF Diceware | 4 words, ≥ 51 bits of entropy |
| Site salt | 32 random bytes | generated at install, never rotated |

### Storage model

For each account, the server stores exactly three secrets:

```sql
CREATE TABLE account (
  id           INTEGER PRIMARY KEY,
  identifier   TEXT UNIQUE,              -- public, user-chosen
  password     TEXT,                     -- Argon2id(password)
  pass_hash    TEXT,                     -- Argon2id(diceware_passphrase)  [L1]
  recovery     TEXT,                     -- Argon2id(derived_key)          [L2]
  created_at   INTEGER
);
```

The server never sees: the raw password, the raw passphrase, the raw recovery word. Every comparison is an Argon2id verification against the client-submitted derived value.

### Key-stretching chain (Level 2 recovery)

```
user input   → recovery_word
client       → derived_key  = HMAC-SHA256(recovery_word, domain ‖ site_salt)
wire         → POST /recover { identifier, derived_key }
server       → verify        = password_verify(derived_key, stored_recovery_hash)  // Argon2id
```

The wire never carries the recovery word. The server never stores the recovery word. Even a full database dump + source code leak does not expose it — only Argon2id hashes of per-site-derived keys.

### Why HMAC-SHA256 (and not PBKDF2 / Argon2)

HMAC is intentionally **fast** client-side because the goal is domain binding, not brute-force resistance. The brute-force resistance is provided server-side by **Argon2id** (memory-hard, 64 MiB per attempt) on the derived key. Splitting the roles keeps the UX instant on mobile while still imposing a memory-hard cost per server-side verification attempt.

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

### Option A — Docker (zero install except docker)

```bash
git clone https://github.com/Pierroons/my-self.git
cd my-self/bi-self/selfrecover/demo
docker build -t selfrecover .
docker run -p 8080:8080 selfrecover
# → http://localhost:8080
```

Image based on `php:8.2-cli-alpine`, ~50 MB, AGPL labels embedded (`org.opencontainers.image.licenses=AGPL-3.0-or-later`). Set `-e SELFRECOVER_FRESH_DB=1` to wipe the SQLite at each restart.

### Option B — Native PHP CLI

**Requirements:** PHP 8.0+ with `pdo_sqlite` (on Debian/Ubuntu: `sudo apt install php-cli php-sqlite3`).

```bash
git clone https://github.com/Pierroons/my-self.git
cd my-self/bi-self/selfrecover/demo
./run.sh
# → http://localhost:8080
```

The demo is a standalone single-page web app that lets you:
1. **Register** an account (passphrase diceware generated automatically)
2. **Log in** with your username + password
3. **Recover L1** — forgot your password → enter your passphrase → new password
4. **Recover L2** — forgot passphrase too → enter your identifier + recovery word → new password

No dependencies beyond PHP CLI. SQLite as database. Zero configuration.

> **⚠ Note:** The demo only covers **Level 1 + Level 2** of the protocol. **Level 3** (multi-factor scoring recovery with admin dispute chat) is **not** included in the demo because it requires an admin interface, a dispute system, and a dashboard — too much for a standalone single-page demo. See the **full reference implementation running in production on a community platform** for L3 in action, and read the **[whitepaper](docs/whitepaper-en.md#5-three-level-recovery-escalation)** for the full L3 specification.

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
       │        [Argon2id verify] │
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
| **Zero-knowledge server** | The server only ever sees Argon2id hashes of per-site-derived values. Compromise of the database reveals no recovery words. |
| **Native anti-phishing** | A phishing site at `not-the-real-domain.tld` derives a different HMAC key, which fails to match any stored Argon2id record. No user training required. |
| **Replay resistance** | Each recovery request is gated by a server-side rate limit + dispute system. L3 adds a multi-factor scoring check. |
| **Forward secrecy against leak** | Site salt is per-deployment, never reused, never transmitted outside the server. Leaked client code alone is useless. |
| **No central dependency** | Each deployment is autonomous. No SPOF, no vendor lock-in, no operator who can revoke accounts across the ecosystem. |
| **Human-memorable secret** | One word of the user's choice. Not a 24-word seed, not a passphrase you write on paper, not a QR code. |

---

## Threat model at a glance

**Protected against:**
- Database compromise (Argon2id-only storage, no reversible secrets)
- Phishing (domain-bound derivation)
- SMTP attacks, SIM swapping, email account takeover (no email in the loop)
- Account brute force (Argon2id memory-hard cost + rate limits + L3 scoring)

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

## Beyond the web: disk unlock

The same recovery word, derived per **label** with Argon2id, can also serve as a fallback key for a **LUKS2** encrypted volume — letting a host unlock its disk without email or third party when its primary mechanism (a distributed witness quorum) is unavailable. The label separates the web key from the disk key, so neither can derive the other.

This is a companion module, **[`selfrecover-luks`](../../self-security/selfrecover-luks/)**, **validated on the bench** (PoC + tests on a throwaway image). Integration on a real production disk is upcoming, not yet claimed as operational.

---

## Status

**Concept stage — tested in production on a community platform**

This repository contains:
- The **protocol specification** (whitepapers v1.1)
- A **standalone working demo** to try the concept locally
- A **reference implementation** lifted from the production code of a community platform

**What this repo is NOT (yet):**
- An installable PHP/JS library (planned, once the protocol is battle-tested)
- A finished product with security audits (feedback and audits are welcome)

The protocol is currently running in production on a community platform with real users. Feedback from real-world deployments will shape the future lib.

---

## Threat model

SelfRecover is honest about what it protects and what it does not. Every cryptographic protocol has a frontier of guarantee.

### Adversaries covered

| Adversary | Coverage |
|---|---|
| Compromised SelfRecover server | ✅ Split knowledge + client-side HMAC: server never sees raw secrets |
| Phishing / spoofed domain | ✅ Domain-bound HMAC: a fake domain produces a different key |
| Network sniffer / MITM | ✅ TLS in transit + only HMAC derivation transmitted |
| Database leak | ✅ Argon2id hashes (memory-hard, GPU-resistant) |
| Online brute-force | ✅ Per-username rate-limit + L2/L3 progressive escalation |

### Adversaries OUT OF SCOPE — explicitly assumed

| Adversary | Mitigation |
|---|---|
| **Compromised host** (keylogger, info-stealer, RAT) | Out of scope. Use **Tails Live USB**, **Qubes OS**, or **MySelf-Live (V0.2)** for root secret ceremonies. See [Roadmap](#roadmap). |
| Compromised browser (extension, 0-day) | Out of scope. Same mitigation. |
| Coercion ($5 wrench attack) | Out of scope. No plausible deniability provided. |
| Theoretical break of SHA-256 / Argon2id | Out of scope. Migration follows ANSSI/NIST guidance. |

### Operational discipline

The passphrase **MUST** never exist outside the user's brain (and a paper backup). It should never be typed for "verification" or "validation". Three legitimate moments only:

1. Account registration (one keystroke, server stores Argon2id hash)
2. Recovery L1 (one keystroke, proves knowledge)
3. After recovery, the passphrase is no longer used — the regenerated password replaces it

If verification of a freshly-rolled passphrase is desired, use the **standalone offline HTML tool** (`demo/offline/selfrecover-validator.html`) on an air-gapped machine.

---

## Roadmap

### V0.1 (current — May 2026)

- [x] Protocol specification
- [x] Reference implementation (community platform)
- [x] Whitepapers EN + FR
- [x] Standalone demo (this repo)
- [x] EFF 7776-word wordlist integrated (EN + FR)
- [x] Three entropy modes in demo: Reinhold dice / Auto random / Free passphrase / Hybrid
- [x] Diceware reference PDF (EN + FR) — official method
- [x] Standalone offline HTML validator (zero external requests, verifiable by `grep`)

### V0.2 — MySelf-Live (planned: summer 2026)

A minimal, signed, verifiable Linux distribution for SelfRecover ceremonies:

- Debian/Alpine-based Live USB, RAM-only, no persistence
- UEFI Secure Boot with MySelf-signed kernel
- Reproducible builds (anyone can verify image hash)
- GPG offline signing (root key on smartcard / YubiKey)
- Multi-channel distribution (HTTPS + IPFS + torrent + GitHub releases)
- Pre-installed: SelfRecover daemon (localhost), Tor, Firefox ESR hardened, EFF PDF embedded
- Network: disabled by default (air-gap mode at boot)
- Target image size: ~500 MB
- Inspired by Tails / Qubes OS / Whonix, focused on cryptographic ceremonies

Build skeleton: see [`tools/build-myself-live/`](../../tools/build-myself-live/) (in progress).

### V0.3 (planned: autumn 2026)

- [ ] Community security audit
- [ ] Reproducible build pipeline finalized
- [ ] Anti-Evil-Maid (Heads / TPM measurements) optional
- [ ] Localizations EN / FR / DE / ES

### V1.0 (planned: 2027)

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

[AGPL-3.0-or-later](../../LICENSE) — strong copyleft. Use it, modify it, self-host it. If you build a service on top of SelfRecover and offer it to others, you must publish your modifications too.

---

## Author

**Pierroons** — author of the project.

*SelfRecover — because your identity shouldn't depend on an inbox.*
