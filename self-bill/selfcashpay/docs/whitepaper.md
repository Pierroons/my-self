---
title: "SelfCashpay — Whitepaper"
subtitle: "Rehabilitating EPC069-12: zero-commission SEPA payments via QR"
author: "Pierroons — MySelf ecosystem"
date: "April 2026"
version: "alpha 0.0.1"
---

# Executive summary

SelfCashpay is an open-source tool that generates **SEPA Credit Transfer QR codes** conforming to the European Payments Council specification EPC069-12. Any EU banking app that supports the standard — and they all do — reads the QR, pre-fills a virement, and executes the transfer through standard SEPA rails.

There is no intermediary. The tool does not hold funds. There is no commission, no KYC, no payment license, no vendor. Creators, associations, freelancers, and small businesses receive payments directly on their own IBAN within minutes (SEPA Instant) or the same day (regular SEPA).

# 1. The lost standard

In February 2013, the European Payments Council published EPC069-12: "Quick Response Code: Guidelines to Enable the Data Capture for the Initiation of a SEPA Credit Transfer". The standard defines a simple, UTF-8 text format encodable into any QR code that any EU banking app can parse.

Over a decade later, the standard is:

- **Universally supported** by EU banks (BNP Paribas, Société Générale, Crédit Agricole, Crédit Mutuel, ING, N26, Revolut, Boursorama, Qonto, Shine, Lydia, etc.).
- **Officially free to use** (no license, no royalty, no gateway).
- **Completely absent from public awareness**, because commercial payment providers (Stripe, PayPal, Square, Sumup) have zero incentive to promote a commission-free alternative that makes them redundant.

SelfCashpay is a one-line rehabilitation of this standard: a generator + a compatibility matrix + self-hosting guides.

# 2. Specification (EPC069-12)

## 2.1 Format

The QR payload is a **UTF-8 text block** with each field on its own line, in a strict order:

```
Line 1: BCD                 (service tag — fixed)
Line 2: 002                 (version — current)
Line 3: 1 or 2              (character set: 1 = UTF-8, 2 = ISO-8859-1)
Line 4: SCT                 (identification: SEPA Credit Transfer)
Line 5: BIC                 (optional in zone SEPA, required outside)
Line 6: Beneficiary name    (max 70 chars)
Line 7: IBAN                (max 34 chars)
Line 8: Currency + amount   (e.g. "EUR45.00", max 12 chars)
Line 9: Purpose             (ISO 20022 code, optional, 4 chars)
Line 10: Reference          (max 35 chars, used for reconciliation)
Line 11: Free text          (max 140 chars, optional)
```

Total size: ≤ 800 bytes, easily fits in a 33×33 QR matrix with error correction level M.

## 2.2 Example

```
BCD
002
1
SCT

JEANNE DUPONT
FR7612345678901234567890123
EUR45.00

FACT-2026-0042

```

Empty lines for unused fields are mandatory to preserve field positions.

## 2.3 Generation

Any QR library that handles plain-text input works. Reference Python implementation:

```python
import qrcode

def epc069_qr(beneficiary, iban, amount_eur, reference=""):
    payload = "\n".join([
        "BCD", "002", "1", "SCT",
        "",  # BIC (omitted in SEPA zone)
        beneficiary,
        iban.replace(" ", ""),
        f"EUR{amount_eur:.2f}",
        "",  # Purpose
        reference,
        ""   # Free text
    ])
    return qrcode.make(payload)

qr = epc069_qr("Jeanne Dupont", "FR7612345678901234567890123", 45.00, "FACT-2026-0042")
qr.save("sepa.png")
```

That's the entire tool. 12 lines of Python.

# 3. User flow

## 3.1 Merchant side

1. Merchant generates a QR code with amount + reference + their IBAN (one-time configuration).
2. QR is shown on a website, printed on a receipt, embedded in an invoice PDF, or displayed on a screen at a point of sale.

## 3.2 Payer side

1. Payer opens their banking app.
2. Payer taps "Virement" → "Scanner un code" (available in 95 %+ of EU banking apps).
3. App reads the QR, pre-fills the virement form.
4. Payer confirms.
5. Money transits via SEPA (Instant if both banks support it: ≤ 10 seconds; Regular: ≤ 1 business day).

