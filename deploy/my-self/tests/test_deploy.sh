#!/bin/bash
# Garde-fou — ce que le déploiement de l'arbre my-self emporte, et sous quel nom.
#
# 🔑 Ce script existe parce que le précédent garde-fou de déploiement, celui du
# module SelfJustice, était vert sur un défaut vivant. Il déployait vers
# `justice.example.org` — c'est-à-dire exactement la chaîne que les pages
# sources contiennent — si bien qu'une substitution réussie et une substitution
# qui n'avait rien fait rendaient le même fichier. Aucun de ses six cas ne
# pouvait les distinguer, et le gabarit cherché a divergé de celui des sources
# sans qu'un seul test rougisse.
#
# D'où deux partis pris ici : le faux dépôt porte des gabarits qu'on choisit, et
# les domaines visés sont en `.invalid` (RFC 2606), qu'aucune source ne peut
# nommer par hasard.
#
# Usage : bash deploy/my-self/tests/test_deploy.sh
# Sortie : 0 si toutes les propriétés tiennent. Leur nombre se compte à
#          l'exécution — l'écrire ici le ferait mentir au prochain ajout.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
SCRIPT="$ICI/../deploy.sh"
[ -r "$SCRIPT" ] || { echo "deploy.sh introuvable : $SCRIPT" >&2; exit 1; }

BAC=$(mktemp -d)
trap 'rm -rf "$BAC"' EXIT

# 🔑 Le total se compte, il ne s'écrit pas. Il était en dur — « 10/10 » — à deux
# endroits, et il y est resté quand deux cas se sont ajoutés le 22/08/2026 : le
# banc annonçait dix propriétés en en éprouvant douze. Un chiffre recopié à côté
# de ce qu'il décrit finit toujours par le démentir.
echecs=0 reussites=0
ok()  { echo "  ✓ $1"; reussites=$((reussites + 1)); }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

# ── Un faux dépôt, un faux serveur ──────────────────────────────────────────
#
# La table du script vise de vrais domaines ; on la remplace ici par une table
# en `.invalid`, et le faux dépôt porte les gabarits correspondants.
monter() { # monter  → reconstruit un dépôt neuf dans $BAC/depot
    rm -rf "$BAC/depot" "$BAC/dist" "$BAC/www" "$BAC/vhosts"
    mkdir -p "$BAC/depot/deploy/my-self" "$BAC/depot/web/my-self.fr" \
             "$BAC/depot/self-right/selfjustice/site" \
             "$BAC/depot/self-right/selfjustice/api/act" \
             "$BAC/depot/self-right/selfjustice/tests" \
             "$BAC/depot/tools" "$BAC/depot/demo/selfdataguard/storage" \
             "$BAC/www" "$BAC/vhosts"

    # 🔑 On renomme le DOMAINE VISÉ, jamais la correspondance. Une première
    # version réécrivait chaque ligne de la table en entier — elle réinstallait
    # donc l'exception `farm → selffarm` au moment même où le cas cherchait à
    # savoir si le script la portait, et restait verte quand on l'effaçait du
    # script. Le banc doit renommer la cible, pas rétablir la règle.
    sed 's|\.my-self\.fr|.test.invalid|g' "$SCRIPT" > "$BAC/depot/deploy/my-self/deploy.sh"

    cat > "$BAC/depot/web/my-self.fr/index.html" <<'HTML'
<a href="https://justice.example.org">Justice</a>
<a href="https://lab.example.org">Lab</a>
<a href="https://farm.example.org">Farm</a>
HTML
    echo "<p>site</p>" > "$BAC/depot/self-right/selfjustice/site/index.php"
    echo "# directives" > "$BAC/depot/self-right/selfjustice/api/act/directives.md"
    echo "<?php \$h = \$_SERVER['HTTP_HOST'] ?? 'your-instance.example';" \
        > "$BAC/depot/self-right/selfjustice/api/act/find.php"
    echo "test" > "$BAC/depot/self-right/selfjustice/tests/sanity.php"
    echo "outil" > "$BAC/depot/tools/build.sh"
    echo "# lisez-moi" > "$BAC/depot/README.md"
    # Le répertoire d'état doit exister à la destination sans que son contenu
    # parte : seul le témoin de présence est gardé.
    touch "$BAC/depot/demo/selfdataguard/storage/.gitkeep"

    # Les secrets du lab et les réponses de son CTF. Aucun n'est suivi par git,
    # et c'est exactement pourquoi ils doivent être posés ici : `.gitignore` ne
    # retient pas un `rsync`, donc le banc doit reproduire un disque, pas un
    # dépôt. Sans ces cinq fichiers, le cas 5 ter mesurerait le vide.
    mkdir -p "$BAC/depot/demo/lab/data" "$BAC/depot/demo/lab/challenge"
    echo "sel-de-l-instance"  > "$BAC/depot/demo/lab/data/.sitesalt"
    echo "cle-de-chiffrement" > "$BAC/depot/demo/lab/data/.blindkey"
    echo "secret-serveur"     > "$BAC/depot/demo/lab/data/.serversecret"
    echo "FLAG{reponses}"     > "$BAC/depot/demo/lab/challenge/flags.txt"
    echo "<?php // outillage" > "$BAC/depot/demo/lab/challenge/prepare.php"
    echo "<?php // le lab"    > "$BAC/depot/demo/lab/index.php"
    echo "donnée vivante de l'instance" > "$BAC/depot/demo/selfdataguard/storage/demo.sqlite"

    cat > "$BAC/vhosts/faux.conf" <<VHOST
server {
    root  $BAC/www/web/my-self.fr;
    location = /act/api/directives.md {
        alias $BAC/www/self-right/selfjustice/api/act/directives.md;
    }
    location ~ ^/act/api/(find)\.php\$ {
        fastcgi_param SCRIPT_FILENAME $BAC/www/self-right/selfjustice/api/act/\$1.php;
    }
}
VHOST
}

