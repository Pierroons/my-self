#!/bin/bash
# SelfJustice — collecte quotidienne de la jurisprudence administrative (fonds
# JADE de la DILA), lancée chaque soir par `jade-update.timer`.
#
# La DILA publie un incrément par jour ouvré, vers 21 h–22 h ;
# `build_jade_db.py --depuis auto` applique tous ceux qui manquent depuis le
# dernier appliqué. Sans ce pilote, le fonds n'avait été construit qu'une fois,
# à la main, le 11/09/2026 — et le MCP servait le Conseil d'État arrêté à la
# veille, sans que rien ne le dise.
#
# 🔑 **Pas de copie de sauvegarde de la base**, contrairement à Judilibre. JADE
# n'écrit que par INSERT OR REPLACE, et `dernier_diff` n'avance qu'après une
# archive entière : une collecte interrompue laisse un sous-ensemble cohérent,
# que le passage suivant complète. Copier 6 Go chaque soir pour s'en protéger
# coûterait plus que le risque.

set -uo pipefail

INSTALL_DIR="${SELFJUSTICE_DIR:-/opt/selfjustice}"
DB="${JUDILIBRE_DB:-${SELFJUSTICE_DB_DIR:-/var/lib/selfjustice/db}/judilibre_index.sqlite}"
CACHE="${SELFJUSTICE_JADE_CACHE:-/var/lib/selfjustice/jade-cache}"
LOG_FILE="${SELFJUSTICE_JADE_LOG:-$INSTALL_DIR/update_jade.log}"
# Cherché à côté de ce script : tools/ dans le dépôt, bin/ sur le serveur.
SCRIPT="$(dirname "$(readlink -f "$0")")/build_jade_db.py"

# Une panne de la DILA d'un soir ne sonne pas ; un fonds qui n'a rien reçu
# depuis une semaine, si — la DILA publie chaque jour ouvré.
AGE_ALERTE=7
# Les incréments déjà appliqués se re-téléchargent : le cache ne garde que le mois.
AGE_CACHE=30

# Le jeton vit dans /etc, pas dans /root : l'unité applique `ProtectHome=true`,
# qui rend /root invisible au service — le piège déjà rencontré pour la clé
# Judilibre.
NTFY_URL="${SELFJUSTICE_NTFY_URL:-}"
NTFY_TOKEN_FILE="${SELFJUSTICE_NTFY_TOKEN_FILE:-/etc/selfjustice/ntfy.token}"

journal() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"; }

alerter() {
    local titre="$1" message="$2"
    journal "ALERTE : $titre — $message"
    [ -n "$NTFY_URL" ] && [ -r "$NTFY_TOKEN_FILE" ] || return 0
    curl -fsS -m 10 --retry 2 \
        -H "Authorization: Bearer $(cat "$NTFY_TOKEN_FILE")" \
        -H "Title: $titre" \
        -H "Priority: high" -H "Tags: warning,scales" \
        -d "$message" "$NTFY_URL" > /dev/null 2>&1 || true
}

# Âge en jours du dernier incrément appliqué, lu dans la base ; vide si illisible.
age_dernier() {
    python3 - "$DB" <<'PY' 2>/dev/null
import datetime, re, sqlite3, sys
ligne = sqlite3.connect(sys.argv[1]).execute(
    "SELECT valeur FROM jade_etat WHERE cle='dernier_diff'").fetchone()
m = re.match(r"JADE_(\d{8})-", ligne[0] if ligne else "")
if m:
    jour = datetime.datetime.strptime(m.group(1), "%Y%m%d").date()
    print((datetime.date.today() - jour).days)
PY
}

journal "=== Début collecte JADE ==="
mkdir -p "$CACHE"

rc=0
python3 "$SCRIPT" --depuis auto --db "$DB" --cache "$CACHE" >> "$LOG_FILE" 2>&1 || rc=$?
age=$(age_dernier)

case "$rc" in
    0)
        journal "Collecte faite — dernier incrément appliqué il y a ${age:-?} jour(s)."
        ;;
    4)
        if [ -z "$age" ] || [ "$age" -gt "$AGE_ALERTE" ]; then
            alerter "SelfJustice — DILA injoignable, JADE en retard" \
                    "Dernier increment JADE applique il y a ${age:-?} jours. Voir $LOG_FILE."
            exit 1
        fi
        journal "DILA injoignable — dernier incrément il y a $age jour(s), alerte au-delà de $AGE_ALERTE."
        ;;
    *)
        alerter "SelfJustice — collecte JADE en echec" \
                "Code $rc. La base garde tout increment applique en entier. Voir $LOG_FILE."
        exit 1
        ;;
esac

find "$CACHE" -maxdepth 1 -name 'JADE_*.tar.gz' -mtime +"$AGE_CACHE" -delete
journal "=== Terminé ==="
