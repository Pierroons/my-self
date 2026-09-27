#!/bin/bash
# Contrôle des versions — chaque module dit-il partout la même version, et cette version a-t-elle son tag ?
#
# 🔑 **Pourquoi ce script existe.** Jusqu'au 27/09/2026, la version d'un module n'existait qu'en texte,
# recopiée à la main dans ses badges, son pilier, le README racine et les pages servies, et le tag signé
# était un geste à part que rien ne réclamait. L'audit du 27/09 a trouvé SelfJustice annoncée en v0.4.0
# avec un seul tag, v0.1.0 ; SelfAct en trois versions selon le fichier, sans aucun tag. `modules.json`
# est désormais la source unique : ce script vérifie que chaque porteur qu'il déclare la répète, et que
# la version annoncée a été publiée.
#
# Usage : bash scripts/check-versions.sh          les porteurs disent la version de modules.json
#         bash scripts/check-versions.sh --tags   chaque version a son tag <id>-v<version>
# Sortie : 0 si tout concorde, 1 sinon, 2 si modules.json est absent ou illisible.
#
# En mode --tags, une version sans tag est signalée en jaune, puis en rouge au bout de
# VERSIONS_DELAI_JOURS jours (7 par défaut), comptés depuis le jour où modules.json porte cette version.

set -uo pipefail
cd "$(git rev-parse --show-toplevel)" || exit 2

mode="${1:-porteurs}"
case "$mode" in
  porteurs|--tags) ;;
  *) echo "usage : $0 [--tags]" >&2; exit 2 ;;
esac

MODE="$mode" DELAI="${VERSIONS_DELAI_JOURS:-7}" python3 - <<'PY'
import json, os, re, subprocess, sys, time

def lire_manifeste(texte):
    donnees = json.loads(texte)
    modules = donnees["modules"]
    ids = [m["id"] for m in modules]
    if len(ids) != len(set(ids)):
        raise ValueError("identifiant de module en double")
    for m in modules:
        v = m.get("version")
        if v is not None and not re.fullmatch(r"\d+\.\d+\.\d+", v):
            raise ValueError(f"{m['id']} : version « {v} » hors du format X.Y.Z")
    return modules

try:
    modules = lire_manifeste(open("modules.json", encoding="utf-8").read())
except (OSError, ValueError, KeyError, json.JSONDecodeError) as e:
    print(f"  ✗ modules.json illisible : {e}")
    sys.exit(2)

versionnes = [m for m in modules if m.get("version")]

if os.environ["MODE"] == "porteurs":
    print("▸ Versions — chaque porteur dit la version de modules.json")
    ecarts, lus = [], 0
    for m in versionnes:
        for fichier, marque in m["porteurs"]:
            if not os.path.isfile(fichier):
                ecarts.append(f"{m['id']} : porteur absent — {fichier}")
                continue
            lignes = [(n, l) for n, l in enumerate(open(fichier, encoding="utf-8", errors="replace"), 1) if marque in l]
            if not lignes:
                # Une marque disparue rendrait le porteur invisible au contrôle : on le dit.
                ecarts.append(f"{m['id']} : marque « {marque} » introuvable dans {fichier}")
                continue
            for n, l in lignes:
                lus += 1
                trouve = re.search(r"\d+\.\d+\.\d+", l[l.index(marque) + len(marque):])
                if not trouve or trouve.group(0) != m["version"]:
                    dit = trouve.group(0) if trouve else "aucune version"
                    ecarts.append(f"{fichier}:{n} — {m['nom']} y est en {dit}, modules.json dit {m['version']}")
    if ecarts:
        print(f"  ✗ {len(ecarts)} écart(s)")
        for e in ecarts:
            print("     " + e)
        sys.exit(1)
    print(f"  ✓ {lus} porteur(s) de {len(versionnes)} module(s) disent la version de modules.json")
    sys.exit(0)

# --tags
print("▸ Versions — chaque version annoncée a son tag")
delai = int(os.environ["DELAI"])
tags = set(subprocess.run(["git", "tag", "-l"], capture_output=True, text=True).stdout.split())
maintenant = time.time()

def depuis_quand(ident, version):
    """Horodatage du plus ancien commit depuis lequel modules.json porte cette version, sans interruption.
    Une version présente seulement dans l'arbre de travail date de maintenant."""
    tete = subprocess.run(["git", "show", "HEAD:modules.json"], capture_output=True, text=True)
    def version_dans(texte):
        try:
            return next((m.get("version") for m in lire_manifeste(texte) if m["id"] == ident), None)
        except (ValueError, KeyError, json.JSONDecodeError):
            return None
    if tete.returncode != 0 or version_dans(tete.stdout) != version:
        return maintenant
    journal = subprocess.run(["git", "log", "--format=%H %ct", "--", "modules.json"],
                             capture_output=True, text=True).stdout.split("\n")
    depuis = maintenant
    for ligne in filter(None, journal):
        sha, quand = ligne.split()
        texte = subprocess.run(["git", "show", f"{sha}:modules.json"], capture_output=True, text=True).stdout
        if version_dans(texte) != version:
            break
        depuis = int(quand)
    return depuis

rouges, jaunes, publies = [], [], 0
for m in versionnes:
    attendu = f"{m['id']}-v{m['version']}"
    if attendu in tags or any(t.startswith(attendu + "-") for t in tags):
        publies += 1
        continue
    jours = int((maintenant - depuis_quand(m["id"], m["version"])) // 86400)
    ligne = f"{m['nom']} v{m['version']} : pas de tag {attendu}, annoncée depuis {jours} jour(s)"
    (rouges if jours >= delai else jaunes).append(ligne)

print(f"  ✓ {publies} version(s) sur {len(versionnes)} ont leur tag")
for j in jaunes:
    print(f"  • {j} — à taguer avant {delai} jours")
if rouges:
    print(f"  ✗ {len(rouges)} version(s) annoncée(s) depuis {delai} jours ou plus sans tag")
    for r in rouges:
        print("     " + r)
    sys.exit(1)
sys.exit(0)
PY