lancer() { MYSELF_VHOSTS="$BAC/vhosts" MYSELF_IMMUTABLE=/nexiste/pas \
           bash "$BAC/depot/deploy/my-self/deploy.sh" "$@" 2>&1; }

# ── 1 — les gabarits connus sont substitués, l'exception comprise ────────────
echo "▸ Les noms de domaine"
monter
sortie=$(lancer assembler --dest "$BAC/dist")
code=$?
page="$BAC/dist/web/my-self.fr/index.html"
if [ "$code" -eq 0 ] && [ -f "$page" ] \
   && grep -q "justice.test.invalid" "$page" && ! grep -q "example.org" "$page"; then
    ok "gabarits connus substitués, aucun survivant"
else
    nok "assemblage code $code — ${sortie//$'\n'/ }"
fi

# 🔑 L'exception : farm.example.org ne devient pas farm.*, mais selffarm.*.
# Une règle de suffixe mécanique poserait un lien vers un domaine sans vhost.
if grep -q "selffarm.test.invalid" "$page" 2>/dev/null && ! grep -qE "(^|[^f])farm\.test\.invalid" "$page"; then
    ok "farm.example.org → selffarm.test.invalid, et pas farm.test.invalid"
else
    nok "l'exception de la table n'est pas appliquée : $(grep -o '[a-z.-]*\.test\.invalid' "$page" 2>/dev/null | paste -sd' ')"
fi

# ── 2 — un gabarit sans règle arrête tout ───────────────────────────────────
echo
echo "▸ Un gabarit qu'aucune règle ne nomme"
monter
echo '<a href="https://nouveau-module.example.org">neuf</a>' >> "$BAC/depot/web/my-self.fr/index.html"
sortie=$(lancer assembler --dest "$BAC/dist"); code=$?
# ⚠️ Le refus doit venir de la TABLE, pas d'un filet plus loin. Sans nommer le
# motif, ce cas restait vert quand on retirait le contrôle : le gabarit
# survivait à la substitution et se faisait attraper par le contrôle suivant.
# Un cas qui ne sait pas quelle protection a joué ne peut pas dire si elle est
# encore là.
# Le discriminant est structurel, pas verbal : si le contrôle de la table a
# refusé, la substitution n'a jamais tourné, donc le contrôle des gabarits
# survivants n'a rien pu dire. Sa présence dans la sortie prouverait que c'est
# lui qui a rattrapé le coup — une seconde version de ce cas s'était contentée
# de chercher le mot « table », que la ligne d'annonce contient de toute façon.
if [ "$code" -ne 0 ] && [ ! -d "$BAC/dist" ] \
   && grep -q "sans règle" <<<"$sortie" && ! grep -q "survivant" <<<"$sortie"; then
    ok "gabarit inconnu → refus par la table (code $code), avant toute substitution"
