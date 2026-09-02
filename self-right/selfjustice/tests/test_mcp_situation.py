#!/usr/bin/env python3
"""Garde-fou — ce que le MCP rend VRAIMENT d'une fiche de situation.

🔑 **Pourquoi ce banc ne ressemble pas à son voisin.** `sanity_prescription.py`
extrait `_formater_prescription` par AST et l'exécute seule. C'est assez pour un
formateur, et c'est aveugle à la seule chose qui comptait ici : les seuils
n'avaient AUCUNE fonction à extraire. Le rendu promettait de les afficher — « les
taire obligerait le modèle à les chercher ailleurs, ou à les inventer » — et
aucune ligne ne le faisait. Un banc qui interroge la logique ne voit pas une
logique absente ; relevé le 02/09/2026 par un contrôle extérieur au chantier.

Ce banc emprunte donc le trajet entier : un `php -S` sert la vraie route `/find`
sur une copie des vraies données, le vrai serveur est lancé en stdio et
interrogé en JSON-RPC. Rien n'est recopié — une copie ne prouve que la copie.

Les cas à douze articles et à liste d'objets sont FABRIQUÉS dans la copie : ce
banc ne doit pas dépendre du contenu de `situations.json`, qui bouge à chaque
curation.

    python3 tests/test_mcp_situation.py
"""

import copy
import json
import os
import pathlib
import shutil
import socket
import subprocess
import sys
import tempfile
import time

RACINE = pathlib.Path(__file__).resolve().parent.parent.parent   # self-right/
MCP = RACINE / "selfjustice" / "mcp"
API = RACINE / "selfact" / "api"

# Le rendu s'arrête à dix articles ; le banc en sert douze pour que la coupe ait
# lieu. La valeur se lit dans le serveur plutôt que de se répéter ici : deux
# chiffres qui doivent rester égaux finissent toujours par diverger.
def plafond_du_serveur() -> int:
    for ligne in (MCP / "selfright_mcp" / "server.py").read_text().splitlines():
        if ligne.startswith("PLAFOND_ARTICLES_FICHE"):
            return int(ligne.split("=")[1].strip())
    print("✗ PLAFOND_ARTICLES_FICHE absent de server.py", file=sys.stderr)
    raise SystemExit(2)


ROUTEUR = """<?php
// nginx sert /find sans suffixe ; php -S ne le fait pas seul.
$chemin = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$cible = __DIR__ . '/api' . (str_ends_with($chemin, '.php') ? $chemin : $chemin . '.php');
if (is_file($cible)) { $_SERVER['SCRIPT_FILENAME'] = $cible; require $cible; return true; }
http_response_code(404); echo '{"ok":false,"error":"route inconnue"}';
"""


def batir_bac(bac: pathlib.Path, plafond: int) -> None:
    shutil.copytree(API, bac / "api")
    (bac / "routeur.php").write_text(ROUTEUR)
    fiches = bac / "api" / "data" / "situations.json"
    donnees = json.loads(fiches.read_text())
    situations = donnees["situations"]
    modele = copy.deepcopy(next(iter(situations.values())))

    trop = copy.deepcopy(modele)
    trop["label"] = "Cas de banc — plus d'articles que le rendu n'en montre"
    trop["art_applicable"] = [
        {"reference": f"art. {n} du code de banc", "num": str(n), "code": "banc"}
        for n in range(1, plafond + 3)
    ]
    situations["banc_articles_en_trop"] = trop

    liste = copy.deepcopy(modele)
    liste["label"] = "Cas de banc — articles en liste d'objets"
    liste["art_applicable"] = [
        {"reference": "art. 970 du code civil", "num": "970", "code": "civil"}
    ]
    liste.pop("thresholds", None)
    situations["banc_articles_en_liste"] = liste

    fiches.write_text(json.dumps(donnees, ensure_ascii=False, indent=2))


def port_libre() -> int:
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    port = s.getsockname()[1]
    s.close()
    return port


