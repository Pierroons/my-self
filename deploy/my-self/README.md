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

## 📌 Où en est la pose — 25 novembre 2026, 06:34

Le découpage est posé **sur un site des trois**, et ce n'est pas un abandon : les deux autres
dépendent d'un répertoire qui n'appartient pas à ce lot.

| site | état | ce qui l'établit |
|---|---|---|
| **SelfDataGuard** | ✅ **posé et cloisonné** | `blindkey.bin` lisible par `php-dataguard` · `www-data` ne lit plus `demo.sqlite` · purge éprouvée par un fichier témoin de 40 min, effacé · `api/inspect_db.php` rend 200 avec sa base |
| **bi-self** | ⏸ ses deux groupes sont posés mais **inertes** — aucun vhost ne les nomme | ses sessions vivent chez SelfJustice, voir ci-dessous |
| **SelfJustice** | ⏸ **bloqué** | son état est écrit par `www-data` en permanence |
| `www.conf` | ⏸ **non posé** | il retire la clé d'API que SelfJustice emploie encore |

🔑 **Ce qui est acquis, et c'était la raison du chantier** : une exécution de code dans l'un des sept
autres sites PHP n'atteint plus l'état de SelfDataGuard — ni sa base, ni sa clé d'index, ni ses
sessions. C'est la condition de déploiement du poivre de la 0.10.0.

### 🔴 Pourquoi bi-self et SelfJustice sont bloqués, et par la même racine

`/var/lib/selfjustice` est en `755 www-data` et **l'application y écrit en continu** — mesuré le
25/11/2026 : `counter.txt` à 06:00, `admin/` à 06:08, `db/` à 02:41, et `feedback/` en **`750`**,
donc illisible par tout autre compte. Un groupe d'ouvriers sous sa propre identité ne peut donc ni
écrire cet état, ni même le lire en entier.

Et bi-self en dépend aussi, ce que rien dans ce lot n'avait vu :
`lib/session_manager.php` déclare `BASE_DIR_DEFAUT = '/var/lib/selfjustice/demo-sessions'`, et
`SELFRECOVER_SESSIONS_DIR` **n'est pas déclarée** par le groupe en service — le défaut s'applique
donc. Les sessions de la démo bi-self, sa liste `.banned_ips` et ses `.counters/` vivent dans l'état
de SelfJustice, en `775 www-data`.

**Les deux sorties possibles**, et aucune n'appartient à ce lot seul :

1. **Donner à bi-self son propre répertoire de sessions** — `SELFRECOVER_SESSIONS_DIR` vers
   `/var/lib/selfrecover/demo-sessions`, possédé par `php-biself`. C'est le geste propre : après lui,
   bi-self ne touche plus du tout à `/var/lib/selfjustice`. Prix : copier `.banned_ips` et
   `.counters/`, et les sessions en cours tombent (TTL 30 min). **Décision du module, conv Recover.**
2. **Reprendre le propriétaire de `/var/lib/selfjustice`** vers `php-justice`. Les trois minuteries de
   synchronisation n'en souffriraient pas — elles tournent en `root` avec
   `ReadWritePaths=/var/lib/selfjustice`, et root ignore le propriétaire. **Décision de la conv MCP**,
   à qui le constat est déposé (`PP-2511-0612`).

⛔ **Ce qu'il ne faut PAS faire** : ajouter `php-biself` ou `php-justice` au groupe propriétaire. Un
groupe partagé est exactement ce que ce découpage supprime, et 74 répertoires de l'arbre servi sont
inscriptibles par `www-data`.

