# SelfRecover-Lite

> 🇬🇧 **[Read in English →](./README.md)**

**Durcir la récupération par e-mail, pour les sites qui la gardent** — le lien et l'appareil, jamais l'un sans l'autre.

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../../LICENSE)
[![Part of: Bi-Self](https://img.shields.io/badge/part%20of-Bi--Self-blue.svg)](../README.fr.md)

---

## Statut

**Conception publiée, aucune implémentation.** Ce dossier ne porte que le whitepaper : rien ne s'installe encore. Le code
viendra avec ses bancs, après le plan du module.

## Ce que c'est

Ton site garde l'adresse e-mail de ses clients — une boutique en ligne, un service grand public — et ne leur demandera pas
de retenir un secret de plus. SelfRecover-Lite garde le « mot de passe oublié » habituel et exige une seconde preuve : un
navigateur que le client a enregistré sur son compte (le « témoin »). Le lien reçu par e-mail ne suffit plus seul, le
témoin non plus.

Sans appareil enregistré, le lien reste nécessaire, avec un mot facultatif choisi à l'avance, ou un recours humain (le
service client) après un délai pendant lequel le client est prévenu et peut s'opposer. Après toute récupération, une
fenêtre de reprise permet au titulaire dépossédé de revenir par un appareil d'avant.

Si ton site accepte de retirer l'e-mail de la récupération, prends [SelfRecover](../selfrecover/README.fr.md). Le choix
entre les deux se fait une fois, avant l'installation.

## Lire

- [Whitepaper (français)](docs/whitepaper-fr.md) · [Whitepaper (English)](docs/whitepaper-en.md)
- [Le comparatif des trois façons de récupérer un compte](../selfrecover/tools/comparison.html)

## Licence

AGPL-3.0-or-later, comme les autres modules MySelf.
