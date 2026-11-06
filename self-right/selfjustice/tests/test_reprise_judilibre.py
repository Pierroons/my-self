#!/usr/bin/env python3
"""Garde-fou — le rafraîchissement Judilibre repart de ses propres juridictions.

`depuis_auto()` fixe le début de la fenêtre que `--depuis auto` remoissonne. La
table `decisions` qu'il lit est partagée avec le collecteur JADE, dont certains
fonds sont éteints (la Cour de discipline budgétaire s'arrête le 19/04/2000).
Compter ces fonds fait remoissonner toute l'histoire de `cc` et `ca` à chaque
passage : au 01/11/2026, une fenêtre ouverte le 20/03/2000, 147 tranches, et
l'amont qui en refuse une partie.

Deux volets. Le premier charge `depuis_auto()` par AST, comme
`test_sedimentation.py` : le script complet importe l'API amont et une clé que
l'intégration continue n'a pas. Le second lance le VRAI moissonneur contre un
amont simulé, parce qu'une date de reprise juste ne dit pas ce que le passage
en fait : quelles juridictions il interroge, ce qu'il efface de la mémoire de
reprise, et comment il sort.

    python3 tests/test_reprise_judilibre.py
"""

import ast
import json
import os
import pathlib
import socket
import sqlite3
import subprocess
import sys
import tempfile
import threading
import urllib.parse
from datetime import date, datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, HTTPServer

SCRIPT = pathlib.Path(__file__).resolve().parent.parent / "tools" / "build_judilibre_index.py"
VOULUES = {"depuis_auto", "JURIDICTIONS", "DATE_DEBUT", "RECOUVREMENT_JOURS"}
ECHECS = []


def verdict(ok: bool, libelle: str) -> None:
    if not ok:
        ECHECS.append(libelle)
    print(f"  {'✓' if ok else '✗'} {libelle}")


def charger() -> dict:
    arbre = ast.parse(SCRIPT.read_text())
    gardes, trouves = [], set()
    for noeud in arbre.body:
        nom = None
        if isinstance(noeud, ast.FunctionDef):
            nom = noeud.name
        elif isinstance(noeud, ast.Assign) and isinstance(noeud.targets[0], ast.Name):
            nom = noeud.targets[0].id
        if nom in VOULUES:
            gardes.append(noeud)
            trouves.add(nom)
    if trouves != VOULUES:
        print(f"✗ manquant dans build_judilibre_index.py : {sorted(VOULUES - trouves)}",
              file=sys.stderr)
        raise SystemExit(2)
    espace: dict = {"date": date, "datetime": datetime, "timedelta": timedelta}
    exec(compile(ast.Module(body=gardes, type_ignores=[]), str(SCRIPT), "exec"), espace)
    return espace


def base(lignes) -> sqlite3.Connection:
    db = sqlite3.connect(":memory:")
    db.execute("CREATE TABLE decisions (id TEXT PRIMARY KEY, jurisdiction TEXT, "
               "decision_date TEXT, date_suspecte INTEGER DEFAULT 0)")
    db.executemany("INSERT INTO decisions VALUES (?,?,?,?)",
                   [(f"d{i}", j, d, s) for i, (j, d, s) in enumerate(lignes)])
    return db


