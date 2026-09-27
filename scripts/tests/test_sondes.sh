#!/bin/bash
# Banc des sondes — ce que `check-paths.sh` et `ecart-instance.sh` attrapent, et
# surtout ce qu'ils laissent passer en rendant vert.
#
# 🔑 **Pourquoi ce banc existe.** `AGENTS.md` demande que chaque contrôle ait été
# vu rougir, et ces deux-là n'avaient rien : ni banc, ni canari, ni cas planté.
# Leur silence ne se distinguait donc pas d'une absence de défaut. Deux faux verts
# ont été trouvés en les relisant, dont un qui retire un fichier du périmètre
# comparé — le contrôle continuait d'annoncer « rien ne diverge » sur du code
# qu'il ne regardait plus.
#
# Chaque cas suit la même forme : le défaut redouté, puis un témoin qui prouve
# que la sonde voit encore ce qu'elle doit voir. Sans le témoin, une sonde cassée
# passerait le banc en n'attrapant plus rien.
#
# Usage : bash scripts/tests/test_sondes.sh
# Sortie : 0 si toutes les propriétés tiennent. Leur nombre se compte à
#          l'exécution — l'écrire ici le ferait mentir au prochain ajout.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
RACINE="$(cd "$ICI/../.." && pwd)"
# 🔑 Les deux chemins se surchargent, pour pouvoir rejouer le banc contre une
# version antérieure d'une sonde. C'est le seul moyen de vérifier qu'un cas
# rougit vraiment : posé sur du code déjà corrigé, il verdit sans rien prouver.
#   ECART_SOUS_TEST=$(git show <sha>:scripts/ecart-instance.sh > /tmp/x; echo /tmp/x)
CHECK_PATHS="${CHECK_PATHS_SOUS_TEST:-$RACINE/scripts/check-paths.sh}"
ECART="${ECART_SOUS_TEST:-$RACINE/scripts/ecart-instance.sh}"
for f in "$CHECK_PATHS" "$ECART"; do
    [ -r "$f" ] || { echo "sonde introuvable : $f" >&2; exit 1; }
done

BAC=$(mktemp -d)
trap 'rm -rf "$BAC"' EXIT

echecs=0 reussites=0
ok()  { echo "  ✓ $1"; reussites=$((reussites + 1)); }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

# ── 1. check-paths.sh lancé hors d'un dépôt git ─────────────────────────────
#
# `cd "$(git rev-parse --show-toplevel)" || exit 1` ne garde rien : hors dépôt,
# la substitution est vide et `cd ""` rend 0 en bash. Les `git ls-files` rendent
# vide ensuite, les quatre blocs affichent ✓, et le script sort en 0 sans avoir
# lu un seul fichier.
# ⚠️ Le code de sortie ne mesure PAS cette propriété. Hors dépôt il vaut déjà 1,
# mais par accident : le dernier bloc échoue parce que la copie de licence qu'il
# cherche n'existe pas dans le bac. Le faux vert se lit avant, dans la sortie —
# quatre blocs annoncent ✓ après n'avoir lu aucun fichier. La propriété à tenir
# est donc « aucun ✓ quand rien n'a pu être lu », pas « le code n'est pas 0 ».
echo "▸ check-paths.sh — un lancement hors dépôt ne doit annoncer aucun succès"
texte="$( (cd "$BAC" && bash "$CHECK_PATHS") 2>&1 || true )"
faux_verts=$(printf '%s\n' "$texte" | grep -c '✓' || true)
if [ "$faux_verts" -gt 0 ]; then
    nok "annonce $faux_verts succès hors dépôt git — autant de contrôles verts sur du vide"
else
    ok "n'annonce aucun succès hors dépôt git"
fi

# Témoin : dans le dépôt réel, il doit encore tourner et rendre un verdict.
sortie=0
( cd "$RACINE" && bash "$CHECK_PATHS" >/dev/null 2>&1 ) || sortie=$?
if [ "$sortie" -le 1 ]; then
    ok "témoin : rend un verdict lisible dans le dépôt réel (code $sortie)"
else
    nok "témoin : code de sortie inattendu dans le dépôt réel ($sortie)"
fi

