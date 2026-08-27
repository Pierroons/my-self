#!/bin/bash
# Bi-Self demo — Tests d'intégration E2E.
#
# Lance une batterie de curl contre les endpoints live pour vérifier que
# chaque pièce du système répond correctement. Exécuter en local depuis
# une machine whitelist (LAN plage LAN privée) ou avec cookie bypass.
#
# Usage :
#   ./integration.sh                    # contre prod bi-self.my-self.fr
#   BASE=http://localhost ./integration.sh   # contre dev local

set -u

BASE="${BASE:-https://bi-self.my-self.fr}"
COOKIES="/tmp/biself-integration-cookies.txt"
rm -f "$COOKIES"
FAIL=0

pass() { printf "  \033[32m✓\033[0m %s\n" "$1"; }
fail() { printf "  \033[31m✗\033[0m %s — %s\n" "$1" "$2"; FAIL=$((FAIL + 1)); }
title() { printf "\n\033[34m▶ %s\033[0m\n" "$1"; }

# ===================================================================
title "Session management"
# ===================================================================

http_code=$(curl -s -o /tmp/body.json -w "%{http_code}" -X POST \
    -H "Content-Type: application/json" \
    -d '{"module":"selfrecover"}' \
    -c "$COOKIES" "$BASE/demo/api/session")
[ "$http_code" = "201" ] && pass "POST /session (create) → 201" || fail "POST /session" "HTTP $http_code"

session_id=$(python3 -c "import json;print(json.load(open('/tmp/body.json'))['session']['session_id'])" 2>/dev/null)
[ -n "$session_id" ] && pass "session_id valid UUID: $session_id" || fail "session_id" "empty"

http_code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIES" "$BASE/demo/api/session")
[ "$http_code" = "200" ] && pass "GET /session (read) → 200" || fail "GET /session" "HTTP $http_code"

# ===================================================================
title "SelfRecover E2E"
# ===================================================================

# Le nom d'hôte est celui que le navigateur LIRAIT : on l'extrait de $BASE au
# lieu de l'écrire, sinon ce script ne saurait éprouver qu'une seule instance.
HOTE=$(printf '%s' "$BASE" | sed -E 's#^[a-z]+://##; s#[:/].*$##' | tr 'A-Z' 'a-z')

# ⚠️ Cette fonction RECOPIE la formule de `client/sr-derive.js`, faute de
# navigateur ici. Une recopie ne vaut que si on la confronte : le contrôle qui
# suit la compare à un vecteur figé de la bibliothèque, et rougit si l'une des
# deux bouge sans l'autre.
derive() {  # derive <mot> <sel> <matériel> [mode=hostname]
    python3 -c "import hashlib,hmac,sys
mot,sel,mat,mode = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
# ⚠️ La minuscule ne s'applique QU'AU mode hostname. La première version de ce
# miroir l'appliquait toujours, et divergeait donc sur un label capitalisé —
# sans que rien ne rougisse, parce que le contrôle ci-dessous ne regardait qu'un
# seul vecteur, en mode hostname. Un garde-fou qui n'éprouve qu'un cas ne garde
# que ce cas.
m = mat.lower() if mode == 'hostname' else mat
print(hmac.new(mot.encode(), (m+'|v2'+sel).encode(), hashlib.sha256).hexdigest())" "$1" "$2" "$3" "${4:-hostname}"
}

VECTEURS="$(dirname "$0")/../../../bi-self/selfrecover/tests/vecteurs-derivation.json"
if [ -r "$VECTEURS" ]; then
    # TOUS les vecteurs, pas le premier : les modes et les casses se répartissent
    # entre eux, et n'en éprouver qu'un laisse passer une formule fausse ailleurs.
    n_vect=$(python3 -c "import json,sys;print(len(json.load(open(sys.argv[1]))['vecteurs']))" "$VECTEURS")
    diverge=0
    i=0
    while [ "$i" -lt "$n_vect" ]; do
        read -r v_mot v_sel v_mat v_mode v_att <<< "$(python3 -c "
import json,sys
v = json.load(open(sys.argv[1]))['vecteurs'][int(sys.argv[2])]
print(v['mot'], v['sel'], v['materiel'], v['mode'], v['empreinte'])" "$VECTEURS" "$i")"
        [ "$(derive "$v_mot" "$v_sel" "$v_mat" "$v_mode")" = "$v_att" ] || diverge=$((diverge + 1))
        i=$((i + 1))
    done
    [ "$diverge" -eq 0 ] \
        && pass "la formule de ce script retrouve les $n_vect vecteurs figés de la bibliothèque" \
        || fail "formule de dérivation" "$diverge vecteur(s) sur $n_vect divergent de client/sr-derive.js"
else
    fail "vecteurs de dérivation" "introuvables — la formule ci-dessous n'est confrontée à rien"
