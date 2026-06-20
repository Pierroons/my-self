# Threat model

> Extracted from the v1.1 whitepaper. Read the [full version](whitepaper-en.md) for context.

## Threats SelfRecover protects against

### ✓ Passive phishing
The derivation is bound to a stable service label. A passive phishing clone that copies the page without adapting its code derives a useless key. The honest limit: an **active** phishing site that controls its own page (hard-coding the right label, fetching the public per-user salt) can reproduce the real key — out of scope, as for any in-browser protocol.

### ✓ Email account takeover
The entire industry standard "reset password via email" chain is eliminated. If your Gmail gets hacked, your SelfRecover-based accounts are not automatically compromised — there's no email link to click.

### ✓ SMTP provider failures / deliverability issues
No SMTP at all. No SendGrid, no Mailgun, no Gmail deliverability rules, no spam folder. The recovery flow is entirely client ↔ site.

### ✓ Third-party dependencies
You don't need to trust Google, Microsoft, or anyone else for account recovery. You only trust the site you're registering on.

### ✓ Rate-limited brute force
Per-username rate limits + L2/L3 escalation make brute-force infeasible.

### ✓ Bot-driven account enumeration
Anti-timing, honeypot fields, and forced delays on L3 init make automated probing very expensive.

---

## Threats SelfRecover does NOT protect against

### ✗ CRITICAL — Server root access (sudo)

**This is the single most important limitation.** If an attacker obtains root access to the server hosting SelfRecover, the entire protocol is bypassed. Root can:
- Read the database and all hashes
- Replace the `password_hash` column directly
- Modify the code itself
- Extract the server secret and per-user salts

**Mandatory deployment rule:**
- Remove `NOPASSWD` from sudoers on installation
- Enforce a strong diceware passphrase (6+ words minimum) for sudo
- SSH key-based auth only, no password login

A SelfRecover deployment without hardened sudo is a lock on a door with no wall.

### ✗ The recovery word is a master key

**If the recovery word is compromised** (social engineering, written down, shoulder surfing, malware), and the attacker also knows the public identifier (which is often published, like an in-game ID), they can recover the account via L2.

- The per-service derivation prevents correlation of *stored hashes* across services — but a known raw word that you reuse stays reusable elsewhere (the service label is public). Derivation does not save a reused secret.
- Rate limiting and L2→L3 escalation slow down brute-force
- **But fundamentally:** no system can protect against a stolen secret. A leaked SSH private key gives server access. A leaked seed phrase empties a wallet. A leaked recovery word opens the account. The security model is identical.

**A protected secret stays safe; a neglected one is exposed.** This is not a flaw — it is the fundamental contract of any secret-based security system.

### ✗ User negligence
- Writing the recovery word on a sticky note visible on the monitor
- Sharing it in a chat or email "for convenience"
- Using the same recovery word on a rogue site that then replays it elsewhere

The per-service derivation does **not** save you from reusing a secret on a rogue site — only unique secrets do. It does prevent cross-service correlation of stored hashes.

### ✗ Database breaches — partially protected
- Raw database leak → attacker only gets Argon2id hashes, which resist cracking
- BUT: if the attacker has root (see above), the protocol is already moot
- Recommendation: encrypt database backups at rest

### ✗ Lost everything
If a user forgets their password AND their passphrase AND their recovery word, the human-reviewed L3 is the only fallback. There is no SMTP-based "magic link" because SelfRecover rejects that model entirely. This is intentional — a system with infinite fallbacks has infinite attack surface.

---

## Summary table

| Threat | Protected ? | Mitigation |
|--------|:---:|---|
| Passive phishing | ✓ (partial) | HMAC per service; active phishing out of scope |
| Email account takeover | ✓ | No email used |
| SMTP failures | ✓ | No SMTP |
| Third-party trust | ✓ | Local only |
| Brute force recovery word | ✓ | Rate limits + L2/L3 escalation |
| Bot enumeration | ✓ | Honeypot + timing + forced delays |
| Server root compromise | ✗ | Mandatory sudo hardening |
| Stolen recovery word | ✗ | User responsibility |
| User negligence | ✗ | Reused secrets stay reusable; only unique secrets help |
| Database breach | ✓ (partial) | Argon2id hashes, but root trumps all |
| Lost everything | ✗ | Admin fallback only |