# ── 1 bis. check-paths.sh — un lien hors des neuf extensions doit être lu ───
#
# Deux contrôles se partageaient les liens et chacun était aveugle à ce que
# l'autre regardait : l'un refusait les liens ancrés, l'autre ne regardait qu'eux.
# Un lien vers un `.js`, un `.conf` ou un répertoire ne passait par aucun des deux.
echo "▸ check-paths.sh — un lien vers un .js mort doit être signalé"
mkdir -p "$BAC/faux-depot"
# 🔑 Le faux dépôt porte un `deploy/` réel, et ce n'est pas décoratif : le contrôle
# des chemins cités en code inline ne signale que ceux dont le premier segment est
# connu du dépôt — c'est ce qui lui évite des centaines de faux positifs. Sans ce
# répertoire, la citation morte plantée ci-dessous sort du périmètre et le canari
# verdit sans rien prouver.
mkdir -p "$BAC/faux-depot/deploy"
( cd "$BAC/faux-depot" \
  && git init -q 2>/dev/null \
  && : > deploy/existe.conf \
  && printf 'Voir [le dérivateur](client/sr-derive.js) et [l API](api/).\nCopier `deploy/disparu.conf` vers le serveur.\n' > a.md \
  && git add a.md deploy/existe.conf 2>/dev/null ) || true
texte="$( (cd "$BAC/faux-depot" && bash "$CHECK_PATHS") 2>&1 || true )"
if printf '%s\n' "$texte" | grep -q 'client/sr-derive.js'; then
    ok "signale un lien mort vers un .js"
else
    nok "un lien vers un .js mort n'est vu par aucun bloc"
fi
if printf '%s\n' "$texte" | grep -q 'api/'; then
    ok "signale un lien mort vers un répertoire"
else
    nok "un lien vers un répertoire mort n'est vu par aucun bloc"
fi
# Une citation entre backticks n'est pas un lien : `prose()` retire le code inline
# exprès, donc aucun bloc de liens ne peut la voir. Elle a son contrôle à part.
if printf '%s\n' "$texte" | grep -q 'deploy/disparu.conf'; then
    ok "signale une citation morte entre backticks"
else
    nok "une citation morte entre backticks n'est vue par aucun bloc"
fi

# ── 2. ecart-instance.sh — l'ancre de fin de motif_vers_regex ───────────────
#
# Un motif rsync ancré à la racine sans ancre de fin attrape par préfixe. Le
# motif qui vise le binaire `selfrecover_derive` attrapait donc aussi ses
# sources `.c` et `.py`, qui quittaient le périmètre comparé : leur contenu
# n'était plus jamais confronté à l'instance. Un contrôle qui rend vert en
# regardant moins est plus dangereux qu'un contrôle qui crie.
echo "▸ ecart-instance.sh — un motif de fichier ne doit pas attraper par préfixe"
if eval "$(sed -n '/^motif_vers_regex()/,/^}/p' "$ECART")" 2>/dev/null \
   && declare -F motif_vers_regex >/dev/null; then
    ok "fonction motif_vers_regex extraite du script"

    re_bin="$(motif_vers_regex '/self-security/selfrecover-luks/selfrecover_derive')"
    for source in selfrecover_derive.c selfrecover_derive.py; do
        if printf '%s\n' "self-security/selfrecover-luks/$source" | grep -qE "$re_bin"; then
            nok "le motif du binaire attrape $source — il sort du périmètre comparé"
        else
            ok "$source reste dans le périmètre comparé"
        fi
    done

    # Témoins : le motif doit toujours attraper ce qu'il vise, et les motifs de
    # répertoire doivent continuer de couvrir leur contenu. Sans eux, une ancre
    # trop serrée viderait les exclusions sans qu'un cas rougisse.
    if printf '%s\n' 'self-security/selfrecover-luks/selfrecover_derive' | grep -qE "$re_bin"; then
        ok "témoin : le motif attrape toujours le binaire qu'il vise"
    else
        nok "témoin : le motif n'attrape plus le binaire — exclusion cassée"
    fi

    re_dir="$(motif_vers_regex '/demo/lab/data/*')"
    if printf '%s\n' 'demo/lab/data/lab.db' | grep -qE "$re_dir"; then
        ok "témoin : un motif de répertoire couvre toujours son contenu"
    else
        nok "témoin : le motif de répertoire ne couvre plus son contenu"
    fi
else
    nok "extraction de motif_vers_regex impossible — le banc ne mesure rien"
fi

# ── 3. ecart-instance.sh — un distant muet ne doit pas rendre vert ──────────
#
# Trois façons de ne rien mesurer se rangeaient en silence : `sha256sum` refusé
# sur un fichier présent n'écrivait rien et le fichier sortait de la comparaison,
# et les deux `ssh` de fin se terminaient par `|| true`, donc zéro figé et zéro
# orphelin s'affichaient qu'ils aient été demandés ou non.
#
# Le faux `ssh` ci-dessous répond `ILLISIBLE` à la comparaison et échoue sur les
# deux mesures suivantes — exactement ce qu'une machine dont les droits ont changé
# renvoie. Le script doit le dire, et surtout ne pas conclure.
echo "▸ ecart-instance.sh — un distant qui ne mesure rien ne doit pas conclure"
mkdir -p "$BAC/bin"
cat > "$BAC/bin/ssh" <<'FAUXSSH'
#!/bin/sh
# La comparaison rend ILLISIBLE pour chaque chemin reçu ; le reste échoue.
case "$*" in
    *sha256sum*) while IFS= read -r f; do printf 'ILLISIBLE  %s\n' "$f"; done; exit 0 ;;
    *)           exit 255 ;;
