# SelfAct

> 🇬🇧 **[Read in English →](./README.md)**

**De « je connais mes droits » à la démarche : le formulaire officiel, le délai, un modèle de lettre à compléter.**

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../../LICENSE)
[![Statut : v0.1.2 en service](https://img.shields.io/badge/statut-v0.1.2%20en%20service-brightgreen.svg)](#statut)
[![Part of: Self-Right](https://img.shields.io/badge/part%20of-Self--Right-blue.svg)](../README.fr.md)
[![Companion of: SelfJustice](https://img.shields.io/badge/companion-SelfJustice-green.svg)](../selfjustice/)
[![Read in English](https://img.shields.io/badge/lang-english-blue.svg)](./README.md)

> **Connais tes droits. Maintenant rends-les réels.**

---

## Ce que SelfAct couvre

[SelfJustice](../selfjustice/) sert le droit : les articles en vigueur, les textes européens,
la jurisprudence. Ton IA s'en sert pour s'appuyer sur les textes en vigueur, avec des références vérifiables, plutôt que sur sa mémoire. Reste à
agir : quel formulaire remplir, quel service en ligne utiliser, dans quel délai, comment écrire
le courrier.

SelfAct couvre cette étape. Il **ne rédige pas ton dossier** : il te montre la ressource
officielle, calcule le délai, et te donne un modèle de lettre à trous. Les faits, c'est toi qui
les écris.

---

## Ce que fait SelfAct

- **Catalogue des ressources officielles** — formulaires CERFA, téléservices et modèles de
  lettres de service-public.gouv.fr, moissonnés les 1er et 15 du mois (compte exact :
  `/act/api/catalog.php?stats=1`).
- **Situations** — une vingtaine de situations courantes, curées à la main ; tu choisis la
  tienne, SelfAct rend la démarche, l'article et le formulaire qui lui sont rattachés.
- **Délais** — calcul selon les articles 640 à 643 du code de procédure civile (métropole,
  outre-mer, étranger), exportable dans ton agenda (`.ics`).
- **Modèles de lettres à trous** — mise en demeure, saisine du conciliateur de justice ou du
  Défenseur des droits, recours gracieux, résiliation, dépôt de plainte.
  Tu complètes les champs dans ton navigateur, puis tu imprimes. Un modèle qui imite la forme
  d'un acte juridique porte la mention « NON OFFICIEL » dans son corps ; tous portent un rappel
  en pied de page.

Ce que tu écris dans un modèle ne quitte pas ton navigateur : le serveur refuse de recevoir un document
(`POST` refusé en `405`, sans lire le corps).

---

## Ce que SelfAct ne fait pas, volontairement

- **Il n'analyse pas ta situation** et ne choisit pas pour toi l'article ou la démarche : la
  consultation juridique est réservée aux professionnels du droit (loi n° 71-1130).
- **Il ne remplit rien à ta place** : il met en forme ce que tu fournis, sans deviner un champ
  ni formuler une demande à partir d'un récit.
- **Il ne produit pas d'acte officiel** : le courrier complété est le tien, tu le relis et tu en
  assumes le contenu avant de l'envoyer.

---

## Rôle dans Self-Right

| SelfJustice (le droit) | SelfAct (la démarche) |
|---|---|
| Sert les articles, les textes européens et la jurisprudence | Pointe la ressource officielle : formulaire, téléservice, modèle de lettre |
| Vérifie qu'une référence existe | Calcule le délai et l'exporte dans ton agenda |
| Dit ce que dit le texte | Donne un modèle à trous — les faits et la signature sont à toi |

---

## Statut

**v0.1.2 — en service sur `justice.my-self.fr/act`.**

Tout est ici : [`api/`](api/) le service et ses données, [`site/`](site/) les
pages, [`tests/`](tests/) les garde-fous, [`docs/`](docs/) le whitepaper. SelfAct
est servi par le même domaine que SelfJustice — `justice.my-self.fr/act` — et
n'échange aucun appel avec lui : SelfJustice dit le droit, SelfAct pointe
la démarche.

- [x] Note de conception
- [x] Catalogue de ressources — plus de 1 800 ressources officielles en 16 catégories, moissonnées sur service-public.gouv.fr, rafraîchies les 1er et 15 (compte exact : `/act/api/catalog.php?stats=1`)
- [x] Aiguillage par situation — une vingtaine de situations curées à la main, chacune reliée à un acte, un article et un formulaire
- [x] Moteur d'échéance — art. 640-643 CPC, métropole / outre-mer / étranger, avec export agenda, couvert par un garde-fou en CI
- [x] Gabarits de courrier — mise en demeure, saisine du conciliateur ou du Défenseur des droits, recours gracieux, résiliation, plainte, chacun portant la mention « NON OFFICIEL » dans le corps quand il imite la forme d'un acte, et le rappel en pied dans tous les cas
- [x] Impression côté navigateur : aucun brouillon ne quitte la machine
- [x] Exposé par le serveur MCP SelfRight (4 de ses 12 outils)
- [ ] Pré-remplissage XML des CERFA
- [ ] Couverture de gabarits plus large — plus de scénarios, plus de juridictions

Voir **[whitepaper](docs/whitepaper.md)** pour la spécification complète du protocole, le plan de bibliothèque de templates, et la roadmap de déploiement.

---

## Auteur

**Pierroons** — [github.com/Pierroons/my-self](https://github.com/Pierroons/my-self)

*SelfAct — Le modèle est prêt. Les faits, c'est toi qui les écris.*

---

## Notes d'installation

Le catalogue synchronisé ne vit **pas** dans l'arbre du code : `update_catalog.sh`
l'écrit dans `/var/lib/selfact/`, et l'API l'y lit. Deux écrivains ne partagent
pas un chemin — le cron moissonne les 1er et 15, le dépôt est poussé depuis un
poste qui, lui, ne moissonne rien. Tant que les deux visaient `api/data/`, le
dernier qui écrivait gagnait, et c'était le plus vieux.

```bash
sudo install -d -o www-data -g deploy -m 775 /var/lib/selfact
```

L'appartenance mixte laisse le cron écrire par le groupe et `www-data`
(nginx/PHP-FPM) lire normalement. `api/data/situations.json` et
`api/data/gabarits.json` restent versionnés : ils sont curés à la main et n'ont
qu'un seul écrivain.
