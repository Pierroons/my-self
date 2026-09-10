# SelfVault — note de conception

Coffre de directives post-mortem à **deux serrures indépendantes**, imprimable en QR codes
et déposable chez un notaire.

> État au 6 septembre 2026. Ce document porte **les raisons** : ce qui a été retenu, ce qui a
> été écarté, ce qui a été mesuré. Il ne décrit pas le module tel qu'il est — le `README.md`
> s'en charge, et la notice du format vit dans le pli.

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
| **L2** | phrase de passe **tirée au sort**, huit mots | le titulaire |

Pas de L3 : sans serveur, il n'y a pas d'administrateur pour arbitrer.

🔑 **Les deux secrets sont tirés, jamais choisis.** Ce n'est pas un confort : puisque chaque
serrure ouvre seule, la sécurité de l'ensemble est celle de la moins chère à ouvrir, et un
coffre remis à un tiers s'attaque hors ligne sans limite d'essais. L'entropie est une
propriété du TIRAGE et non de la chaîne — aucune inspection d'un texte ne dit s'il a été tiré
au sort. Elle se prouve donc à la source : seule une phrase rendue par le générateur porte sa
mesure, et la fabrique refuse tout secret dont le tirage n'est pas établi.

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
  ⚠️ Le rejet ne tient PAS sur la taille du code : la recombinaison de Shamir sur GF(256),
  écrite dans le style commenté du dépôt, a été mesurée le 06/09/2026 à **736 octets** —
  moins d'un QR code. L'argument est humain, et il suffit.

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

## Quatre objets, et un ordre de priorité

| | rôle | imprimé ? | remplaçable ? |
|---|---|---|---|
| l'atelier | fabriquer le coffre, une fois | **jamais** | oui |
| le déchiffreur | ouvrir le coffre, vingt ans plus tard | oui | **oui** — publiable, et réécrivable depuis la notice |
| le coffre | les données chiffrées | oui | **non** |
| le code L1 | ce qui ouvre | oui | **non** |

### On imprime ce qui OUVRE, on n'imprime pas ce qui CRÉE

C'est la décision qui sépare l'atelier du déchiffreur, tranchée le 05/09/2026 après mesure.
Personne n'a besoin de *fabriquer* un coffre à partir du pli : quand le pli s'ouvre, la
titulaire est morte. Tout mettre dans un seul fichier — la liste de 7 776 mots pèse 62,9 ko à
elle seule — aurait porté le pli à environ 84 QR codes, contre 24 aujourd'hui : quatorze
planches au lieu de quatre, soit un pli de 22 pages au lieu de 12.

L'atelier ouvre **aussi** : la titulaire n'a donc qu'un fichier à garder, et elle vérifie son
propre coffre sans recharger quoi que ce soit. Il est assemblé, pas écrit à la main —
`outils/faire_atelier.py` extrait le noyau de déchiffrement de `pli/selfvault.html` entre deux
marqueurs et l'y injecte. **Deux exemplaires du code qui déchiffre finiraient par ne plus dire
la même chose**, et le coffre ne s'ouvrirait plus qu'avec le programme qui l'a écrit ; le banc
vérifie donc que l'atelier assemblé en porte une copie au caractère près.

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

`pli/` porte les **sources** : `selfvault.html` le déchiffreur autonome, `atelier.html` la
page de fabrication à jetons, `gabarit-pli.html` le gabarit du pli, `modele-directives.txt` le
squelette éditable que l'atelier propose. `outils/` porte les fabriques — `selfvault.py` le
format lui-même, `faire_coffre.py`, `faire_pli.py`, `lire_pli.py`, `faire_atelier.py` — et
`test_webcrypto.mjs`, second lecteur du format. `tests/` porte le banc, les défauts provoqués,
les deux pilotes et `recouvrement.py`. `sortie/` porte tout ce que la chaîne produit et n'est
pas versionné : un vrai tirage y écrit un vrai code de récupération.

Trois fichiers sont **assemblés** et ne s'éditent pas : `sortie/selfvault-atelier.html`,
`sortie/pli.html` et les images QR. Une valeur écrite en dur dans un gabarit échappe par
construction au garde-fou de substitution — sept constantes du format y étaient figées le
06/09/2026, et un pli imprimé ne se corrige pas.

## Le format