esac
FAUXSSH
chmod +x "$BAC/bin/ssh"
printf 'hote   canari@invalide\nsert   .   /chemin/sans/importance\n' > "$BAC/instance.map"

texte="$( cd "$RACINE" && PATH="$BAC/bin:$PATH" MYSELF_INSTANCE="$BAC/instance.map" \
          bash "$ECART" 2>&1 )" && code=0 || code=$?

# Témoin, non canari : l'ancienne version tenait déjà cette propriété, parce que
# ses faux hashes la faisaient sortir en divergence. Elle garde sa place pour
# qu'une correction future ne la casse pas, pas pour prouver quoi que ce soit.
if printf '%s\n' "$texte" | grep -q 'Rien ne diverge'; then
    nok "témoin : conclut « rien ne diverge » alors qu'aucune empreinte n'a été lue"
else
    ok "témoin : ne conclut pas quand rien n'a pu être lu"
fi
if printf '%s\n' "$texte" | grep -q 'NON MESURÉ'; then
    ok "nomme les fichiers que le distant n'a pas pu lire"
else
    nok "les fichiers illisibles disparaissent sans trace"
fi
if printf '%s\n' "$texte" | grep -qE 'figés NON mesurés|orphelins NON mesurés'; then
    ok "signale les mesures de fin qui n'ont pas pu tourner"
else
    nok "un ssh en échec sur les figés ou les orphelins passe pour un zéro mesuré"
fi
# ⚠️ Le code de sortie ne suffit pas : l'ancienne version sortait déjà en 1, mais
# pour des divergences que ses faux hashes fabriquaient. La propriété est que le
# verdict nomme la MESURE MANQUANTE — un incomplet, pas un écart.
if printf '%s\n' "$texte" | grep -q 'mesure(s) manquante(s)'; then
    ok "le verdict nomme l'incomplétude, pas une divergence inventée (code $code)"
else
    nok "le verdict ne distingue pas « non mesuré » de « divergent »"
fi

# ── 4. ecart-instance.sh — ce qui est servi exprès n'est pas orphelin ───────
#
# `IGNORES_SERVIS` n'était pas lu. Gitignorés, les fichiers qu'il couvre sont
# absents de `git ls-files`, donc du périmètre comme du hors-périmètre : le `find`
# distant les remontait tous en ORPHELIN. Dix faux pour un vrai.
#
# Ce faux `ssh` rend un `find` contenant les deux : un fichier servi exprès, et un
# véritable inconnu. Seul le second doit sortir.
echo "▸ ecart-instance.sh — un fichier servi exprès ne doit pas sortir orphelin"
mkdir -p "$BAC/bin4"
cat > "$BAC/bin4/ssh" <<'FAUXSSH'
#!/bin/sh
case "$*" in
    *sha256sum*) while IFS= read -r f; do printf 'ABSENT  %s\n' "$f"; done; exit 0 ;;
    *find*)      printf 'demo/lab/vendor/autoload.php\nintrus-jamais-versionne.php\n'; exit 0 ;;
    *)           exit 0 ;;
esac
FAUXSSH
chmod +x "$BAC/bin4/ssh"

texte="$( cd "$RACINE" && PATH="$BAC/bin4:$PATH" MYSELF_INSTANCE="$BAC/instance.map" \
          bash "$ECART" 2>&1 || true )"
if printf '%s\n' "$texte" | grep -q 'ORPHELIN.*intrus-jamais-versionne'; then
    ok "l'intrus réel sort bien en orphelin"
else
    nok "témoin : l'intrus réel ne sort pas — le filtre emporte tout"
fi
if printf '%s\n' "$texte" | grep -q 'ORPHELIN.*demo/lab/vendor'; then
    nok "un fichier d'IGNORES_SERVIS sort en orphelin"
else
    ok "les fichiers d'IGNORES_SERVIS ne sortent plus en orphelin"
fi

# ── Verdict ─────────────────────────────────────────────────────────────────
echo
echo "$reussites propriété(s) tiennent, $echecs en échec."
[ "$echecs" -eq 0 ] || exit 1
