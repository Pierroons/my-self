# MySelf-Live — Build skeleton

> **Status** : pre-alpha skeleton. No working ISO yet.
> **Target** : V0.2 alpha for SelfRecover ceremony use cases (été 2026).

A minimal, signed, verifiable Linux distribution dedicated to **SelfRecover ceremonies** for sensitive secrets : root server passphrase, master key, cofounder identity, regalian account.

Inspired by **Tails**, **Qubes OS**, **Whonix**, **Heads**. Differs from Tails by its explicit cryptographic-ceremony focus and its tighter scope (no anonymous web browsing, no general-purpose Tor routing).

---

## Goals

| Property | Implementation target |
|---|---|
| **RAM-only**, no persistence | `live-build` with `LB_FILESYSTEM=overlay`, ramfs root |
| **Reproducible builds** | Pinned Debian snapshot date, locked package hashes |
| **GPG signed** | Offline root key on YubiKey/smartcard, monthly subkeys |
| **Multi-channel distribution** | HTTPS (my-self.fr) + IPFS + torrent + GitHub releases |
| **UEFI Secure Boot** | shim Microsoft-signed → grub2 → kernel signé MySelf |
| **Pre-installed for ceremonies** | SelfRecover daemon (localhost), Tor, Firefox ESR hardened, EFF PDF embedded, dice animation, PGP/sigstore tooling |
| **Network OFF by default** | systemd unit `disable-net.service` runs at boot, user must opt-in |
| **No Wi-Fi/Bluetooth drivers** | drivers stripped at build time, true air-gap by default |
| **Image size** | target ~500 MB |
| **Languages** | EN + FR initially (V0.2), DE + ES planned (V0.3) |

---

## Tooling — what we've decided to use

| Stage | Tool | Reason |
|---|---|---|
| Base bootstrap | **`debootstrap`** | Industry standard, reproducible, well-audited |
| Live build orchestration | **`live-build`** (Debian) | Same toolchain Tails uses since 2009 |
| ISO generation | **`xorriso`** | Hybrid ISO with UEFI + BIOS support |
| Signing | **`sequoia-pgp`** or **`sigstore` cosign** | Modern, audited, AGPL-friendly |
| Verification | **`reprotest`** | Reproducible build CI |
| Hardware integration | **`shim` Microsoft-signed → `grub2-efi-amd64-signed`** | UEFI Secure Boot acceptance everywhere |

---

## Skeleton tree

```
tools/build-myself-live/
├── README.md                  ← this file
├── build.sh                   ← orchestrator (TODOs only)
├── config/
│   ├── package-list-base.txt   ← minimal Debian package selection
│   ├── package-list-extra.txt  ← Tor, Firefox ESR, SelfRecover deps
│   ├── package-list-block.txt  ← drivers and tools EXPLICITLY excluded
│   ├── boot-grub.cfg.template  ← grub config template
│   └── kernel-cmdline.txt      ← kernel command line (lockdown=integrity, etc.)
├── scripts/
│   ├── 00-prepare-host.sh      ← install live-build, debootstrap, etc.
│   ├── 10-debootstrap.sh       ← bootstrap minimal Debian rootfs
│   ├── 20-install-packages.sh  ← apt install from package-list-*.txt
│   ├── 30-embed-selfrecover.sh ← copy SelfRecover repo + EFF PDFs
│   ├── 40-harden-kernel.sh     ← lockdown mode, no module loading
│   ├── 50-disable-network.sh   ← systemd unit + iptables drop-all by default
│   ├── 60-build-iso.sh         ← xorriso → myself-live-VERSION.iso
│   ├── 70-sign-iso.sh          ← GPG/cosign signature offline
│   └── 80-verify-iso.sh        ← reprotest reproducibility check
└── signing/
    ├── README.md               ← key management protocol
    ├── pubkey-myself-root.asc  ← (placeholder, real key managed offline)
    └── trusted-signers.txt     ← web-of-trust co-signers list
```

---

## Boot chain (simplified)

```
UEFI firmware
  └── shim (Microsoft-signed)
       └── grub2-efi (MySelf-signed)
            └── linux-image-amd64 (MySelf-signed, lockdown mode)
                 └── initramfs (squashfs verified by GPG signature embedded in initrd)
                      └── live system in tmpfs
                           ├── systemd boot — disable-net.service runs first
                           ├── desktop : minimal Xfce or i3wm
                           ├── apps : SelfRecover daemon, Firefox ESR, Tor, dice tool
                           └── all writes go to tmpfs (RAM) — wiped on reboot
```

---

## Threat model — what MySelf-Live protects against (and what it doesn't)

### Covered
- Compromised host OS (you boot from USB, your normal OS is irrelevant)
- Persistent malware in firmware **with caveats** (UEFI Secure Boot helps if BIOS is clean)
- Network surveillance during ceremony (network OFF by default)
- Forensic recovery of typed passphrase (RAM-only, wipe on reboot)
- Tampered ISO during distribution (signed image, multi-channel verification)

### Out of scope
- Hardware keylogger between keyboard and motherboard (use a known-clean keyboard)
- Compromised UEFI/BIOS firmware (use Heads + Coreboot for that level — not our scope)
- Dies a-side-channel attacks on the dice themselves (use certified dice for high-stakes ceremonies)
- Coercion attacks (no plausible deniability)

---

## Roadmap (implementation milestones)

| Milestone | Status | ETA |
|---|---|---|
| **M0 — Skeleton** (this README + dirs + script stubs) | ✅ done | 4 mai 2026 |
| **M1 — Bootable ISO** (Debian Live + minimal Xfce, no MySelf customization) | ⏳ pending | mai-juin 2026 |
| **M2 — SelfRecover embedded** (PDF EFF + daemon localhost + dice tool) | ⏳ pending | juin 2026 |
| **M3 — Network-off-by-default** (systemd unit + tested) | ⏳ pending | juin 2026 |
| **M4 — Reproducible build** (`reprotest` green) | ⏳ pending | juillet 2026 |
| **M5 — GPG signed ISO** (offline root key + cosign) | ⏳ pending | juillet 2026 |
| **M6 — UEFI Secure Boot signed** (shim chain works on common hardware) | ⏳ pending | août 2026 |
| **M7 — Multi-channel distribution** (my-self.fr + IPFS + torrent + GitHub release) | ⏳ pending | août 2026 |
| **V0.2 alpha release** | ⏳ pending | fin été 2026 |

---

## How to contribute

This skeleton is **open for early contributions**. The roadmap is realistic but ambitious — collaborators welcome on any of M1–M7.

To contribute :

1. Pick a milestone you want to tackle
2. Open an issue on `Pierroons/my-self` describing your approach
3. Fork + PR
4. All commits must be GPG-signed, all ISOs must be reproducible

---

## License

AGPL-3.0-or-later (consistent with the entire MySelf ecosystem).

The build scripts, config files, and documentation are AGPL. The packaged Debian system inherits its respective licenses (GPL kernel, etc.) — see `signing/trusted-signers.txt` for the chain of upstream signatures.

---

*MySelf-Live — because cryptographic ceremonies deserve their own dedicated, verifiable, ephemeral OS.*
