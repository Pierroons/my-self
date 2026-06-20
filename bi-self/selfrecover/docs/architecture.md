# Architecture

## Registration flow

```
┌─────────┐                    ┌─────────┐                   ┌─────────┐
│  User   │                    │ Browser │                   │ Server  │
└────┬────┘                    └────┬────┘                   └────┬────┘
     │                              │                             │
     │  Type recovery word          │                             │
     │─────────────────────────────>│                             │
     │                              │  generate user_salt         │
     │                              │  (client-side, random)      │
     │                              │                             │
     │                              │  HMAC-SHA256                │
     │                              │  (service_label‖user_salt,  │
     │                              │   word) ─> derived_key      │
     │                              │                             │
     │                              │  POST /register             │
     │                              │  { derived_key, user_salt,  │
     │                              │    username, identifier,    │
     │                              │    password }               │
     │                              │────────────────────────────>│
     │                              │                             │
     │                              │                Argon2id(derived_key)
     │                              │                generate diceware passphrase
     │                              │                Argon2id(passphrase)
     │                              │                INSERT users
     │                              │                             │
     │                              │<────────────────────────────│
     │                              │  { passphrase: "..." }      │
     │  Display passphrase once     │                             │
     │<─────────────────────────────│                             │
     │                              │                             │
```

## Recovery L2 flow (passphrase lost)

```
User types identifier + recovery word
              │
              ▼
Browser: GET /user-salt?identifier=…  (anti-enumeration: decoy salt if unknown)
              │
              ▼
Browser computes HMAC-SHA256(service_label‖user_salt, word)
              │
              ▼
POST /recover-l2 { identifier, recovery_key (derived) }
              │
              ▼
Server: SELECT user WHERE identifier = ?
              │
              ▼
Server: password_verify(recovery_key, stored_hash)
              │
              ├── OK ──> Generate new password, update user
              │          Return new password to browser
              │
              └── FAIL ─> Increment L2 attempts counter
                         If >= 3 → redirect to Level 3
```

## Key properties

1. **The raw recovery word never leaves the browser.** Only the HMAC derivation is sent over the wire.
2. **The server never stores the raw word.** It only stores an Argon2id hash of the derived key.
3. **Service-specific.** The derivation is keyed by a stable service label, so a passive clone that copies the page derives a different key. (A captured *raw* word reused elsewhere stays reusable — the label is public; the binding stops hash correlation across services, not secret reuse.)
4. **Zero SMTP.** No email addresses involved, anywhere.
5. **Zero third-party.** The user only trusts the site they're registering on.
6. **Split knowledge.** Recovery word alone = nothing. Algorithm alone = nothing. Only the combination proves identity.

## Why HMAC and not plain hash ?

HMAC's keyed construction lets us fold a **stable service label + per-user salt** into the key material, so the same word yields a different key per service and per account. This stops cross-service correlation of stored hashes and shared rainbow tables — it is not, by itself, anti-phishing (an active clone that controls its page can reproduce the key).

## Rate limiting and anti-abuse

Not covered in detail in this diagram, but essential in production:

- Per-username rate limits (L1: 3 attempts/15min → 1h block → 3 blocks → ejected to L2)
- Per-identifier rate limits (L2: 3 attempts total → ejected to L3)
- Cooldown between L3 attempts (1h)
- Honeypot field (hidden via CSS) to trap unsophisticated bots
- Timing check (form submitted in < 2s = bot)
- Fingerprint tracking for cross-account patterns

All these are documented in the [full whitepaper](whitepaper-en.md).
