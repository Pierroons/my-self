# `deploy/bi-self` — ce qui se pose, dans quel ordre, et ce qui le prouve

| fichier | destination | droits |
|---|---|---|
| `php-fpm-flux.conf` | `/etc/php/8.4/fpm/pool.d/biself-flux.conf` — **à poser avant le vhost** | `644 root:root` |
| `nginx-bi-self.conf` | `/etc/nginx/sites-available/` (lien dans `sites-enabled/`) | `644 root:root` |
| `selfrecover-purger.{service,timer}` | `/etc/systemd/system/` | `644 root:root` |
| `../my-self/snippets/{deny-bakfiles,selfrecover-security-headers,security-headers-base}.conf` | `/etc/nginx/snippets/` | `644 root:root` |

⚠️ **Les trois fragments ne sont pas optionnels** : le vhost les inclut, et nginx
refuse de démarrer sur un `include` qui manque. Celui des en-têtes en inclut un
second (le socle), d'où trois fichiers pour deux `include`.

Le vhost porte des marques dépersonnalisées (`your-instance.example`) : elles se
substituent à la pose. `scripts/check-vhost.sh` les substitue pour valider le
gabarit, et il sait tourner par SSH sur un hôte qui a nginx
(`VHOST_TEST_HOST=<alias>`).

## L'ordre, et pourquoi il n'est pas indifférent

1. **Lire ce que le groupe `www` de la machine règle en plus** :

   ```
   sudo grep -vE '^[[:space:]]*(;|$|\[|listen|pm[. =])' /etc/php/8.4/fpm/pool.d/www.conf
   ```

   **Reporter dans `php-fpm-flux.conf` les lignes dont CE flux a besoin** :
   `clear_env`, `env[...]`, `php_admin_value[...]`, `php_value[...]`,
   `security.limit_extensions`, et `user`/`group` s'ils diffèrent.

   🔑 **Reporter « tout par prudence » est une faute, pas une précaution.** Un
   groupe d'ouvriers partage ses ouvriers, mais aussi ses **secrets** : toute
   application servie par un pool peut lire ses `env[...]` par `getenv()`.
   Mesuré le 19/11/2026 sur la machine de production : son groupe partagé
   déclarait quatre `env[...]`, dont **la clé d'API d'un service amont qui ne
   concerne pas cette démonstration** — et `events.php` n'en lit aucune des
   quatre. Un pool neuf qui ne porte pas un secret est un pool qui ne peut pas
   le fuiter.

   ⚠️ **Demander les NOMS avant les valeurs.** La commande ci-dessus affiche les
   valeurs, donc les secrets. Celle-ci donne les noms seuls, et suffit à décider
   quoi reporter :

   ```
   sudo grep -oE '^[[:space:]]*(env\[[A-Z_]+\]|clear_env|php_admin_value\[[a-z_.]+\]|php_value\[[a-z_.]+\]|security\.limit_extensions|user|group)' /etc/php/8.4/fpm/pool.d/www.conf | sort -u
   ```

   ⚠️ Ce fichier n'est **pas lisible** par un compte sans privilège : la lecture
   demande `sudo`, donc un terminal.

2. **Poser le groupe**, puis `php-fpm8.4 -t`, `systemctl reload php8.4-fpm`, et
   vérifier que `/run/php/php8.4-fpm-flux.sock` existe. Comparer ensuite les
   groupes dans `php-fpm8.4 -tt` : mêmes `env`, mêmes `php_admin_value`, même
   `clear_env`.

3. **Poser le vhost.** ⚠️ **Par un `restart`, pas un `reload`** : les zones
   `biself` et `biself_recover` ont changé de clé (`$binary_remote_addr` →
   `$cle_frein`), et nginx garde en mémoire une zone dont la clé change **sans
   rien dire**. Un rechargement laisserait donc les anciens compteurs en place
   et le changement ne s'appliquerait jamais. Compter une seconde de coupure, et
   des compteurs de freins qui repartent de zéro.

4. **Mesurer, et c'est la seule étape qui prouve quelque chose** :
   - un flux `/demo/api/events` s'ouvre et reçoit ses événements — c'est ce qui
     dit que le groupe neuf tient la même clé et lit la même base que les pages ;
   - un **deuxième** flux simultané depuis le même préfixe rend **429**
     (`limit_conn biself_flux 1`, et le groupe dédié n'a que deux ouvriers : à un
     flux par préfixe, deux préfixes distincts ont chacun le sien) ;
   - une route de `moderate` répond normalement, et une rafale y rend 429 ;
   - **chaque famille de routes sort avec ses en-têtes de sécurité** :

     ```
     BISELF_HOTE=<nom servi> bash deploy/bi-self/tests/entetes-servis.sh
     ```

     🔑 nginx ne fusionne pas les `add_header` : un seul, posé dans un bloc
     `location`, annule pour ce chemin tous ceux du bloc `server`. Chaque bloc du
     vhost peut donc dénuder ses routes, et rien ne le signalerait — la page
     s'affiche, elle sort simplement nue.

