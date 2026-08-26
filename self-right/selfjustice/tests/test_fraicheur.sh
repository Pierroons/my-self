#!/bin/bash
# Garde-fou — ce que la sentinelle de fraîcheur appelle un retard.
#
# 🔑 Cette sonde a crié sept matins de suite sur une base parfaitement à jour.
# Deux natures de dates lui arrivent sous le même nom : le catalogue SelfAct
# publie l'horodatage de sa propre synchronisation, LEGI publie la date du
# dernier diff mis en ligne par la DILA — laquelle précède forcément notre
# passage et n'avance plus jusqu'au suivant. La sentinelle exigeait des deux
# qu'elles atteignent le 1er ou le 15. Du 17 au 21/08/2026 elle a donc signalé
# « LEGI : arrêtée au 2026-08-14 » chaque jour, avec un compteur qui montait ;
# et quand le catalogue a réellement décroché le 20, son alerte est arrivée en
# cinquième ligne d'un message qui criait pour rien depuis quatre jours.
#
# Les cas ci-dessous fixent donc les deux règles qui l'ont remplacée — une date
# qui recule, une date qui n'a pas bougé alors qu'une échéance est passée — et,
# tout autant, ceux où elle doit se taire.
#
# Les dates de l'état sont calculées par rapport à l'échéance exigible du jour
# où le test tourne, jamais écrites en dur : figées, elles diraient autre chose
# chaque quinzaine.
#
# Usage : bash tests/test_fraicheur.sh [chemin/vers/check_fraicheur.sh]
# Sortie : 0 si les cas se comportent comme attendu.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
SONDE="${1:-$ICI/../tools/check_fraicheur.sh}"
[ -r "$SONDE" ] || { echo "sentinelle introuvable : $SONDE" >&2; exit 1; }
command -v python3 >/dev/null || { echo "python3 introuvable" >&2; exit 1; }

BAC=$(mktemp -d)
SERVEUR=""
trap '[ -n "$SERVEUR" ] && kill "$SERVEUR" 2>/dev/null; rm -rf "$BAC"' EXIT

# ── Les dates de référence, dérivées de l'échéance du jour ───────────────────
lire_dates() {
    python3 - <<'PY'
import datetime as dt
j = dt.date.today()
if   j.day > 15: att = j.replace(day=15)
elif j.day > 1:  att = j.replace(day=1)
else:
    v = j - dt.timedelta(days=1)
    att = v.replace(day=15) if v.day >= 15 else v.replace(day=1)
# ATTENDU : l'échéance que la sentinelle exige. AVANT : un jour antérieur,
# donc une date vue avant l'échéance. APRES : le lendemain de l'échéance.
print(att.isoformat(), (att - dt.timedelta(days=5)).isoformat(),
      (att + dt.timedelta(days=1)).isoformat())
PY
}
read -r ATTENDU AVANT APRES < <(lire_dates)

# ── Un amont de contrefaçon, piloté par deux fichiers ────────────────────────
cat > "$BAC/amont.py" <<'PY'
import json, os, pathlib
from http.server import BaseHTTPRequestHandler, HTTPServer

BAC = pathlib.Path(os.environ["BAC"])

class H(BaseHTTPRequestHandler):
    def do_GET(self):
        nom = "status.json" if self.path.endswith("/status") else "catalog.json"
        corps = (BAC / nom).read_bytes()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(corps)))
        self.end_headers()
        self.wfile.write(corps)
    def log_message(self, *a):
        pass

HTTPServer(("127.0.0.1", int(os.environ["PORT"])), H).serve_forever()
PY

for _ in 1 2 3; do
    PORT=$(( 8200 + RANDOM % 700 ))
    printf '{}' > "$BAC/status.json"; printf '{}' > "$BAC/catalog.json"
    BAC="$BAC" PORT="$PORT" python3 "$BAC/amont.py" >/dev/null 2>&1 &
    candidat=$!
    for _ in $(seq 40); do
        curl -sf -o /dev/null "http://127.0.0.1:$PORT/status" && break
        sleep 0.1
    done
    if curl -sf -o /dev/null "http://127.0.0.1:$PORT/status"; then SERVEUR=$candidat; break; fi
    kill "$candidat" 2>/dev/null
