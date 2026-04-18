# SelfAct — Conditions d'utilisation

Version **v0.1.0** — 18 avril 2026 · Licence MIT (code) · Données sous licence Etalab 2.0 (modèles service-public.fr)

## 1. Nature du service

SelfAct est un **outil d'indexation** du catalogue des modèles de lettres officiels publiés par l'administration française (service-public.fr), couplé à un **générateur de brouillons d'aide à la rédaction** clairement identifiés comme non officiels.

**Ce que SelfAct EST :**
- Un **index sémantique** consultable via API des 334 modèles de lettres officiels publiés par service-public.fr sous licence Etalab 2.0
- Un **outil de redirection** vers les URLs officielles service-public.fr
- Un **générateur de brouillons HTML** filigranés « NON OFFICIEL — IRRECEVABLE » pour aider à la rédaction quand aucun modèle officiel ne convient

**Ce que SelfAct N'EST PAS :**
- ❌ Un service de conseil juridique (monopole avocat, loi 71-1130 du 31 décembre 1971)
- ❌ Un service de représentation en justice
- ❌ Un service de rédaction d'actes authentiques (monopole notarial)
- ❌ Un substitut à un avocat pour toute situation complexe ou contentieuse
- ❌ Un éditeur des modèles officiels (nous relayons, nous ne produisons pas)

## 2. Statut juridique

**SelfAct est un outil de formatage de données**, comparable à un moteur de recherche spécialisé ou un agrégateur de contenus publics. Il ne constitue pas un service de paiement, ne détient aucun fonds, ne traite aucune donnée personnelle de tiers, n'exige aucun agrément (DSP2, ACPR, etc.).

Code source MIT, déployable par toute personne, hébergement au choix.

## 3. Modèles officiels redirigés

SelfAct indexe les modèles de lettres publiés par **service-public.fr** (gouvernement français) sous **licence Etalab 2.0** (réutilisation libre avec attribution). L'attribution est réalisée dans la réponse API via le champ `meta.source` et dans chaque redirection par le champ `url`.

L'utilisateur qui accède à un modèle officiel via SelfAct est redirigé vers le site officiel service-public.fr et utilise ce dernier sous ses conditions propres.

## 4. PDF filigrane — dernier recours uniquement

Quand aucun modèle officiel ne couvre précisément la situation, SelfAct produit un **document HTML imprimable en PDF** comportant :

- Un **filigrane SVG diagonal** « NON OFFICIEL — IRRECEVABLE » non-supprimable sans réécriture complète
- Un **disclaimer légal** rappelant l'absence de valeur officielle
- Une **section « informations insuffisantes »** (optionnelle) listant les éléments manquants à collecter

**Ce document n'est pas un acte juridique.** Il sert uniquement de brouillon structuré que l'utilisateur doit recomposer sur son propre papier avant tout envoi, ou faire valider par un professionnel du droit.

## 5. Responsabilité

L'utilisateur utilise SelfAct **sous sa seule responsabilité**. SelfAct ne garantit ni l'exactitude, ni l'actualité, ni l'exhaustivité des modèles redirigés (cette responsabilité relève de service-public.fr), et n'offre aucune garantie quant à l'issue d'une démarche entreprise à partir d'un modèle consulté via SelfAct.

**En cas de doute ou de situation complexe**, l'utilisateur consulte un avocat, une permanence gratuite de la Maison de Justice et du Droit, ou les ressources de point-justice.gouv.fr.

## 6. Données personnelles et tracking

SelfAct **ne collecte aucune donnée personnelle**. En particulier :

- Pas de compte utilisateur
- Pas de cookies de session
- Pas d'analytics ou de tracking tiers
- Pas de journal d'accès nominatif au-delà des logs nginx standards (IP rotés 7 jours, aucun enrichissement)
- Le générateur de PDF filigrane ne conserve aucune trace du contenu traité

Le contenu éventuellement saisi dans le corps POST de `/act/api/draft` est traité en mémoire et renvoyé immédiatement. Aucune persistence serveur.

## 7. Disponibilité et mise à jour

Le catalogue (`/act/api/catalog`) est mis à jour de façon **bimensuelle** (1<sup>er</sup> et 15 de chaque mois) depuis service-public.fr, via un scraper open-source auto-hébergé.

Aucune garantie de disponibilité (99.x%) n'est offerte. Le service est fourni en l'état, auto-hébergé sur un Raspberry Pi 4. En cas d'indisponibilité, l'utilisateur peut accéder directement aux modèles via service-public.fr.

## 8. Licence du code SelfAct

Code source sous **licence MIT** — voir [LICENSE](../../LICENSE) à la racine du repo.

## 9. Contact

- **Repository** : https://github.com/Pierroons/my-self
- **Contact** : pierroons@gmx.fr

## 10. Évolution des CGU

Ces CGU peuvent être modifiées. Chaque version est horodatée dans le repository git. La version en vigueur au jour d'utilisation de SelfAct s'applique.

Les utilisateurs sont informés des changements via le journal des commits git et, pour les évolutions substantielles, par une mention visible sur la page `/act` pendant au moins 30 jours.
