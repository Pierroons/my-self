# Self-Security

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Digital and physical protection.**

> *Force me and you get nothing.*

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../LICENSE)
[![SelfGuard: alpha 0.0.1](https://img.shields.io/badge/SelfGuard-alpha%200.0.1-lightgrey.svg)](./selfguard/)
[![SelfKeyGuard: alpha 0.0.1](https://img.shields.io/badge/SelfKeyGuard-alpha%200.0.1-lightgrey.svg)](./selfkeyguard/)
[![Part of: MySelf](https://img.shields.io/badge/part%20of-MySelf-blue.svg)](../README.md)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

---

## The tension it addresses

Security products today defend against a single attacker model: **the remote attacker who doesn't have you in front of them**. They assume you're willing to hand over the key, just slowly. This model is broken in two directions:

1. **Digital coercion is real.** An officer, a burglar, or an abusive partner can force you to unlock your phone, your laptop, your cloud. "Enter your PIN or I break your fingers" has no technical answer if your data exists in a directly accessible form. Biometrics make it worse — they eliminate plausible deniability.
2. **Physical objects still use 1970s security.** Your car starts with a piece of stamped metal or a cloned NFC key. Your motorcycle is gone in 90 seconds. The correlation between "I have the key" and "I am the owner" no longer holds.

Self-Security addresses both dimensions with the same principle: **the default state is locked, presence is required to unlock, coercion yields nothing**.

---

## Why the two modules reinforce each other

**SelfGuard alone** is a data vault with duress protection. Good, but your phone is still a digital object — what about the physical world? Your car, your scooter, your house?

**SelfKeyGuard alone** is hardware 2FA for objects. Good, but the keys that authenticate those objects still live somewhere — on your phone, in your drawer — vulnerable to the same coercion attacks.

**Together**, the security perimeter closes:

- SelfGuard stores the keys (to cars, houses, objects) in a storage that self-destructs under coercion (duress passphrase, panic button).
- SelfKeyGuard uses those keys to authenticate physical objects, with **nothing persistent on the object itself** — the object checks for a proof of presence that only SelfGuard can produce.
- Forcing you to unlock SelfGuard destroys the keys. The object can no longer be authenticated. The attacker gets a brick.

One module protects data. The other protects objects. The coercion resistance is the same: **under stress, the system destroys itself, not betrays its owner**.

---

## Cross-module workflows

- **Traffic stop, phone seized** → SelfGuard asked for passphrase. Owner enters duress passphrase. Visible data = decoy profile (a few photos, mainstream apps). Real data + car key = wiped. Officer gets nothing beyond a normal-looking phone.
- **Burglar at home with phone** → Same mechanism. Safe opening codes, crypto keys, SelfKeyGuard auth tokens = destroyed. Safe remains closed, car doesn't start.
- **Lost phone, not stolen** → Normal unlock = everything intact. Passive finder gets zero data because the phone is locked normally. No difference in visible UX, massive difference in coercion resistance.
- **Car theft attempt** → Keyless entry defeated via relay attack? Doesn't matter, SelfKeyGuard requires live proof-of-presence from SelfGuard. No SelfGuard available = car won't start even if the door opens.

---

## Modules in this bundle

| Module | Role | Status |
|--------|------|--------|
| [SelfGuard](./selfguard/) | Data vault with guaranteed destruction under coercion | alpha 0.0.1 — concept phase |
| [SelfKeyGuard](./selfkeyguard/) | Hardware 2FA for physical objects (car, motorcycle, home) | alpha 0.0.1 — concept phase |

---

## Status

Both modules are in **concept phase** (alpha 0.0.1). The whitepapers define the threat models, the cryptographic design, and the hardware requirements. SelfKeyGuard is particularly concrete: a ~14 € ESP32 prototype can secure a motorcycle ignition with hardware 2FA, tested and documented.

Production deployment is planned after an independent security audit and a physical-world trial period on the author's own vehicles. This is security-critical code and hardware; speed is not a virtue here.

---

## Author

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*Self-Security — The only password worth having is the one that destroys itself.*
