# MySelf

> 🇫🇷 **[Lire cette page en français →](./README.fr.md)**

**Be yourself, for yourself.**

> The human provides entropy. The machine provides impartiality.
> Neither is enough alone. Together, they are sovereign.

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
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
| [SelfInvoice](./selfinvoice/) | How do you bill clients? | beta (Factur-X native) |
| **[SelfFarm-Lite](https://selffarm.my-self.fr)** | **How do you run your farm?** | **v0.2 live ✅** |

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

Generates legally compliant PDF invoices with **native Factur-X** support
(PDF/A-3 + XML CII, EN16931 profile — mandatory in France from September
2026 for reception, 2027-2028 for emission). Multi-regime: franchise VAT
(art. 293 B CGI), micro-BA, réel simplifié/normal. No cloud, no subscription,
no fund custody. The client pays via a standard SEPA transfer to the IBAN
displayed on the invoice.

> *Your invoice. Your template. Your data. Done.*

---

## Application layer — SelfFarm-Lite

When the three pillars hold, **applications can be built on top of them**.
SelfFarm-Lite is the first such application: a full farm-management stack
for young farmers (JA), new installers (NA), small operations (AGRI) and
agri-SMEs (PME).

> *When identity, law, and security are in place, individuals can build.*

SelfFarm-Lite contains 7 modules that all feed **a single central
accounting hub** (`self_agri_book`):

| Module | Role |
|--------|------|
| `self_agri_book` | **Central accounting hub** — journal, ledger, trial balance, profit & loss, balance sheet, FEC DGFIP export (conformant with L47 A-I LPF) |
| `self_invoice` | Factur-X native invoicing (BASIC / EN16931 / EXTENDED) — auto-writes 411/701 to the hub |
| `self_dnja` | French young-farmer business-plan engine — 4-year forecast + official CDOA PDF |
| `self_aid` | Public aid catalog, sourced from primary authorities (Légifrance, BOFiP, FranceAgriMer, MSA, regional portals) |
| `self_banking` | Fake-first bank statement parsers (Société Générale done, CA/CM/Boursorama to come). Imports populate the hub with 512/411 auto-reconciliation, recurring direct debits, bank fees |
| `self_parcelles` | Cartographic view of plots via IGN Géoportail (cadastre overlay + WFS search) |
| `self_achats` | Supplier purchases (seeds, fuel, insurance) — 6xxx/401 to the hub |

**Hub-centric architecture**: every module feeds the same
`ecritures_comptables` SQLite table. Zero double-entry. Dedup guaranteed by
`(source_module, source_id)` uniqueness. Single source of truth for
accountant, tax office, and the farmer themselves.

**Live demo**: https://selffarm.my-self.fr

**Philosophy match**: SelfFarm-Lite uses the three MySelf pillars underneath:
- **Bi-Self**: sign legal documents with your SelfRecover identity, contribute
  to the shared aids catalog via SelfModerate
- **Self-Right**: SelfJustice for agricultural litigation (sharecropping,
  bailleur/preneur, regulatory disputes), SelfAct for CERFA forms (PAC
  declarations, etc.)
- **Self-Security**: SelfGuard for sensitive banking credentials, SelfKeyGuard
  for hardware 2FA on tractors/greenhouses/warehouses

The same pattern can be applied to **any other profession**: `SelfClinic-Lite`
for independent health practitioners, `SelfCraft-Lite` for artisans,
`SelfStore-Lite` for retail, etc. SelfFarm-Lite is the first proof that the
three pillars are load-bearing.

---

## The big picture

MySelf addresses the **complete person** through three pillars and one
application layer:

| Layer | Dimension |
|-------|-----------|
| **Bi-Self** | Social — who you are and how you interact |
| **Self-Right** | Legal — what you can defend by law |
| **Self-Security** | Material — what you protect concretely |
| **SelfFarm-Lite** (application layer) | Professional — what you build and operate |

Three pillars, two modules each, plus SelfInvoice as a standalone module,
plus SelfFarm-Lite as the first application layer on top.

No module is mandatory. You pick what matches your needs and self-host
what you want to control.

---

## Philosophy

- **Open source** (AGPL v3) — open code, community audit, no black box, and anything built on top of MySelf must stay libre too
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

## Support the project

MySelf is self-hosted on a Raspberry Pi 4, no ads, no trackers, no commercial
sponsor. If the project is useful to you or speaks to you, a direct gesture
helps keep it alive.

**[🙏 Support via Viva Wallet](https://pay.vivawallet.com/pierroons)** — card,
Apple Pay, Google Pay, PayPal. Minimal commission, independent pro account.

---

## License

[AGPL-3.0-or-later](LICENSE) — strong copyleft. You can use it, modify
it, self-host it. If you build a service on top of MySelf and offer it
to others, you must publish your modifications too. Historical MIT
releases (before 2026-04-19) remain under their original terms.

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
