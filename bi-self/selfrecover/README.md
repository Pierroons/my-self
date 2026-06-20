# SelfRecover

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Zero-email account recovery protocol** — split knowledge, HMAC per service, no SMTP, no third party.

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.1.1](https://img.shields.io/badge/status-v0.1.1-green.svg)](#status)
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
| **[Full](./demo/index.html)** | None at all | Diceware EFF passphrase + HMAC-per-service | Greenfield projects, high-assurance and post-ANTS-leak threat models |
| **[Lite](./demo/lite.html)** 🆕 | Kept (SMTP reset link) | A user-memorized word HMAC-derived client-side, never sent raw | Existing email-based stacks that want phishing-resistance now and migrate to Full later |

**Live demos:** [Full](https://bi-self.my-self.fr/selfrecover/) · [Lite](https://bi-self.my-self.fr/selfrecover/lite.html) · [Side-by-side comparison (8 adversaries × 3 models)](https://bi-self.my-self.fr/selfrecover/comparison.html)

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

The user remembers **one word of their choice**. That's it.

When they type it, the browser performs a **HMAC-SHA256 derivation** keyed by a stable **service label** plus a **per-user salt**, producing a service-specific key before anything leaves the client. The server never sees the raw word.

```
derived_key = HMAC-SHA256(key = service_label ‖ user_salt, message = recovery_word)
```

**No SMTP.** **No third party.** **Same UX on every site.**

---

## Cryptographic specification

### Primitives

| Role | Algorithm | Parameters |
|------|-----------|------------|
| Client-side key derivation | HMAC-SHA256 | key = service_label &#124;&#124; user_salt, message = recovery_word |
| Server-side secret storage | Argon2id | memory = 64 MiB, time = 4, threads = 2 (memory-hard) |
| Public identifier hashing | SHA-256 | truncated to 16 bytes, then hex-encoded |
| Passphrase generation (L1) | EFF Diceware | 4 words, ≥ 51 bits of entropy |
| Per-user salt | 16 random bytes | generated client-side at registration, stored in clear (a salt is not a secret) |

### Storage model

For each account, the server stores exactly three secrets:

```sql
CREATE TABLE account (
  id           INTEGER PRIMARY KEY,
  identifier   TEXT UNIQUE,              -- public, user-chosen
  password     TEXT,                     -- Argon2id(password)
  pass_hash    TEXT,                     -- Argon2id(diceware_passphrase)  [L1]
  recovery     TEXT,                     -- Argon2id(derived_key)          [L2]
  user_salt    TEXT,                     -- per-user salt, client-generated (not secret)
  created_at   INTEGER
);
```

The server never sees: the raw password, the raw passphrase, the raw recovery word. Every comparison is an Argon2id verification against the client-submitted derived value.

### Key-stretching chain (Level 2 recovery)

```
user input   → recovery_word
client       → derived_key  = HMAC-SHA256(service_label ‖ user_salt, recovery_word)
wire         → POST /recover { identifier, derived_key }
server       → verify        = password_verify(derived_key, stored_recovery_hash)  // Argon2id
```

The wire never carries the recovery word. The server never stores the recovery word. Even a full database dump + source code leak does not expose it — only Argon2id hashes of per-site-derived keys.

### Why HMAC-SHA256 (and not PBKDF2 / Argon2)

HMAC is intentionally **fast** client-side because the goal is service binding, not brute-force resistance. The brute-force resistance is provided server-side by **Argon2id** (memory-hard, 64 MiB per attempt) on the derived key. Splitting the roles keeps the UX instant on mobile while still imposing a memory-hard cost per server-side verification attempt.

---

## Three-level recovery escalation

| Level | Secret required | Outcome |
|-------|----------------|---------|
| **L1** | Passphrase (diceware, 4 words) | New password |
| **L2** | Public identifier + recovery word | New password |
| **L3** | Public identifier + context signals | Human admin decision, then self-re-enrollment |

Rate limits, dispute system, and anti-abuse detection at every level. L3 produces **raw facts** for a human admin — never an automatic score — and on grant the user re-defines their own secret (the server issues no password).

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
5. **Recover L3** — lost everything → context questions build a bundle of raw signals for a human admin (no numeric score); on grant you re-define your own secret

No dependencies beyond PHP CLI. SQLite as database. Zero configuration.

> **Note:** The demo covers all three levels (L1/L2/L3), including the dispute chat and the admin decision flow. Read the **[whitepaper](docs/whitepaper-en.md#5-three-level-recovery-escalation)** for the full L3 specification.

---

## Architecture

```
┌──────────────┐           ┌──────────────┐
│   Browser    │           │    Server    │
└──────┬───────┘           └──────┬───────┘
       │                          │
       │  GET /user-salt?id=…     │
       │─────────────────────────>│
       │<─────────────────────────│
       │   user_salt              │
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
| **Passive-phishing resistance** | The derivation is bound to a stable service label, not reused across services. A passive clone that copies the page without adapting it derives a useless key. An active phishing site that controls its own page is out of scope (true for any in-browser protocol). |
| **Replay resistance** | Each recovery request is gated by a server-side rate limit + dispute system. L3 adds a human-reviewed decision. |
| **Leak resistance** | Each account has its own salt; the server stores only Argon2id hashes of per-service-derived keys. Leaked client code alone is useless. |
| **No central dependency** | Each deployment is autonomous. No SPOF, no vendor lock-in, no operator who can revoke accounts across the ecosystem. |
| **Human-memorable secret** | One word of the user's choice. Not a 24-word seed, not a passphrase you write on paper, not a QR code. |

---

## Threat model at a glance

**Protected against:**
- Database compromise (Argon2id-only storage, no reversible secrets)
- Passive phishing (service-bound derivation)
- SMTP attacks, SIM swapping, email account takeover (no email in the loop)
- Account brute force (Argon2id memory-hard cost + rate limits + human-reviewed L3)

**Not claimed to protect against:**
- Malicious client code / active phishing (if the attacker controls the page your browser loads, the protocol can't help — true for any in-browser protocol)
- Weak recovery words (`password`, `123`) — mitigated by rate-limiting and escalation to a human-reviewed L3, not by the derivation itself
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

**Concept stage — reference implementation + live demo, self-audited**

This repository contains:
- The **protocol specification** (whitepapers v1.1)
- A **standalone working demo** (L1/L2/L3) to try the concept locally
- A **reference implementation** of the full protocol

**What this repo is NOT (yet):**
- An installable PHP/JS library (planned, once the protocol is independently audited)
- A product with an external security audit (an internal adversarial audit has been run; external red-team feedback is welcome)

No real-world production deployment yet. The demo and the self-audit are how you can exercise it today.

---

## Threat model

SelfRecover is honest about what it protects and what it does not. Every cryptographic protocol has a frontier of guarantee.

### Adversaries covered

| Adversary | Coverage |
|---|---|
| Compromised SelfRecover server | ✅ Split knowledge + client-side HMAC: server never sees raw secrets |
| Passive phishing / cloned page | ✅ Service-bound HMAC: a clone that doesn't adapt its code derives a different key (active phishing controlling its own page is out of scope) |
| Network sniffer / MITM | ✅ TLS in transit + only HMAC derivation transmitted |
| Database leak | ✅ Argon2id hashes (memory-hard, GPU-resistant) |
| Online brute-force | ✅ Per-username rate-limit + L2/L3 progressive escalation |

### Adversaries OUT OF SCOPE — explicitly assumed

| Adversary | Mitigation |
|---|---|
| **Compromised host** (keylogger, info-stealer, RAT) | Out of scope. Use **Tails Live USB**, **Qubes OS**, or **MySelf-Live (V0.2)** for root secret ceremonies. See [Roadmap](#roadmap). |
| Compromised browser (extension, 0-day) | Out of scope. Same mitigation. |
| Coercion (physical / rubber-hose) | Out of scope. No plausible deniability provided. |
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
- [x] Reference implementation (this repo)
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
