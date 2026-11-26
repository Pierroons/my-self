#!/usr/bin/env bash
# Pose sur la machine qui sert un vhost que ce dépôt porte, en substituant son domaine et en
# MESURANT ce qu'un rechargement peut appliquer.
#
# À lancer depuis un VRAI terminal : le `sudo` du serveur a besoin d'un TTY, et le canal de Claude
# Code n'en fournit pas (« a terminal is required to read the password »). Rien ne bascule sans la
# confirmation demandée, et un `nginx -t` en échec REMET le fichier précédent puis le re-teste.
#
#   MYSELF_SSH=<alias> bash deploy/my-self/deploy-vhost.sh <gabarit> <vhost de sites-enabled>
#
# Les trois paires que le dépôt porte :
#   deploy/bi-self/nginx-bi-self.conf           bi-self.my-self.fr.conf
#   deploy/selfdataguard/nginx-dataguard.conf   dataguard.my-self.fr.conf
#   deploy/selfjustice/nginx.conf               justice.my-self.fr.conf
#
# 🔑 **Les deux arguments sont explicites exprès.** Une table nom → domaine ferait de ce script un
# second porteur des valeurs que `deploy/my-self/deploy.sh` porte déjà, et une table se périme sans
# bruit. Une paire incohérente est refusée par la mesure, à l'étape 3 : les deux fichiers doivent
# déclarer le même `root`.
#
# Les fonctions de ce script s'éprouvent seules, sans machine ni nginx — il est SOURÇABLE :
#
#   bash deploy/my-self/tests/test_pose_vhost.sh
#
# shellcheck disable=SC2029  # les chemins sont développés sur le poste, exprès

set -euo pipefail

SITES_ENABLED=/etc/nginx/sites-enabled
# Les sauvegardes ne vont PAS dans sites-enabled : `nginx.conf` y inclut `*.conf`, et une copie qui
# garderait ce suffixe serait chargée en double. `/root/` est hors de toute inclusion.
SAUVEGARDES=/root/vhosts-avant

# shellcheck source=deploy/ctf-s2/lib-zones.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/../ctf-s2" && pwd)/lib-zones.sh"

# ── Les fonctions mesurables, que le banc éprouve sans machine ───────────────────────────────────

# Le `root` d'une configuration nginx, espaces normalisés. C'est la clé qui apparie un gabarit et le
# vhost qu'il remplace : le domaine, lui, est un gabarit dans l'un et une valeur dans l'autre, donc
# il ne peut pas servir de clé.
racine_servie() {
    grep -oE '^[[:space:]]*root[[:space:]]+[^;]+' "$1" | head -1 \
        | sed -E 's/^[[:space:]]*root[[:space:]]+//; s/[[:space:]]+$//'
}

# L'unique `server_name` d'une configuration, ou rien si elle en déclare zéro ou plusieurs.
#
# ⚠️ **Zéro et plusieurs sont tous deux des refus, pas un cas à deviner.** Un vhost qui sert deux
# noms n'a pas « un » domaine à substituer, et choisir le premier poserait des certificats au nom
# d'un alias. Le vhost par défaut, lui, déclare `server_name _` : la forme l'écarte d'elle-même.
domaine_unique() {
    local noms
    noms=$(grep -oE '^[[:space:]]*server_name[[:space:]]+[^;]+' "$1" \
        | sed -E 's/^[[:space:]]*server_name[[:space:]]+//' | tr -s ' ' '\n' | grep -vE '^$|^_$')
    [ "$(printf '%s\n' "$noms" | grep -c .)" = 1 ] || return 1
    printf '%s\n' "$noms"
}

# Substitue le domaine dans un gabarit. $1 = fichier, $2 = domaine réel.
#
# 🔴 **DEUX formes de gabarit, et elles ne viennent pas du même endroit.** `<quelque-chose>.example.org`
# est la forme de la table de `deploy.sh`. `your-instance.example` est AUTRE CHOSE : dans le code PHP
# servi, c'est un repli d'exécution quand `HTTP_HOST` est absent, et `deploy.sh` dit explicitement
# qu'il ne doit PAS entrer dans sa table — l'y substituer corromprait le code. Mais dans un vhost,
# la même chaîne est un `server_name` et un chemin de certificat : là, elle DOIT être substituée.
#
# 🔑 La même chaîne a donc deux sens selon le fichier qui la porte. C'est pour ça que ce script ne
# réemploie pas la table de `deploy.sh` : elle répondrait juste à une autre question.
substituer_domaine() {
    sed -E "s/[A-Za-z0-9-]+\.example\.org/$2/g; s/your-instance\.example/$2/g" "$1"
}

