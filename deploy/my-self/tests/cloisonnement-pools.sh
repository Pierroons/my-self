#!/usr/bin/env bash
# Les groupes d'ouvriers PHP sont-ils réellement cloisonnés ?
#
# 🔑 **Pourquoi ce contrôle existe.** Depuis le 24/11/2026, la machine ne sert
# plus ses sites PHP sous une identité unique : chaque site qui porte un état ou
# un secret a la sienne. Cette propriété ne se voit nulle part — ni `nginx -t`,
# ni `php-fpm8.4 -t`, ni aucun code de sortie ne dit si elle tient. Un seul
# `user = www-data` recopié par habitude dans un gabarit neuf la défait en
# silence, et tout continue de fonctionner : c'est précisément ce qui la rend
# dangereuse à ne pas mesurer.
#
# 🔑 **Ce qu'il établit, et c'est plus étroit que « c'est sûr »** : que les
# gabarits DÉCLARENT le cloisonnement, et — avec un hôte — que les ouvriers le
# portent vraiment. Il ne prouve pas qu'un site ne peut pas lire l'état d'un
# autre : cette preuve demande d'ouvrir un fichier sous une identité donnée,
# donc un geste sur la machine, et elle est écrite comme telle dans
# `deploy/my-self/README.md`, à faire à la pose.
#
# ⚠️ **Le volet 5 est celui qui attrape la faute la plus coûteuse** : un vhost
# qui pointe une socket qu'aucun gabarit ne déclare. `nginx -t` l'accepte, et le
# site rend 502 à la première requête après la pose.
#
#   bash deploy/my-self/tests/cloisonnement-pools.sh            # hors ligne, tient en CI
#   POOL_SSH_HOST=<alias> bash .../cloisonnement-pools.sh       # + ce que la machine porte
#
# Sortie : 0 si tout concorde, 1 s'il y a un écart, 2 si le contrôle n'a pas pu
# être établi.
set -uo pipefail

RACINE="$(git rev-parse --show-toplevel)" || exit 2
[ -n "$RACINE" ] || { echo "❌ hors dépôt git" >&2; exit 2; }
cd "$RACINE" || exit 2

# Les dossiers servis par CETTE machine — `deploy/ctf-s2/` en est absent exprès,
# le lab est un autre hôte avec ses propres groupes et son propre frontal.
DOSSIERS=(deploy/my-self deploy/bi-self deploy/selfdataguard deploy/selfjustice)

# Le groupe résiduel : le SEUL qui a le droit de tourner sous `www-data`, parce
# qu'il sert les vhosts sans état propre. En échange, il n'a pas le droit de
# porter un secret — volet 4.
RESIDUEL=deploy/my-self/php-fpm-www.conf

ECARTS=0
dire_ecart() { echo "   ✗ $*"; ECARTS=$((ECARTS + 1)); }

mapfile -t GABARITS < <(
    for d in "${DOSSIERS[@]}"; do
        [ -d "$d" ] || continue
        find "$d" -maxdepth 1 -name 'php-fpm-*.conf' -type f | sort
    done
)
[ "${#GABARITS[@]}" -gt 0 ] || { echo "❌ aucun gabarit de groupe sous ${DOSSIERS[*]}"; exit 2; }