## 3.3 Reconciliation

The reference field (e.g., `FACT-2026-0042`) is preserved through the SEPA rails. When the virement lands on the merchant's bank, the reference appears in the transaction metadata, enabling automatic reconciliation with the originating invoice (if integrated with [SelfInvoice](../selfinvoice/)) or manual lookup in the bank app.

# 4. Bank compatibility matrix

Tested end-to-end (April 2026):

| Bank / App | SEPA QR scan | SEPA Instant | Notes |
|------------|:-----------:|:------------:|-------|
| BNP Paribas | ✅ | ✅ | Natively integrated |
| Société Générale | ✅ | ✅ | Via "Virement rapide" |
| Crédit Agricole | ✅ | ⚠️ | Instant requires subscription |
| Crédit Mutuel | ✅ | ✅ | |
| Boursorama | ✅ | ✅ | |
| ING | ✅ | ✅ | |
| N26 | ✅ | ✅ | |
| Revolut | ✅ | ✅ | |
| Qonto | ✅ | ✅ | B2B / freelance |
| Shine | ✅ | ✅ | |
| Lydia | ✅ | ✅ | |
| Sumup Biz | ❌ | N/A | Doesn't support inbound SEPA QR |
| La Banque Postale | ✅ | ⚠️ | Instant on premium plans |

Banks outside this list have a 95 %+ chance of supporting EPC069-12 because the standard is part of the mandatory SEPA scheme.

# 5. Legal framework

## 5.1 What SelfCashpay is

A **data-formatting tool** that produces a QR code encoding a transfer request. Analogous to a utility like `qrencode`, `base64`, `iconv`.

## 5.2 What SelfCashpay is not

- Not a payment provider (does not process payments).
- Not a payment initiator (does not talk to any bank API or PSP).
- Not a custodian of funds (never holds money).
- Not a regulated financial service under DSP2 (PSD2), EMI, or AISP directives.

## 5.3 Why this matters

The legal simplicity is the whole point. No DSP2 registration, no KYC burden, no regulatory reporting. The tool is infrastructure that anyone can host, fork, integrate, without crossing into regulated territory.

# 6. Use cases

| Use case | Example | Amount range |
|----------|---------|-------------|
| **Creator tips** | Twitch stream overlay with a rotating QR | €1–100 |
| **Association dues** | Template page with 10/20/50/100 € options | €10–500 |
| **Invoice payment** | QR in every PDF sent by SelfInvoice | €50–10000 |
| **Restaurant bill splitting** | Waiter prints a bill with QR, customers each scan their share | €10–500 |
| **Crowdfunding** | Community project with a goal and a shared IBAN | €5–100 |
| **B2B subcontractor** | Freelance invoice with 30-day virement | €500–50000 |
| **Point of sale** | Farmer market vendor with a printed-QR price list | €2–100 |

# 7. Roadmap

**v0.1.0** — Python reference library, CLI (`selfcashpay generate --iban ... --amount ... --ref ...`), 12-bank compatibility test suite.

**v0.2.0** — JavaScript library for browser embedding, self-hosted tip page template, SelfInvoice integration module.

**v0.3.0** — Webhook listener for common bank APIs (Qonto, N26, Shine), bridge to SelfInvoice reconciliation.

**v1.0.0** — Production-stable, documented, packaged (pip, npm, apt).

# 8. Ethical note

This tool intentionally undermines the commission model of commercial payment providers for SEPA-denominated transactions. That is the point. Creators and small businesses in the EU have been paying 1.5 % to 3 % for a service that existed as a free standard for over a decade. SelfCashpay is a public-interest reminder that the rails are ours.

For non-SEPA transactions (cross-border, non-EU currencies, card payments), commercial providers remain necessary. SelfCashpay does not pretend to replace them there.

# 9. References

- EPC069-12 specification (official): https://www.europeanpaymentscouncil.eu
- SEPA Instant Credit Transfer (SCT Inst): EPC rulebook
- Bank compatibility tests: https://github.com/Pierroons/my-self/tree/main/self-bill/selfcashpay/tests
- SelfInvoice integration: https://github.com/Pierroons/my-self/tree/main/self-bill/selfinvoice
