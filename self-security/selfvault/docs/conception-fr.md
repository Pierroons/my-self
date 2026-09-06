# SelfVault — note de conception

Coffre de directives post-mortem à **deux serrures indépendantes**, imprimable en QR codes
et déposable chez un notaire.

> État au 4 septembre 2026, jour où l'ébauche entre dans le dépôt. Ce document porte **les
> raisons** : ce qui a été retenu, ce qui a été écarté, ce qui a été mesuré. Il ne décrit pas
> le module tel qu'il est — le `README.md` s'en charge, et la notice du format vit dans le pli.

---

## Le problème que ça résout

Deux exigences qui se contredisent :

- **du vivant du titulaire**, personne d'autre que lui ne doit pouvoir ouvrir ;
- **après sa mort**, un proche désigné doit pouvoir ouvrir.

Rien dans MySelf ne couvre ce passage. SelfRecover restaure un **accès**, jamais des
**données** — c'est sa règle de conception la plus verrouillée, et elle interdit de le
transposer. SelfDataGuard chiffre, mais ne dit rien de qui ouvre quand le titulaire n'est
plus là. `self-security/selfrecover-luks/quorum-rnd/` fait du partage à seuil, mais pour un
déverrouillage **automatique au démarrage par des témoins réseau** : des serveurs, pas des
personnes, et l'événement déclencheur est un redémarrage, pas un décès.

Et la règle « passphrase perdue = backup perdu », saine du vivant, décrit exactement le
scénario qu'on veut couvrir une fois la personne morte.

## La conception retenue, et pourquoi

**Double serrure sur un même coffre.** Une clé maîtresse tirée au sort
chiffre le contenu ; elle est ensuite enfermée **deux fois**, dans deux enveloppes
indépendantes :

| serrure | secret | détenteur |
|---|---|---|
| **L1** | code de récupération imprimé | le notaire dépositaire |
| **L2** | mot mémorisé | le titulaire |

Pas de L3 : sans serveur, il n'y a pas d'administrateur pour arbitrer.

C'est **exactement le schéma de SelfDataGuard** — `data_master_key_pwd_wrap` /
`data_master_key_recov_wrap`, cf. son whitepaper § 2.2. Seul le destinataire de la seconde
enveloppe change : un tiers, au lieu du même utilisateur. Rien à inventer côté crypto.
LUKS fait la même chose côté disque, et `setup-add-selfrecover-slot.sh` ajoute déjà un slot
sans retirer l'autre.

### Ce qui a été écarté, et pourquoi

- **La minuterie** (Repos Digital, Kayz, Wishbook) : exige qu'un serveur tourne encore dans
  dix ans, et une hospitalisation longue ouvre le coffre alors que la personne est vivante.
  Le domaine compte des centaines d'outils, dont une part notable cesse d'être exploitée
  chaque année. ⚠️ Chiffre à re-sourcer et à dater avant toute publication : la version
  précédente de ce paragraphe citait la CNIL sans lien ni date de consultation, à côté d'un
  décret sourcé à la ligne près.
- **k parts sur n entre proches** : aucune dépendance à un éditeur, mais les proches perdent
  leurs parts, meurent avant, ou se fâchent — et rien n'empêche k d'entre eux de se réunir
  du vivant du titulaire. Surtout : **un dispositif que la veuve ne comprend pas ne
  fonctionne pas.** Un pli scellé chez un notaire, tout le monde saisit sans explication.

### La limite assumée

**Le détenteur du pli complet peut LIRE les données.** La double serrure supprime la
protection contre l'ouverture prématurée. C'est un arbitrage volontaire : on ne se protège
pas ici contre un notaire malhonnête, mais contre l'oubli, la perte et la mort de l'éditeur.
Le notaire est l'institution construite pour ce risque — il détient déjà des testaments
qu'il n'ouvre pas, avec un ordre professionnel et un successeur désigné.

C'est écrit en page 1 du pli.