Le format de l'ébauche était `SELFVAULT1`. Il est passé en `SELFVAULT2` le 04/09/2026, pour
authentifier l'en-tête et engager la clé maîtresse, puis en **`SELFVAULT3`** le 06/09/2026,
pour sceller le coffre. Sa notice fait autorité et vit dans le pli lui-même — c'est elle qui permet de réécrire un déchiffreur sans disposer du
code, et c'est à cette fin qu'elle est imprimée plutôt que rangée ici.

## Ce qui a été mesuré, le 04/09/2026

Chaîne complète : chiffrer → imprimer → rasteriser à 300 dpi → relire → reconstituer →
ouvrir. Le pli comptait alors 7 QR codes ; il en compte 24 aujourd'hui.

| épreuve | résultat |
|---|---|
| les QR lus sur le PDF rastérisé | **tous**, dans le désordre |
| reconstitution des deux fichiers | **octet pour octet**, SHA-256 identiques, `cmp` muet |
| ouverture du coffre reconstitué avec le code **relu dans le PDF** | serrure L1 ouverte |
| L1 avec tirets / sans tirets / espaces / retour à la ligne | ouvre dans les 4 cas |
| L2, phrase tirée | ouvre |

**Rouges provoqués** (un contrôle jamais vu échouer ne prouve rien) :

| défaut planté | rendu |
|---|---|
| deux QR effacés de la page scannée | le manque est nommé — aucun fichier faux rendu |
| un caractère faux dans L1 | refus |
| deux caractères voisins permutés | refus |
| un octet du coffre retourné | refus |
| photocopie délavée + poussière | **les 7 QR d'alors, tous lus** — la correction Q encaisse |
| scan à 150 dpi | 4/7 — lu à l'époque comme un **plancher de résolution**. ⚠️ Ce n'en était pas un : voir la mesure du 09/09/2026 |

## Ce qui a été mesuré, le 06/09/2026

L'audit crypte du module, mené par quatre agents indépendants, puis les corrections qu'il a
imposées. Chaque chiffre ci-dessous a été mesuré, aucun n'est déduit.

| ce qui a été mesuré | résultat |
|---|---|
| tenue de la serrure L2 face au **réseau Bitcoin entier** (8,62·10²⁰ H/s relevés ce jour) | 8 mots = 103,4 bits → 96 000 ans après vingt ans de progrès matériel |
| tenue de l'ancien plancher de 77 bits | **1,1 an** aujourd'hui, 9,5 heures après vingt ans → porté à **96 bits** |
| ce qu'achèterait Argon2id à ce niveau d'entropie | 12 662 ans deviennent 1,3 million d'années — **rien qui protège quelqu'un** |
| Argon2id embarqué : WASM le plus petit / JS pur | **29,5 ko** / **14 ko** — et non « une centaine de kilo-octets » |
| recombinaison Shamir GF(256) écrite dans le style du dépôt | 736 octets, moins d'un QR code |
| capacité d'un QR v40 correction Q | base64 en mode octet 1 247 o · base32 en mode alphanumérique 1 512 o (+21 %) |
| indépendance du second lecteur | **53 % des lignes** venaient du noyau — réécrit, il en partage 2 |
| pli courant à cette date | 24 QR codes (21 déchiffreur + 3 coffre), 13 pages, déchiffreur de 24 825 octets |
| plancher de résolution, re-mesuré sur ce pli-là | 200 dpi passe, 175 échoue — ⚠️ conclusion invalidée le 09/09/2026 |

**Rouges provoqués, éprouvés jusqu'au bout** — chacun a d'abord été vu réussir sans la garde :

| défaut planté | rendu |
|---|---|
| un porteur de serrure réécrit le contenu que l'autre lira | le sceau refuse, **avant toute dérivation** |
| la salamandre invisible d'Albertini — une enveloppe qui s'authentifie sous deux clés | la serrure fautive est **nommée** |
| `getRandomValues` qui ne remplit rien | la page se déclare inutilisable et nomme la cause |
| liste de mots dont les entrées ne diffèrent que par la ponctuation | refusée — 90,5 bits annoncés pour 79,4 réels |
| liste de plus de 65 536 mots | refusée — au-delà, la borne de rejet tombe à zéro et l'onglet se fige |
| coffre entièrement refabriqué par un tiers qui a lu le code | il s'ouvre ; **seule l'empreinte du sceau le démasque** |

## Ce qui a été mesuré, le 09/09/2026

Parti d'une intégration continue qui rougissait un tour sur deux sans qu'aucun fichier du
module n'ait bougé — relancée sur le même commit, elle passait au vert.

