# SelfRecover-Lite

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Hardening email recovery, for the sites that keep it** — the link and the device, never one without the other.

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../../LICENSE)
[![Part of: Bi-Self](https://img.shields.io/badge/part%20of-Bi--Self-blue.svg)](../README.md)

---

## Status

**Design published, no implementation.** This folder carries only the whitepaper: nothing installs yet. The code will
come with its benches, after the module plan.

## What it is

Your site keeps its customers' email addresses — an online shop, a consumer service — and will not ask them to remember
one more secret. SelfRecover-Lite keeps the usual "forgot password" and requires a second proof: a browser the customer
registered on their account (the "witness"). The link received by email is no longer enough on its own, and neither is
the witness.

Without a registered device, the link remains necessary, together with an optional word chosen in advance, or a human
review (customer service) after a delay during which the customer is notified and can object. After any recovery, a
return window lets the dispossessed holder come back through a device from before.

If your site accepts removing email from recovery, take [SelfRecover](../selfrecover/README.md). The choice between the
two is made once, before installation.

## Read

- [Whitepaper (English)](docs/whitepaper-en.md) · [Whitepaper (français)](docs/whitepaper-fr.md)
- [The comparison of three ways to recover an account](../selfrecover/tools/comparison.html) (in French)

## License

AGPL-3.0-or-later, like the other MySelf modules.
