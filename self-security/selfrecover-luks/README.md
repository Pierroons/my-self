# SelfRecover-LUKS

> 🇫🇷 **[Lire en français →](./README.fr.md)**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.5.0](https://img.shields.io/badge/status-v0.5.0-green.svg)](./INSTALL.md)
[![Part of: Self-Security](https://img.shields.io/badge/part%20of-Self--Security-blue.svg)](../README.md)
[![Companion of: SelfRecover](https://img.shields.io/badge/companion-SelfRecover-green.svg)](../../bi-self/selfrecover/)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

> Unlocking **LUKS2** encrypted disks — root volume **and** data volumes — with a **single
> recovery passphrase**, remotely from boot, with no cloud and no trusted third party. The
> self-hosted FDE layer of the **MySelf** ecosystem (Self-Security pillar).

**Status: validated on a LNMP Debian 13 Trixie server (2026-06-07), on an encrypted
laptop (2026-08-22), and on an encrypted-LVM root — the layout the Debian installer
proposes in guided mode (2026-09-13) — v0.5.0.**
Root (`/`) unlocked at boot (Argon2id keyscript + boot SSH) and automatic cascade of secondary
volumes (key-file), reproducible reboots. Documented, reproducible install →
**[INSTALL.md](./INSTALL.md)**.

## The principle

A recovery passphrase — diceware, drawn for each machine by `genere-passphrase.py` — goes through **Argon2id** under the label `disk`, which yields the key of a **LUKS2 slot**.

The label changes the effective salt → two keys drawn from the same secret under two labels are independent. The derivator accepts other labels (`--label`), but **only `disk` has a consumer** (`selfrecover-keyscript.sh`): SelfRecover web and SelfDataGuard do not go through it. Argon2id
(memory-hard) because a disk key is brute-forceable **offline** if the drive is stolen. Resistance comes **first from the passphrase entropy**; Argon2id slows each attempt but does not offset a weak secret.

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
- **The root volume is a keyring**: what it holds opens the rest. The key files of the
  secondary volumes live there and, on a host that signs its own kernels, so does the Secure
  Boot signing key. The disk does not only protect files: it protects the keys that open
  others.
- **Anti-lockout net**: every volume keeps a **native** slot (classic passphrase), never
  removed, openable by hand if the keyscript fails.

> A **quorum** path (auto-unlock by witness consensus, no entry) is described in the whitepaper
> (future work); it is not enabled in this version.

## Components

| File | Role |
|------|------|
| `selfrecover_derive.c` | Argon2id derivation (self-contained C clone for the initramfs; stdin → hex key) |
| `selfrecover_derive.py` | reference implementation (Python, userspace) |
| `selfrecover-keyscript.sh` | root-volume keyscript (derives the recovery passphrase) |
| `initramfs-hook-selfrecover` | embeds binary + libargon2 + **libgcc** + salt + keyscript in the initrd |
| `setup-add-selfrecover-slot.sh` | adds a recovery slot to a LUKS volume (authorized by an existing key) |
| `format-slot.sh` | records the enrolled key format per volume, and refuses to install a keyscript of another format |
| `selfrecover-unlock.sh` | standalone emergency unlock (userspace) |
| `selfrecover-secours.sh` | `command=` of the boot SSH key: offers the recovery passphrase **or** the native passphrase, **never a shell** |
| `verifie-initramfs.sh` | compares the images in `/boot` with the fingerprint recorded on the encrypted volume — makes an image modified off-machine visible |
| `verifie-sauvegardes.sh` | checks that the LUKS header and the salt have a copy **off the volume** and **current** with its slots |
| `genere-passphrase.py` | draws a diceware passphrase, printing both forms and their lengths |
| `initramfs-post-update-verifie-selfrecover` | guard: checks the six pieces, **the salt**, and **the image the bootloader actually loads** after every initramfs build |
| [`tests/test_lecture_keyfile.sh`](./tests/test_lecture_keyfile.sh) | guard: the four read paths, and the trailing `\n` that breaks the key |
| [`tests/test_secours_sans_shell.sh`](./tests/test_secours_sans_shell.sh) | bench: the boot rescue offers both paths and refuses any shell |
| [`tests/test_sauvegardes.sh`](./tests/test_sauvegardes.sh) | bench: `verifie-sauvegardes.sh` refuses a copy on the encrypted volume or a stale one |
| [`tests/test_garde_fou_image_chargee.sh`](./tests/test_garde_fou_image_chargee.sh) | bench: the guard judges the image the bootloader **loads** (Raspberry Pi included) |
| [`docs/cryptsetup-lecture-cle.md`](./docs/cryptsetup-lecture-cle.md) | measurement note (French): how `cryptsetup` reads a key depending on the path taken |
| `fido2-banc-essai/` | research bench: FIDO2 in the initramfs — not a supported path |
| `install.sh` | semi-automatic installer (see INSTALL.md) |
| [`quorum-rnd/`](./quorum-rnd/) | R&D: witness-quorum unlock — **not enabled in v0.5.0** |

## Installation

Full step-by-step guide: **[INSTALL.md](./INSTALL.md)**. In short: compile the derivation,
deploy keyscript + hook, generate the salt, add recovery slots, configure the root volume
(keyscript + dropbear + rootdelay) and the secondary volumes (key-file), rebuild the initramfs,
**test by rebooting with a safety net**.

Architecture document (the *why*): **[SelfRecover-LUKS_Whitepaper](./docs/SelfRecover-LUKS_Whitepaper.md)** — also as [DOCX download](https://github.com/Pierroons/my-self/raw/main/self-security/selfrecover-luks/docs/SelfRecover-LUKS_Whitepaper.docx).

## Safeguards

- **Strong** recovery passphrase (diceware) — the KDF slows attacks, it does not offset a weak secret.
- **Native slot kept** on every volume + initramfs backup before rebuild.
- **No shell before `/` is open.** The boot SSH key is bound to `selfrecover-secours.sh`: the
  recovery passphrase or the native passphrase, nothing else. A shell at that point bypasses the
  encryption — `/boot` is in clear, a modified initrd can be dropped there to capture the next
  entry. `install.sh` asks (`SHELL_AMORCAGE`) and records a refusal in `renoncements.log`.
- **LUKS header backed up before any slot change**, and `verifie-sauvegardes.sh` refuses a copy
  stored on the volume it opens, or one whose slot count no longer matches the disk.
- **Disaster recovery**: keep off-site (password manager) the passphrase, the **deployment
  salt** and the backup secrets — without the salt, no re-derivation on new hardware.
- No automatic destruction: slot addition is explicit, keys live in tmpfs.

AGPL-3.0-or-later · part of the [MySelf](https://my-self.fr) ecosystem
