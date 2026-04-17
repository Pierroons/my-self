---
title: "SelfKeyGuard — Whitepaper"
subtitle: "Hardware 2FA for physical objects — open protocol, ~14 € BOM"
author: "Pierroons — MySelf ecosystem"
date: "April 2026"
version: "alpha 0.0.1"
---

# Executive summary

SelfKeyGuard introduces **two-factor authentication to physical objects** (cars, motorcycles, homes, safes, storage boxes) using a cheap ESP32-based module and a paired phone running [SelfGuard](../selfguard/). A physical possession token (optional NFC badge) combined with a live cryptographic challenge-response from the paired phone is required to unlock or start the object.

No object-side persistent secret. No cloud. No vendor. Under user coercion, SelfGuard wipes its shared keys and the object rejects all subsequent authentications — yielding a brick to the attacker.

# 1. Why now

Physical-object security has not kept up with the threat environment:

- **Keyless entry** on cars is broken by relay attacks (~€300 hardware, < 90 s to steal a car).
- **NFC cloning** on garage fobs, rental bikes, office badges (~€30 reader).
- **Mechanical picking** on residential locks (YouTube tutorials, 15 seconds to open).
- **Smart locks** rely on proprietary cloud services (Tesla, Nuki, Assa Abloy) that can be taken down, hacked at scale, or coerced by court order.

Meanwhile, modern cryptography (HMAC, ECDSA, rolling codes) is mature, low-cost, and runs on €5 microcontrollers. The gap is not technology — it is the absence of an open standard bridging phones and physical objects.

SelfKeyGuard is that standard.

# 2. Protocol

## 2.1 Actors

- **Object**: the physical thing being protected (ESP32-based SelfKeyGuard module installed on its ignition / lock circuit).
- **Phone**: the user's phone running SelfGuard with a paired SelfKeyGuard app.
- **Badge (optional)**: an NFC tag or short-range radio token for a physical possession factor.

## 2.2 Pairing

```
Object generates a random shared_key K (256 bits) during first install.
Object displays K (via QR code or serial-connected display) to the installer.
Installer opens SelfKeyGuard app, scans QR.
SelfKeyGuard stores K inside SelfGuard's real vault.
Object stores K in its secure flash region.
```

K is never transmitted over the air after pairing. It exists in exactly two places: the object's flash and SelfGuard's coercion-resistant vault.

## 2.3 Unlock handshake

```
Trigger:  user approaches (proximity sensor, key turn, button press)
Object → Phone:  challenge C (32 random bytes) + timestamp T
Phone  →  SelfGuard.request_response(C, T)
SelfGuard:  verifies T is recent (< 30 s from phone clock)
SelfGuard:  computes R = HMAC-SHA256(K, C || T)
Phone → Object:  R
Object:  verifies R matches HMAC-SHA256(K_local, C || T)
Object:  checks badge presence (if configured)
Object:  if all OK → unlock / start ignition
```

Challenge C is random per attempt. Timestamp T defeats replay. Shared key K never travels. Badge presence gates against phone theft alone.

## 2.4 Revocation & rotation

- Losing the phone → reinstall SelfGuard on a new phone, re-enter the shared key via backup passphrase (stored in SelfGuard real vault) + object's emergency QR (printed at pairing, kept offline).
- Rotating K → physical access to the object (serial connection or button sequence) is required. Intentional: K rotation should not be possible remotely to avoid attack vectors.

## 2.5 Duress handling

If SelfGuard receives the duress passphrase, it wipes K along with all other secrets. The phone app still runs (decoy profile shows normal SelfKeyGuard UI) but every `request_response()` call returns garbage. The object rejects. No lockout warning visible to the attacker.

# 3. Hardware specification

## 3.1 Bill of materials (motorcycle use case)

