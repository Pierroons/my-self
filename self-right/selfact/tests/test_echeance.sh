#!/bin/bash
# Garde-fou — le calcul d'échéance de procédure, et son export vers un agenda.
#
# 🔑 C'est le seul outil du module qui calcule au lieu de restituer, et le seul
# dont une erreur ne se voit pas : une date fausse ressemble à une date juste.
# Un recours déposé un jour trop tard est irrecevable quel que soit le fond du
# dossier, donc les attendus ci-dessous sont calculés à la main d'après les
# articles 640 à 643 du code de procédure civile, jamais recopiés de la sortie
# du service — un attendu tiré de ce qu'on mesure grave le défaut avec le reste.
#
# Le second bloc existe à cause d'un écart mesuré le 21/08/2026 : la réponse
# annonçait le 15 avril pour un délai augmenté de la distance, et le lien
# d'agenda qu'elle proposait dans la même réponse inscrivait le 16 février. Le
# lien était réécrit à la main et avait oublié un paramètre. Deux sorties du
# même appel se contredisaient.
#
# Usage : bash tests/test_echeance.sh
# Sortie : 0 si les cas se comportent comme attendu.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
API="$(cd "$ICI/../api" && pwd)"
command -v php >/dev/null || { echo "php introuvable" >&2; exit 1; }
[ -f "$API/deadline.php" ] || { echo "deadline.php introuvable : $API" >&2; exit 1; }

# Le port est tiré au hasard dans une plage haute, et on réessaie : une machine
# d'intégration partagée peut très bien avoir déjà celui qu'on visait.
SERVEUR=""
BASE=""
trap '[ -n "$SERVEUR" ] && kill "$SERVEUR" 2>/dev/null' EXIT

for _ in 1 2 3; do
    port=$(( 8100 + RANDOM % 900 ))
    php -S "127.0.0.1:$port" -t "$API" >/dev/null 2>&1 &
    candidat=$!
    essai="http://127.0.0.1:$port/deadline.php"
    for _ in $(seq 40); do
        curl -sf -o /dev/null "$essai?start=2026-01-15&months=1" && break
        sleep 0.1
    done
    if curl -sf -o /dev/null "$essai?start=2026-01-15&months=1"; then
        SERVEUR=$candidat
        BASE=$essai
        break
    fi
    kill "$candidat" 2>/dev/null
done
[ -n "$BASE" ] || { echo "le serveur de test n'a pas démarré après trois tentatives" >&2; exit 1; }

echecs=0
ok()  { echo "  ✓ $1"; }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

champ() { # champ <requête> <clé>
    # shellcheck disable=SC2016  # $d et $argv sont du PHP, pas du shell
    curl -s "$BASE?$1" | php -r '$d = json_decode(file_get_contents("php://stdin"), true); echo $d[$argv[1]] ?? "";' "$2"
}

echo "▸ Les règles des articles 641 à 643"
# Chaque ligne : requête | échéance attendue | la règle qu'elle éprouve
while IFS='|' read -r requete attendu regle; do
    [ -z "$requete" ] && continue
    obtenu=$(champ "$requete" echeance)
    if [ "$obtenu" = "$attendu" ]; then
        ok "$attendu — $regle"
    else
        nok "$regle : attendu $attendu, obtenu ${obtenu:-（rien）}  ($requete)"
    fi
done <<'CAS'
start=2026-01-01&days=15|2026-01-16|délai en jours, le jour de départ ne compte pas (641 al. 1)
start=2026-01-15&months=1|2026-02-16|même quantième, dimanche prorogé au lundi (641 al. 2 + 642)
start=2026-01-31&months=1|2026-03-02|quantième absent, dernier jour du mois puis week-end
start=2026-04-01&months=1|2026-05-04|le 1er mai est férié, report au premier jour ouvrable
start=2024-02-29&years=1|2025-02-28|29 février inexistant l'année suivante
start=2026-01-15&months=1&distance=outremer|2026-03-16|art. 643, un mois pour l'outre-mer
start=2026-01-15&months=1&distance=etranger|2026-04-15|art. 643, deux mois pour l'étranger
CAS

echo
echo "▸ Un lieu inconnu est refusé, pas interprété"
for lieu in corse suisse 1 ""; do
    code=$(curl -s -o /dev/null -w "%{http_code}" "$BASE?start=2026-01-15&months=1&distance=$lieu")
    # Une valeur vide retombe sur la métropole : c'est l'absence de choix, pas
    # un choix erroné. Toute autre valeur doit être refusée plutôt que devinée,
    # sans quoi un délai d'outre-mer mal orthographié rendrait celui de la
    # métropole, plus court d'un mois.
    if [ -z "$lieu" ]; then
        if [ "$code" = "200" ]; then
            ok "vide → 200, la métropole par défaut"
        else
            nok "vide → $code, alors que l'absence de choix est licite"
        fi
    elif [ "$code" = "400" ]; then
        ok "« $lieu » → 400"
    else
        nok "« $lieu » → $code, la valeur a été acceptée ou devinée"
    fi
done

echo
echo "▸ L'agenda porte la date que la réponse annonce"
# 🔑 Le lien est proposé par la réponse elle-même : il doit mener à la date
# qu'elle vient d'annoncer. Rien ne l'impose techniquement, et c'est justement
# ce qui a manqué.
for suffixe in "" "&distance=outremer" "&distance=etranger"; do
    requete="start=2026-01-15&months=1$suffixe"
    annoncee=$(champ "$requete" echeance)
    lien=$(champ "$requete" ics)
    [ -n "$lien" ] || { nok "aucun lien d'agenda pour $requete"; continue; }
    inscrite=$(curl -s "$BASE$lien" | grep -m1 "^DTSTART" | grep -oE "[0-9]{8}")
    attendue="${annoncee//-/}"
    if [ "$inscrite" = "$attendue" ]; then
        ok "${suffixe:-métropole} — réponse et agenda disent $annoncee"
    else
        nok "${suffixe:-métropole} — la réponse dit $annoncee, l'agenda inscrit ${inscrite:-（rien）}"
    fi
done

echo
if [ "$echecs" -eq 0 ]; then
    echo "OK — tous les cas conformes."
    exit 0
fi
echo "ÉCHEC — $echecs cas." >&2
exit 1