**Il n'y a pas de plancher de résolution.** Sur un même pli, la lecture échoue à 300 et à
200 points par pouce et réussit à 150 et 120. Le QR fautif, extrait seul de `sortie/qr/`,
se relit à toutes les tailles : ce n'est ni la charge, ni la densité, c'est la
rasterisation qui perd un code à un facteur d'échelle précis. Deux versions de poppler
(24.02 en intégration, 25.03 au poste) ne tombent pas sur la même phase, donc pas sur les
mêmes codes — d'où le tirage au sort.

| épreuve | résultat |
|---|---|
| page perdant `A9/21` à 300 dpi | relue à 150, 200, 250, 350, 400, 500 et 600 : **complète partout ailleurs** |
| le même code, extrait seul, réduit de 331 à 1 850 px | lu à toutes les tailles |
| `zbarimg -Sx-density=2` sur la page fautive | **le code se rend** — un second balayage suffit |

**Et le banc n'avait jamais rencontré de papier.** Il rasterise un PDF parfait, où chaque
module tombe sur un nombre régulier de pixels. Une planche dégradée comme le ferait un
copieur donne autre chose :

| planche à 300 dpi | QR relus |
|---|---|
| nette | 15 / 15 |
| flou 0,5 px | 15 / 15 |
| **flou 1,0 px** | **0 / 15** |
| flou 0,8 + bruit 1 % + travers 0,5° | 14 / 15 — et un seul manquant perd le coffre |

À 200 points par pouce, un demi-pixel de flou suffisait à tout effacer. Les deux
exemplaires portant la **même image**, ils échouent ensemble : la duplication protège
d'une page déchirée, jamais d'un code illisible.

**Trois corrections, mesurées chacune :**

| ce qui change | ce que ça achète |
|---|---|
| QR imprimés à **7 cm** au lieu de 4,2 | 4,47 px par module au lieu de 2,68 ; le flou supporté passe de « entre 1,0 et 1,5 px » à **1,5 px**, mesuré identique sur six tirages |
| le lecteur **insiste** — balayage fin, puis d'autres résolutions sur un PDF | les répertoires à 300 et 200 dpi qui refusaient se reconstituent |
| la **double impression est retirée** | l'agrandissement est payé : **12 pages**, contre 13 pour l'ancien pli à 4,2 cm |

Le contrôle neuf a été vu rougir sur le pli à 4,2 cm avec le lecteur insistant : trois
codes perdus, dont `V1/3`. L'élargissement porte donc quelque chose que la relecture seule
n'apporte pas.

### Pourquoi la double impression tombe

Elle coûtait quatre planches et couvrait le mauvais accident : la page déchirée, jamais
observée sur ce pli, et non le code illisible, qui est ce qu'on rencontre.

Ce qui la remplacerait vraiment n'est pas une copie mais des **fragments de parité** :
découper en `n` fragments dont `k` quelconques suffisent (Reed-Solomon sur les
fragments). C'est une rupture de format, `PLI1` → `PLI2`, et une question ouverte sur le
chemin « coller les lignes dans la page », qui devrait la supporter aussi. **Décision de
produit non prise.**

### Ce que le banc mesure maintenant, et ce qu'il ne mesure pas

Le flou supporté a été re-mesuré sur la géométrie courante, six tirages, travers de
0,5° partout. Les six rendent le **même** seuil — ce que la dégradation précédente ne
faisait pas :

| flou, à 300 points par pouce | 6 tirages |
|---|---|
| 0,8 px — un scan plausible | reconstitué octet pour octet, 6/6 |
| 1,0 px | 6/6 |
| 1,2 px | 6/6 |
| 1,5 px | 6/6 |
| **2,0 px** | **0/6** |

D'où deux contrôles au lieu d'un. Celui à 0,8 px rougit quand le pli est **déjà**
illisible par un copieur ordinaire — trop tard pour le remanier. Celui à **1,2 px**
rougit avant : il ne dit pas que le pli est cassé, il dit qu'il a perdu sa marge. Les
deux ont été vus rouges en rétablissant 4,2 cm dans le gabarit.

### Le contrôle écrit pour supprimer une loterie en était une autre

