#!/usr/bin/env bash
# Les groupes d'ouvriers PHP de la machine tiennent-ils ENSEMBLE dans sa mémoire ?
#
# 🔑 **Pourquoi ce contrôle existe.** `pm.max_children` dit combien de requêtes
# PHP un groupe sert en même temps. Le relever est un réglage d'exploitation ; le
# relever trop fait tuer des ouvriers par le noyau sous charge, ce qui ressemble
# à une panne d'application. Et ce qui décide n'est pas le poids d'un ouvrier au
# repos, c'est son **pic** : une dérivation Argon2id fait allouer son `memlimit`
# par libsodium, **en dehors** du `memory_limit` de PHP.
#
# 🔑 **Pourquoi il lit PLUSIEURS gabarits depuis le 24/11/2026.** La machine n'a
# plus un seul groupe mais cinq, chacun sous sa propre identité. Le budget est
# celui de la machine : il se compare à la SOMME des groupes, jamais à un seul.
# Un contrôle resté sur un gabarit unique aurait rendu vert en ignorant quatre
# groupes — c'est-à-dire la plus grande partie de ce qu'il prétend mesurer.
#
# 🔑 **Et pourquoi chaque groupe déclare son profil de pic.** Tous ne dérivent
# pas, et ceux qui dérivent ne paient pas le même prix. Appliquer le pire profil
# à tous surestimait le besoin d'un facteur dix sur les groupes qui ne chiffrent
# rien. Chaque gabarit porte donc une ligne `; @pic: <profil>`, sur le modèle du
# `# @plante` des canaris de `structure.yml` : **une sonde qui devine rend un
# vert dont personne ne connaît la portée.** Un gabarit sans marqueur fait
# échouer ce contrôle au lieu d'être deviné.
#
#   @pic: aucun                   le groupe ne dérive aucune clé — son pic est son repos
#   @pic: selfrecover             il dérive au profil de SelfRecover (Hashing::ARGON2)
#   @pic: selfdataguard-plafond   il relit des enveloppes, donc jusqu'au plafond
#                                 de relecture (PROFILE_CEILING_MEMLIMIT)
#
# ⚠️ **Le surcoût d'une dérivation est mesuré ICI, pas sur l'hôte.** libsodium
# alloue `memlimit` octets quelle que soit la machine, et faire dériver au
# plafond sur une production coûterait ce plafond et son temps de calcul par
# essai. De l'hôte, ce contrôle ne LIT que sa mémoire disponible et le poids de
# ses ouvriers au repos.
#
#   POOL_SSH_HOST=<alias> bash deploy/my-self/tests/pic-ouvrier-php.sh
#
# Sortie : 0 si la somme tient dans le budget, 1 si elle le dépasse, 2 si le
# contrôle n'a pas pu être établi.
set -uo pipefail

RACINE="$(git rev-parse --show-toplevel)" || exit 2
[ -n "$RACINE" ] || { echo "❌ hors dépôt git" >&2; exit 2; }
cd "$RACINE" || exit 2

# Les dossiers servis par CETTE machine. `deploy/ctf-s2/` en est absent exprès :
# le lab est un autre hôte, avec sa propre mémoire, et compter ses groupes ici
# gonflerait un budget qu'ils ne consomment pas.
DOSSIERS=(deploy/my-self deploy/bi-self deploy/selfdataguard deploy/selfjustice)

SRC_DATAGUARD=self-security/selfdataguard/src/Crypto/Primitives.php
SRC_RECOVER=bi-self/selfrecover/src/Crypto/Hashing.php

# La part de la mémoire disponible que le pire cas de PHP peut occuper. Le reste
# n'est pas du gras : le frontal, les bases servies et les services voisins
# vivent dedans, et le pire cas de PHP n'a aucune raison d'arriver seul.
PART_BUDGET=70

for f in "$SRC_DATAGUARD" "$SRC_RECOVER"; do
    [ -r "$f" ] || { echo "❌ introuvable : $f"; exit 2; }
done
command -v php >/dev/null || { echo "❌ php absent — le surcoût ne peut pas être mesuré"; exit 2; }
php -r 'exit(extension_loaded("sodium") ? 0 : 1);' \
    || { echo "❌ l'extension sodium manque — le surcoût ne peut pas être mesuré"; exit 2; }
HOTE=${POOL_SSH_HOST:?alias SSH de la machine qui sert — sans elle, aucun budget à comparer}

