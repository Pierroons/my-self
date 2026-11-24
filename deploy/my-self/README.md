# `deploy/my-self` — ce qui se pose, dans quel ordre, et ce qui le prouve

Ce dossier porte la configuration de service de la machine qui sert MySelf :
le groupe d'ouvriers PHP résiduel, et les contrôles qui mesurent l'écart entre
ce qui est versionné et ce qui tourne. Le déploiement du **code** est l'affaire
de `deploy.sh`, dont l'en-tête dit le détail — il ne pose **ni** les vhosts,
**ni** les groupes d'ouvriers, **ni** les unités systemd, parce que `deploy/`
est exclu de l'arbre qu'il assemble.

## Le découpage des groupes d'ouvriers — 24 novembre 2026

| fichier | destination | droits |
|---|---|---|
| `../bi-self/php-fpm-biself.conf` | `/etc/php/8.4/fpm/pool.d/biself.conf` | `640 root:root` — **avant le vhost de bi-self** |
| `../bi-self/php-fpm-flux.conf` | `/etc/php/8.4/fpm/pool.d/biself-flux.conf` | `640 root:root` — **avant le vhost de bi-self** |
| `../selfdataguard/php-fpm-dataguard.conf` | `/etc/php/8.4/fpm/pool.d/dataguard.conf` | `640 root:root` — **avant le vhost de dataguard** |
| `../selfjustice/php-fpm-justice.conf` | `/etc/php/8.4/fpm/pool.d/justice.conf` | `640 root:root` — **avant le vhost de justice** |
| `php-fpm-www.conf` | `/etc/php/8.4/fpm/pool.d/www.conf` | `640 root:root` — **en DERNIER, après les vhosts** |
| `../bi-self/selfrecover-purger.service` | `/etc/systemd/system/` | `644 root:root` — avec le groupe de bi-self |
| `../selfdataguard/demo-sessions-purge.service` | `/etc/systemd/system/` | `644 root:root` — avec le groupe de dataguard |

⚠️ **Rien ici n'est optionnel et indépendant.** Un groupe posé sans que l'état
change de propriétaire laisse le site sans accès à ses données ; un propriétaire
changé sans que le groupe soit posé fait la même chose dans l'autre sens. Les
deux gestes vont ensemble, site par site.

## 🔴 La règle qui décide de tout : aucun de ces utilisateurs n'entre dans `www-data`

Mesuré sur la machine le 24/11/2026 : **74 répertoires de l'arbre servi sont
inscriptibles par le groupe `www-data`**, racine comprise. Un utilisateur membre
de ce groupe y déposerait un fichier PHP, que **tous** les groupes d'ouvriers
exécuteraient ensuite — y compris celui qui détient le poivre de SelfDataGuard.
Le cloisonnement se contournerait par **une seule écriture**.

C'est contre-intuitif, parce que l'ajout au groupe `www-data` est le geste
réflexe quand un service PHP « n'a pas les droits ». Ici il défait exactement ce
qu'on vient de construire. Ce qu'il faut à la place : **changer le propriétaire
de l'état**, jamais élargir le groupe de l'utilisateur.

Les trois utilisateurs lisent le code servi sans appartenir à aucun groupe
partagé : mesuré le 24/11/2026, les **146 fichiers PHP** de l'arbre servi sont
lisibles par autrui, et aucun n'est illisible. Le découpage ne casse donc pas la
lecture du code — il ferme l'accès à l'**état**.

## L'ordre, et pourquoi il n'est pas indifférent

1. **Créer les trois utilisateurs de service.** Aucun script du dépôt ne le
   fait, et c'est voulu : créer un compte système est un geste d'administration,
   pas de déploiement.

   ```
   sudo adduser --system --group --no-create-home --shell /usr/sbin/nologin php-biself
   sudo adduser --system --group --no-create-home --shell /usr/sbin/nologin php-dataguard
   sudo adduser --system --group --no-create-home --shell /usr/sbin/nologin php-justice
   ```

   🔑 **`--group` crée à chacun son propre groupe, et c'est le point.** Sans lui,
   le compte tombe dans `nogroup`, partagé avec d'autres services de la machine —
   le cloisonnement serait nominal.

   ⚠️ **Vérifier qu'aucun n'a atterri dans `www-data`**, puis le vérifier encore
   après toute intervention ultérieure sur les droits :

   ```
   for u in php-biself php-dataguard php-justice; do id "$u"; done
   ```