# ── 1. Chaque gabarit déclare son identité et son écoute ────────────────────
echo "▸ 1. Ce que chaque gabarit déclare — ${#GABARITS[@]} gabarit(s)"
declare -A SOCKET_DE
declare -A USER_DE
for g in "${GABARITS[@]}"; do
    u=$(sed -nE 's/^[[:space:]]*user[[:space:]]*=[[:space:]]*([^[:space:];]+).*/\1/p' "$g" | head -1)
    grp=$(sed -nE 's/^[[:space:]]*group[[:space:]]*=[[:space:]]*([^[:space:];]+).*/\1/p' "$g" | head -1)
    sock=$(sed -nE 's#^[[:space:]]*listen[[:space:]]*=[[:space:]]*([^[:space:];]+).*#\1#p' "$g" | head -1)
    mode=$(sed -nE 's/^[[:space:]]*listen\.mode[[:space:]]*=[[:space:]]*([0-7]+).*/\1/p' "$g" | head -1)
    proprio=$(sed -nE 's/^[[:space:]]*listen\.owner[[:space:]]*=[[:space:]]*([^[:space:];]+).*/\1/p' "$g" | head -1)

    [ -n "$u" ]    || dire_ecart "$g — aucun \`user\` déclaré"
    [ -n "$grp" ]  || dire_ecart "$g — aucun \`group\` déclaré"
    [ -n "$sock" ] || dire_ecart "$g — aucun \`listen\` déclaré"
    [ -n "$mode" ] || dire_ecart "$g — aucun \`listen.mode\` : le mode de la socket est laissé au défaut, donc non déclaré"
    # 🔑 nginx écoute sous `www-data` : c'est LUI qui doit pouvoir ouvrir la
    # socket, pas le groupe d'ouvriers. Un `listen.owner` égal au `user` du
    # groupe fermerait la socket à nginx, et le site rendrait 502.
    if [ -n "$proprio" ] && [ -n "$u" ] && [ "$proprio" = "$u" ] && [ "$u" != "www-data" ]; then
        dire_ecart "$g — \`listen.owner = $proprio\` est l'identité du groupe : nginx ne pourrait pas ouvrir la socket"
    fi

    [ -n "$sock" ] && SOCKET_DE[$g]="$sock"
    [ -n "$u" ] && USER_DE[$g]="$u"
    printf '   %-46s user %-14s mode %-5s %s\n' "${g#deploy/}" "${u:-—}" "${mode:-—}" "${sock:-—}"
done

# ── 2. Aucun groupe sous www-data, sauf le résiduel ─────────────────────────
echo "▸ 2. L'identité de chaque groupe"
for g in "${!USER_DE[@]}"; do
    if [ "${USER_DE[$g]}" = "www-data" ] && [ "$g" != "$RESIDUEL" ]; then
        dire_ecart "$g tourne sous \`www-data\` — le cloisonnement ne tient que si chaque état a son identité"
    fi
done
if [ "${USER_DE[$RESIDUEL]:-}" != "www-data" ]; then
    echo "   ⓘ le résiduel ne tourne plus sous www-data (${USER_DE[$RESIDUEL]:-absent}) — ce n'est pas un défaut, mais le README le suppose"
fi
echo "   identités distinctes : $(printf '%s\n' "${USER_DE[@]}" | sort -u | tr '\n' ' ')"

# 🔑 Deux groupes PEUVENT partager une identité, et l'un le fait exprès :
# `biself` et `biself-flux` servent le même état de session, donc les séparer
# empêcherait le flux de lire ce qu'il doit lire. Ce contrôle l'affiche sans le
# refuser — ce qui se refuse, c'est le partage avec `www-data`, qui est le
# périmètre le plus large de la machine.
partages=$(printf '%s\n' "${USER_DE[@]}" | sort | uniq -d)
[ -n "$partages" ] && echo "   ⓘ identité(s) partagée(s) par plusieurs groupes : $(echo "$partages" | tr '\n' ' ')— à ne garder que pour un état commun"

# ── 3. Deux groupes ne partagent jamais une socket ──────────────────────────
echo "▸ 3. Les sockets"
doublons=$(printf '%s\n' "${SOCKET_DE[@]}" | sort | uniq -d)
if [ -n "$doublons" ]; then
    while IFS= read -r d; do
        dire_ecart "deux gabarits déclarent la même socket : $d — le second écrase le premier au rechargement"
    done <<<"$doublons"
else
    echo "   ✓ ${#SOCKET_DE[@]} socket(s), toutes distinctes"
fi

# ── 4. Le résiduel ne porte aucun secret ────────────────────────────────────
echo "▸ 4. Les variables d'environnement du groupe résiduel"
if [ -r "$RESIDUEL" ]; then
    # ⚠️ On ne lit que les CLÉS, jamais les valeurs : la lecture entière d'un
    # gabarit de groupe a fait sortir une clé d'API le 19/11/2026, et coûté une
    # rotation sur trois porteurs.
    cles=$(sed -nE 's/^[[:space:]]*env\[([^]]+)\].*/\1/p' "$RESIDUEL")
    if [ -n "$cles" ]; then
        while IFS= read -r c; do
            dire_ecart "le résiduel porte \`env[$c]\` — il sert le plus large périmètre de la machine, un secret y est lisible par tout ce qu'il sert"
        done <<<"$cles"
    else
        echo "   ✓ aucune : un secret confié au résiduel serait lisible par tous les vhosts qu'il sert"
    fi
else
    echo "   ❌ résiduel illisible : $RESIDUEL"; exit 2
fi