**Il ne peut pas les RÉÉCRIRE — depuis le 06/09/2026.** Ce n'était pas vrai avant, et ce
document ne l'admettait pas : il ne parlait que de lecture. Le contenu n'était authentifié
que par la clé maîtresse, et toute serrure rend la clé maîtresse. Le dépositaire ouvrait la
sienne, rechiffrait ce qu'il voulait sous cette même clé avec le même AAD — l'en-tête ne
bougeait pas d'un octet, l'engagement restait valide, l'enveloppe de la titulaire était
intacte, et l'application lui affichait « en-tête authentifié » au-dessus d'un texte qu'elle
n'avait jamais écrit. La relation était symétrique : elle pouvait réécrire ce que le
dépositaire lirait.

La fabrique tire donc une paire ECDSA P-256, publie la clé publique dans l'en-tête — donc
dans l'AAD —, signe l'ensemble, et **détruit la clé privée**. Dans le navigateur, celle-ci
est créée non exportable : le moteur refuse de la rendre, elle ne peut être ni écrite ni
copiée. Plus personne au monde ne peut sceller une autre version de ce coffre. Amender,
c'est en fabriquer un nouveau, de version supérieure.

**Le sceau prouve l'intégrité, pas l'origine.** La clé publique naît dans le coffre et ne
renvoie à rien d'extérieur : quelqu'un qui a photographié le pli connaît le code L1 et peut
fabriquer un coffre entièrement neuf, cohérent et scellé, qui s'ouvrira avec le code imprimé.
Rien dans le fichier ne l'en distingue.

**Ce qui l'en distingue est l'empreinte du sceau, imprimée sur le pli** — `SHA-256` de la clé
publique, 32 caractères groupés par quatre, à côté du code d'ouverture, là où la personne
regarde. L'écran d'ouverture affiche celle du fichier qu'on lui donne, et accepte qu'on lui
recopie celle du pli : il compare alors, et refuse bruyamment avant d'essayer la moindre
serrure.

**Elle empreinte la clé publique, pas le fichier.** L'empreinte d'un fichier change dès qu'un
outil réécrit le JSON avec un autre espacement — en faire une porte fabriquerait des refus
injustifiés chez quelqu'un dont le coffre est intact. Celle de la clé survit à toute
réécriture et ne change que si le coffre a été refabriqué.

**Et elle reste facultative.** Un détenteur légitime qui a le coffre mais plus le pli —
incendie, étude fermée — doit pouvoir ouvrir : c'est le risque contre lequel tout le module
existe. Sans empreinte, la page ouvre et dit ce qu'elle ne sait pas : « rien ne rattache ce
fichier au dépôt ». Un garde-fou qui bloque l'ouverture légitime pour empêcher une
substitution serait un mauvais échange sur cet objet-là.

Reste hors format : l'identification du déposant par l'étude, seule défense contre la
substitution du pli lui-même.

**Coût mesuré le 06/09/2026** : le déchiffreur passe de 17 453 à 21 057 octets, le pli de 18 à 21 QR codes
et de 11 à 13 pages.

## Trois objets, et un ordre de priorité

| | rôle | remplaçable ? |
|---|---|---|
| l'app | le déchiffreur | **oui** — publiable sur GitHub, et réécrivable depuis la notice |
| le coffre | les données chiffrées | **non** |
| le code L1 | ce qui ouvre | **non** |

Si l'ordinateur est volé, ce qui disparaît c'est **le coffre**. Imprimer l'app sans le coffre
ne sauve rien. D'où : **coffre d'abord, code ensuite, app en dernier.**

Et surtout : **imprimer la notice du format vaut mieux qu'imprimer le programme.** Une page
de texte lisible permet de réécrire un déchiffreur en vingt lignes, et elle survit à une
photocopie ratée là où un QR abîmé est mort. 

## Le transfert entre études — le protocole existe déjà

Décret n° 71-942 du 26 novembre 1971, **vérifié à la source le 04/09/2026** (Légifrance, texte
à jour au 24/11/2024) :

