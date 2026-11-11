#!/usr/bin/env python3
"""Garde-fou — le collecteur JADE écrit ce qu'il doit, et refuse le reste bruyamment.

🔑 Un collecteur silencieux est pire qu'un collecteur en panne. Celui de LEGI a
construit la base pendant treize mois sur un dump figé, sans erreur, sans code
de sortie, avec une date exacte que personne n'a vue immobile. Les propriétés
qui comptent ici ne sont donc pas « ça écrit », mais « ça refuse en le disant »
et « ça n'invente rien ».

Les propriétés sont éprouvées sur des archives et des fenêtres fabriquées — ici
c'est légitime : ce qu'on éprouve est le comportement du code face à des cas
qu'aucun dump réel ne contient à la demande (un XML tronqué, une juridiction
inconnue, une page d'erreur déguisée en archive, une fenêtre d'incréments qui a
roulé). Leur compte est imprimé en fin de passage, pour qu'il n'ait pas à être
tenu à jour ici.

    python3 tests/sanity_jade_collecteur.py
"""

import datetime
import functools
import http.server
import os
import pathlib
import shutil
import sqlite3
import subprocess
import sys
import tarfile
import tempfile
import threading

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
controles = []


def verdict(ok, libelle):
    controles.append(libelle)
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


def lancer(db, *args, dila=None):
    env = dict(os.environ, SELFJUSTICE_JADE_BASE=dila) if dila else None
    r = subprocess.run([sys.executable, str(COLLECTEUR), "--db", str(db)] + list(args),
                       capture_output=True, text=True, env=env)
    return r.returncode, r.stdout + r.stderr


def dila_locale(repertoire):
    """Sert `repertoire` comme la DILA sert ses incréments : un index, des tarballs."""
    gestion = functools.partial(http.server.SimpleHTTPRequestHandler, directory=str(repertoire))
    gestion.log_message = lambda *a: None
    serveur = http.server.HTTPServer(("127.0.0.1", 0), gestion)
    threading.Thread(target=serveur.serve_forever, daemon=True).start()
    return serveur


def une_decision(ident):
    return [(ident, DECISION.format(
        id=ident, juri="Conseil d'Etat", date="2026-02-01", num=ident[-6:],
        formation="Section", publi="B", contenu="Texte."))]


def horodate(heures=0):
    """Un instant décalé de `heures`, au format que portent les noms d'incrément."""
    return (datetime.datetime.now()
            + datetime.timedelta(hours=heures)).strftime("%Y%m%d-%H%M%S")


def etat_de(db, cle):
    """Ce que le collecteur a retenu sous cette clé, ou None."""
    c = sqlite3.connect(db)
    r = c.execute("SELECT valeur FROM jade_etat WHERE cle=?", (cle,)).fetchone()
    c.close()
    return r[0] if r else None


def graver(db, cle, valeur):
    """Écrit une clé de `jade_etat` : le banc ne peut attendre ni un jour ni une
    republication du fonds global."""
    c = sqlite3.connect(db)
    c.execute("INSERT OR REPLACE INTO jade_etat VALUES (?,?)", (cle, valeur))
    c.commit()
    c.close()
    return valeur


def antidater(db, heures):
    """Recule la dernière lecture rattrapée : le banc ne peut pas attendre un jour."""
    return graver(db, "fenetre_lue_le", horodate(-heures))


GLOBAL_TEMOIN = "Freemium_jade_global_20260101-140000.tar.gz"


