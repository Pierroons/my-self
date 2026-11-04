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

    # 🔑 La juridiction hors index ne s'écrit pas en dur. « ce » servait
    # d'exemple ; le jour où le Conseil d'État entre dans l'index, ce contrôle
    # rougirait pour une couverture ÉLARGIE — un garde-fou ne défend pas l'état
    # d'hier. On demande donc à l'instance ce qu'elle couvre, et on interroge un
    # code qui n'y est pas.
    #
    # Les candidats sont des juridictions que l'API connaît et que l'amont ne
    # sert pas. `tj` et `tcom` n'en sont pas : l'amont les vérifie, et leur
    # absence de l'index passe par un autre chemin, éprouvé juste après. Quand
    # l'instance couvre déjà toutes les autres, ce chemin n'y est pas atteignable :
    # le contrôle le dit au lieu d'en mesurer un voisin. `test_sonde_amont.sh`
    # l'éprouve sur une base qui ne les porte pas toutes.
    _, etat = appeler("/api/status")
    couverts = set((etat.get("jurisprudence") or {}).get("couverture") or {})
    absente = next((j for j in ("ce", "caa", "ta", "tc", "cdbf") if j not in couverts), None)
    if absente is None:
        print("  · juridiction hors index : non atteignable sur cette instance, qui les "
              "couvre toutes — éprouvée par test_sonde_amont.sh")
    else:
        statut, hors = appeler(f"/api/jurisprudence/verifier/470123?jurisdiction={absente}")
        if hors.get("etat") != "indeterminee" or statut != 503:
            echecs.append(f"juridiction hors index « {absente} » → {statut} "
                          f"{hors.get('etat')} (attendu 503 indeterminee)")

    # Un tribunal judiciaire sans date : l'index ne le porte pas et l'amont n'est
    # pas interrogé, donc rien n'a été vérifié.
    statut, tj = appeler("/api/jurisprudence/verifier/21%2F04567?jurisdiction=tj")
    if tj.get("etat") != "indeterminee" or statut != 503:
        echecs.append(f"« tj » sans date → {statut} {tj.get('etat')} (attendu 503 indeterminee)")

    statut, numero = appeler("/api/jurisprudence/search?q=" + urllib.parse.quote("25/01234"))
    if statut != 400:
        echecs.append("un numéro passé en recherche plein texte n'est pas refusé")

    return echecs


if __name__ == "__main__":
    echecs = positif() + negatif()
    total = len(json.loads(JEU.read_text()))
    if echecs:
        print(f"ÉCHEC — {len(echecs)} problème(s) sur {total} références + les contrôles négatifs")
        for e in echecs:
            print("  ·", e)
        sys.exit(1)
    print(f"OK — {total}/{total} références retrouvées, contrôles négatifs passés")
