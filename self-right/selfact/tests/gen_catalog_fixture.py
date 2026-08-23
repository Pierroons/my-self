#!/usr/bin/env python3
"""Extrait du catalogue réel les seules ressources que les gabarits citent.

Le catalogue complet — 1 895 entrées moissonnées les 1er et 15 — a quitté l'arbre
du code le 21/08/2026 : il vit dans l'état de l'instance, et `.gitignore` l'y
tient. Le garde-fou `test_gabarits_officiels.sh` en a pourtant besoin pour
répondre à sa question : « chaque renvoi curé pointe-t-il vers une ressource qui
existe ? »

🔑 **Il passait au vert sur les postes où une copie non versionnée traînait, et
rouge en intégration continue.** Un banc dont le résultat dépend de ce qui n'est
pas dans le dépôt ne mesure pas le dépôt.

L'extrait n'est pas circulaire : il est filtré depuis le catalogue RÉEL par les
identifiants que `gabarits.json` cite. Un identifiant inventé n'y entre pas — il
n'existe pas en amont — et le garde-fou le refuse. Ce qu'il ne mesure plus, c'est
la mort d'une ressource en amont ; c'est le métier de la sonde de fraîcheur, qui
interroge le vrai catalogue.

    python3 tests/gen_catalog_fixture.py           # après une mise à jour des gabarits
"""

import json
import os
import pathlib
import re
import subprocess
import sys

ICI = pathlib.Path(__file__).resolve().parent
API = ICI.parent / "api"
FIXTURE = ICI / "catalog-fixture.json"


def chemin_catalogue() -> str:
    """Demande le chemin à la fonction PHP qui fait autorité, jamais à une copie."""
    return subprocess.run(
        ["php", "-r", f"require '{API / 'chemins.php'}'; echo selfact_chemin_catalogue();"],
        capture_output=True, text=True, check=False,
    ).stdout.strip()


def main() -> int:
    cites = sorted(set(re.findall(r"\bR\d{3,6}\b", (API / "data" / "gabarits.json").read_text())))
    if not cites:
        print("✗ aucun identifiant cité par gabarits.json — l'extraction ne mesure rien", file=sys.stderr)
        return 2

    source = os.environ.get("SELFACT_CATALOG") or chemin_catalogue()
    if not source or not pathlib.Path(source).is_file():
        print(f"✗ catalogue réel introuvable ({source or 'aucun chemin'}).", file=sys.stderr)
        print("  L'extrait se régénère depuis le catalogue de l'instance, pas depuis lui-même.", file=sys.stderr)
        return 2

    complet = json.loads(pathlib.Path(source).read_text())
    gardes = [m for m in complet["models"] if m.get("id") in cites]
    manquants = sorted(set(cites) - {m["id"] for m in gardes})

    FIXTURE.write_text(json.dumps({
        "_meta": {
            "role": "extrait de test — les seules ressources citées par gabarits.json",
            "source": complet["_meta"].get("source"),
            "catalogue_last_sync": complet["_meta"].get("last_sync"),
            "catalogue_total": complet["_meta"].get("total"),
            "total": len(gardes),
            "regenerer": "python3 self-right/selfact/tests/gen_catalog_fixture.py",
        },
        "models": gardes,
    }, ensure_ascii=False, indent=1) + "\n")

    print(f"▸ {len(gardes)}/{len(cites)} ressources citées retrouvées dans le catalogue réel")
    print(f"  extrait écrit : {FIXTURE.relative_to(ICI.parent.parent.parent)}")
    if manquants:
        print(f"✗ absentes du catalogue réel : {', '.join(manquants)}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
