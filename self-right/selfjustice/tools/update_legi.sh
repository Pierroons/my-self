#!/bin/bash
# SelfJustice — Mise à jour bimensuelle des bases.
#
# À lancer via cron le 1er et le 15 de chaque mois :
#   0 4 1,15 * * <install-dir>/update_legi.sh
#
# ## Ce que ce script faisait avant, et pourquoi ça ne suffisait pas
#
# Il téléchargeait les diffs quotidiens de DILA, puis reconstruisait la base
# avec `build_legi_db.py` — lequel ne lit **que le dump global**. Les diffs
# étaient donc téléchargés puis ignorés, et le script en supprimait même les
# plus anciens à chaque passage.
#
# Conséquence : de juillet 2025 à août 2026, la base a servi le droit au
# 13 juillet 2025 en l'annonçant honnêtement, sans que la date ne bouge jamais.
# Treize mois. Le script tournait à l'heure ; c'est son résultat qui était mort.
#
# ## La chaîne actuelle
#
#   legi.download    → récupère les nouveaux diffs
#   legi.tar2sqlite  → les APPLIQUE à la base de travail (incrémental)
#   extract_…py      → aplatit vers le schéma que sert l'API
#
# 🔑 `legi.tar2sqlite` est incrémental : il lit `db_meta.last_update` et reprend
# où il s'est arrêté. Une exécution bimensuelle ne traite que quinze diffs, pas
# les 389 de la reconstruction initiale.

set -e

# Répertoire d'installation — surchargeable pour ne pas dépendre d'un home utilisateur.
LEGI_DIR="${SELFJUSTICE_DIR:-/opt/selfjustice}"
DB_DIR="${SELFJUSTICE_DB_DIR:-/var/lib/selfjustice/db}"
TARBALLS_DIR="$LEGI_DIR/tarballs"
VENV="$LEGI_DIR/legi.py/venv/bin/python"

FULL_DB="$LEGI_DIR/legi-full.sqlite"          # base de travail (schéma legi.py)
API_DB="$DB_DIR/legi_selfjustice.sqlite"      # base servie par l'API
EU_DB="$DB_DIR/conventionnalite.sqlite"
DB_BACKUP="$LEGI_DIR/legi_selfjustice.sqlite.bak"
LOG_FILE="$LEGI_DIR/update_legi.log"
LAST_UPDATE_FILE="/var/lib/selfjustice/legi_last_update.txt"

# --- Alerte sur échec -------------------------------------------------------
#
# 🔑 **Ce script échouait correctement, et personne ne l'entendait.** De mai à
# août 2026, sept exécutions se sont arrêtées sur un `ModuleNotFoundError` : le
# `set -e` faisait son travail, le code de sortie était non nul, et rien ne le
# lisait. Le cron ne remonte rien par défaut.
NTFY_URL="${SELFJUSTICE_NTFY_URL:-}"
NTFY_TOKEN_FILE="${SELFJUSTICE_NTFY_TOKEN_FILE:-/root/.config/selfjustice-ntfy-token}"

alerter() {
    local titre="$1" message="$2"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ALERTE : $titre — $message" >> "$LOG_FILE"
    [ -n "$NTFY_URL" ] && [ -r "$NTFY_TOKEN_FILE" ] || return 0
    curl -fsS -m 10 --retry 2 \
        -H "Authorization: Bearer $(cat "$NTFY_TOKEN_FILE")" \
        -H "Title: $titre" \
        -H "Priority: high" -H "Tags: warning,scales" \
        -d "$message" "$NTFY_URL" > /dev/null 2>&1 || true
}

