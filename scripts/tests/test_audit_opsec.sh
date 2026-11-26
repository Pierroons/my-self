#!/bin/bash
# Banc de `audit-opsec.sh` — ce qu'il attrape, et surtout ce qu'il laissait
# passer en rendant vert.
#
# 🔑 **Pourquoi ce banc existe.** L'audit tient les trois crochets de publication
# du dépôt. Il n'avait aucun banc versionné : son silence ne se distinguait donc
# pas d'une absence de défaut, et deux faux verts y ont vécu plusieurs mois.
#
#   · un motif que `grep` refuse — crochet non fermé, parenthèse non appairée —
#     passait pour « non trouvé », parce que le `exit 2` de `cherche` était
#     appelé en bout de tube et ne quittait que son sous-shell. Mesuré sur la
#     version d'avant : en `--worktree`, `--staged` et `--message`, un fichier
#     qui portait réellement la fuite rendait « ✓ Rien à signaler » et code 0.
#     Ce sont les trois modes des crochets ;
#   · `grep -q` sort à la première correspondance : au-delà de 64 Kio d'entrée,
#     le producteur du tube prenait SIGPIPE, `pipefail` rendait 141, et le `if`
#     lisait « pas trouvé » pendant que grep avait trouvé ;
#   · un périmètre vide (`--range HEAD..HEAD`) rendait sept coches vertes et un
#     « ✓ Rien à signaler » pour zéro commit lu.
#
# Chaque cas suit la même forme : le défaut redouté, puis un témoin qui prouve
# que l'audit voit encore ce qu'il doit voir. Sans le témoin, un audit cassé
# passerait le banc en n'attrapant plus rien.
#
# Usage : bash scripts/tests/test_audit_opsec.sh
# Sortie : 0 si toutes les propriétés tiennent. Leur nombre se compte à
#          l'exécution — l'écrire ici le ferait mentir au prochain ajout.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
RACINE="$(cd "$ICI/../.." && pwd)"
# 🔑 Le chemin se surcharge, pour pouvoir rejouer le banc contre une version
# antérieure de l'audit. C'est le seul moyen de vérifier qu'un cas rougit
# vraiment : posé sur du code déjà corrigé, il verdit sans rien prouver.
#   git show HEAD~1:scripts/audit-opsec.sh > /tmp/avant.sh
#   AUDIT_SOUS_TEST=/tmp/avant.sh bash scripts/tests/test_audit_opsec.sh
AUDIT="${AUDIT_SOUS_TEST:-$RACINE/scripts/audit-opsec.sh}"
[ -r "$AUDIT" ] || { echo "audit introuvable : $AUDIT" >&2; exit 1; }
[ -r "$RACINE/.gitleaks.toml" ] || { echo ".gitleaks.toml introuvable" >&2; exit 1; }

# Trois outils externes décident de codes que ce banc compare, et leur absence
# n'invalide pas un cas : elle en inverse le résultat. Sans `gitleaks` ou
# `exiftool`, l'étage concerné rend un `warn`, donc 1 — et les cas qui attendent
# 0 rougissent en accusant un périmètre qui n'a rien fait. Sans `gh`, le verdict
# devient « sans objet » et les assertions de sortie changent de sens. Refuser de
# conclure vaut mieux qu'un rouge qui envoie chercher au mauvais endroit.
for outil in gitleaks exiftool gh; do
  command -v "$outil" >/dev/null 2>&1 \
    || { echo "outil absent : $outil — ce banc ne peut pas conclure" >&2; exit 1; }
done

BAC=$(mktemp -d)
trap 'rm -rf "$BAC"' EXIT

echecs=0 reussites=0
ok()  { echo "  ✓ $1"; reussites=$((reussites + 1)); }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

# Le motif du banc n'a aucun sens hors du banc : le vrai fichier de motifs porte
# des données personnelles, il n'a rien à faire ici — et un dépôt public qui
# listerait les motifs publierait ce qu'ils protègent.
MOTIF_BANC='motif-banc-xyzzy'
# Crochet non fermé : `grep` sort en 2 là-dessus, mesuré. C'est la forme d'un
# nom composé ou d'une raison sociale mal échappée, pas un cas d'école.
MOTIF_CASSE='motif-banc[xyzzy'

