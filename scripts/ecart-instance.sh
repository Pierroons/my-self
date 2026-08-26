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
#   ABSENT     versionné, jamais arrivé                       → sort en 1
#   ORPHELIN   présent là-bas, absent du dépôt                → à lire
#
# 🔑 **Une destination injoignable n'est pas une destination sans écart.** Le
# `continue` qui la sautait n'incrémentait aucun compteur : si toutes étaient
# sautées, la fin rendait « Rien ne diverge » et sortait en 0 — le script écrit
# pour attraper le faux vert en produisait un. Cas réel : une ligne `sert` qui
# pointe un chemin renommé côté instance. Sort en 2, comme toute configuration
# qui empêche de mesurer.
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

HOTE=""; PREFIXES=(); CIBLES=(); SAUTEES=0
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

# ── Le périmètre se lit chez le déploiement, il ne se recopie pas ────────────
#
# 🔑 Cette liste était écrite ici en dur, sous un commentaire qui affirmait
# « mêmes exclusions que le déploiement ». Elle en portait sept motifs sur
# trente et un : ni `tests`, ni `docs`, ni `mcp`, ni surtout `*.md`. La sonde
# comparait 337 fichiers là où l'assemblage en pose 216, et rendait 103 lignes
# d'écart dont pas une ne décrivait un problème — mesuré le 24/08/2026. Un
# rapport que personne ne peut lire ne protège rien.
#
# `deploy/my-self/deploy.sh` est l'autorité. Ses quatre tableaux sont lus ici,
# jamais recopiés : le jour où une exclusion y naît, la sonde la connaît.
DEPLOY="$(git rev-parse --show-toplevel)/deploy/my-self/deploy.sh"
[ -r "$DEPLOY" ] || { echo "❌ Script de déploiement illisible : $DEPLOY" >&2; exit 1; }

lire_tableau() {   # lire_tableau <NOM> → les chaînes entre guillemets du tableau
    awk -v nom="$1" '
        index($0, nom "=(") == 1 { dedans = 1 }
        dedans {
            ligne = $0; sub(/#.*/, "", ligne)
            while (match(ligne, /"[^"]*"/)) {
                print substr(ligne, RSTART + 1, RLENGTH - 2)
                ligne = substr(ligne, RSTART + RLENGTH)
            }
            if (ligne ~ /\)/) dedans = 0
        }
    ' "$DEPLOY"
}

# Un motif rsync devient un fragment d'expression régulière. Trois formes, et
# c'est toute la sémantique employée ici : un nom simple vaut à n'importe quel
# niveau, un motif ouvert par une barre part de la racine, `*` ne franchit pas
# de barre. Vérifié contre l'assemblage réel, qui pose les mêmes 216 fichiers.
motif_vers_regex() {
    local m="$1" r
    r=$(printf '%s' "$m" | sed -e 's|\.|\\.|g' -e 's|\*|[^/]*|g')
    case "$m" in
        /*)   printf '^%s' "${r#/}" ;;
        */*)  printf '(^|/)%s' "$r" ;;
        *)    printf '(^|/)%s(/|$)' "$r" ;;
    esac
}

# ⚠️ Chaque tableau se contrôle pour lui-même. Un plancher global laissait
# disparaître le plus petit sans un mot : `ETAT_INSTANCE` renommé, ce sont
# `storage/*` et `/demo/lab/data/*` qui rentrent dans le périmètre — donc les
# sels et clés propres au déploiement du lab, comparés comme des fichiers
# ordinaires. Vingt-neuf motifs sur trente et un passaient le seuil.
FRAGMENTS=()
for tableau in EXCLUS ETAT_INSTANCE EXCLUS_MOTIF; do
    lus=0
    while IFS= read -r motif; do
        [ -n "$motif" ] || continue
        FRAGMENTS+=("$(motif_vers_regex "$motif")"); lus=$((lus + 1))
    done < <(lire_tableau "$tableau")
    [ "$lus" -gt 0 ] || {
        echo "❌ Tableau $tableau vide ou introuvable dans $DEPLOY — lecture en échec." >&2
        exit 1; }
done

# ⚠️ Une lecture qui échoue ne doit pas passer pour un périmètre. Zéro motif
# donnerait un regex vide, que `grep -vE` accepte en ne filtrant rien : le
# dépôt entier redeviendrait le périmètre et la sonde crierait sur 421 fichiers.
# Le défaut symétrique — un regex qui attrape tout — viderait le périmètre et
# rendrait vert sans avoir rien comparé. Les deux se voient ici.
[ "${#FRAGMENTS[@]}" -ge 20 ] || {
    echo "❌ ${#FRAGMENTS[@]} motif(s) d'exclusion lus dans $DEPLOY — lecture en échec." >&2
    exit 1; }
EXCLUS="$(IFS='|'; printf '%s' "${FRAGMENTS[*]}")"

# Les gardes reviennent dans le périmètre malgré une exclusion : `directives.md`
# tombe sous `*.md`, et le serveur le sert pourtant par un `location` nommé.
GARDES_FRAGMENTS=()
while IFS= read -r garde; do
    [ -n "$garde" ] && GARDES_FRAGMENTS+=("^${garde//./\\.}$")
done < <(lire_tableau GARDES)
# ⚠️ Aucune garde lue ne veut pas dire « aucune garde ». Le repli silencieux sur
# un motif impossible sortait `directives.md` du périmètre : le fichier que le
# serveur sert par un `location` nommé cessait d'être comparé, et sa divergence
# passait de rouge à vert. Les deux gardes existent, leur absence est une panne.
[ "${#GARDES_FRAGMENTS[@]}" -gt 0 ] || {
    echo "❌ Tableau GARDES vide ou introuvable dans $DEPLOY — lecture en échec." >&2
    exit 1; }
