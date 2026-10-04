# Self-Security

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Encrypt what is stored, and keep it encrypted when the rest gives way.**

> *Dump my database — and get encrypted noise.*

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../LICENSE)
[![SelfDataGuard: v0.6.0](https://img.shields.io/badge/SelfDataGuard-v0.6.0-brightgreen.svg)](./selfdataguard/)
[![SelfRecover-LUKS: v0.6.2](https://img.shields.io/badge/SelfRecover--LUKS-v0.6.2-green.svg)](./selfrecover-luks/)
[![Part of: MySelf](https://img.shields.io/badge/part%20of-MySelf-blue.svg)](../README.md)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

---

## The tension it addresses

Two assumptions hold up most application security, and both give way on the same day:

1. **"The database will not leave."** It does — a forgotten backup, a provider's dump, an SQL injection, a resold drive. Full-disk encryption protects nothing here: the machine is running, the volume is mounted, the rows read in plain.
2. **"The disk is encrypted, so the machine is protected."** Cold, yes — as long as the passphrase that opens it holds against an offline attack. A passphrase chosen to be remembered is usually short, and a stolen disk can be tried at leisure.

Self-Security takes the two surfaces apart: **data is encrypted before it reaches the database**, and **the volume opens with a diceware passphrase drawn for that machine**, derived by Argon2id, whose strength comes from the random draw rather than from memory.

---

## Why the two modules reinforce each other

**SelfDataGuard alone** keeps application data encrypted even if the whole database is exfiltrated: none of the secrets that wrap the data key is in the dump. But SelfDataGuard runs on a machine, and that machine has a disk.

**SelfRecover-LUKS alone** keeps that disk unreadable while the machine is off. But the moment it boots, the volumes are mounted and the database reads in plain.

**Together**, both states are covered — cold by LUKS2, warm by application-layer encryption. One case stays open: a server compromised while it runs reads every session opened as long as it stays compromised, and the secrets submitted to it meanwhile. The two modules share no secret:

| Secret | Held by | Derived with | Opens | Module |
|---|---|---|---|---|
| a diceware passphrase | the machine's administrator | Argon2id, label `disk` | a LUKS2 slot | SelfRecover-LUKS |
| a password, a memorized word, a passphrase | each user | Argon2id, the user's salt | that user's data | SelfDataGuard |

---

## What each one does when something goes wrong

- **Database dumped and published** → fields encrypted by SelfDataGuard stay noise. Each user's master key is wrapped under each of their secrets — password, memorized word, passphrase —, always through Argon2id at the same cost, since wraps are only as strong as the cheapest one, and none of these inputs is in the dump. What the attacker can still do is guess them offline, one Argon2id per try.
- **Machine off, drive seized or resold** → the LUKS2 volume is closed. Secondary volumes open from a key-file kept *inside* the encrypted root, so a stolen drive stays unreadable on its own.
- **Server rebooted remotely** → a dropbear SSH server embedded in the initramfs takes the passphrase; the root volume opens, then the secondary volumes cascade without a second entry. The installer offers to close the boot-time root shell, and recommends doing so: left open, that shell lets whoever holds its key plant a modified initrd. Closed, `/boot` stays modifiable by physical access, and by root on the running machine.
- **Keyscript fails** → every volume keeps a native LUKS slot with a classic passphrase, never removed. A broken keyscript costs a manual unlock, not the data.

---

## Modules in this bundle

| Module | Role | Status |
|--------|------|--------|
| [SelfDataGuard](./selfdataguard/) | Application-layer data-at-rest encryption surviving a database dump | **v0.6.0** — available, test suites listed in its README |
| [SelfRecover-LUKS](./selfrecover-luks/) | LUKS2 root **and** data volumes unlocked by one recovery passphrase | **v0.6.2** — reproducible install; earlier releases validated on a Debian 13 LNMP server, a laptop, an encrypted-LVM root and an arm64 machine |

---

## Status

Both modules run. SelfDataGuard serves its public demo, and its test suites run in CI; SelfRecover-LUKS was validated over full reboot cycles — root volume plus cascading secondary volumes — and its install is documented step by step in [INSTALL.md](./selfrecover-luks/INSTALL.md).

One research path is deliberately left off: unlocking a volume through a **quorum of household witnesses** (Shamir shares, with a SelfRecover fallback when the quorum is unreachable). Its code sits under [`selfrecover-luks/quorum-rnd/`](./selfrecover-luks/quorum-rnd/) and was validated on throwaway images, but it is **not enabled**: the module unlocks by keyscript and keyfile instead.

Neither module has been audited by an external cryptographer. Their design is verified today by their author and by the readers of this repository, and by no one else. Audits are welcome — see [SECURITY.md](../SECURITY.md).

---

## Author

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*Self-Security — two secrets, two states.*
