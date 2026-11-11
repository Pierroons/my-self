#!/usr/bin/env bash
# La ligne de clé du dropbear d'amorçage est-elle posée lisible, et une ligne
# illisible est-elle refusée avant de remplacer le fichier ?
#
# 🔑 **L'invariant que ce banc fixe.** Jusqu'à la 0.6.2, `install.sh` et
# `INSTALL.md` (§8b) posaient une VIRGULE entre les options et la clé :
# `command="…",no-X11-forwarding,ssh-ed25519 AAAA…`. Le type de clé y est lu comme
# une option, la ligne ne désigne plus aucune clé, et la machine reste injoignable
# au démarrage — ce qui ne se voit qu'au redémarrage. Le contre-témoin d'`install.sh`
# comptait des motifs et la laissait passer ; aucun banc ne lisait la ligne produite.
#
# Ce banc éprouve `cle-amorcage.sh` (qui assemble et relit la ligne), sa forme seule
# quand `ssh-keygen` manque, et le câblage d'`install.sh` et d'`INSTALL.md` sur lui.
# Aucun privilège : les clés sont jetables, tirées dans un répertoire temporaire.
#
# Usage  : bash tests/test_cle_amorcage.sh
# Sortie : 0 si chaque propriété tient, 1 sinon.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
MODULE="$(cd "$HERE/.." && pwd)"
CLE="${CLE:-$MODULE/cle-amorcage.sh}"
INSTALLSH="${INSTALLSH:-$MODULE/install.sh}"
INSTALLMD="${INSTALLMD:-$MODULE/INSTALL.md}"
[ -r "$CLE" ]       || { echo "❌ introuvable : $CLE"; exit 1; }
[ -r "$INSTALLSH" ] || { echo "❌ introuvable : $INSTALLSH"; exit 1; }
[ -r "$INSTALLMD" ] || { echo "❌ introuvable : $INSTALLMD"; exit 1; }
command -v ssh-keygen >/dev/null || { echo "❌ ssh-keygen absent : il tire les clés jetables du banc"; exit 1; }

T=$(mktemp -d)
nettoyer() { [ -n "${T:-}" ] && [ -d "$T" ] && find "$T" -mindepth 1 -delete && rmdir "$T"; }
trap nettoyer EXIT
echec=0; total=0
verifier() {  # verifier <libellé> <attendu> <obtenu>
  total=$((total+1))
  if [ "$2" = "$3" ]; then printf '  ✅ %-62s %s\n' "$1" "$3"
  else printf '  ❌ %-62s %s (attendu %s)\n' "$1" "$3" "$2"; echec=$((echec+1)); fi
}
rc() { "$@" >/dev/null 2>&1; echo $?; }
lue_par_openssh() { ssh-keygen -l -f "$1" >/dev/null 2>&1 && echo LUE || echo REFUSEE; }

O='command="/etc/selfkeyguard/selfrecover-secours.sh",no-port-forwarding,no-agent-forwarding,no-X11-forwarding'
ssh-keygen -q -t ed25519 -N '' -C banc-a -f "$T/a" || { echo "❌ ssh-keygen n'a pas tiré la clé a"; exit 1; }
ssh-keygen -q -t ed25519 -N '' -C banc-b -f "$T/b" || { echo "❌ ssh-keygen n'a pas tiré la clé b"; exit 1; }
A="$(cat "$T/a.pub")"; B="$(cat "$T/b.pub")"

# Sans ssh-keygen : bash par son chemin, et un PATH qui ne porte que ce que le
# script appelle d'autre. C'est la forme seule qui juge alors.
mkdir -p "$T/sans-keygen"
for outil in mktemp rm; do ln -s "$(command -v "$outil")" "$T/sans-keygen/$outil"; done
BASH_BIN="$(command -v bash)"
sans_keygen() { PATH="$T/sans-keygen" "$BASH_BIN" "$CLE" "$@"; }

echo "▸ borner — la ligne que pose install.sh"
printf '%s\n' "$A" > "$T/nue"
bash "$CLE" borner "$T/nue" "$O" > "$T/bornee" 2>/dev/null
verifier "clé nue : les options, UNE espace, puis la clé" "$O $A" "$(head -1 "$T/bornee")"
verifier "clé nue bornée : lue par OpenSSH" LUE "$(lue_par_openssh "$T/bornee")"
verifier "clé nue bornée : acceptée par verifier" 0 "$(rc bash "$CLE" verifier "$T/bornee")"

