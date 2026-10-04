# SelfJustice — Admin Watch

Endpoint privé de surveillance des accès `justice.my-self.fr`. Lecture seule, rendu HTML sobre, 24 h rolling window.

## Installation

Le code (`admin/watch.php`) est posé avec l'arbre, comme le reste du module. Trois gestes restent propres à l'instance.

### 1. Le répertoire d'état

```bash
sudo install -d -o www-data -g www-data -m 775 /var/lib/selfjustice/admin
```

### 2. Le jeton

Écrit par `www-data` lui-même, en `0600` dès sa création :

```bash
sudo -u www-data sh -c 'umask 077; openssl rand -hex 16 > /var/lib/selfjustice/admin/token.txt'
echo "https://justice.my-self.fr/w-$(sudo cat /var/lib/selfjustice/admin/token.txt)/"
```

L'adresse se garde dans un gestionnaire de mots de passe.

### 3. Le bloc nginx

Il vit dans le gabarit du vhost, `deploy/selfjustice/nginx.conf` : la zone `selfjustice_admin` et le bloc « Panneau de veille ». Le routage passe par `fastcgi.conf` et un `SCRIPT_FILENAME` absolu, pas par `snippets/fastcgi-php.conf` : son `try_files` cherche un fichier `/w-<jeton>/`, qui n'existe pas, et rend 404 avant d'atteindre PHP.

### 4. Alimenter les logs (cron)

PHP-FPM a `open_basedir` qui ne couvre pas `/var/log/nginx/`. Le script `self-right/selfjustice/tools/admin_feed.sh` recopie le journal dans `/var/lib/selfjustice/admin/`, sans les lignes qui portent le jeton. Une ligne dans `/etc/cron.d/` :

```
*/2 * * * * www-data /opt/selfjustice/bin/admin_feed.sh
```

Sous `www-data`, jamais root : la copie s'écrit dans un répertoire que PHP peut modifier, et root y suivrait un lien symbolique vers n'importe quel fichier de la machine. Le script refuse de tourner sous root.

Le dashboard est à jour dans un délai max de 2 minutes.

## Sécurité

- Token stocké en `0600` chez `www-data` — seul PHP-FPM peut le lire.
- `hash_equals()` pour la comparaison (timing-attack safe).
- Rate limit 10 req/min, burst 5 — une attaque brute force sur le token (2^128 valeurs) est irréaliste.
- Les requêtes vers `/w-…` n'entrent pas dans le journal d'accès (`access_log off`), et les copies que lit le panneau, en `0640`, n'en gardent aucune ligne. CrowdSec, qui lit ce journal, ne voit donc pas les essais de jeton : la limitation de débit est leur seule borne.
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

Relancer les deux commandes du § 2. L'ancien jeton est invalidé immédiatement : 404 à la requête suivante.
