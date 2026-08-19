# Passation

Ce fichier porte le **présent**, et rien d'autre. Il est écrasé à chaque
passage : son historique vit dans `git log -p PASSATION.md`, avec les dates et
le contexte de chaque commit.

Ce qui n'y va pas, parce que c'est déjà ailleurs et mieux :

- **l'état du dépôt et de l'instance** — il se lance, il ne s'écrit pas :
  `./scripts/ecart-instance.sh` et `git log` sont à jour par construction ;
- **le pourquoi d'un changement** — dans son message de commit ;
- **une règle qui vaut au-delà de ce passage** — dans `AGENTS.md` ;
- **un constat à retenir** — dans les mémoires, hors dépôt.

Si une ligne d'ici mérite de survivre au prochain passage, elle est au mauvais
endroit. Et comme ce fichier est public : rien qui n'irait pas dans un commit.

---

**Dernier passage : 19 août 2026 — 22:26**

## À faire

- Les contrôles à état de ce dépôt sont audités pour le défaut « une sonde qui
  échoue efface sa mémoire » — corrigé le 19/08. Ceux des autres projets ne le
  sont pas ; ils sont suivis hors dépôt.
- Le script de déploiement conclut par un succès inconditionnel : un `rsync` en
  code 23 le laisse afficher « Déployé ». C'est le défaut réparé le 17/08 sur
  son homologue de SelfJustice, resté dans ce second exemplaire. Il vit hors
  dépôt, donc son correctif ne se verra dans aucun commit.

## En attente de décision

- Une règle sudoers est prête pour le watchdog d'un projet voisin, à poser à la
  main faute de droits : elle est décrite hors dépôt, avec sa commande de pose
  et son contrôle. Sans elle, le watchdog signale correctement qu'il ne peut
  pas relancer — mais ne relance pas.

- Un service sans fréquentation tourne encore, base vide. **Il est conservé
  volontairement** : il servira de terrain d'essai pour un site en clair à
  l'automne. Ne pas le fermer au motif qu'il paraît mort.

## Pièges actifs

- **Renommer un fichier servi ne le retire pas de la production.** Le `rsync`
  est sans `--delete` : l'ancien nom continue d'être servi après le
  déploiement, et un endpoint retiré du dépôt reste joignable. Constaté le
  19/08 sur une porte de récupération qui délivrait un accès sur un seul
  secret — elle a survécu à sa propre suppression jusqu'à un retrait manuel.
  Après tout renommage : vérifier l'ancien nom, pas seulement le nouveau.
- **Un `vendor/` figé bloque le lien que le déploiement veut poser.** Un
  dossier ne se laisse pas remplacer par un lien symbolique : `rsync` échoue
  sur ce point seul, et le reste passe. Trouvé le 19/08 — la vitrine tournait
  depuis trois mois sur une dépendance périmée, à côté du module à jour.
- **Les sept cibles supprimées en production le 19/08 n'ont plus de filet
  applicatif.** Retour arrière par l'archive datée du jour sur la machine, ou
  par la sauvegarde chiffrée. Le `sed` inverse sur les vhosts ne suffit plus.
- **Deux fichiers d'état voisins ont des sens de défaillance opposés.** Celui
  des volumes, perdu, rend le contrôle muet — il se fusionne, jamais il ne
  s'écrase. Celui du silence de notification, perdu, fait envoyer une alerte de
  trop — c'est voulu. Ne pas uniformiser leur traitement.
- **Le déploiement a deux destinations** depuis le 19/08 : le code servi par le
  frontal, et les outils que le planificateur exécute. Une modification d'outil
  qui n'atteint pas la seconde ne s'exécute jamais, sans que rien ne le dise.
- **La liste blanche des routes vit dans le vhost, que le déploiement ne
  touche pas.** Renommer un endpoint suppose donc deux gestes : le code, puis
  la configuration servie. Dans cet ordre inverse — élargir la liste avant de
  déployer, la resserrer après — le service ne s'interrompt pas.
