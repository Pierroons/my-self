#!/usr/bin/env bash
# Banc du format SELFVAULT2.
#
# Chaque contrôle est éprouvé sur le défaut qu'il prétend attraper, et chaque
# refus a son contre-témoin.
#
# 🔑 Il pilote LES DEUX lecteurs : `outils/test_webcrypto.mjs`, réimplémentation
# indépendante sous Node, et `pli/selfvault.html`, l'application réelle découpée
# en QR codes que le dépositaire ouvrira. Le second était le seul des trois
# porteurs du format que rien n'éprouvait : on pouvait y planter trois défauts
# cryptographiques sans qu'aucun contrôle ne bouge.
#
# Usage : bash tests/banc.sh
# Sortie : 0 si tout est conforme, 1 sinon.
set -uo pipefail
MODULE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BANC="$(mktemp -d)"
DELAI=90   # secondes ; un lecteur qui pend n'est ni vert ni rouge, il est muet
# Nettoyage nommé, pas récursif : ce répertoire porte des coffres et un manifeste
# qui contient des secrets en clair. On retire ce qu'on a posé, et rien d'autre.
# shellcheck disable=SC2317  # appelée par le trap EXIT
nettoyer(){ rm -f "$BANC"/*.selfvault "$BANC"/manifeste.json; rmdir "$BANC" 2>/dev/null; }
trap nettoyer EXIT

python3 "$MODULE/tests/defauts.py" "$BANC" >/dev/null || { echo "✗ fabrication des coffres"; exit 1; }
lire(){ python3 -c "import json;print(json.load(open('$BANC/manifeste.json'))['$1'])"; }
L1=$(lire code_L1); L2=$(lire mot_L2); EMPREINTE=$(lire empreinte_clair)

echec=0; n=0
LECTEURS=("mjs:$MODULE/outils/test_webcrypto.mjs" "app:$MODULE/tests/pilote_app.mjs")

ouvre(){ # ouvre <lecteur> <coffre> <secret>
  timeout "$DELAI" node "$1" "$BANC/$2.selfvault" "$3" 2>&1
}

vert(){ # vert <coffre> <secret> <intitulé> — doit s'ouvrir, ET rendre le bon clair
  n=$((n+1))
  local nom cible s c ok=1
  for l in "${LECTEURS[@]}"; do
    nom=${l%%:*}; cible=${l#*:}
    s=$(ouvre "$cible" "$1" "$2"); c=$?
    if [ $c -eq 124 ]; then echo "  ✗ $3 [$nom] — le lecteur pend (${DELAI}s)"; ok=0
    elif [ $c -ne 0 ]; then echo "  ✗ $3 [$nom] — devait s'ouvrir : ${s%%$'\n'*}"; ok=0
    elif [[ "$s" != *"$EMPREINTE"* ]]; then
      echo "  ✗ $3 [$nom] — ouvert, mais le clair n'est pas celui qu'on a chiffré"; ok=0
    fi
  done
  [ $ok -eq 1 ] && echo "  ✓ $3" || echec=1
}

rouge(){ # rouge <coffre> <secret> <fragment attendu> <intitulé>
  n=$((n+1))
  local nom cible s c ok=1
  for l in "${LECTEURS[@]}"; do
    nom=${l%%:*}; cible=${l#*:}
    s=$(ouvre "$cible" "$1" "$2"); c=$?
    if [ $c -eq 124 ]; then echo "  ✗ $4 [$nom] — le lecteur pend (${DELAI}s), ni vert ni rouge"; ok=0
    elif [ $c -eq 0 ]; then echo "  ✗ $4 [$nom] — s'est OUVERT alors qu'il devait refuser"; ok=0
    elif [[ "$s" != *"$3"* ]]; then
      echo "  ✗ $4 [$nom] — a refusé sans nommer la cause : ${s%%$'\n'*}"; ok=0
    fi
  done
  [ $ok -eq 1 ] && echo "  ✓ $4" || echec=1
}

echo "▸ Les contre-témoins — ce qui doit s'ouvrir, et rendre le bon clair"
vert  sain "$L1"                          "code L1"
vert  sain "$L2"                          "mot L2"
vert  sain "$(lire code_L1_sans_tirets)"  "code L1 sans tirets"
vert  sain "$(echo "$L1" | tr - ' ')"     "code L1 avec espaces"

echo "▸ En-tête authentifié — § 2.1"
rouge version_falsifiee  "$L1" "serrure ne s'ouvre" "version passée à 99"
rouge date_falsifiee     "$L1" "serrure ne s'ouvre" "date passée à 2030"
rouge serrure_renommee   "$L1" "serrure ne s'ouvre" "nom de serrure retouché"
rouge contenu_delie      "$L1" "pas celui de cet en-tête"       "contenu délié de l'en-tête"

echo "▸ Injectivité de la sérialisation — § 2.1 bis"
rouge nom_avec_barre     "$L1" "nom de serrure invalide" "séparateur dans un nom de serrure"
rouge date_avec_saut     "$L1" "date"                    "retour à la ligne dans la date"
rouge sel_avec_barre     "$L1" "base64"                  "séparateur dans un sel"

echo "▸ Forme de l'en-tête"
rouge format_inconnu     "$L1" "format inconnu" "format inconnu"
rouge version_flottante  "$L1" "version"        "version non entière"
rouge date_absente       "$L1" "date"           "date absente"
rouge engagement_absent  "$L1" "engagement"     "engagement absent"
rouge sans_serrure       "$L1" "sans serrure"   "coffre sans serrure"

echo "▸ Bornes du nombre d'itérations — § 2.3"
rouge iterations_basses  "$L1" "hors bornes" "itérations à 1"
rouge iterations_enormes "$L1" "hors bornes" "itérations à un milliard"

echo "▸ Engagement de clé — § 2.2"
rouge engagement_faux    "$L1" "pas celle de ce coffre" "engagement portant sur une autre clé"

echo "▸ Somme de contrôle du code — § 2.6"
rouge sain "$(lire code_controle_faux)" "cinq derniers caractères" "cinq caractères de contrôle faux"
# La permutation touche le corps du code, donc elle casse aussi la somme de
# contrôle : le déchiffreur la nomme comme une faute de recopie, ce qu'elle est.
rouge sain "$(lire code_permute)"       "cinq derniers caractères" "deux caractères réellement différents permutés"
rouge sain "$(echo "$L1" | tr '[:upper:]' '[:lower:]')" "minuscules"    "code saisi en minuscules"

echo "▸ Plancher d'entropie de la serrure mémorisée — § 2.4"
# Le refus a lieu à la FABRICATION : un coffre déjà déposé ne se rattrape plus.
refus(){ # refus <expression python> <doit échouer : oui|non> <intitulé>
  n=$((n+1))
  local s c
  s=$(python3 -c "
import sys; sys.path.insert(0,'$MODULE/outils')
from selfvault import fabriquer, tirer_phrase
fabriquer('x', [('L2 — titulaire', $1)], version=1, date='2026-09-04')" 2>&1); c=$?
  if [ "$2" = oui ] && [ $c -ne 0 ] && [[ "$s" == *"tirage non établi"* || "$s" == *"bits, plancher"* ]]; then
    echo "  ✓ $3"
  elif [ "$2" = non ] && [ $c -eq 0 ]; then echo "  ✓ $3"
  else echo "  ✗ $3 — code $c : ${s##*$'\n'}"; echec=1; fi
}
refus "'motdepasse123'"                                          oui "phrase inventée refusée"
refus "'maison-maison-maison-maison-maison-maison'"               oui "six mots DE LA LISTE, choisis — refusés"
refus "'secret-notaire-banque-argent-porte-clef'"                 oui "phrase thématique de mots de la liste — refusée"
refus "str(tirer_phrase())"                                      oui "phrase tirée puis recopiée en chaîne — refusée"
refus "tirer_phrase()"                                           non "phrase tirée à 7 mots acceptée"

echo "▸ La liste de mots — ce dont le calcul d'entropie dépend"
# Une liste diceware compte 6⁴ = 1 296 mots ou 6⁵ = 7 776. Le plancher est en
# bits parce qu'un plancher en mots serait juste sur l'une et faux sur l'autre.
liste(){ # liste <expression python rendant la liste> <doit échouer : oui|non> <intitulé>
  n=$((n+1))
  local s c d; d=$(mktemp -d)
  s=$(SELFRECOVER_WORDLIST="$d/l.json" python3 -c "
import json, os, sys; sys.path.insert(0,'$MODULE/outils')
json.dump($1, open(os.environ['SELFRECOVER_WORDLIST'],'w'))
from selfvault import tirer_phrase
print(round(tirer_phrase($2).bits, 1))" 2>&1); c=$?
  rm -f "$d/l.json"; rmdir "$d" 2>/dev/null
  if [ "$3" = oui ] && [ $c -ne 0 ]; then echo "  ✓ $4"
  elif [ "$3" = non ] && [ $c -eq 0 ]; then echo "  ✓ $4 — $s bits"
  else echo "  ✗ $4 — code $c : ${s##*$'\n'}"; echec=1; fi
}
liste "['maison']*7776"          7 oui "7 776 entrées, un seul mot distinct — refusée"
liste "[str(i) for i in range(9)]+['']" 7 oui "liste portant une entrée vide — refusée"
liste "[str(i) for i in range(1296)]"  6 oui "liste diceware courte, 6 mots — sous le plancher"
liste "[str(i) for i in range(1296)]"  8 non "liste diceware courte, 8 mots — acceptée"

echo "▸ Erreurs discriminées — § 2.5"
# Le `catch` de la boucle des serrures ne doit rattraper QUE l'échec
# d'authentification. Tout le reste remonte, au lieu d'être maquillé en erreur de
# saisie adressée à quelqu'un qui tient le bon code.
n=$((n+1))
s=$(timeout "$DELAI" node --input-type=module -e "
  const vrai = crypto.subtle.decrypt.bind(crypto.subtle);
  let premier = true;
  crypto.subtle.decrypt = (...a) => { if (premier) { premier = false; throw new TypeError('CANARI-HORS-AUTH'); } return vrai(...a); };
  await import('file://$MODULE/outils/test_webcrypto.mjs');
" X "$BANC/sain.selfvault" "$L1" 2>&1)
if [[ "$s" == *"CANARI-HORS-AUTH"* ]]; then echo "  ✓ une erreur hors authentification remonte"
else echo "  ✗ une erreur hors authentification est avalée : ${s%%$'\n'*}"; echec=1; fi

n=$((n+1))
sansweb(){ timeout "$DELAI" node --input-type=module \
  -e "Object.defineProperty(globalThis,'crypto',{value:{},configurable:true});await import('file://$1');" \
  X "$BANC/sain.selfvault" "$L1" 2>&1; }
a=$(sansweb "$MODULE/outils/test_webcrypto.mjs"); b=$(sansweb "$MODULE/tests/pilote_app.mjs")
if [[ "$a" == *"WebCrypto indisponible"* && "$b" == *"WebCrypto est indisponible"* ]]; then
  echo "  ✓ WebCrypto absent, nommé comme tel des deux côtés"
else
  echo "  ✗ WebCrypto absent — mjs : ${a%%$'\n'*} | app : ${b%%$'\n'*}"; echec=1
fi

echo
if [ $echec -eq 0 ]; then echo "✓ Banc conforme — $n contrôles."; else echo "✗ Banc en échec — $n contrôles."; fi
exit $echec
