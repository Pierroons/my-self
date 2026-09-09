#!/usr/bin/env python3
"""Garde-fou — les articles cités par les directives de domaine existent vraiment.

🔑 Une directive qui nomme un article fait plus que l'évoquer : elle envoie l'IA
le chercher. Un numéro faux ne produit pas une erreur, il produit une analyse qui
s'appuie sur du vide — et le module existe précisément pour empêcher ça.

Deux sections nomment des articles en toutes lettres : « Droit du logement » et
« Droit de la famille ». Chacune est marquée `<code>art. N</code>`, et le jeu
`legi-jeu-domaines.json` porte l'état mesuré de chacun contre la base de
production au jour de son extraction.

Ce que le contrôle établit, hors ligne :

1. **L'ensemble des articles cités est exactement celui du jeu.** Ajouter une
   citation sans l'éprouver fait rougir ; laisser au jeu une entrée que la page
   ne cite plus aussi. C'est le lien qui manque le plus souvent : une liste de
   référence tenue à côté d'un texte finit par décrire un autre texte.
2. **Le jeu ne porte que des articles en vigueur.** Une entrée `ABROGE` qui y
   entrerait serait un défaut, pas un cas de test.

⚠️ Ce que la version hors ligne **n'établit pas** : qu'ils soient encore en
vigueur aujourd'hui. Un jeu figé vieillit. Poser `SELFJUSTICE_API_LIVE` sur une
instance rejoue chaque article contre elle et compare état, code porteur et
amorce de texte — c'est ce mode qui attrape l'abrogation, et le recyclage d'un
numéro sous un texte différent, que l'état seul ne signale jamais.

    python3 tests/sanity_articles_domaines.py
    SELFJUSTICE_API_LIVE=https://justice.example.org \\
        python3 tests/sanity_articles_domaines.py
"""

import json
import os
import pathlib
import re
import sys
import time
import urllib.error
import urllib.request

RACINE = pathlib.Path(__file__).resolve().parent.parent
PAGE = RACINE / "site" / "index.php"
JEU = RACINE / "tests" / "legi-jeu-domaines.json"
AGENT = "SelfJustice-tests/1.0 (controle des articles cites)"
# L'instance limite le débit : vingt-trois appels d'affilée déclenchent des 503.
# Ce contrôle mesure des articles, pas la résistance du serveur — il prend son temps.
PAUSE = float(os.environ.get("SELFJUSTICE_API_PAUSE", "0.5"))


def cites_par_la_page() -> set:
    """Les `<code>art. N</code>` des deux sections de domaine, et d'elles seules."""
    src = PAGE.read_text(encoding="utf-8")
    m = re.search(r'<section id="domaine-logement">.*?<section id="domaine-famille">.*?</section>',
                  src, re.S)
    if not m:
        print("✗ les sections de domaine sont introuvables dans la page — "
              "le contrôle ne peut rien juger", file=sys.stderr)
        sys.exit(2)
    return set(re.findall(r"<code>art\. ([\w\-]+)</code>", m.group(0)))


def interroge(base: str, ref: str, code: str) -> dict:
    """Un article, avec un réessai — un refus de débit n'est pas un article manquant."""
    url = f"{base.rstrip('/')}/api/legi/article/{ref}?code={code}"
    req = urllib.request.Request(url, headers={"User-Agent": AGENT})
    for essai in (1, 2):
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                return json.load(r)
        except urllib.error.HTTPError as e:
            if e.code in (429, 503) and essai == 1:
                time.sleep(5)
                continue
            raise


def main() -> int:
    jeu = json.loads(JEU.read_text(encoding="utf-8"))
    articles = jeu["articles"]
    attendus = {a["ref"] for a in articles}
    cites = cites_par_la_page()

    defauts = []

    for ref in sorted(cites - attendus):
        defauts.append(f"la page cite art. {ref}, absent du jeu — "
                       f"il n'a donc jamais été mesuré")
    for ref in sorted(attendus - cites):
        defauts.append(f"le jeu porte art. {ref}, que la page ne cite plus — "
                       f"entrée orpheline à retirer")
    for a in articles:
        if a["etat"] != "VIGUEUR":
            defauts.append(f"art. {a['ref']} ({a['code']}) est au jeu avec l'état "
                           f"{a['etat']} — une directive ne cite pas un texte mort")

    # Le mode qui vaut vraiment : confronter le jeu à une base vivante.
    base = os.environ.get("SELFJUSTICE_API_LIVE", "").strip()
    joues = 0
    if base:
        for a in articles:
            try:
                d = interroge(base, a["ref"], a["code"])
            except urllib.error.HTTPError as e:
                if e.code in (429, 503):
                    defauts.append(
                        f"art. {a['ref']} ({a['code']}) — l'instance a refusé de servir "
                        f"({e.code}), même après un réessai. C'est une limite de débit, "
                        f"pas un défaut de l'article : relancer plus lentement "
                        f"(SELFJUSTICE_API_PAUSE) avant de conclure quoi que ce soit.")
                else:
                    defauts.append(f"art. {a['ref']} ({a['code']}) — l'instance rend "
                                   f"HTTP {e.code}")
                continue
            except (urllib.error.URLError, TimeoutError) as e:
                defauts.append(f"art. {a['ref']} ({a['code']}) — l'instance n'a pas "
                               f"répondu : {e}")
                continue
            joues += 1
            time.sleep(PAUSE)
            if d.get("error"):
                defauts.append(f"art. {a['ref']} ({a['code']}) — l'instance rend une "
                               f"erreur : {str(d['error'])[:90]}")
                continue
            if d.get("etat") != a["etat"]:
                defauts.append(f"art. {a['ref']} ({a['code']}) était {a['etat']}, "
                               f"l'instance rend {d.get('etat')}")
            if d.get("code_titre") != a["code_titre"]:
                defauts.append(f"art. {a['ref']} a changé de code porteur : "
                               f"{a['code_titre']!r} → {d.get('code_titre')!r}")
            texte = re.sub(r"\s+", " ", d.get("texte") or "").strip()[:90]
            if texte != a["amorce"]:
                defauts.append(f"art. {a['ref']} ({a['code']}) porte un autre texte "
                               f"qu'à l'extraction — numéro recyclé ou article "
                               f"réécrit, à revérifier avant de le citer encore")

    if defauts:
        print(f"✗ {len(defauts)} défaut(s) :", file=sys.stderr)
        for d in defauts:
            print("  · " + d, file=sys.stderr)
        return 1

    if base:
        print(f"✓ {len(cites)} articles cités, {joues} rejoués contre {base} — "
              f"état, code porteur et texte inchangés")
    else:
        print(f"✓ {len(cites)} articles cités correspondent au jeu "
              f"({jeu['_source'].split('—')[-1].strip()}). "
              f"⚠️ Fraîcheur NON vérifiée : poser SELFJUSTICE_API_LIVE pour la mesurer.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
