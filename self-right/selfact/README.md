# SelfAct

> 🇫🇷 **[Lire en français →](./README.fr.md)**

**From "I know my rights" to the step itself: the official form, the deadline, a letter template to complete.**

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.1.2 running](https://img.shields.io/badge/status-v0.1.2%20running-brightgreen.svg)](#status)
[![Part of: Self-Right](https://img.shields.io/badge/part%20of-Self--Right-blue.svg)](../README.md)
[![Companion of: SelfJustice](https://img.shields.io/badge/companion-SelfJustice-green.svg)](../selfjustice/)
[![Read in French](https://img.shields.io/badge/lang-français-blue.svg)](./README.fr.md)

> **Know your rights. Now make them real.**

---

## What SelfAct covers

[SelfJustice](../selfjustice/) serves the law: articles in force, European texts, case law.
Your AI uses it to rely on the texts in force, with verifiable references, rather than on its memory. What remains is acting:
which form to fill, which online service to use, within which deadline, how to write the
letter.

SelfAct covers that step. It **does not write your case**: it shows you the official resource,
computes the deadline, and gives you a letter template with gaps. The facts are yours to write.

---

## What SelfAct does

- **Catalogue of official resources** — CERFA forms, online services and letter templates from
  service-public.gouv.fr, harvested on the 1st and 15th of each month (exact count:
  `/act/api/catalog.php?stats=1`).
- **Situations** — around twenty common situations, hand-curated; you pick yours, SelfAct returns
  the step, the article and the form attached to it.
- **Deadlines** — computed under articles 640 to 643 of the French Code of Civil Procedure
  (mainland, overseas, abroad), exportable to your calendar (`.ics`).
- **Letter templates with gaps** — formal notice, referral to the conciliateur de justice or the
  Défenseur des droits, administrative appeal (recours gracieux), termination, criminal complaint.
  You complete the fields in your browser, then print. A template that imitates the form of a
  legal act carries a "NON OFFICIEL" notice in its body; every template carries a footer
  reminder.

What you type into a template stays in your browser: the server refuses to receive a document (`POST`
rejected with `405`, body unread).

---

## What SelfAct does not do, on purpose

- **It does not analyse your situation** or pick the article or the step for you: legal advice
  is reserved to legal professionals in France (loi n° 71-1130).
- **It fills nothing in for you**: it formats what you provide, without guessing a field or
  turning a story into a claim.
- **It produces no official act**: the completed letter is yours to review and answer for before
  you send it.

---

## Role in Self-Right

| SelfJustice (the law) | SelfAct (the step) |
|---|---|
| Serves the articles, the European texts and the case law | Points to the official resource: form, online service, letter template |
| Checks that a reference exists | Computes the deadline and exports it to your calendar |
| Says what the text says | Gives a template with gaps — the facts and the signature are yours |

---

## Status

**v0.1.2 — running at `justice.my-self.fr/act`.**

Everything is here: [`api/`](api/) the service and its data, [`site/`](site/) the
pages, [`tests/`](tests/) the guards, [`docs/`](docs/) the whitepaper. SelfAct is
served from the same domain as SelfJustice — `justice.my-self.fr/act` — and
exchanges no calls with it: SelfJustice states the law, SelfAct points to the step.

- [x] Concept paper
- [x] Resource catalogue — over 1,800 official resources in 16 categories, harvested from service-public.gouv.fr, refreshed on the 1st and 15th (exact count: `/act/api/catalog.php?stats=1`)
- [x] Situation matching — around twenty hand-curated situations, each mapped to a step, an article and a form
- [x] Deadline engine — art. 640-643 CPC, mainland / overseas / abroad, with calendar export, covered by a CI guard
- [x] Letter templates — formal notice, referral to the conciliateur or the Défenseur des droits, recours gracieux, termination, complaint, each carrying a "NON OFFICIEL" notice in the body when it imitates the form of a legal act, and a footer reminder in every case
- [x] Printing — browser-side, so no draft ever leaves the machine
- [x] Exposed through the SelfRight MCP server (4 of its 12 tools)
- [ ] CERFA XML pre-fill
- [ ] Wider template coverage — more scenarios, more jurisdictions

See **[whitepaper](docs/whitepaper.md)** for the full protocol specification, template library plan, and deployment roadmap.

---

## Author

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*SelfAct — The template is ready. The facts are yours to write.*

---

## Installation notes

The synchronised catalogue does **not** live in the code tree: `update_catalog.sh`
writes it to `/var/lib/selfact/`, and the API reads it there. Two writers do not
share a path — the cron harvests on the 1st and 15th, while the repository is
pushed from a workstation that harvests nothing. As long as both targeted
`api/data/`, the last writer won, and it was the older one.

```bash
sudo install -d -o www-data -g deploy -m 775 /var/lib/selfact
```

The mixed ownership lets the cron write through the group while `www-data`
(nginx/PHP-FPM) reads as usual. `api/data/situations.json` and
`api/data/gabarits.json` stay versioned: they are curated by hand and have a
single writer.
