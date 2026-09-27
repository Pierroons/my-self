#!/bin/bash
# Contrôle d'écosystème — ce que ce dépôt dit de selffarm-lite est-il encore vrai ?
#
# 🔑 **Pourquoi ce script existe.** my-self et selffarm-lite se décrivent l'un l'autre, et chacun
# avançait sans que l'autre le sache : au 27/09/2026, selffarm-lite présentait encore les modules de
# my-self tels qu'en juin, et my-self ne citait selffarm-lite dans aucun README. Chaque dépôt fait foi
# pour ses propres modules ; l'autre le LIT au lieu de le recopier. Ici, les blocs balisés des deux README
# racine et de l'accueil sont produits depuis ce que selffarm-lite publie (son fichier VERSION et ses tags),
# et ce contrôle rougit dès qu'un bloc ne correspond plus.
#
# Le retard vient de l'autre dépôt : ce contrôle tourne donc aussi chaque matin (suivi.yml), pas
# seulement quand on pousse ici.
#
# Usage : bash scripts/check-ecosysteme.sh            compare les blocs à ce que selffarm-lite publie
#         bash scripts/check-ecosysteme.sh --ecrire   réécrit les blocs
# Sortie : 0 si les blocs sont à jour, 1 sinon, 2 si selffarm-lite est injoignable ou un bloc absent.
#
# SELFFARM_VERSION_URL et SELFFARM_DEPOT remplacent les sources publiques (canari, essais hors ligne).

set -uo pipefail
cd "$(git rev-parse --show-toplevel)" || exit 2

mode="${1:-verifier}"
case "$mode" in
  verifier|--ecrire) ;;
  *) echo "usage : $0 [--ecrire]" >&2; exit 2 ;;
esac

URL="${SELFFARM_VERSION_URL:-https://raw.githubusercontent.com/Pierroons/selffarm-lite/main/VERSION}"
DEPOT="${SELFFARM_DEPOT:-https://github.com/Pierroons/selffarm-lite}"

echo "▸ Écosystème — les blocs selffarm-lite des README racine et de l'accueil"
if ! version="$(curl -fsSL --retry 3 --max-time 20 "$URL" | tr -d '[:space:]')" || [ -z "$version" ]; then
  echo "  ✗ version de selffarm-lite injoignable : $URL"
  exit 2
fi
if ! [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "  ✗ selffarm-lite publie une version hors du format X.Y.Z : « $version »"
  exit 2
fi
if ! tags="$(git ls-remote --tags "$DEPOT" 2>/dev/null)"; then
  echo "  ✗ tags de selffarm-lite injoignables : $DEPOT"
  exit 2
fi
publiee=non
grep -qE "refs/tags/v${version//./\\.}(\^\{\})?$" <<<"$tags" && publiee=oui

MODE="$mode" VERSION="$version" PUBLIEE="$publiee" python3 - <<'PY'
import os, re, sys

v, publiee = os.environ["VERSION"], os.environ["PUBLIEE"] == "oui"
LIEN = "[Pierroons/selffarm-lite](https://github.com/Pierroons/selffarm-lite)"
DEBUT = "<!-- ecosysteme:selffarm-lite:debut — produit par scripts/check-ecosysteme.sh --ecrire -->"
FIN = "<!-- ecosysteme:selffarm-lite:fin -->"
blocs = {
    "README.md": f"**SelfFarm-Lite**, the farm application layer of the ecosystem, lives in its own repository: "
                 f"{LIEN} — **v{v}**, {'released' if publiee else 'not yet released'}.",
    "README.fr.md": f"**SelfFarm-Lite**, l'étage applicatif agricole de l'écosystème, vit dans son propre dépôt : "
                    f"{LIEN} — **v{v}**, {'publiée' if publiee else 'pas encore publiée'}.",
    "web/my-self.fr/index.html": f'    <p class="muted">Version {"publiée" if publiee else "annoncée, pas encore publiée"} : '
                                 f'<strong>v{v}</strong>. Le détail de ses modules et de leur état vit dans son '
                                 f'propre dépôt.</p>',
}
motif = re.compile(re.escape(DEBUT) + r"\n(.*?)\n[ \t]*" + re.escape(FIN), re.S)
ecarts, absents = [], []
for fichier, attendu in blocs.items():
    texte = open(fichier, encoding="utf-8").read()
    trouve = motif.search(texte)
    if not trouve:
        absents.append(fichier)
        continue
    if trouve.group(1) != attendu:
        ecarts.append(fichier)
        if os.environ["MODE"] == "--ecrire":
            texte = texte[:trouve.start(1)] + attendu + texte[trouve.end(1):]
            open(fichier, "w", encoding="utf-8").write(texte)

if absents:
    print("  ✗ bloc balisé absent de : " + ", ".join(absents))
    sys.exit(2)
etat = "publiée" if publiee else "pas encore publiée"
if ecarts and os.environ["MODE"] != "--ecrire":
    print(f"  ✗ selffarm-lite est en v{v} ({etat}) ; le bloc de {', '.join(ecarts)} ne le dit plus")
    print("     bash scripts/check-ecosysteme.sh --ecrire le remet à jour")
    sys.exit(1)
if ecarts:
    print(f"  ✓ bloc réécrit dans {', '.join(ecarts)} : selffarm-lite v{v} ({etat})")
else:
    print(f"  ✓ les README et l'accueil disent selffarm-lite v{v} ({etat})")
PY
