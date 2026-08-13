# REVIEW.md

Ce que la relecture de ce dépôt cherche. Complète `AGENTS.md`, qui dit comment
travailler ici ; ce fichier dit quoi signaler.

## Le critère

Un constat se rend quand il porte un **scénario d'échec concret** : les entrées,
l'état de départ, et la sortie fausse ou l'arrêt qui en résulte. Un constat sans
ce scénario reste à vérifier avant d'être écrit.

La relecture porte sur ce que le programme fait. Le nommage, la mise en forme et
les préférences d'écriture relèvent d'`AGENTS.md`.

## Les motifs à chercher en premier

Ces huit motifs sont ceux qui ont réellement produit des défauts ici. Chacun est
illustré par un cas survenu dans ce dépôt.

**1. Le garde-fou déclaré, jamais branché.** La protection existe, porte le bon
nom, et n'est reliée à rien. Chercher l'endroit où elle est *activée*, pas
seulement l'endroit où elle est écrite.
> Des `FOREIGN KEY` déclarées sans `PRAGMA foreign_keys = ON` : contraintes
> décoratives, orphelins à chaque suppression. Une CI de détection de secrets
> écoutant une branche qui n'existe pas, verte depuis la création du dépôt.

**2. La table bâtie sur une observation unique.** Une correspondance code → nom
écrite d'après la seule valeur rencontrée pendant le test.
> Une table de provenance construite sur une valeur vue une fois, quand la
> valeur réellement servie est une autre, sur dix décisions sur dix. Vérifier
> qu'un inventaire — `GROUP BY`, taxonomie publiée de la source — précède la
> table.

**3. Le seuil en fraction.** Comparer `trouvés / total` à un flottant fait varier
la sévérité avec la taille de l'entrée : une même règle coupe entre 1/4 et 2/4
sur quatre termes, et laisse passer 2/5 qui vaut 0,400 exactement.
> Préférer un critère entier — `trouves * 2 < total` — dont le sens ne dépend
> plus du nombre d'éléments.

**4. La condition qui ne lit qu'une de ses deux bornes.** Un conseil ou un filtre
qui teste `depuis` sans jamais consulter `jusqu_a`.
> Une recherche bornée à une date haute s'entendait conseiller de viser « l'état
> actuel du droit ».

**5. La sonde qui mesure autre chose que ce qu'elle annonce.** Le contrôle
répond, la valeur est plausible, et elle porte sur une grandeur voisine.
> Un contrôle de gel d'image mesurant le débit réseau : le flux continue de se
> télécharger pendant que rien ne s'affiche. Une sonde se valide sur un état
> **sain** avant d'être crue sur un état dégradé.

**6. Le contrat inversé entre deux chemins voisins.** Deux recherches du même
service, l'une exigeant tous les termes, l'autre aucun, sans que la différence
soit dite.
> Allonger la question rend plus de résultats d'un côté et presque aucun de
> l'autre. Le comportement se documente là où il surprend.

**7. L'erreur avalée.** Un `except` large, un retour vide, un code d'erreur non
rattrapé sur un chemin parmi d'autres.
> Cinq outils sur huit ne rattrapaient pas une erreur de requête invalide,
> invisible tant qu'un seul chemin la produisait.

**8. Le compte tronqué annoncé comme exact.** Un total calculé sur une liste déjà
limitée, présenté comme le total réel.
> Un outil dont la fonction est de dire ce qui existe annonçait un nombre saturé
> par sa propre limite d'affichage.

## Ce qui mérite une lecture ligne à ligne

- Tout ce qui décide qu'une donnée est **absente** plutôt qu'introuvable : la
  différence engage l'utilisateur qui agit sur la réponse.
- Les valeurs par défaut d'un paramètre exposé : un défaut mal choisi produit un
  résultat vide sans erreur.
- Les bornes d'intervalle reçues d'une source externe, à contraindre à la taille
  réelle de la donnée avant usage.
- Les zones listées « sensibles » dans `AGENTS.md`.

## La forme d'un rapport

- Les constats sont classés du plus grave au moins grave.
- Chacun distingue ce qui a été **vérifié dans le code** de ce qui reste
  plausible.
- Un motif qui se répète est signalé comme motif, pas comme une suite de cas
  isolés.
