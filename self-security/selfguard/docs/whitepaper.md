---
title: "SelfGuard — Whitepaper"
subtitle: "Data vault with guaranteed destruction under coercion"
author: "Pierroons — MySelf ecosystem"
date: "April 2026"
version: "alpha 0.0.1"
---

# Executive summary

SelfGuard is a **coercion-resistant storage daemon** that solves a threat modern cryptographic storage does not: the *attacker in front of you*. By maintaining two parallel data universes gated by two distinct passphrases (normal and duress), SelfGuard guarantees that under physical coercion the user can surrender access to a decoy profile while **the real data is atomically, irreversibly destroyed**.

The decoy profile is indistinguishable from a genuine unlock from the attacker's perspective. The user's real secrets are gone. The attacker gets a functional-looking vault containing nothing of value.

# 1. Threat model

## 1.1 Attackers covered

- **Customs / police / border controls** asking to unlock a device.
- **Burglars / muggers** demanding the phone passphrase on the street.
- **Abusive partners / family members** with physical access and insistence.
- **Corrupt corporate security** seizing devices during an investigation.

## 1.2 Key assumption

The attacker is **time-constrained**: they want access **now**, not a multi-day forensics lab session. Plausible compliance (the user "unlocks" the device and it shows content) is enough to stop the coercion. Forensic recovery beyond that window is out of scope for this threat.

## 1.3 Out of scope

- Nation-state actors with unlimited lab time.
- Pre-installed malware / compromised boot chain.
- Pre-existing backups of the data held by third parties.

# 2. Design principles

## 2.1 Two passphrases, one interface

```
input = user_passphrase
on unlock(input):
    match = argon2id_verify(input, salt_normal, hash_normal)
    if match:
        mount_real_data()
        return

    match_duress = argon2id_verify(input, salt_duress, hash_duress)
    if match_duress:
        atomic_wipe(real_data_blob, real_key_material)
        mount_decoy_data()
        return

    deny()  # with fake delay to prevent timing attacks
```

The duress path is **always** executed on a successful duress match — there is no configuration option to disable it, no "safe mode". This prevents the attacker from finding a way to bypass destruction through UI manipulation.

## 2.2 Atomic destruction

Wipe happens **before** the decoy UI appears. Three layers of destruction:

1. **Overwrite** the ciphertext blob with random data (TRIM-aware on SSD / flash).
2. **Destroy** the KEK (key encryption key) held in hardware secure storage (Secure Enclave, TPM, StrongBox, or a software-backed equivalent).
3. **Purge** filesystem journals and WAL to prevent forensic recovery of intermediate states.

Target: complete wipe in < 500 ms on a modern phone. The user experience shows a normal unlock animation during this window — no visible pause, no "destruction in progress" indicator that the attacker could notice.

## 2.3 No biometrics for real unlock

Biometric authentication (fingerprint, face) is **legally compellable** in many jurisdictions (US, UK partially, France under garde à vue). A passphrase in the head is not. SelfGuard's real data therefore requires a passphrase.

Biometrics can unlock the **decoy profile** for fast casual use — the user taps fingerprint, sees a normal phone, nobody suspects a dual vault.

## 2.4 Independent from host OS

SelfGuard is a daemon operating above a standard storage layer (SQLite or filesystem). It does not require kernel modifications, does not replace the OS's keychain. This enables deployment on:

- **Linux desktop / laptop** (Debian, Ubuntu, Arch)
- **Android** (via LineageOS module, no root required on supported builds)
- **PostmarketOS** (phone-grade Linux)
- **Server** (for selfhosted applications that need coercion-resistant secrets)

# 3. Cryptographic specification

## 3.1 Primitives

| Role | Algorithm | Parameters |
|------|-----------|------------|
| KDF (passphrase → master key) | Argon2id | t=3, m=64 MB, p=4, 32-byte output |
| Envelope encryption | AES-256-GCM (AEAD) | 96-bit nonce, 128-bit tag |
| Per-file nonces | ChaCha20 counter | Reset per mount |
| Hardware-backed KEK | Secure Enclave / TPM / StrongBox | Fallback: software KDF with cost = 1 s |

