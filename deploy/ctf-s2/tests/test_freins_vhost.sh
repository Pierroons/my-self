#!/usr/bin/env bash
# Chaque route PHP du lab est-elle derrière ses freins ? La question est posée à nginx.
#
# Le gabarit est servi tel quel par un nginx jetable, sans privilège et sans PHP. Aucun
# ouvrier n'écoute derrière : ce banc ne mesure pas ce que PHP répond, il lit dans le journal
# ce que nginx a FAIT de chaque requête — la file appliquée (`$limit_req_status`) et les
# ouvriers visés (`$upstream_addr`). Relire les `location` à la main, ou les rejouer par
# expressions régulières, donne ce qu'on croit que nginx choisit ; ceci donne ce qu'il choisit.
#
#   bash deploy/ctf-s2/tests/test_freins_vhost.sh
#   GABARIT=<fichier>    éprouve un autre gabarit — c'est par là que passent les canaris
#   BIN_NGINX=<binaire>  sinon celui du PATH — ⚠️ jamais `NGINX` : nginx lit lui-même cette variable
#   NGINX_ETC=<dossier>  d'où viennent mime.types, fastcgi.conf et les snippets du paquet
#
# ⚠️ Les trois listes ci-dessous sont des inventaires, pas des devinettes : une route y entre
# quand on a MESURÉ qu'elle hache, ou qu'elle fait attendre son ouvrier avant de refuser. Une
# route neuve qui hacherait sans y figurer garde au moins la file commune à toute l'API
# (cas 2) ; ce banc ne peut pas le deviner pour elle.
set -u

LOURDES="register attack"
CALCUL="login recover device_enroll device_auth_begin device_auth_finish dm_send dm_inbox
        profile_save flag_submit report_status redteam_report
        recover_l3 recover_l3_init recover_l3_reset dispute_chat"
PAGES_CALCUL="profile messages recover"

RACINE=$(cd "$(dirname "$0")/../../.." && pwd)
GABARIT=${GABARIT:-$RACINE/deploy/ctf-s2/nginx-ctf.conf}
BIN_NGINX=${BIN_NGINX:-$(command -v nginx || true)}
ETC=${NGINX_ETC:-/etc/nginx}
PUBLIC=$RACINE/demo/lab/public

[ -x "$BIN_NGINX" ] || { echo "❌ nginx introuvable : ce banc ne peut pas s'exécuter (BIN_NGINX=<binaire>)."; exit 2; }
[ -f "$ETC/fastcgi.conf" ] || { echo "❌ $ETC/fastcgi.conf introuvable (NGINX_ETC=<dossier>)."; exit 2; }
[ -f "$GABARIT" ] || { echo "❌ gabarit introuvable : $GABARIT"; exit 2; }

D=$(mktemp -d) || exit 2
arreter() {
    [ -s "$D/nginx.pid" ] && kill "$(cat "$D/nginx.pid")" 2>/dev/null
    rm -r -- "$D"
}
trap arreter EXIT