- **art. 13** — minutes et registres remis au nouveau titulaire dans les **quinze jours**
- **art. 14** — office supprimé : attribution à un ou plusieurs autres offices
- **art. 15** — **état sommaire** remis au successeur, copie déposée à la chambre des notaires
- **art. 16** — décès du notaire : scellés requis par le seul procureur général

⚠️ Ces articles **ne nomment pas** les « dépôts de confiance ». Plusieurs sources
professionnelles l'affirment, la source primaire ne le dit pas dans ces termes. **Question
ouverte, posée au notaire** (question 9 de la note).

Le coffre chiffré peut voyager par n'importe quel canal ; seul
le code L1, qui tient sur une ligne, exige un canal séparé. Et comme tout est réimprimable à
l'identique, **le papier n'a même pas besoin de voyager** : on scanne, on transmet, on
réimprime.

---

## Ce que porte le module

`pli/` porte les **sources** — le déchiffreur autonome et le gabarit du pli. `outils/`
porte la fabrique et le banc, `tests/` les défauts provoqués, et `sortie/` tout ce que la
chaîne produit. `sortie/` n'est pas versionné : un vrai tirage y écrit un vrai code de
récupération.

## Le format

Le format de l'ébauche était `SELFVAULT1`. Il est passé en `SELFVAULT2` le 04/09/2026, pour
authentifier l'en-tête et engager la clé maîtresse, puis en **`SELFVAULT3`** le 06/09/2026,
pour sceller le coffre. Sa notice fait autorité et vit dans le pli lui-même — c'est elle qui permet de réécrire un déchiffreur sans disposer du
code, et c'est à cette fin qu'elle est imprimée plutôt que rangée ici.

## Ce qui a été mesuré, le 04/09/2026

Chaîne complète : chiffrer → imprimer → rasteriser à 300 dpi → relire → reconstituer → ouvrir.

| épreuve | résultat |
|---|---|
| les QR lus sur le PDF rastérisé | **tous**, dans le désordre |
| reconstitution des deux fichiers | **octet pour octet**, SHA-256 identiques, `cmp` muet |
| ouverture du coffre reconstitué avec le code **relu dans le PDF** | serrure L1 ouverte |
| L1 avec tirets / sans tirets / espaces / retour à la ligne | ouvre dans les 4 cas |
| L2, mot mémorisé | ouvre |

**Rouges provoqués** (un contrôle jamais vu échouer ne prouve rien) :

| défaut planté | rendu |
|---|---|
| deux QR effacés de la page scannée | le manque est nommé — aucun fichier faux rendu |
| un caractère faux dans L1 | refus |
| deux caractères voisins permutés | refus |
| un octet du coffre retourné | refus |
| photocopie délavée + poussière | **7/7 quand même** — la correction Q encaisse |
| scan à 150 dpi | 4/7 — **plancher mesuré : 200 dpi passe, 150 échoue**. Re-mesuré le 06/09/2026 sur le pli scellé, à 13 pages : inchangé, et désormais borné des deux côtés par le banc papier |

### Deux défauts trouvés par la boucle

1. **Normalisation divergente.** Python retirait les tirets du code avant dérivation, le
   JavaScript les gardait : les deux serrures refusaient de s'ouvrir alors que tout était
   juste. Corrigé par **une seule forme canonique — lettres et chiffres seuls — appliquée des
   trois côtés** (générateur, app, banc). Effet secondaire heureux : le code marche avec les
   tirets, sans, ou avec des espaces à la place.
2. **Un contre-témoin faux.** « Deux caractères permutés » ouvrait le coffre — parce que les
   deux premiers caractères du code tiré étaient identiques, donc la permutation ne permutait
   rien. Refait sur deux caractères réellement différents : refus. *La sonde était fautive,
   pas le code.*

## Décisions à valider, et travail restant

