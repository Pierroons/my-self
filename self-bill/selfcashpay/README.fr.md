# SelfCashpay

**Paiements par QR-code SEPA — standard EPC069-12, zéro commission, pas d'intermédiaire.**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](../../LICENSE)
[![Status: alpha 0.0.1](https://img.shields.io/badge/status-alpha%200.0.1-lightgrey.svg)](#statut)
[![Part of: Self-Bill](https://img.shields.io/badge/part%20of-Self--Bill-blue.svg)](../README.fr.md)
[![Companion of: SelfInvoice](https://img.shields.io/badge/companion-SelfInvoice-green.svg)](../selfinvoice/)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

> **Scanne. Confirme. Payé. Personne au milieu.**

---

## Le standard oublié

En 2013, l'European Payments Council a publié **EPC069-12** : une spécification pour encoder un virement SEPA dans un QR code. Toutes les banques UE majeures l'ont intégrée : BNP Paribas, Société Générale, ING, N26, Revolut, Boursorama, Crédit Mutuel — toutes parsent ce format nativement.

Pendant douze ans, ce standard est **resté dans les cartons**, parce que les fournisseurs de paiement commerciaux n'ont aucun intérêt à promouvoir une alternative gratuite et sans commission à leurs 2-3 %. Stripe et consorts n'en parlent pas. L'app de votre banque le supporte en silence. Pas de marketing. Pas de campagne d'information.

SelfCashpay remet EPC069-12 en lumière.

---

## Comment ça marche

Le payeur scanne un QR code. Son app bancaire s'ouvre et affiche :

```
Virement SEPA
-------------
Bénéficiaire : Jeanne Dupont
IBAN        : FR76 1234 5678 9012 3456 7890 123
Montant     : 45,00 €
Référence   : FACT-2026-0042
```

Le payeur confirme dans son app. Le virement s'exécute via les rails SEPA standards. L'argent arrive sur l'IBAN du bénéficiaire **en quelques minutes** (SEPA Instant) ou en fin de journée (SEPA classique).

**Aucun intermédiaire** ne touche l'argent. **Aucun fournisseur** ne prend de commission. **Aucun compte** n'est ouvert chez qui que ce soit. Le payeur utilise sa banque normale, le bénéficiaire reçoit sur son IBAN normal.

---

## Principe cœur : données pures, zéro détention

SelfCashpay génère un QR code. C'est tout. Il ne :
- Détient pas de fonds
- N'ouvre pas de compte
- N'exige pas de KYC
- N'a pas besoin d'agrément de paiement (DSP2, EME, AISP)
- Ne traite pas de paiements

Parce qu'il ne traite pas de paiements. Il **encode des données de paiement** que la banque du payeur exécute. Juridiquement, SelfCashpay est un **outil de formatage de données**, pas un service financier. Même catégorie que `iconv` ou `base64`.

Ça change tout. Pas de capture réglementaire. Pas de vendor lock-in. N'importe qui peut auto-héberger, forker, étendre, intégrer dans n'importe quel autre produit.

---

## Le format QR (EPC069-12)

```
BCD
002
1
SCT
BNPAFRPPXXX               ← BIC (optionnel en zone SEPA)
JEANNE DUPONT             ← Nom bénéficiaire (70 caractères max)
FR7612345678901234567890123  ← IBAN
EUR45.00                  ← Devise + montant
                          ← Purpose (4 caractères, optionnel)
FACT-2026-0042            ← Référence (35 caractères max)
                          ← Texte libre (140 caractères, optionnel)
```

Chaque champ sur sa ligne, dans cet ordre, encodé en UTF-8, emballé en QR code **text/plain** (n'importe quel générateur marche : Python `qrcode`, JS `qrcode-generator`, CLI `qrencode`).

Voilà tout le « protocole ». 10 lignes de texte, 800 octets de QR, 100 % interopérable sur les apps bancaires UE.

---

## Cas d'usage

- **Tip jar** sur site de créateur ou overlay stream.
- **Collecte de cotisations** d'une association (un QR pour 10 €, un pour 20 €, etc.).
- **Paiement de facture** intégré dans un PDF SelfInvoice.
- **Point-of-sale** pour petits commerçants qui ne veulent pas de Sumup (imprime juste le QR, met à jour le montant chaque jour).
- **Crowdfunding** avec traçabilité totale par le champ référence.
- **Régler une addition de restaurant** sans que la serveuse touche un Sumup.

---

## Rôle dans Self-Bill

SelfCashpay est le **générateur de données de paiement**. [SelfInvoice](../selfinvoice/) est le **générateur de données de facturation**. Ensemble :

```
SelfInvoice génère la facture PDF
        │
        │  (embarque le QR de SelfCashpay)
        ▼
PDF montré au client — QR visible dans le document
        │
        │  le client scanne le QR avec son app bancaire
        ▼
La banque du client pré-remplit le virement (montant + IBAN + référence)
        │
        │  le client confirme
        ▼
L'argent arrive sur l'IBAN de l'utilisateur SelfInvoice
        │
        │  webhook notification SEPA ou parsing email
        ▼
SelfInvoice rapproche le paiement et marque la facture payée
```

**Le cycle facturation-encaissement complet, sans intermédiaire, sans commission, sans compte chez un fournisseur.**

---

## Statut

**alpha 0.0.1 — phase concept.**

- [x] Revue de la spec EPC069-12
- [x] Générateur QR Python de référence
- [x] Test de compatibilité sur 12 apps bancaires UE majeures
- [ ] Template de page de tips auto-hébergée
- [ ] Watcher webhook pour banques courantes (API BNP, API N26, API Qonto, etc.)
- [ ] Intégration SelfInvoice (module embed)
- [ ] Prototype v0.1.0 avec UI minimale pour montant + référence
- [ ] Déploiement cible : `bill.my-self.fr/cashpay`

Voir **[whitepaper](docs/whitepaper.docx)** pour le décryptage complet d'EPC069-12, la matrice de compatibilité bancaire, et le guide d'auto-hébergement.

---

## Auteur

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*SelfCashpay — L'EPC nous a offert un rail de paiement gratuit. Utilisons-le.*
