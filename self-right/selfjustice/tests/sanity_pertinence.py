#!/usr/bin/env python3
"""Garde-fou — ce que le serveur MCP compte comme « mot de la question ».

🔑 Le score de pertinence rendu à l'appelant vaut le nombre de mots de sa
question retrouvés dans les résultats, sur le nombre de mots posés. Tant que la
liste des mots-outils tenait aux seuls articles et prépositions, le
dénominateur comptait tout le vocabulaire de la question — « mon », « mes »,
« puis », « quels », « combien » — qu'aucun arrêt ne surligne jamais.

Une question de droit posée en langue courante en est à moitié faite. Mesuré le
21/08/2026 sur douze questions réelles interrogeant l'index de production,
plusieurs déclenchaient l'alerte au même niveau que des chaînes de mots sans
rapport entre eux : « puis-je contester une amende de stationnement reçue par
erreur » obtenait 28 %, « est-ce que ma banane peut saxophoner pendant un
tracteur nuage » 25 %. Aucun seuil ne les sépare — la jurisprudence n'emploie
simplement pas les mots de celui qui la cherche.

L'alerte a donc cessé d'affirmer « hors sujet », qui ne s'infère pas d'un
recouvrement lexical, pour dire deux faits : la proportion de termes repris, et
les termes qu'aucune décision ne surligne. Ce module éprouve la partie qui se
mesure sans réseau — le tri des mots, le seuil, et le rapprochement des termes
avec les surlignages. La présence d'un terme dans l'index, elle, demande une
requête et se mesure en recette.

    python3 tests/sanity_pertinence.py
"""

import ast
import pathlib
import re
import sys
import unicodedata

SERVEUR = pathlib.Path(__file__).resolve().parent.parent / "mcp" / "selfright_mcp" / "server.py"

# Charger les quatre définitions utiles sans importer le module : `server.py`
# dépend du paquet `mcp`, absent de l'environnement de CI.
VOULUES = {
    "_MOTS_OUTILS", "_sans_accents", "_mots_utiles", "_sous_la_moitie",
    "_termes_repris", "_termes_jamais_repris", "_couverture",
}


def charger() -> dict:
    arbre = ast.parse(SERVEUR.read_text())
    gardes = []
    for noeud in arbre.body:
        nom = (
            noeud.name if isinstance(noeud, ast.FunctionDef)
            else noeud.targets[0].id if isinstance(noeud, ast.Assign)
            and isinstance(noeud.targets[0], ast.Name) else None
        )
        if nom in VOULUES:
            gardes.append(noeud)
    trouves = {
        n.name if isinstance(n, ast.FunctionDef) else n.targets[0].id for n in gardes
    }
    if trouves != VOULUES:
        print(f"✗ manquant dans server.py : {sorted(VOULUES - trouves)}", file=sys.stderr)
        raise SystemExit(2)
    espace: dict = {"re": re, "unicodedata": unicodedata, "Any": object}
    exec(compile(ast.Module(body=gardes, type_ignores=[]), str(SERVEUR), "exec"), espace)
    return espace


