#!/usr/bin/env bash
# Banc du navigateur : la page s'ouvre-t-elle vraiment chez la titulaire ?
#
# Les autres bancs mesurent le format et le papier. Celui-ci mesure le GESTE :
# un fichier téléchargé, ouvert d'un double-clic, sans serveur ni réseau. C'est
# la seule chose que la titulaire fera, et c'était la seule que rien ne
# vérifiait — `pilote_app.mjs` et `pilote_atelier.mjs` évaluent le script dans
# Node avec un DOM factice, ce qui ne dit rien de `crypto.subtle` en `file://`.
# La page affirmait fonctionner hors ligne ; personne ne l'avait mesuré.
#
# Usage : bash tests/banc_navigateur.sh
# Sortie : 0 si tout est conforme, 1 sinon.
set -uo pipefail
MODULE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
T="$(mktemp -d)"
# Nettoyage sans effacement récursif forcé, comme le banc papier : le navigateur
# laisse des sockets et des verrous dans son profil, d'où le `! -type d` plutôt
# qu'un `-type f` qui les laisserait derrière lui.
# shellcheck disable=SC2317  # appelée par le trap EXIT
nettoyer(){ find "${T:?}" -mindepth 1 ! -type d -delete; find "${T:?}" -depth -type d -exec rmdir {} + 2>/dev/null; }
trap nettoyer EXIT

NAV="$(command -v firefox || command -v firefox-esr || true)"
[ -n "$NAV" ] || { echo "✗ aucun Firefox — le geste de la titulaire n'est pas mesuré"; exit 1; }
command -v node >/dev/null || { echo "✗ « node » absent — les deux lecteurs ne peuvent pas juger"; exit 1; }
python3 -c "import cryptography" 2>/dev/null || { echo "✗ module Python « cryptography » absent"; exit 1; }

echec=0; n=0
vert(){ n=$((n+1)); echo "  ✓ $1"; }
rouge(){ n=$((n+1)); echo "  ✗ $1"; echec=1; }

# ── Le profil jetable ────────────────────────────────────────────────────────
# `dump()` est le seul canal par lequel une page rend du texte au processus qui
# l'a lancée, sans copie d'écran ni pilote automatisé installé à côté. Il est
# fermé par défaut ; ce profil l'ouvre, et meurt avec le banc.
mkdir -p "$T/profil"
{
  echo 'user_pref("browser.dom.window.dump.enabled", true);'
  echo 'user_pref("browser.shell.checkDefaultBrowser", false);'
  echo 'user_pref("datareporting.policy.dataSubmissionEnabled", false);'
  echo 'user_pref("toolkit.telemetry.enabled", false);'
} > "$T/profil/user.js"

python3 "$MODULE/outils/faire_atelier.py" >/dev/null || { echo "✗ assemblage de l'atelier"; exit 1; }

# `page <sortie> [préambule]` — l'atelier, éventuellement précédé d'un script qui
# abîme l'environnement, suivi du pilote.
page(){
  { [ -n "${2:-}" ] && printf '<script>%s</script>\n' "$2"
    cat "$MODULE/sortie/selfvault-atelier.html"
    printf '\n<script>\n'
    cat "$MODULE/tests/pilote_navigateur.js"
    printf '\n</script>\n'
  } > "$1"
}

# `ouvrir <fichier>` — rend les lignes « SV … » que la page a écrites.
ouvrir(){
  MOZ_HEADLESS=1 timeout 120 "$NAV" --new-instance -profile "$T/profil" "file://$1" 2>&1 \
    | sed -n 's/^SV //p'
}

echo "▸ Le geste de la titulaire — un fichier, un double-clic, pas de réseau"

page "$T/atelier.html"
ouvrir "$T/atelier.html" > "$T/sortie.txt"
val(){ sed -n "s/^$1=//p" "$T/sortie.txt" | head -1; }

attendu(){ # attendu <clé> <valeur attendue> <intitulé>
  local eu; eu=$(val "$1")
  if [ "$eu" = "$2" ]; then vert "$3"; else rouge "$3 — $1=${eu:-rien}"; fi
}

attendu protocole    "file:"  "la page tourne depuis un fichier local"
attendu securise     "true"   "un fichier local est un contexte réputé sûr — WebCrypto est permis"
attendu subtle       "object" "crypto.subtle est présent"
attendu bouton_actif "true"   "la page ne s'est pas désactivée d'elle-même"
attendu FIN          "ok"     "l'atelier a fabriqué un coffre dans le navigateur"
[ "$(val FIN)" = "ok" ] || echo "    ↳ $(val erreur)$(val etat)"

