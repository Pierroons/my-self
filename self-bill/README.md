# Self-Bill

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Invoicing + getting paid, no middleman.**

> *Bill it. Cash it. Keep all of it.*

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](../LICENSE)
[![SelfInvoice: alpha 0.0.1](https://img.shields.io/badge/SelfInvoice-alpha%200.0.1-lightgrey.svg)](./selfinvoice/)
[![SelfCashpay: alpha 0.0.1](https://img.shields.io/badge/SelfCashpay-alpha%200.0.1-lightgrey.svg)](./selfcashpay/)
[![Part of: MySelf](https://img.shields.io/badge/part%20of-MySelf-blue.svg)](../README.md)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

---

## The tension it addresses

Freelancers, small businesses, independent creators, and associations face the same extractive reality:

- **Billing tools** (Stripe Invoicing, QuickBooks, Zoho, Pennylane) charge **10-25 € / month** for basic PDF generation — a task that takes a templated Word file.
- **Payment providers** (Stripe, PayPal, Square, Sumup) take **1.4 % to 3.5 % + 0.25 €** per transaction, with delayed payouts (2-7 days), chargebacks, and account freezes at their discretion.
- **Both** externalize your customer data, your revenue history, and your cash flow to private companies that can drop you at any time.

On a 50 €/month tip: ~1.75 € commission + ~4 €/month Stripe subscription = **~12 % of a solo creator's tips evaporate** before they see them.

The technical reality is that **neither billing nor SEPA payments need intermediaries anymore**. SEPA has standardized a QR-code format (EPC069-12) that any EU banking app reads natively. The invoice format is a plain PDF with legal mentions. The infrastructure exists; only the commercial middlemen persist.

Self-Bill cuts them out with two modules that together cover the full invoicing-to-cash flow.

---

## Why the two modules reinforce each other

**SelfInvoice alone** generates compliant invoices (legal mentions, VAT / franchise en base, retention indication, bordereaux) as downloadable PDFs. But a PDF isn't paid. The client still needs a way to pay — usually a "virement to IBAN XXX" line in the invoice, which requires copy-pasting and is rarely done on mobile.

**SelfCashpay alone** generates an EPC069-12 SEPA QR code that the client scans with their banking app. The transfer is pre-filled (amount, IBAN, BIC, reference). The client just confirms and the money lands on your IBAN **instantly**. But a QR code without an invoice is not traceable — no legal trace, no audit, no VAT proof.

**Together**, the cycle is complete:

1. SelfInvoice generates a compliant PDF invoice with all legal mentions.
2. The same invoice embeds an SelfCashpay QR code matching exactly the invoiced amount + reference.
3. The client receives the PDF, scans the QR, confirms in their banking app, the money arrives on the author's IBAN.
4. Cross-reference automated: when a SEPA transfer arrives with a matching reference, SelfInvoice flags the invoice as paid.

**Zero commission.** **Zero intermediary custody of funds.** **No banking license needed** because the tools never touch the money — only the data around it. Legally simpler than Stripe, faster to integrate, and the creator keeps everything.

---

## Cross-module workflows

- **Freelance photographer, 450 € invoice** → SelfInvoice generates the PDF (with URSSAF mention, VAT franchise en base statement, client SIRET). The SEPA QR matches 450 € + reference `FACT-2026-0042`. Client scans on their phone, confirms, done in 15 seconds. Photographer sees "FACT-2026-0042 paid" appear automatically.
- **Association receiving dues** → SelfInvoice templates for 10/20/50 € annual dues. SelfCashpay QR matches the expected amount. Members pay in one QR scan, the association treasurer has a clean audit trail.
- **Tip jar on a creator website** → Pure SelfCashpay, no invoice needed for tips below legal thresholds. QR shown on site, visitor scans with their banking app, virement arrives. Creator just configures their IBAN once.
- **B2B subcontractor invoice** → SelfInvoice enforces the legal mentions for B2B (SIRET, TVA intracommunautaire, délai de paiement art. L441-10 Code de commerce). SelfCashpay QR with a 30-day virement reference for automated reconciliation.

---

## Modules in this bundle

| Module | Role | Status |
|--------|------|--------|
| [SelfInvoice](./selfinvoice/) | Compliant invoice generator (PDF, legal mentions, audit trail) | alpha 0.0.1 — concept phase |
| [SelfCashpay](./selfcashpay/) | SEPA QR-code payments (EPC069-12), zero commission | alpha 0.0.1 — concept phase |

---

## Status

Both modules are in **concept phase** (alpha 0.0.1). The whitepapers specify:
- SelfInvoice: PDF templates for micro-entrepreneur / SASU / associations, legal mentions matrix by status, reconciliation engine.
- SelfCashpay: EPC069-12 QR generator, IBAN-bank-app compatibility matrix, self-hosted tip page template.

Prototype implementations are planned for v0.1.0. Target deployment: `bill.my-self.fr/invoice` and `bill.my-self.fr/cashpay`.

The economic pitch is simple: replacing Stripe Invoicing + Stripe Payments for a solo creator saves **~150 € / year** and keeps data fully local. Scaling to associations and micro-entrepreneurs, this becomes a concrete alternative to SaaS fiscalité.

---

## Author

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*Self-Bill — Because every euro you earn should reach you whole.*