def fonds(chemin, *increments, global_nom=GLOBAL_TEMOIN):
    """Un répertoire servi comme la DILA sert le fonds JADE : son tarball global,
    et une fenêtre d'incréments qui peut être vide.

    🔑 Le global y est toujours, même quand la fenêtre est vide : c'est ce qui
    permet de distinguer une fenêtre vide d'une page qui n'est pas l'index du
    fonds. La mesure est dans `fonds_publie()`.
    """
    chemin.mkdir()
    archive(chemin / global_nom, une_decision("CETATEXT000001"))
    for nom in increments:
        archive(chemin / nom, une_decision("CETATEXT9" + nom[5:13]))
    return chemin


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

        # 🔑 La reprise automatique, contre une DILA locale. La DILA ne garde
        # qu'une fenêtre d'incréments : une fenêtre qui commence après le dernier
        # appliqué doit arrêter la collecte, sans rien écrire — sinon
        # `dernier_diff` sauterait par-dessus le trou, qui deviendrait invisible.
        print("\n▸ La reprise automatique (--depuis auto)")
        base = tmp / "reprise.sqlite"
        cache = tmp / "cache"
        cache.mkdir()
        continue_ = tmp / "dila-continue"
        trouee = tmp / "dila-trouee"
        continue_.mkdir()
        trouee.mkdir()
        depart = archive(tmp / "JADE_20260201-213000.tar.gz", une_decision("CETATEXT100201"))
        code, sortie = lancer(base, "--diff", str(depart))
        verdict(code == 0, "un premier incrément fixe le point de reprise (code %d)" % code)
        for jour in ("20260201", "20260202", "20260203"):
            archive(continue_ / ("JADE_%s-213000.tar.gz" % jour), une_decision("CETATEXT10" + jour[4:]))
        for jour in ("20260210", "20260211"):
            archive(trouee / ("JADE_%s-213000.tar.gz" % jour), une_decision("CETATEXT10" + jour[4:]))

        def etat_reprise():
            c = sqlite3.connect(base)
            d = c.execute("SELECT valeur FROM jade_etat WHERE cle='dernier_diff'").fetchone()[0]
            n = c.execute("SELECT COUNT(*) FROM decisions WHERE source='jade'").fetchone()[0]
            c.close()
            return d, n

        serveur = dila_locale(continue_)
        code, sortie = lancer(base, "--depuis", "auto", "--cache", str(cache),
                              dila="http://127.0.0.1:%d" % serveur.server_port)
        serveur.shutdown()
        dernier, n = etat_reprise()
        verdict(code == 0 and dernier == "JADE_20260203-213000.tar.gz" and n == 3,
                "fenêtre continue → les deux incréments manquants appliqués (%s, %d décisions, code %d)"
                % (dernier, n, code))

        serveur = dila_locale(trouee)
        code, sortie = lancer(base, "--depuis", "auto", "--cache", str(cache),
                              dila="http://127.0.0.1:%d" % serveur.server_port)
        serveur.shutdown()
        dernier, n = etat_reprise()
        verdict(code != 0 and "trou possible" in sortie.lower(),
                "fenêtre qui commence après le dernier appliqué → arrêt nommé (code %d)" % code)
        verdict(dernier == "JADE_20260203-213000.tar.gz" and n == 3,
                "et rien n'est écrit : %s, %d décisions" % (dernier, n))
        verdict(etat_de(base, "fenetre_lue_le") is not None
                and "20260210" < etat_de(base, "fenetre_lue_le"),
                "et il tient alors qu'une lecture rattrapée existe : les incréments "
                "de cette fenêtre sont nés avant elle")

        # ── La preuve de continuité ─────────────────────────────────────────
        # 🔑 Une fenêtre qui commence après le dernier appliqué ne dit pas à elle
        # seule qu'il manque quelque chose : la DILA purge ses incréments quand
        # elle republie son fonds global. Ce qui tranche est la dernière lecture
        # RATTRAPÉE : si le plus ancien incrément publié est né après elle, rien
        # n'a pu paraître et disparaître entre les deux. Une lecture qui n'a pas
        # rattrapé la fenêtre ne prouve rien, sinon des refus successifs
        # blanchiraient un vrai trou.
        print("\n▸ La preuve de continuité (une fenêtre qui a roulé)")
        pcache = tmp / "cache-preuve"
        pcache.mkdir()
        reference = "JADE_%s.tar.gz" % horodate(-30)
        archive(tmp / reference, une_decision("CETATEXT9" + reference[5:13]))

        def base_prete(nom):
            """Une base dont le dernier appliqué a 30 h, sans ancre de lecture, et
            qui a vu le fonds global témoin — pour que ce soit la condition éprouvée
            par chaque cas qui refuse, et non le global qui manque."""
            d = tmp / nom
            lancer(d, "--diff", str(tmp / reference))
            graver(d, "global_vu", GLOBAL_TEMOIN)
            return d

        def passage(db, repertoire):
            serveur = dila_locale(repertoire)
            try:
                return lancer(db, "--depuis", "auto", "--cache", str(pcache),
                              dila="http://127.0.0.1:%d" % serveur.server_port)
            finally:
                serveur.shutdown()

        neuf = "JADE_%s.tar.gz" % horodate(-2)

        pdb = base_prete("preuve.sqlite")
        code, sortie = passage(pdb, fonds(tmp / "fonds-vide"))
        verdict(code == 0 and etat_de(pdb, "fenetre_lue_le") is None,
                "une fenêtre vide sans lecture rattrapée antérieure ne consigne rien : "
                "elle ne dit pas ce qu'elle portait avant d'être purgée (code %d)" % code)
        code, sortie = passage(pdb, fonds(tmp / "fonds-rattrape", reference))
        verdict(code == 0 and etat_de(pdb, "fenetre_lue_le") is not None,
                "une fenêtre entièrement appliquée, elle, consigne la lecture "
                "(code %d, ancre %s)" % (code, etat_de(pdb, "fenetre_lue_le")))

        antidater(pdb, 24)
        code, sortie = passage(pdb, fonds(tmp / "fonds-roule", neuf))
        verdict(code == 0 and etat_de(pdb, "dernier_diff") == neuf,
                "fenêtre roulée, née après la dernière lecture rattrapée → appliquée "
                "(code %d, dernier %s)" % (code, etat_de(pdb, "dernier_diff")))

        pdb3 = base_prete("preuve-vue.sqlite")
        antidater(pdb3, 1)
        code, sortie = passage(pdb3, fonds(tmp / "fonds-deja-vu", neuf))
        verdict(code != 0 and "trou possible" in sortie.lower()
                and etat_de(pdb3, "dernier_diff") == reference,
                "un incrément déjà visible lors de la dernière lecture ne prouve "
                "rien → refus (code %d)" % code)

        pdb4 = base_prete("preuve-non-rattrapee.sqlite")
        troue = fonds(tmp / "fonds-troue", neuf)
        code, _ = passage(pdb4, troue)
        premier = etat_de(pdb4, "fenetre_lue_le")
        code2, sortie2 = passage(pdb4, troue)
        verdict(code != 0 and code2 != 0 and premier is None
                and etat_de(pdb4, "fenetre_lue_le") is None,
                "une lecture qui n'a pas rattrapé la fenêtre ne devient pas une "
                "preuve : deux refus de suite, aucune ancre (codes %d et %d)"
                % (code, code2))

        pdb5 = base_prete("preuve-perimee.sqlite")
        antidater(pdb5, 26)
        code, sortie = passage(pdb5, fonds(tmp / "fonds-perime", neuf))
        verdict(code != 0 and etat_de(pdb5, "dernier_diff") == reference,
                "passé la mémoire de la fenêtre, la même situation redevient un "
                "trou (code %d)" % code)

        # 🔑 Le trou que la seule condition de temps laisserait ouvert : un
        # incrément paraît APRÈS la dernière lecture et disparaît AVANT la
        # suivante. La fenêtre du lendemain ne porte alors que des incréments nés
        # après l'ancre, et « né après la lecture » accorderait la preuve
        # par-dessus. Ce qui l'interdit est la republication du fonds global, la
        # seule purge mesurée : un global changé depuis l'ancre annule la preuve.
        pdb8 = base_prete("preuve-global-republie.sqlite")
        antidater(pdb8, 24)
        republie = fonds(tmp / "fonds-republie", neuf,
                         global_nom="Freemium_jade_global_20261108-140000.tar.gz")
        code, sortie = passage(pdb8, republie)
        verdict(code != 0 and "trou possible" in sortie.lower()
                and etat_de(pdb8, "dernier_diff") == reference,
                "un fonds global republié depuis la dernière lecture annule la "
                "preuve : un incrément a pu y être purgé sans témoin (code %d)" % code)

        # 🔑 Ce qu'une fenêtre vide peut hériter, et ce qu'elle ne peut pas. Les
        # deux cas qui suivent ne diffèrent que par l'âge de l'ancre : le premier
        # est l'incident du 08/11/2026, où la veille avait tout vu ; le second est
        # la nuit manquée, où un incrément a disparu sans témoin.
        republie_nom = "Freemium_jade_global_20261108-140000.tar.gz"
        pdb9 = base_prete("preuve-vide-heritee.sqlite")
        antidater(pdb9, 24)
        code, sortie = passage(pdb9, fonds(tmp / "fonds-vide-recent",
                                           global_nom=republie_nom))
        verdict(code == 0 and etat_de(pdb9, "global_vu") == republie_nom,
                "une fenêtre vide sous un global republié hérite quand la lecture de "
                "la veille est récente — c'est l'incident du 08/11 (code %d)" % code)

        pdb10 = base_prete("preuve-nuit-manquee.sqlite")
        vieille = antidater(pdb10, 48)
        code, sortie = passage(pdb10, fonds(tmp / "fonds-vide-nuit-manquee",
                                            global_nom=republie_nom))
        verdict(code == 0 and etat_de(pdb10, "fenetre_lue_le") == vieille
                and etat_de(pdb10, "global_vu") == GLOBAL_TEMOIN,
                "mais après une nuit manquée elle n'hérite pas : l'ancre et le global "
                "vu ne bougent pas (code %d)" % code)
        code, sortie = passage(pdb10, fonds(tmp / "fonds-apres-nuit-manquee", neuf,
                                            global_nom=republie_nom))
        verdict(code != 0 and "trou possible" in sortie.lower()
                and etat_de(pdb10, "dernier_diff") == reference,
                "et le refus tient le lendemain : l'incrément que personne n'a vu "
                "n'est pas enjambé (code %d)" % code)

        # ⚠️ Nom et cache propres à ce cas : `telecharger()` rend un fichier déjà
        # présent dans le cache sans le retélécharger, donc réutiliser le nom d'un
        # cas précédent ferait absorber SON archive, et celle-ci jamais.
        pdb7 = base_prete("preuve-incomplete.sqlite")
        cache7 = tmp / "cache-incomplet"
        cache7.mkdir()
        refuse = "JADE_%s.tar.gz" % horodate(-3)
        incomplet = tmp / "fonds-incomplet"
        incomplet.mkdir()
        archive(incomplet / "Freemium_jade_global_20260101-140000.tar.gz",
                une_decision("CETATEXT000002"))
        archive(incomplet / refuse, [("CETATEXT990001", DECISION.format(
            id="CETATEXT990001", juri="Chambre régionale des comptes de Bretagne",
            date="2024-01-01", num="990001", formation="", publi="C",
            contenu="Texte."))])
        ancre_posee = antidater(pdb7, 24)
        serveur = dila_locale(incomplet)
        try:
            code, sortie = lancer(pdb7, "--depuis", "auto", "--cache", str(cache7),
                                  dila="http://127.0.0.1:%d" % serveur.server_port)
        finally:
            serveur.shutdown()
        verdict(code != 0 and etat_de(pdb7, "fenetre_lue_le") == ancre_posee,
                "une collecte incomplète n'arme pas la preuve, bien qu'elle applique "
                "l'incrément : l'ancre reste celle d'avant (code %d)" % code)

        faux_index = tmp / "pas-un-index"
        faux_index.mkdir()
        (faux_index / "index.html").write_text(
            "<html><body><h1>Service momentanément indisponible</h1></body></html>",
            encoding="utf-8")
        pdb6 = base_prete("preuve-index.sqlite")
        code, sortie = passage(pdb6, faux_index)
        verdict(code == 4 and etat_de(pdb6, "fenetre_lue_le") is None,
                "un index qui ne nomme ni incrément ni fonds global → source "
                "indisponible, aucune ancre (code %d)" % code)

        serveur = dila_locale(continue_)
        port_ferme = serveur.server_port
        serveur.shutdown()
        serveur.server_close()
        ancre_avant = etat_de(base, "fenetre_lue_le")
        code, sortie = lancer(base, "--depuis", "auto", "--cache", str(cache),
                              dila="http://127.0.0.1:%d" % port_ferme)
        verdict(code == 4 and "Traceback" not in sortie,
                "DILA injoignable → code 4, sans trace Python (code %d)" % code)
        verdict(etat_de(base, "fenetre_lue_le") == ancre_avant,
                "et un passage qui n'a pas joint la DILA ne consigne aucune lecture")

        print()
        if echecs:
            print("✗ %d contrôle(s) en échec." % len(echecs))
            return 1
        print("✓ Le collecteur JADE écrit, refuse et le dit — %d contrôles." % len(controles))
        return 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
