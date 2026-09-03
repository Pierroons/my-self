#!/bin/bash
# Les gabarits de vhost sont-ils syntaxiquement valides ?
#
# 🔑 **Pourquoi ce contrôle existe.** Les gabarits nginx du dépôt n'étaient
# validés par rien. Un gabarit cassé se découvrait donc sur la machine, au
# rechargement — c'est-à-dire au pire moment, avec le service déjà arrêté ou
# sur le point de l'être.
#
# ⚠️ **Un gabarit ne passe pas `nginx -t` tel quel, et c'est normal.** Il porte
# des marques dépersonnalisées (`your-instance.example`) et des chemins de
# certificat qui n'existent nulle part. nginx s'arrête sur le certificat AVANT
# de lire le reste : sans substitution, on ne teste rien du tout et l'échec
# ressemble à un défaut du gabarit. On substitue donc, et on lui donne un
# certificat jetable, pour que le test porte sur ce qu'on veut mesurer.
#
# Où il tourne : ici si `nginx` est installé, sinon sur l'hôte nommé par
# `VHOST_TEST_HOST` (par SSH). Sans l'un ni l'autre, il ÉCHOUE — un contrôle
# qu'on ne peut pas lancer ne doit pas rendre vert. Pour l'autoriser à passer
# son tour en connaissance de cause : `VHOST_TEST_SKIP_OK=1`.
#
# Usage :
#   bash scripts/check-vhost.sh
#   VHOST_TEST_HOST=mon-serveur bash scripts/check-vhost.sh
set -uo pipefail
cd "$(git rev-parse --show-toplevel)"

echec=0

gabarits=$(git ls-files '*.conf' | xargs grep -l 'server_name' 2>/dev/null || true)
if [ -z "$gabarits" ]; then
    echo "▸ Gabarits de vhost"
    echo "  ⚠ aucun gabarit trouvé — rien à valider"
    exit 0
fi

# 🔑 Les snippets que les gabarits incluent vivent dans le DÉPÔT, pas dans le
# `/etc/nginx` de la machine de test. Sans eux, un `include snippets/X.conf;`
# fait échouer le gabarit sur un fichier manquant — le rouge qui accuse le
# gabarit au lieu de la recette, contre lequel la copie plus bas met déjà en
# garde. Les trois gabarits ont cassé ainsi le jour où l'include a été câblé.
#
# Ils voyagent avec le gabarit, encodés : la recette part aussi par SSH, où rien
# du dépôt local n'est lisible.
snippets=$(git ls-files 'deploy/*/snippets/*.conf')
# Ils sont mis à plat dans un seul répertoire, parce que c'est ainsi qu'un vhost
# les inclut. Deux fichiers de même nom s'y masqueraient, et le gabarit serait
# validé contre le mauvais — un vert qui ne mesure pas ce qu'il annonce.
doublons=$(printf '%s\n' "$snippets" | xargs -r -n1 basename | sort | uniq -d)
if [ -n "$doublons" ]; then
    echo "▸ Gabarits de vhost"
    echo "  ✗ deux snippets portent le même nom : $(printf '%s' "$doublons" | tr '\n' ' ')"
    echo "    Mis à plat, l'un masquerait l'autre et le gabarit serait validé"
    echo "    contre le mauvais fichier."
    exit 1
fi
SNIPPETS_B64=$(for f in $snippets; do
    printf '%s %s\n' "$(basename "$f")" "$(base64 -w0 < "$f")"
done | base64 -w0)

