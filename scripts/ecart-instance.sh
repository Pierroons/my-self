#!/usr/bin/env bash
# Écart entre ce qui est versionné et ce qui est servi.
#
# 🔑 **Pourquoi ce script existe.** Un dépôt dit ce qu'on a voulu servir ; il ne
# dit jamais ce qui est servi. Les deux divergent en silence et dans les deux
# sens : un correctif commité et jamais déployé, un fichier écrit sur place et
# jamais remonté, une arborescence remplacée dont l'ancienne reste en ligne.
# Aucun de ces cas ne produit de symptôme — tout rend « OK ».
#
# 🔑 **Un dépôt peut alimenter plusieurs destinations.** Constaté le 19/08/2026 :
# la première version ne comparait qu'un seul chemin et rendait « rien à
# signaler » sur la seule divergence qui comptait — les outils exécutés par cron
# vivent ailleurs que le code servi par le frontal, et le correctif d'un verrou
# posé trois jours plus tôt n'y était jamais arrivé. Un comparateur ne voit que
# le périmètre qu'on lui donne ; c'est exactement le défaut qu'il doit attraper.
#
# Quatre écarts, qui n'ont pas la même gravité :
#   DIVERGENT  présent des deux côtés, contenu différent      → sort en 1
#   FIGÉ       versionné, hors périmètre, et pourtant présent → à décider
#   ABSENT     versionné, jamais arrivé                       → parfois normal
#   ORPHELIN   présent là-bas, absent du dépôt                → à lire
set -uo pipefail

CONFIG="${MYSELF_INSTANCE:-$HOME/.config/selfopsec/instance.map}"
DOMAINES="${MYSELF_DOMAINES:-$HOME/.config/selfopsec/domaines.map}"

# L'hôte et les chemins de destination vivent hors dépôt, avec les motifs de
# l'audit OPSEC et la table des domaines : l'adresse d'une machine n'a pas à
# être publiée avec le code qui la vérifie.
[ -r "$CONFIG" ] || { cat >&2 <<AIDE
❌ Configuration d'instance introuvable : $CONFIG
   Une ligne d'hôte, puis une ligne par destination :
       hote   utilisateur@machine-ou-alias-ssh
       sert   .                          /chemin/servi/par/le/frontal
       sert   un/sous-dossier/du/depot   /autre/chemin/sur/la/machine
   Le préfixe « . » désigne la racine du dépôt. Pour un fichier couvert par
   plusieurs destinations, chacune est vérifiée — un même script peut vivre à
   deux endroits, dont un seul s'exécute.
   Ou pointe ailleurs avec MYSELF_INSTANCE=/chemin/vers/la/config
AIDE
exit 1; }

HOTE=""; PREFIXES=(); CIBLES=()
while read -r cle a b _; do
    case "$cle" in ''|'#'*) continue;; esac
    case "$cle" in
        hote)   HOTE="$a" ;;
        sert)   [ -n "${b:-}" ] && { PREFIXES+=("$a"); CIBLES+=("$b"); } ;;
        racine) PREFIXES+=("."); CIBLES+=("$a") ;;   # ancienne forme, une seule racine
    esac
done < "$CONFIG"
[ -n "$HOTE" ] || { echo "❌ $CONFIG doit définir hote" >&2; exit 1; }
[ "${#PREFIXES[@]}" -gt 0 ] || { echo "❌ $CONFIG doit définir au moins une destination" >&2; exit 1; }

cd "$(git rev-parse --show-toplevel)" || exit 1

# Mêmes exclusions que le déploiement. ⚠️ `--exclude=tools` sans barre matche
# tout dossier de ce nom, à tout niveau — pas seulement à la racine.
EXCLUS='(^|/)(\.github|_perso|tools|scripts|\.claude|deploy|node_modules)(/|$)'
ATTENDU="$(git rev-parse --show-toplevel)/scripts/ecart-attendu.txt"

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

# Un motif impossible sert de liste vide : `grep -f` sur un fichier vide accepte
# TOUT, ce qui classerait chaque divergence en « attendu ». Le défaut le plus
# coûteux serait ici, et il ne se verrait pas.
printf '$^\n' > "$TMP/motifs-attendus"
[ -r "$ATTENDU" ] && cut -f1 "$ATTENDU" | grep -vE '^\s*(#|$)' >> "$TMP/motifs-attendus"

SEDS=()
if [ -r "$DOMAINES" ]; then
    while read -r placeholder domaine _; do
        case "$placeholder" in ''|'#'*) continue;; esac
        case "$placeholder$domaine" in *[!a-zA-Z0-9.-]*) continue;; esac
        [ -n "$domaine" ] && SEDS+=("-e" "s/$placeholder/$domaine/g")
    done < "$DOMAINES"
fi

# Empreinte locale, placeholders concrétisés comme le fait le déploiement. Sans
# ce rejeu, tout fichier substitué sortirait divergent et le rapport, illisible.
empreinte() {
    if [ "${#SEDS[@]}" -gt 0 ] && [[ "$1" =~ \.(html|php|js|json)$ ]]; then
        sed "${SEDS[@]}" -- "$1" | sha256sum | cut -d' ' -f1
    else
        sha256sum -- "$1" | cut -d' ' -f1
    fi
}