def main() -> int:
    ns = charger()
    mots_utiles, sous_la_moitie = ns["_mots_utiles"], ns["_sous_la_moitie"]
    echecs = 0

    def verdict(ok: bool, libelle: str) -> None:
        nonlocal echecs
        if not ok:
            echecs += 1
        print(f"  {'✓' if ok else '✗'} {libelle}")

    print("▸ Les mots comptés sont ceux qui portent le sujet")
    cas = [
        # (question, mots qui doivent rester, mots qui doivent disparaître)
        ("quels sont mes droits si mon propriétaire refuse de rendre ma caution",
         ["droits", "propriétaire", "refuse", "rendre", "caution"],
         ["quels", "sont", "mes", "mon"]),
        ("est-ce que mon employeur peut me licencier pendant un arrêt maladie",
         ["employeur", "licencier", "arrêt", "maladie"],
         ["que", "mon", "peut", "pendant"]),
        ("combien de temps ai-je pour contester un licenciement aux prud'hommes",
         ["temps", "contester", "licenciement", "prud", "hommes"],
         ["combien", "pour", "aux"]),
        # Les accents ne doivent pas faire échapper un mot à la liste qui le nomme.
        ("même cause réelle été", ["cause", "réelle"], ["même", "été"]),
        # Rien de substantiel ne doit tomber avec le vocabulaire de question.
        ("harcèlement moral", ["harcèlement", "moral"], []),
        ("clause de non-concurrence", ["clause", "non", "concurrence"], ["de"]),
    ]
    for question, attendus, exclus in cas:
        obtenus = mots_utiles(question)
        manquants = [m for m in attendus if m not in obtenus]
        restants = [m for m in exclus if m in obtenus]
        verdict(
            not manquants and not restants,
            f"« {question[:52]}{'…' if len(question) > 52 else ''} » → {len(obtenus)} mot(s)"
            + (f" — manque {manquants}" if manquants else "")
            + (f" — reste {restants}" if restants else ""),
        )

    print("\n▸ Le seuil est entier, et l'égalité épargnée")
    # Un seuil en fraction change de sévérité avec la longueur de la question :
    # 2/5 vaut 0,400 et n'est pas inférieur à 0,4, quand 2/6 l'est. Le critère
    # doit couper à la moitié, quel que soit le dénominateur.
    for trouves, total, attendu in [
        (1, 3, True), (2, 5, True), (2, 6, True), (0, 4, True),
        (2, 4, False), (3, 6, False), (3, 5, False), (4, 4, False),
    ]:
        verdict(
            sous_la_moitie(trouves, total) is attendu,
            f"{trouves}/{total} → {'marqué' if attendu else 'épargné'}",
        )

    print("\n▸ Ce que la liste entière laisse de côté")
    # 🔑 C'est le collectif qui compte : un terme repris par un SEUL arrêt de la
    # liste est repris. Sans ce pli, la moyenne — basse dès qu'aucune décision
    # ne couvre tout — ferait nommer à l'utilisateur des termes pourtant
    # présents, et l'alerte perdrait le seul contenu qui l'intéresse.
    jamais, repris, couverture = (
        ns["_termes_jamais_repris"], ns["_termes_repris"], ns["_couverture"]
    )

    def arret(*mots):
        """Un résultat de contrefaçon, ne surlignant que ces mots-là."""
        return {"highlights": {"text": ["".join(f"<em>{m}</em> " for m in mots)]}}

    q = "harcèlement moral au travail"   # → harcèlement, moral, travail
    cas = [
        ([arret("harcelement"), arret("moral"), arret("travail")], [],
         "trois arrêts, un terme chacun → la liste ne laisse rien de côté"),
        ([arret("harcelement"), arret("harcelement")], ["moral", "travail"],
         "un seul terme repris partout → les deux autres sont nommés"),
        ([arret("harcelement", "moral", "travail")], [],
         "un arrêt qui reprend tout → rien à signaler"),
        # ⚠️ Sans résultat, il n'y a rien à mesurer : nommer les trois termes
        # laisserait croire qu'on les a cherchés dans quelque chose. L'outil ne
        # passe d'ailleurs jamais ici — une liste vide sort plus tôt, avec son
        # propre message.
        ([], [], "aucun arrêt → rien d'affirmé, il n'y avait rien à mesurer"),
        ([{"highlights": {}}], [],
         "un arrêt sans surlignage → non mesurable, donc rien d'affirmé"),
    ]
    for resultats, attendu, libelle in cas:
        obtenu = jamais(q, resultats)
        verdict(obtenu == attendu, f"{libelle} — {obtenu}")

    print("\n▸ Les accents ne font pas rater un terme")
    # L'amont accepte la requête sans accents et surligne le texte avec : sans
    # le pli sur les préfixes, le terme le mieux ciblé compterait comme absent.
    for surligne, attendu_vide in [("harcèlement", True), ("harcelement", True),
                                   ("harcelant", True), ("licenciement", False)]:
        obtenu = repris("harcèlement", arret(surligne))
        trouve = bool(obtenu and obtenu[0])
        verdict(
            trouve is attendu_vide,
            f"« harcèlement » face à « {surligne} » → "
            + ("reconnu" if trouve else "non reconnu"),
        )
    verdict(couverture("harcèlement moral", arret("harcelement")) == (1, 2),
            "la couverture dérive du même rapprochement : 1/2")

    print()
    if echecs:
        print(f"✗ {echecs} contrôle(s) en échec")
        return 1
    print("✓ tous les contrôles passent")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
