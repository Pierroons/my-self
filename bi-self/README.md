# Bi-Self

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Sovereign identity + autonomous community moderation.**

> *If a community can build itself, it can govern itself.*

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../LICENSE)
[![SelfRecover: v0.12.2](https://img.shields.io/badge/SelfRecover-v0.12.2-green.svg)](./selfrecover/)
[![SelfModerate: v0.4.0](https://img.shields.io/badge/SelfModerate-v0.4.0-yellow.svg)](./selfmoderate/)
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

**SelfRecover without SelfModerate** is a nice recovery trick, but not a community. You can get your account back without email, but there's no fabric for collective life.

**SelfModerate, with or without SelfRecover**, is vote-based moderation, and a vote is only worth what an extra account costs. SelfRecover does not raise that cost: it drops email, a weak barrier, and proves nothing about one person holding one account. The brakes live in SelfModerate: a new account votes only after a delay — 24 h with `Config::prod()`, 2 min by default — or a first post, accounts linked to each other that vote against the same target have their votes cancelled, and a burst of unlinked votes goes to human review.

**Together**:

- An account survives a lost password (SelfRecover), so its reputation and its history survive too, instead of starting over under a new name.
- Collective voting (SelfModerate) spreads moderation across the members.
- Moderators remain, as arbiters of what the votes do not settle: every ban they impose, lift or uphold carries their name in the journal, when the host plugs one in; without a journal, nothing records them.

---

## Cross-module workflows

- **New member joins** → creates an account with a recovery word (SelfRecover). Zero email. During that delay, SelfModerate lets the account vote only if it has posted (anti-Sybil warm-up).
- **Toxic behavior reported** → members vote (SelfModerate), under the anti-Sybil brakes described above. A reputation that reaches zero bans automatically if the host has plugged in a journal; otherwise it raises a flag and an arbiter decides.
- **Lost password** → the member recovers their account at level 1 or 2 (SelfRecover), with no email and no one to ask. Level 3 goes to a human admin, who reads the case.

---

## Modules in this bundle

| Module | Role | Status |
|--------|------|--------|
| [SelfRecover](./selfrecover/) | Zero-email identity & recovery | **v0.12.2** — PSR-4 library + browser deriver, deployed and self-audited implementation |
| [SelfModerate](./selfmoderate/) | Community moderation by collective reasoning | v0.4.0 — installable engine, automatic ban traced to a journal, 45 checks in CI; 2 protocol mechanisms missing |

---

## Status

SelfRecover ships as a reference implementation and **runs in production** — as the authentication backend of a messaging service, which reuses the SelfRecover account store as-is and pins its own version. Its demo is self-audited; no external audit has been run. SelfModerate's protocol is set out in a whitepaper that is written but not yet published; the reference implementation lives in [`selfmoderate/src/`](./selfmoderate/src/) and the lab uses it. Two protocol mechanisms remain to be written, marked in its README. Members cannot vote on the thresholds yet: the host sets them (`Config`).

The two modules are designed to interlock, yet neither imports the other: wiring them together is the integrator's job. The lab runs both.

---

## Author

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*Bi-Self — Identity is the foundation of community.*