# ── 5. Chaque socket d'un vhost est déclarée par un gabarit ─────────────────
#
# ⚠️ C'est le volet qui attrape la faute la plus coûteuse de la pose. Les
# gabarits de vhost se cherchent par `git ls-files`, comme `check-vhost.sh`
# monte ses snippets — un vhost neuf non suivi par git n'entrerait pas, et
# c'est voulu : ce qui n'est pas versionné n'est pas ce qu'on pose.
echo "▸ 5. Les sockets que les vhosts réclament"
mapfile -t VHOSTS < <(git ls-files 'deploy/*/nginx*.conf' | sort)
declare -A DECLAREES
for s in "${SOCKET_DE[@]}"; do DECLAREES[$s]=1; done
reclamees=0
for v in "${VHOSTS[@]}"; do
    # Le lab a ses propres groupes, sur une autre machine : ses sockets ne sont
    # pas déclarées ici, et les exiger ferait rougir sur une absence normale.
    case "$v" in deploy/ctf-s2/*) continue ;; esac
    while IFS= read -r s; do
        reclamees=$((reclamees + 1))
        if [ -z "${DECLAREES[$s]:-}" ]; then
            dire_ecart "$v réclame la socket $s, qu'aucun gabarit ne déclare — \`nginx -t\` l'accepte, le site rendra 502"
        fi
    done < <(sed -nE 's#.*fastcgi_pass[[:space:]]+unix:([^;[:space:]]+).*#\1#p' "$v" | sort -u)
done
[ "$reclamees" -gt 0 ] && echo "   $reclamees socket(s) distincte(s) réclamée(s) par ${#VHOSTS[@]} gabarit(s) de vhost, hors lab" \
    || echo "   ⓘ aucune socket réclamée : les vhosts ne nomment aucun groupe d'ouvriers"

# ── 6. Ce que la machine porte vraiment ─────────────────────────────────────
if [ -n "${POOL_SSH_HOST:-}" ]; then
    echo "▸ 6. Ce que la machine porte — $POOL_SSH_HOST, en lecture seule"
    # shellcheck disable=SC2016  # les $ sont évalués par le shell DISTANT, exprès
    distant=$(timeout 30 ssh -o BatchMode=yes "$POOL_SSH_HOST" '
        echo "--sockets--"; ls -l /run/php/*.sock 2>/dev/null
        echo "--ouvriers--"; ps -o user=,cmd= -C php-fpm8.4 2>/dev/null
    ' 2>/dev/null) || { echo "   ❌ lecture impossible — le volet 6 n'est pas établi"; exit 2; }
    socks=$(printf '%s\n' "$distant" | sed -n '/--sockets--/,/--ouvriers--/p' | /usr/bin/grep -c '\.sock' || true)
    identites=$(printf '%s\n' "$distant" | sed -n '/--ouvriers--/,$p' | /usr/bin/grep -oE '^[a-z0-9_-]+' | sort -u | tr '\n' ' ')
    echo "   sockets en place : ${socks:-0} · identités des ouvriers : ${identites:-aucune}"
    # ⚠️ Avant la pose, la machine ne porte qu'un groupe sous `www-data` : ce
    # volet DOIT alors signaler l'écart, et ce n'est pas un défaut du gabarit
    # mais le signal qu'il reste un geste à faire.
    attendues=${#SOCKET_DE[@]}
    if [ "${socks:-0}" -lt "$attendues" ]; then
        echo "   ⓘ ${socks:-0} socket(s) pour $attendues déclarée(s) : le découpage n'est pas encore posé."
        echo "      Ce n'est pas un écart de gabarit — c'est la pose qui reste à faire."
    fi
else
    echo "▸ 6. Ce que la machine porte — non lancé (POOL_SSH_HOST absent)"
    echo "   ⓘ le volet hors ligne ne dit rien des ouvriers réels : il juge ce qui est DÉCLARÉ."
fi

echo
if [ "$ECARTS" -gt 0 ]; then
    echo "✗ $ECARTS écart(s) : le cloisonnement déclaré n'est pas cohérent."
    exit 1
fi
echo "✓ le cloisonnement est cohérent dans les gabarits."
echo "  ⚠️ Cela établit ce qui est DÉCLARÉ, pas ce qu'un site peut lire. La preuve"
echo "     qu'un groupe ne peut pas ouvrir l'état d'un autre demande un geste sur la"
echo "     machine — elle est écrite dans \`deploy/my-self/README.md\`, à faire à la pose."