printf '# deux clés\n%s\n\n%s\n' "$A" "$B" > "$T/deux"
bash "$CLE" borner "$T/deux" "$O" > "$T/deux-bornees" 2>/dev/null
verifier "deux clés nues : deux lignes bornées" 2 "$(grep -c '^command=' "$T/deux-bornees")"
verifier "commentaire et ligne vide passent tels quels" "# deux clés|" "$(sed -n '1p' "$T/deux-bornees")|$(sed -n '3p' "$T/deux-bornees")"
verifier "deux clés bornées : acceptées par verifier" 0 "$(rc bash "$CLE" verifier "$T/deux-bornees")"

printf 'no-pty %s\n' "$A" > "$T/munie"
bash "$CLE" borner "$T/munie" "$O" > "$T/munie-bornee" 2>/dev/null
verifier "clé déjà munie d'options : une virgule entre les options" "$O,no-pty $A" "$(cat "$T/munie-bornee")"
verifier "clé munie bornée : lue par OpenSSH" LUE "$(lue_par_openssh "$T/munie-bornee")"

echo "▸ verifier — ce qui est refusé avant de remplacer le fichier"
printf '%s,%s\n' "$O" "$A" > "$T/virgule"
verifier "⭐ la ligne à virgule de la 0.6.2 : refusée par OpenSSH" REFUSEE "$(lue_par_openssh "$T/virgule")"
verifier "⭐ la ligne à virgule de la 0.6.2 : refusée par verifier" 1 "$(rc bash "$CLE" verifier "$T/virgule")"
verifier "⭐ la ligne à virgule, sans ssh-keygen : refusée par la forme" 1 "$(rc sans_keygen verifier "$T/virgule")"
verifier "la ligne à espace, sans ssh-keygen : acceptée par la forme" 0 "$(rc sans_keygen verifier "$T/bornee")"
printf '%s\n%s,%s\n' "$O $A" "$O" "$B" > "$T/seconde"
verifier "une seule ligne fautive suffit à refuser" 1 "$(rc bash "$CLE" verifier "$T/seconde")"
# Le message se capture avant d'être jugé : sous pipefail, un tube derrière un
# verifier qui sort en 1 rendrait « non » même quand le numéro y est.
message="$(bash "$CLE" verifier "$T/seconde" 2>&1)"
case "$message" in *'ligne(s) 2 '*) nomme=oui ;; *) nomme=non ;; esac
verifier "la ligne fautive est nommée par son numéro" oui "$nomme"
printf '%s %s\n' "$O" "${A%???????????}" > "$T/tronquee"
verifier "clé tronquée : refusée par la lecture OpenSSH" 1 "$(rc bash "$CLE" verifier "$T/tronquee")"
printf '# rien\n\n' > "$T/sans-cle"
verifier "fichier sans aucune clé : refusé" 1 "$(rc bash "$CLE" verifier "$T/sans-cle")"
verifier "mal appelé : code 2" 2 "$(rc bash "$CLE" borner "$T/nue")"

echo "▸ le câblage — install.sh et INSTALL.md passent par cle-amorcage.sh"
verifier "install.sh exige cle-amorcage.sh parmi ses fichiers" oui \
  "$(grep -qE 'format-slot\.sh cle-amorcage\.sh; do' "$INSTALLSH" && echo oui || echo non)"
verifier "install.sh borne par cle-amorcage.sh" oui \
  "$(grep -qF 'cle-amorcage.sh" borner "$AK" "$SR_OPTS"' "$INSTALLSH" && echo oui || echo non)"
verifier "install.sh relit la ligne avant de remplacer le fichier" oui \
  "$(grep -qF 'cle-amorcage.sh" verifier "$AK.nouveau"' "$INSTALLSH" && echo oui || echo non)"
verifier "install.sh n'assemble plus la ligne lui-même" 0 "$(grep -cF 'print o ","' "$INSTALLSH")"
verifier "la vérification finale exige le script de secours" oui \
  "$(grep -qF 'sbin/dropbear selfkeyguard/selfrecover-secours' "$INSTALLSH" && echo oui || echo non)"
verifier "⭐ INSTALL.md §8b : la ligne s'assemble avec une espace" oui \
  "$(grep -qF "printf '%s %s\\n' \"\$O\"" "$INSTALLMD" && echo oui || echo non)"
verifier "⭐ INSTALL.md : plus aucune virgule entre options et clé" 0 "$(grep -cF "printf '%s,%s" "$INSTALLMD")"
verifier "INSTALL.md §8b : la ligne est relue avant la régénération" oui \
  "$(grep -qF 'bash cle-amorcage.sh verifier /etc/dropbear/initramfs/authorized_keys' "$INSTALLMD" && echo oui || echo non)"

echo
if [ "$echec" -eq 0 ]; then
  echo "✅ $total/$total — la ligne d'amorçage se pose lisible, et une ligne illisible est refusée."
  exit 0
else
  echo "❌ $echec échec(s) sur $total"
  exit 1
fi