def volet_calcul() -> None:
    espace = charger()
    depuis_auto = espace["depuis_auto"]
    debut = espace["DATE_DEBUT"]
    recouvrement = espace["RECOUVREMENT_JOURS"]
    jour = "2026-11-03"

    def moins_recouvrement(j: str) -> str:
        return (date.fromisoformat(j) - timedelta(days=recouvrement)).isoformat()

    print(f"▸ Les juridictions moissonnées sont {espace['JURIDICTIONS']}")
    verdict(set(espace["JURIDICTIONS"]) == {"cc", "ca"},
            "le banc décrit `cc` et `ca` ; une liste changée demande de relire ses cas")

    print()
    print("▸ Un fonds JADE éteint ne recule pas la reprise")
    # Les dates de l'index servi, lues le 03/11/2026 sur la base de production.
    servie = [
        ("cc", "2026-10-22", 0), ("ca", "2026-10-29", 0),
        ("ce", "2026-10-29", 0), ("caa", "2026-10-29", 0), ("tc", "2026-10-05", 0),
        ("ta", "2009-12-17", 0), ("cdbf", "2000-04-19", 0),
    ]
    obtenu = depuis_auto(base(servie), jour)
    attendu = (moins_recouvrement("2026-10-22"), [])
    verdict(obtenu == attendu, f"index servi du 03/11/2026 → {obtenu}, attendu {attendu}")

    print()
    print("▸ La reprise suit la juridiction moissonnée la plus en retard")
    obtenu = depuis_auto(base([("cc", "2026-06-01", 0), ("ca", "2026-10-29", 0)]), jour)
    attendu = (moins_recouvrement("2026-06-01"), [])
    verdict(obtenu == attendu, f"cc au 01/06, ca au 29/10 → {obtenu}, attendu {attendu}")

    print()
    print("▸ Une décision datée dans le futur ne fixe pas la reprise")
    # `enregistrer()` ne marque suspectes que les dates d'avant 1800 : une date
    # future arrive avec `date_suspecte = 0`, et c'est ainsi qu'on la plante.
    obtenu = depuis_auto(base([("cc", "2026-06-01", 0), ("cc", "2099-01-01", 0),
                               ("ca", "2026-10-29", 0)]), jour)
    attendu = (moins_recouvrement("2026-06-01"), [])
    verdict(obtenu == attendu, f"cc au 01/06 plus une ligne cc en 2099 → {obtenu}, attendu {attendu}")
    obtenu = depuis_auto(base([("cc", "2026-10-22", 0), ("ca", "2026-10-29", 0),
                               ("ca", "2099-01-01", 1)]), jour)
    attendu = (moins_recouvrement("2026-10-22"), [])
    verdict(obtenu == attendu, f"une ligne marquée suspecte → {obtenu}, attendu {attendu}")

    print()
    print("▸ Une juridiction absente est rendue à part, sans ramener la fenêtre au début")
    obtenu = depuis_auto(base([("cc", "2026-10-22", 0), ("ce", "2026-10-29", 0)]), jour)
    attendu = (moins_recouvrement("2026-10-22"), ["ca"])
    verdict(obtenu == attendu, f"`ca` absente → {obtenu}, attendu {attendu}")
    obtenu = depuis_auto(base([("cc", "2026-10-22", 0), ("ca", "0201-02-24", 1)]), jour)
    verdict(obtenu == attendu, f"`ca` n'a que des dates suspectes → {obtenu}, attendu {attendu}")
    obtenu = depuis_auto(base([]), jour)
    attendu = (debut, ["cc", "ca"])
    verdict(obtenu == attendu, f"index vide → {obtenu}, attendu {attendu}")


