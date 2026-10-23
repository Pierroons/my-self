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

## Ce qui touche un secret, une clé ou une dérivation

Un changement qui crée, transporte, stocke ou dérive un secret répond à ces cinq questions. Une
réponse ambiguë est un constat.

- **Quel composant possède le secret ?** Le nommer, avec l'endroit où il naît.
- **Quelle frontière de confiance traverse-t-il ?** Navigateur vers serveur, module vers
  intégrateur, mémoire vers disque, initramfs vers système.
- **Que peut obtenir un appelant compromis ?** Ce qu'un intégrateur malveillant, ou un code hôte
  compromis, retire de l'API publique modifiée.
- **Que se passe-t-il quand une dépendance ne répond plus ?** Un refus franc, jamais un repli vers
  un chemin moins protégé.
- **Que peut-on observer sans exposer le secret ?** Un journal porte l'opération, l'appelant et le
  résultat ; ni le secret, ni la clé, ni le texte en clair.

Cinq règles en découlent pour le code tel qu'il est.

**1. Une clé ne sort pas du composant qui la détient.** Une méthode publique rend le résultat
d'une opération — un coffre ouvert, un texte chiffré —, pas une clé maîtresse ou dérivée.
> Depuis SelfDataGuard 0.7.0, `UnlockedVault`, `UnlockedEscrow` et `UnlockedArchive` font
> l'opération elles-mêmes — chiffrer un champ, ouvrir le séquestre, resceller une serrure — et
> `self-security/selfdataguard/tests/sanity_keys.php` rougit si une méthode publique de la couche
> objet rend ou reçoit une clé. La clé admin n'est descellée que dans `EscrowCeremony`, par des
> méthodes privées, après l'arbitre, le journal et le témoin de tête : une méthode publique qui la
> rendrait ou la recevrait est un constat.

**2. Une dérivation porte un contexte nommé, propre à son usage.** Chaque chaîne de séparation de
domaine est une constante, et deux usages ne partagent pas une chaîne par coïncidence.
> `UserVault` dérive la serrure du mot mémorisé sur `HMAC_CONTEXT_SUFFIX` (`/dataguard`) et celle
> de la passphrase sur `PASSPHRASE_CONTEXT_SUFFIX` (`/dataguard/passphrase`) : deux serrures, deux
> chaînes.

**3. Le code d'un module n'importe pas celui d'un autre.** Deux modules qui doivent s'accorder
déclarent chacun leur valeur, et un banc les tient d'accord ; c'est l'intégrateur qui les fait
travailler ensemble.
> `bi-self/selfrecover/tests/sanity_couplage_dataguard.php` tient SelfRecover et SelfDataGuard
> d'accord sur la normalisation de la passphrase et le plancher du mot de passe, sans qu'aucune
> des deux bibliothèques ne dépende de l'autre. Après une récupération, c'est l'intégrateur qui
> rouvre le coffre et le scelle sous les nouveaux secrets, en une seule écriture.

**4. Ce qui ne sait pas répond non.** Une dépendance muette, une identité invérifiable, un
paramètre inconnu : refus, et le refus se dit. Aucune valeur par défaut n'autorise.

**5. Une interface n'est pas une frontière.** Deux classes d'un même processus partagent sa
mémoire. Une garantie qui repose sur l'encapsulation se présente comme telle, jamais comme une
isolation.

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
