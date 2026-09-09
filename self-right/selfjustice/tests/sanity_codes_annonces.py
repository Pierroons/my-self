#!/usr/bin/env python3
"""Garde-fou — la page n'annonce aucun code que l'API ne saurait résoudre.

🔑 La page a longtemps listé seize alias quand l'API en résolvait 108. Une IA qui
lit la directive s'arrête à ce qu'elle nomme : le code de l'organisation
judiciaire répondait, mais personne ne le demandait. L'écart entre les deux
surfaces ne casse rien — il rétrécit silencieusement le droit consultable.

Ce contrôle interdit l'écart dans l'autre sens, le seul qui produise une erreur
visible : un `?code=` cité dans la page que l'API rejetterait.

Deux propriétés, parce que la page cite deux familles de noms :

1. **Les raccourcis de la table manuelle.** Chaque `?code=` de la page qui
   ressemble à un alias historique doit exister dans `$CODE_ALIASES` d'`api.php`.
   Retirer un alias du code sans toucher la page laisserait la page mentir.

2. **Les noms dérivés.** Les 92 autres codes n'ont pas d'entrée manuelle : leur
   nom est produit par `code_slug()` à partir du titre officiel. Un slug écrit à
   la main ne sera résolu que s'il est **stable** par cette fonction —
   `code_slug(x) == x`. `organisation-judiciaire` avec un tiret, ou
   `Organisation_Judiciaire`, ne seront jamais servis, et la page ne peut pas le
   savoir toute seule.

La fonction n'est pas réimplémentée ici : elle est extraite d'`api.php` et
exécutée par PHP. Deux copies d'une même règle divergent, et la divergence est
muette — c'est le défaut que ce fichier existe pour empêcher.

    python3 tests/sanity_codes_annonces.py
"""

import pathlib
import re
import shutil
import subprocess
import sys

RACINE = pathlib.Path(__file__).resolve().parent.parent
PAGE = RACINE / "site" / "index.php"
API = RACINE / "api" / "api.php"


def alias_manuels(src: str) -> set:
    """Les clés de $CODE_ALIASES, telles qu'api.php les déclare."""
    m = re.search(r"\$CODE_ALIASES\s*=\s*\[(.*?)\];", src, re.S)
    if not m:
        return set()
    return set(re.findall(r"'([a-z0-9_]+)'\s*=>", m.group(1)))


def slugs_cites(src: str) -> set:
    """Les noms de code que la page donne à lire, hors identifiants bruts."""
    trouves = set(re.findall(r"\?code=([A-Za-z0-9_.\-]+)", src))
    # `?code=...` est un gabarit de prose, pas un nom : il ne porte aucune lettre.
    # Les identifiants bruts sont hors sujet — ils ne passent pas par code_slug().
    return {s for s in trouves
            if re.search(r"[A-Za-z]", s)
            and not re.fullmatch(r"(?:LEGITEXT|JORFTEXT)\d+", s)}


def slug_par_php(mots: list) -> dict:
    """code_slug() d'api.php, appliquée à chaque mot — la fonction réelle."""
    src = API.read_text(encoding="utf-8")
    m = re.search(r"function code_slug\(string \$titre\): string \{.*?\n\}", src, re.S)
    if not m:
        print("✗ code_slug() introuvable dans api.php — le contrôle ne peut pas juger",
              file=sys.stderr)
        sys.exit(2)
    programme = (
        "<?php " + m.group(0) + "\n"
        "foreach (explode(\"\\n\", trim(stream_get_contents(STDIN))) as $l) {\n"
        "  if ($l !== '') echo $l, \"\\t\", code_slug($l), \"\\n\";\n}"
    )
    out = subprocess.run(["php", "-r", programme[5:]], input="\n".join(mots),
                         capture_output=True, text=True)
    if out.returncode != 0:
        print("✗ php a échoué : " + out.stderr.strip(), file=sys.stderr)
        sys.exit(2)
    rendu = {}
    for ligne in out.stdout.splitlines():
        if "\t" in ligne:
            avant, apres = ligne.split("\t", 1)
            rendu[avant] = apres
    return rendu


def main() -> int:
    if not shutil.which("php"):
        print("⊘ php introuvable — contrôle non exécuté", file=sys.stderr)
        return 0

    page = PAGE.read_text(encoding="utf-8")
    alias = alias_manuels(API.read_text(encoding="utf-8"))
    cites = slugs_cites(page)
    if not cites:
        print("✗ aucun ?code= trouvé dans la page — l'extraction est cassée,"
              " pas la page", file=sys.stderr)
        return 1

    # La liste explicite d'alias de la page : les <li><code>?code=x</code></li>.
    listes = set(re.findall(r"<li><code>\?code=([a-z0-9_]+)</code>", page))

    defauts = []

    # 1. Ce que la page présente comme un alias doit en être un.
    for s in sorted(listes - alias):
        defauts.append(f"la page liste ?code={s} comme alias, absent de $CODE_ALIASES")

    # 2. Tout nom cité hors de la table doit être stable par code_slug().
    derives = sorted(cites - alias)
    if derives:
        rendu = slug_par_php(derives)
        for s in derives:
            if rendu.get(s) != s:
                defauts.append(
                    f"?code={s} n'est pas un nom que code_slug() produit "
                    f"(elle en ferait « {rendu.get(s)} ») — l'API le rejettera")

    if defauts:
        print(f"✗ {len(defauts)} défaut(s) :", file=sys.stderr)
        for d in defauts:
            print("  · " + d, file=sys.stderr)
        return 1

    print(f"✓ {len(cites)} noms de code cités par la page — "
          f"{len(listes)} alias listés, {len(derives)} dérivés, tous résolvables")
    return 0


if __name__ == "__main__":
    sys.exit(main())