# ── Ce que le navigateur a produit, jugé par le format ───────────────────────
echo "▸ Ce que le navigateur a produit — jugé par le format, pas par lui-même"

L1=$(val L1); L2=$(val L2)
python3 "$MODULE/tests/juge_navigateur.py" "$MODULE" "$T/sortie.txt" "$T/coffre.selfvault" \
  > "$T/verdicts.txt" 2>&1
verdict(){ sed -n "s/^$1=//p" "$T/verdicts.txt" | head -1; }
juge(){ # juge <clé> <intitulé>
  local v; v=$(verdict "$1")
  if [ "$v" = "oui" ]; then vert "$2"; else rouge "$2 — ${v#non:}"; fi
}

juge recolle        "le coffre est ressorti entier du navigateur"
juge json           "c'est du JSON, pas un message d'erreur recollé"
juge forme          "les onze champs tiennent devant outils/selfvault.py"
juge format_annonce "le navigateur écrit du SELFVAULT3, pas un dialecte"
juge serrures       "les deux serrures sont là"
juge sceau          "le sceau posé par le navigateur se vérifie en Python"
juge empreinte      "l'empreinte affichée est celle que le format calcule"

# ── Les deux lecteurs indépendants ───────────────────────────────────────────
# Les mêmes que `banc.sh` : la réimplémentation écrite depuis la notice imprimée,
# et le pilote du déchiffreur qui sera collé dans le pli. Si l'un ouvre et pas
# l'autre, ce n'est pas le coffre qui est en cause.
echo "▸ Les deux lecteurs indépendants, aux deux serrures"

# Les deux lecteurs rendent l'empreinte SHA-256 du clair, pas le clair : c'est
# le témoin que `banc.sh` emploie déjà, et il est plus serré qu'une sous-chaîne
# — il ne tient que si le texte est rendu au caractère près.
TEMOIN=$(val clair)
if [ -z "$TEMOIN" ]; then rouge "le navigateur n'a pas déposé l'empreinte du texte saisi"; fi
for lecteur in "notice:$MODULE/outils/test_webcrypto.mjs" "pli:$MODULE/tests/pilote_app.mjs"; do
  nom=${lecteur%%:*}; cible=${lecteur#*:}
  for serrure in "L1 (dépositaire):$L1" "L2 (titulaire):$L2"; do
    quoi=${serrure%%:*}; secret=${serrure#*:}
    if ! clair=$(timeout 60 node "$cible" "$T/coffre.selfvault" "$secret" 2>&1); then
      rouge "$quoi ne s'ouvre pas [$nom] : ${clair%%$'\n'*}"
    elif [[ "$clair" != *"$TEMOIN"* ]]; then
      rouge "$quoi s'ouvre [$nom] mais rend un autre clair que celui saisi"
    else
      vert "$quoi s'ouvre et rend le texte saisi dans le navigateur [$nom]"
    fi
  done
done

# ── L'identité, dans la forme que la chaîne du pli attend ────────────────────
echo "▸ La jonction avec la chaîne du pli"

juge meta "meta.json porte les trois champs que faire_pli.py exige"

# ── Le contre-témoin ─────────────────────────────────────────────────────────
# Une sonde qu'on n'a jamais vue rougir ne mesure rien. On retire WebCrypto à la
# page avant qu'elle s'exécute : c'est exactement ce que vivrait la titulaire sur
# un navigateur qui refuserait `crypto.subtle` à un fichier local.
echo "▸ Le contre-témoin — la sonde sait-elle rougir"

page "$T/sans_crypto.html" "try{Object.defineProperty(window.crypto,'subtle',{value:undefined,configurable:true});}catch(e){}"
ouvrir "$T/sans_crypto.html" > "$T/sans.txt"
if grep -q '^subtle=object' "$T/sans.txt"; then
  rouge "le contre-témoin n'a pas retiré WebCrypto — la sonde ne prouve rien"
elif grep -q '^FIN=ok$' "$T/sans.txt"; then
  rouge "l'atelier prétend avoir fabriqué un coffre SANS WebCrypto"
else
  vert "sans WebCrypto, l'atelier ne fabrique rien — la sonde sait rougir"
fi

echo
if [ $echec -eq 0 ]; then
  echo "✓ Banc navigateur conforme — $n contrôles, sur $($NAV --version | head -1)."
else
  echo "✗ Banc navigateur : des contrôles ont échoué."
fi
exit $echec
