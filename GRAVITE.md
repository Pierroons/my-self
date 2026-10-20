# GRAVITE.md

Comment se note la gravité d'un constat dans ce dépôt. `REVIEW.md` dit **quoi
signaler** ; ce fichier dit **à quel niveau**, et surtout d'après quelles règles.

Sans barème écrit, la gravité se décide à la discussion, au cas par cas — donc
elle varie selon qui relit, et elle se renégocie à chaque fois. Les trois règles
ci-dessous existent pour que deux relectures indépendantes arrivent au même
niveau, et pour qu'un désaccord porte sur les faits plutôt que sur l'échelle.

## Les quatre niveaux

**🔴 Critique.** Un secret ou une donnée d'utilisateur devient lisible par qui ne
détient rien ; ou un tiers prive durablement le titulaire de son compte ; ou des
données d'utilisateur sont détruites sans décision humaine ; ou un pouvoir
d'administration s'exerce sans trace qu'on ne puisse falsifier.

> Jusqu'à la v0.11.0, le frein du niveau 1 comptait les échecs sous le nom du
> compte visé : un tiers qui connaissait ce nom fermait la porte du titulaire en
> épuisant son compteur. Jusqu'à la v0.12.0, un refus d'arbitre déclenchait
> lui-même un gel, si bien que trois dossiers frauduleux gelaient la victime.
> Les deux ferment la dernière porte à qui n'a rien fait : critiques.

**🟠 Haute.** Exploitable avec un prérequis réaliste — une écriture en base, un
secret déjà fuité, un intégrateur qui suit le contrat à la lettre, une
configuration que la documentation recommande. Ou : une propriété de sécurité
annoncée que le code ne tient pas.

> Un frein qui compte les échecs de plusieurs portes dans un même compteur :
> échouer sur l'une ferme les autres. Corrigé en v0.12.x en bornant le comptage
> aux préfixes de la porte concernée, et en refusant une liste vide — une liste
> vide comptait tout.

**🟡 Moyenne.** Le défaut dégrade sans ouvrir : déni de service, oracle dont
l'exploitation coûte cher, échec silencieux qu'un appelant peut lire comme un
succès, valeur fausse rendue à l'utilisateur.

> La v0.12.2 a corrigé une langue de passphrase qui ne suivait pas le profil de
> déploiement : le défaut d'une fonction était l'anglais, l'appelant passait le
> français explicitement, et simplifier cet appel en se fiant au commentaire
> faisait basculer les passphrases **sans qu'aucun banc rougisse**.

**🟢 Basse — dette.** La propriété tient aujourd'hui, mais rien ne l'impose : un
docblock la demande, le moteur de base de données la fournit par accident, ou
l'ordre d'appel la garantit tant que personne ne le change.

## Les trois règles qui tranchent

Ce sont elles qui rendent ce barème propre à ce projet plutôt qu'applicable à
n'importe quel logiciel. Une seule suffit à faire monter un constat.

### 1. La règle du dernier recours — un cran de plus

**Tout défaut qui empêche le titulaire d'atteindre sa dernière porte monte d'un
niveau.** Ce projet refuse par conception l'adresse de courriel, le SMS et
l'identité civile comme voies de récupération : il n'y a rien derrière. Ailleurs,
un compte verrouillé se rouvre par un support ; ici, un compte verrouillé est
perdu.

Le corollaire vaut dans l'autre sens, et il compte autant : **un frein qui
protège en enfermant dehors le propriétaire ne protège pas.** Un refus qui tombe
avant que le secret ne soit évalué, un compteur rempli par un tiers sous un
identifiant public, un plafond global qu'un adversaire seul épuise — ces trois-là
transforment une défense en arme contre l'utilisateur.

### 2. La règle de l'intégrateur conforme — haute au minimum

**Si respecter le contrat à la lettre produit une installation vulnérable, le
défaut est au contrat, pas chez l'intégrateur.** Ces bibliothèques sont faites
pour être intégrées par des gens qui ne liront pas leur code. Une propriété dont
dépend la sécurité et qui n'est pas écrite dans l'interface n'existe pas : elle
sera perdue au premier adaptateur, et silencieusement.

Trois formes à chercher : un paramètre qui porte une garde et dont la valeur par
défaut l'annule ; une propriété tenue par le moteur de stockage de référence et
jamais déclarée, donc absente dès qu'on en change ; une méthode qui tend un
secret au lieu d'exécuter l'opération qui en a besoin.

### 3. La règle du docblock — selon le bruit de sa violation

Une obligation écrite en prose (« l'appelant DOIT », « ne jamais conserver »,
« à usage unique », « appeler avant ») est une dette. Son niveau dépend d'une
seule question : **si quelqu'un la viole, est-ce qu'on l'apprend ?**

- violation **bruyante** — une exception, un test qui rougit, un refus visible :
  **basse** ;
- violation **silencieuse** — le programme continue, la donnée est écrite, rien
  ne diffère à l'œil : **haute**.

Un commentaire juste ne protège personne ; une sonde, si. Quand une obligation
monte à haute par cette règle, le correctif attendu n'est pas de mieux l'écrire :
c'est de l'imposer par le code, ou de la faire rougir.

## Ce que ce barème ne décide pas

- **S'il faut publier un avis de sécurité.** Un constat critique corrigé n'est
  pas automatiquement un advisory : cette décision regarde l'exposition réelle
  des installations, et elle n'est pas technique.
- **Le calendrier.** Un constat critique sur un chemin que personne n'emprunte
  peut attendre un lot ; un constat moyen sur une porte publique peut ne pas
  attendre. Le niveau dit la gravité, pas l'urgence.
- **La version.** Un correctif qui change une signature publique est une version
  mineure quelle que soit sa gravité. L'inverse est vrai aussi : un défaut
  critique se corrige parfois en un correctif de patch.

## La forme d'une note dans un rapport

Le niveau n'a de valeur qu'avec ce qui l'a établi. Un constat porte donc, outre
le scénario d'échec qu'exige `REVIEW.md` :

- le niveau, et **la règle qui l'a fait monter** s'il a monté ;
- ce qui est **vérifié dans le code** et ce qui reste **plausible** — les deux ne
  se notent pas pareil, et un constat plausible se dit comme tel ;
- ce qui **n'a pas été mesuré**, nommément. Une mesure absente qui n'est pas
  nommée se lit comme une mesure réussie.

---

*MySelf — AGPL-3.0-or-later. Ce fichier complète `REVIEW.md` (quoi signaler) et
`AGENTS.md` (comment travailler ici).*
