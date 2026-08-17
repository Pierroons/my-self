#!/usr/bin/env python3
"""Contrôle de l'endpoint /api/jurisprudence.

Le contrôle positif n'est pas décoratif : un `count = 0` ne distingue pas
« cette décision n'existe pas » d'une requête mal formée. Sans un jeu de
références dont on sait qu'elles existent, l'endpoint peut nier en silence et
personne ne le verra — c'est le mode de défaillance que ce module combat.

    SELFJUSTICE_JURIS_DB=data/judilibre_index.sqlite \
        php -S 127.0.0.1:8799 -t api api/api.php &
    python3 tests/test_jurisprudence.py [http://127.0.0.1:8799]

L'argument est l'hôte seul : « /api » est ajouté ici, le passer deux fois rend
du HTML d'erreur que le décodage JSON signale d'une pile d'appels illisible.

⚠️ Visé sur l'instance publique, ce contrôle fait bannir celui qui le lance :
ses trente appels enchaînés sur des routes de vérification déclenchent le
scénario `http-probing` de CrowdSec. Le lancer depuis le serveur lui-même, ou
lever la décision ensuite (`cscli decisions delete --id …`). Aucune adresse
publique n'a sa place dans l'allowlist pour contourner ça — elle changerait de
main un jour, le contournement resterait.
"""

import json
import pathlib
import sys
import urllib.parse
import urllib.request

BASE = (sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8799").rstrip("/")
JEU = pathlib.Path(__file__).with_name("judilibre-jeu-test.json")


def appeler(chemin: str) -> tuple[int, dict]:
    requete = urllib.request.Request(BASE + chemin)
    try:
        with urllib.request.urlopen(requete, timeout=20) as reponse:
            return reponse.status, json.loads(reponse.read())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read())


def positif() -> list[str]:
    """Les 30 références connues doivent toutes ressortir, avec leur juridiction."""
    echecs = []
    for cas in json.loads(JEU.read_text()):
        ref = urllib.parse.quote(cas["number"], safe="")
        _, corps = appeler(f"/api/jurisprudence/verifier/{ref}?jurisdiction={cas['jurisdiction']}")
        if corps.get("etat") != "trouvee":
            echecs.append(f"{cas['number']} → {corps.get('etat')} (attendu trouvee)")
            continue
        if cas["id"] not in [d["id"] for d in corps["decisions"]]:
            echecs.append(f"{cas['number']} trouvée mais sans la décision {cas['id']}")
    return echecs


def negatif() -> list[str]:
    """Les trois états doivent différer, et l'absence porter ses réserves."""
    echecs = []

    _, absente = appeler("/api/jurisprudence/verifier/99-99.999")
    if absente.get("etat") != "absente":
        echecs.append(f"référence fantaisiste → {absente.get('etat')} (attendu absente)")
    elif not absente.get("reserve"):
        echecs.append("une absence sans réserve : elle se lira « n'existe pas »")

    statut, hors = appeler("/api/jurisprudence/verifier/470123?jurisdiction=ce")
    if hors.get("etat") != "indeterminee" or statut != 503:
        echecs.append(f"juridiction hors index → {statut} {hors.get('etat')} (attendu 503 indeterminee)")

    statut, numero = appeler("/api/jurisprudence/search?q=" + urllib.parse.quote("25/01234"))
    if statut != 400:
        echecs.append("un numéro passé en recherche plein texte n'est pas refusé")

    return echecs


if __name__ == "__main__":
    echecs = positif() + negatif()
    total = len(json.loads(JEU.read_text()))
    if echecs:
        print(f"ÉCHEC — {len(echecs)} problème(s) sur {total} références + 3 contrôles négatifs")
        for e in echecs:
            print("  ·", e)
        sys.exit(1)
    print(f"OK — {total}/{total} références retrouvées, 3 contrôles négatifs passés")