PORT=$(( 20000 + RANDOM % 20000 ))
mkdir -p "$D/snippets" "$D/tmp"
cp "$ETC/mime.types" "$ETC/fastcgi.conf" "$ETC/fastcgi_params" "$D/"
cp "$ETC/snippets/fastcgi-php.conf" "$D/snippets/"
for s in "$RACINE"/deploy/ctf-s2/snippets/*.conf; do
    sed -E "s#/var/log/nginx/#$D/#g" "$s" > "$D/snippets/$(basename "$s")"
done

# Quatre substitutions, et rien d'autre : l'écoute (un port sans privilège, sans TLS), la
# racine (ce dépôt), le journal (un format qui dit ce que nginx a fait), et les sockets des
# ouvriers (un chemin court qui n'existe nulle part : c'est voulu).
sed -E "
    /ssl_certificate|ssl_dhparam|options-ssl-nginx\.conf/d
    s#listen 443 ssl;#listen 127.0.0.1:$PORT;#
    /listen \[::\]:443/d
    s#listen 80 default_server; listen \[::\]:80 default_server;#listen 127.0.0.1:$(( PORT + 1 )) default_server;#
    s#/var/www/ctf-s2/#$RACINE/#g
    s#access_log /var/log/nginx/access\.log lab_anon;#access_log $D/acces.log essai;#
    s#/var/log/nginx/#$D/#g
    s#unix:/run/php/#unix:/nonexistent/#g
" "$GABARIT" > "$D/gabarit.conf"

cat > "$D/nginx.conf" <<EOF
worker_processes 1;
pid $D/nginx.pid;
error_log $D/erreurs.log;
events { worker_connections 512; }
http {
    include mime.types;
    log_format essai '\$request_uri|\$status|\$limit_req_status|\$upstream_addr';
    client_body_temp_path $D/tmp/corps;
    fastcgi_temp_path $D/tmp/fcgi;
    proxy_temp_path $D/tmp/proxy;
    uwsgi_temp_path $D/tmp/uwsgi;
    scgi_temp_path $D/tmp/scgi;
    include gabarit.conf;
}
EOF

if ! "$BIN_NGINX" -p "$D" -c "$D/nginx.conf" 2> "$D/lancement.log"; then
    echo "❌ nginx refuse ce gabarit :"; sed 's/^/     /' "$D/lancement.log" | head -5; exit 1
fi
for _ in 1 2 3 4 5 6 7 8 9 10; do [ -s "$D/nginx.pid" ] && break; sleep 0.2; done

# Chaque requête part d'une adresse de 127.0.0.0/8 dont ni le /24 ni le /16 n'ont servi :
# aucune file n'est entamée, ni celle d'un préfixe ni celle d'un fournisseur — ce banc lit
# un routage, pas un débit.
n=0
demander() {
    n=$(( n + 1 ))
    curl -s -o /dev/null -m 10 --interface "127.$(( n % 250 + 1 )).$(( n / 250 )).1" "$@" || true
}
U=http://127.0.0.1:$PORT
for f in "$PUBLIC"/api/*.php; do
    r=/api/$(basename "$f")
    demander "$U$r?nu"
    demander "$U$r/x.php?reste"
    demander -H 'Sec-Fetch-Site: same-origin' "$U$r?du-lab"
    demander -H 'Sec-Fetch-Site: cross-site' "$U$r?tiers"
    demander -H 'Sec-Fetch-Site: same-site' "$U$r?voisin"
done
for f in "$PUBLIC"/*.php; do
    r=/$(basename "$f")
    demander "$U$r?page"
    demander "$U$r/x.php?reste"
    demander -H 'Sec-Fetch-Site: cross-site' "$U$r?page-tiers"
done
demander "$U/api/zz_route_que_personne_n_a_ecrite.php?neuve"
demander "$U/api/zz-avec-un-tiret.php?neuve"
demander "$U/api/v2/zz_dans_un_sous_dossier.php?neuve"

kill "$(cat "$D/nginx.pid")" 2>/dev/null
for _ in 1 2 3 4 5 6 7 8 9 10; do [ -s "$D/nginx.pid" ] || break; sleep 0.2; done
J=$D/acces.log
[ -s "$J" ] || { echo "❌ le journal est vide : nginx n'a rien servi, ce banc ne prouve rien."; exit 1; }

# Champs du journal : 1 = chemin demandé, 2 = code, 3 = file appliquée, 4 = ouvriers visés.
# ⚠️ nginx écrit `-` pour une valeur vide : c'est à `-` qu'on compare, jamais à la chaîne vide.
echecs=0
verdict() {    # verdict <libellé> <lignes fautives>
    if [ -z "$2" ]; then
        echo "  ✅ $1"
    else
        echo "  ❌ $1"
        printf '%s\n' "$2" | head -8 | sed 's/^/       /'
        echecs=$(( echecs + 1 ))
    fi
}

routes=$(grep -c '?nu|' "$J")
[ "$routes" -ge 20 ] || { echo "❌ $routes route(s) d'API seulement au journal : le banc n'a pas parcouru le lab."; exit 1; }
[ "$(grep -c '?neuve|' "$J")" -eq 3 ] || { echo "❌ les trois routes inventées ne sont pas toutes au journal."; exit 1; }

verdict "chacune des $routes routes d'API passe par une file" \
    "$(awk -F'|' '$1 ~ /\?nu$/ && $3 == "-"' "$J")"
verdict "une route d'API que personne n'a écrite tombe dans une file, quels que soient son nom et son dossier" \
    "$(awk -F'|' '$1 ~ /\?neuve$/ && $3 == "-"' "$J")"
verdict "un reste de chemin n'atteint jamais PHP" \
    "$(awk -F'|' '$1 ~ /\?reste$/ && ($2 != 404 || $4 != "-")' "$J")"
verdict "un appel d'API venu d'un autre site, sous-domaine voisin compris, est refusé avant PHP" \
    "$(awk -F'|' '$1 ~ /\?(tiers|voisin)$/ && ($2 != 403 || $4 != "-")' "$J")"
verdict "un appel d'API parti d'une page du lab atteint PHP" \
    "$(awk -F'|' '$1 ~ /\?du-lab$/ && ($2 == 403 || $4 == "-")' "$J")"
verdict "une page ouverte depuis un autre site reste servie" \
    "$(awk -F'|' '$1 ~ /\?page-tiers$/ && ($2 == 403 || $4 == "-")' "$J")"

mal_rangees=""
attendre() {   # attendre <chemin et marque, en expression régulière> <groupe d'ouvriers>
    grep -q "^$1|[^|]*|[^|]*|.*/php8\.4-fpm-$2\.sock" "$J" || mal_rangees="$mal_rangees
