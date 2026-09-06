#!/usr/bin/env python3
"""Assemble l'atelier : la page qui fabrique un coffre, et qui l'ouvre.

L'atelier n'est pas écrit à la main. Il est composé de trois choses qui vivent
ailleurs, et qui restent maîtresses chez elles :

  - le NOYAU du déchiffreur, extrait de `pli/selfvault.html` entre ses marqueurs ;
  - la LISTE DE MOTS, obtenue par `selfvault._liste_mots()`, donc la copie
    normative du dépôt, avec ses contrôles d'entrées vides et de doublons ;
  - le MODÈLE de directives, `pli/modele-directives.txt`.

🔑 Le noyau n'est pas recopié, il est extrait. Deux exemplaires du code qui
déchiffre finiraient par ne plus dire la même chose, et le coffre ne s'ouvrirait
plus qu'avec le programme qui l'a écrit. Le banc vérifie que l'atelier assemblé
porte du noyau une copie au caractère près.

  python3 outils/faire_atelier.py        # écrit sortie/selfvault-atelier.html

Aucune dépendance : ni chiffrement, ni image. L'atelier produit n'est JAMAIS
imprimé dans le pli — le pli ne porte que ce qui ouvre.
"""
import html, json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from selfvault import BITS_MIN, ITER, MOTS_DEFAUT, MOTS_MAX, _liste_mots

SD = os.path.dirname(os.path.abspath(__file__))
RACINE = os.path.dirname(SD)
PLI = os.path.join(RACINE, "pli")
SORTIE = os.path.join(RACINE, "sortie")

DEBUT = "/* === NOYAU SELFVAULT3 — début"
FIN = "=== NOYAU SELFVAULT3 — fin "


def noyau(chemin):
    """Le bloc de `pli/selfvault.html` qui lit, vérifie et ouvre un coffre.

    Les deux commentaires qui l'encadrent partent avec lui : ils disent pourquoi
    ce bloc ne touche pas au DOM, et cette raison voyage mieux collée au code
    qu'expliquée dans un fichier que personne n'ouvrira.
    """
    page = open(chemin, encoding="utf-8").read()
    d = page.find(DEBUT)
    f = page.find(FIN)
    if d < 0 or f < 0:
        raise SystemExit("%s ne porte pas ses deux marqueurs de noyau" % chemin)
    fin = page.find("*/", f)
    if fin < 0:
        raise SystemExit("le marqueur de fin de noyau n'est pas dans un commentaire fermé")
    bloc = page[d:fin + 2]
    # Le contrôle porte sur le CODE, pas sur les commentaires : ceux-ci parlent
    # justement du DOM pour dire qu'on n'y touche pas.
    code = re.sub(r"/\*.*?\*/", "", bloc, flags=re.S)
    code = re.sub(r"^[ \t]*//.*$", "", code, flags=re.M)
    touche = [m for m in ("document", "$(") if m in code]
    if touche:
        raise SystemExit("le noyau touche au DOM (%s) : il ne serait pas réemployable tel quel"
                         % ", ".join(touche))
    return bloc


def main(argv=()):
    """`faire_atelier.py [source.html] [cible.html]`.

    Les deux chemins sont facultatifs et servent au banc : il assemble depuis une
    copie doctorée pour vérifier qu'un jeton non substitué fait bien échouer le
    rendu. Sans eux, la source et la cible sont celles du module.
    """
    source = argv[0] if len(argv) > 0 else os.path.join(PLI, "atelier.html")
    gabarit = open(source, encoding="utf-8").read()

    # Une seule balise `<script>` sans attribut : c'est ce dont dépend le pilote
    # du banc, qui découpe la page par `indexOf('<script>')`.
    if gabarit.count("<script") != 1 or "<script>" not in gabarit:
        raise SystemExit("%s doit porter exactement une balise <script> sans attribut" % source)

    mots = _liste_mots()
    jetons = {
        "{{NOYAU}}": noyau(os.path.join(PLI, "selfvault.html")),
        "{{MOTS}}": json.dumps(mots, ensure_ascii=False),
        "{{MODELE}}": html.escape(open(os.path.join(PLI, "modele-directives.txt"),
                                       encoding="utf-8").read().rstrip("\n")),
        "{{AVERTISSEMENT}}": "<!-- Page ASSEMBLÉE par outils/faire_atelier.py — ne pas éditer\n     ici. Les sources sont pli/atelier.html, pli/selfvault.html (le noyau),\n     pli/modele-directives.txt et la liste de mots de SelfRecover. -->",
        "{{ITER}}": str(ITER),
        "{{BITS_MIN}}": "%g" % BITS_MIN,
        "{{MOTS_DEFAUT}}": str(MOTS_DEFAUT),
        "{{MOTS_MAX}}": str(MOTS_MAX),
    }
    rendu = gabarit
    for cle, valeur in jetons.items():
        rendu = rendu.replace(cle, valeur)

    # Un jeton qui survit à la substitution fait échouer l'assemblage. Le motif
    # accepte les minuscules et le tiret : un jeton mal orthographié doit être
    # nommé ici, pas découvert dans la page par la personne qui s'en sert.
    restants = re.findall(r"\{\{[A-Za-z0-9_-]+\}\}", rendu)
    if restants:
        raise SystemExit("jeton non substitué : " + ", ".join(sorted(set(restants))))

    cible = argv[1] if len(argv) > 1 else os.path.join(SORTIE, "selfvault-atelier.html")
    os.makedirs(os.path.dirname(os.path.abspath(cible)), exist_ok=True)
    open(cible, "w", encoding="utf-8").write(rendu)

    print("atelier : %s" % cible)
    print("  %d octets — dont %d mots et un noyau de %d octets"
          % (len(rendu.encode("utf-8")), len(mots), len(jetons["{{NOYAU}}"].encode("utf-8"))))
    print("  jamais imprimé dans le pli")


if __name__ == "__main__":
    main(sys.argv[1:])