else
    nok "gabarit inconnu → code $code : refusé par un filet plus loin, pas par la table"
fi

# ── 2 bis — aucune substitution du tout ─────────────────────────────────────
echo
echo "▸ Des pages qui ne nomment aucune instance"
# 🔑 C'est le défaut mesuré le 21/08 sur le module SelfJustice : le gabarit
# cherché avait divergé de celui des sources, zéro occurrence substituée, sortie
# 0. Le déploiement aurait publié un domaine mort en annonçant un succès. Zéro
# ne peut pas être un cas nominal — les pages nomment leur instance.
monter
sed -i 's|https://[a-z-]*\.example\.org|#|g' "$BAC/depot/web/my-self.fr/index.html"
sortie=$(lancer assembler --dest "$BAC/dist"); code=$?
if [ "$code" -ne 0 ] && [ ! -d "$BAC/dist" ] && grep -q "aucun gabarit substitué" <<<"$sortie"; then
    ok "aucune occurrence à substituer → refus (code $code)"
else
    nok "aucune occurrence à substituer → code $code : un domaine mort partirait avec un succès annoncé"
fi

# ── 3 — le repli d'exécution n'est pas un gabarit ───────────────────────────
echo
echo "▸ « your-instance.example » traverse intact"
monter
lancer assembler --dest "$BAC/dist" >/dev/null
if grep -q "your-instance.example" "$BAC/dist/self-right/selfjustice/api/act/find.php" 2>/dev/null; then
    ok "repli conservé — c'est un défaut d'exécution, pas un nom d'instance"
else
    nok "le repli a été substitué : le nom de l'instance est gravé dans un message d'erreur"
fi

# ── 4 — un secret n'atteint pas une racine servie ───────────────────────────
echo
echo "▸ Un fichier interdit dans la source"
monter
echo "TOKEN=secret" > "$BAC/depot/web/my-self.fr/.env"
sortie=$(lancer assembler --dest "$BAC/dist"); code=$?
if [ "$code" -ne 0 ] && [ ! -d "$BAC/dist" ]; then
    ok ".env → refus (code $code), rien d'assemblé"
else
    nok ".env → code $code, l'arbre part avec : $(find "$BAC/dist" -name '.env' 2>/dev/null)"
fi

# ── 5 — le fichier gardé survit à l'exclusion de son extension ──────────────
echo
echo "▸ Ce que le filtre ne doit pas emporter"
monter
lancer assembler --dest "$BAC/dist" >/dev/null
if [ -f "$BAC/dist/self-right/selfjustice/api/act/directives.md" ]; then
    ok "directives.md gardé, malgré l'exclusion des *.md"
else
    nok "directives.md filtré alors qu'un vhost le sert"
fi
if [ ! -f "$BAC/dist/README.md" ] && [ ! -d "$BAC/dist/tools" ] \
   && [ ! -d "$BAC/dist/self-right/selfjustice/tests" ]; then
    ok "README, outils et tests écartés de l'arbre servi"
else
    nok "l'arbre emporte de l'outillage : $(ls "$BAC/dist" 2>/dev/null | paste -sd' ')"
fi

# ── 5 bis — l'état de l'instance ne se déploie pas ──────────────────────────
echo
echo "▸ Ce que l'instance a produit chez elle"
# 🔑 La leçon du catalogue SelfAct, le 21/08/2026 : une donnée vivante écrasée
# par la copie d'un poste de travail recule de plusieurs jours sans qu'aucune
# commande n'échoue. Le répertoire doit exister à la destination ; son contenu
# appartient à l'instance.
if [ -f "$BAC/dist/demo/selfdataguard/storage/.gitkeep" ] \
   && [ ! -f "$BAC/dist/demo/selfdataguard/storage/demo.sqlite" ]; then
    ok "storage/ : le répertoire part, la donnée reste chez l'instance"
