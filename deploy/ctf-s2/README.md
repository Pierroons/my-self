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
  - les quatre zones de lissage — 10 req/s sur l'authentification, et **2 req/s en
    file** (sans `nodelay`) devant les portes du niveau 3 et les consoles
    d'arbitrage, qui écrivent en base, et devant les routes qui dérivent une clé ;
    plus un `429` au lieu du `503` par défaut, qu'un chercheur rapporterait comme un
    déni de service qu'il aurait provoqué. ⚠️ Les files comptent par adresse : en
    IPv6, chaque adresse d'un même `/64` a la sienne ;
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

Les secrets d'instance (`data/.blindkey`, `.sitesalt`, `.serversecret`, `.modaudit`) ne
quittent jamais le serveur autrement que dans cette archive chiffrée. Deux sortes de
témoins vivent à côté d'eux et voyagent avec eux : `<secret>.existe`, qui empêche un
secret disparu d'être remplacé en silence, et `.blindkey.scelle`, qui ferme la lecture
des blobs sans contexte. ⚠️ Une restauration qui perdrait `.blindkey.scelle`
rouvrirait cette lecture : l'archive doit le reprendre, et sa présence se vérifie
après restauration.

**La clé naît à la première requête**, scellée. Posée à la main avant elle, elle
bloque l'instance : le site refuse de créer une base sous une clé qui a pu servir.
Posée après, elle ne naît pas scellée, et la passe de migration pose le témoin.

**Une base antérieure à la liaison des chiffrés** passe par
`demo/lab/tools/migrer-aad.php`, qui relie les blobs déjà écrits à leur contexte. Elle
vérifie que sa clé est celle de la base, refuse les blobs qu'elle trouve recopiés —
les sceller risquerait de les certifier pour qui les a déplacés —, et ne s'annonce
finie que lorsqu'il n'en reste aucun de nu : elle pose alors `.blindkey.scelle`.
Jusque-là, un blob ancien se lit encore sans contexte. Une base restaurée d'avant la
passe se relit en retirant ce témoin, puis en relançant la passe.

**La console porte les prises du site** (`LAB_DB_PATH`, `LAB_BLINDKEY_PATH`, et les
autres chemins de secrets) : sans elles, elle ne voit ni la même base ni la même clé.
`selfrecover-su reset-db` vérifie qu'il voit la base, et que la clé qu'il voit ouvre un
de ses chiffrés ; il refuse sinon, sans rien figer. Il fige ensuite `.blindkey` et ses
deux témoins avec la base, sous le même horodatage, et ne recrée rien : la base neuve
et sa clé neuve naissent à la première requête du site. Sous une autre identité que le
site, la console ne lit pas `.blindkey`, qui naît en `0600`, et `reset-db` le dit.

`reset-db --confirm --forcer` fige sans vérifier : quand la base est perdue ou vidée —
le site refuse alors d'en recréer une sous la clé qui a servi —, ou quand la
vérification échoue sur une base abîmée ou dont le numéro a été réécrit. Ces cas ont la
forme d'une console mal prise, et le code ne peut pas les départager : c'est une
décision d'opérateur. Restaurer une base figée, c'est restaurer aussi sa clé figée,
sous les noms d'origine.
Le numéro de la base (table `lab_generation`) se restaure avec elle : perdu, il n'est pas
remplacé tant que la table, la trace de son tirage ou des blobs scellés en dépendent ;
réécrit, il ne correspond plus à l'empreinte de sa trace. Dans les deux cas, le site
refuse de chiffrer et de déchiffrer en nommant la table.

À chaque mise à jour du code, après la bascule : **poser les témoins d'existence**
pour les secrets présents, avec l'identité qui possède `data/` — celle qui sert le
site ne l'écrit pas forcément, et un témoin manquant se signale au journal du site :

```
for s in .blindkey .sitesalt .serversecret .modaudit; do [ -f "data/$s" ] && touch "data/$s.existe"; done
```