# Le corps du test, joué tel quel ici ou à distance.
#
# ⚠️ Le gabarit y est EMBARQUÉ en base64, il n'arrive pas par l'entrée standard.
# La première version le passait sur stdin derrière le script lui-même : `bash -s`
# consommait le flux entier, le `cat` ne recevait rien, et les trois gabarits
# échouaient sans un mot d'explication. Deux choses ne peuvent pas partager une
# seule entrée standard.
recette() {
printf 'GABARIT_B64=%s\n' "$(base64 -w0 < "$1")"
printf 'SNIPPETS_B64=%s\n' "$SNIPPETS_B64"
cat <<'RECETTE'
set -u
D=$(mktemp -d /tmp/vhost-test-XXXXXX) || exit 2
trap 'rm -rf "$D"' EXIT
mkdir -p "$D/logs" "$D/certs"
printf '%s' "$GABARIT_B64" | base64 -d > "$D/gabarit-brut.conf"

# Tout ce qu'un vhost peut inclure par chemin RELATIF : ces includes se résolvent
# depuis le préfixe, donc depuis $D. En oublier un fait échouer le gabarit sur un
# fichier manquant — un rouge qui accuse le gabarit au lieu de la recette.
for f in /etc/nginx/*.conf /etc/nginx/*_params /etc/nginx/mime.types; do
    [ -r "$f" ] && cp "$f" "$D/" 2>/dev/null
done
[ -d /etc/nginx/snippets ] && cp -r /etc/nginx/snippets "$D/" 2>/dev/null
# Ceux du dépôt priment : un gabarit versionné doit être validé contre le
# snippet que le dépôt fournit, jamais contre l'homonyme qu'une machine de test
# porterait par hasard.
mkdir -p "$D/snippets"
printf '%s' "$SNIPPETS_B64" | base64 -d | while read -r nom contenu; do
    [ -n "$nom" ] || continue
    printf '%s' "$contenu" | base64 -d > "$D/snippets/$nom"
done
# `nginx.conf` du système n'a rien à faire ici : c'est le nôtre qui pilote.
rm -f "$D/nginx.conf" 

# ⚠️ `nginx -t` OUVRE les sockets d'écoute : sur 80 et 443 il lui faudrait root.
# On décale vers des ports hauts, le temps du test. Ce qui est vérifié reste la
# syntaxe des directives `listen` et tout le reste du fichier ; ce qui ne l'est
# pas, c'est que 80 et 443 soient libres sur la machine — et ce n'est pas le rôle
# de ce contrôle.
P80=18080
P443=18443

# Certbot pose `options-ssl-nginx.conf` sur une machine qui sert du TLS, et les
# gabarits l'incluent. Il n'existe pas sur un runner d'intégration : le test y
# échouait sur un fichier absent, en accusant le gabarit. On le fabrique, avec le
# contenu que certbot y met — ainsi le contrôle ne dépend plus de ce qui est
# installé sur la machine qui l'exécute.
mkdir -p "$D/letsencrypt"
cat > "$D/letsencrypt/options-ssl-nginx.conf" <<'SSLOPT'
ssl_session_cache shared:le_nginx_SSL:10m;
ssl_session_timeout 1440m;
ssl_session_tickets off;
ssl_protocols TLSv1.2 TLSv1.3;
ssl_prefer_server_ciphers off;
ssl_ciphers "ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384";
SSLOPT
# 2048 bits : OpenSSL 3 refuse plus court (« dh key too small ») et le test
# échouait alors sur SA propre clé, pas sur le gabarit. `-dsaparam` rend la
# génération quasi instantanée — ces paramètres ne sont pas un secret, ils ne
# servent qu'à faire démarrer le test.
openssl dhparam -dsaparam -out "$D/letsencrypt/ssl-dhparams.pem" 2048 >/dev/null 2>&1 || true

openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
    -keyout "$D/certs/k.pem" -out "$D/certs/c.pem" \
    -subj "/CN=gabarit.test" >/dev/null 2>&1 || { echo "openssl indisponible"; exit 2; }

# Les placeholders deviennent des valeurs que nginx peut charger, et les chemins
# de journal des valeurs qu'un utilisateur ordinaire peut écrire. On ne corrige
# pas le gabarit : on le rend testable, le temps du test.
sed -E "s#/etc/letsencrypt/live/[^/]+/fullchain\.pem#$D/certs/c.pem#g;
        s#/etc/letsencrypt/live/[^/]+/privkey\.pem#$D/certs/k.pem#g;
        s#/etc/letsencrypt/live/[^/]+/chain\.pem#$D/certs/c.pem#g;
        s#your-instance\.example#gabarit.test#g;
        s#/var/log/nginx/#$D/logs/#g;
        s#/etc/letsencrypt/options-ssl-nginx\.conf#$D/letsencrypt/options-ssl-nginx.conf#g;
        s#/etc/letsencrypt/ssl-dhparams\.pem#$D/letsencrypt/ssl-dhparams.pem#g;
        s#(listen[[:space:]]+([^;]*[[:space:]])?)80([[:space:];])#\\1${P80}\\3#g;
        s#(listen[[:space:]]+([^;]*[[:space:]])?)443([[:space:];])#\\1${P443}\\3#g;
        s#(listen[[:space:]]+\[::\]:)80([[:space:];])#\\1${P80}\\2#g;
        s#(listen[[:space:]]+\[::\]:)443([[:space:];])#\\1${P443}\\2#g" \
        "$D/gabarit-brut.conf" > "$D/gabarit.conf"

# `pid` et `error_log` dans le répertoire jetable : par défaut nginx les veut
# sous /run et /var/log, que seul root peut écrire. Les déplacer ici est ce qui
# permet au test de se passer de privilèges.
{
  printf 'pid %s/nginx.pid;\n' "$D"
  printf 'error_log %s/logs/error.log;\n' "$D"
  printf 'events { worker_connections 16; }\n'
  printf 'http {\n'
  printf '  access_log %s/logs/access.log;\n' "$D"
  printf '  client_body_temp_path %s/logs;\n' "$D"
  printf '  proxy_temp_path %s/logs;\n' "$D"
  printf '  fastcgi_temp_path %s/logs;\n' "$D"
  printf '  include mime.types;\n'
  printf '  include gabarit.conf;\n'
  printf '}\n'
} > "$D/nginx.conf"

# nginx vit en /usr/sbin, hors du PATH d'un utilisateur ordinaire. Le chercher
# par son chemin évite d'exiger root pour un test qui n'écrit que dans un
# répertoire jetable — la première version passait par `sudo` pour cette seule
# raison, sans en avoir besoin.
NGINX=$(command -v nginx || true)
for c in /usr/sbin/nginx /usr/local/sbin/nginx /usr/local/nginx/sbin/nginx; do
    [ -n "$NGINX" ] && break
    [ -x "$c" ] && NGINX="$c"
done
[ -n "$NGINX" ] || { echo "nginx introuvable sur cet hôte"; exit 2; }
"$NGINX" -p "$D" -t -c "$D/nginx.conf" 2>&1
RECETTE
}

nginx_local=$(command -v nginx 2>/dev/null || true)
for c in /usr/sbin/nginx /usr/local/sbin/nginx; do
    [ -n "$nginx_local" ] && break
    [ -x "$c" ] && nginx_local="$c"
done

if [ -n "$nginx_local" ]; then
    ou="ici ($("$nginx_local" -v 2>&1 | sed 's#nginx version: ##'))"
    lancer() { recette "$1" | bash -s; }
elif [ -n "${VHOST_TEST_HOST:-}" ]; then
    ou="sur ${VHOST_TEST_HOST} (par SSH)"
    lancer() { recette "$1" | ssh -o BatchMode=yes "$VHOST_TEST_HOST" 'bash -s'; }
else
    echo "▸ Gabarits de vhost"
    if [ "${VHOST_TEST_SKIP_OK:-}" = "1" ]; then
        echo "  • nginx introuvable et VHOST_TEST_HOST non défini — passé, sur demande explicite"
        exit 0
    fi
    echo "  ✗ nginx introuvable et VHOST_TEST_HOST non défini : ce contrôle ne peut pas s'exécuter."
    echo "    Installe nginx, nomme un hôte (VHOST_TEST_HOST=…), ou assume le saut"
    echo "    avec VHOST_TEST_SKIP_OK=1. Un contrôle qu'on ne peut pas lancer ne rend pas vert."
    exit 1
fi

echo "▸ Gabarits de vhost — validés ${ou}"
while read -r g; do
    [ -z "$g" ] && continue
    sortie=$(lancer "$g" 2>&1)
    if printf '%s' "$sortie" | grep -q "test is successful"; then
        echo "  ✓ ${g}"
    else
        echo "  ✗ ${g}"
        printf '%s\n' "$sortie" | grep -E "emerg|error|warn" | head -3 | sed 's/^/       /'
        echec=1
    fi
done <<< "$gabarits"

exit $echec
