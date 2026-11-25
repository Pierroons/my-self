#!/usr/bin/env bash
# Les groupes d'ouvriers PHP servis disent-ils la même chose que leurs gabarits ?
#
# 🔑 **Pourquoi ce contrôle existe.** Le `www.conf` de la machine a vécu hors du
# dépôt jusqu'au 21/11/2026 : personne n'aurait vu sa dérive, et c'est lui qui
# portait les variables d'environnement de tout ce que la machine sert en PHP.
# `deploy/my-self/deploy.sh` ne pose pas ces fichiers — comme il ne pose pas les
# vhosts —, donc un gabarit et son instance peuvent diverger sans bruit.
#
# 🔑 **Pourquoi il en lit CINQ depuis le 24/11/2026.** Le découpage a remplacé le
# groupe unique par cinq, chacun sous son identité. Resté sur un seul gabarit, ce
# contrôle aurait continué de rendre vert en ignorant quatre groupes — un vert
# dont la portée aurait été quatre fois plus petite que ce qu'on en aurait lu.
#
# 🔑 **Le nom du fichier posé se déduit de la section du gabarit** (`[justice]`
# → `justice.conf`) : sans ça, la correspondance gabarit → destination vivrait
# à deux endroits, ici et dans le README, et les deux divergeraient.
#
# ⚠️ **Il n'affiche JAMAIS la valeur d'un secret.** Toute ligne `env[...]` ou
# `php_admin_value[...]` est réduite à sa CLÉ des deux côtés avant comparaison :
# le contrôle dit « cette variable est là ou pas », jamais ce qu'elle vaut. La
# lecture entière d'un de ces fichiers a fait sortir une clé d'API le
# 19/11/2026, et elle a coûté une rotation sur trois porteurs.
#
# ⚠️ **À lancer depuis un VRAI terminal** : `sudo` de la machine a besoin d'un
# TTY (`/etc/php/8.4/fpm/pool.d/` n'est pas lisible sans privilège), et le canal
# d'un agent n'en fournit pas.
#
#   POOL_SSH_HOST=<alias> bash deploy/my-self/tests/ecart-pool-php.sh
#
# Sortie : 0 si les directives concordent partout, 1 s'il y a un écart ou un
# groupe pas encore posé, 2 si le contrôle n'a pas pu être établi — un contrôle
# qu'on ne peut pas lancer ne rend pas vert.
set -uo pipefail
cd "$(git rev-parse --show-toplevel)" || exit 2

DOSSIERS=(deploy/my-self deploy/bi-self deploy/selfdataguard deploy/selfjustice)
POOL_D=/etc/php/8.4/fpm/pool.d
HOTE=${POOL_SSH_HOST:?alias SSH de la machine qui sert (son sudo demande un mot de passe)}

T=$(mktemp -d) || exit 2
trap 'rm -rf "$T"' EXIT

mapfile -t GABARITS < <(
    for d in "${DOSSIERS[@]}"; do
        [ -d "$d" ] || continue
        find "$d" -maxdepth 1 -name 'php-fpm-*.conf' -type f | sort
    done
)
[ "${#GABARITS[@]}" -gt 0 ] || { echo "❌ aucun gabarit de groupe sous ${DOSSIERS[*]}"; exit 2; }

# Les directives actives, normalisées, SECRETS MASQUÉS.
# Le masquage se fait ici et côté distant : la valeur ne traverse jamais le
# réseau ni ce terminal.
directives() {
    sed -E 's/;.*$//' "$1" \
    | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//; s/[[:space:]]*=[[:space:]]*/ = /' \
    | grep -vE '^$' \
    | sed -E 's/^((env|php_admin_value|php_value)\[[A-Za-z_.]+\]) = .*/\1 = <MASQUE>/' \
    | sort -u
}

# ── 1. Ce que le dépôt déclare, et où chaque gabarit se pose ────────────────
echo "▸ 1. Les gabarits du dépôt — ${#GABARITS[@]}"
declare -A CIBLE_DE
for g in "${GABARITS[@]}"; do
    section=$(sed -nE 's/^[[:space:]]*\[([A-Za-z0-9._-]+)\].*/\1/p' "$g" | head -1)
    if [ -z "$section" ]; then
        echo "   ❌ $g — aucune section \`[...]\` : sa destination ne peut pas être déduite"
        exit 2
    fi
    CIBLE_DE[$g]="$POOL_D/$section.conf"
    directives "$g" > "$T/$(basename "$g").gabarit"
    printf '   %-46s → %-34s %s directive(s)\n' "${g#deploy/}" "$section.conf" \
        "$(wc -l < "$T/$(basename "$g").gabarit")"
done