# Les gabarits qui subsistent après substitution. Une sortie non vide est un refus.
#
# ⚠️ C'est le garde-fou qui manquait le 25/11/2026 : rien n'empêchait mécaniquement de poser un
# vhost portant encore `your-instance.example`, ce qui aurait fait tomber le `server_name` et les
# chemins de certificat de la production publique.
gabarits_restants() {
    grep -nE '[A-Za-z0-9-]+\.example\.org|your-instance\.example|example\.com' "$1" || true
}

# ── La pose ─────────────────────────────────────────────────────────────────────────────────────

main() {
    local gabarit="${1:-}" vhost="${2:-}"
    if [ -z "$gabarit" ] || [ -z "$vhost" ]; then
        sed -n '2,24p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
        return 2
    fi
    local ssh_alias="${MYSELF_SSH:?alias SSH de la machine qui sert}"
    local suffixe; suffixe="avant-$(date +%Y%m%d-%H%M)"
    local t; t=$(mktemp -d); trap 'rm -rf "$t"' RETURN

    echo "▸ 1. La révision de référence, et le gabarit qu'elle porte"
    # 🔑 `main` est la branche de ce qui est SERVI (`project_main_source_de_l_infra`). La machine n'a
    # pas de fichier de révision — mesuré le 25/11/2026 — donc c'est `main` qui tranche, pas l'arbre
    # de travail d'une conv.
    git fetch -q origin main
    local sha; sha=$(git rev-parse origin/main)
    echo "   origin/main = $sha"
    git cat-file -e "$sha:$gabarit" 2>/dev/null || {
        echo "❌ $gabarit n'existe pas à cette révision — il n'est pas encore sur main."; return 1; }
    # ⚠️ **L'arbre local n'est PAS la source de la pose.** Sans ce refus, on relit un fichier local
    # en croyant juger ce qui va être posé, et le lot d'une autre conv part sans qu'on le voie.
    if ! git diff --quiet "$sha" -- "$gabarit"; then
        echo "❌ ton $gabarit local DIFFÈRE de celui de main : c'est main qui serait posé."
        echo "   Envoie ton lot, ou relis le gabarit de main :  git show $sha:$gabarit"
        return 1
    fi
    git show "$sha:$gabarit" > "$t/gabarit"

    echo "▸ 2. Le vhost en service : son chemin RÉEL, et son domaine"
    # 🔴 `sites-enabled/` ne porte que des liens vers `sites-available/` — sauf accident. Un `sed -i`
    # sur un lien le REMPLACE par un fichier réel : la source canonique cesse alors d'être servie, et
    # qui « répare » le lien revient en arrière sans le savoir. Payé le 25/11/2026 sur dataguard.
    # D'où `readlink -f` : on écrit dans le fichier réel, jamais à travers le lien.
    local reel; reel=$(ssh "$ssh_alias" "readlink -f '$SITES_ENABLED/$vhost'")
    [ -n "$reel" ] || { echo "❌ $vhost est introuvable dans $SITES_ENABLED."; return 1; }
    echo "   $SITES_ENABLED/$vhost → $reel"
    ssh "$ssh_alias" "cat '$reel'" > "$t/service"
    local domaine
    domaine=$(domaine_unique "$t/service") || {
        echo "❌ ce vhost ne déclare pas UN seul nom — rien à substituer sans deviner."; return 1; }
    echo "   domaine lu sur la machine : $domaine"

    echo "▸ 3. La paire est-elle cohérente ? (le \`root\` des deux fichiers)"
    local r_gab r_srv
    r_gab=$(racine_servie "$t/gabarit"); r_srv=$(racine_servie "$t/service")
    if [ "$r_gab" != "$r_srv" ]; then
        echo "❌ paire incohérente — le gabarit sert « $r_gab », le vhost « $r_srv »."
        return 1
    fi
    echo "   ✓ les deux servent « $r_srv »"

    echo "▸ 4. Substitution, puis refus s'il reste un gabarit"
    substituer_domaine "$t/gabarit" "$domaine" > "$t/candidat"
    local restants; restants=$(gabarits_restants "$t/candidat")
    if [ -n "$restants" ]; then
        echo "❌ des gabarits subsistent après substitution — rien n'est posé :"
        printf '%s\n' "$restants" | sed 's/^/     /'
        return 1
    fi
    echo "   ✓ aucun gabarit ne subsiste"

    echo "▸ 5. Une zone de frein est-elle déjà déclarée AILLEURS ?"
    # 🔴 **`-R` et non `-r` : `sites-enabled/` n'est qu'un dossier de LIENS**, et `grep -r` ne les
    # suit pas. Avec `-r`, ce contrôle ne verrait que `conf.d/` et rendrait un vert dont personne ne
    # connaîtrait la portée — sur la même surface, `-r` a rendu 0 là où `-R` rend 18 (23/11/2026).
    #
    # Le motif exige `limit_req_zone` : sans lui, les `limit_req zone=x` d'USAGE seraient comptés
    # comme des déclarations, et toute pose serait refusée.
    #
    # L'exclusion porte sur le fichier qu'on remplace, par son nom de base — le lien et sa cible
    # le partagent. Ses propres zones disparaissent avec lui : ce ne sont pas des collisions, et sans
    # cette exclusion le contrôle refuserait le cas nominal.
    local base_reel; base_reel=$(basename "$reel")
    ssh "$ssh_alias" "grep -RHoE 'limit_req_zone[[:space:]]+\\\$[A-Za-z0-9_]+[[:space:]]+zone=[A-Za-z0-9_]+' \
        --include='*.conf' /etc/nginx/conf.d /etc/nginx/sites-enabled 2>/dev/null" \
        | grep -vF "$base_reel" \
        | sed -E 's#^([^:]+):.*zone=([A-Za-z0-9_]+)$#\2 \1#' | sort -u > "$t/ailleurs" || true
    echo "   $(grep -c . "$t/ailleurs" || true) zone(s) déclarée(s) ailleurs sur la machine"
    local collisions; collisions=$(zones_deja_declarees "$t/candidat" "$t/ailleurs")
    if [ -n "$collisions" ]; then
        echo "❌ nginx refuserait la configuration ENTIÈRE — une zone ne se déclare qu'une fois :"
        printf '%s\n' "$collisions" | sed 's/^/     /'
        echo "     Retirer ces lignes du gabarit, ou la déclaration de conf.d — c'est une décision,"
        echo "     pas un détail de pose : les deux endroits fixent le débit."
        return 1
    fi
    echo "   ✓ aucune zone du gabarit n'est déjà déclarée ailleurs"

    echo "▸ 6. Une zone change-t-elle de CLÉ ? (ce qu'un rechargement ne peut pas appliquer)"
    local changees geste
    changees=$(zones_a_cle_changee "$t/service" "$t/candidat")
    if [ -n "$changees" ]; then
        geste=restart
        echo "   ⚠️ clé(s) changée(s) — un rechargement serait refusé SANS ERREUR VISIBLE, donc REDÉMARRAGE :"
        printf '%s\n' "$changees" | sed 's/^/     /'
        echo "     (coupure d'environ une seconde pour TOUS les sites, et les compteurs repartent de zéro)"
    else
        geste=reload
        echo "   ✓ aucune clé ne change — un rechargement suffit, sans coupure"
    fi

    echo "▸ 7. Ce qui changerait — lignes fonctionnelles seules"
    diff "$t/service" "$t/candidat" | grep -E '^[<>]' \
        | grep -vE '^[<>][[:space:]]*#|^[<>][[:space:]]*$' | sed 's/^/   /' || echo "   (aucune ligne fonctionnelle ne change)"

    echo
    # ⚠️ **Sans terminal, c'est un REFUS propre, pas une mort obscure.** Sous `set -e`, un `read` qui
    # tombe sur une fin de fichier rend non zéro et tuerait le script au milieu — on ne saurait pas
    # si quelque chose a été posé. Les étapes 1 à 7 ne font que lire : elles valent donc comme
    # répétition sans pose, y compris depuis un canal sans TTY.
    local reponse=""
    if ! read -r -p "Poser ce vhost par un $geste, sauvegarde dans $SAUVEGARDES ? [oui/N] " reponse; then
        echo
        echo "⏹ pas de terminal pour confirmer — RIEN n'est posé. Les mesures ci-dessus sont valides."
        return 0
    fi
    [ "$reponse" = "oui" ] || { echo "⏹ rien n'est fait."; return 0; }

    echo "▸ 8. Sauvegarde, pose, test — un test en échec REMET le précédent"
    local pose; pose=$(date +%s)
    # 🔑 La pose précède le test : `nginx -t` lit la configuration SUR LE DISQUE. Ce qui doit être
    # garanti n'est pas l'ordre des gestes mais l'état LAISSÉ — ou le candidat est valide et chargé,
    # ou le précédent est rétabli. Un fragment fautif laissé en place ne casse rien tout de suite :
    # c'est le rechargement SUIVANT, des jours plus tard, qui met le service à terre.
    # ⚠️ `cp -aL` et non `cp -a` : sur un lien, `-a` copie le LIEN et la sauvegarde ne contient rien.
    # Payé le 25/11/2026 — la sauvegarde annoncée était un lien mort.
    ssh "$ssh_alias" "cat > /tmp/vhost-candidat.conf" < "$t/candidat"
    local somme_locale somme_distante
    somme_locale=$(sha256sum "$t/candidat" | cut -d' ' -f1)
    somme_distante=$(ssh "$ssh_alias" "sha256sum /tmp/vhost-candidat.conf | cut -d' ' -f1")
    [ "$somme_locale" = "$somme_distante" ] || {
        echo "❌ le transfert a altéré le candidat ($somme_locale ≠ $somme_distante)."; return 1; }
    echo "   candidat transféré, empreinte vérifiée : $somme_locale"

    ssh -t "$ssh_alias" "set -e
      sudo mkdir -p '$SAUVEGARDES'
      sudo cp -aL '$reel' '$SAUVEGARDES/$(basename "$reel").$suffixe'
      sudo install -m 644 -o root -g root /tmp/vhost-candidat.conf '$reel'
      if ! sudo /usr/sbin/nginx -t; then
        echo '   ❌ nginx refuse ce vhost — je remets le précédent'
        sudo cp -a '$SAUVEGARDES/$(basename "$reel").$suffixe' '$reel'
        if sudo /usr/sbin/nginx -t; then
          echo \"   ✓ précédent rétabli et valide : l'instance est dans l'état d'avant\"
        else
          echo '   🔴 le précédent NE PASSE PAS NON PLUS : la cause est ailleurs dans /etc/nginx,'
          echo '      et le prochain rechargement échouera. À traiter avant toute autre pose.'
        fi
        rm -f /tmp/vhost-candidat.conf
        exit 1
      fi
      sudo systemctl $geste nginx
      rm -f /tmp/vhost-candidat.conf"

    echo "▸ 9. La configuration posée est-elle celle qui SERT ?"
    # Un worker plus vieux que le geste n'a pas été remplacé : le master a refusé la nouvelle
    # configuration et garde l'ancienne en mémoire. C'est le seul endroit de ce script qui distingue
    # « le fichier est posé » de « la configuration est active » — la classe que le chantier
    # MEM-1011-0950 laisse ouverte pour les déploiements de ce dépôt.
    sleep 2
    local limite ages total vieux
    limite=$(( $(date +%s) - pose + 10 ))
    ages=$(ssh "$ssh_alias" "ps -eo etimes,args | grep '[n]ginx: worker' | awk '{ print \$1 }'")
    [ -n "$ages" ] || { echo "❌ aucun worker nginx ne tourne — le service est à terre."; return 1; }
    total=$(printf '%s\n' "$ages" | grep -c .)
    vieux=$(printf '%s\n' "$ages" | awk -v l="$limite" '$1 > l { n++ } END { print n+0 }')
    if [ "$vieux" != 0 ]; then
        echo "❌ $vieux worker(s) sur $total sont plus vieux que le geste ($limite s) :"
        echo "   LE FICHIER EST POSÉ MAIS LA CONFIGURATION N'EST PAS APPLIQUÉE."
        ssh "$ssh_alias" "grep -i emerg /var/log/nginx/error.log | tail -3 || tail -3 /var/log/nginx/error.log" | sed 's/^/     /'
        echo "   Reprendre par un redémarrage :"
        echo "     ssh -t $ssh_alias 'sudo /usr/sbin/nginx -t && sudo systemctl restart nginx'"
        echo "   Ou revenir en arrière :"
        echo "     ssh -t $ssh_alias 'sudo cp -a $SAUVEGARDES/$(basename "$reel").$suffixe $reel && sudo systemctl restart nginx'"
        return 1
    fi
    echo "   ✓ les $total worker(s) ont tous moins de $limite s : la configuration posée est celle qui sert"

    echo "▸ 10. Mesures à l'arrivée"
    ssh "$ssh_alias" "set -e
      echo -n '   vhost servi  '; sha256sum '$reel' | cut -d' ' -f1
      echo -n '   lien         '; ls -l '$SITES_ENABLED/$vhost' | sed 's/.*$vhost/$vhost/'
      echo -n '   sauvegarde   '; ls -1 '$SAUVEGARDES/$(basename "$reel")'.avant-* | tail -1"
    ssh "$ssh_alias" "curl -sS -o /dev/null -m 20 -w '   HTTP %{http_code} en %{time_total}s\n' \
        --resolve '$domaine:443:127.0.0.1' 'https://$domaine/'"
    echo "✓ terminé."
}

# Sourçable : le banc charge les fonctions sans déclencher la pose. ⚠️ `deploy.sh` ne l'est pas — son
# `case` final rendrait `exit 2` dans l'appelant —, et c'est ce qui a imposé d'écrire ce script
# autrement plutôt que de réemployer ses fonctions.
if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
    main "$@"
fi
