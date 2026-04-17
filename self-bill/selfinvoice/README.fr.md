# SelfInvoice

**Générateur de factures conformes — PDF, mentions légales, zéro cloud.**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](../../LICENSE)
[![Status: alpha 0.0.1](https://img.shields.io/badge/status-alpha%200.0.1-lightgrey.svg)](#statut)
[![Part of: Self-Bill](https://img.shields.io/badge/part%20of-Self--Bill-blue.svg)](../README.fr.md)
[![Companion of: SelfCashpay](https://img.shields.io/badge/companion-SelfCashpay-green.svg)](../selfcashpay/)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

> **Ta facture. Ton template. Tes données. Terminé.**

---

## Le problème

La génération de factures est un **marché SaaS de 5 milliards de dollars** pour une tâche qui se résume à : « remplir un template avec 10 champs et générer un PDF ». Les fournisseurs actuels :

- **Stripe Invoicing** : limites du free tier, 0,4 % par facture payée via Stripe en plus des frais de paiement
- **Pennylane / QuickBooks / Zoho** : 10-25 € / mois avec multiplicateurs « par utilisateur »
- **Tiime, Indy, Freebe** : 7-20 € / mois, vous enferment dans leur pipeline comptable
- **Tous** : hébergent votre liste clients sur leurs serveurs, conditionnent votre capacité à facturer à votre abonnement

Pour un freelance qui génère 10 factures par mois, **le stack Stripe+comptabilité SaaS coûte en moyenne 200-350 € / an** — pour une tâche qu'un template PDF convenable règle.

Le pire : chacun de ces outils est **plus complexe que l'exigence légale**. Une facture française valide demande ~12 mentions légales obligatoires ([art. 242 nonies A Annexe II CGI](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000038613081)). C'est tout. Pas de SaaS nécessaire, pas de cloud nécessaire.

---

## Vision

SelfInvoice est un **générateur de factures local-first** avec :

- Templates pour chaque statut français courant : micro-entrepreneur, SASU, association, profession libérale, auto-entrepreneur avec TVA.
- Insertion automatique des mentions légales requises selon votre statut.
- Génération PDF via `weasyprint` (pixel-perfect, pas de dépendance au rendu navigateur).
- **Aucune donnée ne quitte votre machine** — la liste clients est une base SQLite locale.
- QR code [SelfCashpay](../selfcashpay/) intégré pour paiement SEPA en un scan.
- Rapprochement automatique quand un virement SEPA matche la référence de la facture.

---

## Architecture cœur

```
┌─────────────────┐      ┌──────────────┐      ┌───────────────┐
│  Base SQLite    │      │  Moteur      │      │  Rendu PDF    │
│  (clients,      │──────│  templates   │──────│  (weasyprint) │
│   factures,     │      │  (jinja2)    │      │               │
│   produits)     │      │              │      │               │
└─────────────────┘      └──────────────┘      └───────────────┘
        │                                              │
        │                                              ▼
        │                                      ┌──────────────────┐
        │                                      │  facture-{ref}.  │
        │                                      │  pdf             │
        │                                      └──────────────────┘
        │                                              │
        │                                              ▼
        │                                      ┌──────────────────┐
        └─────────────────────────────────────→│  QR SEPA embed   │
                                               │  (SelfCashpay)   │
                                               └──────────────────┘
```

Déploiement en binaire unique, tourne sur un laptop, un téléphone (via Termux), ou un Raspberry Pi.

---

## Matrice de mentions légales

SelfInvoice embarque une **matrice** qui détermine quelles mentions légales inclure selon le statut de l'émetteur :

| Statut | Mentions requises |
|--------|------------------|
| Micro-entrepreneur | SIRET, RCS/RM, « TVA non applicable, art. 293 B CGI » si franchise en base |
| SASU | SIRET, RCS, capital, SIREN, TVA intra. |
| Association (but non lucratif) | N° RNA, statut « association loi 1901 », TVA exempt. |
| Profession libérale | SIRET, RCS (si commercial), N° ordre (si réglementé) |
| Tous B2B | Délai de paiement (art. L441-10), taux de pénalité, indemnité 40 € (D441-5) |
| Tous | Nom émetteur, adresse, nom client, adresse, date facture, réf. unique, % TVA et montant |

La matrice est **un fichier JSON dans le repo** — auditable, forkable, patchable quand la loi change.

---

## Rôle dans Self-Bill

SelfInvoice génère le **document conforme**. [SelfCashpay](../selfcashpay/) génère le **QR code de paiement**. Ensemble ils forment le cycle facturation-encaissement complet sans aucun intermédiaire :

1. SelfInvoice → PDF avec toutes mentions légales + QR SEPA intégré (délégué à SelfCashpay).
2. Le client scanne le QR sur téléphone → l'app bancaire pré-remplit le virement.
3. Le client confirme le virement → argent sur votre IBAN en minutes.
4. SelfInvoice surveille les notifications SEPA → matche la référence → marque la facture payée.

**Zéro commission. Aucune détention de fonds. Zéro abonnement.**

---

## Statut

**alpha 0.0.1 — phase concept.**

- [x] Matrice de mentions légales (JSON)
- [x] Brouillon de bibliothèque de templates (micro-entrepreneur, SASU, association)
- [ ] Rendu PDF (pipeline weasyprint)
- [ ] Schéma SQLite clients/factures
- [ ] Intégration SelfCashpay (embed QR)
- [ ] Watcher de notifications SEPA (webhook ou polling email)
- [ ] Prototype v0.1.0 avec CLI + UI web
- [ ] Déploiement cible : `bill.my-self.fr/invoice`

Voir **[whitepaper](docs/whitepaper.docx)** pour le cadre légal complet, l'architecture des templates, et le guide de déploiement.

---

## Auteur

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*SelfInvoice — Parce qu'un PDF reste juste un PDF.*
