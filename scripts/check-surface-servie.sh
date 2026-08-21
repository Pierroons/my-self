#!/bin/bash
# Sonde de surface — quel fichier du dépôt le serveur rend-il alors qu'il n'est
# pas du contenu web ?
#
# 🔑 **Ce n'est pas une sonde de contenu, et c'est le point.** Le 21/08/2026,
# `README.md` répondait 200 sur le site public et documentait le déploiement de
# cette instance-là : nom du vhost, chemin disque, compte de dépôt, procédure
# sudo. L'audit OPSEC ne l'avait pas vu, et n'avait pas à le voir — lui cherche
# des motifs dans les fichiers du dépôt, et ce fichier est un fichier du dépôt
# comme un autre.
#
# On aurait pu lui ajouter `/var/www` comme motif. Ç'aurait été une erreur :
# quinze fichiers du dépôt le portent légitimement, parce qu'un projet
# auto-hébergeable doit dire où s'installe son code. Le motif aurait crié
# quinze fois pour une fois utile, et on aurait cessé de le lire.
#
# La bonne question n'est pas « ce texte contient-il un secret ? » mais « ce
# fichier devrait-il être atteignable depuis l'extérieur ? ». Un `.md`, un
# `.sh`, un `.yml` qui répond 200 est une surface, qu'il fuite ou non.
#
# Usage :
#   ./check-surface-servie.sh              # sur l'instance : lit les vhosts
#   ./check-surface-servie.sh --verbeux    # dit aussi ce qu'il a écarté
# Sortie : 0 si aucune surface inattendue, 1 sinon.

set -uo pipefail

ICI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VHOSTS="${SURFACE_VHOSTS:-/etc/nginx/sites-enabled}"
ALLOWLIST="${SURFACE_ALLOWLIST:-$ICI/surface-allowlist.txt}"
PLAFOND="${SURFACE_PLAFOND:-300}"
VERBEUX=""
[ "${1:-}" = "--verbeux" ] && VERBEUX=1

# Ce qu'un navigateur vient légitimement chercher. Le `php` en fait partie : le
# serveur l'exécute, il ne rend pas la source — un `.php` qui rendrait son texte
# est un autre défaut, que cette sonde ne prétend pas couvrir.
WEB="html|htm|php|css|js|mjs|map|json|webmanifest|svg|png|jpe?g|gif|webp|avif|ico|woff2?|ttf|eot|txt|xml|pdf|mp4|webm|wasm"

[ -d "$VHOSTS" ] || { echo "répertoire de vhosts introuvable : $VHOSTS" >&2; exit 2; }
[ -r "$ALLOWLIST" ] || { echo "allowlist introuvable : $ALLOWLIST" >&2; exit 2; }

mapfile -t EXCEPTIONS < <(grep -vE '^\s*(#|$)' "$ALLOWLIST" | cut -f1)

# ── Les couples (nom servi, racine) que le serveur déclare ──────────────────
#
# Un fichier de configuration peut porter plusieurs blocs `server` ; on lit donc
# bloc par bloc plutôt que fichier par fichier, sans quoi une racine se verrait
# attribuer le nom d'un voisin.
couples() {
    awk '
        /^[[:space:]]*server[[:space:]]*\{/ { bloc++; nom[bloc]=""; racine[bloc]="" }
        /^[[:space:]]*server_name[[:space:]]/ && nom[bloc]=="" {
            n=$2; gsub(";","",n); nom[bloc]=n
        }
        /^[[:space:]]*root[[:space:]]/ && racine[bloc]=="" {
            r=$2; gsub(";","",r); racine[bloc]=r
        }
        END { for (b=1; b<=bloc; b++)
                  if (nom[b] != "" && nom[b] != "_" && racine[b] != "")
                      print nom[b] "\t" racine[b] }
    ' "$VHOSTS"/*.conf 2>/dev/null | sort -u
}

exempte() {
    local url="$1" e
    for e in "${EXCEPTIONS[@]}"; do
        [[ "$url" =~ $e ]] && return 0
    done
    return 1
}

trouves=0
testes=0
ecartes=0
non_testes=0

echo "▸ Ce que chaque racine servie expose hors contenu web"
while IFS=$'\t' read -r nom racine; do
    [ -d "$racine" ] || continue
    # Un nom qui ne résout pas ne peut pas être interrogé de l'extérieur : le
    # dire, plutôt que de compter un silence pour une absence de défaut.
    if ! getent hosts "$nom" >/dev/null 2>&1; then
        [ -n "$VERBEUX" ] && printf "  ·  %-30s non résolu, non interrogé\n" "$nom"
        continue
    fi

    mapfile -t candidats < <(
        find "$racine" -type f 2>/dev/null \
        | grep -vE "\.($WEB)$" \
        | sed "s|^$racine/||" | sort
    )
    [ ${#candidats[@]} -eq 0 ] && continue

    n=${#candidats[@]}
    if [ "$n" -gt "$PLAFOND" ]; then
        # ⚠️ Une troncature muette se lit comme « tout est couvert ». On dit
        # combien de fichiers n'ont pas été interrogés.
        non_testes=$((non_testes + n - PLAFOND))
        echo "  ⚠️  $nom : $n fichiers hors contenu web, seuls les $PLAFOND premiers interrogés" >&2
        candidats=("${candidats[@]:0:$PLAFOND}")
    fi

    for rel in "${candidats[@]}"; do
        url="https://$nom/$rel"
        # 🔑 Anti-cache obligatoire. Un frontal se tient devant ces domaines, et
        # une mesure prise juste après un rechargement de configuration lit
        # encore l'ancienne réponse — constaté deux fois le 21/08/2026, où un
        # 404 tout neuf se présentait en 200. Le faux rouge fait perdre du
        # temps ; le faux vert ferait croire à une surface fermée.
        code=$(curl -s -o /dev/null -w "%{http_code}" -H "Cache-Control: no-cache" \
               --max-time 10 "$url?sonde=$RANDOM")
        testes=$((testes + 1))
        [ "$code" = "200" ] || continue
        if exempte "/$rel"; then
            ecartes=$((ecartes + 1))
            [ -n "$VERBEUX" ] && printf "  ·  %s — servi délibérément\n" "$url"
            continue
        fi
        printf "  ✗  %-60s 200\n" "$url" >&2
        trouves=$((trouves + 1))
    done
done < <(couples)

echo
echo "  $testes fichier(s) interrogé(s), $ecartes écarté(s) par l'allowlist"
[ "$non_testes" -gt 0 ] && echo "  ⚠️  $non_testes fichier(s) NON interrogés (plafond) — relancer avec SURFACE_PLAFOND plus haut" >&2

if [ "$trouves" -eq 0 ]; then
    echo "✓ aucune surface inattendue."
    exit 0
fi
echo "✗ $trouves fichier(s) hors contenu web répondent 200." >&2
echo "  Soit le serveur ne doit plus les servir, soit ils entrent à l'allowlist AVEC leur raison." >&2
exit 1