fi

username="test$(openssl rand -hex 2)"
word="mot-memorise-$(openssl rand -hex 3)"
sel=$(openssl rand -hex 16)
derived=$(derive "$word" "$sel" "$HOTE")

http_code=$(curl -s -o /tmp/body.json -w "%{http_code}" -X POST \
    -H "Content-Type: application/json" \
    -d "{\"username\":\"$username\",\"recovery_derived_key\":\"$derived\",\"recovery_salt\":\"$sel\"}" \
    -b "$COOKIES" -c "$COOKIES" "$BASE/demo/api/recover/register")
[ "$http_code" = "200" ] && pass "register → 200" || fail "register" "HTTP $http_code"

password=$(python3 -c "import json;print(json.load(open('/tmp/body.json'))['credentials']['password'])" 2>/dev/null)
passphrase=$(python3 -c "import json;print(json.load(open('/tmp/body.json'))['credentials']['passphrase'])" 2>/dev/null)
[ -n "$password" ] && pass "credentials retournés (password, passphrase)" || fail "credentials" "missing"

# Le mot mémorisé ne doit PAS revenir : le serveur ne l'a jamais reçu.
python3 -c "import json,sys;sys.exit(0 if 'recovery_word' not in json.load(open('/tmp/body.json'))['credentials'] else 1)" \
    && pass "le serveur ne renvoie pas le mot mémorisé" \
    || fail "fuite du mot mémorisé" "la réponse porte recovery_word"

http_code=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
    -H "Content-Type: application/json" \
    -d "{\"username\":\"$username\",\"password\":\"$password\"}" \
    -b "$COOKIES" -c "$COOKIES" "$BASE/demo/api/recover/login")
[ "$http_code" = "200" ] && pass "login → 200" || fail "login" "HTTP $http_code"

http_code=$(curl -s -o /tmp/body.json -w "%{http_code}" -X POST \
    -H "Content-Type: application/json" \
    -d "{\"username\":\"$username\",\"passphrase\":\"$passphrase\"}" \
    -b "$COOKIES" -c "$COOKIES" "$BASE/demo/api/recover/recover-l1")
[ "$http_code" = "200" ] && pass "recover-l1 → 200" || fail "recover-l1" "HTTP $http_code"

# La route de sel : elle doit rendre CELUI du compte, et jamais d'erreur.
salt_rendu=$(curl -s -X POST -H "Content-Type: application/json" \
    -d "{\"username\":\"$username\"}" \
    -b "$COOKIES" "$BASE/demo/api/recover/sel" \
    | python3 -c "import json,sys;print(json.load(sys.stdin).get('sel',''))" 2>/dev/null)
[ "$salt_rendu" = "$sel" ] && pass "sel → celui du compte" || fail "sel" "rendu=$salt_rendu attendu=$sel"

# Anti-oracle : un compte inconnu doit rendre un sel lui aussi, deux fois le même.
faux1=$(curl -s -X POST -H "Content-Type: application/json" -d '{"username":"nexistepas"}' \
    -b "$COOKIES" "$BASE/demo/api/recover/sel" | python3 -c "import json,sys;print(json.load(sys.stdin).get('sel',''))" 2>/dev/null)
