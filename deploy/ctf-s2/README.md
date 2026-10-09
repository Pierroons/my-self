# Configuration de service du lab CTF (Saison 2)

Ce que nginx sert sur l'instance publique du lab, versionné parce qu'il
n'existait qu'à un exemplaire : sur la machine. Perdre le serveur, c'était
réécrire tout cela de mémoire.

## Où va quoi

| fichier | destination sur l'hôte |
|---|---|
| `nginx-ctf.conf` | `/etc/nginx/sites-available/lab` (lien dans `sites-enabled/`) |
| `snippets/lab-deny-backups.conf` | `/etc/nginx/snippets/` |
| `snippets/lab-entetes-statiques.conf` | `/etc/nginx/snippets/` |

🔑 **Le gabarit déclare lui-même son contexte `http`** — la `map`, le
`log_format` et la zone `limit_req`, en tête de fichier, comme les gabarits de
`bi-self` et de `selfjustice`. Sortis dans un `conf.d/` à part, ils rendaient le
vhost intestable (`unknown log format`, mesuré en intégration le 04/10) et le
feraient refuser de démarrer sur une machine qui ne les porte pas déjà.

## Ce que chacun règle

- **`nginx-ctf.conf`** — tout le vhost, contexte `http` compris :
  - la `map` qui **tronque l'adresse source** (`/24` et `/48`) avant de l'écrire.
    Ce serveur est fait pour être attaqué : ses journaux sont ce que quelqu'un
    lira s'il obtient un accès, et ils ne doivent désigner personne ;
  - les trois zones de lissage — 10 req/s sur l'authentification, et **2 req/s en
    file** (sans `nodelay`) devant les portes du niveau 3 et les consoles
    d'arbitrage, qui écrivent en base ; plus un `429` au lieu du
    `503` par défaut, qu'un chercheur rapporterait comme un déni de service
    qu'il aurait provoqué ;
  - puis la racine, le routage PHP et l'inclusion des deux snippets.
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