## 3.2 Storage layout

```
vault/
├── manifest.json        (salts, hash versions, wipe rules — NOT secret)
├── blob_normal.enc      (AEAD ciphertext of real data)
├── blob_duress.enc      (AEAD ciphertext of decoy data)
└── wipe_marker          (present iff duress path executed)
```

The manifest is world-readable by design. Leaking it reveals only that a SelfGuard vault exists, not its content.

## 3.3 Wipe path

```python
def atomic_wipe(blob_path, kek_handle):
    # 1. Destroy the hardware KEK — ciphertext becomes permanently unreadable
    hardware.destroy_key(kek_handle)

    # 2. Overwrite ciphertext with random data (3 passes, TRIM-aware)
    with open(blob_path, "r+b") as f:
        size = f.seek(0, 2)
        for _ in range(3):
            f.seek(0)
            f.write(os.urandom(size))
            f.flush()
            os.fsync(f.fileno())

    # 3. Issue fstrim on the containing filesystem
    subprocess.run(["fstrim", mount_point], check=True)

    # 4. Mark the vault as wiped (for statistics / forensics transparency)
    open(wipe_marker_path, "w").close()
```

The hardware key destruction (step 1) is sufficient by itself: without the KEK, the ciphertext is an opaque blob. Steps 2-3 provide defense in depth.

# 4. Security properties

| Property | Mechanism |
|----------|-----------|
| **Coercion resistance** | Duress passphrase triggers atomic wipe before decoy mount |
| **Plausible deniability** | Decoy profile UI is indistinguishable from real unlock |
| **No biometric compellability** | Real data requires passphrase; biometrics only gate decoy |
| **Forensic resistance** | Hardware KEK destruction + overwrite + TRIM |
| **Rate-limit bypass resistance** | Argon2id cost ≥ 250 ms per attempt; 5-attempt lockout |
| **Side-channel resistance (timing)** | Both unlock paths execute in constant time; fake delay on wrong passphrase |

# 5. Integration with SelfKeyGuard

SelfKeyGuard stores per-object shared keys (for car / motorcycle / home 2FA) inside SelfGuard's real vault. On duress → SelfGuard wipes → SelfKeyGuard loses its shared keys → physical objects reject the authentication challenge → remain locked.

This is the full Self-Security chain: coercing the user destroys both digital data and the ability to authenticate physical objects.

# 6. Roadmap

**v0.1.0** — Linux daemon reference implementation, SQLite backend, CLI UX, formal security audit.

**v0.2.0** — Android (LineageOS module), iOS (impossible without jailbreak, so iOS-compatible via a userspace signed package).

**v0.3.0** — Integration with common password managers (Bitwarden, KeePassXC) as a replacement KEK layer.

**v1.0.0** — Production-stable, audited, packaged for major Linux distros.

# 7. Ethical & legal considerations

SelfGuard is **not** a tool to evade law enforcement in contexts where cooperation is legally required and proportionate (e.g. a court-ordered search with a warrant specific to stored content). It is a tool to protect against **disproportionate, coerced, or unlawful access attempts** — the bulk of real-world coercion.

The open-source nature of SelfGuard ensures that any court, any auditor, any curious user can verify exactly what happens during a duress unlock. Transparency is the guarantee of legitimacy.

# 8. References

- Argon2id spec: RFC 9106
- Encryption AEAD: RFC 5116 (AES-GCM), RFC 8439 (ChaCha20-Poly1305)
- Forensic considerations: Carrier, *File System Forensic Analysis*, 2005
- Duress-aware design: Katz & Lindell, *Introduction to Modern Cryptography*, Ch. 14
- SelfKeyGuard integration: https://github.com/Pierroons/my-self/tree/master/self-security/selfkeyguard