# 🔑 Le piège est gardé dans une variable parce qu'il faut savoir le remettre.
# `set +e` empêche le script de s'arrêter, mais **ne désarme pas le piège ERR** :
# une commande dont on tolère l'échec le déclenche quand même. Mesuré le
# 15/08/2026 — la synchronisation a réussi, 525 441 articles insérés, et une
# alerte « sync LEGI en echec. Base servie inchangee » est partie une seconde
# avant le « Mise à jour réussie » du même passage. Deux messages contraires
# dans la même minute, dont le faux porte la priorité haute.
#
# Le message nomme la commande plutôt que la ligne : `$LINENO` lu depuis un
# piège rend le numéro de la ligne courante de l'interpréteur, pas celui de la
# commande fautive — la même alerte du 15/08 désignait la ligne 162, qui lit une
# date dans SQLite et n'avait rien à voir avec l'échec.
PIEGE_ERR='alerter "SelfJustice — sync LEGI en echec" "Arret sur : $BASH_COMMAND. Base servie inchangee, voir $LOG_FILE."'
# shellcheck disable=SC2064  # expansion voulue : on installe la chaîne, dont
# le contenu est en apostrophes et reste donc différé au déclenchement.
trap "$PIEGE_ERR" ERR

journal() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"; }

# --- Une seule instance à la fois -------------------------------------------
#
# 🔑 **Deux exécutions simultanées se détruisent mutuellement.** Constaté le
# 15/08/2026 : deux passages lancés à la même seconde, tout le journal en
# double, et les deux téléchargements en concurrence sur le même dossier de
# tarballs. L'un renommait le `.part` que l'autre attendait —
# `FileNotFoundError: LEGI_20260803….tar.gz.part -> ….tar.gz` — pendant que le
# FTP de la DILA expirait sous les deux connexions.
#
# Le verrou est posé AVANT la première ligne de journal : c'est ce doublon-là,
# « === Début mise à jour LEGI === » écrit deux fois à la même seconde, qui a
# mis sur la piste. Une garde placée plus bas laisserait la trace ambiguë.
#
# `flock` plutôt qu'un `mkdir` ou un fichier de PID : le noyau libère le verrou
# tout seul, sans rien à nettoyer. Un verrou par fichier, lui, survit à la panne
# qu'il aurait dû protéger, et il faut le retirer à la main — souvent en
# découvrant qu'on ne synchronise plus depuis des semaines.
#
# 🔑 Le verrou tient tant qu'un processus ayant hérité du descripteur 9 est
# vivant, pas seulement ce script. Mesuré le 16/08/2026 : ce script tué par
# `kill -9`, son enfant survivant gardait le verrou ; la libération n'est venue
# qu'à la mort de ce dernier.
#
# C'est exactement ce qu'on veut ici. Si le pilote meurt alors que
# `legi.download` télécharge encore, le verrou couvre le travail réel et non le
# script qui l'a lancé — un second passage ne viendra pas écrire dans le dossier
# de tarballs qu'une instance orpheline remplit toujours.
#
# ⚠️ Le descripteur 9 reste ouvert jusqu'à la fin du script : c'est lui qui
# porte le verrou. Ne pas le refermer, ne pas le réutiliser.
LOCK_FILE="$LEGI_DIR/update_legi.lock"
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    journal "Instance déjà en cours (verrou $LOCK_FILE) — ce passage s'arrête"
    alerter "SelfJustice — sync LEGI déjà en cours" \
            "Un second lancement a ete refuse par le verrou. La synchronisation en cours n a pas ete perturbee, mais un doublon de planification est probable : verifier /etc/cron.d/selfjustice et la crontab root."
    exit 0
fi

cd "$LEGI_DIR"
journal "=== Début mise à jour LEGI ==="

# 1. Sauvegarder la base actuellement servie
if [ -f "$API_DB" ]; then
    cp "$API_DB" "$DB_BACKUP"
    journal "Sauvegarde : $DB_BACKUP"
fi

# 2. Récupérer les nouveaux diffs
journal "Téléchargement des dumps DILA"
"$VENV" -m legi.download "$TARBALLS_DIR" >> "$LOG_FILE" 2>&1

# 3. Les appliquer à la base de travail
#
# ⚠️ C'est l'étape que l'ancien script n'avait pas. Sans elle, les diffs
# téléchargés ne servent à rien et la base reste au dernier dump global.
journal "Application des diffs (legi.tar2sqlite)"
"$VENV" -m legi.tar2sqlite --pragma journal_mode=WAL "$FULL_DB" "$TARBALLS_DIR" >> "$LOG_FILE" 2>&1

