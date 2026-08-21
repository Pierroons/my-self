# Self-Security

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Encrypt what is stored, and keep it encrypted when the rest gives way.**

> *Dump my database — and get encrypted noise.*

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../LICENSE)
[![SelfDataGuard: v0.2.0](https://img.shields.io/badge/SelfDataGuard-v0.2.0-brightgreen.svg)](./selfdataguard/)
[![SelfRecover-LUKS: v0.3.0](https://img.shields.io/badge/SelfRecover--LUKS-v0.3.0-green.svg)](./selfrecover-luks/)
[![Part of: MySelf](https://img.shields.io/badge/part%20of-MySelf-blue.svg)](../README.md)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

---

## The tension it addresses

Two assumptions hold up most application security, and both give way on the same day:

1. **"The database will not leave."** It does — a forgotten backup, a provider's dump, an SQL injection, a resold drive. Full-disk encryption protects nothing here: the machine is running, the volume is mounted, the rows read in plain.
2. **"The disk is encrypted, so the machine is protected."** Cold, yes. And the passphrase that opens it is usually a *second* secret to remember, written down somewhere — which makes it the weak link rather than the strong one.

Self-Security takes the two surfaces apart: **data is encrypted before it reaches the database**, and **the volume opens with a secret you already memorize**.

---

## Why the two modules reinforce each other

**SelfDataGuard alone** keeps application data unreadable even if the whole database is exfiltrated — the key is derived from a secret only the user knows, so a dump on its own yields nothing. But it runs on a machine, and that machine has a disk.

**SelfRecover-LUKS alone** keeps that disk unreadable while the machine is off. But the moment it boots, the volumes are mounted and the database reads in plain.

**Together**, both states are covered — cold by LUKS2, warm by application-layer encryption — and they draw on one memorized passphrase, derived under two distinct labels:

| Label | Opens | Module |
|---|---|---|
| `disk` | a LUKS2 slot | SelfRecover-LUKS |
| `data-enc` | application data | SelfDataGuard |

The label changes the effective salt, so two keys from the same secret stay independent: compromising one does not open the other.

---

## What each one does when something goes wrong

- **Database dumped and published** → fields encrypted by SelfDataGuard stay noise. Each user's master key is wrapped twice — once by an Argon2id key derived from their password, once by an HMAC-SHA256 key derived from their recovery word — and neither derivation input is in the dump.
- **Machine off, drive seized or resold** → the LUKS2 volume is closed. Secondary volumes open from a key-file kept *inside* the encrypted root, so a stolen drive stays unreadable on its own.
- **Server rebooted remotely** → a dropbear SSH server embedded in the initramfs takes the passphrase; the root volume opens, then the secondary volumes cascade without a second entry.
- **Keyscript fails** → every volume keeps a native LUKS slot with a classic passphrase, never removed. A broken keyscript costs a manual unlock, not the data.

---

## Modules in this bundle

| Module | Role | Status |
|--------|------|--------|
| [SelfDataGuard](./selfdataguard/) | Application-layer data-at-rest encryption surviving a database dump | **v0.2.0** — in service, 191 checks across 8 suites |
| [SelfRecover-LUKS](./selfrecover-luks/) | LUKS2 root **and** data volumes unlocked by one recovery passphrase | **v0.3.0** — validated on a Debian 13 LNMP server, reproducible install |

---

## Status

Both modules run. SelfDataGuard is deployed and its eight suites pass; SelfRecover-LUKS was validated over full reboot cycles — root volume plus cascading secondary volumes — and its install is documented step by step in [INSTALL.md](./selfrecover-luks/INSTALL.md).

Neither has been audited by an external cryptographer. Their design is verified today by their author and by the readers of this repository, and by no one else. Audits are welcome — see [SECURITY.md](../SECURITY.md).

---

## Published designs

Two designs in this pillar have **no module of their own to run**. They state a threat model, a cryptographic design and, for one of them, a hardware bill of materials. They are published so the reasoning can be read and argued with — not because anything is ready to install.

| Design | Question | Document |
|---|---|---|
| [SelfGuard](./selfguard/) | What survives coercion? | [whitepaper](./selfguard/docs/whitepaper.md) |
| [SelfKeyGuard](./selfkeyguard/) | Can a physical object require hardware 2FA? | [whitepaper](./selfkeyguard/docs/whitepaper.md) |

SelfKeyGuard describes **two arms**, and only the first is document-only. Its second arm — unlocking a disk through a quorum of household witnesses, with a SelfRecover fallback — has working R&D code, kept under [`selfrecover-luks/quorum-rnd/`](./selfrecover-luks/quorum-rnd/) and validated on throwaway images. It is deliberately **not enabled in v0.3.0**, which unlocks by keyscript and keyfile instead.

A first implementation would follow an independent security audit and a physical trial period. This is security-critical code and hardware; speed is not a virtue here.

---

## Author

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*Self-Security — one passphrase, two states, readable in neither.*
