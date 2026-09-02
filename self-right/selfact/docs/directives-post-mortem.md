# Directives sur les données personnelles après le décès

Situation `directives_donnees_post_mortem` — spécification du cas, du rôle du notaire
et du document produit.

## Pourquoi ce cas existe

L'article 85 de la loi n° 78-17 permet à toute personne de définir, de son vivant, le
sort de ses données personnelles après son décès. Il ouvre deux régimes :

| Régime | Ce que la loi prévoit | État |
|---|---|---|
| **Générales** | Portent sur l'ensemble des données. Enregistrables auprès d'un *tiers de confiance numérique certifié par la CNIL*, référencées dans un *registre unique* fixé par décret en Conseil d'État. | **Inopérant** — le décret n'a jamais été publié. |
| **Particulières** | Portent sur les traitements qu'elles désignent, enregistrées auprès de chaque responsable de traitement. Exigent un *consentement spécifique* qui ne peut pas résulter de l'approbation des CGU. | **Applicable** aujourd'hui, sans condition de forme. |

La CNIL constate elle-même la carence dans son cahier Innovation & Prospective n° 10
« Nos données après nous » (octobre 2025), et renvoie alors au notaire comme voie de
consignation praticable.

C'est la seule situation du module **adossée au texte de loi plutôt qu'au catalogue** :
le catalogue des ressources officielles ne porte rien sur le sujet — ni sur « données
personnelles décès », ni sur « testament », ni sur « dernières volontés ». Ses entrées
« succession » sont toutes fiscales et post-mortem.

## Le rôle du notaire — ce qu'il est, et ce qu'il n'est pas

### Ce qu'il est

1. **Consignataire des directives.** À défaut du registre unique, il reçoit et conserve
   le document. Le dépôt d'un écrit avant décès est une prestation établie et tarifée
   (art. A444-60 du code de commerce : garde d'un testament olographe avant décès).
2. **Point d'ouverture au décès.** Vérifier un acte de décès et exécuter des volontés
   est son métier. Aucun mécanisme technique n'a besoin d'être inventé pour ça.
3. **Officier public.** Ce qu'il reçoit acquiert date certaine, ce qu'aucun fichier posé
   sur un disque ne peut offrir.

### Ce qu'il n'est pas

- ❌ **Le « tiers de confiance numérique certifié par la CNIL » de l'article 85.** Celui-là
  n'existe pas : aucun référentiel de certification n'a été publié. Employer ce terme
  devant un notaire crée un malentendu — il comprend le sens légal, et il a raison de
  dire que ça n'existe pas. Dire « tiers de confiance au sens courant ».
- ❌ **Le détenteur des données.** Dans l'architecture visée, il détient de quoi ouvrir,
  jamais le contenu. Un cabinet qui ferme ne doit pas emporter les données avec lui.
- ❌ **Un point de confiance unique.** SelfRecover pose la règle et elle vaut ici :
  *aucun humain seul ne doit pouvoir ouvrir*. Le notaire est un chemin, pas le chemin.

## Le rôle du document produit

### Ce qu'il est

Un **brouillon d'aide à la rédaction**, mis en forme à partir d'un gabarit fixe, que
l'utilisateur relit, complète et assume. Le gabarit `directives_donnees_post_mortem`
énonce les mentions que l'article 85 rend utiles : le traitement visé, le sort demandé
pour les données, la personne chargée de l'exécution, et le rappel que les directives
sont révocables à tout moment.

### Ce qu'il n'est pas

- ❌ **Un acte juridique.** Il porte la mention « NON OFFICIEL » en tête, au milieu et en
  pied de document.
- ❌ **Un testament.** L'article 970 du code civil exige un écrit *en entier, daté et
  signé de la main* du testateur ; un document dactylographié signé à la main est nul
  comme testament (Cass. 1re civ., 23 octobre 1984, n° 83-14.398). Le document le dit
  dans son corps, pas en note de bas de page.
- ❌ **Un conseil juridique.** Le gabarit est fixe. Il ne varie pas selon la situation de
  l'utilisateur, aucun arbre de décision ne produit de recommandation, et la route ne
  reçoit aucune donnée : `POST` est refusé en 405, le remplissage a lieu dans le
  navigateur, rien n'est transmis ni conservé.

### Le montage qu'il sert

Deux pièces **séparées**, et la séparation est la raison d'être du montage :

- les **directives**, dactylographiées — l'article 85 ne leur impose aucune forme ;
- un **testament olographe manuscrit**, s'il faut désigner l'exécutant par testament
  (art. 1025 du code civil), qui renvoie au document séparé.

Réunir les deux dans un seul document dactylographié signé à la main annule le
testament. C'est l'espèce jugée en 1984.

## Classement dans la matrice A/B/C

Ce cas relève du **cas C** : il imite la forme d'un acte juridique, et aucun modèle
officiel ne lui correspond. C'est la définition même du C.

⚠️ **Deux décisions du 02/09/2026, à ne pas confondre.** La première porte sur la FORME
de la marque du C : le filigrane diagonal a été retiré, parce qu'il recouvrait le corps du
document et le rendait difficile à lire — un avertissement illisible n'avertit personne.
Il est remplacé par la mention « NON OFFICIEL » en clair, répétée en tête et au milieu.

La seconde porte sur sa PORTÉE. La mention a d'abord été posée sur tous les cas, puis
ramenée au **cas C**, comme à l'origine : un courrier amiable n'imite aucun acte, et le
dire « non officiel » ne répond à aucune confusion possible. Un avertissement qui se
répète là où il n'a pas lieu d'être finit par ne plus être lu là où il compte.

Le classement en C commande donc bien un marquage, et ce cas-ci le reçoit — c'est
cohérent avec les trois « n'est pas » ci-dessus, et notamment avec le testament. Le rappel
en pied de page, lui, reste sur tous les documents : aucun n'est rendu nu.

## Ce que le module porte, et où

| Élément | Emplacement |
|---|---|
| La situation | `api/data/situations.json` → `directives_donnees_post_mortem` |
| Le gabarit | `api/data/gabarits.json` → `directives_donnees_post_mortem` |
| Le corps du document | `api/draft.php` (bloc conditionnel sur le type) |
| Le garde-fou du fichier de situations | `tests/test_situations.sh` |

Les articles cités sont portés **en liste d'objets** (`art_applicable`), avec leur
référence lisible et les identifiants permettant de les revérifier en base. C'est la
première situation à employer cette forme ; les autres portent une chaîne libre.

## Ce qui reste ouvert

- **La remise au survivant n'existe nulle part.** SelfRecover restaure un *accès*, jamais
  des *données* — c'est sa règle de conception la plus verrouillée, et elle interdit de
  le transposer pour ouvrir un coffre après un décès. SelfDataGuard chiffre, mais ne dit
  rien de qui ouvre quand le titulaire n'est plus là. Le partage à seuil existe en R&D
  (`self-security/selfrecover-luks/quorum-rnd/`), pour le déverrouillage de volume au
  démarrage, et n'est pas activé.
- **Relecture par un praticien du droit** : jamais faite, et c'est la faiblesse principale
  de ce cas comme de tout le module.
- Le décret de l'article 85 peut paraître. Rien ne le laisse penser — la carence est
  documentée depuis une question sénatoriale de 2017 — mais un outil de rédaction reste
  utile après : un tiers certifié conserverait l'acte, il ne l'écrirait pas.
