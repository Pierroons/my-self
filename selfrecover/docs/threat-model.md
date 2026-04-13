# Threat model

> Extracted from the v1.1 whitepaper. Read the [full version](whitepaper-en.md) for context.

## Threats SelfRecover protects against

### ✓ Phishing attacks
Anti-phishing is native. A fake site like `arc-raiders-lfg.net` (instead of the real `arc-pve-hub.com`) will compute a completely different HMAC derivation from the same recovery word. Captured credentials are useless on any other domain.

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
- Extract the `SITE_SALT`

**Mandatory deployment rule:**
- Remove `NOPASSWD` from sudoers on installation
- Enforce a strong diceware passphrase (6+ words minimum) for sudo
- SSH key-based auth only, no password login

A SelfRecover deployment without hardened sudo is a lock on a door with no wall.

### ✗ The recovery word is a master key

**If the recovery word is compromised** (social engineering, written down, shoulder surfing, malware), and the attacker also knows the public identifier (which is often published, like an in-game ID), they can recover the account via L2.

- The HMAC derivation limits the damage to a single site (the word is useless on other domains)
- Rate limiting and L2→L3 escalation slow down brute-force
- **But fundamentally:** no system can protect against a stolen secret. A leaked SSH private key gives server access. A leaked seed phrase empties a wallet. A leaked recovery word opens the account. The security model is identical.

**Be careful: no problem. Be careless: open bar.** This is not a flaw — it is the fundamental contract of any secret-based security system.

### ✗ User negligence
- Writing the recovery word on a sticky note visible on the monitor
- Sharing it in a chat or email "for convenience"
- Using the same recovery word on a rogue site that then uses it elsewhere

The HMAC per-domain design mitigates the last point, but not the first two.

### ✗ Database breaches — partially protected
- Raw database leak → attacker only gets bcrypt hashes, which resist cracking
- BUT: if the attacker has root (see above), the protocol is already moot
- Recommendation: encrypt database backups at rest

### ✗ Lost everything
If a user forgets their password AND their passphrase AND their recovery word AND fails L3 scoring, the admin is the only fallback. There is no SMTP-based "magic link" because SelfRecover rejects that model entirely. This is intentional — a system with infinite fallbacks has infinite attack surface.

---

## Summary table

| Threat | Protected ? | Mitigation |
|--------|:---:|---|
| Phishing | ✓ | HMAC per domain |
| Email account takeover | ✓ | No email used |
| SMTP failures | ✓ | No SMTP |
| Third-party trust | ✓ | Local only |
| Brute force recovery word | ✓ | Rate limits + L2/L3 escalation |
| Bot enumeration | ✓ | Honeypot + timing + forced delays |
| Server root compromise | ✗ | Mandatory sudo hardening |
| Stolen recovery word | ✗ | User responsibility |
| User negligence | ✗ (partial) | HMAC per-domain limits blast radius |
| Database breach | ✓ (partial) | bcrypt hashes, but root trumps all |
| Lost everything | ✗ | Admin fallback only |