# ── 2. Ce que la machine sert — une seule connexion, valeurs masquées à la source
echo "▸ 2. Les groupes servis sur $HOTE — valeurs masquées À LA SOURCE"
# 🔑 **Une seule session `ssh -t`, pas une par fichier** : chacune redemanderait
# le mot de passe de `sudo`, et un contrôle qu'on renonce à lancer ne mesure
# rien. Les fichiers sont séparés par un marqueur que le masquage ne produit pas.
liste=""
for g in "${GABARITS[@]}"; do liste+=" '${CIBLE_DE[$g]}'"; done
# shellcheck disable=SC2029  # la liste est développée ici, exprès
if ! ssh -t "$HOTE" "sudo sh -c 'for f in$liste; do echo \"===POOL \$f\"; if [ -r \"\$f\" ]; then sed -E \"s/^([[:space:]]*(env|php_admin_value|php_value)\[[A-Za-z_.]+\])[[:space:]]*=.*/\1 = <MASQUE>/\" \"\$f\"; else echo \"===ABSENT\"; fi; done'" \
        > "$T/brut.txt" 2>"$T/err.txt"; then
    echo "   ❌ lecture impossible — ni vert ni rouge, le contrôle n'est pas établi :"
    sed 's/^/   /' "$T/err.txt" | tail -3
    exit 2
fi
if ! grep -q '^===POOL ' "$T/brut.txt"; then
    echo "   ❌ aucun marqueur lu : la commande distante n'a rien rendu d'exploitable."
    exit 2
fi
# Découpe par marqueur. ⚠️ `csplit` serait plus court mais numérote ses morceaux
# dans l'ordre de lecture, pas par nom : la correspondance se perdrait.
awk -v d="$T" '
    /^===POOL /{ n=split($2, p, "/"); f=d "/" p[n] ".servi"; next }
    f { print > f }
' "$T/brut.txt" || { echo "   ❌ découpe des groupes impossible"; exit 2; }

# ── 3. La comparaison, gabarit par gabarit ──────────────────────────────────
echo
ecart=0; absents=0
for g in "${GABARITS[@]}"; do
    cible="${CIBLE_DE[$g]}"
    base=$(basename "$cible")
    servi="$T/$base.servi"
    gab="$T/$(basename "$g").gabarit"

    if [ ! -s "$servi" ] || grep -q '^===ABSENT$' "$servi" 2>/dev/null; then
        echo "⏳ $base — PAS ENCORE POSÉ sur la machine."
        echo "   Ce n'est pas un défaut du gabarit : c'est le geste de pose qui reste."
        echo "   L'ordre est dans \`deploy/my-self/README.md\` — le résiduel en dernier."
        absents=$((absents + 1)); continue
    fi

    directives "$servi" > "$T/$base.norm"
    manquantes=$(comm -23 "$T/$base.norm" "$gab")
    en_trop=$(comm -13 "$T/$base.norm" "$gab")

    if [ -z "$manquantes" ] && [ -z "$en_trop" ]; then
        echo "✓ $base — le groupe servi et son gabarit portent les mêmes directives."
        continue
    fi
    ecart=1
    if [ -n "$manquantes" ]; then
        echo "✗ $base — SERVI mais absent du gabarit : le poser RETIRERAIT ceci :"
        printf '%s\n' "$manquantes" | sed 's/^/    /'
    fi
    if [ -n "$en_trop" ]; then
        echo "✗ $base — dans le gabarit mais PAS servi : la machine est en retard, ou le gabarit anticipe :"
        printf '%s\n' "$en_trop" | sed 's/^/    /'
    fi
done

echo
if [ "$ecart" -eq 0 ] && [ "$absents" -eq 0 ]; then
    echo "✓ les ${#GABARITS[@]} groupes servis et leurs gabarits portent les mêmes directives."
    echo "  ⚠️ Les VALEURS des secrets ne sont pas comparées, par construction :"
    echo "     ce contrôle dit qu'une variable est là, jamais ce qu'elle vaut."
    echo "  ⚠️ Et il ne dit rien du CLOISONNEMENT — qu'un groupe ne puisse pas lire"
    echo "     l'état d'un autre se mesure par \`cloisonnement-pools.sh\`."
    exit 0
fi
[ "$absents" -gt 0 ] && echo "⏳ $absents groupe(s) pas encore posé(s) sur la machine."
[ "$ecart" -ne 0 ] && {
    echo "⚠️ Un écart n'est pas forcément une faute : ces gabarits ne reprennent pas"
    echo "   les commentaires du paquet, et une directive au défaut n'a pas à y figurer."
    echo "   Ce qui compte est la liste ci-dessus, ligne par ligne."
}
exit 1
