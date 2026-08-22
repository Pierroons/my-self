#!/bin/bash
# Garde-fou — quel catalogue SelfAct est réellement servi.
#
# 🔑 **Deux écrivains ne partagent pas un chemin.** `catalog.json` est produit
# par une machine les 1er et 15 ; le code qui l'entoure est poussé depuis un
# poste de travail. Tant que les deux visaient `api/act/data/catalog.json`, le
# dernier qui écrivait gagnait — et le poste de travail porte toujours la copie
# la plus vieille, puisqu'il ne moissonne rien.
#
# Mesuré le 21/08/2026 : la synchronisation du 15 avait écrit 1 890 modèles à
# 03:30 ; un transfert du dépôt vers la production, le 20 à 21:27, a reposé
# par-dessus la copie versionnée du 3 août. Douze jours perdus, aucune commande
# en échec, et le décrochage n'a été vu que six jours plus tard.
#
# Le cas central ci-dessous est celui-là : **les deux fichiers coexistent**, et
# c'est celui de l'instance qui doit être lu. Sans cet ordre, le défaut reste
# seulement détectable ; avec lui, il devient inoffensif.
#
# Usage : bash tests/test_chemin_catalogue.sh
# Sortie : 0 si les cas se comportent comme attendu.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
ACT="$(cd "$ICI/../api" && pwd)"
command -v php >/dev/null || { echo "php introuvable" >&2; exit 1; }
[ -f "$ACT/chemins.php" ] || { echo "chemins.php introuvable : $ACT" >&2; exit 1; }

BAC=$(mktemp -d)
SERVEUR=""
trap '[ -n "$SERVEUR" ] && kill "$SERVEUR" 2>/dev/null; rm -rf "$BAC"' EXIT

echecs=0
ok()  { echo "  ✓ $1"; }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

# Une copie de travail : on ne touche pas au dépôt, et surtout pas à ses data.
cp -a "$ACT" "$BAC/act"
mkdir -p "$BAC/act/data" "$BAC/etat"

catalogue() { # catalogue <fichier> <total>  → un catalogue minimal reconnaissable
    printf '{"_meta":{"version":"test","last_sync":"2026-08-21","total":%s},"models":[]}' "$2" > "$1"
}

resoudre() { # resoudre [VAR=…] → ce que le résolveur rend
    env "$@" php -r "require '$BAC/act/chemins.php'; echo selfact_chemin_catalogue();"
}

echo "▸ Qui l'emporte, et dans quel ordre"

# 🔑 Le cas du 20/08 : les deux fichiers existent. C'est l'état de l'instance
# qui doit gagner, sans quoi un transfert du dépôt suffit à faire régresser le
# catalogue servi.
catalogue "$BAC/act/data/catalog.json" 1
catalogue "$BAC/etat/catalog.json" 2
obtenu=$(resoudre "SELFJUSTICE_VAR_DIR=$BAC/etat")
if [ "$obtenu" = "$BAC/etat/catalog.json" ]; then
    ok "les deux copies coexistent → celle de l'instance l'emporte"
else
    nok "les deux copies coexistent → $obtenu, la copie du dépôt peut encore écraser"
fi

# La présence du RÉPERTOIRE tranche, pas celle du fichier : au premier passage
# la synchronisation n'a rien écrit, et un test sur le fichier ferait écrire le
# producteur ici et lire les consommateurs là.
rm -f "$BAC/etat/catalog.json"
obtenu=$(resoudre "SELFJUSTICE_VAR_DIR=$BAC/etat")
if [ "$obtenu" = "$BAC/etat/catalog.json" ]; then
    ok "répertoire d'état présent, fichier pas encore écrit → l'état l'emporte quand même"
else
    nok "premier passage → $obtenu : le producteur écrirait ailleurs que là où on lit"
fi
catalogue "$BAC/etat/catalog.json" 2

# Un clone sans répertoire d'état doit rester utilisable.
obtenu=$(resoudre "SELFJUSTICE_VAR_DIR=$BAC/inexistant")
if [ "$obtenu" = "$BAC/act/data/catalog.json" ]; then
    ok "pas de répertoire d'état → repli sur la copie du dépôt"
else
    nok "pas de répertoire d'état → $obtenu, un clone ne trouverait rien"
fi

# L'exploitant garde le dernier mot.
obtenu=$(resoudre "SELFJUSTICE_VAR_DIR=$BAC/etat" "SELFACT_CATALOG=$BAC/impose.json")
if [ "$obtenu" = "$BAC/impose.json" ]; then
    ok "SELFACT_CATALOG imposé → respecté"
else
    nok "SELFACT_CATALOG imposé → $obtenu"
fi

echo
echo "▸ Producteur et consommateurs visent le même fichier"
# La règle est écrite une fois, dans chemins.php ; update_catalog.sh la demande
# au lieu de la reformuler. Ce cas vérifie que c'est toujours vrai.
ligne=$(grep -c "selfact_chemin_catalogue" "$BAC/act/update_catalog.sh" || true)
if [ "${ligne:-0}" -ge 1 ]; then
    ok "update_catalog.sh demande son chemin au résolveur"
else
    nok "update_catalog.sh réécrit le chemin au lieu de le demander"
fi
en_dur=$(grep -l "data/catalog.json" "$BAC/act"/*.php 2>/dev/null | grep -v chemins.php | wc -l)
if [ "$en_dur" -eq 0 ]; then
    ok "aucun fichier PHP hors chemins.php ne code le chemin en dur"
else
    nok "$en_dur fichier(s) PHP codent encore data/catalog.json en dur"
fi

echo
echo "▸ De bout en bout : quel catalogue l'API sert-elle vraiment"
for _ in 1 2 3; do
    port=$(( 8300 + RANDOM % 600 ))
    SELFJUSTICE_VAR_DIR="$BAC/etat" php -S "127.0.0.1:$port" -t "$BAC/act" >/dev/null 2>&1 &
    candidat=$!
    for _ in $(seq 40); do
        curl -sf -o /dev/null "http://127.0.0.1:$port/catalog.php" && break
        sleep 0.1
    done
    if curl -sf -o /dev/null "http://127.0.0.1:$port/catalog.php"; then
        SERVEUR=$candidat; BASE="http://127.0.0.1:$port"; break
    fi
    kill "$candidat" 2>/dev/null
done
if [ -z "$SERVEUR" ]; then
    nok "le serveur de test n'a pas démarré — contrôle de bout en bout non joué"
else
    total=$(curl -s "$BASE/catalog.php" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo $d["meta"]["total"] ?? "?";')
    if [ "$total" = "2" ]; then
        ok "l'API sert le catalogue de l'instance (total 2), pas celui du dépôt (total 1)"
    else
        nok "l'API sert un catalogue de total $total — attendu 2, celui de l'instance"
    fi
fi

echo
if [ "$echecs" -eq 0 ]; then
    echo "OK — tous les cas conformes."
    exit 0
fi
echo "ÉCHEC — $echecs cas." >&2
exit 1
