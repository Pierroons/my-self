# Configuration de service du lab CTF (Saison 2)

Ce que nginx sert sur l'instance publique du lab, et les scripts qui
l'exploitent, versionnés parce qu'ils n'existaient qu'à un exemplaire : sur la
machine. Perdre le serveur, c'était réécrire tout cela de mémoire.

Une modification se fait ici, puis se pose. Le code est celui qui tourne ; les
seuls écarts avec l'hôte sont les gabarits (section suivante) et des
commentaires réécrits pour être publiés.

## Où va quoi

| fichier | destination sur l'hôte | droits |
|---|---|---|
| `nginx-ctf.conf` | `/etc/nginx/sites-available/lab` (lien dans `sites-enabled/`) | `644 root:root` |
| `snippets/lab-deny-backups.conf` | `/etc/nginx/snippets/` | `644 root:root` |
| `snippets/lab-entetes-statiques.conf` | `/etc/nginx/snippets/` | `644 root:root` |
| `ctf-s2-annonces`, `ctf-s2-anonymiser-journaux`, `ctf-s2-exporter`, `ctf-s2-integrite`, `ctf-s2-sauvegarde`, `ctf-s2-sortie-deposer`, `ctf-s2-veille` | `/usr/local/sbin/` | `750 root:root` |
| `ctf-s2-purge-empreintes` | `/usr/local/sbin/` | `750 root:www-data` — son service tourne en `www-data` |
| `ctf-s2-sortie-lire` | `/usr/local/bin/` — commande forcée de la clé qui rapatrie l'archive | `755 root:root` |
| `00-anonymiser-journaux` | `/etc/logrotate.d/httpd-prerotate/` | `755 root:root` |
| `ctf-s2-*.service`, `ctf-s2-*.timer` | `/etc/systemd/system/` | `644 root:root` |
| `ctf-s2-sauvegarde.cron` | `/etc/cron.d/ctf-s2-sauvegarde` | `644 root:root` |
| `maj-code.sh` | le répertoire personnel du compte d'administration | `700`, à ce compte |
| `deploy-vhost-ctf.sh` | reste sur le poste : il pose `nginx-ctf.conf` à la révision que le lab sert | — |

⚠️ `run-parts` n'exécute que les noms en `[A-Za-z0-9_-]` : renommer
`00-anonymiser-journaux` avec un point le ferait ignorer en silence, et les
journaux partiraient à l'archive sans être anonymisés.

### Cinq gabarits à substituer à la pose

Le dépôt ne publie ni les noms de comptes ni les désignations de l'hôte. Les
copies en service portent les valeurs ; celles-ci portent un gabarit :

| gabarit | ce qu'il désigne | fichiers |
|---|---|---|
| `<compte-sortie>` | le compte, sans privilège, dont la clé rapatrie l'archive | `ctf-s2-sortie-deposer`, `ctf-s2-sortie-lire`, `ctf-s2-sauvegarde` |
| `<compte-admin>` | le compte d'administration du VPS, qui élève par `sudo` | `ctf-s2-sauvegarde` |
| `<nom-de-l-adresse>` | le nom que l'hébergeur attache à l'adresse du lab | `ctf-s2-veille` |
| `<ptr-du-vps>` | le nom que rend la résolution inverse de cette adresse | `ctf-s2-veille` |
| `<compte-site-ferme>` | le compte de l'authentification basique que le vhost exige quand le site est fermé | `maj-code.sh` |

Un gabarit oublié ne fait pas toujours échouer. `<compte-site-ferme>` laissé tel
quel, `maj-code.sh` s'arrête sur le site fermé (401 au lieu de 200). Les deux derniers laissés tels
quels, la veille ne reconnaît plus le serveur sous les noms que l'hébergeur lui
donne : le balayage de masse qui vise ces noms déclenche l'alerte « on cherche
autre chose que le lab ». Après une pose, cette recherche ne doit rien rendre sur l'hôte,
lancée par le compte d'administration :

```sh
sudo grep -nE '<(compte-sortie|compte-admin|nom-de-l-adresse|ptr-du-vps|compte-site-ferme)>' /usr/local/sbin/ctf-s2-* /usr/local/bin/ctf-s2-* ~/maj-code.sh
```

`deploy-vhost-ctf.sh` lit ses deux alias SSH dans `CTF_SSH_LECTURE` (le compte de
lecture, sans privilège) et `CTF_SSH_ADMIN` (le compte qui élève), et s'arrête
si l'un manque.

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
    IPv6, chaque adresse d'un même `/64` a la sienne. Elles bornent un débit, pas un
    volume : rien ne plafonne le nombre de messages qu'un compte envoie ;
  - puis la racine, le routage PHP et l'inclusion des deux snippets.
- **`lab-deny-backups.conf`** — 46 extensions qui ne doivent jamais être
  servies. ⚠️ Ni `txt` ni `asc` : `security.txt` et la clé PGP du canal de
  signalement passent par là. Les refus sont journalisés à part, parce que ce
  qu'un chercheur sonde est exactement ce qu'on veut lire.
- **`lab-entetes-statiques.conf`** — l'application pose ses en-têtes depuis PHP,
  qui ne voit jamais passer un `.js`. Ces fichiers repartaient sans rien, pas
  même HSTS.

## Ce qui n'est PAS ici, et pourquoi

Le côté NAS du rapatriement — le script qui tire l'archive et son unité — vit
avec la configuration du NAS. Les jetons d'alerte et la phrase de passe de
l'export restent sur le serveur, dans les fichiers que les scripts nomment.

Les secrets d'instance (`data/.blindkey`, `.sitesalt`, `.serversecret`, `.modaudit`) ne
quittent jamais le serveur autrement que dans l'archive quotidienne
(`ctf-s2-sauvegarde`), chiffrée par `ctf-s2-exporter` avant de partir sur le NAS. Deux sortes de
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
et sa clé neuve naissent à la première requête du site. Pour compter ce qu'il supprime,
il ouvre la base, et la migration du code en place s'y applique avant le gel : une base
d'un schéma plus ancien n'est pas figée octet pour octet. Sous une autre identité que le
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
refuse de chiffrer et de déchiffrer en nommant la table. ⚠️ Sans sa trace, ou sans
l'empreinte qu'elle porte — perdue, ou retirée par le tirage d'un numéro neuf que le refus
propose —, un numéro réécrit ne se voit plus : tout devient illisible, sans refus nommé.

À chaque mise à jour du code, après la bascule : **poser les témoins d'existence**
pour les secrets présents, avec l'identité qui possède `data/` — celle qui sert le
site ne l'écrit pas forcément, et un témoin manquant se signale au journal du site :

```sh
for s in .blindkey .sitesalt .serversecret .modaudit; do [ -f "data/$s" ] && touch "data/$s.existe"; done
```

Elle se lance depuis `demo/lab/` et vise `data/` : un secret dérouté par sa prise
(`LAB_BLINDKEY_PATH`, `LAB_SITESALT_PATH`…) reçoit son témoin à son propre chemin.