$1 : attendue chez les ouvriers « $2 » — $(grep "^$1|" "$J" || echo 'absente du journal')"
}
for r in $LOURDES;      do attendre "/api/$r\.php?nu" lourd; done
for r in $CALCUL;       do attendre "/api/$r\.php?nu" calcul; done
for r in $PAGES_CALCUL; do attendre "/$r\.php?page" calcul; done
verdict "les routes et les pages qui hachent vont aux ouvriers qui hachent, jamais à ceux des pages" "${mal_rangees#$'\n'}"

# Le journal dit QU'une file s'est appliquée, pas lesquelles. Les deux files communes — la
# somme d'un préfixe, la part d'un fournisseur — se lisent donc dans le gabarit : tout bloc
# qui confie aux ouvriers qui hachent doit les nommer toutes les deux.
sans_part=$(awk '
    /^[[:space:]]*location .*\{[[:space:]]*$/ { bloc = $0; texte = ""; dedans = 1; next }
    dedans && /^[[:space:]]*\}[[:space:]]*$/ {
        dedans = 0
        if (texte ~ /fpm-calcul\.sock/ && !(texte ~ /zone=lab_calcul / && texte ~ /zone=lab_calcul_fournisseur /)) print bloc
        if (texte ~ /fpm-lourd\.sock/  && !(texte ~ /zone=lab_lourd /  && texte ~ /zone=lab_lourd_fournisseur /))  print bloc
        next
    }
    dedans { texte = texte "\n" $0 }
' "$GABARIT")
verdict "tout bloc qui confie aux ouvriers qui hachent compte par préfixe ET par fournisseur" "$sans_part"

echo
if [ "$echecs" -eq 0 ]; then
    echo "✅ 8/8 — chaque route du lab est derrière ses freins"
else
    echo "❌ $echecs propriété(s) sur 8 ne tiennent pas"
    exit 1
fi
