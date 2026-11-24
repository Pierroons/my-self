# Déployer la démo SelfDataGuard

Ce dossier porte la configuration de service de la démo publique
(`demo/selfdataguard/`). Le code se pose avec le reste du dépôt par
`deploy/my-self/deploy.sh` ; ces fichiers-ci se posent à la main.

| fichier | où il va |
|---|---|
| `nginx-dataguard.conf` | le vhost de la démo |
| `php-fpm-dataguard.conf` | le groupe d'ouvriers PHP de la démo, sous l'utilisateur `php-dataguard` |
| `demo-sessions-purge.service` et `.timer` | la purge des bases de démonstration, sous la même identité |

Le groupe d'ouvriers, ses droits et l'ordre de sa pose sont décrits dans
`deploy/my-self/README.md`, qui découpe les sites PHP de l'instance en plusieurs
identités. Pose-le d'abord : la suite suppose que `/var/lib/selfdataguard/`
appartient à `php-dataguard`, en `0700`.

## Ce que la démo garde, et où

Tout l'état vit dans `/var/lib/selfdataguard/`, hors de la racine servie. Le
vhost le désigne à PHP par trois `fastcgi_param` :

| paramètre | fichier |
|---|---|
| `DATAGUARD_DB_PATH` | son dossier porte `sessions/`, une base par visiteur, effacée 30 minutes après sa dernière action |
| `DATAGUARD_BLINDKEY_PATH` | la clé d'index |
| `DATAGUARD_PEPPER_PATH` | le poivre des serrures, depuis la 0.10.0 |

La clé d'administration de démonstration (`admin-recovery.pub` et `.sealed`)
s'écrit à côté de la clé d'index.

Une instance qui nomme `DATAGUARD_DB_PATH` doit nommer les deux autres : la démo
répond 500, en nommant le paramètre manquant, plutôt que de prendre les replis
des essais locaux.

`demo/selfdataguard/storage/` sert à ces essais locaux (`run.sh`). Sur
l'instance, il ne contient que `.gitkeep` (mesuré le 24 novembre 2026) et reste
à `www-data` : `php-dataguard` n'y écrit pas, et un paramètre perdu fait échouer
la démo au lieu d'écrire un secret dans l'arbre servi.

## Passer à la 0.10.0

Les bases écrites par la 0.9.x n'ont pas de poivre, et la 0.10.0 refuse une
base qui tient des coffres sans l'avoir inscrit. Les bases de la démo ne durent
que 30 minutes : on ne les fait pas monter, on les vide.

1. **Créer le poivre**, avant de poser le code :

   ```
   sudo -u php-dataguard php /var/www/myself/self-security/selfdataguard/bin/pepper.php new /var/lib/selfdataguard/pepper
   ```

   L'outil l'écrit en `0600` et n'écrase jamais un fichier existant. Pour la
   démo, sa perte ne ferme que des bases éphémères : aucune copie n'est
   nécessaire.

2. **Poser le vhost** (le paramètre `DATAGUARD_PEPPER_PATH`), puis le code :

   ```
   sudo nginx -t && sudo systemctl reload nginx
   ```

3. **Vider les bases de la 0.9.x** :

   ```
   sudo -u php-dataguard find /var/lib/selfdataguard/sessions -mindepth 1 -delete
   ```

   Sans ce geste, un visiteur qui tient une base d'avant reçoit une erreur
   jusqu'à ce qu'elle expire.

4. **Vérifier** : une requête, puis lire, sous le nom que porte ton vhost.

   ```
   curl -s https://dataguard.example.org/api/inspect_db.php
   ```

   La réponse liste des coffres vides, sans `error`. Inscris-toi ensuite dans
   la page avec un mot mémorisé, puis ouvre le coffre par ce mot.

**Revenir à la 0.9.x** demande le même geste dans l'autre sens : la 0.9.x
n'ouvre pas une serrure scellée sous le poivre, donc on vide `sessions/` après
avoir reposé l'ancien code.

## Une base qui dure

La démo ne fait pas monter ses bases. Une base qui dure, chez un intégrateur,
passe par une montée en quatre temps, que décrit l'en-tête de
`self-security/selfdataguard/bin/pepper.php` :

1. la façade construite en `PepperForms::AcceptBare` ;
2. `pepper.php adopt`, qui pose une couche sous le poivre sur chaque serrure, sans secret
   d'utilisateur ;
3. `pepper.php status`, jusqu'à ce que plus rien ne soit nu ;
4. la façade construite en `PepperForms::LayeredOnly`.

Pour une telle base, la perte du poivre ferme tout coffre qui n'a pas de
passphrase : garde-en une copie hors du serveur.
