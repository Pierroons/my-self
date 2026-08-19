#!/usr/bin/env bash
# Écart entre ce qui est versionné et ce qui est servi.
#
# 🔑 **Pourquoi ce script existe.** Un dépôt dit ce qu'on a voulu servir ; il ne
# dit jamais ce qui est servi. Les deux divergent silencieusement, et dans les
# deux sens : un correctif commité mais jamais déployé, un fichier édité sur
# place et jamais remonté, une arborescence remplacée dont l'ancienne reste en
# ligne. Aucun de ces cas ne produit de symptôme — tout rend « OK ».
#
# Trois écarts distincts, qui n'ont pas la même gravité :
#   DIVERGENT  le fichier existe des deux côtés et son contenu diffère
#   ABSENT     versionné, jamais arrivé à destination
#   ORPHELIN   servi sans exister dans le dépôt
#
# Seul DIVERGENT fait échouer : une absence est parfois légitime (module servi
# ailleurs), un orphelin est parfois voulu (données, cache, artefact de build).
#
# ⚠️ Ce script rejoue la substitution des placeholders avant de comparer. Sans
# ça, tout fichier concrétisé au déploiement sortirait divergent et le rapport
# serait noyé — un contrôle qui crie sur tout ne se lit plus.
set -uo pipefail

CONFIG="${MYSELF_INSTANCE:-$HOME/.config/selfopsec/instance.map}"
DOMAINES="${MYSELF_DOMAINES:-$HOME/.config/selfopsec/domaines.map}"

# L'hôte et le chemin de destination vivent hors dépôt : les écrire ici les
# publierait, comme les motifs de l'audit OPSEC et la table des domaines.
[ -r "$CONFIG" ] || { cat >&2 <<AIDE
❌ Configuration d'instance introuvable : $CONFIG
   Deux lignes, clé puis valeur :
       hote     utilisateur@machine-ou-alias-ssh
       racine   /chemin/servi/sur/l/instance
   Ou pointe ailleurs avec MYSELF_INSTANCE=/chemin/vers/la/config
AIDE
exit 1; }

HOTE=""; RACINE=""
while read -r cle valeur _; do
    case "$cle" in ''|'#'*) continue;; esac
    case "$cle" in
        hote)   HOTE="$valeur" ;;
        racine) RACINE="$valeur" ;;
    esac
done < "$CONFIG"
[ -n "$HOTE" ] && [ -n "$RACINE" ] || { echo "❌ $CONFIG doit définir hote ET racine" >&2; exit 1; }

cd "$(git rev-parse --show-toplevel)" || exit 1

# Mêmes exclusions que le déploiement. ⚠️ `--exclude=tools` sans barre matche
# tout dossier de ce nom, à tout niveau — pas seulement à la racine. Un fichier
# exclu mais présent sur l'instance n'est pas hors sujet : il vit là-bas et le
# déploiement ne le met plus à jour. C'est la catégorie FIGÉ.
EXCLUS='(^|/)(\.github|_perso|tools|scripts|\.claude|deploy|node_modules)(/|$)'
ATTENDU="$(git rev-parse --show-toplevel)/scripts/ecart-attendu.txt"

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

echo "▸ Fichiers versionnés destinés à l'instance"
git ls-files -z | tr '\0' '\n' | grep -vE "$EXCLUS" | sort > "$TMP/versionnes"
printf '   %s fichier(s)\n' "$(wc -l < "$TMP/versionnes")"

echo "▸ Empreintes locales, placeholders concrétisés"
# La substitution se fait sur une copie : l'arbre de travail n'est pas touché.
SEDS=()
if [ -r "$DOMAINES" ]; then
    while read -r placeholder domaine _; do
        case "$placeholder" in ''|'#'*) continue;; esac
        case "$placeholder$domaine" in *[!a-zA-Z0-9.-]*) continue;; esac
        [ -n "$domaine" ] && SEDS+=("-e" "s/$placeholder/$domaine/g")
    done < "$DOMAINES"
    printf '   %s substitution(s) rejouée(s)\n' "${#SEDS[@]}"
else
    echo "   ⚠ table des domaines absente — les fichiers concrétisés sortiront divergents"
fi

while IFS= read -r f; do
    [ -f "$f" ] || continue
    if [ "${#SEDS[@]}" -gt 0 ] && [[ "$f" =~ \.(html|php|js|json)$ ]]; then
        sed "${SEDS[@]}" -- "$f" | sha256sum | cut -d' ' -f1 | tr -d '\n'
    else
        sha256sum -- "$f" | cut -d' ' -f1 | tr -d '\n'
    fi
    printf '  %s\n' "$f"
