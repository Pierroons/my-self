# Configuration de service du lab CTF (Saison 2)

Ce que nginx sert sur l'instance publique du lab, versionné parce qu'il
n'existait qu'à un exemplaire : sur la machine. Perdre le serveur, c'était
réécrire tout cela de mémoire.

## Où va quoi

| fichier | destination sur l'hôte |
|---|---|
| `nginx-ctf.conf` | `/etc/nginx/sites-available/lab` (lien dans `sites-enabled/`) |
| `conf.d/lab-journaux.conf` | `/etc/nginx/conf.d/` |
| `conf.d/lab-ratelimit.conf` | `/etc/nginx/conf.d/` |
| `snippets/lab-deny-backups.conf` | `/etc/nginx/snippets/` |
| `snippets/lab-entetes-statiques.conf` | `/etc/nginx/snippets/` |

L'ordre compte : les deux fichiers de `conf.d/` déclarent une `map`, un
`log_format` et une zone `limit_req`, qui vivent dans le contexte `http` — le
vhost les référence et ne démarrerait pas sans eux.

## Ce que chacun règle

- **`nginx-ctf.conf`** — le vhost. Racine, routage PHP, lissage des points
  d'entrée coûteux, et l'inclusion des deux snippets.
- **`lab-journaux.conf`** — tronque l'adresse source (`/24` et `/48`) avant de
  l'écrire. Ce serveur est fait pour être attaqué : ses journaux sont ce que
  quelqu'un lira s'il obtient un accès, et ils ne doivent désigner personne.
- **`lab-ratelimit.conf`** — 10 req/s sur l'authentification, et un `429` au
  lieu du `503` par défaut, qu'un chercheur rapporterait comme un déni de
  service qu'il aurait provoqué.
- **`lab-deny-backups.conf`** — 46 extensions qui ne doivent jamais être
  servies. ⚠️ Ni `txt` ni `asc` : `security.txt` et la clé PGP du canal de
  signalement passent par là. Les refus sont journalisés à part, parce que ce
  qu'un chercheur sonde est exactement ce qu'on veut lire.
- **`lab-entetes-statiques.conf`** — l'application pose ses en-têtes depuis PHP,
  qui ne voit jamais passer un `.js`. Ces fichiers repartaient sans rien, pas
  même HSTS.

## Ce qui n'est PAS ici, et pourquoi

Les scripts d'exploitation — déploiement du code, sauvegarde quotidienne,
export chiffré vers le NAS — suivent la règle d'`AGENTS.md` : le déployeur vit
hors dépôt. Ils sont en revanche **repris dans l'archive quotidienne**, qui part
chiffrée sur le NAS ; c'est leur seule copie hors machine.

Les secrets d'instance (`data/.blindkey`, `.sitesalt`, `.serversecret`) ne
quittent jamais le serveur autrement que dans cette archive chiffrée.
