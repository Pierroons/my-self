#!/usr/bin/env python3
"""Contrôle de l'endpoint /api/eu — le contenu, pas seulement la présence.

🔑 Ce module existe à cause d'une panne précise. Pendant des mois, la base a
servi les protocoles annexés aux traités à la place des traités eux-mêmes :
l'article 50 TUE rendait les statuts de la BCE, l'article 45 TFUE la présidence
de la BCE, et « libre circulation des travailleurs » ne rendait aucun résultat.
Le tout avec un bandeau « base à jour », des comptes d'articles crédibles et un
chemin nominal qui fonctionnait — TFUE 101, CEDH 8 et RGPD 17 étaient corrects.

Aucun contrôle de fraîcheur ni de volumétrie ne pouvait le voir : ils datent la
construction et comptent les lignes, jamais ce que les lignes contiennent. Il a
fallu lire un article dont on connaissait le texte.

D'où ce contrôle : chaque article témoin doit porter les mots que son sujet
impose. Les mots choisis sont ceux du texte officiel, assez précis pour qu'un
autre article ne les contienne pas par hasard.

    python3 tests/test_conventionnalite.py [https://instance.example]
"""

import json
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = (sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8799").rstrip("/")

# Une instance locale n'a pas de limite de débit ; une instance publique en a
# une. La pause ne s'applique donc qu'aux appels distants.
PAUSE = 0 if BASE.startswith("http://127.0.0.1") else 0.7

# L'argument est l'hôte seul : « /api » est ajouté ici. Le passer deux fois rend
# du HTML d'erreur, que le décodage JSON signale d'une pile illisible.

# (source, article) -> fragment que le texte doit contenir.
# Choisis dans les articles les plus invoqués de chaque texte, et sur les deux
# traités qui avaient été écrasés.
TEMOINS = [
    ("TUE", "2", "dignité humaine"),
    ("TUE", "6", "Charte des droits fondamentaux"),
    ("TUE", "7", "violation grave"),
    ("TUE", "50", "peut décider, conformément à ses règles"),
    ("TFUE", "18", "domaine d'application des traités"),
    ("TFUE", "20", "citoyenneté de l'Union"),
    ("TFUE", "34", "restrictions quantitatives"),
    ("TFUE", "45", "libre circulation des travailleurs"),
    ("TFUE", "49", "liberté d'établissement"),
    ("TFUE", "56", "libre prestation des services"),
    ("TFUE", "101", "incompatibles avec le marché intérieur"),
    ("TFUE", "267", "titre préjudiciel"),
    ("CHARTE_UE", "8", "données à caractère personnel"),
    ("CHARTE_UE", "47", "recours effectif"),
    ("CEDH", "6", "délai raisonnable"),
    ("CEDH", "8", "vie privée et familiale"),
    ("RGPD", "17", "effacement"),
    ("AI_ACT", "50", "transparence"),
]

# Nombre d'articles attendu par source. Un compte supérieur est le symptôme
# exact de la collision qui a corrompu les traités : les protocoles s'ajoutent
# au lieu de rester dehors.
VOLUMES = {
    "TUE": 55, "TFUE": 358, "CHARTE_UE": 54,
    "RGPD": 99, "AI_ACT": 113, "CEDH": 114,
}


def appeler(chemin: str) -> tuple[int, dict]:
    """Un appel, avec la patience qu'impose la limite de débit du serveur.

    ⚠️ L'instance protège son API par un `limit_req burst=10` : une vingtaine
    d'appels enchaînés se voit répondre 503. Sans la pause et la reprise
    ci-dessous, ce contrôle rendait « article absent » sur des articles
    parfaitement présents — un faux négatif produit par le testeur lui-même,
    exactement le genre de conclusion que ce module existe pour empêcher.
    """
    for tentative in range(4):
        if tentative or PAUSE:
            time.sleep(PAUSE + tentative)
        requete = urllib.request.Request(BASE + chemin)
        try:
            with urllib.request.urlopen(requete, timeout=20) as reponse:
                return reponse.status, json.loads(reponse.read())
        except urllib.error.HTTPError as e:
            if e.code == 503:          # limite de débit : réessayer plus lentement
                continue
            try:
                return e.code, json.loads(e.read())
            except ValueError:
                return e.code, {}
    return 503, {"error": "limite de débit du serveur, après 4 tentatives"}


def contenu() -> list[str]:
    """Chaque article témoin doit porter les mots de son sujet."""
    echecs = []
    for source, num, fragment in TEMOINS:
        _, corps = appeler(f"/api/eu/article/{source}/{urllib.parse.quote(num)}")
        texte = corps.get("texte") or ""
        if not texte:
            echecs.append(f"{source} art. {num} : absent ({corps.get('error', 'sans texte')})")
        elif fragment.lower() not in texte.lower():
            echecs.append(
                f"{source} art. {num} : ne contient pas « {fragment} » — "
                f"début : « {texte[:60].strip()}… »"
            )
    return echecs


def volumetrie() -> list[str]:
    """Un écart de volume signale que le parseur a ramassé autre chose."""
    _, statut = appeler("/api/status")
    sources = (statut.get("eu") or {}).get("sources") or {}
    echecs = []
    for source, attendu in VOLUMES.items():
        obtenu = sources.get(source)
        if obtenu is None:
            echecs.append(f"{source} : absente du statut")
        elif abs(obtenu - attendu) > max(5, attendu // 20):
            echecs.append(
                f"{source} : {obtenu} articles pour ~{attendu} attendus — "
                "un excédent signale des protocoles collectés avec le texte"
            )
    return echecs


def negatif() -> list[str]:
    """Ce que le serveur doit refuser, et la recherche retrouver."""
    echecs = []

    statut, inconnue = appeler("/api/eu/article/CJUE/1")
    if statut < 400:
        echecs.append(f"source inconnue acceptée (HTTP {statut})")

    _, absent = appeler("/api/eu/article/TUE/9999")
    if absent.get("texte"):
        echecs.append("un numéro fantaisiste rend un texte")

    # La preuve par la négative de la panne : cette recherche ne rendait rien.
    _, r = appeler("/api/eu/search?source=TFUE&q="
                   + urllib.parse.quote("libre circulation des travailleurs"))
    if not (r.get("results") or []):
        echecs.append("« libre circulation des travailleurs » introuvable dans le TFUE")

    return echecs


if __name__ == "__main__":
    echecs = contenu() + volumetrie() + negatif()
    if echecs:
        print(f"ÉCHEC — {len(echecs)} problème(s)")
        for e in echecs:
            print("  ·", e)
        sys.exit(1)
    print(f"OK — {len(TEMOINS)} articles témoins conformes, "
          f"{len(VOLUMES)} volumétries justes, 3 contrôles négatifs passés")
