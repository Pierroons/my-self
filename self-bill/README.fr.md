# Self-Bill

**Facturer + se faire payer, sans intermédiaire.**

> *Facture-le. Encaisse-le. Garde tout.*

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](../LICENSE)
[![SelfInvoice: alpha 0.0.1](https://img.shields.io/badge/SelfInvoice-alpha%200.0.1-lightgrey.svg)](./selfinvoice/)
[![SelfCashpay: alpha 0.0.1](https://img.shields.io/badge/SelfCashpay-alpha%200.0.1-lightgrey.svg)](./selfcashpay/)
[![Part of: MySelf](https://img.shields.io/badge/part%20of-MySelf-blue.svg)](../README.fr.md)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

---

## La tension qu'il adresse

Freelances, TPE, créateurs indépendants, associations — tous font face à la même réalité extractive :

- **Les outils de facturation** (Stripe Invoicing, QuickBooks, Zoho, Pennylane) facturent **10-25 € / mois** pour générer des PDF — une tâche qu'un fichier Word templé règle.
- **Les fournisseurs de paiement** (Stripe, PayPal, Square, Sumup) prennent **1,4 % à 3,5 % + 0,25 €** par transaction, avec versements différés (2-7 jours), rétrofacturations, et gels de comptes à leur discrétion.
- **Les deux** externalisent vos données clients, votre historique de revenus, et votre trésorerie à des sociétés privées qui peuvent vous lâcher à tout moment.

Sur un tip de 50 €/mois : ~1,75 € de commission + ~4 €/mois d'abonnement Stripe = **~12 % des pourboires d'un créateur solo disparaissent** avant qu'il ne les voie.

La réalité technique est que **ni la facturation ni les paiements SEPA n'ont plus besoin d'intermédiaires**. SEPA a standardisé un format de QR-code (EPC069-12) que n'importe quelle app bancaire UE lit nativement. Le format facture est un PDF avec des mentions légales. L'infrastructure existe ; seuls les intermédiaires commerciaux persistent.

Self-Bill les supprime avec deux modules qui ensemble couvrent tout le flux facturation-encaissement.

---

## Pourquoi les deux modules se renforcent mutuellement

**SelfInvoice seul** génère des factures conformes (mentions légales, TVA / franchise en base, indication de retenue, bordereaux) en PDF téléchargeables. Mais un PDF n'est pas payé. Le client doit encore un moyen de payer — d'habitude une ligne « virement à IBAN XXX » dans la facture, qui nécessite copier-coller et est rarement fait sur mobile.

**SelfCashpay seul** génère un QR-code SEPA EPC069-12 que le client scanne avec son app bancaire. Le virement est pré-rempli (montant, IBAN, BIC, référence). Le client confirme juste et l'argent atterrit sur votre IBAN **instantanément**. Mais un QR-code sans facture n'est pas traçable — pas de trace légale, pas d'audit, pas de preuve TVA.

**Ensemble**, le cycle est complet :

1. SelfInvoice génère une facture PDF conforme avec toutes les mentions légales.
2. La même facture embarque un QR-code SelfCashpay correspondant exactement au montant + référence facturés.
3. Le client reçoit le PDF, scanne le QR, confirme dans son app bancaire, l'argent arrive sur l'IBAN de l'auteur.
4. Rapprochement automatisé : quand un virement SEPA arrive avec une référence qui matche, SelfInvoice marque la facture comme payée.

**Zéro commission.** **Aucun intermédiaire ne détient les fonds.** **Pas besoin d'agrément bancaire** car les outils ne touchent jamais l'argent — seulement les données autour. Juridiquement plus simple que Stripe, plus rapide à intégrer, et le créateur garde tout.

---

## Workflows croisés

- **Photographe freelance, facture de 450 €** → SelfInvoice génère le PDF (avec mention URSSAF, franchise en base TVA, SIRET client). Le QR SEPA correspond à 450 € + référence `FACT-2026-0042`. Le client scanne sur son téléphone, confirme, terminé en 15 secondes. Le photographe voit « FACT-2026-0042 payée » apparaître automatiquement.
- **Association recevant cotisations** → Templates SelfInvoice pour cotisations annuelles 10/20/50 €. QR SelfCashpay correspondant au montant attendu. Les membres paient en un scan, le trésorier de l'association a un audit trail propre.
- **Tip jar sur site de créateur** → SelfCashpay pur, pas besoin de facture pour les tips sous les seuils légaux. QR affiché sur le site, le visiteur scanne avec son app bancaire, le virement arrive. Le créateur configure juste son IBAN une fois.
- **Facture B2B sous-traitance** → SelfInvoice impose les mentions légales B2B (SIRET, TVA intracommunautaire, délai de paiement art. L441-10 Code de commerce). QR SelfCashpay avec référence virement 30 jours pour rapprochement automatisé.

---

## Modules du binôme

| Module | Rôle | Statut |
|--------|------|--------|
| [SelfInvoice](./selfinvoice/) | Générateur de factures conformes (PDF, mentions légales, audit trail) | alpha 0.0.1 — phase concept |
| [SelfCashpay](./selfcashpay/) | Paiements par QR-code SEPA (EPC069-12), zéro commission | alpha 0.0.1 — phase concept |

---

## Statut

Les deux modules sont en **phase concept** (alpha 0.0.1). Les whitepapers spécifient :
- SelfInvoice : templates PDF pour micro-entrepreneur / SASU / associations, matrice de mentions légales par statut, moteur de rapprochement.
- SelfCashpay : générateur de QR EPC069-12, matrice de compatibilité IBAN-app bancaire, template de page de tips auto-hébergée.

Les implémentations prototypes sont prévues pour la v0.1.0. Déploiement cible : `bill.my-self.fr/invoice` et `bill.my-self.fr/cashpay`.

Le pitch économique est simple : remplacer Stripe Invoicing + Stripe Payments pour un créateur solo économise **~150 € / an** et garde les données 100 % locales. Porté à l'échelle des associations et micro-entrepreneurs, ça devient une alternative concrète aux SaaS de fiscalité.

---

## Auteur

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*Self-Bill — Chaque euro que tu gagnes devrait te parvenir entier.*