# ── 1. Les deux profils, lus dans leur source ───────────────────────────────
# shellcheck disable=SC2016  # c'est du PHP, ses $ ne sont pas pour le shell
profil=$(php -r '
require $argv[1];
$c = "Pierroons\\SelfDataGuard\\Crypto\\Primitives";
printf("%d %d %d %d",
    constant("$c::ARGON2_OPSLIMIT"),          constant("$c::ARGON2_MEMLIMIT"),
    constant("$c::PROFILE_CEILING_OPSLIMIT"), constant("$c::PROFILE_CEILING_MEMLIMIT"));
' "$SRC_DATAGUARD" 2>/dev/null) || { echo "❌ profil illisible dans $SRC_DATAGUARD"; exit 2; }
read -r OPS MEM OPS_MAX MEM_MAX <<<"$profil"
if [ -z "${MEM_MAX:-}" ] || [ "$MEM_MAX" -le 0 ]; then
    echo "❌ profil vide dans $SRC_DATAGUARD"; exit 2
fi

# ⚠️ `memory_cost` de PHP est en **Kio**, là où `memlimit` de libsodium est en
# octets. Les confondre donne un surcoût faux de 1024 — d'où la conversion ici,
# et non au moment de la soustraction.
# shellcheck disable=SC2016  # idem : PHP, pas du shell
profil_sr=$(php -r '
require $argv[1];
$p = constant("Pierroons\\SelfRecover\\Crypto\\Hashing::ARGON2");
printf("%d %d", $p["time_cost"], $p["memory_cost"] * 1024);
' "$SRC_RECOVER" 2>/dev/null) || { echo "❌ profil illisible dans $SRC_RECOVER"; exit 2; }
read -r OPS_SR MEM_SR <<<"$profil_sr"
if [ -z "${MEM_SR:-}" ] || [ "$MEM_SR" -le 0 ]; then
    echo "❌ profil vide dans $SRC_RECOVER"; exit 2
fi

echo "▸ 1. Les profils Argon2id, lus dans leur source"
echo "   SelfDataGuard — scellement ops $OPS, mem $((MEM / 1048576)) MiB · plafond de relecture ops $OPS_MAX, mem $((MEM_MAX / 1048576)) MiB"
echo "   SelfRecover   — ops $OPS_SR, mem $((MEM_SR / 1048576)) MiB"

# ── 2. Le surcoût de chaque profil, mesuré ──────────────────────────────────
#
# VmHWM est le pic du processus depuis son démarrage ; VmRSS, ce qu'il occupe à
# l'instant t. Les deux comptent : le premier dit ce qu'une dérivation réclame,
# le second si elle le rend. Les dérivations sont faites de la plus légère à la
# plus lourde, sinon le pic de la première masquerait celui des suivantes.
# shellcheck disable=SC2016  # idem : PHP, pas du shell
mesure=$(php -r '
function lire(): array {
    $s = file_get_contents("/proc/self/status");
    preg_match("/VmRSS:\s+(\d+) kB/", $s, $a);
    preg_match("/VmHWM:\s+(\d+) kB/", $s, $b);
    return [(int)($a[1] ?? 0), (int)($b[1] ?? 0)];
}
[$repos, ] = lire();
sodium_crypto_pwhash(32, "m", random_bytes(16), (int)$argv[1], (int)$argv[2], SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
[, $pic_sr] = lire();
sodium_crypto_pwhash(32, "m", random_bytes(16), (int)$argv[3], (int)$argv[4], SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
[, $pic_courant] = lire();
sodium_crypto_pwhash(32, "m", random_bytes(16), (int)$argv[5], (int)$argv[6], SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
[$apres, $pic_max] = lire();
printf("%d %d %d %d %d", $repos, $pic_sr, $pic_courant, $pic_max, $apres);
' "$OPS_SR" "$MEM_SR" "$OPS" "$MEM" "$OPS_MAX" "$MEM_MAX" 2>/dev/null) \
    || { echo "❌ la mesure du surcoût a échoué"; exit 2; }
read -r REPOS PIC_SR PIC_COURANT PIC_MAX APRES_M <<<"$mesure"
[ "${PIC_MAX:-0}" -gt 0 ] || { echo "❌ mesure vide — /proc/self/status illisible ?"; exit 2; }

SUR_SR=$(( (PIC_SR - REPOS) / 1024 ))
SUR_COURANT=$(( (PIC_COURANT - REPOS) / 1024 ))
SUR_MAX=$(( (PIC_MAX - REPOS) / 1024 ))
echo "▸ 2. Surcoût d'UNE dérivation, mesuré sur ce poste"
echo "   au repos $((REPOS / 1024)) Mio · SelfRecover +${SUR_SR} Mio · scellement +${SUR_COURANT} Mio · plafond de relecture +${SUR_MAX} Mio"
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
    echo "   ❌ aucun processus php-fpm8.4 lu : un groupe tourne-t-il ?"; exit 2
fi
OUVRIER_MIO=$((OUVRIER_KO / 1024))
echo "   mémoire disponible ${DISPO_MIO} Mio · un ouvrier au repos ${OUVRIER_MIO} Mio (médiane des processus lus)"

# ── 4. Chaque groupe, son plafond et son profil ─────────────────────────────
#
# Les gabarits sont découverts, pas listés : un groupe neuf entre donc dans le
# budget sans qu'on y pense. ⚠️ C'est aussi pourquoi le marqueur est OBLIGATOIRE
# — sans lui, un gabarit neuf entrerait avec un pic deviné.
mapfile -t GABARITS < <(
    for d in "${DOSSIERS[@]}"; do
        [ -d "$d" ] || continue
        find "$d" -maxdepth 1 -name 'php-fpm-*.conf' -type f | sort
    done
)
if [ "${#GABARITS[@]}" -eq 0 ]; then
    echo "❌ aucun gabarit de groupe trouvé sous ${DOSSIERS[*]}"; exit 2
fi
echo "▸ 4. Les groupes d'ouvriers déclarés — ${#GABARITS[@]} gabarit(s)"

TOTAL_REPOS=0; TOTAL_PIRE=0; TOTAL_OUVRIERS=0; MANQUE=0
for g in "${GABARITS[@]}"; do
    # ⚠️ Un gabarit peut déclarer PLUSIEURS sections — `php-fpm-calcul.conf` du
    # lab en porte deux. Leurs plafonds se SOMMENT, parce que les sections
    # tournent ensemble : lire la dernière seule sous-estimerait le groupe.
    enfants=$(sed -nE 's/^[[:space:]]*pm\.max_children[[:space:]]*=[[:space:]]*([0-9]+).*/\1/p' "$g" \
        | awk '{s+=$1} END {print s+0}')
    profil_pool=$(sed -nE 's/^[[:space:]]*;[[:space:]]*@pic:[[:space:]]*([a-z-]+).*/\1/p' "$g" | head -1)

    if [ -z "$profil_pool" ]; then
        echo "   ✗ $g — AUCUN marqueur \`; @pic: …\` : son pic ne peut pas être deviné"
        MANQUE=1; continue
    fi
    if [ "$enfants" -le 0 ]; then
        echo "   ✗ $g — pm.max_children introuvable ou nul"
        MANQUE=1; continue
    fi

    case "$profil_pool" in
        aucun)                 sur=0 ;;
        selfrecover)           sur=$SUR_SR ;;
        selfdataguard-plafond) sur=$SUR_MAX ;;
        *)
            echo "   ✗ $g — marqueur inconnu : « $profil_pool »"
            MANQUE=1; continue ;;
    esac

    pic=$((OUVRIER_MIO + sur))
    printf '   %-46s %2d ouvrier(s) · @pic %-22s pire cas %5d Mio\n' \
        "${g#deploy/}" "$enfants" "$profil_pool" "$((enfants * pic))"
    TOTAL_OUVRIERS=$((TOTAL_OUVRIERS + enfants))
    TOTAL_REPOS=$((TOTAL_REPOS + enfants * OUVRIER_MIO))
    TOTAL_PIRE=$((TOTAL_PIRE + enfants * pic))
done

if [ "$MANQUE" -ne 0 ]; then
    echo
    echo "✗ un gabarit au moins n'est pas exploitable : le budget total serait faux."
    echo "  Chaque gabarit de groupe porte une ligne \`; @pic: aucun|selfrecover|selfdataguard-plafond\`."
    exit 1
fi

# ── 5. Le verdict, sur la SOMME ─────────────────────────────────────────────
BUDGET=$((DISPO_MIO * PART_BUDGET / 100))
echo "▸ 5. Verdict — sur la somme des groupes, pas sur l'un d'eux"
echo "   ${TOTAL_OUVRIERS} ouvrier(s) au total · au repos ${TOTAL_REPOS} Mio · pire cas ${TOTAL_PIRE} Mio"
echo "   budget retenu : ${PART_BUDGET} % de ${DISPO_MIO} Mio = ${BUDGET} Mio"
echo

if [ "$TOTAL_PIRE" -gt "$BUDGET" ]; then
    echo "✗ la somme des groupes dépasse le budget : ${TOTAL_PIRE} Mio dans le pire cas, pour ${BUDGET} Mio."
    echo "  Ce qui pèse est le groupe dont le profil est le plus lourd — baisser son"
    echo "  \`pm.max_children\` coûte moins que de baisser tous les autres."
    exit 1
fi
echo "✓ les ${#GABARITS[@]} groupes tiennent ensemble : ${TOTAL_PIRE} Mio de pire cas pour ${BUDGET} Mio de budget."
echo "  ⚠️ Ce contrôle borne la MÉMOIRE, rien d'autre. Le temps qu'un script peut"
echo "     retenir un ouvrier est borné par le \`request_terminate_timeout\` de son"
echo "     gabarit, et le cloisonnement des identités par"
echo "     \`deploy/my-self/tests/cloisonnement-pools.sh\` — pas ici."
