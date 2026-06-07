# SelfRecover-LUKS — Whitepaper

> **Déverrouillage souverain de disques chiffrés par passphrase de récupération unifiée**
> *Architecture, sécurité et déploiement*

Écosystème MySelf — pilier Self-Security · Version 0.3.0 · 07 juin 2026 · AGPL-3.0-or-later
📥 Aussi disponible en DOCX (impression / diffusion) : [SelfRecover-LUKS_Whitepaper.docx](./SelfRecover-LUKS_Whitepaper.docx)

---

## Résumé

SelfRecover-LUKS permet de déverrouiller l'intégralité des volumes chiffrés (LUKS2) d'un serveur à partir d'une unique passphrase de récupération, à distance et dès le démarrage, sans dépendre d'un service tiers, d'un cloud, ni d'une connexion obligatoire vers un fournisseur.

## 1. Contexte et problème

Le chiffrement intégral du disque (FDE) protège les données au repos. Il pose toutefois trois difficultés opérationnelles récurrentes :

- **Accès distant —** déverrouiller un serveur à distance (machine hébergée) ;

- **Multiplicité —** gérer plusieurs volumes chiffrés (racine + données) sans multiplier les secrets à retenir ;

- **Résilience —** garantir la récupération après perte du secret principal ou destruction du matériel.

Les solutions courantes délèguent souvent à un tiers : séquestre de clés dans le cloud, puce liée au constructeur, serveur de clés réseau. SelfRecover-LUKS répond aux trois besoins sans aucune dépendance externe.

## 2. Positionnement : une honnêteté revendiquée

SelfRecover-LUKS n'invente aucune primitive cryptographique. LUKS2 et Argon2id assurent le chiffrement et la dérivation ; un serveur SSH minimal embarqué dans l'image d'amorçage assure l'accès distant. La valeur ajoutée est strictement architecturale : assembler ces briques en un protocole cohérent, entièrement auto-hébergé, où un seul secret mémorisable gouverne l'ensemble — sans cloud, sans tiers de confiance, sans connexion réseau imposée. C'est une solution de niche assumée : elle vise la souveraineté et la simplicité d'usage, non une performance cryptographique inédite.

## 3. Vue d'ensemble de l'architecture

Le flux se résume ainsi :

> Passphrase de récupération (mémorisée)  
> │  
> ▼ dérivation Argon2id, cloisonnée par étiquette  
> clés filles indépendantes (étiquettes : disk / auth / data-enc …)  
> │  
> ├──► volume RACINE : keyscript dans l'image d'amorçage  
> │ + accès distant SSH minimal (saisie à distance)  
> │  
> └──► volumes SECONDAIRES : fichier-clé stocké sur la racine  
> (chiffrée) → ouverture automatique après le pivot

## 4. Le secret unifié : dérivation cloisonnée par étiquette

Une unique passphrase racine produit des clés filles indépendantes selon une étiquette :

- **étiquette « disk » —** clé d'un slot LUKS (déverrouillage disque) ;

- **étiquette « auth » —** preuve d'identité / d'accès applicatif ;

- **étiquette « data-enc » —** chiffrement de données applicatives.

Le sel effectif est dérivé par SHA-256(sel_de_déploiement \|\| étiquette), tronqué. Deux étiquettes issues du même secret produisent des clés non corrélées : compromettre la clé applicative ne révèle pas la clé disque. La dérivation emploie Argon2id (memory-hard), adaptée à une clé de disque exposée à une attaque hors-ligne en cas de vol du support : chaque essai est massivement ralenti.

## 5. Déverrouillage du volume racine au démarrage

Le volume racine est référencé dans la table de chiffrement avec un keyscript. Au démarrage, ce keyscript demande la passphrase de récupération, la dérive (étiquette « disk ») et fournit la clé brute à l'outil de déverrouillage. Pour l'accès distant, un serveur SSH minimal est embarqué dans l'image d'amorçage : l'administrateur s'y connecte et saisit sa passphrase à distance.

Deux points d'attention de déploiement, souvent sous-estimés :