# 4. Aplatir vers le schéma de l'API, dans un fichier temporaire
journal "Extraction vers le schéma de l'API"
"$VENV" "$LEGI_DIR/bin/extract_selfjustice_db.py" \
    --source "$FULL_DB" --dest "$API_DB.nouveau" >> "$LOG_FILE" 2>&1

# 5. Contrôler avant de servir
#
# Le script d'extraction refuse déjà de produire moins de 100 000 articles ;
# ce second contrôle porte sur le fichier réellement destiné à l'API.
NB_ARTICLES=$(sqlite3 "$API_DB.nouveau" "SELECT COUNT(*) FROM articles" 2>/dev/null || echo 0)
if [ "$NB_ARTICLES" -lt 100000 ]; then
    rm -f "$API_DB.nouveau"
    alerter "SelfJustice — extraction suspecte" \
            "Seulement $NB_ARTICLES articles extraits. La base servie n'a pas ete remplacee."
    exit 1
fi

# 6. Bascule
chown www-data:www-data "$API_DB.nouveau"
chmod 644 "$API_DB.nouveau"
mv "$API_DB.nouveau" "$API_DB"
journal "Base servie remplacée : $NB_ARTICLES articles"

# 7. Dater depuis la base elle-même
#
# 🔑 La date vient de `db_meta.last_update`, écrite par legi.tar2sqlite d'après
# le dernier diff appliqué. L'ancien script la déduisait du **nom du fichier
# global** — qui ne change jamais, d'où une date figée pendant treize mois.
LAST_RAW=$("$VENV" -c "
import sqlite3, sys
try:
    c = sqlite3.connect('file:$FULL_DB?mode=ro', uri=True)
    print(c.execute(\"SELECT value FROM db_meta WHERE key='last_update'\").fetchone()[0])
except Exception:
    sys.exit(1)
" 2>/dev/null || echo "")

MOIS=(janvier février mars avril mai juin juillet août septembre octobre novembre décembre)
if [ -n "$LAST_RAW" ]; then
    YYYY=${LAST_RAW:0:4}; MM=${LAST_RAW:4:2}; DD=${LAST_RAW:6:2}
    DATE_FR="$((10#$DD)) ${MOIS[$((10#$MM - 1))]} $YYYY"
    DATE_ISO="$YYYY$MM$DD"
else
    DATE_FR=$(date '+%-d')" ${MOIS[$(( $(date +%-m) - 1 ))]} "$(date +%Y)
    DATE_ISO=$(date +%Y%m%d)
fi
echo "$DATE_FR" > "$LAST_UPDATE_FILE"
journal "Base datée du $DATE_FR"

# 8. Le contenu est-il en retard sur la dernière échéance ?
#
# 🔑 Deux dates, et seule celle-ci compte. Que ce script tourne à l'heure ne dit
# rien de la fraîcheur du droit servi : le cron a été ponctuel tous les 1er et 15
# pendant que le contenu restait au 13 juillet 2025. Un contrôle sur la date
# d'exécution aurait donné son feu vert tout l'été.
JOUR=$(date +%-d)
if [ "$JOUR" -ge 15 ]; then
    ECHEANCE=$(date +%Y%m15)
else
    ECHEANCE=$(date -d "$(date +%Y-%m-01) -1 month +14 days" +%Y%m%d)
fi
if [ "$DATE_ISO" -lt "$ECHEANCE" ]; then
    JOURS=$(( ( $(date +%s) - $(date -d "${DATE_ISO:0:4}-${DATE_ISO:4:2}-${DATE_ISO:6:2}" +%s) ) / 86400 ))
    alerter "SelfJustice — base LEGI perimee" \
            "Contenu date du $DATE_FR, soit $JOURS jours de retard alors que la synchronisation vient de tourner. Verifier que DILA publie encore des diffs."
fi

# 9. Base de conventionnalité (UE + CEDH + AI Act)
#
# ⚠️ Le code 2 signifie « base utilisable, mais des sources n'ont pas pu être
# rafraîchies » — EUR-Lex génère ses pages à la demande et répond parfois 202
# au-delà de la patience du script. Ce n'est pas une raison d'interrompre la
# synchronisation LEGI qui vient de réussir, mais ça doit se savoir.
journal "Reconstruction de la base de conventionnalité"
set +e
trap - ERR
"$VENV" "$LEGI_DIR/bin/build_eu_db.py" --db "$EU_DB" >> "$LOG_FILE" 2>&1
EU_CODE=$?
# shellcheck disable=SC2064  # expansion voulue : on installe la chaîne, dont
# le contenu est en apostrophes et reste donc différé au déclenchement.
trap "$PIEGE_ERR" ERR
set -e
EU_ARTICLES=$(sqlite3 "$EU_DB" "SELECT COUNT(*) FROM articles" 2>/dev/null || echo 0)
chown www-data:www-data "$EU_DB"
journal "Conventionnalité : $EU_ARTICLES articles (code $EU_CODE)"
if [ "$EU_CODE" = "2" ]; then
    alerter "SelfJustice — sources UE non rafraichies" \
            "La base de conventionnalite compte $EU_ARTICLES articles et reste utilisable, mais au moins une source n a pas pu etre telechargee. Detail dans $LOG_FILE."
elif [ "$EU_CODE" != "0" ]; then
    alerter "SelfJustice — echec de la base UE" \
            "build_eu_db.py a rendu le code $EU_CODE. Voir $LOG_FILE."
fi
# Le mois vient du tableau MOIS, comme pour LEGI plus haut, et non de `date
# '+%B'` traduit depuis l'anglais : sur une instance en locale allemande ou
# espagnole, %B rendrait « August » ou « agosto », que le sed ne connaît pas.
# Le client lirait une date qu'il ne sait pas analyser et annoncerait la
# fraîcheur « indéterminée » — son avertissement le plus grave — déclenché par
# une simple variable d'environnement.
echo "$(date '+%-d') ${MOIS[$(( $(date +%-m) - 1 ))]} $(date +%Y)" \
    > /var/lib/selfjustice/eu_last_update.txt

# 10. Propager aux statistiques publiques
"$LEGI_DIR/bin/update_stats.sh" >> "$LOG_FILE" 2>&1 || true

# 11. Les tarballs sont CONSERVÉS
#
# ⚠️ L'ancien script ne gardait que les 30 derniers diffs. C'était cohérent avec
# `build_legi_db.py`, qui ne lisait que le global — et incompatible avec
# `legi.tar2sqlite`, qui a besoin de la chaîne complète pour reconstruire depuis
# zéro. Le 03/08/2026, ce nettoyage a obligé à re-télécharger 389 archives.
# 2,7 Go conservés valent mieux qu'une journée de re-téléchargement.
DISK=$(du -sh "$TARBALLS_DIR" | cut -f1)

# 🔑 **La date du CONTENU et celle de la SYNCHRONISATION sont deux choses.**
# `legi_last_update.txt` porte la date du dernier diff que la DILA a publié :
# elle précède forcément notre passage, et n'avance plus jusqu'au suivant. Les
# deux autres bases écrivaient, elles, leur date d'exécution sous le même nom.
#
# Trois bases, deux sémantiques, un seul nom de champ — et un client incapable
# de trancher. Constaté le 21/08/2026 : le serveur MCP annonçait « RETARD —
# base LEGI arrêtée au 14 août, l'échéance du 15 n'a pas été honorée » dans
# CHAQUE réponse, alors que la synchronisation du 15 avait tourné et pris le
# dernier diff disponible. Les deux autres bases passaient par accident.
#
# Ces marqueurs-ci ne disent qu'une chose : notre synchronisation a réussi ce
# jour-là. C'est ce qu'il faut pour juger d'un cron mort, et c'est tout ce que
# le client ne peut pas déduire seul.
date +%F > /var/lib/selfjustice/legi_last_sync.txt
date +%F > /var/lib/selfjustice/eu_last_sync.txt

journal "Mise à jour réussie : $NB_ARTICLES articles LEGI, $EU_ARTICLES UE, tarballs $DISK"
journal "=== Fin mise à jour LEGI ==="
