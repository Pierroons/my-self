#!/bin/bash
# Contrôle d'écosystème — ce que ce dépôt dit de selffarm-lite est-il encore vrai ?
#
# 🔑 **Pourquoi ce script existe.** my-self et selffarm-lite se décrivent l'un l'autre, et chacun
# avançait sans que l'autre le sache : au 27/09/2026, selffarm-lite présentait encore les modules de
# my-self tels qu'en juin, et l'accueil de my-self affichait des statuts SelfFarm écrits à la main que
# selffarm-lite disait faux. Chaque dépôt fait foi pour ses propres modules et publie un `modules.json` ;
# l'autre le LIT au lieu de le recopier. Ici, les blocs balisés des deux README racine et de l'accueil
# sont produits depuis le manifeste de selffarm-lite et ses tags, et ce contrôle rougit dès qu'un bloc
# ne correspond plus.
#
# Le retard vient de l'autre dépôt : ce contrôle tourne donc aussi chaque matin (suivi.yml), pas
# seulement quand on pousse ici.
#
# Usage : bash scripts/check-ecosysteme.sh            compare les blocs à ce que selffarm-lite publie
#         bash scripts/check-ecosysteme.sh --ecrire   réécrit les blocs
# Sortie : 0 si les blocs sont à jour, 1 sinon, 2 si le manifeste est injoignable ou mal formé, ou un
# bloc absent.
#
# SELFFARM_MANIFESTE_URL et SELFFARM_DEPOT remplacent les sources publiques (canari, essais hors ligne).

set -uo pipefail
cd "$(git rev-parse --show-toplevel)" || exit 2

mode="${1:-verifier}"
case "$mode" in
  verifier|--ecrire) ;;
  *) echo "usage : $0 [--ecrire]" >&2; exit 2 ;;
esac

echo "▸ Écosystème — les blocs selffarm-lite des README racine et de l'accueil"
MODE="$mode" \
URL="${SELFFARM_MANIFESTE_URL:-https://raw.githubusercontent.com/Pierroons/selffarm-lite/main/modules.json}" \
DEPOT="${SELFFARM_DEPOT:-https://github.com/Pierroons/selffarm-lite}" python3 - <<'PY'
import html, json, os, re, subprocess, sys

url, depot = os.environ["URL"], os.environ["DEPOT"]
lu = subprocess.run(["curl", "-fsSL", "--retry", "3", "--max-time", "20", url], capture_output=True, text=True)
if lu.returncode != 0 or not lu.stdout.strip():
    print(f"  ✗ manifeste de selffarm-lite injoignable : {url}")
    sys.exit(2)
try:
    manifeste = json.loads(lu.stdout)
    v = manifeste["depot"]["version"]
    modules = manifeste["modules"]
    if not re.fullmatch(r"\d+\.\d+\.\d+", v or ""):
        raise ValueError(f"version de dépôt hors du format X.Y.Z : « {v} »")
    if not modules:
        raise ValueError("aucun module")
    for m in modules:
        for cle in ("id", "nom", "dossier"):
            if not m.get(cle):
                raise ValueError(f"module sans « {cle} » : {m}")
        if not m.get("statut", {}).get("fr") or not m.get("resume", {}).get("fr"):
            raise ValueError(f"{m['id']} : statut ou résumé français manquant")
except (ValueError, KeyError, TypeError, json.JSONDecodeError) as e:
    print(f"  ✗ manifeste de selffarm-lite mal formé : {e}")
    sys.exit(2)

tags = subprocess.run(["git", "ls-remote", "--tags", depot], capture_output=True, text=True)
if tags.returncode != 0:
    print(f"  ✗ tags de selffarm-lite injoignables : {depot}")
    sys.exit(2)
publiee = re.search(rf"refs/tags/v{re.escape(v)}(\^\{{\}})?$", tags.stdout, re.M) is not None

DEPOT_WEB = "https://github.com/Pierroons/selffarm-lite"
LIEN = f"[Pierroons/selffarm-lite]({DEPOT_WEB})"
DEBUT = "<!-- ecosysteme:selffarm-lite:debut — produit par scripts/check-ecosysteme.sh --ecrire -->"
FIN = "<!-- ecosysteme:selffarm-lite:fin -->"

def carte(m):
    # Un `dossier` peut désigner un fichier (une route du webapp) : GitHub le montre par `blob`.
    genre = "blob" if "." in m["dossier"].rsplit("/", 1)[-1] else "tree"
    statut = m["statut"]["fr"]
    classe = "live" if statut == "disponible" else "draft" if statut == "en préparation" else "alpha"
    resume = m["resume"]["fr"]
    return "\n".join([
        f'      <a class="mod" href="{DEPOT_WEB}/{genre}/main/{html.escape(m["dossier"])}" target="_blank" rel="noopener">',
        f'        <span class="name">{html.escape(m["nom"])}<span class="status {classe}">{html.escape(statut)}</span></span>',
        f'        <span class="role">{html.escape(resume[:1].upper() + resume[1:])}.</span>',
        "      </a>",
    ])

accueil = "\n".join(
    [f'    <p class="muted">Version {"publiée" if publiee else "annoncée, pas encore publiée"} : '
     f'<strong>v{v}</strong> — ses modules, tels que son propre manifeste les décrit :</p>',
     '    <div class="floor-modules">']
    + [carte(m) for m in modules]
    + ["    </div>"])

blocs = {
    "README.md": f"**SelfFarm-Lite**, the farm application layer of the ecosystem, lives in its own repository: "
                 f"{LIEN} — **v{v}**, {'released' if publiee else 'not yet released'}.",
    "README.fr.md": f"**SelfFarm-Lite**, l'étage applicatif agricole de l'écosystème, vit dans son propre dépôt : "
                    f"{LIEN} — **v{v}**, {'publiée' if publiee else 'pas encore publiée'}.",
    "web/my-self.fr/index.html": accueil,
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
etat = f"v{v} {'publiée' if publiee else 'pas encore publiée'}, {len(modules)} modules"
if ecarts and os.environ["MODE"] != "--ecrire":
    print(f"  ✗ selffarm-lite publie {etat} ; le bloc de {', '.join(ecarts)} ne le dit plus")
    print("     bash scripts/check-ecosysteme.sh --ecrire le remet à jour")
    sys.exit(1)
if ecarts:
    print(f"  ✓ bloc réécrit dans {', '.join(ecarts)} : selffarm-lite {etat}")
else:
    print(f"  ✓ les README et l'accueil disent selffarm-lite {etat}")
PY
