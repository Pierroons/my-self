# Bi-Self

**Sovereign Identity + Autonomous Community Moderation**

Bi-Self combines two independent modules into a self-hosted ecosystem for online communities — no cloud, no third party, no single point of authority.

| Module | Purpose | Status |
|--------|---------|--------|
| [SelfRecover](./selfrecover/) | Account recovery without email or phone — multi-level identity verification | v0.1.0 ✅ |
| [SelfModerate](./selfmoderate/) | Community-driven moderation through social reasoning — vote, reputation, escalation | v0.1.0 (concept) |

## The equation

**Reliable identity + Collective moderation = Autonomous community**

- **SelfRecover** answers "Who are you?" — without relying on any third party
- **SelfModerate** answers "How do you behave?" — without depending on a single administrator
- **Bi-Self** is the union of both sovereignties

## Why both?

Without reliable identity, vote-based moderation is vulnerable to Sybil attacks (fake accounts manipulating votes). Without collective moderation, identity alone doesn't protect against toxic behavior. The two reinforce each other.

## Architecture

```
bi-self/
├── selfrecover/      # Identity & account recovery
│   ├── demo/         # Standalone PHP+SQLite demo
│   ├── docs/         # Whitepapers EN+FR, architecture, threat model
│   └── README.md
├── selfmoderate/     # Community moderation engine
│   ├── demo/         # Standalone PHP+SQLite demo (coming)
│   ├── docs/         # Technical whitepaper, threat model
│   └── README.md
└── README.md         # You are here
```

## Use individually or together

- **Need just identity recovery?** → Use `selfrecover/` standalone
- **Need just community moderation?** → Use `selfmoderate/` standalone (works with any auth system)
- **Want the full package?** → Use both — SelfRecover's anti-Sybil protection strengthens SelfModerate's vote integrity

## Reference implementation

[ARC PVE Hub](https://arc.rpi4server.ovh) — a PWA for ARC Raiders PVE players — is the first platform to integrate Bi-Self. It serves as a testing ground and reference for developers.

## Requirements

- PHP 8.0+ (or any backend language)
- Relational database (MariaDB, PostgreSQL, SQLite)
- No external dependencies, no cloud services
- Runs on a Raspberry Pi 4

## License

MIT — see [LICENSE](./selfrecover/LICENSE)

## Author

**Pierroons** — [github.com/Pierroons](https://github.com/Pierroons)

---

*If a community can build itself, it can govern itself.*