# ── Un dépôt jetable, avec un distant local ────────────────────────────────
# Le distant est nécessaire : l'audit borne les plages à ce que le distant ne
# publie pas encore, et sans `origin` ce filtre ne s'applique pas du tout.
W="$BAC/w"
g() { git -C "$W" -c user.name=banc -c user.email=banc@example.org -c commit.gpgsign=false "$@"; }
monte_depot() {
  rm -rf "$W" "$BAC/distant.git"
  git init -q --bare "$BAC/distant.git"
  git init -q -b dev "$W"
  mkdir -p "$W/scripts"
  cp "$AUDIT" "$W/scripts/audit-opsec.sh"
  cp "$RACINE/.gitleaks.toml" "$W/.gitleaks.toml"
  g remote add origin "$BAC/distant.git"
}

# Lance l'audit dans le dépôt jetable et écrit son code dans $RC, sa sortie
# (couleurs retirées) dans $BAC/sortie. Le fichier de motifs se passe par
# l'environnement, jamais par le vrai ~/.config.
RC=0
audit() {
  local pat="$1"; shift
  RC=0
  ( cd "$W" && SELFOPSEC_PATTERNS="$pat" bash scripts/audit-opsec.sh "$@" ) \
    > "$BAC/brut" 2>&1 || RC=$?
  sed 's/\x1b\[[0-9;]*m//g' "$BAC/brut" > "$BAC/sortie"
}
contient() { grep -qF "$1" "$BAC/sortie"; }
# Compte les coches vertes portées par les étages qui suivent `--range`.
# ⚠️ Cette liste s'accroche à la prose de l'audit, donc elle vieillit avec elle :
# toute coche d'un étage qui suit `--range` doit y figurer, y compris celle que
# l'étage 2 rend sur une plage NON vide — sans elle, retirer la garde sur le
# nombre de révisions ferait reverdir l'étage sans que ce banc le voie.
verts_des_etages_de_plage() {
  grep -cE '^  ✓ (aucun secret|aucun commit|aucun motif dans le contenu|aucun motif ni secret dans les messages|[0-9]+ orphelin)' \
    "$BAC/sortie" || true
}

# ════════════════════════════════════════════════════════════════════════════
# A. Un motif que grep refuse doit ARRÊTER l'audit, dans les quatre modes
# ════════════════════════════════════════════════════════════════════════════
# Le fichier audité porte RÉELLEMENT la fuite : c'est ce qui rend le cas coûteux,
# et ce qui interdit de « simplifier » la fixture en un fichier propre.
echo "▸ un motif inutilisable arrête l'audit, et la fuite ne passe pas"

printf '%s\n' "$MOTIF_CASSE" > "$BAC/pat-casse.txt"
printf '%s\n' "$MOTIF_BANC"  > "$BAC/pat-bon.txt"

monte_depot
printf 'une ligne avec %s dedans\n' "$MOTIF_BANC" > "$W/fuite.txt"
g add -A && g commit -q -m "socle du banc" && g push -q origin dev

for mode in --worktree --staged; do
  # --worktree lit le disque, --staged lit l'index : le fichier est donc écrit
  # puis indexé, pour que les deux modes aient quelque chose à lire.
  printf 'une ligne avec %s dedans\n' "$MOTIF_BANC" > "$W/encore.txt"
  g add encore.txt
  audit "$BAC/pat-casse.txt" "$mode"
  if [ "$RC" = "2" ] && ! contient "Rien à signaler"; then
    ok "$mode — code 2, et aucun « Rien à signaler »"
  else
    nok "$mode — code $RC, et la sortie dit « Rien à signaler » : la fuite passe"
  fi
  if contient "motif(s) inutilisable(s)"; then
    ok "$mode — le refus nomme le fichier de motifs, avant la première coche"
  else
    nok "$mode — le refus ne se lit pas dans la sortie"
  fi
  g rm -q -f --cached encore.txt && rm -f "$W/encore.txt"
done