La dégradation du banc mêlait trois choses, dont une inventée : 1 % des pixels mis à une
valeur tirée au hasard. Un scanner ne fait pas cela ; un fax abîmé, oui. Ce bruit-là
faisait rougir le banc **un tirage sur neuf environ**, et j'ai d'abord conclu du contraire
sur **un seul tirage** — exactement le défaut que je venais de corriger dans le banc.
Retiré : la dégradation se borne au flou et au travers, et le banc **imprime ce qu'il
n'établit pas** — ni grain de capteur, ni binarisation de copieur, ni tache, ni
photographie au téléphone.

### Une troisième loterie, plus ancienne que les deux autres

Le contrôle « pages mélangées » copiait les images sous des noms tirés de `$RANDOM`.
Deux tirages sur vingt-quatre coïncident **7 fois sur 1 000** — mesuré sur 2 000
essais — et `cp` écrase alors sans rien dire : le répertoire perd un QR code, et les
trois contrôles qui le lisent rougissent en accusant le pli. Vu une fois sur dix
passages.

Les noms sont maintenant numérotés à la suite. La propriété éprouvée est que le rang
ne vient pas du nom du fichier ; elle n'a jamais eu besoin que les noms soient
imprévisibles. Une garde compte les images copiées et, si le compte ne tombe pas
juste, le banc dit **« banc fautif, pas pli fautif »** au lieu de laisser un défaut
d'outillage se déguiser en pli mal numérisé.

**Ce que le pli imprimait était le mauvais remède.** « Rescannez à 300 points par pouce au
moins » demande *plus fin* ; ce qu'il faut, c'est *autrement*.

### Deux défauts de méthode, dans le banc lui-même

1. **Deux contrôles ne s'exécutaient pas du tout.** Ils appelaient une fonction définie plus
   bas dans le fichier ; bash ne dit rien, et le banc restait vert. Trouvé par `shellcheck`.
2. **Un contrôle vert grâce à un état qu'il ne créait pas.** Le contrôle « atelier → pli »
   s'appuyait sur un `secrets/code_L1.txt` laissé par une exécution antérieure. Vert sur ce
   poste, rouge au premier passage en intégration continue. Le banc se relance désormais
   **deux fois depuis un arbre vierge** : un contrôle qui dépend de ce que le précédent a
   laissé ne se voit qu'à la seconde passe.

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

**Tranché le 04/09/2026, confirmé le 06/09 après mesure — PBKDF2 plutôt qu'Argon2id.**
`crypto.subtle` n'expose que PBKDF2, HKDF et ECDH : Argon2id ne figure que dans une proposition
WICG qu'aucun moteur n'implémente. Il faudrait donc l'embarquer.

Le motif d'origine était faux, et il est corrigé ici : « une centaine de kilo-octets » n'a
jamais été mesuré. Les vraies tailles sont **29,5 ko** pour le plus petit WASM et **14 ko** en
JS pur — le JS pur est deux fois plus petit que le WASM. La conclusion tient malgré tout, pour
deux autres raisons :

1. **À 103 bits tirés au sort, une KDF mémoire-dure n'achète rien.** PBKDF2 à 600 000
   itérations demande déjà 12 662 ans au réseau Bitcoin entier ; Argon2id en ferait 1,3 million.
   On ne protège personne de plus. Son intérêt est de rattraper les secrets *choisis par un
   humain* — 40 bits, cassés en 0,25 ms — et ce module n'en a aucun, par construction.
2. **Un déchiffreur qui exige du WASM cesse d'être réécrivable depuis la notice imprimée**, ce
   que le pli promet et qui est la propriété la mieux tenue du module.

⚠️ **Cet arbitrage repose entièrement sur le plancher d'entropie** — sans lui, la règle
d'`AGENTS.md` sur les KDF mémoire-dures s'applique et impose Argon2id. Le plancher est passé de
77 à **96 bits** le 06/09/2026 : 77 bits ne tenaient qu'un an face au matériel d'aujourd'hui, et
le pli promet vingt ans. Les deux décisions ne se séparent pas.

**Tranché le 06/09/2026 — AES-GCM reste, et le nonce aléatoire aussi.** Le seul vrai défaut de
GCM est la collision de nonce, plafonnée par le NIST à 2³² chiffrements par clé en mode IV
aléatoire. Ici la borne est hors sujet : **chaque clé ne chiffre qu'un seul message** — chaque
serrure a sa clé et une enveloppe, la clé maîtresse a un contenu. Le cas catastrophique est
fermé par construction, pas par discipline.

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
- [x] **L'atelier de fabrication** — `pli/atelier.html`, assemblé le 05/09/2026, complété le
      06/09. Le module savait ouvrir un coffre sans rien installer ; il ne savait pas en
      fabriquer un sans Python, quatre bibliothèques, et des dernières volontés écrites en dur
      dans un fichier `.py`. Quatre étapes, un modèle de directives pré-rempli, les deux
      secrets tirés et affichés une seule fois, et une vérification en mémoire — le seul moment
      où la titulaire s'assure qu'elle a bien recopié. Il remet **deux fichiers** : le coffre,
      et l'identité qui s'imprime en page 1 du pli et n'a rien à faire dans le coffre.
