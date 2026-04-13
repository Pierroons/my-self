# SelfRecover

**Zero-email account recovery protocol** — split knowledge, HMAC per domain, no SMTP, no third party.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Status: Concept](https://img.shields.io/badge/status-concept%20stage-orange.svg)](#status)
[![Production test](https://img.shields.io/badge/production%20tested-ARC%20PVE%20Hub-green.svg)](https://arc.rpi4server.ovh)

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

**Pierroons** — a farmer who codes on the side.

*SelfRecover — because your identity shouldn't depend on an inbox.*