printf 'retire %s de la demo\n' "$MOTIF_BANC" > "$BAC/message.txt"
audit "$BAC/pat-casse.txt" --message "$BAC/message.txt"
if [ "$RC" = "2" ]; then
  ok "--message — code 2 sur un message qui porte la fuite"
else
  nok "--message — code $RC : le commit partirait avec la fuite dans son message"
fi

# ⚠️ Ce cas-ci ne rougit pas sur la version d'avant le correctif, et ce n'est pas
# un défaut du banc : le mode plage était déjà couvert, par une autre garde — le
# `git grep` de l'étage 2 lit son code par `PIPESTATUS` au niveau principal, donc
# son `exit 2` quittait bien le script. Les trois modes atteints étaient les trois
# moins chers, c'est-à-dire ceux des crochets. Le cas reste ici pour la
# non-régression : c'est la seule garde qui tenait, elle doit continuer.
# Témoin positif du mode `--message`, et il manquait : le seul cas ci-dessus
# emploie un motif cassé, donc il sort à la validation sans entrer dans le mode.
# Supprimer le bloc `--message` entier laissait ce banc vert, alors que
# `commit-msg` est la seule lecture des messages de commit de tout le dépôt.
audit "$BAC/pat-bon.txt" --message "$BAC/message.txt"
if [ "$RC" = "1" ] && contient "$MOTIF_BANC"; then
  ok "témoin --message : un message qui porte la fuite est bloqué (code 1)"
else
  nok "témoin --message : la fuite du message passe (code $RC)"
fi
printf '# que des commentaires git\n#\n' > "$BAC/message-vide.txt"
audit "$BAC/pat-bon.txt" --message "$BAC/message-vide.txt"
if [ "$RC" = "0" ] && ! grep -q '^  ✓' "$BAC/sortie"; then
  ok "--message sans contenu — ne bloque pas, et n'annonce aucun succès"
else
  nok "--message sans contenu — code $RC, ou une coche verte sur rien"
fi

audit "$BAC/pat-casse.txt" --range "origin/dev..HEAD"
if [ "$RC" = "2" ]; then
  ok "--range — code 2 (déjà couvert avant le correctif, à ne pas perdre)"
else
  nok "--range — code $RC"
fi

# Témoin : le même audit, avec un motif valide, doit TROUVER la fuite. Sans ce
# témoin, un audit qui refuserait tout passerait les quatre cas ci-dessus.
printf 'une ligne avec %s dedans\n' "$MOTIF_BANC" > "$W/encore.txt"
g add encore.txt
audit "$BAC/pat-bon.txt" --staged
if [ "$RC" = "1" ] && contient "$MOTIF_BANC"; then
  ok "témoin : avec un motif valide, la même fuite est trouvée (code 1)"
else
  nok "témoin : la fuite n'est plus trouvée avec un motif valide (code $RC)"
fi
g rm -q -f --cached encore.txt && rm -f "$W/encore.txt"

# ════════════════════════════════════════════════════════════════════════════
# A bis. Un motif VALIDE, en tête d'une entrée qui dépasse la capacité d'un tube
# ════════════════════════════════════════════════════════════════════════════
# C'est le cas qui mesure la forme d'alimentation de `cherche`, et lui seul. Les
# cas ci-dessus sont tous tranchés par la validation des motifs, qui s'exécute
# avant tout aiguillage de mode : ils ne disent donc rien de la manière dont
# `cherche` est alimentée. Ici le motif est valide, la validation laisse passer,
# et le verdict ne dépend plus que d'une chose — qu'aucun tube ne sépare le
# producteur de `grep`.
#
# D'où les deux exigences du cas : l'entrée dépasse 64 Kio, et le motif est dans
# sa PREMIÈRE ligne. Un motif en fin d'entrée ne prouverait rien, parce que le
# producteur aurait fini d'écrire avant que `grep -q` ne sorte.
echo "▸ un motif valide en tête d'une grosse entrée est trouvé, pas avalé"

monte_depot
echo "socle" > "$W/socle.txt"
g add -A && g commit -q -m "socle" && g push -q origin dev
{ printf 'la fuite %s est ici, en premiere ligne\n' "$MOTIF_BANC"
  python3 -c "print('x'*200000)"; } > "$W/gros.txt"