done
[ -n "$SERVEUR" ] || { echo "l'amont de test n'a pas démarré" >&2; exit 1; }

echecs=0
ok()  { echo "  ✓ $1"; }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

# 🔑 `/status` porte TROIS blocs, et la sentinelle les lit tous : une source
# absente lui devient « date illisible », ce qui est un retard. Les deux qui ne
# sont pas sous examen sont donc tenues saines, sans quoi chaque cas crierait
# pour une raison étrangère à ce qu'il éprouve.
#
# jouer <date LEGI> <volume LEGI> <date catalogue> <volume catalogue> <état JSON>
jouer() {
    python3 - "$1" "$2" "$ATTENDU" <<'PY' > "$BAC/status.json"
import json, sys
d, v, sain = sys.argv[1], int(sys.argv[2]), sys.argv[3]
print(json.dumps({
    "legi": {"articles": v, "last_update": d},
    "eu": {"articles": 793, "last_update": sain},
    "jurisprudence": {"decisions": 1191177, "last_update": sain},
}))
PY
    python3 - "$3" "$4" <<'PY' > "$BAC/catalog.json"
import json, sys
d, v = sys.argv[1], int(sys.argv[2])
print(json.dumps({"meta": {"last_sync": d, "total": v}}))
PY
    printf '%s' "$5" > "$BAC/etat.json"
    # HOME détourné : la sentinelle charge ~/.check-fraicheur.env avant tout, et
    # une configuration réelle ferait interroger la vraie instance.
    HOME="$BAC" \
    SELFRIGHT_API_URL="http://127.0.0.1:$PORT" \
    SELFRIGHT_ACT_URL="http://127.0.0.1:$PORT" \
    CHECK_FRAICHEUR_ETAT="$BAC/etat.json" \
    CHECK_FRAICHEUR_SILENCE_FICHIER="$BAC/silence" \
    bash "$SONDE" --verbeux 2>&1
}

etat() { # etat <date> <volume LEGI> <vu_le> [date catalogue] [volume catalogue]
    # Chaque source garde son propre volume : partager le compteur ferait passer
    # 525 441 articles pour 1 890 modèles et déclencherait une fausse baisse.
    python3 - "$1" "$2" "$3" "${4:-$1}" "${5:-1890}" "$ATTENDU" <<'PY'
import json, sys
d, v, vu = sys.argv[1], int(sys.argv[2]), sys.argv[3]
dc, vc, sain = sys.argv[4], int(sys.argv[5]), sys.argv[6]
print(json.dumps({
    "LEGI": {"date": d, "volume": v, "vu_le": vu},
    "catalogue SelfAct": {"date": dc, "volume": vc, "vu_le": vu},
    "conventionnalité": {"date": sain, "volume": 793, "vu_le": sain},
    "jurisprudence": {"date": sain, "volume": 1191177, "vu_le": sain},
}))
PY
}

# ── Ce qui doit rester silencieux ────────────────────────────────────────────
echo "▸ Une base à jour de son amont ne déclenche rien"

# Le cas des sept fausses alertes : LEGI porte la date du diff DILA, antérieure
# à l'échéance, et vue le jour même où la synchronisation a tourné.
# Le catalogue est tenu strictement immobile : c'est LEGI qu'on éprouve ici,
# et une source qui bouge à côté ferait crier le cas pour une autre raison.
sortie=$(jouer "$AVANT" 525441 "$ATTENDU" 1890 "$(etat "$AVANT" 525441 "$ATTENDU" "$ATTENDU" 1890)")
if grep -q "^RETARD" <<<"$sortie"; then
    nok "date d'amont antérieure à l'échéance, vue à l'échéance → $(grep '^RETARD' <<<"$sortie")"
else
    ok "date d'amont antérieure à l'échéance, mais vue à l'échéance → silence"
fi