- [x] **Un banc au dépôt** — `tests/banc.sh` éprouve chaque contrôle sur le défaut qu'il
      prétend attraper, et pilote **les deux lecteurs** : la réimplémentation Node et
      l'application réelle. Il imprime son propre décompte plutôt que de le faire recopier
      ailleurs. La boucle papier y est jointe depuis le 04/09 — rasteriser, relire les QR,
      borner la résolution, et depuis le 09/09 dégrader la planche comme un scanner.
      Au 09/09 : **83 contrôles de format, 20 papier, 18 navigateur.**
      **Dépendances dérivées des imports, pas recopiées** : binaires
      `zbar-tools`, `poppler-utils`, `weasyprint`, `node` ; modules Python `cryptography`,
      `qrcode` et `pillow` — ce dernier parce que `qrcode` en a besoin pour écrire un PNG.
      Le banc vérifie les sept avant de mesurer quoi que ce soit, et nomme celui qui manque.
- [~] **Versionner le pli** : la page 1 porte un numéro de version, authentifié par l'AAD,
      repris du coffre et borné à 99 999 des deux côtés depuis le 06/09. Le pli ne prétend plus
      qu'un ancien code est mort — **rien ne révoque quoi que ce soit hors ligne**, et l'ancien
      code ouvre l'ancien coffre tant qu'il en subsiste une copie. Reste ouvert : rien ne
      vérifie qu'un coffre régénéré incrémente son numéro, et une succession de versions n'est
      pas *prouvable* — un champ `precedent = SHA-256(coffre v(n-1))` dans l'AAD la rendrait
      démontrable au lieu de déclarée.
- [ ] **L'encodeur de QR codes dans le navigateur** — le pli est aujourd'hui composé par
      `outils/faire_pli.py`, qui exige Python et `qrcode`. Tant qu'il n'existe pas, la
      titulaire fabrique son coffre à l'atelier mais ne peut pas imprimer son pli seule.
- [ ] **base32 en mode alphanumérique** — mesuré le 06/09 : +21 % de densité par QR code.
      C'est le seul chemin qui laisse de la place pour de vraies directives, dont le pli ne
      porte aujourd'hui aucun octet. ⚠️ Le gain en pages qui figurait ici — « de 13 à 7 » — se
      calculait sur le pli à 4,2 cm imprimé deux fois, et ne vaut plus. Sur la géométrie
      actuelle — 6 QR par planche, 4 planches, 12 pages — les 24 QR codes tomberaient à 20 et
      le nombre de planches ne bougerait pas : ce que la densité achète n'est pas des pages
      mais quatre emplacements libres. À recalculer avant d'en faire un argument. Rupture de
      format : `PLI1` → `PLI2`, séparateur `|` → `:`. Décision de produit, non prise.
- [ ] **Articuler avec le document type de directives** — deux objets à ne pas mélanger : les
      directives disent *quoi faire* et partent en clair, le coffre porte *les moyens* et ne
      part jamais en clair. Le document type peut désigner le coffre et dire où il est, sans
      jamais porter la clé.

## Ce qui n'est pas décidé et ne doit pas l'être par défaut

- Le coffre contient-il des mots de passe ? Si oui, on marche sur Proton Pass — **vérifier
  d'abord s'il a un accès d'urgence**, non mesuré à ce jour.
- Combien de serrures, et pour qui ? Le format en accepte jusqu'à **8** depuis le 06/09, la
  démo en pose 2. Une variante mesurée attend un arbitrage : **tirer L1 sur 50 caractères et en
  remettre deux moitiés à deux dépositaires** fermerait la faiblesse assumée ci-dessus pour
  trente caractères de papier et zéro ligne de code. ⚠️ Scinder le code *actuel* de 25
  caractères donnerait 49 bits par moitié — cassables en moins d'une heure. Il faut allonger le
  tirage, pas couper l'existant.
- Le nom `SelfVault` est un provisoire, pas un arbitrage.