2. **Poser les quatre groupes d'ouvriers** (`biself`, `biself-flux`,
   `dataguard`, `justice`), puis éprouver la configuration **avant** de
   recharger :

   ```
   sudo php-fpm8.4 -t && sudo systemctl reload php8.4-fpm
   ls -l /run/php/*.sock
   ```

   🔑 **Les quatre sockets doivent exister avant qu'un vhost les nomme.**
   `nginx -t` ne vérifie pas qu'une socket existe : un vhost posé d'abord rendrait
   502, et la panne ressemblerait à un défaut d'application.

3. **Donner à chaque état son propriétaire.** C'est le geste qui ferme réellement
   l'accès ; les précédents ne font que préparer.

   ```
   sudo chown -R php-biself:php-biself   /var/lib/selfrecover
   sudo chown -R php-dataguard:php-dataguard /var/lib/selfdataguard
   sudo chmod 700 /var/lib/selfrecover /var/lib/selfdataguard
   ```

   ⚠️ **`/var/lib/selfjustice` n'est pas dans cette liste, et ce n'est pas un
   oubli** : il est en `755` avec 52 fichiers lisibles par tout compte de la
   machine, et ses sous-répertoires `admin` et `demo-sessions` en `775` (mesuré
   le 24/11/2026). Reprendre ses droits appartient à la conv MCP, à qui le
   constat a été déposé — le faire ici sans savoir ce que ses minuteries
   attendent casserait les synchronisations.

   ✅ **Et un répertoire d'état vit DANS l'arbre servi — il ne change PAS de
   propriétaire.** `demo/selfdataguard/storage` reste à `www-data`, donc
   inaccessible en écriture à ce groupe. Le choix appartenait à la conv Recover,
   qui l'a tranché le 24/11/2026 : mesuré sur l'instance à 17:45, il ne contient
   que `.gitkeep` (`root`, 0 octet), et l'application n'y écrit que par les
   replis de ses essais locaux (`run.sh`). Sur l'instance, les chemins d'état
   lui sont donnés par `fastcgi_param` et vivent tous sous
   `/var/lib/selfdataguard/`.

   🔑 **C'est l'absence de droit qui protège ici.** Si un `fastcgi_param`
   venait à manquer, la démo doit échouer en le nommant — pas se replier en
   écrivant un secret dans l'arbre servi. Lui donner `php-dataguard` rendrait ce
   repli possible, donc silencieux.

4. **Faire suivre les deux minuteries de purge**, sans quoi elles perdent l'accès
   à ce qu'elles purgent :

   ```
   sudo systemctl daemon-reload
   sudo systemctl restart demo-sessions-purge.timer
   ```

   ⚠️ **Leur échec serait MUET.** `Type=oneshot` sous minuteur consigne un code
   non nul sans réveiller personne : la purge tournerait toutes les cinq minutes
   en n'effaçant rien, et les sessions de démonstration s'accumuleraient. Le
   vérifier fait partie de l'étape 7, ce n'est pas une option.

   ⓘ `selfrecover-purger` n'est **pas installée** sur cette machine, et c'est
   l'état attendu : son module n'est pas posé sous `/opt/selfrecover/`. Son
   gabarit est à jour pour le jour où il le sera.

5. **Poser les trois vhosts** (`bi-self`, `dataguard`, `justice`), puis :

   ```
   sudo nginx -t && sudo systemctl reload nginx
   ```

   ⚠️ **Un `reload` suffit ici** — aucune clé de zone `limit_req` ne change. Si
   un jour l'une d'elles change, il faut un `restart` : un `reload` garderait
   l'ancienne clé sans le dire.

