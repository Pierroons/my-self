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

# L'hôte et les chemins de destination vivent hors dépôt, avec les motifs de
# l'audit OPSEC : l'adresse d'une machine n'a pas à être publiée avec le code
# qui la vérifie. La table des domaines, elle, est lue dans le déploiement, qui
# la publie déjà — voir `lire_domaines`.
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
#
# ⚠️ Un motif qui ne finit pas par une barre désigne un chemin ENTIER : rsync
# n'exclut pas `selfrecover_derive.c` parce qu'il exclut `selfrecover_derive`.
# Sans ancre de fin, les deux sources du dérivateur LUKS sortaient « figées »
# alors qu'elles sont déployées et identiques (audit du 27/09/2026).
motif_vers_regex() {
    local m="$1" r fin='(/|$)'
    r=$(printf '%s' "$m" | sed -e 's|\.|\\.|g' -e 's|\*|[^/]*|g')
    case "$m" in */) fin='' ;; esac
    case "$m" in
        /*)   printf '^%s%s' "${r#/}" "$fin" ;;
        */*)  printf '(^|/)%s%s' "$r" "$fin" ;;
        *)    printf '(^|/)%s(/|$)' "$r" ;;
    esac
}

# ⚠️ Chaque tableau se contrôle pour lui-même. Un plancher global laissait
# disparaître le plus petit sans un mot : `ETAT_INSTANCE` renommé, c'est
# `storage/*` qui rentre dans le périmètre — donc les données vivantes de
# l'instance, comparées comme des fichiers ordinaires.
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

# ⚠️ Les fichiers d'`IGNORES_SERVIS` sont gitignorés : absents de `git ls-files`,
# donc du périmètre comme du hors-périmètre, donc de `connus`. Non filtrés, ils
# sortiraient en ORPHELIN alors qu'ils sont servis **délibérément** — et une sonde
# qui crie à tort emporte ses constats utiles avec elle.
#
# 🔑 Deux gardes, parce qu'un tableau vide et un tableau disparu ne se valent pas.
# Vide, il est déclaré : rien d'ignoré n'est servi, et `^$` ne filtre que les
# lignes vides. Disparu, il donnerait un motif vide, que `grep -vE ""` accepte en
# filtrant TOUT : zéro orphelin, et le verdict l'annoncerait comme une mesure.
grep -q '^IGNORES_SERVIS=(' "$DEPLOY" || {
    echo "❌ Tableau IGNORES_SERVIS introuvable dans $DEPLOY — lecture en échec." >&2
    exit 1; }
SERVIS_FRAGMENTS=()
while IFS= read -r prefixe; do
    [ -n "$prefixe" ] || continue
    SERVIS_FRAGMENTS+=("^${prefixe//./\\.}")
done < <(lire_tableau IGNORES_SERVIS)
SERVIS_RE='^$'
[ "${#SERVIS_FRAGMENTS[@]}" -eq 0 ] || SERVIS_RE="$(IFS='|'; printf '%s' "${SERVIS_FRAGMENTS[*]}")"

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

# 🔑 La table des domaines est celle que le déploiement substitue, lue chez
# lui. Recopiée dans une configuration privée, elle avait perdu `ctf` : les huit
# liens de la page d'accueil sortaient « DIVERGENT » alors que dépôt et instance
# disaient la même chose. Les surcharges suivent celles du déploiement.
lire_domaines() {
    if [ -n "${MYSELF_DOMAINES:-}" ]; then cat -- "$MYSELF_DOMAINES"; return; fi
    if [ -n "${MYSELF_TABLE:-}" ]; then cat -- "$MYSELF_TABLE"; return; fi
    awk "/cat <<'TABLE'/ { dedans = 1; next } /^TABLE\$/ { dedans = 0 } dedans" "$DEPLOY"
}

SEDS=(); : > "$TMP/gabarits"
while read -r placeholder domaine _; do
    case "$placeholder" in ''|'#'*) continue;; esac
    case "$placeholder$domaine" in *[!a-zA-Z0-9.-]*) continue;; esac
    [ -n "$domaine" ] && { SEDS+=("-e" "s/$placeholder/$domaine/g"); printf '%s\n' "$placeholder" >> "$TMP/gabarits"; }
done < <(lire_domaines)
# Sans table, chaque fichier substitué sortirait divergent : l'erreur se dit.
[ "${#SEDS[@]}" -gt 0 ] || { echo "❌ Table des domaines vide — lue dans $DEPLOY" >&2; exit 1; }

