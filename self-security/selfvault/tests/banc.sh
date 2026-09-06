#!/usr/bin/env bash
# Banc du format SELFVAULT3.
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
export MODULE   # lu par les sous-processus python du contrôle `champ`
BANC="$(mktemp -d)"
DELAI=90   # secondes ; un lecteur qui pend n'est ni vert ni rouge, il est muet
# Nettoyage nommé, pas récursif : ce répertoire porte des coffres et un manifeste
# qui contient des secrets en clair. On retire ce qu'on a posé, et rien d'autre.
# shellcheck disable=SC2317  # appelée par le trap EXIT
nettoyer(){ rm -f "$BANC"/*.selfvault "$BANC"/manifeste.json "$BANC"/directives.txt \
                  "$BANC"/doctore.html "$BANC"/doctore-rendu.html; rmdir "$BANC" 2>/dev/null; }
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
# JSON n'a pas d'entiers. Ce coffre porte `"version": 1.0` : l'AAD et le message
# signé sont identiques au bit près à ceux de `sain`, et les trois porteurs
# doivent s'accorder dessus.
vert  version_un_flottant "$L1"           "version écrite 1.0 — ouverte comme 1"

echo "▸ En-tête authentifié — § 2.1"
rouge version_falsifiee  "$L1" "serrure ne s'ouvre" "version passée à 99"
rouge date_falsifiee     "$L1" "serrure ne s'ouvre" "date passée à 2030"
rouge serrure_renommee   "$L1" "serrure ne s'ouvre" "nom de serrure retouché"
rouge contenu_delie      "$L1" "pas celui de cet en-tête"       "contenu délié de l'en-tête"

echo "▸ Injectivité de la sérialisation — § 2.1 bis"
rouge nom_avec_barre     "$L1" "nom de serrure invalide" "séparateur dans un nom de serrure"
rouge date_avec_saut     "$L1" "AAAA-MM-JJ"              "retour à la ligne dans la date"
rouge sel_avec_barre     "$L1" "sel de"                  "séparateur dans un sel"
# Les trois champs que le MESSAGE SIGNÉ ajoute à l'AAD, et la même famille par
# serrure. Sans leur contrôle de forme, l'encodage du message signé cesse d'être
# sans ambiguïté — et rien ne l'éprouvait.
rouge contenu_avec_saut     "$L1" "contenu"    "retour à la ligne dans le contenu"
# Le sceau signe une projection du fichier, pas le JSON : un champ qu'il ne nomme
# pas passerait sans être couvert.
rouge champ_inconnu         "$L1" "champ inconnu" "champ inconnu dans l'en-tête"
rouge champ_inconnu_serrure "$L1" "champ inconnu" "champ inconnu dans une serrure"
rouge nonce_avec_barre      "$L1" "nonce du coffre" "séparateur dans le nonce du coffre"
rouge enveloppe_avec_barre  "$L1" "enveloppe de" "séparateur dans une enveloppe"
rouge serr_nonce_avec_saut  "$L1" "nonce de"   "retour à la ligne dans le nonce d'une serrure"

echo "▸ Forme de l'en-tête"
rouge format_inconnu     "$L1" "format inconnu" "format inconnu"
rouge version_flottante  "$L1" "entier de 1 à" "version non entière"
rouge date_absente       "$L1" "AAAA-MM-JJ"    "date absente"
rouge engagement_absent  "$L1" "engagement de clé" "engagement absent"
rouge sans_serrure       "$L1" "coffre sans serrure" "coffre sans serrure"

echo "▸ Bornes du nombre d'itérations — § 2.3"
rouge iterations_basses  "$L1" "hors bornes" "itérations à 1"
rouge iterations_enormes "$L1" "hors bornes" "itérations à un milliard"

echo "▸ Bornes de l'en-tête — ce qu'un fichier hostile ne doit pas obtenir"
rouge trop_de_serrures   "$L1" "en accepte"    "neuf serrures — refusé avant toute dérivation"
rouge version_enorme     "$L1" "entier de 1 à" "version au-delà de 2⁵³ — deux fichiers, un seul AAD"

echo "▸ Le sceau — § 2.7"
# Le contenu n'est authentifié que par la clé maîtresse, et TOUTE serrure la rend.
# Sans sceau, qui détient une serrure réécrit ce que l'autre lira, sans trace.
rouge sceau_absent          "$L1" "signature :" "coffre non scellé"
rouge sceau_etranger        "$L1" "sceau de ce coffre ne tient pas" "signé d'une autre clé que celle qu'il publie"
rouge contenu_reecrit       "$L1" "sceau de ce coffre ne tient pas" "contenu réécrit par un porteur de serrure"
# Re-sceller avec sa propre paire fait concorder la signature — et casse toutes
# les enveloppes, `cle_publique` étant dans l'AAD. Les deux mécanismes se tiennent.
rouge rescelle_par_un_tiers "$L1" "serrure ne s'ouvre" "re-scellé avec la clé d'un tiers"

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
  # Le refus doit NOMMER sa cause : un tirage non établi, ou le plancher franchi.
  # Un `ValueError` quelconque ne compte pas — c'est ce qui distingue un garde-fou
  # d'une panne.
  if [ "$2" = oui ] && [ $c -ne 0 ] && [[ "$s" == *"tirage non établi"* || "$s" == *plancher* ]]; then
    echo "  ✓ $3"
  elif [ "$2" = non ] && [ $c -eq 0 ]; then echo "  ✓ $3"
  else echo "  ✗ $3 — code $c : ${s##*$'\n'}"; echec=1; fi
}
refus "'motdepasse123'"                                          oui "phrase inventée refusée"
refus "'maison-maison-maison-maison-maison-maison'"               oui "six mots DE LA LISTE, choisis — refusés"
refus "'secret-notaire-banque-argent-porte-clef'"                 oui "phrase thématique de mots de la liste — refusée"
refus "str(tirer_phrase())"                                      oui "phrase tirée puis recopiée en chaîne — refusée"
refus "tirer_phrase()"                                           non "phrase tirée au défaut acceptée"
# Sept mots sur la liste longue passent sous le plancher : voir la justification
# datée de `BITS_MIN` dans outils/selfvault.py.
refus "tirer_phrase(7)"                                          oui "sept mots — sous le plancher"

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
liste "[str(i) for i in range(1296)]"  9 oui "liste diceware courte, 9 mots — sous le plancher"
liste "[str(i) for i in range(1296)]" 10 non "liste diceware courte, 10 mots — acceptée"
# La distinction se mesure sur ce que la dérivation REÇOIT. 2 592 racines en trois
# variantes ponctuées font 7 776 entrées toutes distinctes, et 2 592 mots réels :
# 90,5 bits annoncés pour 79,4 réels.
liste "[('m%04d'%i)+s for i in range(2592) for s in ('','-','.')]" 8 oui \
      "liste dont les entrées ne diffèrent que par la ponctuation — refusée"
# Au-delà de 65 536 mots, la borne de rejet du tirage JS tombe à zéro et l'onglet
# se fige sans rien dire. Python tirait, lui, sans broncher.
liste "[str(i) for i in range(65537)]" 8 oui "liste de plus de 65 536 mots — refusée"

echo "▸ Forme des champs de l'AAD, côté fabrique"
# Ces refus n'ont pas de lecteur pour les prononcer : aucun coffre Python ne se
# déchiffre dans le dépôt. Ils s'éprouvent donc sur le validateur lui-même, qui
# est la porte d'entrée que la notice invite à réécrire.
champ(){ # champ <retouche python sur `c`> <doit échouer : oui|non> <intitulé>
  n=$((n+1))
  local s c
  s=$(RETOUCHE="$1" python3 - "$BANC/sain.selfvault" <<'PY' 2>&1
import json, os, sys
sys.path.insert(0, os.environ["MODULE"] + "/outils")
from selfvault import verifier_champs, verifier_chiffres
c = json.load(open(sys.argv[1]))
exec(os.environ["RETOUCHE"])
verifier_champs(c)
verifier_chiffres(c)
PY
); c=$?
  if [ "$2" = oui ] && [ $c -ne 0 ]; then echo "  ✓ $3"
  elif [ "$2" = non ] && [ $c -eq 0 ]; then echo "  ✓ $3"
  else echo "  ✗ $3 — code $c : ${s##*$'\n'}"; echec=1; fi
}
champ 'pass'                        non "le coffre sain passe — contre-témoin"
# « None » et « 12345 » sont du base64 valide : une regex appliquée à `str(x)`
# porte sur la représentation et jamais sur le type. Voir `_b64` dans selfvault.py.
champ 'c["engagement"] = None'      oui "engagement à None — refusé"
champ 'c["engagement"] = 12345'     oui "engagement entier — refusé"
champ 'c["serrures"][0]["sel"] = None' oui "sel à None — refusé"
# Le « \d » de Python désigne TOUS les chiffres d'Unicode, celui de JavaScript les
# seuls [0-9]. Ce coffre se fabriquait, et le déchiffreur imprimé ne l'ouvrait jamais.
champ 'c["date"] = "٢٠٢٦-٠٩-٠٦"'    oui "date en chiffres arabo-indiens — refusée"
champ 'c["version"] = 1.0'          non "version 1.0 acceptée — JavaScript ne la distingue pas de 1"
champ 'c["contenu"] = "AA|BB"'      oui "séparateur dans le contenu — refusé"
champ 'c["serrures"][0]["nonce"] = None' oui "nonce de serrure à None — refusé"

echo "▸ L'empreinte du sceau — ce qui rattache un coffre à SON pli"
# Le sceau prouve qu'un coffre n'a pas bougé, jamais d'où il vient : qui a lu le
# code imprimé peut en fabriquer un neuf, cohérent et scellé. L'empreinte de la
# clé publique, imprimée sur le pli, est le seul ancrage — et elle est facultative,
# parce qu'un détenteur légitime qui a perdu le pli doit pouvoir ouvrir.
EMP=$(python3 -c "
import json, os, sys; sys.path.insert(0, os.environ['MODULE'] + '/outils')
from selfvault import empreinte_sceau
print(empreinte_sceau(json.load(open('$BANC/sain.selfvault'))))")

sceau(){ # sceau <coffre> <empreinte saisie> <fragment attendu> <intitulé>
  n=$((n+1)); local s
  s=$(timeout "$DELAI" node "$MODULE/tests/pilote_app.mjs" "$BANC/$1.selfvault" "$L1" "$2" 2>&1)
  if [[ "$s" == *"$3"* ]]; then echo "  ✓ $4"
  else echo "  ✗ $4 — ${s%%$'\n'*}"; echec=1; fi
}
sceau sain "$EMP"                    "c'est bien le coffre déposé" "empreinte recopiée — le coffre est reconnu"
sceau sain "$(tr -d ' ' <<<"${EMP^^}")" "c'est bien le coffre déposé" "— recopiée sans espaces et en majuscules"
sceau sain ""                        "rien ne rattache"            "sans empreinte, le coffre s'ouvre et la page le dit"
sceau sain "0000 1111 2222 3333 4444 5555 6666 7777" \
                                     "ne correspond pas"           "empreinte d'un autre pli — refus"
# Le contre-témoin qui donne son sens à tout le reste : le coffre substitué s'ouvre
# avec le code du pli, son sceau tient, et seule l'empreinte le démasque.
sceau refabrique ""                  "rien ne rattache"            "coffre refabriqué par un tiers — il s'ouvre, personne ne le sait"
sceau refabrique "$EMP"              "ne correspond pas"           "— mais l'empreinte du pli le démasque"

n=$((n+1))
AFF=$(timeout "$DELAI" node "$MODULE/tests/pilote_app.mjs" "$BANC/sain.selfvault" "$L1" 2>&1 \
      | sed -n 's/^sceau affiché : //p')
if [ "$AFF" = "$EMP" ]; then echo "  ✓ Python et le déchiffreur calculent la même empreinte"
else echo "  ✗ empreintes divergentes — python « $EMP » contre page « $AFF »"; echec=1; fi

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

# ── L'atelier — ce qui FABRIQUE le coffre ────────────────────────────────────
# Le pli ne porte que ce qui OUVRE ; l'atelier est ce que la titulaire emploie
# une fois, avant le dépôt, et il n'est jamais imprimé. Son code de déchiffrement
# n'est pas écrit deux fois : il est EXTRAIT de `pli/selfvault.html` à
# l'assemblage. Ce qui suit éprouve cette extraction, les deux tirages, et la
# fabrication dans les deux sens.
echo
echo "▸ L'atelier de fabrication"
ATELIER="$MODULE/sortie/selfvault-atelier.html"
PY_ATELIER="import sys; sys.path.insert(0,'$MODULE/outils')"

ok(){ # ok <intitulé> <commande…> — doit réussir
  n=$((n+1)); local s c
  s=$(timeout "$DELAI" "${@:2}" 2>&1); c=$?
  if [ $c -eq 0 ]; then echo "  ✓ $1"
  elif [ $c -eq 124 ]; then echo "  ✗ $1 — pend (${DELAI}s)"; echec=1
  else echo "  ✗ $1 — code $c : ${s##*$'\n'}"; echec=1; fi
}
ko(){ # ko <intitulé> <commande…> — doit échouer
  n=$((n+1)); local s c
  s=$(timeout "$DELAI" "${@:2}" 2>&1); c=$?
  if [ $c -eq 0 ]; then echo "  ✗ $1 — a réussi alors qu'il devait échouer"; echec=1
  elif [ $c -eq 124 ]; then echo "  ✗ $1 — pend (${DELAI}s)"; echec=1
  else echo "  ✓ $1"; fi
}

echo "▸ Indépendance du second lecteur"
# Le second lecteur ne vaut que s'il diverge : une copie hérite des défauts
# qu'elle devrait révéler. Le seuil est un NOMBRE de lignes et non une part, qui
# se diluerait quand l'un des deux fichiers grossit. Quatre : une poignée de
# signatures d'appel WebCrypto coïncident forcément entre deux implémentations
# honnêtes, là où une copie en partage la quasi-totalité — c'est ce second cas
# que le contre-témoin ci-dessous mesure.
ok "le second lecteur est une réimplémentation, pas une copie" \
   python3 "$MODULE/tests/recouvrement.py" "$MODULE/pli/selfvault.html" \
           "$MODULE/outils/test_webcrypto.mjs" 4
ko "— et la mesure reconnaît une copie" \
   python3 "$MODULE/tests/recouvrement.py" "$MODULE/pli/selfvault.html" \
           "$MODULE/pli/selfvault.html" 4




ok "l'atelier s'assemble" python3 "$MODULE/outils/faire_atelier.py"

# Un jeton mal orthographié doit être nommé à l'assemblage, pas découvert dans la
# page par la personne qui s'en sert.
sed 's|</main>|<p>{{INCONNU}}</p></main>|' "$MODULE/pli/atelier.html" > "$BANC/doctore.html"
ko "un jeton non substitué fait échouer l'assemblage" \
   python3 "$MODULE/outils/faire_atelier.py" "$BANC/doctore.html" "$BANC/doctore-rendu.html"

# 🔑 Le noyau n'est pas recopié, il est extrait. Deux exemplaires du code qui
# déchiffre finiraient par ne plus dire la même chose, et le coffre ne s'ouvrirait
# plus qu'avec le programme qui l'a écrit.
ok "le noyau de l'atelier est la copie exacte de celui du déchiffreur" python3 -c "
$PY_ATELIER
from faire_atelier import noyau
a = open('$ATELIER', encoding='utf-8').read()
sys.exit(0 if noyau('$MODULE/pli/selfvault.html') in a else 'le noyau assemblé diverge')"
ok "— et la comparaison distingue un noyau d'un caractère près" python3 -c "
$PY_ATELIER
from faire_atelier import noyau
a = open('$ATELIER', encoding='utf-8').read()
retouche = noyau('$MODULE/pli/selfvault.html').replace('ITER_MIN=100000', 'ITER_MIN=100001', 1)
sys.exit(0 if retouche not in a else 'un noyau retouché passe pour identique')"

# La liste embarquée vient de `_liste_mots()`, donc de la copie normative du
# dépôt, avec ses contrôles d'entrées vides et de doublons.
LISTE="
$PY_ATELIER
import json, re
from selfvault import _liste_mots
a = open('$ATELIER', encoding='utf-8').read()
m = re.search(r'const MOTS = (\[.*?\]);', a, re.S)
if not m: sys.exit('la liste embarquée est introuvable')
embarquee = json.loads(m.group(1)); normative = _liste_mots()"
ok "la liste de mots embarquée est celle de SelfRecover" python3 -c "$LISTE
sys.exit(0 if embarquee == normative else 'la liste embarquée diverge de la copie normative')"
ok "— et la comparaison distingue un mot changé" python3 -c "$LISTE
normative[len(normative)//2] = 'coquelicot'
sys.exit(0 if embarquee != normative else 'un mot changé passe inaperçu')"

# Un rejet ne s'observe pas : deux générateurs, l'un avec rejet et l'autre sans,
# rendent des suites qui se ressemblent. Le pilote injecte donc un générateur qui
# rend toutes les valeurs une fois chacune, et compte.
ok "le tirage des mots est uniforme — 7 776 issues, 8 fois chacune" \
   node "$MODULE/tests/pilote_atelier.mjs" tirage-mots
ko "— sans le rejet, le biais est vu" \
   node "$MODULE/tests/pilote_atelier.mjs" tirage-mots casse
ok "le tirage des lettres du code est uniforme — 30 issues, 8 fois chacune" \
   node "$MODULE/tests/pilote_atelier.mjs" tirage-lettres
ko "— sans le rejet, le biais est vu" \
   node "$MODULE/tests/pilote_atelier.mjs" tirage-lettres casse

# Une panne du générateur d'aléa ne se voit pas dans ce qu'il rend : la page
# tirait `abdominal-abdominal-…` en affichant la mesure du tirage prévu, et rien
# ne bougeait. Le pilote remplace `getRandomValues` par une fonction qui ne
# remplit rien, et exige que la page se déclare inutilisable EN NOMMANT la cause.
# Pour le refaire rougir : retirer la branche `sourceFiable()` de l'artefact
# assemblé — il rend alors « la page fabrique quand même ».
ok "un générateur d'aléa qui ne remplit rien interdit la fabrication" \
   node "$MODULE/tests/pilote_atelier.mjs" alea-mort

# La fabrication, dans les deux sens. C'est le seul contrôle qui dise que le
# coffre écrit par le navigateur est du SELFVAULT3 et pas un dialecte : il est
# relu par la réimplémentation indépendante, écrite depuis la notice imprimée.
printf 'DIRECTIVES DE BANC\nDeux lignes, un accent aigu, et une « citation ».\n' > "$BANC/directives.txt"
CLAIR=$(sha256sum "$BANC/directives.txt" | cut -d' ' -f1)
if TIRAGE=$(timeout "$DELAI" node "$MODULE/tests/pilote_atelier.mjs" fabriquer \
              "$BANC/directives.txt" "$BANC/atelier.selfvault" 2>&1); then
  AL2=$(sed -n 's/^L2 //p' <<<"$TIRAGE"); AL1=$(sed -n 's/^L1 //p' <<<"$TIRAGE")
else
  AL2=""; AL1=""; echec=1; echo "  ✗ l'atelier ne fabrique pas : ${TIRAGE##*$'\n'}"
fi
croise(){ # croise <lecteur> <secret> <intitulé>
  n=$((n+1)); local s
  s=$(timeout "$DELAI" node "$1" "$BANC/atelier.selfvault" "$2" 2>&1)
  if [[ "$s" == *"$CLAIR"* ]]; then echo "  ✓ $3"
  else echo "  ✗ $3 — ${s##*$'\n'}"; echec=1; fi
}
n=$((n+1))
if [[ "$TIRAGE" == *"VÉRIFIÉ — empreinte du clair : $CLAIR"* ]]; then
  echo "  ✓ l'atelier rouvre en mémoire le coffre qu'il vient de faire"
else
  echo "  ✗ l'atelier rouvre en mémoire le coffre qu'il vient de faire — pas d'empreinte concordante"; echec=1
fi
croise "$MODULE/outils/test_webcrypto.mjs" "$AL1" "coffre fabriqué au navigateur, relu par la réimplémentation — serrure L1"
croise "$MODULE/outils/test_webcrypto.mjs" "$AL2" "— et par la phrase mémorisée, serrure L2"
croise "$MODULE/tests/pilote_app.mjs"      "$AL1" "— et par le déchiffreur imprimé dans le pli"

# 🔑 La chaîne complète de la titulaire : elle fabrique à l'atelier, puis on
# imprime. L'atelier collectait l'identité à l'étape 1 et ne l'écrivait nulle
# part ; `faire_pli.py` la lit dans `meta.json`, et le pli ne pouvait donc pas
# être composé à partir de ce que l'atelier produit. Ce contrôle est le seul qui
# relie les deux moitiés du module.
#
# Il écrase `sortie/` — le banc papier, qui tourne après, refabrique tout au
# départ.
n=$((n+1))
if [ -s "$BANC/meta.json" ] \
   && cp "$BANC/atelier.selfvault" "$MODULE/sortie/coffre.selfvault" \
   && cp "$BANC/meta.json" "$MODULE/sortie/meta.json" \
   && python3 "$MODULE/outils/faire_pli.py" >/dev/null 2>&1 \
   && grep -qF "$(python3 -c "import json;print(json.load(open('$BANC/meta.json'))['titulaire'])")" \
           "$MODULE/sortie/pli.html"; then
  echo "  ✓ le pli se compose à partir de ce que l'atelier écrit, identité comprise"
else
  echo "  ✗ le pli ne se compose pas depuis l'atelier : la chaîne fabriquer→imprimer est rompue"; echec=1
fi

n=$((n+1))
s=$(timeout "$DELAI" node "$MODULE/tests/pilote_atelier.mjs" ouvrir "$BANC/sain.selfvault" "$L1" 2>&1)
if [[ "$s" == *"$EMPREINTE"* ]]; then echo "  ✓ coffre fabriqué en Python, ouvert par l'atelier"
else echo "  ✗ coffre fabriqué en Python, ouvert par l'atelier — ${s##*$'\n'}"; echec=1; fi

# ── La boucle papier ─────────────────────────────────────────────────────────
# Elle vit à part parce qu'elle exige des outils système que le format n'exige
# pas. Quand ils manquent, on le DIT : un contrôle sauté en silence ressemble
# trait pour trait à un contrôle passé.
echo
manquants=""
for o in pdftoppm pdftotext zbarimg weasyprint; do
  command -v "$o" >/dev/null || manquants="$manquants $o"
done
if [ -n "$manquants" ]; then
  echo "⚠ Boucle papier NON MESURÉE — absent(s) :$manquants"
  echo "  (apt install poppler-utils zbar-tools weasyprint)"
else
  bash "$MODULE/tests/banc_papier.sh" || echec=1
  # Le banc papier régénère le coffre et le pli dans `sortie/`, qui n'est pas
  # versionné. Les secrets de `outils/secrets/` changent donc aussi.
fi

echo
if [ $echec -eq 0 ]; then echo "✓ Banc conforme — $n contrôles de format."; else echo "✗ Banc en échec."; fi
exit $echec
