#!/usr/bin/env bash
# Chaque famille de routes sort-elle avec ses en-têtes de sécurité ?
#
# 🔑 **Pourquoi ce contrôle existe.** nginx ne FUSIONNE pas les `add_header` :
# un seul `add_header` déclaré dans un bloc `location` annule tous ceux du bloc
# `server` pour ce chemin. Le vhost de bi-self hérite ses en-têtes d'un fragment
# posé au niveau `server`, et il compte huit blocs `location` : il suffit qu'un
# seul d'entre eux pose un en-tête un jour pour que ses routes sortent nues. Rien
# ne le signale — la page s'affiche, elle sort simplement sans ses protections.
#
# ⚠️ **Il interroge l'instance, pas le dépôt.** Il ne peut donc pas tourner en
# intégration continue, et il ne remplace pas `scripts/check-vhost.sh`, qui juge
# la syntaxe du gabarit. Les deux mesurent des choses différentes : l'un que le
# fichier est valide, l'autre que le serveur répond comme il annonce.
#
# 🔑 **Les en-têtes attendus sont LUS dans les fragments versionnés**, pas
# recopiés ici : une liste recopiée vieillit sans bruit, et le jour où un
# fragment gagne un en-tête, ce contrôle doit l'exiger sans qu'on y pense.
#
#   BISELF_HOTE=bi-self.exemple.fr bash deploy/bi-self/tests/entetes-servis.sh
#
# Sortie : 0 si chaque route porte tous les en-têtes, 1 s'il en manque un,
# 2 si le contrôle n'a pas pu être établi — un contrôle qu'on ne peut pas lancer
# ne rend pas vert.
set -uo pipefail

RACINE="$(git rev-parse --show-toplevel)" || exit 2
[ -n "$RACINE" ] || { echo "❌ hors dépôt git" >&2; exit 2; }
cd "$RACINE" || exit 2

HOTE=${BISELF_HOTE:?nom d-hôte de l-instance à interroger (ex. BISELF_HOTE=bi-self.exemple.fr)}
command -v curl >/dev/null || { echo "❌ curl absent"; exit 2; }

FRAGMENT=deploy/my-self/snippets/selfrecover-security-headers.conf
[ -r "$FRAGMENT" ] || { echo "❌ fragment introuvable : $FRAGMENT"; exit 2; }

# Les en-têtes du fragment, et de ceux qu'il inclut par `include snippets/X.conf`.
# Un seul niveau d'inclusion : c'est la profondeur que le fragment emploie, et
# une récursion muette cacherait le jour où elle s'approfondit.
fichiers=("$FRAGMENT")
while read -r inclus; do
    [ -n "$inclus" ] || continue
    chemin="deploy/my-self/snippets/$(basename "$inclus")"
    if [ -r "$chemin" ]; then
        fichiers+=("$chemin")
    else
        echo "❌ le fragment inclut « $inclus », que le dépôt ne porte pas : $chemin"
        exit 2
    fi
done < <(grep -oE 'include[[:space:]]+snippets/[A-Za-z0-9._-]+\.conf' "$FRAGMENT" | awk '{print $2}')

mapfile -t ATTENDUS < <(
    grep -hoiE '^[[:space:]]*add_header[[:space:]]+[A-Za-z-]+' "${fichiers[@]}" \
    | awk '{print tolower($2)}' | sort -u
)
if [ "${#ATTENDUS[@]}" -eq 0 ]; then
    echo "❌ aucun en-tête lu dans ${fichiers[*]} — le contrôle n'a pas de référence"
    exit 2
fi
echo "▸ ${#ATTENDUS[@]} en-tête(s) attendu(s), lus dans ${#fichiers[@]} fragment(s) : ${ATTENDUS[*]}"
echo

# Une route par bloc `location` du vhost, parce que c'est le bloc qui décide.
# ⚠️ `/demo/api/events` est un flux que le serveur tient ouvert : la requête est
# bornée dans le temps, sinon ce contrôle occuperait lui-même un ouvrier du
# groupe dédié — qui n'en compte que deux.
ROUTES=(
    "/"                             "location /"
    "/index.html"                   "bloc expires"
    "/moderate"                     "rewrite vers une page"
    "/selfrecover/"                 "410"
    "/lite.html"                    "410"
    "/selfrecover"                  "301"
    "/demo/api/session"             "flux autorisé par cette route"
    "/demo/api/events"              "flux SSE, groupe d-ouvriers dédié"
    "/demo/api/recover/me"          "file biself_recover"
    "/demo/api/moderate/me"         "file biself_api"
    "/demo/api/duo/sybil-attack"    "file biself_api, burst court"
)

manque=0
injoignable=0

for ((i = 0; i < ${#ROUTES[@]}; i += 2)); do
    route=${ROUTES[i]}
    quoi=${ROUTES[i + 1]}

    # `-I` (HEAD) suffit : un `add_header … always` est posé quel que soit le
    # code de réponse, donc un 401 ou un 405 mesure aussi bien qu'un 200.
    entetes=$(curl -sS -I --max-time 8 "https://$HOTE$route" 2>/dev/null)
    rc=$?
    if [ "$rc" -ne 0 ] || [ -z "$entetes" ]; then
        echo "  ⚠ $route — injoignable (curl rc=$rc) · $quoi"
        injoignable=1
        continue
    fi

    code=$(printf '%s' "$entetes" | awk 'toupper($1) ~ /^HTTP/ {print $2}' | tail -1)
    absents=()
    for attendu in "${ATTENDUS[@]}"; do
        printf '%s' "$entetes" | grep -qiE "^${attendu}:" || absents+=("$attendu")
    done

    if [ "${#absents[@]}" -eq 0 ]; then
        echo "  ✓ $route — $code, les ${#ATTENDUS[@]} en-têtes · $quoi"
    else
        manque=1
        echo "  ✗ $route — $code, MANQUE : ${absents[*]} · $quoi"
    fi

    # Le rythme d'une prod publique : une requête, puis la suivante. Les files
    # de ce vhost tolèrent bien plus, mais rien n'oblige ce contrôle à s'en
    # approcher.
    sleep 0.3
done

echo
if [ "$manque" -eq 1 ]; then
    echo "✗ un bloc location annule les en-têtes du serveur sur au moins une route."
    echo "  Réinclure le fragment dans ce bloc, ou remplacer son \`add_header\` par"
    echo "  une directive qui n'en est pas une (\`expires\`)."
    exit 1
fi
if [ "$injoignable" -eq 1 ]; then
    echo "❌ au moins une route n'a pas répondu : le contrôle n'est pas établi."
    exit 2
fi
echo "✓ chaque famille de routes sort avec ses ${#ATTENDUS[@]} en-têtes."