**Tranché le 06/09/2026 — le format devient `SELFVAULT3`.** Le sceau change les règles de
lecture : un lecteur qui ignore la signature accepterait un coffre réécrit. Garder le nom
`SELFVAULT2` aurait mis deux jeux de règles derrière un seul identifiant — sur un objet dont
la notice imprimée EST la spécification, et qui doit se relire dans vingt ans, c'est
exactement le piège que ce module existe pour éviter.

**Tranché le 04/09/2026 — PBKDF2 plutôt qu'Argon2id.** Argon2id est meilleur, mais n'existe pas
nativement dans les navigateurs : il faudrait embarquer une bibliothèque WASM, soit une centaine
de kilo-octets et une dépendance à faire survivre vingt ans. PBKDF2 est natif partout
(600 000 itérations, OWASP Password Storage Cheat Sheet, relevé le 04/09/2026). Compromis assumé
en faveur de la survie du format : un déchiffreur qui exige du WASM cesse d'être réécrivable
depuis la notice imprimée, ce que le pli promet.

⚠️ **Cet arbitrage repose sur le plancher d'entropie de la serrure mémorisée** — sans lui, la
règle d'`AGENTS.md` sur les KDF mémoire-dures s'applique et impose Argon2id. Le générateur tire
la phrase, `bits_de()` mesure le tirage, et `fabriquer()` refuse tout secret dont le tirage n'est
pas établi. Les deux décisions ne se séparent pas.

Reste à faire :

- [x] **Le lecteur de pli** — `outils/lire_pli.py`, écrit le 04/09/2026. Il avale un PDF, une
      image ou un répertoire d'images, nomme **tous** les QR codes manquants, n'écrit aucun
      fichier partiel, et refuse de conclure quand il n'a pas d'empreinte de référence à
      comparer. Dépendances système seulement : poppler-utils et zbar-tools.
- [x] **Somme de contrôle du code L1 réellement vérifiée** — faite le 04/09/2026. Elle ne se
      déclenche que si la saisie ressemble à un code, et reste un indice : elle change le
      message, jamais la décision d'essayer.
- [x] **Message de manque plus complet** — la liste entière est nommée, et `tests/banc_papier.sh`
      l'éprouve sur deux QR codes effacés.
- [x] **Où vit le module** — `self-security/selfvault/`, à côté de SelfDataGuard dont il
      reprend le schéma. Tranché le 04/09/2026.
- [~] **Un banc au dépôt** — `tests/banc.sh` éprouve chaque contrôle sur le défaut qu'il
      prétend attraper, et pilote **les deux lecteurs** : la réimplémentation Node et
      l'application réelle. Il imprime son propre décompte plutôt que de le faire recopier
      ailleurs. Reste à y joindre la boucle papier : rasteriser, relire les QR, mesurer le
      plancher de résolution. **Dépendances dérivées des imports, pas recopiées** : binaires
      `zbar-tools`, `poppler-utils`, `weasyprint`, `node` ; modules Python `cryptography`,
      `qrcode` et `pillow` — ce dernier parce que `qrcode` en a besoin pour écrire un PNG.
      Le banc vérifie les sept avant de mesurer quoi que ce soit, et nomme celui qui manque.
- [ ] **Versionner le pli** : la page 1 porte un numéro de version, désormais authentifié
      par l'AAD et repris du coffre. Rien ne vérifie encore qu'un coffre régénéré incrémente
      ce numéro, ni que le dépositaire soit averti que son ancien code est mort.
- [ ] **Articuler avec le document type de directives** — deux objets à ne pas mélanger : les
      directives disent *quoi faire* et partent en clair, le coffre porte *les moyens* et ne
      part jamais en clair. Le document type peut désigner le coffre et dire où il est, sans
      jamais porter la clé.

## Ce qui n'est pas décidé et ne doit pas l'être par défaut

- Le coffre contient-il des mots de passe ? Si oui, on marche sur Proton Pass — **vérifier
  d'abord s'il a un accès d'urgence**, non mesuré à ce jour.
- Combien de serrures, et pour qui ? Le format en accepte n, la démo en pose 2.
- Le nom `SelfVault` est un provisoire, pas un arbitrage.
