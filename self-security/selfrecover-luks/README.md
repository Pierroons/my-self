# SelfRecover-LUKS

> 🇫🇷 **[Lire en français →](./README.fr.md)**

> Unlocking **LUKS2** encrypted disks — root volume **and** data volumes — with a **single
> recovery passphrase**, remotely from boot, with no cloud and no trusted third party. The
> sovereign FDE layer of the **MySelf** ecosystem (Self-Security pillar).

**Status: validated on a LNMP Debian 13 Trixie server (2026-06-07) — v0.3.0.**
Root (`/`) unlocked at boot (Argon2id keyscript + boot SSH) and automatic cascade of secondary
volumes (key-file), reproducible reboots. Documented, reproducible install →
**[INSTALL.md](./INSTALL.md)**.

## The principle

One memorized recovery passphrase → **Argon2id** derivation per **label** → compartmentalized child keys:

| label | use |
|-------|-----|
| `auth` | prove / recover access (SelfRecover web) |
| `data-enc` | encrypt application data (SelfDataGuard) |
| `disk` | **key for a LUKS2 slot** (this module) |

The label changes the effective salt → two keys from the same secret are independent. Argon2id
(memory-hard) because a disk key is brute-forceable **offline** if the drive is stolen.

## Architecture

A single recovery-passphrase entry opens the **whole** machine:

```
Recovery passphrase (entered once, remotely via boot SSH)
   │  Argon2id derivation (label "disk")
   ├──► ROOT VOLUME (/)   : keyscript in the initramfs → opens / at boot
   └──► SECONDARY VOLUMES : key-file stored on / (encrypted) → opened
                            automatically after pivot (cascade)
```

- **Remote unlock at boot**: a minimal SSH server (dropbear) embedded in the initramfs; the
  admin types their passphrase.
- **Cascade**: non-root volumes are opened by `systemd-cryptsetup` via a key-file kept inside
  the encrypted root vault (a stolen drive stays unreadable).
- **Anti-lockout net**: every volume keeps a **native** slot (classic passphrase), never
  removed, openable by hand if the keyscript fails.

> A **quorum** path (auto-unlock by witness consensus, no entry) is described in the whitepaper
> (future work); it is not enabled in this version.

## Components

| File | Role |
|------|------|
| `selfrecover_derive.c` | Argon2id derivation (self-contained C clone for the initramfs; stdin → raw key) |
| `selfrecover_derive.py` | reference implementation (Python, userspace) |
| `selfrecover-keyscript.sh` | root-volume keyscript (derives the recovery passphrase) |
| `initramfs-hook-selfrecover` | embeds binary + libargon2 + **libgcc** + salt + keyscript in the initrd |
| `setup-add-selfrecover-slot.sh` | adds a recovery slot to a LUKS volume (authorized by an existing key) |
| `selfrecover-unlock.sh` | standalone emergency unlock (userspace) |
| `install.sh` | semi-automatic installer (see INSTALL.md) |
| [`quorum-rnd/`](./quorum-rnd/) | R&D: witness-quorum unlock — **not enabled in v0.3.0** |

## Installation

Full step-by-step guide: **[INSTALL.md](./INSTALL.md)**. In short: compile the derivation,
deploy keyscript + hook, generate the salt, add recovery slots, configure the root volume
(keyscript + dropbear + rootdelay) and the secondary volumes (key-file), rebuild the initramfs,
**test by rebooting with a safety net**.

Architecture document (the *why*): **[SelfRecover-LUKS_Whitepaper](./docs/SelfRecover-LUKS_Whitepaper.md)** — also as [DOCX download](https://github.com/Pierroons/my-self/raw/main/self-security/selfrecover-luks/docs/SelfRecover-LUKS_Whitepaper.docx).

## Safeguards

- **Strong** recovery passphrase (diceware) — the KDF slows attacks, it does not offset a weak secret.
- **Native slot kept** on every volume + initramfs backup before rebuild.
- **Disaster recovery**: keep off-site (password manager) the passphrase, the **deployment
  salt** and the backup secrets — without the salt, no re-derivation on new hardware.
- No automatic destruction: slot addition is explicit, keys live in tmpfs.

AGPL-3.0-or-later · part of the [MySelf](https://my-self.fr) ecosystem