class Amont(BaseHTTPRequestHandler):
    """Un amont qui ne connaît aucune décision, et note chaque requête."""
    appels: list = []

    def log_message(self, *args):
        pass

    def do_GET(self):
        url = urllib.parse.urlparse(self.path)
        Amont.appels.append((url.path, dict(urllib.parse.parse_qsl(url.query))))
        corps = json.dumps({"total": 0, "results": [], "next_batch": None}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(corps)


def volet_passage() -> None:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        port = s.getsockname()[1]
    serveur = HTTPServer(("127.0.0.1", port), Amont)
    threading.Thread(target=serveur.serve_forever, daemon=True).start()

    with tempfile.TemporaryDirectory() as tmp:
        tmp = pathlib.Path(tmp)
        (tmp / "keyid").write_text("factice\n")
        index, marqueur = tmp / "index.sqlite", tmp / "marqueur.txt"
        env = {**os.environ,
               "SELFJUSTICE_JUDILIBRE_BASE": f"http://127.0.0.1:{port}",
               "JUDILIBRE_KEY_FILE": str(tmp / "keyid"),
               "JUDILIBRE_DB": str(index),
               "JUDILIBRE_MARQUEUR": str(marqueur)}
        aujourdhui = datetime.now(timezone.utc).date()

        def jour(n: int) -> str:
            return (aujourdhui - timedelta(days=n)).isoformat()

        def passage() -> tuple:
            Amont.appels.clear()
            p = subprocess.run([sys.executable, str(SCRIPT), "--depuis", "auto"], env=env,
                               capture_output=True, text=True, timeout=120)
            exports = [q for chemin, q in Amont.appels if chemin.endswith("/export")]
            return p.returncode, p.stdout + p.stderr, exports

        def ecrire(sql: str, *lignes) -> None:
            db = sqlite3.connect(index)
            db.executemany(sql, lignes)
            db.commit()
            db.close()

        print()
        print("▸ Le vrai passage, sur un index vide")
        code, sortie, exports = passage()
        verdict(code == 4, f"sort en 4 (juridiction absente) — rendu {code}")
        verdict("cc : absente" in sortie and "ca : absente" in sortie,
                "le journal nomme les deux juridictions absentes")
        verdict(exports == [], f"aucune tranche demandée — {len(exports)} demandée(s)")
        verdict("0100-01-01" not in sortie, "aucune fenêtre ouverte en l'an 0100 au journal")
        verdict(not marqueur.exists(), "le marqueur n'est pas écrit")

        print()
        print("▸ Le vrai passage, `cc` présente, `ca` absente, un fonds JADE éteint")
        ecrire("INSERT INTO decisions (id, jurisdiction, decision_date, date_suspecte) "
               "VALUES (?,?,?,0)",
               ("cc1", "cc", jour(10)), ("cdbf1", "cdbf", "2000-04-19"))
        # Une tranche de `ca` déjà faite, comme la laisse une moisson complète en
        # cours, et une de `cc` dans la fenêtre : la première est une mémoire de
        # reprise, la seconde est à refaire.
        ecrire("INSERT INTO intervalles_faits VALUES (?,?,?,?,?)",
               ("ca", jour(20), jour(15), 7, jour(1)),
               ("cc", jour(20), jour(15), 7, jour(1)))
        code, sortie, exports = passage()
        verdict(code == 4, f"sort en 4 (juridiction absente) — rendu {code}")
        verdict("seront reprises" not in sortie, "rien ne promet un rattrapage au passage suivant")
        verdict("ca : absente" in sortie and "cc : absente" not in sortie,
                "le journal nomme `ca`, et elle seule")
        juris = sorted({q.get("jurisdiction") for q in exports})
        verdict(juris == ["cc"], f"seule `cc` est interrogée — {juris}")
        debuts = sorted(q.get("date_start", "") for q in exports)
        verdict(bool(debuts) and debuts[0] == jour(10 + 30),
                f"la fenêtre s'ouvre au {jour(40)} — {debuts[0] if debuts else 'aucune requête'}")
        db = sqlite3.connect(index)
        restantes = {r[0] for r in db.execute("SELECT jurisdiction FROM intervalles_faits")}
        db.close()
        verdict("ca" in restantes, "la tranche faite de `ca` est conservée")
        verdict("cc" not in restantes, "celle de `cc`, dans la fenêtre, est effacée pour être refaite")
        verdict(not marqueur.exists(), "le marqueur n'est pas écrit")

        print()
        print("▸ Le vrai passage, les deux juridictions présentes")
        ecrire("INSERT INTO decisions (id, jurisdiction, decision_date, date_suspecte) "
               "VALUES (?,?,?,0)", ("ca1", "ca", jour(5)))
        code, sortie, exports = passage()
        verdict(code == 0, f"sort en 0 (complet) — rendu {code}")
        juris = sorted({q.get("jurisdiction") for q in exports})
        verdict(juris == ["ca", "cc"], f"`cc` et `ca` sont interrogées — {juris}")
        debuts = sorted(q.get("date_start", "") for q in exports)
        verdict(bool(debuts) and debuts[0] == jour(40),
                f"la fenêtre s'ouvre au {jour(40)} — {debuts[0] if debuts else 'aucune requête'}")
        verdict(marqueur.exists(), "le marqueur est écrit")

    serveur.shutdown()


def main() -> int:
    # Le second volet ne dépend pas de la signature de `depuis_auto()` : il se
    # joue même quand le premier n'a pas pu l'appeler.
    try:
        volet_calcul()
    except Exception as e:
        verdict(False, f"`depuis_auto()` n'a pas pu être appelée : {e!r}")
    volet_passage()
    print()
    if ECHECS:
        print(f"✗ {len(ECHECS)} contrôle(s) en échec")
        return 1
    print("✓ la reprise ne dépend que des juridictions moissonnées")
    return 0


if __name__ == "__main__":
    sys.exit(main())
