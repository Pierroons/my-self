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

**Dernier passage : 19 août 2026 — 22:50**

## À faire

- Les contrôles à état de ce dépôt sont audités pour le défaut « une sonde qui
  échoue efface sa mémoire » — corrigé le 19/08. Ceux des autres projets ne le
  sont pas ; ils sont suivis hors dépôt.

## En attente de décision

- Une règle sudoers est prête pour le watchdog d'un projet voisin, à poser à la
  main faute de droits : elle est décrite hors dépôt, avec sa commande de pose
  et son contrôle. Sans elle, le watchdog signale correctement qu'il ne peut
  pas relancer — mais ne relance pas.

- Un service sans fréquentation tourne encore, base vide. **Il est conservé
  volontairement** : il servira de terrain d'essai pour un site en clair à
  l'automne. Ne pas le fermer au motif qu'il paraît mort.

## Pièges actifs

- **Les sept cibles supprimées en production le 19/08 n'ont plus de filet
  applicatif.** Retour arrière par l'archive datée du jour sur la machine, ou
  par la sauvegarde chiffrée. Le `sed` inverse sur les vhosts ne suffit plus.
- **Le script de déploiement conclut par un succès inconditionnel** : un
  transfert en échec le laisse afficher « Déployé ». Corrigé le 17/08 sur son
  homologue, resté dans ce second exemplaire. Il vit hors dépôt, donc son
  correctif ne se verra dans aucun commit.
