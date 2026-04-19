# Contributing to SelfRecover

Thanks for your interest! SelfRecover is at **concept stage** — the protocol is defined and running in production on one app, but it hasn't been audited by the security community yet.

## What we need most

1. **Security audits** — if you have cryptography or security expertise, please poke holes in the protocol
2. **Threat model reviews** — what attacks haven't we considered?
3. **Implementation experience** — if you try to deploy SelfRecover on your own app, tell us what worked and what didn't
4. **Ports to other languages** — PHP is the reference implementation, but Node.js, Python, Go, Rust ports are welcome

## What we're NOT ready for yet

- **Feature creep** — we want to stabilize the core protocol before adding fancy stuff
- **UI frameworks** — the demo is intentionally framework-free HTML/CSS/JS to stay minimal
- **Database abstractions** — SQLite for the demo, PHP PDO for the reference impl, both intentional

## How to contribute

- **Bug reports**: open an issue using the Bug Report template
- **Questions**: open a Discussion (preferred) or an Issue
- **Security disclosures**: see [SECURITY.md](SECURITY.md) — do NOT open a public issue for security bugs
- **Pull requests**: fork, branch, PR — small and focused is better than big and sprawling

## Code style

- **PHP**: PSR-12, but we don't enforce it strictly — readability > dogma
- **JS**: vanilla, no build step, ES6+ OK
- **Markdown**: 80-120 col wrap for docs

## License of contributions

By contributing, you agree that your contributions will be licensed under the AGPL-3.0-or-later license (see [LICENSE](../../LICENSE) at the repo root). Historical contributions made before 2026-04-19 remain licensed under the MIT License they were originally submitted under.

---

Cheers,
Pierroons