g add gros.txt
audit "$BAC/pat-bon.txt" --staged
if [ "$RC" = "1" ] && contient "gros.txt"; then
  ok "contenu indexé de 200 Ko, motif en 1re ligne — trouvé et nommé"
else
  nok "contenu indexé de 200 Ko, motif en 1re ligne — code $RC, la fuite passe"
fi
audit "$BAC/pat-bon.txt" --worktree
if [ "$RC" = "1" ] && contient "gros.txt"; then
  ok "contenu sur disque de 200 Ko, motif en 1re ligne — trouvé et nommé"
else
  nok "contenu sur disque de 200 Ko, motif en 1re ligne — code $RC, la fuite passe"
fi

# Même mécanisme, autre étage : la garde qui écarte les blobs non textuels de
# l'étage 5 sortait aussi en 141 sur un gros blob, et l'orphelin partait sans un
# mot. La propriété se mesure sur le NOM du fichier dans la sortie, pas sur le
# code : l'étage 2 trouve le même motif dans l'historique et rendrait 1 à lui
# seul, mais lui ne nomme que le motif. Seul l'étage 5 nomme le chemin.
g commit -q -m "le gros fichier entre dans l'historique"
g rm -q gros.txt && g commit -q -m "et il en sort : le voilà orphelin"
audit "$BAC/pat-bon.txt"
if contient "gros.txt"; then
  ok "orphelin de 200 Ko — l'étage 5 le lit et le nomme"
else
  nok "orphelin de 200 Ko — écarté en silence, son contenu n'est jamais grepé"
fi

# Témoin : un dépôt propre et un motif valide rendent un verdict, pas une erreur.
monte_depot
echo "rien de sensible ici" > "$W/propre.txt"
g add -A && g commit -q -m "dépôt propre" && g push -q origin dev
audit "$BAC/pat-bon.txt" --range "origin/dev..HEAD"
if [ "$RC" -le 1 ]; then
  ok "témoin : un dépôt propre rend un verdict lisible (code $RC)"
else
  nok "témoin : code inattendu sur un dépôt propre ($RC)"
fi

# ════════════════════════════════════════════════════════════════════════════
# B. Un périmètre vide ne doit pas porter de coche verte
# ════════════════════════════════════════════════════════════════════════════
# ⚠️ Le code de sortie ne mesure PAS cette propriété, et ne le mesurera pas :
# `SANS_OBJET` rend 0 délibérément, parce que les trois crochets traitent un
# code non nul comme un refus de publier, et qu'un périmètre vide n'est pas une
# fuite. Le faux vert se lit donc dans la SORTIE — quatre étages annonçaient ✓
# après n'avoir lu aucun commit.
echo "▸ une plage sans commit ne rend aucune coche verte"

# Un dépôt sans aucun commit traverse la même branche de l'étage 2, mais sans
# `--range` : la garde du périmètre vide ne le voit pas, et c'est l'étage qui doit
# poser « sans objet » lui-même. `monte_depot` laisse justement le dépôt
# fraîchement initialisé, sans un commit.
monte_depot
audit "$BAC/pat-bon.txt"
if contient "Rien à signaler"; then
  nok "dépôt sans commit — « Rien à signaler » sans qu'un commit ait été lu"
else
  ok "dépôt sans commit — le verdict dit qu'il n'a rien lu"
fi

monte_depot
echo "un commit pour la suite" > "$W/base.txt"
g add -A && g commit -q -m "base" && g push -q origin dev
audit "$BAC/pat-bon.txt" --range "HEAD..HEAD"
n=$(verts_des_etages_de_plage)
if [ "$n" = "0" ]; then
  ok "plage vide — aucun des quatre étages de plage n'annonce de succès"
else
  nok "plage vide — $n coche(s) verte(s) sur zéro commit lu"
fi
if contient "ne contient aucun commit"; then
  ok "plage vide — le verdict nomme la plage comme cause"
else
  nok "plage vide — le verdict n'explique pas pourquoi rien n'a été lu"
fi
if contient "Rien à signaler"; then
  nok "plage vide — « Rien à signaler » sur un audit qui n'a rien lu"