| Part | Purpose | Cost |
|------|---------|------|
| ESP32-S3 WROOM | MCU, WiFi/BLE radio, crypto accelerator | €8 |
| MAX485 transceiver | RS485/CAN bus bridge (for automotive) | €2 |
| 5V/10A relay module | Ignition circuit cut / enable | €3 |
| PN532 NFC module (optional) | Physical badge verification | €4 |
| Weatherproof enclosure, wiring, fuses | — | €3 |
| **Total** | | **€20** |

Without NFC (phone-only mode): €14.

## 3.2 Firmware

Open-source firmware written in Rust (preferred) or C (ESP-IDF). Key features:

- Secure boot enabled (ESP32 eFuse, factory-flashed).
- K stored in the encrypted eFuse partition.
- OTA updates disabled by default (physical re-flash required).
- Deep-sleep between unlock attempts: < 10 µA idle.
- Hardware watchdog, brown-out detection, tamper detection (optional CR2032 backup cell).

## 3.3 Install procedure (motorcycle)

1. Identify the ignition circuit on the bike (Haynes manual or similar).
2. Cut the "start enable" wire, re-route through the relay (normally open).
3. Power the ESP32 from the 12 V accessory line (through a 5 V regulator).
4. Optional: connect CAN bus via MAX485 for richer integration.
5. First power-up generates K, displays QR on the ESP32's OLED (if equipped) or logs to serial.
6. Scan QR with SelfKeyGuard app. Pair complete.

Reversibility: removing the module and reconnecting the cut wire returns the bike to stock.

# 4. Attack analysis

| Attack | Mitigation |
|--------|-----------|
| Relay attack (amplifier) | Challenge-response with timestamp → replay impossible |
| Phone theft (no coercion) | Badge required (if configured) + SelfGuard passphrase on the phone app |
| Phone + badge theft | Requires active cooperation of user to unlock SelfGuard → same as phone unlock |
| Coerced unlock | Duress passphrase → K wiped → object bricked |
| Physical bypass of object | Possible but costly (destroy electrical system) — raises the effort above opportunistic theft |
| Sniffing the radio | No secret in the clear; only random C and HMAC R are ever exchanged |
| Firmware tampering | Secure boot + signed firmware; tamper detection shuts down the object |
| Supply chain (counterfeit module) | Open firmware → end user can re-flash from source at any time |

# 5. Integration with SelfGuard

K is stored inside SelfGuard's real-data vault (not the decoy). Under normal operation: SelfGuard returns K on demand → SelfKeyGuard computes R → object unlocks. Under duress: SelfGuard wipes the real vault → K gone → object rejects forever. The two modules form the full coercion-resistance chain described in [Self-Security](../README.md).

# 6. Roadmap

**v0.1.0 (first milestone)** — Motorcycle reference design validated on author's bike, install guide, firmware source, SelfGuard integration.

**v0.2.0** — Car integration (CAN bus, common models), NFC badge support, phone companion apps (iOS + Android).

**v0.3.0** — Residential lock module (solenoid-driven, battery-powered, mechanical fallback).

**v1.0.0** — Formal security audit, CE certification for retail sale, documented installation procedures for top-10 vehicle models.

# 7. Ethical considerations

SelfKeyGuard is a **defensive** technology. It does not alter the user's responsibility for the object (insurance, registration, lawful use). It does not bypass police access mechanisms (e.g., court-ordered tow and forensic teardown). It simply removes the current asymmetry where a €30 reader defeats a €30,000 car.

Open-source hardware + firmware + protocol ensures that no vendor can remotely disable the user's property, unlike every commercial "smart lock" on the market today.

# 8. References

- HMAC-SHA256: RFC 2104, RFC 4231
- EPC069-12 (SEPA QR, unrelated but inspiring): https://www.europeanpaymentscouncil.eu
- Tesla relay attack analysis (KU Leuven, 2019): https://www.esat.kuleuven.be/cosic/news/tesla-key-fob-attack
- ESP32 secure boot documentation: https://docs.espressif.com
- SelfGuard integration spec: https://github.com/Pierroons/my-self/tree/main/self-security/selfguard
