# Architecture

## Registration flow

```
┌─────────┐                    ┌─────────┐                   ┌─────────┐
│  User   │                    │ Browser │                   │ Server  │
└────┬────┘                    └────┬────┘                   └────┬────┘
     │                              │                             │
     │  Type recovery word "bob"    │                             │
     │─────────────────────────────>│                             │
     │                              │  GET /salt                  │
     │                              │────────────────────────────>│
     │                              │<────────────────────────────│
     │                              │  {salt: "aece..."}          │
     │                              │                             │
     │                              │  HMAC-SHA256                │
     │                              │  ("bob", hostname+salt)     │
     │                              │  ─> derived_key             │
     │                              │                             │
     │                              │  POST /register             │
     │                              │  { derived_key, username,   │
     │                              │    identifier, password }   │
     │                              │────────────────────────────>│
     │                              │                             │
     │                              │                bcrypt(derived_key)
     │                              │                generate diceware passphrase
     │                              │                bcrypt(passphrase)
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
Browser computes HMAC-SHA256(word, hostname+salt)
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
2. **The server never stores the raw word.** It only stores a bcrypt hash of the derived key.
3. **Domain-specific.** A phishing site like `arc-pve-hub-fake.com` will compute a completely different derived key. A captured recovery word is useless on any other domain.
4. **Zero SMTP.** No email addresses involved, anywhere.
5. **Zero third-party.** The user only trusts the site they're registering on.
6. **Split knowledge.** Recovery word alone = nothing. Algorithm alone = nothing. Only the combination proves identity.

## Why HMAC and not plain hash ?

HMAC's keyed construction gives us **anti-phishing for free**. If we used `SHA256(word + salt)`, a phishing site could use the same salt and capture useful hashes. With HMAC, the *domain is part of the key material*, so a different domain produces cryptographically different outputs even with the same word and salt.

## Rate limiting and anti-abuse

Not covered in detail in this diagram, but essential in production:

- Per-username rate limits (L1: 3 attempts/15min → 1h block → 3 blocks → ejected to L2)
- Per-identifier rate limits (L2: 3 attempts total → ejected to L3)
- Cooldown between L3 attempts (1h)
- Honeypot field (hidden via CSS) to trap naive bots
- Timing check (form submitted in < 2s = bot)
- Fingerprint tracking for cross-account patterns

All these are documented in the [full whitepaper](whitepaper-en.md).