class Client:
    """Le vrai serveur, lancé en stdio, interrogé en JSON-RPC."""

    def __init__(self, act_url: str):
        # ⚠️ PYTHONPATH vise le DÉPÔT. Un venv installé porte une COPIE du
        # serveur, figée à sa date d'installation : sans cette ligne, le banc
        # éprouverait un autre code que celui qu'on relit.
        env = dict(os.environ,
                   PYTHONPATH=str(MCP),
                   SELFRIGHT_API_URL="http://127.0.0.1:1/api",
                   SELFRIGHT_ACT_URL=act_url)
        self.p = subprocess.Popen(
            [sys.executable, "-m", "selfright_mcp.server"],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            env=env, text=True, bufsize=1)
        self.n = 0
        self._demander("initialize", {
            "protocolVersion": "2025-06-18", "capabilities": {},
            "clientInfo": {"name": "banc", "version": "0"}})
        self._notifier("notifications/initialized")

    def _ecrire(self, msg: dict) -> None:
        self.p.stdin.write(json.dumps(msg) + "\n")
        self.p.stdin.flush()

    def _notifier(self, methode: str) -> None:
        self._ecrire({"jsonrpc": "2.0", "method": methode})

    def _demander(self, methode: str, params: dict) -> dict:
        self.n += 1
        self._ecrire({"jsonrpc": "2.0", "id": self.n, "method": methode, "params": params})
        while True:
            ligne = self.p.stdout.readline()
            if not ligne:
                print("✗ le serveur MCP s'est tu :", file=sys.stderr)
                print(self.p.stderr.read(), file=sys.stderr)
                raise SystemExit(2)
            try:
                rep = json.loads(ligne)
            except json.JSONDecodeError:
                continue
            if rep.get("id") == self.n:
                if "error" in rep:
                    print(f"✗ erreur MCP : {rep['error']}", file=sys.stderr)
                    raise SystemExit(2)
                return rep["result"]

    def situation(self, slug: str) -> str:
        r = self._demander("tools/call", {
            "name": "actes_pour_situation", "arguments": {"situation": slug}})
        return "".join(c.get("text", "") for c in r["content"])

    def fermer(self) -> None:
        self.p.terminate()
        self.p.wait(timeout=5)


ECHECS = 0


def verdict(ok: bool, titre: str, sortie: str = "") -> None:
    global ECHECS
    if ok:
        print(f"  ✓ {titre}")
        return
    ECHECS += 1
    print(f"  ✗ {titre}")
    if sortie:
        print("      " + sortie.replace("\n", "\n      "))


def main() -> int:
    for outil in ("php",):
        if shutil.which(outil) is None:
            print(f"✗ {outil} est nécessaire à ce banc et n'est pas là", file=sys.stderr)
            return 2
    try:
        import mcp  # noqa: F401
    except ImportError:
        print("✗ le paquet `mcp` est nécessaire à ce banc : pip install mcp httpx",
              file=sys.stderr)
        return 2

    plafond = plafond_du_serveur()
    bac = pathlib.Path(tempfile.mkdtemp(prefix="banc-mcp-"))
    php = None
    client = None
    try:
        batir_bac(bac, plafond)
        port = port_libre()
        php = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", str(bac / "routeur.php")],
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, cwd=bac)
        time.sleep(1.2)
        client = Client(f"http://127.0.0.1:{port}")

        print("▸ Les seuils chiffrés arrivent jusqu'au modèle")
        # 🔑 Le cas qui a manqué. `impaye_commercial_loyer` porte son plafond de
        # compétence depuis l'origine ; le rendu ne l'a jamais affiché. Un
        # plafond tu, c'est la juridiction choisie de travers.
        s = client.situation("impaye_commercial_loyer")
        verdict("Seuils applicables" in s, "le bloc des seuils existe", s)
        # ⚠️ Chercher « 5000 » n'importe où ne prouve rien : le libellé d'un
        # acte porte déjà « obligatoire < 5000 € ». Le contrôle restait vert
        # avec les seuils retirés. Le montant se cherche DANS son bloc.
        verdict("montant max conciliateur : 5000" in s, "le montant est rendu", s)
        verdict("art applicable : art. 750-1 CPC" in s, "son fondement est rendu", s)
        verdict("{'" not in s and "': " not in s, "aucune accolade Python n'a fui", s)

        print()
        print("▸ Une situation sans seuil ne gagne pas un bloc vide")
        s = client.situation("voisinage_nuisances")
        verdict("Seuils applicables" not in s, "aucun bloc de seuils", s)
        verdict("acte(s)" in s, "la réponse tient debout", s)

        print()
        print("▸ Les articles en liste d'objets se lisent")
        s = client.situation("banc_articles_en_liste")
        verdict("art. 970 du code civil" in s, "la référence sort en clair", s)
        verdict("{'" not in s, "pas de repr Python", s)

        print()
        print("▸ Un rendu tronqué se DIT")
        # 🔑 Le silence serait pire que la coupe : dix articles rendus sans
        # mention d'un reste font conclure que la fiche en cite dix.
        s = client.situation("banc_articles_en_trop")
        verdict(f"art. {plafond} du code de banc" in s, f"les {plafond} premiers sont là", s)
        verdict(f"art. {plafond + 1} du code de banc" not in s, "le suivant est bien coupé")
        verdict("et 2 autre(s)" in s, "le reste est compté", s)
        verdict(f"{plafond + 2} au total" in s, "et le total est dit", s)
    finally:
        if client:
            client.fermer()
        if php:
            php.terminate()
        shutil.rmtree(bac, ignore_errors=True)

    print()
    if ECHECS:
        print(f"✗ {ECHECS} contrôle(s) en échec")
        return 1
    print("✓ le rendu d'une situation dit ses seuils, ses articles, et ce qu'il coupe")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
