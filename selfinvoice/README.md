# SelfInvoice

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Compliant invoice generator — PDF, legal mentions, zero cloud.**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](../../LICENSE)
[![Status: alpha 0.0.1](https://img.shields.io/badge/status-alpha%200.0.1-lightgrey.svg)](#status)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

> **Your invoice. Your template. Your data. Done.**

---

## The problem

Invoice generation is a **$5 billion SaaS market** for a task that fundamentally reduces to: "fill a template with 10 fields and generate a PDF". Current providers:

- **Stripe Invoicing**: free tier limits, 0.4 % per invoice paid through Stripe on top of payment fees
- **Pennylane / QuickBooks / Zoho**: 10-25 € / month with "per user" multipliers
- **Tiime, Indy, Freebe**: 7-20 € / month, lock you into their accounting pipeline
- **All of them**: host your customer list on their servers, condition your billing capability on your subscription

For a freelancer generating 10 invoices a month, **the average Stripe+accounting SaaS stack costs 200-350 € / year** — for a task a decent PDF template handles.

The worst part: every one of these tools is **more complex than the legal requirement**. A valid French invoice requires ~12 mandatory legal mentions ([art. 242 nonies A Annexe II CGI](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000038613081)). That's it. No SaaS needed, no cloud needed.

---

## Vision

SelfInvoice is a **local-first invoice generator** with:

- Templates for every common French status: micro-entrepreneur, SASU, association, profession libérale, auto-entrepreneur with TVA.
- Automatic insertion of the required legal mentions based on your status.
- PDF generation via `weasyprint` (pixel-perfect, no browser rendering dependency).
- **Zero data leaves your machine** — the customer list is a local SQLite database.
- IBAN and invoice reference shown prominently on the PDF for easy manual bank transfer.
- Automatic reconciliation when a SEPA transfer matches the invoice reference.

---

## Core architecture

```
┌─────────────────┐      ┌──────────────┐      ┌───────────────┐
│  SQLite DB      │      │  Template    │      │  PDF renderer │
│  (customers,    │──────│  engine      │──────│  (weasyprint) │
│   invoices,     │      │  (jinja2)    │      │               │
│   products)     │      │              │      │               │
└─────────────────┘      └──────────────┘      └───────────────┘
        │                                              │
        │                                              ▼
        │                                      ┌──────────────────┐
        │                                      │  invoice-{ref}.  │
        │                                      │  pdf             │
        │                                      └──────────────────┘
        │                                              │
```

Single binary deployment, runs on a laptop, phone (via Termux), or Raspberry Pi.

---

## Legal mentions matrix

SelfInvoice ships with a **matrix** that determines which legal mentions to include based on the issuer's status:

| Status | Required mentions |
|--------|------------------|
| Micro-entrepreneur | SIRET, RCS/RM, "TVA non applicable, art. 293 B CGI" if franchise en base |
| SASU | SIRET, RCS, capital, SIREN, TVA intra. |
| Association (non-profit) | N° RNA, statut "association loi 1901", TVA exempt. |
| Profession libérale | SIRET, RCS (if commercial), N° ordre (if regulated) |
| All B2B | Payment deadline (art. L441-10), penalty rate, indemnity 40 € (D441-5) |
| All | Issuer name, address, customer name, address, invoice date, unique ref, VAT % and amount |

The matrix is **a JSON file in the repo** — auditable, forkable, patchable when the law changes.

---

## Core principle — pure data, zero custody

SelfInvoice generates files (invoice PDF + a local database entry). It does not:

- Hold funds
- Open accounts at any third party
- Require KYC
- Process payments

It produces a **compliant document** that the customer pays via a regular SEPA transfer to the issuer's IBAN. Legally, SelfInvoice is a **document formatting tool**, not a financial service. Same category as a Word template or LaTeX class.

**Zero commission. Zero fund custody. Zero subscription.**

---

## Status

**alpha 0.0.1 — concept phase.**

- [x] Legal mentions matrix (JSON)
- [x] Template library draft (micro-entrepreneur, SASU, association)
- [ ] PDF renderer (weasyprint pipeline)
- [ ] Customer/invoice SQLite schema
- [ ] SEPA notification watcher (webhook or email polling)
- [ ] v0.1.0 prototype with CLI + web UI
- [ ] Target deployment: `invoice.my-self.fr`

See **[whitepaper](docs/whitepaper.docx)** for the full legal framework, template architecture, and deployment guide.

---

## Author

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*SelfInvoice — Because a PDF is still just a PDF.*
