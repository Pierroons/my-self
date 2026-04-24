# Changelog

Tous les changements notables de l'écosystème MySelf sont documentés ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et
l'écosystème respecte un versionnement sémantique au niveau de chaque module.
Ce changelog agrège les jalons transversaux du projet.

---

## [v0.3.0] — 24-25 avril 2026

### Ajouté — Étage applicatif SelfFarm-Lite complet

- **Hub comptable central** (`self_agri_book`) — table SQLite
  `ecritures_comptables` alimentée par tous les modules métier
- **Journal** (`/compta`) — écritures chronologiques avec balance par compte,
  stats globales, filtres par source
- **Compte de résultat** (`/compta/resultat`) — produits classe 7 / charges
  classe 6 → résultat net (bénéfice ou déficit)
- **Bilan comptable** (`/compta/bilan`) — actif (classes 2/3/5 + 4xx débiteur)
  ↔ passif (classe 1 + 4xx créditeur + résultat) avec vérification automatique
  de l'équilibre
- **Export FEC DGFIP** (`/compta/export-fec`) — fichier 18 colonnes
  tab-separated conforme BOI-CF-IOR-60-40-10 (art. L47 A-I LPF)
- **Facture Factur-X du journal** (`/compta/facture-du-journal`) — consolide
  les ventes B2B 411/701 du journal en un seul PDF/A-3 + XML CII EN16931
- **4 sources d'auto-écritures** branchées sur le hub :
  - `self_invoice` → facture Factur-X → 411/701
  - `self_compta_manuel` → vente rapide → 411/701 (B2B facturable vs B2C non-facturable)
  - `self_achats` → achat fournisseur → 6xxx/401
  - `self_banking` → import relevé → lettrage auto 512/411, prélèvements,
    frais bancaires
- **Dédup idempotente** par `(source_module, source_id)` — retenter la même
  pièce ne crée aucun doublon
- **Validation équilibre D/C** automatique via Pydantic model validator
- **PCG Agricole 2026** officiel (ANC + arrêté 1986 + règlement ANC 2019-01) —
  9 classes, 396 comptes, 133 agri-spécifiques

### Ajouté — SelfInvoice multi-régime

- Générateur Factur-X live avec **3 régimes distincts** sur `/invoice` :
  - Franchise TVA (art. 293 B CGI) — mention obligatoire
  - Micro-BA (TVA normale)
  - Réel (simplifié ou normal — même facture légale)
- Pool B2B facturable vs B2C non-facturable
- Articles séparés du libellé comptable (nom + détail + quantité + unité + PU HT)
- Profils Factur-X dynamiques selon régime (BASIC / EN16931)

### Ajouté — self_parcelles IGN live

- Bascule vers la nouvelle API Géoplateforme IGN (`source_ign=BDP`)
- Vraie géométrie des parcelles cadastrales (fin des polygones inventés)
- Calcul de surface géodésique depuis la géométrie (si IGN ne la fournit plus)
- Mode sélection + mode déplacement + recherche par code INSEE/section/numéro

### Ajouté — Landing my-self.fr

- Section "étage applicatif" au-dessus des 3 piliers
- Module `self_agri_book` promu "hub live"
- Module `self_invoice` promu "démo live"
- Bouton "🌻 Essayer la démo" vers `https://selffarm.my-self.fr`

### Ajouté — Haute disponibilité RPI4

- Watchdog matériel BCM2835 activé avec timeout 14s
- Démon `watchdog` userspace installé + configuré
- Reboot automatique garanti en < 30 s si kernel panic ou driver réseau figé
- Test de non-régression passé (kill -STOP daemon → reboot auto effectif)

---

## [v0.2.0] — 19-23 avril 2026

### Ajouté — SelfInvoice beta

- Template visuel canonique (HTML/CSS factures)
- Code Python : core (Invoice, Party, Tax, Payment), builders Factur-X CII XML,
  API FastAPI (routes invoices + payments), intégration Viva Wallet (OAuth2)
- Tests unitaires (Invoice + Factur-X builder)

### Ajouté — Modules SelfFarm-Lite individuels

- `self_dnja` — moteur prévisionnel DNJA 4 ans avec PDF CDOA
- `self_aid` — catalogue d'aides JA Creuse (V1, élargissement NA/AGRI/PME en V2)
- `self_banking` — parser SG Particuliers (approche fake-first)
- `self_agri_book` (squelette) — plan comptable + modèles Pydantic
- `self_factur_x_agri` (squelette) — à fusionner avec `self_invoice`

### Ajouté — Méta-repo MySelf

- Passage de `bi-self` (nom temporaire) à `my-self` (nom définitif)
- Licence bascule MIT → **AGPL-3.0-or-later** sur tout le repo
- Référentiel sources officielles MySelf (Légifrance, BOFiP, service-public,
  FranceAgriMer, GEVES…) — ordre d'autorité strict
- Convention cadence législative bimensuelle (1er + 15 du mois)
- Convention "pattern CAF" pour les engagements sensibles
- Convention "règles IA-robustes" (pas de contre-exemples qui perversent)

### Ajouté — Self-Right opérationnel

- `SelfJustice` en prod sur `justice.my-self.fr`
- `SelfAct` index des 334 modèles officiels service-public.fr
- Compatibilité multi-IA testée (Kimi, DeepSeek, Grok, Mistral, Claude natif)

---

## [v0.1.0] — 1-18 avril 2026

### Ajouté — Les 3 piliers conceptuels

- **Bi-Self** — SelfRecover (récup sans email, HMAC par domaine) + SelfModerate
  (modération par raisonnement social)
- **Self-Right** — SelfJustice (directives juridiques 5 catégories droit FR) +
  SelfAct (courriers, saisines, CERFA)
- **Self-Security** — SelfGuard (destruction garantie sous contrainte) +
  SelfKeyGuard (2FA matérielle objets physiques)

### Ajouté — Infrastructure

- Hébergement Raspberry Pi 4 (hors cloud)
- Cloudflare Tunnels pour exposition (stock, justice, arc, bi-self, my-self)
- Let's Encrypt via certbot
- CrowdSec + UFW
- Mumble + portail Tor hidden service (projet RN2C annexe)

### Ajouté — Tooling

- nginx reverse proxy multi-vhosts
- Auto-update opt-in via `version.json` (pattern RN2C)
- Versionnage systématique (toute app MySelf doit bumper version avant deploy)
- Logging full + toggle console côté user

---

## Avant v0.1.0

Projet en incubation privée — recherches, prototypes, whitepapers.
Pas de version publique.

---

## Conventions de versioning

- **vX.0.0** : jalon majeur (nouvelle dimension, rupture d'architecture)
- **vX.Y.0** : feature release (nouveau module ou refonte significative)
- **vX.Y.Z** : patch (fix, enrichissement mineur)

Chaque module individuel a son propre versionnement sémantique (voir
leurs README respectifs). Ce changelog racine agrège uniquement les
jalons transversaux de l'écosystème.

---

## Auteur

[Pierroons](https://github.com/Pierroons) — JA bio CBD + maraîchage en
installation en Creuse (23), 2026.

Co-écrit avec **Claude** (Anthropic) dans le cadre du « Self pact » humain–IA
décrit dans le [README](./README.md).