**Retour arrière** : le vhost d'abord (`restart`), le groupe d'ouvriers ensuite.
L'inverse laisserait le vhost pointer une socket disparue, donc 502 sur le flux.

## 🔑 Ce que ce lot corrige, et ce qu'il faut savoir avant d'y toucher

Mesuré le 19/11/2026 sur la production : **un seul groupe d'ouvriers PHP**
(`pool.d/www.conf`) servait tous les sites de la machine, et `/demo/api/events`
le partageait avec eux. Or ce flux tient son ouvrier jusqu'à trente minutes
(`text/event-stream`, échéance de la session, `fastcgi_read_timeout 1800s`).
Une poignée de connexions tenues sur cette seule route de démonstration pouvait
donc faire attendre toute la production PHP, pas la démonstration seule.

Trois propriétés en découlent, et chacune se perd facilement :

- **une file au débit ne borne pas une connexion tenue.** Une requête suffit à
  occuper un ouvrier une demi-heure : c'est `limit_conn`, le nombre de
  connexions simultanées, qui mesure cet abus — pas `limit_req` ;
- **les freins comptent par préfixe** (`$cle_frein`, /24 en IPv4, /64 en IPv6).
  Sur `$binary_remote_addr`, un seul fournisseur IPv6 dispose de milliards de
  quotas distincts et le frein ne borne plus rien ;
- **nginx ne retient qu'un bloc `location`.** Celui de `/` et sa zone `biself`
  ne s'appliquent donc à aucune route d'API : chacune doit nommer sa file. Les
  quatre familles qui n'en avaient aucune — `session`, `events`, les neuf de
  `moderate`, `duo/sybil-attack` — ont été relevées en relecture le 18/11/2026
  alors qu'elles étaient en service depuis le 06/10.

⚠️ **Et le gabarit n'est pas ce qui sert.** Mesuré le 03/11 puis le 19/11 : le
vhost en service ne portait **aucune** limite de débit, là où le gabarit en
déclarait deux. Avant de conclure qu'une route est freinée, lire le fichier de
la machine — pas celui du dépôt.

## Ce que la pose ne change pas, et pourquoi il a fallu le vérifier

Le gabarit et le vhost servi avaient divergé dans **les deux sens** (mesuré le
19 puis le 21/11/2026) : le dépôt déclarait des freins que la machine n'avait
pas, et la machine portait des blocs que le dépôt ignorait. Poser le gabarit
d'alors aurait donc **rouvert** une démonstration fermée et remplacé le TLS en
service. Les blocs du servi y sont maintenant repris, et la pose est neutre sur
tout ce qui suit :

- **la démonstration SelfRecover autonome reste fermée** — le 410 sur
  `/selfrecover/`, le 301 de `/selfrecover`, les 410 de `/lite.html` et
  `/comparison.html`, le refus des bases sous ce préfixe ;
- **le TLS reste celui qui tourne** : `listen 443 ssl` et les deux certificats
  hors arborescence certbot. Le gabarit ne pose plus `options-ssl-nginx.conf` ni
  de paramètres Diffie-Hellman — l'instance tient ses protocoles dans son
  `nginx.conf` ;
- **l'écoute reste IPv4 seule**, et il n'y a pas de bloc sur le port 80 : la
  redirection vers HTTPS est portée par le vhost par défaut du serveur, pour
  tous les noms à la fois ;
- **les en-têtes restent ceux du fragment servi**, par `include`. Les cinq
  `add_header` que le gabarit portait en dur étaient à la fois moins (pas de
  HSTS) et plus stricts (pas de script inline) : les poser aurait retiré un
  engagement et cassé les pages de démonstration.

⚠️ Ce qui s'ajoute, en revanche, mord : les quatre files, la limite de
connexions du flux, le groupe d'ouvriers dédié, le refus des URL en `.php` et
celui des fichiers cachés hors `/.well-known`. C'est aussi ce qui demande
l'étape 4 : un frein mal calibré se voit sur le visiteur honnête avant de se
voir sur l'abus.
