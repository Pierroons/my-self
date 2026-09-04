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
| **L2** | phrase de passe tirée au sort | le titulaire | ≥ 77 bits |

C'est le schéma de SelfDataGuard (`data_master_key_pwd_wrap` / `_recov_wrap`) ; seul le destinataire de la seconde enveloppe change. Ajouter ou retirer une serrure ne touche ni au contenu, ni aux autres.

🔑 **Les deux secrets sont tirés, jamais choisis.** Puisque chaque serrure ouvre seule, la sécurité de l'ensemble est celle de la serrure la moins chère à ouvrir. Un coffre remis à un tiers s'attaque hors ligne, sans limite d'essais : seul le coût de chaque essai protège, et il ne rattrape pas un secret trop court. `fabriquer()` refuse tout secret dont le tirage n'est pas établi.

## La limite assumée

**Le détenteur du pli complet peut ouvrir les données.** La double serrure supprime la protection contre l'ouverture prématurée. C'est un arbitrage : on ne se protège pas ici contre un dépositaire malhonnête, mais contre l'oubli, la perte et la disparition de l'éditeur. C'est écrit en page 1 du pli.

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
| `tests/defauts.py` · `tests/pilote_app.mjs` | les coffres défectueux, et le pilote de l'application |
| `docs/conception-fr.md` | les raisons : ce qui a été retenu, écarté, mesuré |

`sortie/` porte ce que la chaîne produit et **n'est pas versionné** : un tirage réel y écrit un vrai code de récupération.

```
python3 outils/faire_coffre.py [version]   # coffre + secrets dans outils/secrets/
python3 outils/faire_pli.py                # QR codes + pli rendu
python3 outils/lire_pli.py pli-scanne.pdf  # reconstitue depuis le pli scanné
bash tests/banc.sh                         # le banc, sur les deux lecteurs
```

## Le format `SELFVAULT2`

JSON, champs binaires en Base64. **La notice imprimée dans le pli fait autorité** : elle permet de réécrire un déchiffreur sans disposer de ce dépôt, et c'est pour cette raison que le format n'emploie que des primitives natives aux navigateurs.

L'en-tête canonique est passé en données authentifiées associées de chaque opération AES-GCM, la clé maîtresse est engagée par un HMAC, le nombre d'itérations est borné à la lecture comme à l'écriture, et chaque champ entrant dans l'AAD est contraint à une forme qui exclut ses deux caractères structurants.

## Statut

Format arrêté le 4 septembre 2026. **Le pli n'a pas encore été présenté à un notaire**, et le module n'est déployé nulle part. `tests/banc.sh` éprouve chaque contrôle sur le défaut qu'il prétend attraper, avec son contre-témoin, sur les deux lecteurs ; il tourne en intégration continue et imprime son propre décompte.

La boucle papier est mesurée de bout en bout : le pli rendu, rastérisé, relu, reconstitué **octet pour octet**, et le coffre rouvert avec le code imprimé sur sa page 2. `outils/lire_pli.py` nomme tous les QR codes manquants, n'écrit aucun fichier partiel, et refuse de conclure quand il n'a pas d'empreinte de référence à comparer. **Plancher de résolution re-mesuré le 04/09/2026 : 200 points par pouce passent, 150 échouent** — d'où le minimum de 300 inscrit sur le pli.

Reste ouvert : les conditions de remise, à écrire dans l'acte de dépôt — la page 1 renvoie aujourd'hui à un accord dont le notaire successeur n'aura pas connaissance.
