# SelfJustice

> 🇬🇧 **[Read in English →](./README.md)**

**Pré-analyse juridique impartiale par directives lisibles par IA — servie via une API publique gratuite.**

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../../LICENSE)
[![Status: v0.1.0](https://img.shields.io/badge/status-v0.1.0-green.svg)](#statut)
[![Live](https://img.shields.io/badge/live-justice.my--self.fr-brightgreen.svg)](https://justice.my-self.fr)
[![Part of: Self-Right](https://img.shields.io/badge/part%20of-Self--Right-blue.svg)](../README.fr.md)
[![Companion of: SelfAct](https://img.shields.io/badge/companion-SelfAct-green.svg)](../selfact/)
[![LEGI: 488 903 articles](https://img.shields.io/badge/LEGI-488%20903%20articles-blue.svg)](https://justice.my-self.fr/api/status)
[![EU/CEDH: 705 articles](https://img.shields.io/badge/EU%2FCEDH-705%20articles-blue.svg)](https://justice.my-self.fr/api/status)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

> **Comment accéder à la justice sans se ruiner ?**

---

## Le problème

Le conseil juridique en France coûte 50–300 € par consultation. La plupart des citoyens face à des conflits quotidiens — licenciement abusif, voisinage bruyant, refus d'indemnisation d'assurance, litiges de consommation — soit abandonnent, soit agissent à l'aveugle sans comprendre leurs droits.

Pendant ce temps, chaque assistant IA (Claude, ChatGPT, Mistral, Gemini, Perplexity) répond volontiers aux questions juridiques, mais sans encadrement structuré il hallucine les citations, loupe la hiérarchie des normes, ne reste pas impartial, et saute les disclaimers obligatoires.

**Et si le cadre juridique lui-même était lisible par machine — et si l'IA savait exactement comment raisonner dessus ?**

---

## La solution

SelfJustice est une **page unique de directives** (HTML) plus une **API HTTP publique** que n'importe quelle IA peut interroger pour produire des pré-analyses juridiques rigoureuses et impartiales.

- La page de directives dit à l'IA **comment raisonner** : impartialité, hiérarchie des normes, base légale obligatoire pour chaque affirmation, glossaire pour non-juristes, disclaimer légal obligatoire.
- L'API dit à l'IA **ce que dit réellement le droit** : 488 903 articles juridiques français indexés (dump LEGI de la DILA) + 705 articles UE/CEDH (Charte des droits fondamentaux, TFUE, TUE, RGPD, Convention européenne des droits de l'homme).

N'importe quelle IA. N'importe quel citoyen. N'importe quel conflit. Une pré-analyse cohérente, sourcée, impartiale.

---

## Architecture

```
┌──────────────┐           ┌───────────────┐           ┌──────────────────┐
│ Utilisateur  │           │    IA user    │           │   SelfJustice    │
│   (conflit)  │           │ (tout modèle) │           │ (statique + API) │
└──────┬───────┘           └───────┬───────┘           └────────┬─────────┘
       │                           │                            │
       │  « mon patron me          │                            │
       │   harcèle, analyse        │                            │
       │   justice.my-self.fr »    │                            │
       │──────────────────────────>│                            │
       │                           │  GET /directives.html      │
       │                           │───────────────────────────>│
       │                           │<───────────────────────────│
       │                           │  [lit les directives]      │
       │                           │                            │
       │                           │  GET /api/legi/article/    │
       │                           │    L1152-1?code=travail    │
       │                           │───────────────────────────>│
       │                           │<───────────────────────────│
       │                           │  {texte officiel article + │
       │                           │   date en vigueur + source}│
       │                           │                            │
       │                           │  GET /api/eu/article/      │
       │                           │    CEDH/8                  │
       │                           │───────────────────────────>│
       │                           │<───────────────────────────│
       │<──────────────────────────│                            │
       │  Analyse structurée :     │                            │
       │  qualification, parties,  │                            │
       │  base légale par partie,  │                            │
       │  forces/faiblesses,       │                            │
       │  voies de recours,        │                            │
       │  délais, glossaire,       │                            │
       │  disclaimer.              │                            │
```

**Coût pour l'utilisateur :** zéro (il utilise son propre abonnement IA).
**Coût pour l'opérateur :** le nom de domaine + l'hébergement d'un Raspberry Pi.

---

## Composants cœur

### 1. Page de directives (`site/index.html`)

Directives machine-readable disant à l'IA comment raisonner :

- **Rôle** : pré-analyste, pas avocat. La frontière de la loi n° 71-1130 du 31 décembre 1971 strictement respectée.
- **Principes** : impartialité (les deux parties analysées), base légale obligatoire, pas de conseil stratégique, disclaimer en entrée et sortie, détection explicite du hors-scope.
- **Procédure** : analyse en 7 étapes (qualification, faits, articles par partie, forces/faiblesses, voies de recours, délais, sortie).
- **Template de sortie** : 11 sections incluant un glossaire obligatoire pour non-juristes.
- **Transparence des sources** : chaque citation inclut provenance + date + niveau de fiabilité.
- **Hiérarchie des normes** : Constitution → CEDH/traités UE → Codes → Règlements → Jurisprudence.

### 2. API publique

| Endpoint | Usage |
|----------|-------|
| `GET /api/status` | Total articles, date dernière sync, ventilation par source |
| `GET /api/legi/article/{ref}?code={alias}` | Article juridique français (avec désambiguïsation par code : travail, civil, penal, consommation, sante_publique, assurances, urbanisme, route, etc.) |
| `GET /api/legi/search?q=...&limit=...` | Recherche plein texte dans LEGI |
| `GET /api/eu/article/{source}/{num}` | Article UE/CEDH (`source` ∈ `CEDH`, `CHARTE_UE`, `TFUE`, `TUE`, `RGPD`) |
| `GET /api/eu/search?q=...&source=...` | Recherche dans UE/CEDH |
| `GET /api/stats/by-ai` | Stats anonymes publiques : consultations utilisateur par famille d'IA, compte des crawlers |
| `GET /api/stats/by-endpoint` | Top des articles consultés (anonymisés) |

Toutes les endpoints retournent du JSON, toutes sont rate-limitées, toutes ont CORS ouvert.

### 3. Stats & transparence

- Le parsing des logs d'accès distingue les **consultations utilisateur** (Claude-User, ChatGPT-User, Perplexity-User) des **crawlers automatisés** (GPTBot, ClaudeBot, GoogleBot, etc.).
- Le compteur de la homepage affiche le nombre de consultations en temps réel, mis à jour horairement via `build_stats.sh`.
- Zéro IP loguée, zéro cookie, aucun contenu utilisateur stocké. Uniquement les familles User-Agent anonymisées et les chemins d'endpoint.

---

## Stack technique

| Couche | Technologie |
|-------|-----------|
| Serveur web | nginx 1.22 avec CSP strict, rate limiting, headers de sécurité |
| Backend | PHP-FPM 8.2 (lecture seule) |
| Base de données | SQLite 3 (dump LEGI parsé en `legi_selfjustice.sqlite`) + SQLite (UE/CEDH) |
| TLS | Let's Encrypt, auto-renouvellement |
| Hôte | Raspberry Pi 4, auto-hébergé |
| Cron | Sync LEGI bimensuelle + reconstruction des stats horaire |

---

## Essayer

### Depuis n'importe quelle interface IA

1. Ouvrir [claude.ai](https://claude.ai), Mistral Le Chat, ChatGPT, Gemini, Perplexity
2. Décrire son conflit en langage courant
3. Ajouter : `analyse justice.my-self.fr`
4. Recevoir une pré-analyse structurée avec citations d'articles officiels

### Depuis la ligne de commande

```bash
# Vérifier le statut de la base
curl -s https://justice.my-self.fr/api/status | jq

# Récupérer un article spécifique
curl -s "https://justice.my-self.fr/api/legi/article/L1152-1?code=travail" | jq

# Recherche plein texte
curl -s "https://justice.my-self.fr/api/legi/search?q=harcelement&limit=20" | jq
```

### Auto-héberger

Cloner le repo, pointer nginx sur `site/`, configurer `api/api.php` contre votre dump SQLite LEGI, terminé. Guide d'installation complet dans `deploy/`.

---

## Rôle dans Self-Right

SelfJustice **diagnostique**. [SelfAct](../selfact/) **agit**. Ensemble ils couvrent l'arc complet de « je pense que je suis dans mes droits » à « la mise en demeure est signée et envoyée » :

1. L'utilisateur décrit le conflit → SelfJustice retourne une analyse JSON structurée.
2. SelfAct prend ce JSON → génère mise en demeure, saisine, CERFA, calendrier.
3. L'utilisateur télécharge un dossier ZIP, envoie en RAR à La Poste.

Zéro frais de consultation. Zéro cloud. Zéro intermédiaire.

---

## Disclaimer légal

SelfJustice est un **outil d'information**, pas un conseil juridique. Il ne constitue pas :
- Un conseil juridique au sens de la loi n° 71-1130 du 31 décembre 1971
- Une consultation juridique (réservée aux avocats inscrits au Barreau)
- Un avis juridique contraignant

**Consultez toujours un avocat avant toute action en justice.**

---

## Statut

**v0.1.0 — en production sur [justice.my-self.fr](https://justice.my-self.fr)**

- [x] Directives système (procédure d'analyse en 7 étapes, 5 principes)
- [x] 5 catégories juridiques (travail, voisinage, consommation, civil, pénal)
- [x] Template de sortie structuré avec glossaire
- [x] Disclaimers légaux (conforme loi 71-1130)
- [x] API avec 488 903 articles LEGI
- [x] API avec 705 articles UE/CEDH
- [x] Testé multi-IA (Claude, crawler ChatGPT, OAI-SearchBot détectés)
- [x] Stats publiques (`/api/stats/by-ai`, `/api/stats/by-endpoint`)
- [x] Domaine dédié [justice.my-self.fr](https://justice.my-self.fr)
- [ ] Relecture formelle par avocat praticien
- [ ] Contributions communautaires pour domaines non couverts

---

## Roadmap

- **v0.1.0 (actuelle)** — Directives cœur + 5 catégories + API LEGI/UE
- **v0.2.0** — Droit de la famille (divorce, garde, pension) + droit du logement (baux, expulsion)
- **v0.3.0** — Droit administratif (litiges avec services publics)
- **v0.4.0** — Intégration jurisprudence (Cass., CE, Conseil constitutionnel)
- **v1.0.0** — Directives peer-reviewed + intégration SelfAct

---

## Philosophie

SelfJustice fait partie de l'écosystème **MySelf**, spécifiquement le pilier **Self-Right** :

| Module | Rôle |
|--------|------|
| **SelfJustice** (celui-ci) | Diagnostiquer — que dit la loi ? |
| [SelfAct](../selfact/) | Agir — rédiger la mise en demeure, remplir le CERFA, calendrier des délais |

L'humain apporte l'entropie (vécu, faits). La machine apporte l'impartialité (raisonnement structuré, loi citée). Aucun des deux ne suffit seul.

---

## Licence

[AGPL-3.0-or-later](../../LICENSE) — utilisez, forkez, hébergez le vôtre. Si vous faites tourner une version modifiée en service, vous devez publier vos modifications.

---

## Auteur

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*SelfJustice — parce que la justice ne devrait pas demander de compte bancaire.*
