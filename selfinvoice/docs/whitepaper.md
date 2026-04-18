---
title: "SelfInvoice — Whitepaper"
subtitle: "Local-first invoice generator, compliant PDFs, zero SaaS"
author: "Pierroons — MySelf ecosystem"
date: "April 2026"
version: "alpha 0.0.1"
---

# Executive summary

SelfInvoice is a **local-first invoice generator** for French freelancers, associations, and small businesses. It produces legally compliant PDF invoices with all mandatory legal mentions (art. 242 nonies A Annexe II CGI), prominently displays the issuer's IBAN and the invoice reference so the client can initiate a standard SEPA transfer, and handles automatic reconciliation when the virement arrives. Customer data never leaves the local machine.

The goal is simple: replace the €150–350 / year stack of Stripe Invoicing + a SaaS accounting tool with a single self-hosted binary that does one job honestly.

# 1. Problem statement

## 1.1 Today's landscape

A French freelancer needs to:

1. Issue invoices with all mandatory legal mentions.
2. Offer the client a way to pay.
3. Track which invoices are paid.
4. Export data for accounting at year-end.

Current options:

- **Stripe Invoicing** (free tier, but 0.4 % per invoice when paid through Stripe + 1.4 % + €0.25 payment fees) → ≈ 2 % evaporates per invoice.
- **Pennylane / QuickBooks / Zoho Books** (~15–25 €/month) → ~€250/year for basic PDF generation + a customer list.
- **Tiime, Freebe, Indy** (~10 €/month) → lock-in into their accounting exports.
- **Word templates + manual reconciliation** → free but error-prone, no audit trail.

All cloud options share the same issues: **customer data on third-party servers**, billing capability **contingent on subscription**, no portability, and the constant risk of the service changing pricing or terms.

## 1.2 The legal requirement is simple

Article 242 nonies A of Annexe II of the CGI defines the mandatory mentions for a French invoice. There are ~12 depending on status. No SaaS is required to implement that list — a Jinja template + a PDF renderer do it.

# 2. Architecture

## 2.1 Component overview

```
┌─────────────────┐        ┌──────────────────┐        ┌──────────────────┐
│  Local SQLite   │◄──────►│  Template engine │───────►│  PDF renderer    │
│  DB             │        │  (Jinja2)        │        │  (WeasyPrint)    │
│                 │        │                  │        │                  │
│  - customers    │        │  + legal_matrix  │        │  invoice-{ref}.  │
│  - invoices     │        │  .json           │        │       pdf        │
│  - products     │        │                  │        │                  │
│  - payments     │        │                  │        │                  │
└─────────────────┘        └──────────────────┘        └──────────────────┘
        ▲                                                        │
        │                                                        ▼
        │                                              ┌──────────────────┐
        │                                              │  SEPA QR code    │
        │                                              │  embedded in PDF │
        │                                              └──────────────────┘
        │
        │                                              ┌──────────────────┐
        └──────────────────────────────────────────────│  Payment watcher │
                                                       │  (webhook or     │
                                                       │   email IMAP)    │
                                                       └──────────────────┘
```

## 2.2 Legal mentions matrix

Each issuer status is mapped to required mentions in a JSON file (`config/legal_matrix.json`), versioned in the repo:

```json
{
  "micro_entrepreneur": {
    "required": [
      "issuer_name", "issuer_address", "siret", "ape",
      "rcs_or_rm", "vat_statement"
    ],
    "vat_statement": "TVA non applicable, art. 293 B du CGI",
    "penalty_clause_if_b2b": true
  },
  "sasu": {
    "required": [
      "issuer_name", "issuer_address", "siren", "rcs",
      "capital", "vat_intra", "vat_rate_and_amount"
    ]
  },
  "association": {
    "required": [
      "issuer_name", "issuer_address", "rna",
      "legal_form_statement", "activity_commercial_or_not"
    ]
  }
}
```

When the law changes (finance laws, reform of the micro regime), the matrix is updated and every invoice template automatically reflects it.

## 2.3 Data model

