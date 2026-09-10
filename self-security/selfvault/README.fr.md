# SelfVault

> 🇬🇧 **[Read in English →](./README.md)**

**Un coffre de directives post-mortem à deux serrures indépendantes, imprimable en QR codes et déposable chez un notaire.**

[![Licence : AGPL v3](https://img.shields.io/badge/Licence-AGPL_v3-blue.svg)](../../LICENSE)
[![Statut : format arrêté, non déposé](https://img.shields.io/badge/statut-format%20arr%C3%AAt%C3%A9%2C%20non%20d%C3%A9pos%C3%A9-orange.svg)](#statut)
[![Pilier : Self-Security](https://img.shields.io/badge/pilier-Self--Security-blue.svg)](../README.fr.md)
[![Voisin : SelfDataGuard](https://img.shields.io/badge/voisin-SelfDataGuard-green.svg)](../selfdataguard/README.fr.md)

---

## Le problème

Deux exigences qui se contredisent. **Du vivant du titulaire**, personne d'autre que lui ne doit pouvoir ouvrir. **Après sa mort**, un proche désigné doit pouvoir ouvrir.

Rien dans MySelf ne couvre ce passage. SelfRecover restaure un *accès*, jamais des *données*. SelfDataGuard chiffre, mais ne dit rien de qui ouvre quand le titulaire n'est plus là. Et la règle « passphrase perdue = données perdues », saine du vivant, décrit exactement le scénario qu'on veut couvrir une fois la personne morte.

Les dispositifs à minuterie exigent qu'un serveur tourne encore dans dix ans. Le partage à seuil entre proches suppose que les proches conservent leurs parts et ne se réunissent pas trop tôt. Un pli scellé chez un notaire est compris par tout le monde sans explication.

## Comment ça marche

Une clé maîtresse tirée au sort chiffre le contenu. Elle est ensuite enfermée **deux fois**, dans deux enveloppes indépendantes :

| serrure | secret | détenteur | entropie |
|---|---|---|---|
| **L1** | code de récupération imprimé | le dépositaire | 98 bits |
| **L2** | phrase de passe tirée au sort | le titulaire | ≥ 96 bits |

C'est le schéma de SelfDataGuard (`data_master_key_pwd_wrap` / `_recov_wrap`) ; seul le destinataire de la seconde enveloppe change. Ajouter ou retirer une serrure ne touche ni au contenu, ni aux autres.

🔑 **Les deux secrets sont tirés, jamais choisis.** Puisque chaque serrure ouvre seule, la sécurité de l'ensemble est celle de la serrure la moins chère à ouvrir. Un coffre remis à un tiers s'attaque hors ligne, sans limite d'essais : seul le coût de chaque essai protège, et il ne rattrape pas un secret trop court. `fabriquer()` refuse tout secret dont le tirage n'est pas établi.

## La limite assumée

**Le détenteur du pli complet peut LIRE les données.** La double serrure supprime la protection contre l'ouverture prématurée. C'est un arbitrage : on ne se protège pas ici contre un dépositaire malhonnête, mais contre l'oubli, la perte et la disparition de l'éditeur. C'est écrit en page 1 du pli.

**Il ne peut pas modifier ce coffre-ci.** Le contenu n'est authentifié que par la clé maîtresse, et toute serrure rend la clé maîtresse : sans autre protection, qui détient une serrure réécrit ce que l'autre lira, sans trace. La fabrique scelle donc le coffre — ECDSA P-256, clé publique dans l'en-tête, **clé privée détruite** —, et le déchiffreur vérifie ce sceau avant d'essayer la moindre serrure. Amender, c'est fabriquer un nouveau coffre.

**Le sceau prouve l'intégrité, pas l'origine.** La clé publique naît dans le coffre et ne renvoie à rien d'extérieur : qui connaît un code d'ouverture peut fabriquer un coffre neuf, scellé et cohérent, qui s'ouvrira avec le code imprimé. L'ancrage est l'**empreinte du sceau** — `SHA-256` de la clé publique, imprimée à côté du code d'ouverture. L'écran affiche celle du fichier chargé et compare si on lui recopie celle du pli. Elle porte sur la clé et non sur le fichier, donc elle survit à toute réécriture du JSON ; et elle reste **facultative**, parce qu'un détenteur légitime qui a perdu le pli doit pouvoir ouvrir.

## Ce que porte le module

| chemin | quoi |
|---|---|
| `pli/selfvault.html` | le déchiffreur autonome, sans dépendance, hors ligne |
| `pli/gabarit-pli.html` | le gabarit du pli, à jetons |
| `outils/selfvault.py` | le format : fabrique, sérialisation canonique, plancher d'entropie |
| `outils/faire_coffre.py` · `outils/faire_pli.py` | la chaîne : coffre, QR codes, pli rendu |
| `outils/lire_pli.py` | le lecteur : pli scanné → fichiers reconstitués |
| `outils/test_webcrypto.mjs` | une réimplémentation indépendante, écrite depuis la notice |
| `tests/banc.sh` · `tests/banc_papier.sh` | le banc du format, et celui de la boucle papier |
| `tests/banc_navigateur.sh` | le banc du geste : la page ouverte d'un double-clic, dans un vrai navigateur |
| `tests/defauts.py` · `tests/pilote_app.mjs` | les coffres défectueux, et le pilote de l'application |
| `tests/pilote_navigateur.js` · `tests/juge_navigateur.py` | ce qui pilote l'atelier dans le navigateur, et ce qui juge ce qu'il en sort |
| `docs/conception-fr.md` | les raisons : ce qui a été retenu, écarté, mesuré |

`sortie/` porte ce que la chaîne produit et **n'est pas versionné** : un tirage réel y écrit un vrai code de récupération.

```
python3 outils/faire_coffre.py [version]   # coffre + secrets dans outils/secrets/
python3 outils/faire_pli.py                # QR codes + pli rendu
python3 outils/lire_pli.py pli-scanne.pdf  # reconstitue depuis le pli scanné
bash tests/banc.sh                         # le banc, sur les deux lecteurs
bash tests/banc_navigateur.sh              # le geste réel, dans un navigateur
```

## Le format `SELFVAULT3`

JSON, champs binaires en Base64. **La notice imprimée dans le pli fait autorité** : elle permet de réécrire un déchiffreur sans disposer de ce dépôt, et c'est pour cette raison que le format n'emploie que des primitives natives aux navigateurs.

L'en-tête canonique est passé en données authentifiées associées de chaque opération AES-GCM, la clé maîtresse est engagée par un HMAC, le nombre d'itérations, le nombre de serrures et le numéro de version sont bornés à la lecture comme à l'écriture, et chaque champ entrant dans l'AAD est contraint à une forme qui exclut ses deux caractères structurants.

L'AAD est arrêté avant les chiffrements, puisqu'il leur sert d'entrée : il ne peut donc pas les couvrir. Une signature ECDSA P-256 prend le relais sur ce que l'AAD ne peut pas atteindre — les nonces, les enveloppes et le contenu chiffré.

## Statut

Format arrêté le 4 septembre 2026, scellé le 6 septembre 2026 (`SELFVAULT3`). **Le pli n'a pas encore été présenté à un notaire**, et le module n'est déployé nulle part. `tests/banc.sh` éprouve chaque contrôle sur le défaut qu'il prétend attraper, avec son contre-témoin, sur les deux lecteurs ; il tourne en intégration continue et imprime son propre décompte.

**Le pli ne dépend d'aucun de ces programmes.** Il porte une page « Relire les QR codes » avec deux chemins. **Sur Windows, sans rien installer** : l'Outil Capture d'écran décode un QR code et rend son texte ; le coffre n'en compte que trois, et le déchiffreur les recolle lui-même — une case prévue pour ça. **Sur Linux ou macOS** : quatre commandes shell n'employant que `zbar-tools` et des outils Unix ordinaires, que le banc **extrait du pli rendu et exécute telles quelles** — une procédure imprimée sur un document opposable qu'on n'a jamais lancée est une affirmation, pas une mesure.

Le pli interdit formellement les lecteurs de QR codes en ligne : ces codes **sont** le coffre, les téléverser revient à en remettre une copie à un inconnu.

Chaque QR code n'est imprimé **qu'une fois**, et le pli ne vaut que complet. La double impression a été retirée le 09/09/2026 : les deux exemplaires portaient la **même image**, donc ils échouaient ensemble — elle protégeait d'une page déchirée, jamais d'un code illisible. Les pages rendues paient l'agrandissement des QR codes à **7,83 cm**, qui protège, lui, de ce qu'on rencontre : **12 pages** contre 13 pour l'ancien pli à 4,2 cm. Cette taille n'est pas choisie, elle est **calculée** — `outils/faire_pli.py` la dérive du nombre de modules pour que chacun tombe sur exactement 5 pixels à 300 points par pouce. À 7 cm, soit 4,469 pixels par module, la rasterisation perdait un code sur quatre tirages sur quatre. Une redondance qui protégerait vraiment demanderait des fragments de parité — `n` fragments dont `k` suffisent — et une rupture de format ; ce n'est pas fait. Le lecteur refuse quand deux lectures d'un même rang divergent — deux balayages de la même source, ou deux tirages mêlés dans le même répertoire — plutôt que de laisser la dernière lue gagner en silence.

La boucle papier est mesurée de bout en bout : le pli rendu, rastérisé, relu, reconstitué **octet pour octet**, et le coffre rouvert avec le code imprimé en tête de son dossier technique. `outils/lire_pli.py` nomme tous les QR codes manquants, n'écrit aucun fichier partiel, et refuse de conclure quand il n'a pas d'empreinte de référence à comparer. **Il n'existe pas de plancher de résolution**, contrairement à ce que ce README a longtemps affirmé : mesuré le 09/09/2026, un même pli échouait à 300 et 200 points par pouce et réussissait à 150 et 120. Un QR code peut se dérober à une rasterisation et se rendre à la suivante, sans que rien ne soit abîmé. Le lecteur insiste donc au lieu d'accuser la numérisation : balayage plus fin d'abord, puis d'autres résolutions quand la source est un PDF. Le banc borne ce qui se borne — 300 points par pouce doivent passer, 80 doivent refuser — et mesure en plus une planche **floutée et penchée**, parce qu'un pli est lu par un scanner et qu'aucune rasterisation ne ressemble à un scanner. Deux niveaux de flou, pas un : 0,8 px rougit quand le pli est déjà illisible, 1,2 px rougit quand il a seulement perdu sa marge — le seuil de rupture est à 2,0 px, identique sur six tirages. Ce que le banc n'établit pas est imprimé avec le reste : ni grain de capteur, ni binarisation de copieur, ni tache, ni photographie au téléphone.

Reste ouvert : les conditions de remise, à écrire dans l'acte de dépôt — la page 1 renvoie aujourd'hui à un accord dont le notaire successeur n'aura pas connaissance.
