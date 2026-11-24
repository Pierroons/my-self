#!/usr/bin/env bash
# Le nombre d'ouvriers PHP du gabarit tient-il dans la mémoire de la machine ?
#
# 🔑 **Pourquoi ce contrôle existe.** `pm.max_children` est le plafond de
# requêtes PHP simultanées du serveur ENTIER : un seul groupe d'ouvriers y sert
# tous les vhosts, et `$GABARIT` dit combien, daté. Le relever est un réglage
# d'exploitation ; le relever trop fait
# tuer des ouvriers par le noyau sous charge, ce qui ressemble à une panne
# d'application. Ce qui décide n'est pas le poids d'un ouvrier au repos, c'est
# son **pic** : une ouverture d'enveloppe SelfDataGuard alloue le `memlimit`
# d'Argon2id, et libsodium alloue **en dehors** du `memory_limit` de PHP.
#
# 🔑 **Le profil est lu dans sa source**, `Primitives.php`, jamais recopié ici :
# le jour où le profil monte, ce contrôle doit rougir sans qu'on y pense. Et il
# mesure DEUX profils — celui sous lequel on scelle, et le plafond sous lequel
# on accepte encore de RELIRE. C'est le second qui dimensionne : une enveloppe
# porte son propre profil, donc un lecteur paie ce que son auteur a choisi, dans
# la limite du plafond (`PROFILE_CEILING_MEMLIMIT`).
#
# ⚠️ **Le surcoût d'une dérivation est mesuré ICI, pas sur l'hôte.** libsodium
# alloue `memlimit` octets quelle que soit la machine, et faire dériver au
# plafond sur une production coûterait un `PROFILE_CEILING_MEMLIMIT` et son temps
# de calcul par essai. De l'hôte, ce contrôle ne LIT que sa mémoire disponible et
# le poids de ses ouvriers au repos.
#
#   POOL_SSH_HOST=<alias> bash deploy/my-self/tests/pic-ouvrier-php.sh
#
# Sortie : 0 si le gabarit tient dans le budget, 1 s'il le dépasse, 2 si le
# contrôle n'a pas pu être établi.
set -uo pipefail

RACINE="$(git rev-parse --show-toplevel)" || exit 2
[ -n "$RACINE" ] || { echo "❌ hors dépôt git" >&2; exit 2; }
cd "$RACINE" || exit 2

GABARIT=deploy/my-self/php-fpm-www.conf
SOURCE=self-security/selfdataguard/src/Crypto/Primitives.php

# La part de la mémoire disponible que le pire cas de PHP peut occuper. Le reste
# n'est pas du gras : le frontal, les bases servies et les services voisins
# vivent dedans, et le pire cas de PHP n'a aucune raison d'arriver seul.
PART_BUDGET=70

for f in "$GABARIT" "$SOURCE"; do
    [ -r "$f" ] || { echo "❌ introuvable : $f"; exit 2; }
done
command -v php >/dev/null || { echo "❌ php absent — le surcoût ne peut pas être mesuré"; exit 2; }
php -r 'exit(extension_loaded("sodium") ? 0 : 1);' \
    || { echo "❌ l'extension sodium manque — le surcoût ne peut pas être mesuré"; exit 2; }
HOTE=${POOL_SSH_HOST:?alias SSH de la machine qui sert — sans elle, aucun budget à comparer}

