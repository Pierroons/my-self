#!/bin/bash
# Garde-fou — le guichet s'ouvre sur ce que l'index porte, et sur rien d'autre.
#
# 🔑 La liste des juridictions acceptées était écrite à la main à trois endroits
# — `juridiction_valide()`, le message de refus, et le moissonneur — quand
# `juris_couverture()` la lisait déjà dans la base. Ajouter une juridiction au
# moissonnage sans toucher les deux autres aurait rempli la base de décisions
# que l'API refuse de servir : la donnée présente, et la porte fermée devant.
#
# Ce contrôle éprouve la dérivation dans les DEUX sens, ce qu'un seul état ne
# peut pas faire : une fonction qui rendrait toujours ['cc','ca'] passerait un
# test qui ne lui montre qu'une base judiciaire. Il faut donc une base qui porte
# le Conseil d'État, et vérifier que le guichet s'ouvre tout seul — c'est
# exactement la propriété qu'on veut tenir au moment de moissonner « ce » pour
# de bon.
#
# Trois exécutions séparées, parce que `juridictions_servies()` met son résultat
# en cache : deux états ne cohabitent pas dans un même processus.
#
# Usage : bash tests/sanity_juridictions_servies.sh
set -uo pipefail
ICI="$(cd "$(dirname "$0")" && pwd)"
RACINE="$(cd "$ICI/.." && pwd)"
command -v php     >/dev/null || { echo "php introuvable" >&2; exit 1; }
command -v sqlite3 >/dev/null || { echo "sqlite3 introuvable" >&2; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf -- "$TMP"' EXIT
ECHECS=0
verdict() { if [ "$1" = "0" ]; then echo "  ✓ $2"; else echo "  ✗ $2"; ECHECS=$((ECHECS+1)); fi; }

# Une base minimale : seules les colonnes que juris_couverture() lit.
faire_base() {
    local db="$1"; shift
    sqlite3 "$db" "CREATE TABLE decisions (id TEXT PRIMARY KEY, number TEXT,
        decision_date TEXT, jurisdiction TEXT, date_suspecte INTEGER DEFAULT 0);"
    local i=0
    for j in "$@"; do
        i=$((i+1))
        sqlite3 "$db" "INSERT INTO decisions VALUES ('d$i','$i/000$i','2026-01-0$i','$j',0);"
    done
}

# Interroge les fonctions du code, jamais une copie de leur règle.
# $1 = chemin de base (peut ne pas exister) · $2 = juridiction à valider
sonder() {
    php -r '
        $src = file_get_contents($argv[1] . "/api/api.php");
        define("JURIS_DB", $argv[2]);
        const JURIDICTIONS_SANS_INDEX = ["cc", "ca"];
        foreach (["open_db", "juris_couverture", "juridictions_servies",
                  "juridiction_libelle", "juridiction_valide",
                  "message_juridiction_inconnue"] as $nom) {
            if (!preg_match("/^function " . $nom . "\(.*?^}$/ms", $src, $m)) {
                fwrite(STDERR, "$nom introuvable dans api/api.php\n"); exit(2);
            }
            eval($m[0]);
        }
        echo json_encode([
            "servies" => juridictions_servies(),
            "valide"  => juridiction_valide($argv[3]),
            "message" => message_juridiction_inconnue("zzz"),
        ], JSON_UNESCAPED_UNICODE);
    ' "$RACINE" "$1" "$2"
}

echo "▸ Sans index lisible — le repli, pas le refus général"
R="$(sonder "$TMP/absente.sqlite" ce)"
grep -q '"servies":\["cc","ca"\]' <<<"$R" ; verdict $? "la couverture retombe sur cc et ca"
grep -q '"valide":null'           <<<"$R" ; verdict $? "« ce » est refusé — l'index ne le porte pas"
grep -q 'ArianeWeb'               <<<"$R" ; verdict $? "le refus dit où chercher la justice administrative"

echo
echo "▸ Index judiciaire — l'état d'aujourd'hui"
faire_base "$TMP/judiciaire.sqlite" cc ca
R="$(sonder "$TMP/judiciaire.sqlite" ce)"
grep -q '"valide":null'      <<<"$R" ; verdict $? "« ce » reste refusé"
grep -q 'Cour de cassation'  <<<"$R" ; verdict $? "le refus nomme ce qu'il sert"
grep -q 'ArianeWeb'          <<<"$R" ; verdict $? "la réserve administrative est encore vraie, donc présente"

echo
echo "▸ Index élargi au Conseil d'État — le contre-témoin"
faire_base "$TMP/elargie.sqlite" cc ca ce
R="$(sonder "$TMP/elargie.sqlite" ce)"
grep -q '"valide":"ce"'    <<<"$R" ; verdict $? "« ce » est accepté SANS qu'on ait touché à api.php"
grep -q "Conseil d'État"   <<<"$R" ; verdict $? "le refus nomme le Conseil d'État parmi les juridictions servies"
if grep -q 'ArianeWeb' <<<"$R"; then
    verdict 1 "la réserve « relève d'ArianeWeb » a disparu — elle serait devenue fausse"
else
    verdict 0 "la réserve « relève d'ArianeWeb » a disparu — elle serait devenue fausse"
fi

echo
echo "▸ Ce que les collecteurs demandent doit être ce que le guichet nomme"
# Deux collecteurs alimentent la même table : Judilibre pour l'ordre judiciaire,
# JADE pour l'ordre administratif. Ne contrôler que le premier laissait un angle
# mort — `ta`, `tc` et `cdbf` sont entrés dans la base par JADE sans que rien
# n'exige leur libellé. Un garde-fou borné à une source ne signale pas ce qu'il
# cesse de couvrir : il rétrécit en silence.
JUDI="$(grep -oP 'JURIDICTIONS = \[\K[^]]+' "$RACINE/tools/build_judilibre_index.py" | tr -d '" ')"
JADE="$(grep -oP '^CODES = \(\K[^)]+' "$RACINE/tools/jade_juridictions.py" | tr -d '" ')"
MOISSON="$JUDI,$JADE"
SANS_LIBELLE="$(php -r '
    $src = file_get_contents($argv[1] . "/api/api.php");
    preg_match("/^function juridiction_libelle\(.*?^}$/ms", $src, $m); eval($m[0]);
    foreach (explode(",", $argv[2]) as $j) {
        if ($j !== "" && $j === juridiction_libelle($j)) echo "$j ";
    }
' "$RACINE" "$MOISSON")"
[ -z "$(echo "$SANS_LIBELLE" | tr -d ' ')" ]
verdict $? "chaque juridiction moissonnée ($MOISSON) a un libellé — sinon le refus afficherait un code brut"

echo
if [ "$ECHECS" -eq 0 ]; then
    echo "✓ La couverture se dérive de l'index — 10 contrôles."
    exit 0
fi
echo "✗ $ECHECS contrôle(s) en échec."
exit 1