done < "$TMP/versionnes" | sort -k2 > "$TMP/local"

echo "▸ Empreintes de l'instance"
# Un seul aller-retour : la liste part sur l'entrée standard.
if ! ssh -o ConnectTimeout=10 "$HOTE" "cd '$RACINE' 2>/dev/null || exit 3
    while IFS= read -r f; do
        if [ -f \"\$f\" ]; then sha256sum -- \"\$f\"; else echo \"ABSENT  \$f\"; fi
    done" < "$TMP/versionnes" 2>/dev/null | sed 's/^\([0-9a-f]*\)  /\1  /' | sort -k2 > "$TMP/distant"; then
    echo "❌ instance injoignable, ou $RACINE inexistant" >&2; exit 1
fi
printf '   %s réponse(s)\n' "$(wc -l < "$TMP/distant")"

# Un motif impossible sert de liste vide : grep -f sur un fichier vide accepte
# TOUT, ce qui classerait chaque divergence en « attendu ». Le défaut le plus
# coûteux serait ici, et il ne se verrait pas.
printf '$^\n' > "$TMP/motifs-attendus"
[ -r "$ATTENDU" ] && cut -f1 "$ATTENDU" | grep -vE '^\s*(#|$)' >> "$TMP/motifs-attendus"

echo
divergents=0; absents=0

while IFS= read -r ligne; do
    h_local="${ligne%%  *}"; f="${ligne#*  }"
    ligne_d="$(grep -F -m1 -- "  $f" "$TMP/distant" 2>/dev/null || true)"
    [ -n "$ligne_d" ] || continue
    h_dist="${ligne_d%%  *}"
    if [ "$h_dist" = "ABSENT" ]; then
        absents=$((absents + 1)); printf 'ABSENT     %s\n' "$f" >> "$TMP/rapport"
    elif [ "$h_local" != "$h_dist" ]; then
        if grep -qE -f "$TMP/motifs-attendus" <<< "$f"; then
            printf 'ATTENDU    %s\n' "$f" >> "$TMP/rapport"
        else
            divergents=$((divergents + 1)); printf 'DIVERGENT  %s\n' "$f" >> "$TMP/rapport"
        fi
    fi
done < "$TMP/local"

echo "▸ Versionné, hors périmètre de déploiement, et pourtant présent"
git ls-files -z | tr '\0' '\n' | grep -E "$EXCLUS" | sort > "$TMP/exclus"
figes="$(ssh -o ConnectTimeout=10 "$HOTE" "cd '$RACINE' 2>/dev/null || exit 3
    while IFS= read -r f; do [ -f \"\$f\" ] && echo \"\$f\"; done" \
    < "$TMP/exclus" 2>/dev/null || true)"
nb_figes="$(printf '%s' "$figes" | grep -c . || true)"
printf '   %s figé(s)\n' "$nb_figes"

echo "▸ Servi sans être versionné"
# Le sens inverse : ce que l'instance porte et que le dépôt ignore.
orphelins="$(ssh -o ConnectTimeout=10 "$HOTE" "find '$RACINE' -type f \
    \\( -name '*.php' -o -name '*.html' -o -name '*.js' -o -name '*.sql' -o -name '*.conf' \\) \
    -printf '%P\n' 2>/dev/null" 2>/dev/null \
    | sort | comm -23 - "$TMP/versionnes" | head -40)"
nb_orphelins="$(printf '%s' "$orphelins" | grep -c . || true)"
printf '   %s orphelin(s)\n' "$nb_orphelins"

echo
if [ -s "${TMP}/rapport" ]; then
    sort "$TMP/rapport"
else
    echo "Aucun écart versionné → servi."
fi
[ "$nb_figes" -gt 0 ] && printf '%s\n' "$figes" | sed 's/^/FIGÉ       /'
[ "$nb_orphelins" -gt 0 ] && printf '%s\n' "$orphelins" | sed 's/^/ORPHELIN   /'

echo
printf '── %s divergent(s) · %s figé(s) · %s absent(s) · %s orphelin(s)\n' \
    "$divergents" "$nb_figes" "$absents" "$nb_orphelins"

if [ "$divergents" -gt 0 ]; then
    echo "✗ Le dépôt et l'instance ne disent pas la même chose."
    exit 1
fi
echo "✓ Rien ne diverge. Absences et orphelins sont à lire, pas à corriger d'office."
