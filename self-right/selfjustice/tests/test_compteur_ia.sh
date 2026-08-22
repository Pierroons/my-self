#!/bin/bash
# Éprouve le compteur de consultations d'IA de `tools/update_stats.sh`, sur les
# trois manières qu'il avait de rendre un chiffre faux :
#
#   1. le motif cherché dans la ligne entière — une URL ou un référent suffisait
#      à se déclarer IA, sur un compteur public donc forgeable ;
#   2. la rotation des journaux versant au cumul une valeur encore visible ;
#   3. un journal introuvable rendu comme un journal sans correspondance, ce qui
#      gèle le compteur public sans un mot.
#
# Le code est EXTRAIT du script et rejoué tel quel, jamais recopié : une règle
# réécrite à côté de sa source diverge en silence et le garde-fou reste vert.
# Et c'est la SECTION ENTIÈRE qui est rejouée, pas des fonctions isolées — le
# défaut d'origine n'était pas dans un calcul mais dans l'assemblage, dans le
# choix de la valeur écrite au fichier d'état.

set -u
ICI="$(cd "$(dirname "$0")" && pwd)"
SCRIPT="$ICI/../tools/update_stats.sh"
LIB="$ICI/../tools/lib_journal.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
echecs=0

verifier() { # $1 libellé · $2 attendu · $3 obtenu
    if [ "$2" = "$3" ]; then
        printf '  ✓ %-50s %s\n' "$1" "$3"
    else
        printf '  ✗ %-50s attendu %s, obtenu %s\n' "$1" "$2" "$3"
        echecs=$((echecs + 1))
    fi
}

# ---------------------------------------------------------------------------
# Extraction. Si le script est remanié et que ces bornes ne trouvent plus rien,
# le test SORT EN ERREUR — sans cette garde il passerait au vert sans rien éprouver.
# ---------------------------------------------------------------------------
for f in "$SCRIPT" "$LIB"; do
    [ -r "$f" ] || { echo "ÉCHEC : $f illisible"; exit 1; }
done
# shellcheck source=../tools/lib_journal.sh
. "$LIB"

SECTION="$TMP/section.sh"
sed -n '/^AI_PATTERNS=/p' "$SCRIPT" > "$SECTION"
sed -n '/^entier() /,/^TOTAL_HITS=\$(recompter_ia)/p' "$SCRIPT" >> "$SECTION"

for attendu in 'AI_PATTERNS=' 'entier()' 'compter_ia()' 'cumul_apres_rotation()' \
               'recompter_ia()' 'TOTAL_HITS=$(recompter_ia)'; do
    if ! grep -qF "$attendu" "$SECTION"; then
        echo "ÉCHEC : « $attendu » absent de la section extraite de $SCRIPT."
        echo "        Le script a changé de forme ; ce test n'éprouve plus rien."
        exit 1
    fi
done
for f in journal_user_agents journal_contenu; do
    declare -f "$f" >/dev/null 2>&1 || { echo "ÉCHEC : $f absente de $LIB"; exit 1; }
done

# Rejoue la section avec les chemins du bac à sable. `LOG_FILE` et consorts sont
# lus par `recompter_ia` : les poser ici suffit, le script les rend surchargeables.
rejouer() {
    # Lues par la section sourcée juste après, que shellcheck ne suit pas.
    # shellcheck disable=SC2034
    LOG_FILE="$TMP/access.log"
    # shellcheck disable=SC2034
    LOG_FILE_OLD="$TMP/access.log.1"
    # shellcheck disable=SC2034
    COUNTER_FILE="$TMP/counter.txt"
    # shellcheck disable=SC2034
    ETAT_ROTATION="$TMP/rotation.txt"
    TOTAL_HITS=''
    # shellcheck source=/dev/null
    . "$SECTION"
    printf '%s' "${TOTAL_HITS:-vide}"
}

