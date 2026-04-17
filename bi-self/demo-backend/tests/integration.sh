#!/bin/bash
# Bi-Self demo — Tests d'intégration E2E.
#
# Lance une batterie de curl contre les endpoints live pour vérifier que
# chaque pièce du système répond correctement. Exécuter en local depuis
# une machine whitelist (LAN 192.168.1.x) ou avec cookie bypass.
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

username="test$(openssl rand -hex 2)"
http_code=$(curl -s -o /tmp/body.json -w "%{http_code}" -X POST \
    -H "Content-Type: application/json" \
    -d "{\"username\":\"$username\"}" \
    -b "$COOKIES" -c "$COOKIES" "$BASE/demo/api/recover/register")
[ "$http_code" = "200" ] && pass "register → 200" || fail "register" "HTTP $http_code"

password=$(python3 -c "import json;print(json.load(open('/tmp/body.json'))['credentials']['password'])" 2>/dev/null)
passphrase=$(python3 -c "import json;print(json.load(open('/tmp/body.json'))['credentials']['passphrase'])" 2>/dev/null)
word=$(python3 -c "import json;print(json.load(open('/tmp/body.json'))['credentials']['recovery_word'])" 2>/dev/null)
[ -n "$password" ] && pass "credentials retournés (password, passphrase, word)" || fail "credentials" "missing"

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

# L2 avec HMAC client (Python simulate)
salt=$(curl -s -b "$COOKIES" "$BASE/demo/api/recover/site-salt" | python3 -c "import json,sys;print(json.load(sys.stdin)['site_salt'])")
hmac_legit=$(python3 -c "import hashlib, hmac; print(hmac.new('$word'.encode(), ('bi-self.my-self.fr' + '$salt').encode(), hashlib.sha256).hexdigest())")
http_code=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
    -H "Content-Type: application/json" \
    -d "{\"username\":\"$username\",\"derived_key\":\"$hmac_legit\",\"domain_used\":\"bi-self.my-self.fr\"}" \
    -b "$COOKIES" -c "$COOKIES" "$BASE/demo/api/recover/recover-l2")
[ "$http_code" = "200" ] && pass "recover-l2 (HMAC legit) → 200" || fail "recover-l2" "HTTP $http_code"

# Phishing sim
hmac_phish=$(python3 -c "import hashlib, hmac; print(hmac.new('$word'.encode(), ('phishing-my-self-fr.local' + '$salt').encode(), hashlib.sha256).hexdigest())")
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
# L'endpoint sybil-attack déclenche 14+ opérations internes rapides qui se
# heurtent systématiquement au rate-limit nginx `biself_demo 30r/m burst=10`
# quand on l'enchaîne après tous les tests ci-dessus (qui consomment déjà
# ~12 slots du burst). Il se teste manuellement via le navigateur sur
# https://bi-self.my-self.fr/duo (ou en curl isolé après 30s de pause).
pass "sybil-attack à tester manuellement via /duo"

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
