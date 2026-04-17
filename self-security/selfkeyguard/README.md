# SelfKeyGuard

**Hardware 2FA for physical objects — cars, motorcycles, homes, anything with a lock.**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](../../LICENSE)
[![Status: alpha 0.0.1](https://img.shields.io/badge/status-alpha%200.0.1-lightgrey.svg)](#status)
[![Part of: Self-Security](https://img.shields.io/badge/part%20of-Self--Security-blue.svg)](../README.md)
[![Companion of: SelfGuard](https://img.shields.io/badge/companion-SelfGuard-green.svg)](../selfguard/)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

> **The key alone is not enough. Presence is.**

---

## The problem

Physical objects are still protected by **possession-based** authentication: whoever holds the key, the card, the fob, is the owner. This model has been broken for decades:

- **Cars** with keyless entry are stolen in 90 seconds via relay attack (amplifier in the pocket, accomplice at the car door).
- **Motorcycles** are opened with a screwdriver on a 15-year-old cylinder design.
- **Homes** have a handful of cylinder patterns and electronic keypads with shared keys.
- **NFC badges** (bike rentals, fitness centers, cars) are cloned from distance with a 30 € reader.

The root cause is that the secret to unlock = a single piece of information that **travels physically** with the user. Stealing the carrier steals the right.

SelfKeyGuard introduces **cryptographic 2FA** to physical objects, with a hardware prototype cheap enough (~14 €) to retrofit any existing lock.

---

## Core principle

Instead of "this key opens this lock", SelfKeyGuard requires **two factors** to unlock:

1. **Possession**: a physical presence signal (NFC badge, Bluetooth device, or short-range radio with a rolling code).
2. **Live proof from SelfGuard**: the paired phone running SelfGuard must emit a **live, non-replayable cryptographic challenge response**.

Neither factor alone works. A stolen badge is useless without the phone. A cloned phone is useless without the badge. A recorded exchange is useless because the challenge rolls.

```
Object wakes (user approaches)
    ↓
Object sends random challenge C to paired phone
    ↓
SelfGuard (on phone) computes R = HMAC-SHA256(stored_shared_key, C || timestamp)
    ↓
Object verifies R and checks timestamp drift (< 30 s)
    ↓
If both OK + physical badge present: unlock
```

Under duress, the user activates SelfGuard's duress passphrase. The shared_key is wiped. The object can no longer receive a valid R. It remains locked. The attacker has the phone and the badge — and a brick.

---

## Hardware reference

The prototype is an **ESP32** module (~14 €) + a micro-relay for the car's original immobilizer circuit:

| Component | Role | Cost |
|-----------|------|------|
| ESP32-S3 | Radio + crypto engine | ~8 € |
| MAX485 | RS485 to CAN bridge (car integration) | ~2 € |
| Micro-relay (5V, 10A) | Cut/enable ignition or unlock line | ~3 € |
| NFC module (optional) | Physical badge verification | ~4 € |
| Enclosure + wiring | — | ~3 € |

Total: **~14-20 €** depending on options. Open firmware, auditable hardware, reusable for motorcycles, home locks, safes, storage boxes.

---

## Typical setup (motorcycle)

1. Install ESP32 + relay on the ignition circuit (2 wires cut, 2 wires to relay).
2. Pair phone with SelfKeyGuard via QR code + shared secret in SelfGuard.
3. Configure: NFC badge (optional) + phone presence required.
4. Done. The motorcycle only starts when badge + phone (with live SelfGuard response) are within a few meters.

Stolen motorcycle on a truck 100 km away? The ESP32 won't validate — no phone in range, no key response. The ignition stays dead. Cheap thieves give up. Professional thieves with time can physically bypass the ignition, but at the cost of destroying the bike's electrical system — raising the cost of the theft significantly.

---

## Role in Self-Security

SelfKeyGuard is **the hardware arm** of Self-Security: it extends the coercion-resistance of SelfGuard into the physical world. Without SelfGuard, SelfKeyGuard's shared keys would be stored in a normal phone vault — compromised by coercion, gone. With SelfGuard, coercion destroys the shared keys, and the car / motorcycle / home becomes useless to the attacker.

---

## Status

**alpha 0.0.1 — prototype phase.**

- [x] Protocol design
- [x] Hardware bill of materials
- [x] ESP32 reference firmware (motorcycle use case, tested on author's bike)
- [ ] Phone companion app (iOS + Android)
- [ ] SelfGuard integration API
- [ ] Formal security audit
- [ ] Installation guides for common targets (Peugeot, Yamaha, Bosch locks)
- [ ] CE certification for retail sale (long-term)

See **[whitepaper](docs/whitepaper.docx)** for the full hardware spec, cryptographic protocol, installation guide, and attack analysis.

---

## Author

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*SelfKeyGuard — Your car starts only because your phone says so.*