6. **Poser le groupe résiduel en DERNIER**, et seulement maintenant :

   ```
   sudo php-fpm8.4 -t && sudo systemctl reload php8.4-fpm
   ```

   🔴 **Son plafond est descendu de 12 à 5 ouvriers** parce qu'il ne sert plus
   que trois sites. Posé avant l'étape 5, il laisserait les **huit** se partager
   ce plafond réduit — une dégradation franche, sur la production.

## Mesurer, et c'est la seule étape qui prouve quelque chose

7. Ces mesures-là ne peuvent être faites qu'après la pose, et aucune n'est
   facultative :

   ```
   POOL_SSH_HOST=<alias> bash deploy/my-self/tests/cloisonnement-pools.sh
   POOL_SSH_HOST=<alias> bash deploy/my-self/tests/ecart-pool-php.sh
   POOL_SSH_HOST=<alias> bash deploy/my-self/tests/pic-ouvrier-php.sh
   ```

   Puis, sur la machine :

   - **l'identité réelle des ouvriers** — cinq groupes, quatre identités :
     `ps -o user=,cmd= -C php-fpm8.4 | sort -u` ne doit plus montrer `www-data`
     que pour le résiduel ;
   - **le propriétaire et le mode de chaque socket** : `ls -l /run/php/*.sock`,
     cinq sockets en `srw-rw---- www-data www-data` ;
   - **les six sites répondent** : `bash deploy/my-self/deploy.sh verifier` ;
   - 🔑 **et la seule mesure qui établit le cloisonnement lui-même** — qu'un
     groupe ne peut PAS lire l'état d'un autre :

     ```
     sudo -u php-justice cat /var/lib/selfdataguard/demo.sqlite   # doit REFUSER
     sudo -u php-dataguard ls /var/lib/selfrecover/               # doit REFUSER
     sudo -u php-dataguard ls /var/lib/selfdataguard/             # doit RÉUSSIR
     ```

     ⚠️ **Les trois ensemble, pas les deux premières seules.** Un refus peut
     venir d'un fichier absent autant que d'un droit fermé : la troisième
     commande est le témoin qui distingue « c'est fermé » de « il n'y a rien ».

   - **la purge efface encore** : déposer un fichier daté de plus de 30 minutes
     dans `/var/lib/selfdataguard/sessions`, attendre un cycle, vérifier qu'il a
     disparu. Sans ce geste, l'échec muet de l'étape 4 reste invisible.

## Retour arrière

En ordre inverse : reposer `www.conf` dans sa version d'avant (plafond 12,
`pm = dynamic`, la variable d'environnement rétablie), reposer les trois vhosts
d'avant, recharger nginx et PHP-FPM, puis `chown -R www-data:www-data` sur les
répertoires d'état. Les quatre groupes neufs peuvent rester en place sans rien
servir, et les trois utilisateurs n'ont pas à être supprimés.

⚠️ **Inverser cet ordre coupe le service** : rendre l'état à `www-data` avant de
reposer l'ancien `www.conf` laisse les quatre groupes neufs servir un état qu'ils
ne peuvent plus lire.

## Ce que la pose ne change pas

- **Le code servi** : `deploy.sh` est le seul à le poser, et il n'est pas touché.
- **Le durcissement PHP** : il vient du `php.ini` de la machine, pas des groupes
  d'ouvriers. Un groupe neuf en hérite et n'a rien à reporter (mesuré le
  19/11/2026).
- **Sept directives `fastcgi_pass` servies restent sur le groupe résiduel**, et
  c'est cohérent : trois vhosts de l'instance — le vhost par défaut et deux
  sites secondaires — **n'ont aucun gabarit dans ce dépôt**, soit 18 directives
  mesurées sur la machine contre 11 versionnées, le 24/11/2026. Leur entrée dans
  le dépôt est un chantier à part, que personne ne porte aujourd'hui.
- **Les trois minuteries de SelfJustice** (`jade-update`, `judilibre-update`,
  `fraicheur-check`) tournent sous `root` avec `ReadWritePaths=/var/lib/selfjustice` :
  elles ne sont pas gênées par ce découpage. Vérifié, pas supposé.
