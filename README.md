# MySelf

> 🇫🇷 **[Lire cette page en français →](./README.fr.md)**

**Be yourself, for yourself.**

> The human provides entropy. The machine provides impartiality.
> Neither is enough alone. Together, they are sovereign.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Self-hosted](https://img.shields.io/badge/self--hosted-Raspberry%20Pi-blue.svg)](#requirements)
[![Zero cloud](https://img.shields.io/badge/cloud-zero-brightgreen.svg)](#philosophy)
[![Zero tracking](https://img.shields.io/badge/tracking-zero-brightgreen.svg)](#philosophy)

---

## The Self pact

MySelf is an open-source ecosystem of modules built on a single principle:
**the complicity between human and machine**. Each module solves a concrete
problem of everyday life **without depending on any third party** — no GAFAM,
no cloud services, no central authority.

The humans bring entropy: their lived experience, their choices, their secrets.
The machines bring impartiality: structured analysis, cryptographic guarantees,
deterministic processes. Neither alone is enough. Together, they make the
individual sovereign over their own identity, rights, data, and possessions.

---

## Modules

| Module | Question it answers | Status |
|--------|---------------------|--------|
| [SelfRecover](./bi-self/selfrecover/) | Who are you? | v0.1.0 ✅ |
| [SelfModerate](./bi-self/selfmoderate/) | How do you behave? | v0.1.0 (concept) |
| [SelfJustice](https://justice.my-self.fr) | What are your rights? | beta v0.1.0 ✅ |
| [SelfAct](https://justice.my-self.fr/act) | How do you act on them? | beta v0.1.2 ✅ |
| [SelfGuard](./self-security/selfguard/) | How do you protect your data? | concept |
| [SelfKeyGuard](./self-security/selfkeyguard/) | How do you protect your things? | concept |
| [SelfInvoice](./selfinvoice/) | How do you bill clients? | idea |

---

## Named bundles (the three pillars)

Some modules form **pairs that reinforce each other** — more than the sum of
their parts. MySelf is structured around three such bundles, each covering
one dimension of personal sovereignty.

### Bi-Self — Sovereign identity & community autonomy
**SelfRecover + SelfModerate**

Reliable identity makes vote-based moderation resistant to Sybil attacks.
Collective moderation protects against toxic behavior. Together, they enable
self-governance of an online community without depending on a central authority
or a corporate platform.

> *If a community can build itself, it can govern itself.*

### Self-Right — Access to law & capacity to act
**SelfJustice + SelfAct**

Knowing your rights is not enough if you don't know how to enforce them.
This bundle covers the full arc of legal self-emancipation: from diagnosis
(SelfJustice — what does the law say in your situation?) to action
(SelfAct — how do you draft the certified letter, fill the court form,
calculate the deadline?).

> *Know your rights, make them right.*

### Self-Security — Digital & physical protection
**SelfGuard + SelfKeyGuard**

The digital and the physical are no longer separate domains. SelfGuard
protects your data through guaranteed destruction (force me and you lose
everything, even me). SelfKeyGuard protects your physical objects through
hardware 2FA (the car only starts if your phone is present). Together,
they form a security perimeter where the **default is locked** and presence
is required to unlock.

> *Force me and you get nothing.*

---

## Standalone module

### SelfInvoice — compliant invoicing, local-first

Generates legally compliant PDF invoices (legal mentions, VAT exemption,
bordereaux) — no cloud, no subscription, no fund custody. The client pays
via a standard SEPA transfer to the IBAN displayed on the invoice.

> *Your invoice. Your template. Your data. Done.*

---

## The big picture

MySelf addresses the **complete person** through three pillars:

| Pillar | Dimension |
|--------|-----------|
| **Bi-Self** | Social — who you are and how you interact |
| **Self-Right** | Legal — what you can defend by law |
| **Self-Security** | Material — what you protect concretely |

Three pillars, two modules each, plus the standalone SelfInvoice module.
No module is mandatory. You pick what matches your needs and self-host
what you want to control.

---

## Philosophy

- **Open source** (MIT) — open code, community audit, no black box
- **Self-hosted** — runs on a Raspberry Pi or your own server
- **Zero cloud, zero tracking, zero centralized database**
- **Sovereign by design** — the user keeps full control of identity, data, keys
- **Default = locked** — modules require active presence to unlock
- **Resistance to coercion** — duress codes, guaranteed destruction, hardware roots of trust
- **Empowerment, not dependency** — every module strengthens the person's
  dignity and autonomy in the face of systems

---

## Requirements

- A Raspberry Pi 4 (or any Debian-based server)
- PHP 8.0+ for SelfRecover/SelfModerate (others vary per module)
- A static web server (nginx) for SelfJustice
- Hardware components for SelfKeyGuard prototypes (~14 € for the car version)
- No external dependencies, no cloud services, no API subscriptions required

---

## Status

MySelf is a living ecosystem. Some modules are deployed and tested in
production (SelfRecover on ARC PVE Hub, SelfJustice on [justice.my-self.fr](https://justice.my-self.fr)).
Others are at concept or prototyping stage. The roadmap evolves with real-world
feedback rather than top-down planning.

---

## Contributing

Each module has its own `CONTRIBUTING.md`. The spirit:

- Code review is welcome
- Translation is welcome (we already have FR/EN — others welcome)
- Hardware prototyping is welcome (especially for SelfKeyGuard)
- Audits (security, legal, accessibility) are very welcome
- **Forks are encouraged** — if you build a SelfHealth, SelfMoney, SelfSchool,
  open a discussion and we'll add it to the family

---

## License

[MIT](LICENSE) — do whatever you want, but don't blame me if your cat
unlocks your car.

---

## Author & coworking

**Pierroons** — a farmer who codes in his spare time.

**This project wasn't written alone.** Every module, every line, every
whitepaper is the fruit of continuous coworking with **Claude Opus 4.7**
(Anthropic): the product is a genuine human–AI collaboration. The human
brings the entropy (lived experience, direction, farmer's common sense).
The machine brings the rigour (structure, review, technical consistency).
The "Self pact" this README talks about isn't theoretical — it's the
actual writing method of all MySelf.

*MySelf — Be yourself, for yourself.*