# Première mesure : aucun historique, donc rien d'affirmable.
sortie=$(jouer "$AVANT" 525441 "$AVANT" 1890 "{}")
if grep -q "^RETARD" <<<"$sortie"; then
    nok "premier passage sans état → $(grep '^RETARD' <<<"$sortie")"
else
    ok "premier passage sans état → silence, la comparaison commence demain"
fi

# ── Ce qui doit crier ────────────────────────────────────────────────────────
echo
echo "▸ Une date qui recule"
# Le décrochage réel du 20/08 : une copie plus ancienne écrase la fraîche.
sortie=$(jouer "$ATTENDU" 525441 "$AVANT" 1895 "$(etat "$ATTENDU" 525441 "$ATTENDU" "$ATTENDU" 1890)")
if grep -q "RECUL" <<<"$sortie"; then
    ok "$AVANT après $ATTENDU → RECUL signalé"
else
    nok "une date qui recule n'est pas signalée : ${sortie//$'\n'/ }"
fi

echo
echo "▸ Une date qui ne bouge plus"
# Le cron mort : la date est celle d'avant l'échéance, et elle l'était déjà.
sortie=$(jouer "$AVANT" 525441 "$AVANT" 1890 "$(etat "$AVANT" 525441 "$AVANT")")
if grep -q "figée" <<<"$sortie"; then
    ok "vue avant l'échéance et inchangée depuis → FIGÉE signalée"
else
    nok "une base figée sur une échéance passée n'est pas signalée : ${sortie//$'\n'/ }"
fi

echo
echo "▸ Un retard qui dure ne redevient pas vert"
# 🔑 La fenêtre de silence existe pour ne pas renotifier le même fait chaque
# jour, et ce raisonnement est juste. Mais elle sortait aussi en 0 : une base
# arrêtée depuis vingt jours rendait vert dès le deuxième passage, et une
# supervision branchée sur le code de sortie voyait le retard disparaître
# pendant qu'il durait. Le canal se tait, le verdict non.
#
# Le fichier de silence n'est écrit qu'après un envoi réussi, et le banc n'a pas
# de canal : on l'arme donc à la main, avec le message que le premier passage
# vient de produire — c'est exactement ce que la sonde y aurait écrit.
sortie=$(jouer "$AVANT" 525441 "$AVANT" 1890 "$(etat "$AVANT" 525441 "$AVANT")")
message=$(sed -n 's/^RETARD : //p' <<<"$sortie" | head -1)
if [ -z "$message" ]; then
    nok "le premier passage n'a pas crié : le silence reste inéprouvé"
else
    printf '%s|%s\n' "$(date +%s)" "$message" > "$BAC/silence"
    second=$(jouer "$AVANT" 525441 "$AVANT" 1890 "$(etat "$AVANT" 525441 "$AVANT")")
    code=$?
    rm -f "$BAC/silence"
    if [ "$code" -ne 0 ] && grep -q "le retard dure" <<<"$second"; then
        ok "second passage, même retard → canal muet mais RC=$code"
    else
        nok "un retard qui dure rend RC=$code au second passage : ${second//$'\n'/ }"
    fi
fi

echo
echo "▸ Les contrôles de volume tiennent toujours"
# Marqueur avancé, volume identique : la synchronisation en trompe-l'œil.
sortie=$(jouer "$APRES" 525441 "$ATTENDU" 1890 "$(etat "$ATTENDU" 525441 "$ATTENDU")")
if grep -q "trompe-l" <<<"$sortie"; then
    ok "date avancée, volume figé → trompe-l'œil signalé"
else
    nok "le trompe-l'œil n'est plus détecté : ${sortie//$'\n'/ }"
fi

# Volume en baisse à date égale.
sortie=$(jouer "$ATTENDU" 525000 "$ATTENDU" 1890 "$(etat "$ATTENDU" 525441 "$ATTENDU")")
if grep -q "BAISSE" <<<"$sortie"; then
    ok "volume en baisse → signalé"
else
    nok "une baisse de volume n'est pas signalée : ${sortie//$'\n'/ }"
fi

