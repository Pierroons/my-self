# SelfJustice

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**Impartial legal pre-analysis powered by AI-readable directives — served over a free public API.**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.1.0 beta](https://img.shields.io/badge/status-v0.1.0%20beta-green.svg)](#status)
[![Live](https://img.shields.io/badge/live-justice.my--self.fr-brightgreen.svg)](https://justice.my-self.fr)
[![Part of: Self-Right](https://img.shields.io/badge/part%20of-Self--Right-blue.svg)](../README.md)
[![Companion of: SelfAct](https://img.shields.io/badge/companion-SelfAct-green.svg)](../selfact/)
[![LEGI](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fjustice.my-self.fr%2Fapi%2Fstatus&query=%24.legi.articles&label=LEGI&suffix=%20articles&color=blue)](https://justice.my-self.fr/api/status)
[![EU/CEDH](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fjustice.my-self.fr%2Fapi%2Fstatus&query=%24.eu.articles&label=EU%2FCEDH&suffix=%20articles&color=blue)](https://justice.my-self.fr/api/status)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

> **How do I access justice without going broke?**

---

## The problem

Legal advice in France costs 50–300 € per consultation. Most citizens facing everyday conflicts — unfair dismissal, noisy neighbors, refused insurance claims, consumer disputes — either give up or act blindly without understanding their rights.

Meanwhile, every AI assistant (Claude, ChatGPT, Mistral, Gemini, Perplexity) is eager to answer legal questions, but without structured guidance they hallucinate citations, miss the hierarchy of norms, fail to stay impartial, and skip the mandatory disclaimers.

**What if the legal framework itself was machine-readable — and the AI was told exactly how to reason about it?**

---

## The solution

SelfJustice is a **single authoritative directives page** (HTML) plus a **public HTTP API** that any AI can fetch to produce rigorous, impartial legal pre-analyses.

- The directives page tells the AI **how to reason**: impartiality, hierarchy of norms, mandatory legal basis for every claim, glossary for non-lawyers, mandatory legal disclaimer.
- The API tells the AI **what the law actually says**: the whole indexed French legal corpus (LEGI dump from DILA, over 500,000 articles) plus the EU/ECHR texts (Charter of Fundamental Rights, TFEU, TEU, GDPR, AI Act 2024/1689, European Convention on Human Rights). Live counts: [`/api/status`](https://justice.my-self.fr/api/status).

Any AI. Any citizen. Any conflict. One consistent, sourced, impartial pre-analysis.

---

## Architecture

```
┌──────────────┐           ┌───────────────┐           ┌──────────────────┐
│     User     │           │   User's AI   │           │   SelfJustice    │
│  (conflict)  │           │  (any model)  │           │ (static + API)   │
└──────┬───────┘           └───────┬───────┘           └────────┬─────────┘
       │                           │                            │
       │  "my boss harasses me,    │                            │
       │   analyze justice.        │                            │
       │   my-self.fr"             │                            │
       │──────────────────────────>│                            │
       │                           │  GET /directives.html      │
       │                           │───────────────────────────>│
       │                           │<───────────────────────────│
       │                           │  [reads directives]        │
       │                           │                            │
       │                           │  GET /api/legi/article/    │
       │                           │    L1152-1?code=travail    │
       │                           │───────────────────────────>│
       │                           │<───────────────────────────│
       │                           │  {official article text +  │
       │                           │   date in force + source}  │
       │                           │                            │
       │                           │  GET /api/eu/article/      │
       │                           │    CEDH/8                  │
       │                           │───────────────────────────>│
       │                           │<───────────────────────────│
       │<──────────────────────────│                            │
       │  Structured analysis:     │                            │
       │  qualification, parties,  │                            │
       │  legal basis per party,   │                            │
       │  forces/weaknesses,       │                            │
       │  remedies, deadlines,     │                            │
       │  glossary, disclaimer.    │                            │
```

**Cost for the user:** zero (they use their own AI subscription).
**Cost for the operator:** the domain name + one modest machine.

---

## Core components

### 1. Directives page (`site/index.html`)

Machine-readable directives telling the AI how to reason:

- **Role**: pre-analyst, not lawyer. Loi n° 71-1130 du 31 décembre 1971 boundary strictly respected.
- **Principles**: impartiality (both parties analyzed), legal basis mandatory, no strategic advice, disclaimer in entry and exit, explicit hors-scope detection.
- **Procedure**: 7-step analysis (qualification, facts, articles per party, strengths/weaknesses, remedies, deadlines, output).
- **Output template**: 11 sections including mandatory glossary for non-lawyers.
- **Sources transparency**: every citation must include provenance + date + reliability level.
- **Hierarchy of norms**: Constitution → ECHR/EU treaties → Codes → Regulations → Jurisprudence.

### 2. Public API

| Endpoint | Purpose |
|----------|---------|
| `GET /api/status` | Volume and sync date for all three bases: LEGI, conventionality, case law. For conventionality, `provenance` states per source whether the text was re-read from its publisher or served from a hand-deposited copy |
| `GET /api/legi/article/{ref}?code={alias}` | French legal article (with code disambiguation: travail, civil, penal, consommation, sante_publique, assurances, urbanisme, route, etc.) |
| `GET /api/legi/search?q=...&limit=...` | Full-text search across LEGI |
| `GET /api/eu/article/{source}/{num}` | EU/CEDH article (`source` ∈ `CEDH`, `CHARTE_UE`, `TFUE`, `TUE`, `RGPD`, `AI_ACT`) |
| `GET /api/eu/search?q=...&source=...` | Search in EU/CEDH |
| `GET /api/jurisprudence/verifier/{numero}` | Whether a ruling number actually exists |
| `GET /api/jurisprudence/search?q=...` | Search decisions by topic |
| `GET /api/jurisprudence/decision/{id}` | Full text of a decision |
| `GET /api/stats/by-ai` | Public anonymous stats: user consultations by AI family, crawler counts |
| `GET /api/stats/by-endpoint` | Top consulted articles (anonymized) |

All endpoints return JSON, all are rate-limited, all are CORS-open.

### 3. Stats & transparency

- Access log parsing distinguishes **user-initiated consultations** (Claude-User, ChatGPT-User, Perplexity-User) from **automated crawlers** (GPTBot, ClaudeBot, GoogleBot, etc.).
- Homepage counter shows real-time consultation count, updated hourly via `build_stats.sh`.
- Zero IP logged, zero cookie, zero user content stored. Only anonymized User-Agent families and endpoint paths.

---

## Technical stack

| Layer | Technology |
|-------|-----------|
| Web server | nginx 1.22 with strict CSP, rate limiting, security headers |
| Backend | PHP-FPM 8.2 (read-only) |
| Database | SQLite 3 (LEGI dump parsed to `legi_selfjustice.sqlite`) + SQLite (EU/CEDH) |
| TLS | Let's Encrypt, auto-renewal |
| Host | self-hosted server (x86 or ARM) |
| Cron | Bi-monthly LEGI sync + hourly stats rebuild |

---

## Try it

### From any AI chat interface

1. Open [claude.ai](https://claude.ai), Mistral Le Chat, ChatGPT, Gemini, Perplexity
2. Describe your conflict in plain language
3. Add: `analyse justice.my-self.fr`
4. Receive a structured pre-analysis with official article citations

### From the command line

```bash
# Check the database status
curl -s https://justice.my-self.fr/api/status | jq

# Fetch a specific article
curl -s "https://justice.my-self.fr/api/legi/article/L1152-1?code=travail" | jq

# Full-text search
curl -s "https://justice.my-self.fr/api/legi/search?q=harcelement&limit=20" | jq
```

### Self-host

Clone the repo, point nginx to `site/`, configure `api/api.php` against your LEGI SQLite dump. `deploy/selfjustice/` carries the nginx vhost, the deployment script and the systemd sync units — the reference configuration, not a step-by-step guide.

---

## Role in Self-Right

SelfJustice **diagnoses**. [SelfAct](../selfact/) **acts**. Together they cover the full arc from "I think I'm in my rights" to "the formal notice is signed and sent":

1. User describes conflict → SelfJustice returns structured JSON analysis.
2. SelfAct takes that JSON → generates mise en demeure, saisine, CERFA, calendar.
3. User downloads a ZIP dossier, sends by RAR at La Poste.

Zero consultation fee. Zero cloud. Zero intermediary.

---

## Legal disclaimer

SelfJustice is an **information tool**, not legal advice. It does not constitute:
- Legal counsel under French law n° 71-1130 of December 31, 1971
- A legal consultation (reserved to licensed attorneys)
- A binding legal opinion

**Always consult a lawyer before taking legal action.**

---

## Status

**v0.1.0 — live in production at [justice.my-self.fr](https://justice.my-self.fr)**

- [x] System directives (7-step analysis procedure, 5 principles)
- [x] 5 legal categories (work, neighborhood, consumer, civil, criminal)
- [x] Structured output template with glossary
- [x] Legal disclaimers (loi 71-1130 compliant)
- [x] API serving the full LEGI corpus
- [x] API serving the EU/ECHR corpus (including AI Act 2024/1689)
- [x] Multi-AI tested (Claude, ChatGPT crawler, OAI-SearchBot detected)
- [x] Public stats (`/api/stats/by-ai`, `/api/stats/by-endpoint`)
- [x] Dedicated domain [justice.my-self.fr](https://justice.my-self.fr)
- [ ] Formal legal review by practicing attorney
- [ ] Community contributions for non-covered domains

---

## Roadmap

- **v0.1.0 (current)** — Core directives + 5 categories + LEGI/EU API
- **v0.2.0** — Family law (divorce, custody, alimony) + housing law (leases, eviction)
- **v0.3.0** — Administrative law (disputes with public services)
- **v0.4.0** — Administrative case law (Conseil d'État, CAA, administrative courts) — **judicial** case law (Cour de cassation, courts of appeal) already ships and is served
- **v1.0.0** — Peer-reviewed directives + SelfAct integration

---

## Philosophy

SelfJustice is part of the **MySelf** ecosystem, specifically the **Self-Right** pillar:

| Module | Role |
|--------|------|
| **SelfJustice** (this) | Diagnose — what does the law say? |
| [SelfAct](../selfact/) | Act — draft the formal notice, fill the CERFA, calendar the deadlines |

The human provides entropy (lived experience, facts). The machine provides impartiality (structured reasoning, cited law). Neither is enough alone.

---

## License

[AGPL-3.0-or-later](../../LICENSE) — use it, fork it, host your own. If you run a modified version as a service, you must publish your modifications.

---

## Author

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*SelfJustice — because justice shouldn't require a bank account.*