else
  ok "plage vide — pas de « Rien à signaler »"
fi

# Témoin : sur une plage qui porte des commits, les coches reviennent. Sans lui,
# un audit qui n'annoncerait plus jamais de succès passerait les trois cas.
echo "encore une ligne" >> "$W/base.txt"
g commit -q -am "un commit à auditer"
audit "$BAC/pat-bon.txt" --range "origin/dev..HEAD"
n=$(verts_des_etages_de_plage)
if [ "$n" -gt 0 ] && contient "Rien à signaler"; then
  ok "témoin : sur une plage non vide, les coches et le verdict vert reviennent"
else
  nok "témoin : une plage non vide ne rend plus de succès ($n coche(s))"
fi

# `--staged` sur un index vide : le même défaut, dans le mode qu'un crochet
# appelle réellement — `--worktree` n'est appelé par aucun des trois.
monte_depot
echo "rien" > "$W/y.txt"
g add -A && g commit -q -m "base" && g push -q origin dev
audit "$BAC/pat-bon.txt" --staged
if grep -q "^  ✓ rien d'indexé" "$BAC/sortie"; then
  nok "--staged sur un index vide — coche verte sans avoir lu un fichier"
elif grep -q "rien d'indexé" "$BAC/sortie"; then
  ok "--staged sur un index vide — le dit sans coche verte"
else
  nok "--staged sur un index vide — ne dit pas qu'il n'a rien lu"
fi

# Un arbre propre en --worktree n'est pas un audit réussi non plus : la même
# famille. L'audit doit dire qu'il n'a rien lu sans coche verte. La propriété se
# mesure sur le SIGNE de cette ligne : le script sort avant le verdict final.
monte_depot
echo "rien" > "$W/x.txt"
g add -A && g commit -q -m "base" && g push -q origin dev
audit "$BAC/pat-bon.txt" --worktree
if grep -q '^  ✓ aucun fichier modifié ni intracké' "$BAC/sortie"; then
  nok "--worktree sur un arbre propre — coche verte sans avoir lu un fichier"
elif grep -q 'aucun fichier modifié ni intracké' "$BAC/sortie"; then
  ok "--worktree sur un arbre propre — le dit sans coche verte"
else
  nok "--worktree sur un arbre propre — ne dit pas qu'il n'a rien lu"
fi
if contient "Rien à signaler"; then
  nok "--worktree sur un arbre propre — « Rien à signaler » sans avoir lu un fichier"
else
  ok "--worktree sur un arbre propre — pas de « Rien à signaler »"
fi

# ════════════════════════════════════════════════════════════════════════════
# C. Le périmètre d'une plage — ce qu'un commit de l'envoi INTRODUIT
# ════════════════════════════════════════════════════════════════════════════
# La propriété : sur une plage, seule la version qu'un commit de l'envoi introduit
# compte. Un héritage déjà publié ne doit pas rougir indéfiniment, un revert ou
# une recopie doivent rougir.
echo "▸ sur une plage, seule la version introduite par l'envoi compte"

export SELFOPSEC_PATTERNS="$BAC/pat-bon.txt"
plage() { RC=0; ( cd "$W" && bash scripts/audit-opsec.sh --range "$1" ) > "$BAC/brut" 2>&1 || RC=$?; \
          sed 's/\x1b\[[0-9;]*m//g' "$BAC/brut" > "$BAC/sortie"; }
cas() {
  local nom="$1" attendu="$3"
  if [ "$2" = "$attendu" ]; then ok "$nom (code $2)"; else nok "$nom — code $2, attendu $attendu"; fi
}

monte_depot
echo "ligne propre" > "$W/g.txt"
printf 'le %s publié\n' "$MOTIF_BANC" > "$W/f.txt"
g add -A && g commit -q -m "A : f porte le motif, publié" && g push -q origin dev
g push -q origin dev:main
g checkout -q -b conv/x
echo "travail local" >> "$W/g.txt" && g commit -q -am "C : touche g" && g push -q origin conv/x
echo "encore du travail" >> "$W/g.txt" && g commit -q -am "C2 : non publié"
g checkout -q dev && echo "le fichier nettoyé" > "$W/f.txt" && g commit -q -am "B : dev nettoie f" && g push -q origin dev
g checkout -q conv/x && g merge -q --no-edit dev
plage "origin/conv/x..HEAD"; cas "héritage publié amené par une fusion" "$RC" 0

