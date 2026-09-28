# SelfJustice

> 🇬🇧 **[Read in English →](./README.md)**

**Pré-analyse juridique impartiale par directives lisibles par IA — servie via une API publique gratuite.**

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../../LICENSE)
[![Statut : v0.4.1 bêta](https://img.shields.io/badge/statut-v0.4.1%20b%C3%AAta-green.svg)](#statut)
[![Live](https://img.shields.io/badge/live-justice.my--self.fr-brightgreen.svg)](https://justice.my-self.fr)
[![Part of: Self-Right](https://img.shields.io/badge/part%20of-Self--Right-blue.svg)](../README.fr.md)
[![Companion of: SelfAct](https://img.shields.io/badge/companion-SelfAct-green.svg)](../selfact/)
[![LEGI](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fjustice.my-self.fr%2Fapi%2Fstatus&query=%24.legi.articles&label=LEGI&suffix=%20articles&color=blue)](https://justice.my-self.fr/api/status)
[![EU/CEDH](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fjustice.my-self.fr%2Fapi%2Fstatus&query=%24.eu.articles&label=EU%2FCEDH&suffix=%20articles&color=blue)](https://justice.my-self.fr/api/status)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

> **Comment accéder à la justice sans se ruiner ?**

---

## Le problème

Le conseil juridique en France se paie à la consultation. La plupart des citoyens face à des conflits quotidiens — licenciement abusif, voisinage bruyant, refus d'indemnisation d'assurance, litiges de consommation — soit abandonnent, soit agissent à l'aveugle sans comprendre leurs droits.

Pendant ce temps, chaque assistant IA (Claude, ChatGPT, Mistral, Gemini, Perplexity) répond volontiers aux questions juridiques, mais sans encadrement structuré il hallucine les citations, loupe la hiérarchie des normes, ne reste pas impartial, et saute les disclaimers obligatoires.

**Et si le cadre juridique lui-même était lisible par machine — et si l'IA savait exactement comment raisonner dessus ?**

---

## La solution

SelfJustice est une **page unique de directives** (HTML) plus une **API HTTP publique** que n'importe quelle IA peut interroger pour produire des pré-analyses juridiques rigoureuses et impartiales.

- La page de directives dit à l'IA **comment raisonner** : impartialité, hiérarchie des normes, base légale obligatoire pour chaque affirmation, glossaire pour non-juristes, disclaimer légal obligatoire.
- L'API dit à l'IA **ce que dit réellement le droit** : tout le corpus juridique français indexé (dump LEGI de la DILA, plus de 500 000 articles) et les textes UE/CEDH (Charte des droits fondamentaux, TFUE, TUE, RGPD, règlement IA 2024/1689, Convention européenne des droits de l'homme). Compteurs en direct : [`/api/status`](https://justice.my-self.fr/api/status).

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
       │                           │  GET /                     │
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
**Coût pour l'opérateur :** le nom de domaine + une machine modeste.

---

## Composants cœur

### 1. Page de directives (`site/index.php`)

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
| `GET /api/status` | Volumétrie et date de synchronisation des trois bases : LEGI, conventionnalité, jurisprudence. Pour la conventionnalité, `provenance` dit source par source si le texte a été relu chez son éditeur ou servi depuis une copie déposée à la main |
| `GET /api/legi/article/{ref}?code={alias}` | Article juridique français (avec désambiguïsation par code : travail, civil, penal, consommation, sante_publique, assurances, urbanisme, route, etc.) |
| `GET /api/legi/search?q=...&limit=...` | Recherche plein texte dans LEGI |
| `GET /api/eu/article/{source}/{num}` | Article UE/CEDH (`source` ∈ `CEDH`, `CHARTE_UE`, `TFUE`, `TUE`, `RGPD`, `AI_ACT`) |
| `GET /api/eu/search?q=...&source=...` | Recherche dans UE/CEDH |
| `GET /api/jurisprudence/verifier/{numero}` | Dit si un numéro d'arrêt existe réellement |
| `GET /api/jurisprudence/search?q=...` | Cherche des décisions par thème |
| `GET /api/jurisprudence/decision/{id}` | Texte intégral d'une décision |
| `GET /api/stats/by-ai` | Stats anonymes publiques : consultations utilisateur par famille d'IA, compte des crawlers |
| `GET /api/stats/by-endpoint` | Top des articles consultés (anonymisés) |

Toutes les endpoints retournent du JSON, toutes sont rate-limitées, toutes ont CORS ouvert.

### 3. Stats & transparence

- Le parsing des logs d'accès distingue les **consultations utilisateur** (Claude-User, ChatGPT-User, Perplexity-User) des **crawlers automatisés** (GPTBot, ClaudeBot, GoogleBot, etc.).
- Le compteur de la homepage affiche le nombre de consultations en temps réel, mis à jour horairement via `build_stats.sh`.
- Les statistiques publiées ne portent que des familles de User-Agent et des chemins d'endpoint — jamais une adresse IP. Le journal d'accès dont elles sont tirées garde, lui, les adresses IP, comme tout serveur web : 14 jours sur l'instance de référence, puis la rotation quotidienne les efface. Pas de compte, pas de cookie. Le formulaire de retour sur la mise en page, si tu t'en sers, garde ce que tu envoies 30 jours.

---

## Stack technique

| Couche | Technologie |
|-------|-----------|
| Serveur web | nginx, CSP qui n'admet que les scripts du site, rate limiting, headers de sécurité |
| Backend | PHP-FPM 8.2 (lecture seule) |
| Base de données | SQLite 3 (dump LEGI parsé en `legi_selfjustice.sqlite`) + SQLite (UE/CEDH) |
| TLS | Let's Encrypt, auto-renouvellement |
| Hôte | serveur auto-hébergé (x86 ou ARM) |
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

Clone le dépôt, pointe nginx sur `site/`, configure `api/api.php` contre ton dump SQLite LEGI. `deploy/selfjustice/` porte le vhost nginx, le script de déploiement et les unités systemd de synchronisation — la configuration de référence, pas un guide pas-à-pas.

---

## Rôle dans Self-Right

SelfJustice sert **le droit**. [SelfAct](../selfact/) sert **la démarche**. Ensemble, ils vont de « que dit le texte ? » à « quel formulaire, quel délai, quel courrier ? » :

1. Tu décris ta situation à ta propre IA, qui lit le droit par SelfJustice et t'explique ce qu'il dit, avec des références vérifiables.
2. SelfAct pointe la ressource officielle de la démarche, calcule le délai et te donne un modèle de lettre à trous.
3. Tu écris les faits, tu relis, tu signes, tu envoies.

Gratuit. Ta question est lue par l'IA que tu choisis ; SelfJustice ne reçoit que les recherches de textes qu'elle lui envoie.

---

## Disclaimer légal

SelfJustice est un **outil d'information**, pas un conseil juridique. Il ne constitue pas :
- Un conseil juridique au sens de la loi n° 71-1130 du 31 décembre 1971
- Une consultation juridique (réservée aux professionnels du droit, loi n° 71-1130, art. 54)
- Un avis juridique contraignant

**Consulte toujours un avocat avant toute action en justice.**

---

## Statut

**v0.4.1 — en production sur [justice.my-self.fr](https://justice.my-self.fr)**

- [x] Directives système (procédure d'analyse en 7 étapes, 5 principes)
- [x] 8 catégories juridiques (travail, logement, famille, administration, voisinage, consommation, civil, pénal)
- [x] Points d'entrée détaillés — droit du logement, droit de la famille, droit administratif
- [x] Template de sortie structuré avec glossaire
- [x] Avertissements sur la loi 71-1130 — la consultation juridique reste réservée aux professionnels du droit
- [x] API servant tout le corpus LEGI — **108 codes** adressables par leur titre, sans table d'alias
- [x] API servant le corpus UE/CEDH (dont le règlement IA 2024/1689)
- [x] Index de jurisprudence judiciaire (Cour de cassation, cours d'appel)
- [x] Index de jurisprudence **administrative**, texte intégral servi (Conseil d'État, CAA,
      Tribunal des conflits ; TA et CDBF en fonds historique) — source : fonds JADE de la DILA
- [x] Serveur MCP (paquet `selfright-mcp`) — consultation depuis un client local
- [x] Intégration SelfAct — les ressources officielles servies à côté des directives
- [x] Testé multi-IA (Claude, crawler ChatGPT, OAI-SearchBot détectés)
- [x] Stats publiques (`/api/stats/by-ai`, `/api/stats/by-endpoint`)
- [x] Domaine dédié [justice.my-self.fr](https://justice.my-self.fr)
- [ ] Relecture formelle par avocat praticien
- [ ] Contributions communautaires pour domaines non couverts

---

## Roadmap

- **v0.1.0** — Directives cœur + 5 catégories + API LEGI/UE
- **v0.2.0** — Droit de la famille (divorce, garde, pension) + droit du logement (baux, expulsion)
- **v0.3.0** — Droit administratif (litiges avec services publics)
- **v0.4.0** — Jurisprudence administrative : Conseil d'État et cours
  administratives d'appel jusqu'au jour dit, Tribunal des conflits, et deux fonds historiques
  — les tribunaux administratifs **s'arrêtent en 2009** et la Cour de discipline budgétaire et
  financière **en 2000**, parce que JADE n'en publie qu'une sélection. Un jugement de TA récent
  ne s'y trouve donc pas ; c'est l'objet de la v0.5.0. Elle ne vient **pas** de l'API Judilibre, qui ne sert que l'ordre judiciaire —
  mesuré le 10/09/2026 : `Value of the jurisdiction parameter must be in [cc,ca,tj,tcom]`. Sa
  source est le fonds **JADE** de la DILA, un dump global et ses incréments quotidiens,
  moissonnés par un collecteur distinct (`tools/build_jade_db.py`). **570 896 décisions au
  11/09/2026**, de 1873 à 2026, texte intégral compris — le compte du jour se lit sur
  `/api/status`. La jurisprudence **judiciaire** (Cour de cassation, cours d'appel) est livrée
  et servie ; `tj` et `tcom` sont disponibles chez l'amont et non moissonnés
- **v0.4.1 (actuelle)** — Les décisions administratives se vérifient par leur numéro, et
  `/verifier` ne nie plus une décision présente derrière des homonymes plus récents. La recherche
  par thème dit qu'elle ne couvre que l'ordre judiciaire, au lieu de rendre l'erreur de l'amont
- **v1.0.0** — Directives relues par un avocat praticien (l'intégration SelfAct est livrée depuis la v0.2.0)

---

## Philosophie

SelfJustice fait partie de l'écosystème **MySelf**, spécifiquement le pilier **Self-Right** :

| Module | Rôle |
|--------|------|
| **SelfJustice** (celui-ci) | Le droit — que dit la loi ? |
| [SelfAct](../selfact/) | La démarche — la ressource officielle, le délai, un modèle de lettre à compléter |

L'humain apporte l'entropie (vécu, faits). La machine apporte l'impartialité (raisonnement structuré, loi citée). Aucun des deux ne suffit seul.

---

## Licence

[AGPL-3.0-or-later](../../LICENSE) — utilise, forke, héberge le tien. Si tu fais tourner une version modifiée en service, tu dois publier tes modifications.

---

## Auteur

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*SelfJustice — parce que la justice ne devrait pas demander de compte bancaire.*
