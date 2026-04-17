# SelfCashpay

**SEPA QR-code payments — EPC069-12 standard, zero commission, no middleman.**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](../../LICENSE)
[![Status: alpha 0.0.1](https://img.shields.io/badge/status-alpha%200.0.1-lightgrey.svg)](#status)
[![Part of: Self-Bill](https://img.shields.io/badge/part%20of-Self--Bill-blue.svg)](../README.md)
[![Companion of: SelfInvoice](https://img.shields.io/badge/companion-SelfInvoice-green.svg)](../selfinvoice/)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

> **Scan. Confirm. Paid. No one in between.**

---

## The forgotten standard

In 2013, the European Payments Council published **EPC069-12**: a specification for encoding a SEPA Credit Transfer into a QR code. Every major EU bank integrated it: BNP Paribas, Société Générale, ING, N26, Revolut, Boursorama, Crédit Mutuel — they all parse this format out of the box.

For twelve years, this standard has been **sitting on shelves**, because commercial payment providers have no incentive to promote a free, commission-less alternative to their 2-3 % takes. Stripe and co. don't mention it. Your bank's app supports it quietly. No marketing. No education campaign.

SelfCashpay brings EPC069-12 back into the light.

---

## How it works

The payer scans a QR code. Their banking app opens and displays:

```
Virement SEPA
-------------
Bénéficiaire : Jeanne Dupont
IBAN        : FR76 1234 5678 9012 3456 7890 123
Montant     : 45,00 €
Référence   : FACT-2026-0042
```

The payer confirms in their app. The transfer executes through the standard SEPA rails. Money lands on the beneficiary's IBAN **within minutes** (SEPA Instant) or end of day (regular SEPA).

**No intermediary** touches the money. **No provider** takes a cut. **No account** is opened with anyone. The payer uses their normal bank, the payee receives on their normal IBAN.

---

## Core principle: pure data, zero custody

SelfCashpay generates a QR code. That's all. It never:
- Holds funds
- Opens an account
- Requires KYC
- Needs a payment license (DSP2, EMI, AISP)
- Processes payments

Because it doesn't process payments. It **encodes payment data** that the payer's own bank executes. Legally, SelfCashpay is a **data formatting tool**, not a financial service. The same category as `iconv` or `base64`.

This changes everything. No regulatory capture. No vendor lock-in. Anyone can self-host, fork, extend, embed in any other product.

---

## The QR format (EPC069-12)

```
BCD
002
1
SCT
BNPAFRPPXXX               ← BIC (optional in zone SEPA)
JEANNE DUPONT             ← Recipient name (70 chars max)
FR7612345678901234567890123  ← IBAN
EUR45.00                  ← Currency + amount
                          ← Purpose (4 chars, optional)
FACT-2026-0042            ← Reference (35 chars max)
                          ← Free text (140 chars, optional)
```

Each field on its own line, in this order, UTF-8 encoded, framed as a **text/plain** QR code (any generator works: Python `qrcode`, JS `qrcode-generator`, CLI `qrencode`).

That's the entire "protocol". 10 lines of text, 800 bytes of QR, 100 % interoperable across EU banking apps.

---

## Use cases

- **Tip jar** on a creator's website or stream overlay.
- **Dues collection** by an association (one QR for 10 €, one for 20 €, etc.).
- **Invoice payment** embedded in a SelfInvoice PDF.
- **Point-of-sale** for small vendors who don't want a Sumup (just print the QR, update amount daily).
- **Crowdfunding** with full traceability through the reference field.
- **Crossing a restaurant bill** without the waitress touching a Sumup.

---

## Role in Self-Bill

SelfCashpay is the **payment data generator**. [SelfInvoice](../selfinvoice/) is the **billing data generator**. Together:

```
SelfInvoice generates the invoice PDF
        │
        │  (embeds QR from SelfCashpay)
        ▼
PDF shown to client — QR visible in the document
        │
        │  client scans QR with banking app
        ▼
Client's bank pre-fills virement (amount + IBAN + reference)
        │
        │  client confirms
        ▼
Money arrives on SelfInvoice user's IBAN
        │
        │  SEPA notification webhook or email parsing
        ▼
SelfInvoice reconciles payment and marks invoice paid
```

**The full invoicing-to-cash cycle, with no intermediary, no commission, no account with a provider.**

---

## Status

**alpha 0.0.1 — concept phase.**

- [x] EPC069-12 spec review
- [x] Reference Python QR generator
- [x] Compatibility test with 12 major EU banking apps
- [ ] Self-hosted tip page template
- [ ] Webhook watcher for common banks (BNP API, N26 API, Qonto API, etc.)
- [ ] SelfInvoice integration (embed module)
- [ ] v0.1.0 prototype with minimal UI for amount + reference
- [ ] Target deployment: `bill.my-self.fr/cashpay`

See **[whitepaper](docs/whitepaper.docx)** for the full EPC069-12 breakdown, bank compatibility matrix, and self-hosting guide.

---

## Author

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*SelfCashpay — The EPC gave us a free payment rail. Let's use it.*