# Empreinte locale, placeholders concrétisés comme le fait le déploiement. Sans
# ce rejeu, tout fichier substitué sortirait divergent et le rapport, illisible.
# Le déploiement substitue dans TOUT fichier qui porte un gabarit, quelle que soit
# son extension (`grep -rlF` puis `sed -i`) : le rejeu suit la même règle. Mais
# seulement pour l'arbre qu'il assemble (préfixe `.`) : les outils du planificateur
# partent bruts, et `check_fraicheur.sh` GARDE exprès son domaine d'exemple, que
# la configuration de l'instance remplace à l'exécution.
empreinte() {
    if [ "$prefixe" = "." ] && [ "${#SEDS[@]}" -gt 0 ] && grep -qF -f "$TMP/gabarits" -- "$1"; then
        sed "${SEDS[@]}" -- "$1" | sha256sum | cut -d' ' -f1
    else
        sha256sum -- "$1" | cut -d' ' -f1
    fi
}

git ls-files -z | tr '\0' '\n' | sort > "$TMP/versionnes"
divergents=0; figes=0; absents=0; orphelins=0
# ⚠️ Ce qui n'a pas pu être mesuré se compte à part, et entre dans le verdict.
# Sans lui, un fichier dont le distant ne dit rien — `sha256sum` refusé sur un
# 0600, lien cassé, chemin devenu répertoire — sortait de la boucle en silence,
# et le script concluait « rien ne diverge » sur des fichiers qu'il n'avait pas lus.
non_mesures=0
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

    # ⚠️ `sha256sum` qui échoue sur un fichier présent n'écrivait rien : stderr
    # est jeté, stdout reste muet, et le fichier disparaissait de la comparaison
    # sans laisser de trace. Un `0600` appartenant à un autre compte suffisait.
    if ! ssh -o ConnectTimeout=10 "$HOTE" "cd '$cible' 2>/dev/null || exit 3
        while IFS= read -r f; do
            if [ -f \"\$f\" ]; then sha256sum -- \"\$f\" 2>/dev/null || echo \"ILLISIBLE  \$f\"
            else echo \"ABSENT  \$f\"; fi
        done" < "$TMP/relatifs" > "$TMP/distant" 2>/dev/null; then
        echo "   ⚠ injoignable, ou ${cible} inexistant — destination NON comparée"
        SAUTEES=$((SAUTEES + 1))
        continue
    fi
    # ⚠️ Le compte se prend sur ce qui a RÉELLEMENT été soumis à comparaison, pas
    # sur le périmètre : la boucle ci-dessus écarte ce que `[ -f ]` refuse, un
    # lien vers un répertoire par exemple. L'écart était d'un fichier sur 239, et
    # un périmètre entièrement absent du worktree aurait annoncé 239 comparaisons
    # en n'en faisant aucune.
    printf '   %s fichier(s) comparé(s)\n' "$(wc -l < "$TMP/relatifs")"

    while IFS= read -r ligne; do
        h_local="${ligne%%  *}"; rel="${ligne#*  }"
        if [ "$prefixe" = "." ]; then origine="$rel"; else origine="${prefixe%/}/$rel"; fi
        # ⚠️ L'appariement se fait sur l'égalité du chemin, pas sur une
        # sous-chaîne : `grep -F "  x.js"` trouvait la ligne de `x.json` et
        # comparait le hash d'un autre fichier, donc une divergence inventée.
        ligne_d="$(awk -F'  ' -v r="$rel" '$2 == r { print; exit }' "$TMP/distant" 2>/dev/null || true)"
        if [ -z "$ligne_d" ]; then
            non_mesures=$((non_mesures + 1))
            printf 'NON MESURÉ %s → %s (le distant n'"'"'a rien répondu)\n' "$origine" "$cible" >> "$TMP/rapport"
            continue
        fi
        h_dist="${ligne_d%%  *}"
        if [ "$h_dist" = "ILLISIBLE" ]; then
            non_mesures=$((non_mesures + 1))
            printf 'NON MESURÉ %s → %s (illisible sur la destination)\n' "$origine" "$cible" >> "$TMP/rapport"
        elif [ "$h_dist" = "ABSENT" ]; then
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
    # ⚠️ `|| true` avalait l'échec de cette mesure : hôte injoignable entre-temps,
    # cible renommée, et la sonde annonçait zéro figé sans avoir pu le demander.
    # 🔑 Le `exit 0` distant est nécessaire : `while … do [ -f ] && echo; done`
    # rend le code du DERNIER corps exécuté, donc 1 si le dernier fichier de la
    # liste est absent — un succès aurait été lu comme une panne.
    if [ -s "$TMP/hors-perimetre" ]; then
        if ssh -o ConnectTimeout=10 "$HOTE" "cd '$cible' 2>/dev/null || exit 3
            while IFS= read -r f; do [ -f \"\$f\" ] && echo \"\$f\"; done
            exit 0" < "$TMP/hors-perimetre" > "$TMP/figes-distant" 2>/dev/null; then
            while IFS= read -r f; do
                figes=$((figes + 1)); printf 'FIGÉ       %s → %s\n' "$f" "$cible" >> "$TMP/rapport"
            done < "$TMP/figes-distant"
        else
            echo "   ⚠ figés NON mesurés sur ${cible}"
            non_mesures=$((non_mesures + 1))
        fi
    fi

    # Le sens inverse : ce que la destination porte et que le dépôt ignore.
    #
    # 🔑 Se comparer au seul périmètre comptait douze fichiers deux fois — une
    # fois FIGÉ, une fois ORPHELIN. Un test versionné qu'un ancien déploiement a
    # laissé sur la machine n'est pas inconnu du dépôt : il en sort, et c'est
    # « figé » qui le décrit. Un orphelin est ce dont le dépôt n'a aucune trace.
    cat "$TMP/relatifs" "$TMP/hors-perimetre" 2>/dev/null | sort -u > "$TMP/connus"
    # Même défaut que pour les figés : un `find` qui n'a pas pu tourner rendait
    # zéro orphelin, et le verdict final l'annonçait comme une mesure.
    if ssh -o ConnectTimeout=10 "$HOTE" "find '$cible' -type f \
        \\( -name '*.php' -o -name '*.html' -o -name '*.js' -o -name '*.sql' -o -name '*.sh' -o -name '*.py' \\
           -o -name '.env*' -o -name '.htaccess' -o -name '*.conf' -o -name '*.ini' -o -name '*.yml' -o -name '*.yaml' \\) \\
        -printf '%P\n' 2>/dev/null" > "$TMP/find-distant" 2>/dev/null; then
        while IFS= read -r f; do
            [ -n "$f" ] || continue
            orphelins=$((orphelins + 1)); printf 'ORPHELIN   %s → %s\n' "$f" "$cible" >> "$TMP/rapport"
        done < <(sort "$TMP/find-distant" | grep -vE "$SERVIS_RE" | comm -23 - "$TMP/connus")
    else
        echo "   ⚠ orphelins NON mesurés sur ${cible}"
        non_mesures=$((non_mesures + 1))
    fi
done

echo
sort "$TMP/rapport" 2>/dev/null | grep -v '^ORPHELIN' || true
grep '^ORPHELIN' "$TMP/rapport" 2>/dev/null | head -20 || true
# ⚠️ Une liste coupée sans le dire se lit comme une liste complète. Le `head`
# garde le rapport lisible ; la ligne ci-dessous garde le compte honnête.
[ "$orphelins" -gt 20 ] && printf '           … et %s orphelin(s) non affiché(s).\n' "$((orphelins - 20))"
[ -s "$TMP/rapport" ] || echo "Aucun écart."

echo
printf '── %s divergent(s) · %s figé(s) · %s absent(s) · %s orphelin(s) · %s non mesuré(s)\n' \
    "$divergents" "$figes" "$absents" "$orphelins" "$non_mesures"

if [ "$divergents" -gt 0 ] || [ "$absents" -gt 0 ]; then
    echo "✗ Le dépôt et l'instance ne disent pas la même chose."
    exit 1
fi
# Le verdict ne porte que sur ce qui a été comparé. Le dire avant de conclure,
# sinon zéro divergence sur zéro comparaison se lit comme zéro divergence.
if [ "$SAUTEES" -gt 0 ] || [ "$non_mesures" -gt 0 ]; then
    [ "$SAUTEES" -gt 0 ] && printf '✗ %s destination(s) non comparée(s) — verdict incomplet.\n' "$SAUTEES"
    [ "$non_mesures" -gt 0 ] && printf '✗ %s mesure(s) manquante(s) — verdict incomplet.\n' "$non_mesures"
    echo "  Vérifie l'hôte et les chemins de $CONFIG."
    exit 2
fi
echo "✓ Rien ne diverge, rien ne manque. Figés et orphelins se lisent, ils ne se corrigent pas d'office."