else
    nok "storage/ : $(ls "$BAC/dist/demo/selfdataguard/storage" 2>/dev/null | paste -sd' ') — la donnée du poste écraserait celle de l'instance"
fi

# ── 5 ter — les secrets du lab ne voyagent pas ──────────────────────────────
echo
echo "▸ Les secrets d'un lab public"
# 🔑 Mesuré le 22/08/2026 : l'assemblage emportait `.blindkey`, `.sitesalt` et
# `.serversecret` de `demo/lab/data/`, que le code du lab décrit lui-même comme
# « propre à ce déploiement ». Les poser aurait remplacé le sel qui vérifie les
# mots de passe et la clé qui chiffre les coffres — plus personne ne se connecte,
# et ce qui était chiffré devient illisible. `flags.txt` aurait changé les
# réponses du CTF sous les joueurs d'une partie en cours.
#
# Le cas éprouve les deux protections, parce qu'elles ne se valent pas :
# l'exclusion évite, et l'interdiction ARRÊTE si le fichier revient par un autre
# chemin. Une exclusion seule laisserait passer le prochain module.
emporte=""
for f in demo/lab/data/.sitesalt demo/lab/data/.blindkey          demo/lab/data/.serversecret demo/lab/challenge/flags.txt          demo/lab/challenge/prepare.php; do
    [ -e "$BAC/dist/$f" ] && emporte="$emporte $f"
done
if [ -z "$emporte" ] && [ -f "$BAC/dist/demo/lab/index.php" ]; then
    ok "lab : le code part, les secrets et les drapeaux restent"
else
    nok "lab : dist/ emporte$emporte$([ -f "$BAC/dist/demo/lab/index.php" ] || echo ' — et le code du lab manque')"
fi

# 🔑 Les bretelles, seules. Un secret déposé AILLEURS que dans le répertoire
# exclu doit arrêter l'assemblage, sinon la correction du 22/08 ne protège que
# le chemin d'hier. `*.key` ne capture pas `.blindkey`, faute de point : c'est
# précisément le trou que ce cas garde fermé.
monter
echo "cle-egaree" > "$BAC/depot/web/my-self.fr/.blindkey"
sortie=$(lancer assembler --dest "$BAC/dist"); code=$?
if [ "$code" -ne 0 ] && [ ! -d "$BAC/dist" ]; then
    ok "un .blindkey hors du lab arrête l'assemblage"
else
    nok "un .blindkey hors du lab a franchi le filtre (code $code)"
fi
monter
lancer assembler --dest "$BAC/dist" >/dev/null 2>&1

# ── 6 — une référence du serveur sans fichier fait refuser la pose ──────────
echo
echo "▸ Le serveur nomme, le déploiement doit apporter"
monter
lancer assembler --dest "$BAC/dist" >/dev/null
rm -f "$BAC/dist/self-right/selfjustice/api/act/directives.md"
# ⚠️ Sans `--appliquer`, exprès. Avec, le refus pouvait venir de « demande
# root » — ce banc ne tourne pas en root — et le cas restait vert alors qu'on
# venait de retirer le contrôle des références. Un refus n'est une preuve que
# si l'on sait de quoi il refuse.
sortie=$(lancer poser "$BAC/dist" --racine "$BAC/www"); code=$?
if [ "$code" -ne 0 ] && grep -q "orpheline" <<<"$sortie" && grep -q "directives.md" <<<"$sortie"; then
    ok "fichier nommé par un vhost et absent → refus (code $code), référence nommée"
else
    nok "référence orpheline non détectée (code $code) : la 404 se découvrirait chez un visiteur"
fi

# ── 7 — un orphelin de la destination est signalé, jamais supprimé ──────────
echo
echo "▸ Ce qui traîne à la destination"
monter
lancer assembler --dest "$BAC/dist" >/dev/null
mkdir -p "$BAC/www/web/my-self.fr"
echo "<p>page retirée du dépôt</p>" > "$BAC/www/web/my-self.fr/ancienne.html"
sortie=$(lancer poser "$BAC/dist" --racine "$BAC/www")
if grep -q "ancienne.html" <<<"$sortie" && [ -f "$BAC/www/web/my-self.fr/ancienne.html" ]; then
    ok "orphelin signalé et conservé — l'effacer est une décision humaine"