# ── 1. Le profil, lu dans sa source ─────────────────────────────────────────
# shellcheck disable=SC2016  # c'est du PHP, ses $ ne sont pas pour le shell
profil=$(php -r '
require $argv[1];
$c = "Pierroons\\SelfDataGuard\\Crypto\\Primitives";
printf("%d %d %d %d",
    constant("$c::ARGON2_OPSLIMIT"),         constant("$c::ARGON2_MEMLIMIT"),
    constant("$c::PROFILE_CEILING_OPSLIMIT"), constant("$c::PROFILE_CEILING_MEMLIMIT"));
' "$SOURCE" 2>/dev/null) || { echo "❌ profil illisible dans $SOURCE"; exit 2; }
read -r OPS MEM OPS_MAX MEM_MAX <<<"$profil"
if [ -z "${MEM_MAX:-}" ] || [ "$MEM_MAX" -le 0 ]; then
    echo "❌ profil vide dans $SOURCE"; exit 2
fi
echo "▸ 1. Profil Argon2id, lu dans $SOURCE"
echo "   scellement : ops $OPS, mem $((MEM / 1048576)) MiB · plafond de relecture : ops $OPS_MAX, mem $((MEM_MAX / 1048576)) MiB"

# ── 2. Le surcoût d'une dérivation, mesuré ──────────────────────────────────
#
# VmHWM est le pic du processus depuis son démarrage ; VmRSS, ce qu'il occupe à
# l'instant t. Les deux comptent : le premier dit ce qu'une dérivation réclame,
# le second si elle le rend.
# shellcheck disable=SC2016  # idem : PHP, pas du shell
mesure=$(php -r '
function lire(): array {
    $s = file_get_contents("/proc/self/status");
    preg_match("/VmRSS:\s+(\d+) kB/", $s, $a);
    preg_match("/VmHWM:\s+(\d+) kB/", $s, $b);
    return [(int)($a[1] ?? 0), (int)($b[1] ?? 0)];
}
[$repos, ] = lire();
sodium_crypto_pwhash(32, "mesure", random_bytes(16), (int)$argv[1], (int)$argv[2], SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
[, $pic_courant] = lire();
sodium_crypto_pwhash(32, "mesure", random_bytes(16), (int)$argv[3], (int)$argv[4], SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
[$apres_max, $pic_max] = lire();
printf("%d %d %d %d", $repos, $pic_courant, $pic_max, $apres_max);
' "$OPS" "$MEM" "$OPS_MAX" "$MEM_MAX" 2>/dev/null) || { echo "❌ la mesure du surcoût a échoué"; exit 2; }
read -r REPOS PIC_COURANT PIC_MAX APRES_M <<<"$mesure"
[ "${PIC_MAX:-0}" -gt 0 ] || { echo "❌ mesure vide — /proc/self/status illisible ?"; exit 2; }

SUR_COURANT=$(( (PIC_COURANT - REPOS) / 1024 ))
SUR_MAX=$(( (PIC_MAX - REPOS) / 1024 ))
echo "▸ 2. Surcoût d'UNE dérivation, mesuré sur ce poste"
echo "   au repos $((REPOS / 1024)) Mio · pic au profil courant +${SUR_COURANT} Mio · pic au plafond +${SUR_MAX} Mio"
if [ "$((APRES_M - REPOS))" -gt 16384 ]; then
    echo "   ⚠️ la mémoire n'est PAS rendue après la dérivation ($(( (APRES_M - REPOS) / 1024 )) Mio restent)."
    echo "      Le surcoût devient alors permanent par ouvrier, et le budget ci-dessous est optimiste."
else
    echo "   ✓ rendue après la dérivation : seuls les ouvriers qui dérivent AU MÊME INSTANT la paient"
fi

# ── 3. Ce que la machine offre ──────────────────────────────────────────────
echo "▸ 3. La machine qui sert — $HOTE, en lecture seule"
# shellcheck disable=SC2016  # les $ sont évalués par le shell DISTANT, exprès
distant=$(timeout 30 ssh -o BatchMode=yes "$HOTE" '
    free -m | awk "/^Mem:/ {print \$7}"
    ps -o rss= -C php-fpm8.4 2>/dev/null | sort -n | awk "{v[NR]=\$1} END {print (NR ? v[int((NR+1)/2)] : 0)}"
' 2>/dev/null) || { echo "   ❌ lecture impossible — ni vert ni rouge, le contrôle n'est pas établi"; exit 2; }
# ⚠️ `free -m` rend des MÉBIoctets, et `/proc` des kibioctets : tout ce qui suit
# est en Mio. Le dépôt porte un contrôle « Mio/MiB, jamais Mo/MB » pour la
# mémoire d'un profil de hachage — la même rigueur vaut pour celle d'une machine,
# sinon une somme de Mio s'écrit en Mo et le total devient faux de 5 %.
DISPO_MIO=$(printf '%s\n' "$distant" | sed -n 1p)
OUVRIER_KO=$(printf '%s\n' "$distant" | sed -n 2p)
if [ -z "${DISPO_MIO:-}" ] || [ "$DISPO_MIO" -le 0 ]; then
    echo "   ❌ mémoire disponible non lue"; exit 2
fi
if [ -z "${OUVRIER_KO:-}" ] || [ "$OUVRIER_KO" -le 0 ]; then
    echo "   ❌ aucun processus php-fpm8.4 lu : le groupe tourne-t-il ?"; exit 2
fi
OUVRIER_MIO=$((OUVRIER_KO / 1024))
echo "   mémoire disponible ${DISPO_MIO} Mio · un ouvrier au repos ${OUVRIER_MIO} Mio (médiane des processus lus)"

# ── 4. Le verdict ───────────────────────────────────────────────────────────
PIC_OUVRIER=$((OUVRIER_MIO + SUR_MAX))
BUDGET=$((DISPO_MIO * PART_BUDGET / 100))
PLAFOND=$((BUDGET / PIC_OUVRIER))
DECLARE=$(sed -nE 's/^[[:space:]]*pm\.max_children[[:space:]]*=[[:space:]]*([0-9]+).*/\1/p' "$GABARIT" | tail -1)
[ -n "${DECLARE:-}" ] || { echo "❌ pm.max_children introuvable dans $GABARIT"; exit 2; }

echo "▸ 4. Verdict"
echo "   pic d'un ouvrier qui relit au plafond : ${OUVRIER_MIO} + ${SUR_MAX} = ${PIC_OUVRIER} Mio"
echo "   budget retenu : ${PART_BUDGET} % de ${DISPO_MIO} Mio = ${BUDGET} Mio → ${PLAFOND} ouvrier(s) au plus"
echo "   le gabarit déclare pm.max_children = ${DECLARE}"
echo "   régimes à cette valeur : repos $((DECLARE * OUVRIER_MIO)) Mio · tous en dérivation courante $((DECLARE * (OUVRIER_MIO + SUR_COURANT))) Mio · pire cas $((DECLARE * PIC_OUVRIER)) Mio"
echo

if [ "$DECLARE" -gt "$PLAFOND" ]; then
    echo "✗ le gabarit dépasse le budget : $((DECLARE * PIC_OUVRIER)) Mio dans le pire cas, pour ${BUDGET} Mio."
    echo "  Soit la valeur descend à ${PLAFOND}, soit la machine gagne de la mémoire, soit le"
    echo "  plafond de relecture du profil baisse — c'est lui qui pèse ${SUR_MAX} Mio par ouvrier."
    exit 1
fi
echo "✓ ${DECLARE} ouvriers tiennent dans le budget (plafond mesuré : ${PLAFOND})."
echo "  ⚠️ Ce contrôle borne la MÉMOIRE, rien d'autre. Le temps qu'un script peut"
echo "     retenir un ouvrier est borné ailleurs, par le \`request_terminate_timeout\`"
echo "     du gabarit — à relire là-bas, pas ici."