printf 'nouveau %s\n' "$MOTIF_BANC" >> "$W/g.txt" && g commit -q -am "D : motif neuf"
plage "origin/conv/x..HEAD"; cas "motif neuf dans la série" "$RC" 1

g checkout -q -b conv/y origin/conv/x
printf 'le %s local\n' "$MOTIF_BANC" > "$W/h.txt" && g add h.txt && g commit -q -m "E : sali"
echo "propre" > "$W/h.txt" && g commit -q -am "F : nettoyé"
plage "origin/conv/x..HEAD"; cas "sali puis nettoyé dans la série" "$RC" 1

g checkout -q -b conv/z dev && g push -q origin conv/z
g revert --no-edit HEAD >/dev/null
plage "origin/conv/z..HEAD"; cas "revert qui ressuscite une version publiée" "$RC" 1

g checkout -q dev && printf 'repris %s\n' "$MOTIF_BANC" > "$W/k.txt" && g add k.txt \
  && g commit -q -m "K : publié sur dev" && g push -q origin dev
g checkout -q -b conv/w origin/conv/x && echo "w" > "$W/w.txt" && g add w.txt \
  && g commit -q -m "W : local" && g merge -q --no-edit dev
plage "origin/conv/x..HEAD"; cas "fusion qui reprend une version publiée sur dev" "$RC" 0

g checkout -q -b conv/v origin/conv/x && printf 'repris %s\n' "$MOTIF_BANC" > "$W/k.txt" \
  && g add k.txt && g commit -q -m "V : recopie"
plage "origin/conv/x..HEAD"; cas "recopie d'une version publiée par un commit de l'envoi" "$RC" 1

g checkout -q -b conv/u dev && g push -q origin conv/u
g merge -q --no-commit --no-ff origin/conv/x >/dev/null 2>&1
g checkout origin/conv/x -- f.txt
g commit -q --no-edit -m "M : fusion qui garde l'ancien f"
plage "origin/conv/u..HEAD"; cas "fusion qui garde une version publiée nettoyée" "$RC" 1

g checkout -q conv/z
printf '%s\tf.txt\tbanc\n' "$(g rev-parse HEAD:f.txt)" > "$W/scripts/opsec-historique-accepte.txt"
plage "origin/conv/z..HEAD"; cas "revert vers une version acceptée" "$RC" 1
rm -f "$W/scripts/opsec-historique-accepte.txt"

g checkout -q -b conv/t origin/conv/x
g merge -q --no-commit --no-ff dev >/dev/null 2>&1
g checkout HEAD -- f.txt
g commit -q --no-edit -m "T : fusion de dev qui garde l'ancien f"
plage "origin/dev..HEAD"; cas "vers dev, fusion de dev qui garde l'ancien f" "$RC" 1

g checkout -q dev && printf '# relevé 12345\n' > "$W/scripts/opsec-allowlist.txt" \
  && g add scripts/opsec-allowlist.txt && g commit -q -m "L : chiffres publiés" && g push -q origin dev
g checkout -q -b conv/r dev && g push -q origin conv/r
g checkout -q dev && printf '# relevé\n' > "$W/scripts/opsec-allowlist.txt" \
  && echo "autre" >> "$W/g.txt" && g commit -q -am "L2 : nettoyé, et g touché" && g push -q origin dev
g checkout -q conv/r && g merge -q --no-commit --no-ff dev >/dev/null 2>&1
g checkout HEAD -- scripts/opsec-allowlist.txt
g commit -q --no-edit -m "R : fusion qui garde l'ancienne liste"
plage "origin/dev..HEAD"; cas "vers dev, fusion qui garde une liste aux chiffres nettoyés" "$RC" 1

# ── Verdict ─────────────────────────────────────────────────────────────────
echo
echo "$reussites propriété(s) tiennent, $echecs en échec."
[ "$echecs" -eq 0 ] || exit 1
