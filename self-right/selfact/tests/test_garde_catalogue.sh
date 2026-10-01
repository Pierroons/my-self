#!/bin/bash
# Garde-fou — le scraper refuse un catalogue dont une source est tronquée.
#
# 🔑 Le garde comparait le TOTAL : une source entière pouvait disparaître sans
# franchir le seuil, pourvu qu'elle pèse moins que lui. Il compare désormais
# chaque source à son volume précédent. Les retraits ordinaires de
# service-public, eux, doivent passer : c'est le cas du 01/10/2026, une collecte
# complète qui rendait 1 907 entrées contre 1 924.
#
# Usage : bash tests/test_garde_catalogue.sh
# Sortie : 0 si les cas se comportent comme attendu.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
GARDE="$(cd "$ICI/../api" && pwd)/garde_catalogue.php"
command -v php >/dev/null || { echo "php introuvable" >&2; exit 1; }
[ -f "$GARDE" ] || { echo "garde_catalogue.php introuvable : $GARDE" >&2; exit 1; }

echecs=0
ok()  { echo "  ✓ $1"; }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

# juger <volumes avant> <volumes après> → les sources tronquées, une par ligne.
# Les volumes s'écrivent `type=n,type=n` ; « avant » devient un catalogue
# d'entrées, comme celui que le scraper relit sur le disque.
juger() {
    php -r '
        require $argv[1];
        $lire = function (string $s): array {
            $r = [];
            foreach (array_filter(explode(",", $s)) as $p) {
                [$t, $n] = explode("=", $p);
                $r[$t] = (int) $n;
            }
            return $r;
        };
        $anciens = [];
        foreach ($lire($argv[2]) as $t => $n) {
            for ($i = 0; $i < $n; $i++) { $anciens[] = ["type" => $t]; }
        }
        foreach (selfact_sources_tronquees($anciens, $lire($argv[3])) as $l) { echo $l, "\n"; }
    ' "$GARDE" "$1" "$2"
}

echo "▸ Ce qui doit passer"
r=$(juger "formulaire=870,teleservice=715,modele_lettre=339" "formulaire=875,teleservice=708,modele_lettre=324")
if [ -z "$r" ]; then
    ok "retraits ordinaires (1 924 → 1 907, 01/10/2026) → écriture permise"
else
    nok "des retraits ordinaires sont refusés : $r"
fi

r=$(juger "" "formulaire=875,teleservice=708,modele_lettre=324")
if [ -z "$r" ]; then
    ok "aucun catalogue en place → écriture permise"
else
    nok "un premier catalogue est refusé : $r"
fi

echo
echo "▸ Ce qui doit être refusé"
# Le trou que ferme ce test : une source entière perdue pendant que le total
# reste au-dessus du plancher (1 585 sur 1 924, soit 82 %).
r=$(juger "formulaire=870,teleservice=715,modele_lettre=339" "formulaire=870,teleservice=715")
if grep -q "^modele_lettre 339 → 0" <<<"$r"; then
    ok "une source disparue, total à 82 % → refus, source nommée"
else
    nok "une source entière disparaît sans refus : « $r »"
fi

r=$(juger "formulaire=870,teleservice=715,modele_lettre=339" "formulaire=870,teleservice=500,modele_lettre=339")
if grep -q "^teleservice 715 → 500" <<<"$r" && [ "$(wc -l <<<"$r")" -eq 1 ]; then
    ok "une source à 70 % → refus de celle-là seule"
else
    nok "une source tronquée à 70 % : « $r »"
fi

echo
if [ "$echecs" -eq 0 ]; then
    echo "OK — le garde tient."
    exit 0
fi
echo "ÉCHEC — $echecs cas." >&2
exit 1