# ---------------------------------------------------------------------------
# 1. Le motif se cherche dans le User-Agent
# ---------------------------------------------------------------------------
# `ClaudeBot/1.0` porte la casse que le robot d'Anthropic annonce réellement,
# quand `AI_PATTERNS` écrit `claudebot` : c'est ce qui rend `tolower` porteur.
# Avec une fixture tout en minuscules, retirer l'insensibilité à la casse
# n'aurait rien changé au résultat.
cat > "$TMP/access.log" <<'FIXTURE'
1.2.3.4 - - [22/Aug/2026:10:00:00 +0200] "GET / HTTP/1.1" 200 39215 "-" "Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)"
9.9.9.9 - - [22/Aug/2026:10:01:00 +0200] "GET /robots.txt HTTP/1.1" 200 812 "-" "ClaudeBot/1.0"
9.9.9.9 - - [22/Aug/2026:10:02:00 +0200] "GET / HTTP/1.1" 200 39215 "-" "Mozilla/5.0 (compatible; PerplexityBot/1.0)"
8.8.8.8 - - [22/Aug/2026:10:03:00 +0200] "GET / HTTP/1.1" 200 39215 "-" "Robot \x22GPTBot\x22/1.2"
5.6.7.8 - - [22/Aug/2026:10:04:00 +0200] "GET /?q=ChatGPT HTTP/1.1" 200 39215 "-" "Mozilla/5.0 (X11; Linux x86_64) Firefox/128.0"
5.6.7.8 - - [22/Aug/2026:10:05:00 +0200] "GET / HTTP/1.1" 200 39215 "https://blog.example/anthropic-et-le-droit" "Mozilla/5.0 (X11; Linux x86_64) Firefox/128.0"
7.7.7.7 - - [22/Aug/2026:10:06:00 +0200] "GET / HTTP/1.1" 200 39215 "-" "Mozilla/5.0 (Windows NT 10.0) Chrome/126.0"
1.2.3.4 - - [22/Aug/2026:10:07:00 +0200] "GET /?q=GPTBot HTTP/1.1"
decision anthropic — ligne tronquee, sans guillemets
FIXTURE
: > "$TMP/access.log.1"

echo "Compteur d'IA — le motif se cherche dans le User-Agent"
AI_PATTERNS="$(sed -n "s/^AI_PATTERNS='\(.*\)'$/\1/p" "$SCRIPT")"
verifier "quatre robots, quatre User-Agents" 4 "$(rejouer)"

# La sonde doit pouvoir rougir : sur CETTE fixture, l'ancienne méthode — `grep`
# sur la ligne entière — compte quatre consultations qui n'ont jamais eu lieu.
verifier "la fixture distingue (ancienne méthode)" 8 "$(grep -ciE "$AI_PATTERNS" "$TMP/access.log")"

# Trois contrefaçons, dont une par troncature : sur une ligne coupée juste après
# la requête, l'avant-dernier champ entre guillemets EST la requête.
cp "$TMP/access.log" "$TMP/fixture.log"
for forge in '/?q=ChatGPT' 'blog.example/anthropic' '/?q=GPTBot'; do
    grep -F "$forge" "$TMP/fixture.log" > "$TMP/access.log"
    rm -f "$TMP/counter.txt" "$TMP/rotation.txt"
    verifier "forgé par un visiteur : $forge" 0 "$(rejouer)"
done
cp "$TMP/fixture.log" "$TMP/access.log"
cp "$TMP/fixture.log" "$TMP/fixture-ua.log"
rm -f "$TMP/counter.txt" "$TMP/rotation.txt" "$TMP/fixture.log"

# ---------------------------------------------------------------------------
# 2. Trois rotations d'affilée — l'assemblage, pas les fonctions isolées
# ---------------------------------------------------------------------------
echo
echo "Rotation — trois cycles, le total suit la vérité"

journal() { # $1 fichier · $2 nombre de consultations d'IA
    : > "$1"
    local i=0
    while [ "$i" -lt "$2" ]; do
        echo '1.2.3.4 - - [x] "GET / HTTP/1.1" 200 1 "-" "ClaudeBot/1.0"' >> "$1"
        i=$((i + 1))
    done
    echo '7.7.7.7 - - [x] "GET / HTTP/1.1" 200 1 "-" "Chrome/126.0"' >> "$1"
}

