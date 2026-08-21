#!/bin/bash
# SelfAct — wrapper cron pour scraper.php.
#
# Ajout crontab (bimensuel 1er + 15 à 03:30 Europe/Paris, cohérent SelfJustice) :
#   30 3 1,15 * * <install-dir>/api/act/update_catalog.sh >> /var/log/selfact-catalog.log 2>&1
#
# ## Pourquoi ce script alerte désormais
#
# 🔑 **Il échouait proprement, et personne ne l'entendait.** Le catalogue servi
# a stagné au 18 avril 2026 plus de trois mois : `set -euo pipefail` faisait son
# travail, la sauvegarde était restaurée, le code de sortie était non nul — et
# rien ne lisait ce code. Le cron ne remonte rien par défaut.
#
# C'est le mode de défaillance de la synchronisation LEGI, qui a servi le droit
# au 13 juillet 2025 pendant treize mois : un script ponctuel dont le résultat
# est mort passe tous les contrôles portant sur son exécution.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
# 🔑 Le chemin est DEMANDÉ au résolveur, jamais réécrit ici. Producteur et
# consommateurs doivent viser le même fichier ; deux formulations de la même
# règle finissent par diverger, et la divergence ne se voit qu'au moment où
# l'un écrit là où l'autre ne lit plus.
CATALOG="$("$PHP_BIN" -r "require '$SCRIPT_DIR/chemins.php'; echo selfact_chemin_catalogue();" 2>/dev/null)"
if [ -z "$CATALOG" ]; then
    echo "erreur : chemins.php n'a pas rendu d'emplacement pour le catalogue." >&2
    exit 1
fi
mkdir -p "$(dirname "$CATALOG")"

# Au-delà de ce nombre de jours, le catalogue a manqué une échéance bimensuelle.
PEREMPTION_JOURS="${SELFACT_PEREMPTION_JOURS:-20}"

# --- Alerte ---------------------------------------------------------------
# Vides par défaut : renseignées par l'exploitant de l'instance, dans son
# environnement (le cron de SelfJustice exporte déjà SELFJUSTICE_NTFY_URL).
NTFY_URL="${SELFJUSTICE_NTFY_URL:-}"
NTFY_TOKEN_FILE="${SELFJUSTICE_NTFY_TOKEN_FILE:-/root/.config/selfjustice-ntfy-token}"

alerter() {
    local titre="$1" message="$2"
    echo "[$(date -Iseconds)] ALERTE : $titre — $message"
    [ -n "$NTFY_URL" ] && [ -r "$NTFY_TOKEN_FILE" ] || return 0
    curl -fsS -m 10 --retry 2 \
        -H "Authorization: Bearer $(cat "$NTFY_TOKEN_FILE")" \
        -H "Title: $titre" \
        -H "Priority: high" -H "Tags: warning,scroll" \
        -d "$message" "$NTFY_URL" > /dev/null 2>&1 || true
}

trap 'alerter "SelfAct — mise a jour du catalogue en echec" \
      "Arret ligne $LINENO. Le catalogue precedent reste servi."' ERR

cd "$SCRIPT_DIR"

echo "---"
echo "[$(date -Iseconds)] SelfAct update_catalog start"

if [ -f "$CATALOG" ]; then
    cp "$CATALOG" "$CATALOG.bak"
fi

set +e
"$PHP_BIN" "$SCRIPT_DIR/scraper.php" --verbose 2>&1
CODE=$?
set -e

if [ "$CODE" != "0" ]; then
    echo "[$(date -Iseconds)] scraper.php a rendu le code $CODE"
    if [ -f "$CATALOG.bak" ]; then
        mv "$CATALOG.bak" "$CATALOG"
        echo "[$(date -Iseconds)] catalog.json restauré depuis la sauvegarde"
    fi
    # Le code 3 est le refus d'écrire du scraper : il a trouvé nettement moins
    # d'entrées qu'en base et a préféré ne rien remplacer. Le garde-fou a bien
    # fonctionné, mais c'est le signe que la source a changé — donc ça se dit.
    if [ "$CODE" = "3" ]; then
        alerter "SelfAct — catalogue tronque, ecriture refusee" \
                "Le scraper a trouve nettement moins d entrees qu en base. Catalogue precedent conserve. Verifier si service-public.gouv.fr a change sa structure HTML."
    else
        alerter "SelfAct — scraper en echec (code $CODE)" \
                "Le catalogue precedent reste servi. Detail dans le log."
    fi
    exit 1
fi

rm -f "$CATALOG.bak"

# Volumétrie lue depuis le JSON, pas par un grep sur '"id":' — les entrées ne
# sont pas les seules à porter cette clé, et un compteur faux est pire que pas
# de compteur : il rassure.
# shellcheck disable=SC2016  # $d et $argv sont du PHP : les quotes simples sont voulues
LIRE_META='
    $d = json_decode(file_get_contents($argv[1]), true) ?: [];
    $m = $d["_meta"] ?? [];
    if ($argv[2] === "count")     { echo count($d["models"] ?? []); }
    if ($argv[2] === "last_sync") { echo $m["last_sync"] ?? ""; }
    if ($argv[2] === "types") {
        $o = []; foreach (($m["types"] ?? []) as $k => $v) { $o[] = "$k=$v"; }
        echo implode(", ", $o);
    }
'
COUNT=$("$PHP_BIN" -r "$LIRE_META" "$CATALOG" count 2>/dev/null || echo 0)
TYPES=$("$PHP_BIN" -r "$LIRE_META" "$CATALOG" types 2>/dev/null || echo "")
LAST_SYNC=$("$PHP_BIN" -r "$LIRE_META" "$CATALOG" last_sync 2>/dev/null || echo "")

echo "[$(date -Iseconds)] SelfAct update_catalog done — $COUNT entrées ($TYPES)"

# --- Le contenu est-il réellement frais ? ---------------------------------
#
# 🔑 Le contrôle porte sur la date écrite DANS le catalogue, jamais sur celle de
# cette exécution. Un scraper ponctuel qui produit un fichier inchangé passerait
# tout contrôle portant sur son propre déclenchement — c'est exactement ce qui
# a laissé le catalogue à trois mois et le droit français à treize.
if [ -n "$LAST_SYNC" ]; then
    AGE=$(( ( $(date +%s) - $(date -d "$LAST_SYNC" +%s) ) / 86400 ))
    if [ "$AGE" -gt "$PEREMPTION_JOURS" ]; then
        alerter "SelfAct — catalogue perime" \
                "Le catalogue date de $AGE jours alors que la synchronisation vient de tourner. Verifier que le scraper ecrit bien dans $CATALOG."
    fi
else
    alerter "SelfAct — catalogue sans date" \
            "Impossible de lire _meta.last_sync : le catalogue est peut-etre malforme."
fi
