# SelfGuard

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Data vault with guaranteed destruction under coercion.**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: alpha 0.0.1](https://img.shields.io/badge/status-alpha%200.0.1-lightgrey.svg)](#status)
[![Part of: Self-Security](https://img.shields.io/badge/part%20of-Self--Security-blue.svg)](../README.md)
[![Companion of: SelfKeyGuard](https://img.shields.io/badge/companion-SelfKeyGuard-green.svg)](../selfkeyguard/)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

> **Force me and you get nothing. Not me — nothing.**

---

## The problem

Every encrypted storage product today (Signal, Bitwarden, KeePass, VeraCrypt, iOS data protection) answers the same threat model: **the attacker is remote, the device is in your pocket, the secret is only in your head**. Biometrics + PIN unlock = security.

That model breaks the moment you're **in front of the attacker**. An officer asking for your phone. A burglar with a knife. An abusive partner. A corrupt customs agent. Three real scenarios where **unlocking is the wrong answer** but there is no technical option for "destroy everything now".

Current tools either comply (give the attacker everything) or refuse (you get beaten). SelfGuard picks a third path: **the vault destroys itself before handing anything over**, and does it in a way the attacker can't distinguish from normal unlock.

---

## Core principle: two passphrases, one interface

SelfGuard holds two parallel data universes:

- **Normal passphrase** → unlocks real data (photos, messages, crypto keys, SelfKeyGuard auth tokens, everything).
- **Duress passphrase** → unlocks a **decoy profile** (a few innocent photos, some apps, a fake message history). Simultaneously: **all real data and its keys are wiped, irreversibly**.

From the attacker's point of view, entering the duress passphrase shows a phone with content. No red flag, no panic, no UI difference. From the user's point of view, their real secrets just disappeared forever.

The UX is identical to a regular unlock. That's the whole point: **plausible compliance, guaranteed destruction**.

---

## Design constraints

- **Atomic destruction**: the wipe is performed before the decoy profile appears. No window of recovery, no partial state.
- **No biometrics** for unlock: biometrics eliminate plausible deniability (a court can compel a fingerprint, not a passphrase in your head). Biometrics can gate the decoy profile, not the real one.
- **Storage layer is independent**: SelfGuard is a daemon sitting above a normal storage (SQLite, filesystem). It manages the crypto envelope and the duress logic. Host OS integration is optional — Linux, Android LineageOS, and PostmarketOS are target platforms.
- **Open audit**: every byte of the wipe path is auditable. No blob, no hidden side-effects.

---

## Cryptographic skeleton

```
master_key = argon2id(passphrase, salt, t=3, m=64MB, p=4)
master_key_duress = argon2id(duress_passphrase, salt, ...)

real_data = AEAD(AES-256-GCM, master_key, payload)
decoy_data = AEAD(AES-256-GCM, master_key_duress, decoy_payload)

on unlock(input):
    if argon2id(input, salt) == stored_key_hash: unlock_real()
    elif argon2id(input, salt) == stored_key_hash_duress:
        wipe_real_data_securely()  # atomic, no journal recovery
        unlock_decoy()
    else: deny()
```

Details on secure wipe (DoD 5220.22-M variant for flash, TRIM-aware, with hardware-backed key destruction on supported platforms) in the whitepaper.

---

## Role in Self-Security

SelfGuard is the **coercion-resistant storage layer**. On top of it, [SelfKeyGuard](../selfkeyguard/) builds hardware authentication tokens for physical objects. Both modules assume the threat model "the attacker is in front of me" — coordinated, they form a perimeter where **forcing the user yields nothing but decoy**.

Without SelfGuard, SelfKeyGuard's auth keys live in a normal phone vault — compromising by coercion returns the key. With SelfGuard, coercion destroys the keys along with the rest.

---

## Status

**alpha 0.0.1 — concept phase.**

- [x] Threat model draft
- [x] Cryptographic skeleton
- [ ] Reference implementation (Linux daemon, SQLite backend)
- [ ] Android integration (LineageOS module)
- [ ] Security audit (independent, required before v0.1.0)
- [ ] UX study for duress passphrase training

See **[whitepaper](docs/whitepaper.docx)** for the full threat model, wipe-path analysis, and hardware-backed key destruction specifics.

---

## Author

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*SelfGuard — The only vault that obeys you, not the one holding the knife.*
