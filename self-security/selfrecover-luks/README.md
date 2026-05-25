# SelfRecover-LUKS

> 🇫🇷 **[Lire en français →](./README.fr.md)**

> Bridge between the **SelfRecover** protocol (account recovery without email or third party)
> and **LUKS2 disk encryption**: a memorized recovery word becomes a key that opens an encrypted
> volume. The **sovereign disk-recovery** layer of the `lnmp_my-self` stack.

**Status: validated on the bench (2026-05-25)** — PoC + throwaway-image test + quorum
integration wrapper. Real-disk integration on a target: to be done on the destination server.

## The principle

One memorized root word → **Argon2id** derivation per **label** → compartmentalized child keys:

| label | use |
|-------|-----|
| `auth` | prove / recover access (SelfRecover web) |
| `data-enc` | encrypt application data (SelfDataGuard) |
| `disk` | **key-file for a LUKS2 slot** (this module) |

The label changes the effective salt → two keys from the same word are independent (the web
server cannot derive the disk key). This is the *Recover⇄DataGuard mapping* extended to FDE.

**Why Argon2id and not the web-auth HMAC?** A disk key is brute-forceable **offline** if the SSD
is stolen → it needs a slow, memory-hard KDF (≈63 ms + 64 MiB per attempt in the PoC; to be raised
in production). The fast HMAC suits authentication (protected by online rate-limiting), not the disk.

## Place in the architecture

The `/data` SSD of an `lnmp_my-self` server is **LUKS2** with several slots:

```
/data (LUKS2)
├── quorum slot      : AUTO unlock at boot (shares distributed across witnesses)
├── SelfRecover slot : this module — HUMAN recovery, no email/third party
└── air-gapped slot  : offline backup passphrase (vault)
```
On top, **SelfDataGuard** encrypts sensitive fields (readable even with the disk open → anti application-dump).

**Cross safety net**: the quorum slot is *never* removed. If a witness goes down, the SelfRecover
word opens the volume; if the word is forgotten, the quorum still opens it. Two paths, one volume.

## Components

| File | Role |
|------|------|
| `selfrecover_derive.py` | derives a deterministic key `(word, salt, label) → Argon2id` |
| `keyguard-luks-unlock.py` | **network-quorum** unlock, with **SelfRecover fallback** if the quorum is down (boot-safe: with no TTY, exits cleanly) |
| `setup-add-selfrecover-slot.sh` | adds a SelfRecover slot to a volume, authorized by an existing key (quorum master or passphrase) |
| `add-slot-via-quorum.sh` | chains *quorum → master in tmpfs → slot add → shred*. `--dry-run` reconstructs + verifies without changing anything |
| `selfrecover-unlock.sh` | standalone emergency unlock (word → `luksOpen`) |
| `test-luks-selfrecover.sh` | end-to-end PoC on a **throwaway image** (slot add, open, re-derivation at boot, wrong-word rejection) |
| `test-phase2-image.sh` | validates on a **throwaway image** that both paths (quorum master + SelfRecover word) open the same volume |

```bash
# derivation only
python3 selfrecover_derive.py --word "<recovery word>" --salt "<deployment salt>" --label disk --format raw

# full test on a throwaway image (no real disk touched)
sudo apt install cryptsetup
sudo bash test-phase2-image.sh
```

## Configuration

Everything is externalized (no infra value hard-coded). Copy **`keyguard.conf.example`** to
`keyguard.conf` (git-ignored) and fill in witnesses, paths and device — or pass the same keys as
environment variables (`WITNESSES`, `KEYGUARD_DIR`, `LUKS_DEVICE`, …).

## Safeguards

- **Strong** recovery word (diceware) — the KDF slows attacks down, it does not compensate a weak word.
- The SelfRecover slot is a **fallback**, not the day-to-day unlock.
- `/data` unlocks **post-boot** (not the OS) → no initramfs required, just a systemd service.
- No automatic destruction: slot addition is explicit, keys live in tmpfs and are `shred`-ed.

## To do

- Integration into the **orchestrated boot** on the target (try auto-unlock, otherwise prompt SelfRecover).
- **Production** Argon2id parameters (`memory_cost` ↑ to target ~0.5–1 s/attempt).

AGPL-3.0-or-later · part of the [MySelf](https://my-self.fr) ecosystem