faux2=$(curl -s -X POST -H "Content-Type: application/json" -d '{"username":"nexistepas"}' \
    -b "$COOKIES" "$BASE/demo/api/recover/sel" | python3 -c "import json,sys;print(json.load(sys.stdin).get('sel',''))" 2>/dev/null)
{ [ ${#faux1} -eq 32 ] && [ "$faux1" = "$faux2" ] && [ "$faux1" != "$sel" ]; } \
    && pass "sel → un compte inconnu rend un faux sel, stable, distinct du vrai" \
    || fail "anti-oracle de /sel" "faux1=$faux1 faux2=$faux2"

# La dérivation faite sous le VRAI nom d'hôte est reconnue.
hmac_legit=$(derive "$word" "$sel" "$HOTE")
http_code=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
    -H "Content-Type: application/json" \
    -d "{\"username\":\"$username\",\"derived_key\":\"$hmac_legit\"}" \
    -b "$COOKIES" -c "$COOKIES" "$BASE/demo/api/recover/phishing-check")
[ "$http_code" = "200" ] && pass "phishing-check (nom d'hôte réel) → 200" || fail "phishing-check" "HTTP $http_code"

# Celle faite sous un autre nom d'hôte doit être refusée : c'est l'anti-hameçonnage.
hmac_phish=$(derive "$word" "$sel" "phishing-$(printf '%s' "$HOTE" | tr '.' '-').local")
http_code=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
    -H "Content-Type: application/json" \
    -d "{\"username\":\"$username\",\"derived_key\":\"$hmac_phish\"}" \
    -b "$COOKIES" -c "$COOKIES" "$BASE/demo/api/recover/phishing-check")
[ "$http_code" = "401" ] && pass "phishing-check (autre nom d'hôte) → 401" || fail "anti-hameçonnage" "HTTP $http_code au lieu de 401"

# Phishing sim
verdict=$(curl -s -X POST \
    -H "Content-Type: application/json" \
    -d "{\"username\":\"$username\",\"derived_key_legit\":\"$hmac_legit\",\"derived_key_phishing\":\"$hmac_phish\"}" \
    -b "$COOKIES" "$BASE/demo/api/recover/phishing-sim" | python3 -c "import json,sys;print(json.load(sys.stdin)['verdict'])" 2>/dev/null)
[ "$verdict" = "expected" ] && pass "phishing-sim verdict = expected (legit match, phishing no-match)" || fail "phishing-sim" "verdict=$verdict"

# Code viewer
files=$(curl -s "$BASE/demo/api/recover/code?file=register" -b "$COOKIES" | python3 -c "import json,sys;d=json.load(sys.stdin);print('ok' if d['ok'] and len(d['content']) > 100 else 'ko')")
[ "$files" = "ok" ] && pass "code viewer (register.php) retourne du contenu" || fail "code viewer" "$files"

# ===================================================================
title "SelfModerate E2E"
# ===================================================================

rm -f "$COOKIES"
curl -s -c "$COOKIES" -X POST -H "Content-Type: application/json" -d '{"module":"selfmoderate"}' "$BASE/demo/api/session" > /dev/null

users_count=$(curl -s -b "$COOKIES" "$BASE/demo/api/moderate/users" | python3 -c "import json,sys;print(len(json.load(sys.stdin)['users']))")
[ "$users_count" = "5" ] && pass "5 bots préchargés" || fail "users count" "$users_count"

visitor_id=$(curl -s -b "$COOKIES" -c "$COOKIES" -X POST -d '{}' "$BASE/demo/api/moderate/create-identity" | python3 -c "import json,sys;print(json.load(sys.stdin)['visitor_id'])")
[ -n "$visitor_id" ] && pass "create-identity → id=$visitor_id" || fail "create-identity" "empty"

vote_rep=$(curl -s -b "$COOKIES" -X POST -H "Content-Type: application/json" \
    -d '{"target_id":1,"value":-1,"reason":"test"}' "$BASE/demo/api/moderate/vote" | python3 -c "import json,sys;print(json.load(sys.stdin)['new_reputation'])")
[ "$vote_rep" = "17" ] && pass "vote -1 sur alice → rep 17" || fail "vote" "rep=$vote_rep"

sleep 1
pack_raw=$(curl -s -b "$COOKIES" -X POST -H "Content-Type: application/json" \
    -d '{"target_id":5}' "$BASE/demo/api/moderate/trigger-pack")
pack=$(echo "$pack_raw" | python3 -c "import json,sys;print(json.load(sys.stdin)['detection']['pack_detected'])" 2>/dev/null || echo "parse_err")
[ "$pack" = "True" ] && pass "pack-voting détecté sur eve" || fail "pack-voting" "got '$pack' raw='$(echo "$pack_raw" | head -c 120)'"

# ===================================================================
title "Duo synergy (test manuel)"
# ===================================================================
# ⚠️ CE CAS N'EST PAS COUVERT — et il ne l'était pas davantage avant.
#
# Cette ligne rendait `pass` depuis toujours, en s'appuyant sur un chiffre qui
# n'existe pas : elle invoquait « le rate-limit nginx `biself_demo 30r/m
# burst=10` » et « ~12 slots du burst » pour justifier de ne rien éprouver. Le
# vhost versionné (`deploy/bi-self/nginx-bi-self.conf:5,28`) déclare
# `zone=biself rate=10r/s burst=20`, sur `$binary_remote_addr` — 600 requêtes
# par minute, vingt fois ce qui était écrit, et par IP plutôt que par session.
# Ni le nom de zone, ni le débit, ni le burst, ni la granularité ne
# correspondaient.
#
# Un `pass` qui n'éprouve rien est un faux vert : il compte dans le total et
# rend la suite plus verte qu'elle n'est. Le cas se teste à la main via
# https://bi-self.my-self.fr/duo, et le banc le DIT au lieu de le compter.
echo "  ⚠ sybil-attack : non couvert par ce banc — à éprouver à la main via /duo" 

# ===================================================================
rm -f "$COOKIES" /tmp/body.json
echo
if [ "$FAIL" -eq 0 ]; then
    printf "\033[32m=== Tous les tests passent ✓ ===\033[0m\n"
    exit 0
else
    printf "\033[31m=== %d test(s) échoué(s) ✗ ===\033[0m\n" "$FAIL"
    exit 1
fi