rotation() { mv "$TMP/access.log" "$TMP/access.log.1"; }

rm -f "$TMP/counter.txt" "$TMP/rotation.txt" "$TMP/access.log.1"
journal "$TMP/access.log" 3
verifier "jour 1 — 3 consultations"                3 "$(rejouer)"
rotation ; journal "$TMP/access.log" 2
verifier "jour 2 — le pivoté reste visible (3+2)"  5 "$(rejouer)"
rotation ; journal "$TMP/access.log" 4
verifier "jour 3 — le premier journal a disparu"   9 "$(rejouer)"
rotation ; journal "$TMP/access.log" 1
verifier "jour 4 — cumul + fenêtre"               10 "$(rejouer)"
verifier "sans rotation, rejouer ne change rien"  10 "$(rejouer)"

# ---------------------------------------------------------------------------
# 3. Une source introuvable crie, et ne rend pas zéro
# ---------------------------------------------------------------------------
echo
echo "Source introuvable — le compteur se tait plutôt que de mentir"
mv "$TMP/access.log" "$TMP/ailleurs.log"
verifier "journal disparu : pas de valeur publiée" vide "$(rejouer 2>"$TMP/err")"
if grep -q 'ALERTE' "$TMP/err"; then
    printf '  ✓ %-50s %s\n' "et l'absence est annoncée" "ALERTE"
else
    printf '  ✗ %-50s\n' "l'absence passe en silence"
    echecs=$((echecs + 1))
fi
mv "$TMP/ailleurs.log" "$TMP/access.log"

if [ "$(id -u)" -eq 0 ]; then
    printf '  ↷ %-50s %s\n' "journal illisible" "non éprouvé (lancé en root)"
else
    chmod 000 "$TMP/access.log.1"
    rejouer >/dev/null 2>"$TMP/err2" || true
    if grep -q 'illisible' "$TMP/err2"; then
        printf '  ✓ %-50s %s\n' "journal illisible : annoncé" "ALERTE"
    else
        printf '  ✗ %-50s\n' "journal illisible compté comme vide"
        echecs=$((echecs + 1))
    fi
    chmod 644 "$TMP/access.log.1"
fi

# ---------------------------------------------------------------------------
# 4. Le second compteur — `build_stats.sh` publie un JSON qui AFFIRME
#    catégoriser par User-Agent. Il est éprouvé de bout en bout, avec ses
#    familles, parce que le défaut vivait dans les deux scripts et qu'un
#    correctif appliqué à un seul ne vaut rien.
# ---------------------------------------------------------------------------
echo
echo "Second compteur — la ventilation par famille"
BUILD="$ICI/../tools/build_stats.sh"
if [ ! -x "$BUILD" ] && [ ! -r "$BUILD" ]; then
    echo "ÉCHEC : $BUILD introuvable — la ventilation n'est plus éprouvée."
    exit 1
fi
cp "$TMP/fixture-ua.log" "$TMP/access.log"
: > "$TMP/access.log.1"
SELFJUSTICE_LOG="$TMP/access.log" SELFJUSTICE_LOG_OLD="$TMP/access.log.1" \
    SELFJUSTICE_STATS_DIR="$TMP/stats" bash "$BUILD" >/dev/null 2>&1

champ() { sed -n "s/.*\"$1\": \([0-9]*\).*/\1/p" "$TMP/stats/by-ai.json" | head -1; }
verifier "consultations utilisateur (ChatGPT-User)"  1 "$(champ user_total)"
verifier "robot Anthropic (ClaudeBot)"               1 "$(champ claude_bots)"
verifier "robot OpenAI (GPTBot, une seule fois)"     1 "$(champ openai_bots)"
verifier "robot Perplexity"                          1 "$(champ perplexity_bots)"
verifier "total des robots"                          3 "$(champ crawler_total)"

echo
if [ "$echecs" -eq 0 ]; then
    echo "OK — compteur d'IA : User-Agent, rotation, source introuvable, ventilation."
else
    echo "ÉCHEC — $echecs contrôle(s) en défaut."
    exit 1
fi