git ls-files -z | tr '\0' '\n' | sort > "$TMP/versionnes"
divergents=0; figes=0; absents=0; orphelins=0
: > "$TMP/rapport"

for i in "${!PREFIXES[@]}"; do
    prefixe="${PREFIXES[$i]}"; cible="${CIBLES[$i]}"
    echo "▸ ${prefixe} → ${cible}"

    if [ "$prefixe" = "." ]; then
        grep -vE "$EXCLUS" "$TMP/versionnes" > "$TMP/couverts"
        grep -E  "$EXCLUS" "$TMP/versionnes" > "$TMP/hors-perimetre"
    else
        # Une destination explicite l'emporte sur les exclusions : si on la
        # déclare, c'est qu'on veut la vérifier, exclue du rsync ou non.
        grep -E "^${prefixe%/}/" "$TMP/versionnes" > "$TMP/couverts" || true
        : > "$TMP/hors-perimetre"
    fi
    nb="$(wc -l < "$TMP/couverts")"
    [ "$nb" -gt 0 ] || { echo "   aucun fichier versionné sous ce préfixe"; continue; }

    # Chemin relatif à la destination : le sous-dossier du dépôt disparaît.
    : > "$TMP/relatifs"; : > "$TMP/local"
    while IFS= read -r f; do
        [ -f "$f" ] || continue
        if [ "$prefixe" = "." ]; then rel="$f"; else rel="${f#"${prefixe%/}"/}"; fi
        printf '%s\n' "$rel" >> "$TMP/relatifs"
        printf '%s  %s\n' "$(empreinte "$f")" "$rel" >> "$TMP/local"
    done < "$TMP/couverts"

    if ! ssh -o ConnectTimeout=10 "$HOTE" "cd '$cible' 2>/dev/null || exit 3
        while IFS= read -r f; do
            if [ -f \"\$f\" ]; then sha256sum -- \"\$f\"; else echo \"ABSENT  \$f\"; fi
        done" < "$TMP/relatifs" > "$TMP/distant" 2>/dev/null; then
        echo "   ⚠ injoignable, ou ${cible} inexistant — destination sautée"
        continue
    fi
    printf '   %s fichier(s) comparé(s)\n' "$nb"

    while IFS= read -r ligne; do
        h_local="${ligne%%  *}"; rel="${ligne#*  }"
        ligne_d="$(grep -F -m1 -- "  $rel" "$TMP/distant" 2>/dev/null || true)"
        [ -n "$ligne_d" ] || continue
        h_dist="${ligne_d%%  *}"
        if [ "$prefixe" = "." ]; then origine="$rel"; else origine="${prefixe%/}/$rel"; fi
        if [ "$h_dist" = "ABSENT" ]; then
            absents=$((absents + 1))
            printf 'ABSENT     %s → %s\n' "$origine" "$cible" >> "$TMP/rapport"
        elif [ "$h_local" != "$h_dist" ]; then
            if grep -qE -f "$TMP/motifs-attendus" <<< "$origine"; then
                printf 'ATTENDU    %s → %s\n' "$origine" "$cible" >> "$TMP/rapport"
            else
                divergents=$((divergents + 1))
                printf 'DIVERGENT  %s → %s\n' "$origine" "$cible" >> "$TMP/rapport"
            fi
        fi
    done < "$TMP/local"

    # Hors périmètre de déploiement, et pourtant là-bas : le fichier vit sur
    # l'instance et plus aucun déploiement ne le mettra à jour.
    if [ -s "$TMP/hors-perimetre" ]; then
        while IFS= read -r f; do
            figes=$((figes + 1)); printf 'FIGÉ       %s → %s\n' "$f" "$cible" >> "$TMP/rapport"
        done < <(ssh -o ConnectTimeout=10 "$HOTE" "cd '$cible' 2>/dev/null || exit 3
            while IFS= read -r f; do [ -f \"\$f\" ] && echo \"\$f\"; done" \
            < "$TMP/hors-perimetre" 2>/dev/null || true)
    fi

    # Le sens inverse : ce que la destination porte et que le dépôt ignore.
    while IFS= read -r f; do
        [ -n "$f" ] || continue
        orphelins=$((orphelins + 1)); printf 'ORPHELIN   %s → %s\n' "$f" "$cible" >> "$TMP/rapport"
    done < <(ssh -o ConnectTimeout=10 "$HOTE" "find '$cible' -type f \
        \\( -name '*.php' -o -name '*.html' -o -name '*.js' -o -name '*.sql' -o -name '*.sh' -o -name '*.py' \\) \
        -printf '%P\n' 2>/dev/null" 2>/dev/null | sort | comm -23 - "$TMP/relatifs" | head -40)
done

echo
sort "$TMP/rapport" 2>/dev/null | grep -v '^ORPHELIN' || true
grep '^ORPHELIN' "$TMP/rapport" 2>/dev/null | head -20 || true
[ -s "$TMP/rapport" ] || echo "Aucun écart."

echo
printf '── %s divergent(s) · %s figé(s) · %s absent(s) · %s orphelin(s)\n' \
    "$divergents" "$figes" "$absents" "$orphelins"

if [ "$divergents" -gt 0 ]; then
    echo "✗ Le dépôt et l'instance ne disent pas la même chose."
    exit 1
fi
echo "✓ Rien ne diverge. Figés, absences et orphelins se lisent, ils ne se corrigent pas d'office."