```sql
CREATE TABLE customers (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  address TEXT NOT NULL,
  siret TEXT,
  vat_intra TEXT,
  email TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE invoices (
  id INTEGER PRIMARY KEY,
  ref TEXT UNIQUE NOT NULL,         -- e.g. "FACT-2026-0042"
  customer_id INTEGER REFERENCES customers(id),
  date_issued DATE NOT NULL,
  date_due DATE,
  amount_excl_vat DECIMAL(10,2) NOT NULL,
  vat_rate DECIMAL(5,2),
  amount_incl_vat DECIMAL(10,2) NOT NULL,
  status TEXT CHECK (status IN ('draft', 'sent', 'paid', 'cancelled')),
  pdf_path TEXT,
  paid_at TIMESTAMP
);

CREATE TABLE invoice_lines (
  id INTEGER PRIMARY KEY,
  invoice_id INTEGER REFERENCES invoices(id),
  description TEXT NOT NULL,
  quantity DECIMAL(10,2) NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  vat_rate DECIMAL(5,2)
);

CREATE TABLE payments (
  id INTEGER PRIMARY KEY,
  invoice_id INTEGER REFERENCES invoices(id),
  amount DECIMAL(10,2) NOT NULL,
  received_at TIMESTAMP NOT NULL,
  source TEXT,  -- 'sepa_webhook', 'email_imap', 'manual'
  reference TEXT
);
```

# 3. Reconciliation engine

Three strategies, configurable:

## 3.1 SEPA webhook (recommended for banks with APIs)

Banks like Qonto, Shine, Revolut expose webhook APIs for transaction notifications. SelfInvoice registers a webhook endpoint. When a transfer arrives with a reference matching `FACT-2026-XXXX`, the corresponding invoice is marked paid.

## 3.2 Email IMAP polling (universal fallback)

Most banks send email notifications for incoming transfers. SelfInvoice polls an IMAP mailbox, parses the subject line / body with regex patterns per bank, extracts amount + reference, matches to invoices.

## 3.3 Manual reconciliation

Fallback: user marks invoice paid manually via the CLI/UI when the virement shows up in their bank app.

# 4. User flows

## 4.1 CLI usage (power users)

```bash
selfinvoice customer add "Jeanne Dupont" --address "1 rue Pasteur, 75000 Paris"
selfinvoice invoice create --customer "Jeanne Dupont" \
    --line "Photo shoot 2h" \
    --qty 1 --price 450 --vat 0 --vat-statement "franchise_en_base"
# → generates FACT-2026-0042.pdf with embedded QR
selfinvoice send FACT-2026-0042 --email jeanne@dupont.fr
```

## 4.2 Web UI (for non-technical users)

Runs locally on `http://localhost:8765`. Minimal Next.js or plain-HTML form. Accessible over LAN if the user wants to invoice from a tablet.

# 5. Payment section on the invoice

Every generated invoice reserves a dedicated payment section in the PDF (bottom-right, visible but non-intrusive) listing:

- Beneficiary name
- Full IBAN
- Invoice reference (to be used as the transfer reference)
- Amount due

The client reads these details in the PDF, opens their own banking app, and initiates a standard SEPA transfer — no third-party service involved. Payment arrives directly on the issuer's IBAN. SelfInvoice's reconciliation engine watches for matching references and marks the invoice as paid.


# 6. Privacy & portability

- No telemetry, no cloud calls, no analytics.
- Full export to JSON / CSV / FEC (French accounting format) at any time.
- All invoices archived as signed PDFs with a SHA-256 hash in a local chain (tamper-evident log).
- Backup = copy the SQLite file + the PDF directory. That's it.

# 7. Roadmap

**v0.1.0** — CLI for customers, invoices, lines; legal matrix for micro-entrepreneur + SASU + association; PDF generation; manual reconciliation.

**v0.2.0** — Web UI, email sending via SMTP, IMAP-based reconciliation for BNP, Qonto, Shine, Revolut, Crédit Mutuel.

**v0.3.0** — FEC export, yearly summary, VAT declaration assistance.

**v1.0.0** — Plugin API for custom templates, community-contributed legal matrices for other EU countries.

# 8. References

- Art. 242 nonies A, Annexe II CGI: https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000038613081
- Art. L441-10 C. com. (B2B payment delays): https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000038613459
- FEC format: BOI-CF-IOR-60-40
- WeasyPrint: https://weasyprint.org
- EPC069-12: https://www.europeanpaymentscouncil.eu