ⓘ **Les deux groupes de bi-self restent posés et vides** : 4 ouvriers au repos, environ 116 Mio sur
5 485 disponibles (mesuré le 25/11). Les retirer puis les reposer coûterait plus que de les laisser.

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

   🔴 **Un groupe neuf n'hérite d'AUCUNE variable d'environnement, et c'est le
   code servi qui dit lesquelles il lui faut — pas le groupe `www`.** `clear_env`
   vaut `yes` par défaut : l'environnement est vidé avant que les `env[...]` du
   groupe soient posés. La liste se mesure donc **sur le code**, pas sur la
   configuration :

   ```
   grep -rnoE "getenv\(.[A-Z_]+.\)|\$_ENV\[.[A-Z_]+.\]" <racine servie du site>
   ```

   ⚠️ Puis, pour chaque nom rendu, lire **le défaut que le code applique quand la
   variable manque** : une variable absente ne fait pas échouer PHP, elle le fait
   basculer sur une valeur en dur, souvent un chemin. C'est ce défaut-là qui
   décide si le groupe neuf peut travailler. Mesuré le 25/11/2026 sur bi-self :
   trois `getenv` dans `lib/session_manager.php`, dont **une seule compte** —
   les deux autres (`SELFRECOVER_COOKIE_DOMAIN`, `SELFRECOVER_COOKIE_INSECURE`)
   ont des défauts justes en production.

   ⓘ Pour relever les noms déclarés par le groupe en service **sans lire le
   fichier en entier** — son en-tête dit pourquoi : `sudo grep -oE
   '^env\[[A-Z_]+\]' /etc/php/8.4/fpm/pool.d/www.conf` rend les noms seuls,
   jamais les valeurs.

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

4. **Faire suivre les deux minuteries de purge — dans le MÊME geste que
   l'étape 3, et non après.** 🔴 Corrigé le 25/11/2026 : numérotées à la suite,
   ces deux étapes laissent une fenêtre où le répertoire a changé de
   propriétaire et où la purge tourne encore sous l'ancien compte. Elle tire
   toutes les cinq minutes et son échec est **muet** — la fenêtre n'a pas besoin
   d'être longue pour être traversée. Les deux gestes tiennent dans une seule
   commande.

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

5. 🔴 **NE PAS poser les trois gabarits de vhost. Réécrire les seules lignes
   `fastcgi_pass` des vhosts servis.** Mesuré le 25/11/2026 avant la pose, et
   c'est ce qui a arrêté la moitié de la manœuvre : les gabarits ne sont pas des
   remplaçants des fichiers en service, pour trois raisons indépendantes.

   - Ils portent des **valeurs à substituer** — `dataguard.example.org`,
     `justice.example.org`, `your-instance.example`. Posés tels quels, le
     `server_name` et les chemins de certificat de la production tombent. Aucun
     déployeur ne les substitue : `deploy/my-self/deploy.sh` ne fait que **lire**
     `sites-enabled` (fonction `references_serveur`), il n'y écrit jamais.
   - Ils **déclarent des zones `limit_req_zone`** que l'instance déclare déjà
     dans `conf.d/`, et **nginx refuse une zone déclarée deux fois**. Leurs
     propres commentaires le disent : « une instance qui les déclare déjà
     ailleurs retire ces lignes ».
   - Celui de `bi-self` fait **14 781 octets contre 3 798 servis**, soit 281
     lignes d'écart : il porte le lot des freins, dont un `map $cle_frein` qui
     **change la clé de zones existantes** — ce qui exige un `restart` de nginx,
     pas un `reload`. Le poser ici déploierait un lot que personne n'a demandé.

   Or le découpage n'a besoin que de **11 lignes** sur les 18 servies. Pour un
   fichier qui n'a qu'une occurrence, la substitution globale suffit ; pour
   `bi-self`, la route du flux se distingue **par son bloc**, jamais par son
   numéro de ligne :

   ```
   sudo sed -i "s|unix:/run/php/php8.4-fpm.sock|unix:/run/php/php8.4-fpm-<pool>.sock|" <vhost>
   sudo sed -i "/location = \/demo\/api\/events/,/^    }/ s|-biself.sock|-flux.sock|" <vhost de bi-self>
   sudo nginx -t && sudo systemctl reload nginx
   ```

   🔴 **La sauvegarde du vhost ne reste PAS dans `sites-enabled/`.** nginx y
   inclut `*` **sans regarder l'extension** — une copie `.avant-…` y serait
   chargée en double, et le `server_name` dupliqué ferait échouer le
   rechargement. Elle va dans un dossier à part.

   ⚠️ **Un `reload` suffit pour ce changement-ci** — aucune clé de zone ne bouge.
   Il ne suffirait pas pour le lot des freins, qui est l'objet du point
   précédent.

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