- **Bibliothèque d'exécution —** la fonction de dérivation, multi-thread, requiert la présence de la bibliothèque d'exécution des threads dans l'image d'amorçage (dépendance chargée dynamiquement, invisible à l'analyse statique des dépendances) ;

- **Délai d'attente —** le délai d'attente du périphérique racine doit être étendu, afin de laisser le temps d'établir la connexion distante et de saisir la passphrase avant abandon.

## 6. Cascade des volumes secondaires : fichier-clé dans le coffre

Sur les systèmes d'amorçage modernes, les volumes non-racine sont pris en charge par un mécanisme qui ne supporte pas les keyscripts. La réponse standard et robuste consiste à utiliser un fichier-clé aléatoire, stocké sur le volume racine (lui-même chiffré), et ajouté comme slot LUKS du volume secondaire.

Une fois la racine déverrouillée, le système monte automatiquement les volumes secondaires en lisant ce fichier-clé. Ainsi, une seule saisie de la passphrase de récupération ouvre la racine, puis tous les volumes secondaires s'ouvrent en cascade. La sécurité tient au fait que le fichier-clé réside dans le coffre chiffré : un disque volé reste illisible, car la racine chiffrée rend le fichier-clé inaccessible.

## 7. Modèle de menace

| **Scénario**                             | **Traitement**                                                                                      |
|------------------------------------------|-----------------------------------------------------------------------------------------------------|
| **Vol / perte du support (au repos)**    | LUKS2 + Argon2id : sans la passphrase, l'attaque hors-ligne est massivement ralentie (memory-hard). |
| **Déverrouillage à distance**            | La passphrase transite chiffrée par SSH ; elle n'est jamais stockée en clair.                       |
| **Dépendance à un tiers**                | Aucune : pas de séquestre cloud, pas de clé confiée à un constructeur ou à un serveur externe.      |
| **Machine allumée / déverrouillée**      | Hors périmètre : les clés sont en mémoire (vrai pour tout FDE).                                     |
| **Altération de l'amorçage (evil-maid)** | Hors périmètre à ce stade : amorçage en clair → cf. travaux futurs (signature / intégrité).         |

## 8. Filets anti-verrouillage

Chaque volume conserve un slot « natif » (passphrase classique), indépendant du mécanisme de récupération. En cas de défaillance du keyscript, l'administrateur ouvre le volume manuellement via ce slot, puis poursuit l'amorçage. Des sauvegardes de l'image d'amorçage et de la configuration autorisent un retour arrière immédiat. Principe directeur : aucun point de défaillance unique du côté du déverrouillage.

## 9. Récupération après catastrophe

Reconstruire le système après destruction du matériel suppose de conserver, HORS du serveur, trois éléments :

- **Passphrase —** la passphrase de récupération (mémorisée et/ou stockée) ;

- **Sel —** le sel de déploiement, indispensable à la dérivation ;

- **Sauvegardes —** les secrets d'accès aux sauvegardes (accès au dépôt distant, passphrase du dépôt chiffré).

Sans le sel conservé hors-site, la dérivation est irréalisable sur du matériel neuf : la passphrase seule ne suffit pas. C'est le point critique de toute architecture de ce type, et il doit être traité explicitement, non implicitement.

## 10. Déploiement — étapes génériques

1.  Installer le système en chiffrement intégral (volume racine LUKS2).

2.  Déployer la fonction de dérivation et le sel de déploiement.

3.  Ajouter un slot « récupération » sur chaque volume (clé dérivée, étiquette « disk »), en conservant le slot natif.

4.  Volume racine : keyscript dans la table de chiffrement + serveur SSH minimal dans l'image d'amorçage.

5.  Volumes secondaires : fichier-clé sur la racine + référence dans la table de chiffrement (montés après le pivot).

6.  Régénérer l'image d'amorçage, sauvegarder l'image précédente, puis tester par redémarrage avec filet (slot natif + accès console).

## 11. Limites et travaux futurs

- **Durcissement evil-maid :** amorçage en clair → signature (démarrage sécurisé) ou mesure d'intégrité matérielle à étudier.

- **Quorum distribué :** déverrouillage automatique par consensus de témoins, sans saisie, en réflexion.

- **Unification des sauvegardes :** dériver les secrets de sauvegarde depuis la même passphrase racine (étiquette dédiée), pour unifier complètement le socle de confiance — en gardant à l'esprit la contrainte du sel hors-site.

## 12. Licence et écosystème

SelfRecover-LUKS est publié sous licence AGPL-3.0-or-later. Il s'inscrit dans le pilier Self-Security de l'écosystème MySelf, dédié à la souveraineté numérique : des outils auto-hébergeables, sans dépendance à un cloud ni à un tiers de confiance.
