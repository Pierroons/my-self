# Bi-Self

**Sovereign identity + autonomous community moderation.**

> *If a community can build itself, it can govern itself.*

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](../LICENSE)
[![SelfRecover: v0.1.0](https://img.shields.io/badge/SelfRecover-v0.1.0-green.svg)](./selfrecover/)
[![SelfModerate: v0.1.0](https://img.shields.io/badge/SelfModerate-v0.1.0-orange.svg)](./selfmoderate/)
[![Part of: MySelf](https://img.shields.io/badge/part%20of-MySelf-blue.svg)](../README.md)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

---

## The tension it addresses

Every online community faces two chronic problems that no platform has solved honestly:

1. **Who are you?** — Email-based identity is leaky, centralized, and forces dependence on Google/Microsoft. Social login is worse. And yet any democracy — even a forum democracy — starts with answering "one person, one voice."
2. **How do we keep the peace?** — Top-down moderation is arbitrary. Pure voting is gameable through fake accounts. Algorithmic moderation is opaque. Communities end up either authoritarian or chaotic.

Bi-Self addresses both at once. It gives communities the **two minimum primitives** to govern themselves: a way to recognize members without central authority, and a way to regulate behavior without a moderator-king.

---

## Why the two modules reinforce each other

**SelfRecover without SelfModerate** is a nice recovery trick, but not a community. You can prove who you are, but there's no fabric for collective life.

**SelfModerate without SelfRecover** is vote-based moderation built on sand. Anyone can create ten accounts and swing any vote. Community "democracy" becomes Sybil theatre.

**Together**, the dynamic flips:

- Reliable identity (SelfRecover) makes each vote meaningful.
- Collective voting (SelfModerate) creates a fabric that survives any single bad actor, including the founder.
- The moderator class disappears. The rules emerge from the community itself, enforceable and revisable by the community itself.

One plus one equals a self-governing community. Not three — a qualitatively different thing.

---

## Cross-module workflows

- **New member joins** → creates an account with a recovery word (SelfRecover). Zero email. The first 24 h of their activity are monitored by SelfModerate (anti-spam warm-up).
- **Toxic behavior reported** → community votes (SelfModerate). Identity of voters is guaranteed unique (SelfRecover). Outcome is binding.
- **Lost password** → any member recovers their account via L1/L2/L3 escalation (SelfRecover). No email, no admin request.
- **Collective rule change** → community proposes a new moderation threshold, votes. Threshold updates without admin intervention.

---

## Modules in this bundle

| Module | Role | Status |
|--------|------|--------|
| [SelfRecover](./selfrecover/) | Zero-email identity & recovery | v0.1.0 ✅ — production tested on [ARC PVE Hub](https://arc.rpi4server.ovh) |
| [SelfModerate](./selfmoderate/) | Community moderation by collective reasoning | v0.1.0 (whitepaper) — prototype pending |

---

## Status

SelfRecover is **deployed in production** on the ARC PVE Hub community and handles real recovery flows every day. SelfModerate has a complete whitepaper defining the protocol; reference implementation is planned for v0.2.0 alongside a live deployment on the same platform.

The two modules are designed to interlock — when both are live, a community can bootstrap itself and govern itself without any central service.

---

## Author

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*Bi-Self — Identity is the foundation of community.*
