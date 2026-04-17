# SelfJustice — Admin Watch

Endpoint privé de surveillance des accès `justice.my-self.fr`. Lecture seule, rendu HTML sobre, 24 h rolling window.

## Installation

### 1. Déployer le code

```bash
sudo cp admin/watch.php /var/www/selfjustice/admin/watch.php
sudo chown www-data:www-data /var/www/selfjustice/admin/watch.php
sudo chmod 644 /var/www/selfjustice/admin/watch.php

sudo mkdir -p /var/lib/selfjustice/admin
sudo chown zelda:www-data /var/lib/selfjustice/admin
sudo chmod 775 /var/lib/selfjustice/admin
```

### 2. Générer et stocker le token

```bash
TOKEN=$(openssl rand -hex 16)
echo "$TOKEN" | sudo tee /var/lib/selfjustice/admin/token.txt > /dev/null
sudo chown www-data:www-data /var/lib/selfjustice/admin/token.txt
sudo chmod 600 /var/lib/selfjustice/admin/token.txt
echo "URL d'accès : https://justice.my-self.fr/w-$TOKEN/"
```

Le token doit être conservé en lieu sûr (gestionnaire de mots de passe, Proton Pass, etc.). Pour le régénérer, relancer cette commande — l'ancien token est invalidé.

### 3. Configurer nginx

Le vhost versionné (`migration/nginx-selfjustice.conf`) contient déjà la location block. Si elle n'est pas encore en place :

```nginx
limit_req_zone $binary_remote_addr zone=selfjustice_admin:10m rate=10r/m;

server {
    # ... existant ...

    location ~ "^/w-([a-f0-9]{32})/?$" {
        limit_req zone=selfjustice_admin burst=5 nodelay;
        set $sj_token $1;
        include fastcgi.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /var/www/selfjustice/admin/watch.php;
        fastcgi_param SCRIPT_NAME /admin/watch.php;
        fastcgi_param SJ_PROVIDED_TOKEN $sj_token;
    }
}
```

**Important** : ne pas utiliser `snippets/fastcgi-php.conf` qui contient un `try_files` basé sur l'URI — l'URI `/w-<token>/` n'existe pas comme fichier et la requête serait 404 avant d'atteindre PHP. Le vhost utilise `fastcgi.conf` directement avec un `SCRIPT_FILENAME` absolu.

```bash
sudo nginx -t && sudo systemctl reload nginx
```

### 4. Alimenter les logs (cron)

PHP-FPM a `open_basedir` qui ne couvre pas `/var/log/nginx/`. Le script `admin_feed.sh` duplique les logs dans `/var/lib/selfjustice/admin/access.log` (path autorisé). À installer via cron :

```bash
cp tools/admin_feed.sh /home/zelda/legi/admin_feed.sh
chmod +x /home/zelda/legi/admin_feed.sh
(crontab -l 2>/dev/null; echo "*/2 * * * * /home/zelda/legi/admin_feed.sh") | crontab -
```

Le dashboard est désormais à jour dans un délai max de 2 minutes.

## Sécurité

- Token stocké en `0600` chez `www-data` — seul PHP-FPM peut le lire.
- `hash_equals()` pour la comparaison (timing-attack safe).
- Rate limit 10 req/min, burst 5 — une attaque brute force sur le token (2^128 valeurs) est irréaliste.
- CrowdSec (déjà en place sur le serveur) observe les 404 sur `/w-*` et peut bannir une IP qui teste trop de tokens.
- Page servie avec `X-Robots-Tag: noindex, nofollow` et `Cache-Control: no-store` — rien n'est indexé ni cachée.
- Zéro log métier côté serveur : le dashboard est calculé à la volée à chaque requête, pas stocké.

## Utilisation

Ouvrir l'URL `https://justice.my-self.fr/w-<TOKEN>/` dans un navigateur.

Le dashboard affiche sur une fenêtre glissante de 24 h :
- KPI : total requêtes, IPs uniques, UA uniques, 4xx, 5xx, tentatives d'intrusion
- Top 15 IPs, User-Agents, endpoints consultés
- IA détectées (Claude-User, ChatGPT, Perplexity, crawlers, etc.)
- 20 tentatives d'intrusion récentes (scans de paths sensibles)
- 20 erreurs 5xx récentes

Rafraîchi à chaque chargement de la page.

## Révocation / rotation du token

Si le token est compromis, régénérer :

```bash
TOKEN=$(openssl rand -hex 16)
echo "$TOKEN" | sudo tee /var/lib/selfjustice/admin/token.txt > /dev/null
echo "Nouvelle URL : https://justice.my-self.fr/w-$TOKEN/"
```

L'ancien token est immédiatement invalidé (404 à la prochaine requête).