else
    nok "orphelin muet, ou supprimé automatiquement"
fi

# ── 8 — sans --appliquer, rien n'est écrit ─────────────────────────────────
echo
echo "▸ Le plan n'écrit pas"
monter
lancer assembler --dest "$BAC/dist" >/dev/null
rm -rf "$BAC/www"; mkdir -p "$BAC/www/web/my-self.fr" "$BAC/www/self-right/selfjustice/api/act"
touch "$BAC/www/self-right/selfjustice/api/act/directives.md"
avant=$(find "$BAC/www" -type f | wc -l)
sortie=$(lancer poser "$BAC/dist" --racine "$BAC/www")
apres=$(find "$BAC/www" -type f | wc -l)
# ⚠️ Compter les fichiers ne suffit pas : ce banc ne tourne pas en root, et le
# contrôle de root refuserait la pose de toute façon. Le compte resterait donc
# égal même si le mode plan avait disparu. Il faut que la sortie DISE qu'elle
# planifie — c'est la seule preuve que ce chemin-là a été pris.
if [ "$avant" -eq "$apres" ] && grep -q "Plan seulement" <<<"$sortie"; then
    ok "sans --appliquer : annoncé comme plan, $avant fichier(s) avant et après"
else
    nok "sans --appliquer : $avant → $apres, et rien n'annonce un plan — le mode a disparu"
fi

# ── 9 — un domaine protégé n'est pas un domaine en panne ────────────────────
#
# 🔑 Ces cas existent parce que `verifier` exigeait 200 partout, `lab` compris,
# alors que `lab` est derrière une authentification Basic et répond 401. La sonde
# sortait donc en échec à chaque pose — indéfiniment, sur un défaut inexistant.
#
# ⚠️ Le cas qui compte n'est pas celui où `lab` rend 401 : c'est celui où il rend
# 200. Une sonde qui accepterait les deux ne mesurerait plus rien, et la
# disparition de la Basic Auth passerait pour une bonne nouvelle.
echo
echo "▸ Le code attendu, par domaine"
monter

# Un faux sondeur : le réseau ne peut pas mentir au script, une variable si.
cat > "$BAC/faux-sonde" <<'SONDE'
#!/bin/bash
hote="${1#https://}"; hote="${hote%%/*}"
while read -r h c; do
    [ "$h" = "$hote" ] && { echo "$c"; exit 0; }
done <<< "$FAUX_CODES"
echo 200
SONDE
chmod +x "$BAC/faux-sonde"

sonder() { FAUX_CODES="$1" MYSELF_SONDE="$BAC/faux-sonde" \
           bash "$BAC/depot/deploy/my-self/deploy.sh" verifier 2>&1; }

sortie=$(sonder "lab.test.invalid 401"); code=$?
if [ "$code" -eq 0 ] && grep -q "401 — protégé" <<<"$sortie"; then
    ok "lab répond 401 : accepté, et dit protégé"
else
    nok "lab répond 401 mais la sonde n'est pas satisfaite (sortie $code)"
fi

sortie=$(sonder "lab.test.invalid 200"); code=$?
if [ "$code" -ne 0 ] && grep -q "attendu 401" <<<"$sortie"; then
    ok "lab répond 200 : REFUSÉ — la protection aurait sauté"
else
    nok "lab répond 200 sans que la sonde bronche : elle n'éprouve plus la Basic Auth"
fi

sortie=$(sonder "justice.test.invalid 502"); code=$?
if [ "$code" -ne 0 ] && grep -q "attendu 200" <<<"$sortie"; then
    ok "un domaine ordinaire en 502 : refusé"
else
    nok "un 502 sur justice passe inaperçu"
fi

echo
total=$((reussites + echecs))
if [ "$echecs" -eq 0 ]; then
    echo "OK — $reussites/$total propriétés tiennent."
    exit 0
fi
echo "ÉCHEC — $echecs propriété(s) sur $total." >&2
exit 1