# 🔑 Le cas mesuré le 21/08/2026 : le catalogue a été resynchronisé et a rendu
# 1 895 modèles — exactement le compte de la copie du 3 août qu'il remplaçait,
# alors que douze de ses seize catégories avaient changé. La règle du
# trompe-l'œil ne comparait que le total ; elle a crié sur une synchronisation
# bien réelle, le soir même où l'on venait de la corriger de ses fausses alertes.
echo
echo "▸ Le trompe-l'œil regarde tous les compteurs, pas seulement le total"

repartition() { # repartition <date> <total> <travail> <logement>
    python3 - "$1" "$2" "$3" "$4" <<'PY' > "$BAC/catalog.json"
import json, sys
d, t, a, b = sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), int(sys.argv[4])
print(json.dumps({"meta": {"last_sync": d, "total": t,
                           "categories": {"travail": a, "logement": b}}}))
PY
}

etat_avec_empreinte() { # <date> <total> <travail> <logement> <vu_le>
    python3 - "$1" "$2" "$3" "$4" "$5" "$ATTENDU" <<'PY' > "$BAC/etat.json"
import json, sys
d, t, a, b, vu, sain = sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), int(sys.argv[4]), sys.argv[5], sys.argv[6]
print(json.dumps({
    "catalogue SelfAct": {"date": d, "volume": t, "vu_le": vu,
                          "empreinte": {"total": t, "categories.travail": a,
                                        "categories.logement": b}},
    "LEGI": {"date": sain, "volume": 525441, "vu_le": sain,
             "empreinte": {"articles": 525441}},
    "conventionnalité": {"date": sain, "volume": 793, "vu_le": sain,
                         "empreinte": {"articles": 793}},
    "jurisprudence": {"date": sain, "volume": 1191177, "vu_le": sain,
                      "empreinte": {"decisions": 1191177}},
}))
PY
}

lancer() {
    HOME="$BAC" \
    SELFRIGHT_API_URL="http://127.0.0.1:$PORT" \
    SELFRIGHT_ACT_URL="http://127.0.0.1:$PORT" \
    CHECK_FRAICHEUR_ETAT="$BAC/etat.json" \
    CHECK_FRAICHEUR_SILENCE_FICHIER="$BAC/silence" \
    bash "$SONDE" --verbeux 2>&1
}

python3 - "$ATTENDU" <<'PY' > "$BAC/status.json"
import json, sys
sain = sys.argv[1]
print(json.dumps({
    "legi": {"articles": 525441, "last_update": sain},
    "eu": {"articles": 793, "last_update": sain},
    "jurisprudence": {"decisions": 1191177, "last_update": sain},
}))
PY

# Même total, répartition différente : la base a bougé.
etat_avec_empreinte "$AVANT" 1895 213 208 "$AVANT"
repartition "$ATTENDU" 1895 211 207
sortie=$(lancer)
if grep -q "trompe-l" <<<"$sortie"; then
    nok "total identique mais répartition changée → trompe-l'œil crié à tort"
else
    ok "total identique, 2 catégories déplacées → silence, la base a bougé"
fi

# Rien n'a bougé du tout : là, le marqueur ment.
etat_avec_empreinte "$AVANT" 1895 213 208 "$AVANT"
repartition "$ATTENDU" 1895 213 208
sortie=$(lancer)
if grep -q "trompe-l" <<<"$sortie"; then
    ok "aucun compteur déplacé → trompe-l'œil signalé"
else
    nok "une base strictement immobile à date avancée passe inaperçue"
fi

echo
echo "▸ Une source injoignable reste un retard"
kill "$SERVEUR" 2>/dev/null; SERVEUR=""
sortie=$(jouer "$ATTENDU" 525441 "$ATTENDU" 1890 "$(etat "$ATTENDU" 525441 "$ATTENDU")")
if grep -q "injoignable" <<<"$sortie"; then
    ok "amont éteint → injoignable signalé, pas « tout va bien »"
else
    nok "un amont éteint passe pour sain : ${sortie//$'\n'/ }"
fi

echo
if [ "$echecs" -eq 0 ]; then
    echo "OK — tous les cas conformes."
    exit 0
fi
echo "ÉCHEC — $echecs cas." >&2
exit 1
