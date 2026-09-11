#!/usr/bin/env python3
"""Garde-fou — le collecteur JADE écrit ce qu'il doit, et refuse le reste bruyamment.

🔑 Un collecteur silencieux est pire qu'un collecteur en panne. Celui de LEGI a
construit la base pendant treize mois sur un dump figé, sans erreur, sans code
de sortie, avec une date exacte que personne n'a vue immobile. Les propriétés
qui comptent ici ne sont donc pas « ça écrit », mais « ça refuse en le disant »
et « ça n'invente rien ».

Sept propriétés, sur des archives fabriquées pour ce contrôle — ici c'est
légitime : ce qu'on éprouve est le comportement du code face à des cas qu'aucun
dump réel ne contient à la demande (un XML tronqué, une juridiction inconnue,
une page d'erreur déguisée en archive).

    python3 tests/sanity_jade_collecteur.py
"""

import os
import pathlib
import shutil
import sqlite3
import subprocess
import sys
import tarfile
import tempfile

RACINE = pathlib.Path(__file__).resolve().parent.parent
COLLECTEUR = RACINE / "tools" / "build_jade_db.py"

DECISION = """<?xml version="1.0" encoding="UTF-8"?>
<TEXTE_JURI_ADMIN>
  <META><META_COMMUN><ID>{id}</ID><NATURE>Texte</NATURE></META_COMMUN>
  <META_SPEC><META_JURI><TITRE>{juri}</TITRE><DATE_DEC>{date}</DATE_DEC>
  <JURIDICTION>{juri}</JURIDICTION><NUMERO>{num}</NUMERO>
  <FORMATION>{formation}</FORMATION><PUBLI_RECUEIL>{publi}</PUBLI_RECUEIL>
  </META_JURI><META_JURI_ADMIN><TYPE_REC>excès de pouvoir</TYPE_REC>
  </META_JURI_ADMIN></META_SPEC></META>
  <TEXTE><BLOC_TEXTUEL><CONTENU>{contenu}</CONTENU></BLOC_TEXTUEL></TEXTE>
</TEXTE_JURI_ADMIN>
"""

echecs = []


def verdict(ok, libelle):
    print("  %s %s" % ("✓" if ok else "✗", libelle))
    if not ok:
        echecs.append(libelle)


def archive(chemin, decisions):
    """Fabrique un tarball JADE. `decisions` : liste de (nom, contenu brut)."""
    with tempfile.TemporaryDirectory() as tmp:
        for nom, contenu in decisions:
            f = pathlib.Path(tmp) / (nom + ".xml")
            f.write_text(contenu, encoding="utf-8")
        with tarfile.open(chemin, "w:gz") as tar:
            for f in sorted(pathlib.Path(tmp).iterdir()):
                tar.add(f, arcname="jade/global/inedit/CETA/TEXT/" + f.name)
    return chemin


def lancer(db, *args):
    r = subprocess.run([sys.executable, str(COLLECTEUR), "--db", str(db)] + list(args),
                       capture_output=True, text=True)
    return r.returncode, r.stdout + r.stderr