GARDES_RE="$(IFS='|'; printf '%s' "${GARDES_FRAGMENTS[*]}")"
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
        grep -vE "$EXCLUS" "$TMP/versionnes" > "$TMP/couverts" || true
        grep -E  "$GARDES_RE" "$TMP/versionnes" >> "$TMP/couverts" || true
        sort -u -o "$TMP/couverts" "$TMP/couverts"
        grep -E  "$EXCLUS" "$TMP/versionnes" | grep -vE "$GARDES_RE" > "$TMP/hors-perimetre" || true
    else
        # Une destination explicite l'emporte sur les exclusions : si on la
        # déclare, c'est qu'on veut la vérifier, exclue du rsync ou non.
        grep -E "^${prefixe%/}/" "$TMP/versionnes" > "$TMP/couverts" || true
        : > "$TMP/hors-perimetre"
    fi
    nb="$(wc -l < "$TMP/couverts")"
    # ⚠️ Zéro fichier sous le préfixe n'est pas « rien ne diverge », c'est « rien
    # n'a été mesuré ». Une faute de frappe dans instance.map, ou un dossier
    # renommé dans le dépôt, éteignait la destination et le verdict restait vert
    # — le défaut exact corrigé pour la destination injoignable le 20/08/2026,
    # à deux lignes d'ici, et laissé entier à cet endroit.
    [ "$nb" -gt 0 ] || {
        echo "   ⚠ aucun fichier versionné sous ce préfixe — destination NON comparée"
        SAUTEES=$((SAUTEES + 1)); continue; }

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
        echo "   ⚠ injoignable, ou ${cible} inexistant — destination NON comparée"
        SAUTEES=$((SAUTEES + 1))
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
            # 🔑 Une absence n'est pas moins grave qu'une divergence : le fichier
            # versionné que la destination devrait porter n'y est pas, donc ce qui
            # est servi n'est plus ce qui est versionné. Le verdict la rangeait
            # pourtant parmi ce qui « se lit » et sortait en 0. Durcie le
            # 24/08/2026, au moment où le compteur venait de tomber à zéro : une
            # absence voulue se déclare comme une divergence voulue, dans
            # ecart-attendu.txt, et elle reste affichée.
            if grep -qE -f "$TMP/motifs-attendus" <<< "$origine"; then
                printf 'ATTENDU    %s → %s (absent, déclaré)\n' "$origine" "$cible" >> "$TMP/rapport"
            else
                absents=$((absents + 1))
                printf 'ABSENT     %s → %s\n' "$origine" "$cible" >> "$TMP/rapport"
            fi
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
    #
    # 🔑 Se comparer au seul périmètre comptait douze fichiers deux fois — une
    # fois FIGÉ, une fois ORPHELIN. Un test versionné qu'un ancien déploiement a
    # laissé sur la machine n'est pas inconnu du dépôt : il en sort, et c'est
    # « figé » qui le décrit. Un orphelin est ce dont le dépôt n'a aucune trace.
    cat "$TMP/relatifs" "$TMP/hors-perimetre" 2>/dev/null | sort -u > "$TMP/connus"
    while IFS= read -r f; do
        [ -n "$f" ] || continue
        orphelins=$((orphelins + 1)); printf 'ORPHELIN   %s → %s\n' "$f" "$cible" >> "$TMP/rapport"
    done < <(ssh -o ConnectTimeout=10 "$HOTE" "find '$cible' -type f \
        \\( -name '*.php' -o -name '*.html' -o -name '*.js' -o -name '*.sql' -o -name '*.sh' -o -name '*.py' \\
           -o -name '.env*' -o -name '.htaccess' -o -name '*.conf' -o -name '*.ini' -o -name '*.yml' -o -name '*.yaml' \\) \\
        -printf '%P\n' 2>/dev/null" 2>/dev/null | sort | comm -23 - "$TMP/connus")
done

echo
sort "$TMP/rapport" 2>/dev/null | grep -v '^ORPHELIN' || true
grep '^ORPHELIN' "$TMP/rapport" 2>/dev/null | head -20 || true
# ⚠️ Une liste coupée sans le dire se lit comme une liste complète. Le `head`
# garde le rapport lisible ; la ligne ci-dessous garde le compte honnête.
[ "$orphelins" -gt 20 ] && printf '           … et %s orphelin(s) non affiché(s).\n' "$((orphelins - 20))"
[ -s "$TMP/rapport" ] || echo "Aucun écart."

echo
printf '── %s divergent(s) · %s figé(s) · %s absent(s) · %s orphelin(s)\n' \
    "$divergents" "$figes" "$absents" "$orphelins"

if [ "$divergents" -gt 0 ] || [ "$absents" -gt 0 ]; then
    echo "✗ Le dépôt et l'instance ne disent pas la même chose."
    exit 1
fi
# Le verdict ne porte que sur ce qui a été comparé. Le dire avant de conclure,
# sinon zéro divergence sur zéro comparaison se lit comme zéro divergence.
if [ "$SAUTEES" -gt 0 ]; then
    printf '✗ %s destination(s) non comparée(s) — verdict incomplet.\n' "$SAUTEES"
    echo "  Vérifie l'hôte et les chemins de $CONFIG."
    exit 2
fi
echo "✓ Rien ne diverge, rien ne manque. Figés et orphelins se lisent, ils ne se corrigent pas d'office."