def main():
    tmp = pathlib.Path(tempfile.mkdtemp())
    try:
        db = tmp / "index.sqlite"

        # Une décision Judilibre préexistante : la collecte JADE ne doit pas la
        # toucher. Les deux fonds partagent la table, pas les lignes.
        conn = sqlite3.connect(db)
        conn.execute("""CREATE TABLE decisions (
            id TEXT PRIMARY KEY, number TEXT, decision_date TEXT, jurisdiction TEXT,
            chamber TEXT, location TEXT, formation TEXT, publication TEXT,
            solution TEXT, ecli TEXT, type TEXT, update_date TEXT,
            date_suspecte INTEGER DEFAULT 0)""")
        conn.execute("INSERT INTO decisions (id, number, jurisdiction, decision_date) "
                     "VALUES ('JURITEXT001','23-10.001','cc','2026-01-01')")
        conn.commit()
        conn.close()

        print("▸ Une collecte ordinaire")
        bon = archive(tmp / "JADE_20260101-000000.tar.gz", [
            ("CETATEXT001", DECISION.format(
                id="CETATEXT001", juri="Conseil d'Etat", date="2024-03-15",
                num="123456", formation="Section", publi="A",
                contenu="Vu la procédure :<br/>\n<br/>   premier alinéa.")),
            ("CETATEXT002", DECISION.format(
                id="CETATEXT002", juri="CAA de MARSEILLE", date="2023-06-01",
                num="21MA00042", formation="3e chambre", publi="C",
                contenu="Texte de la cour.")),
        ])
        code, sortie = lancer(db, "--diff", str(bon))
        verdict(code == 0, "une collecte saine sort en code 0")

        conn = sqlite3.connect(db)
        q = lambda s, *p: conn.execute(s, p).fetchone()
        verdict(q("SELECT COUNT(*) FROM decisions WHERE source='jade'")[0] == 2,
                "les deux décisions sont écrites")
        verdict(q("SELECT jurisdiction, location FROM decisions WHERE id='CETATEXT002'")
                == ("caa", "MARSEILLE"), "la juridiction est normalisée, la ville gardée")
        texte = q("SELECT texte FROM decisions WHERE id='CETATEXT001'")[0]
        verdict("<br" not in texte and "Vu la procédure" in texte,
                "le texte est stocké débalisé")
        verdict(q("SELECT publication, type FROM decisions WHERE id='CETATEXT001'")
                == ("A", "excès de pouvoir"), "recueil et type de recours sont gardés")
        verdict(q("SELECT jurisdiction, texte FROM decisions WHERE id='JURITEXT001'")
                == ("cc", None), "la décision Judilibre préexistante est intacte")
        conn.close()

        print("\n▸ Un second passage du même incrément")
        code, _ = lancer(db, "--diff", str(bon))
        conn = sqlite3.connect(db)
        n = conn.execute("SELECT COUNT(*) FROM decisions WHERE source='jade'").fetchone()[0]
        conn.close()
        verdict(n == 2, "rien n'est dupliqué (%d décisions)" % n)

        print("\n▸ Le même identifiant deux fois dans une archive")
        # Le fonds le fait : 40 fois sur 552 576 fichiers. La base en portera
        # moins que de fichiers lus, et cet écart doit s'expliquer tout seul.
        redite = archive(tmp / "JADE_20260110-000000.tar.gz", [
            ("a_CETATEXT009", DECISION.format(
                id="CETATEXT009", juri="Conseil d'Etat", date="2024-01-01",
                num="777", formation="", publi="C", contenu="Première copie.")),
            ("b_CETATEXT009", DECISION.format(
                id="CETATEXT009", juri="Conseil d'Etat", date="2024-01-01",
                num="777", formation="", publi="C", contenu="Seconde copie.")),
        ])
        code, sortie = lancer(db, "--diff", str(redite))
        conn = sqlite3.connect(db)
        n = conn.execute("SELECT COUNT(*) FROM decisions WHERE id='CETATEXT009'")\
            .fetchone()[0]
        conn.close()
        verdict(n == 1, "une seule ligne est gardée")
        verdict("plusieurs fois" in sortie,
                "l'écart entre lues et écrites est NOMMÉ, pas laissé à deviner")
        verdict(code == 0, "et ce n'est pas traité comme un défaut (code %d)" % code)

        print("\n▸ Une date que le calendrier ne connaît pas")
        aberrante = archive(tmp / "JADE_20260102-000000.tar.gz", [
            ("CETATEXT003", DECISION.format(
                id="CETATEXT003", juri="Conseil d'Etat", date="2990-01-01",
                num="999", formation="", publi="C", contenu="Texte.")),
        ])
        lancer(db, "--diff", str(aberrante))
        conn = sqlite3.connect(db)
        verdict(conn.execute("SELECT date_suspecte FROM decisions WHERE id='CETATEXT003'")
                .fetchone()[0] == 1, "l'an 2990 lève date_suspecte")
        conn.close()

        print("\n▸ Une juridiction que la table ne connaît pas")
        inconnue = archive(tmp / "JADE_20260103-000000.tar.gz", [
            ("CETATEXT004", DECISION.format(
                id="CETATEXT004", juri="Chambre régionale des comptes de Bretagne",
                date="2024-01-01", num="1", formation="", publi="C",
                contenu="Texte.")),
        ])
        code, sortie = lancer(db, "--diff", str(inconnue))
        conn = sqlite3.connect(db)
        ecrite = conn.execute("SELECT COUNT(*) FROM decisions WHERE id='CETATEXT004'")\
            .fetchone()[0]
        conn.close()
        verdict(ecrite == 0, "la décision n'est PAS écrite sous un code par défaut")
        verdict("Chambre régionale des comptes" in sortie,
                "le libellé refusé est NOMMÉ, pas seulement compté")
        verdict(code != 0, "la collecte sort en code non nul (%d)" % code)

        print("\n▸ Un XML tronqué")
        tronque = archive(tmp / "JADE_20260104-000000.tar.gz", [
            ("CETATEXT005", "<?xml version=\"1.0\"?><TEXTE_JURI_ADMIN><META>"),
        ])
        code, sortie = lancer(db, "--diff", str(tronque))
        verdict("1 illisibles" in sortie or "illisibles" in sortie,
                "il est compté, la moisson n'est pas interrompue")
        verdict(code != 0, "et la collecte sort en code non nul (%d)" % code)

        print("\n▸ Une page d'erreur déguisée en archive")
        faux = tmp / "JADE_20260105-000000.tar.gz"
        faux.write_bytes(b"<!DOCTYPE HTML><html><head><title>404 Not Found</title>")
        code, sortie = lancer(db, "--diff", str(faux))
        verdict(code != 0, "elle est refusée (code %d)" % code)
        verdict("gzip" in sortie and "404" not in sortie.split("gzip")[0][-200:] or
                "n'est pas une archive gzip" in sortie,
                "le message nomme la cause au lieu d'une trace Python")
        verdict("Traceback" not in sortie, "aucune trace Python n'est montrée")

        print()
        if echecs:
            print("✗ %d contrôle(s) en échec." % len(echecs))
            return 1
        print("✓ Le collecteur JADE écrit, refuse et le dit — 19 contrôles.")
        return 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
