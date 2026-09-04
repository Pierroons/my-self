#!/usr/bin/env python3
"""Reconstitue le déchiffreur et le coffre à partir du pli scanné.

C'est la seule pièce que le dépositaire utilisera vraiment. Sans elle, la page 1
du pli lui demande de recalculer deux empreintes sans nommer d'outil pour le
faire : un ordre sans instrument.

  python3 lire_pli.py pli-scanne.pdf                 # ou un répertoire d'images
  python3 lire_pli.py pli-scanne.pdf -o /tmp/sorti

Dépendances système : `poppler-utils` (pdftoppm, pdftotext) et `zbar-tools`
(zbarimg). Aucune bibliothèque Python.

🔑 Trois règles de conduite, toutes trois éprouvées par le banc :

- il nomme **tous** les QR codes manquants, pas le premier ;
- il n'écrit **aucun fichier partiel** : sans la totalité des fragments et sans
  concordance des empreintes, rien n'est posé sur le disque ;
- quand il ne peut PAS vérifier une empreinte contre celle de la page 1, il le
  dit et sort en échec. Un lecteur qui rend « reconstitué » sans avoir comparé
  ressemble trait pour trait à un lecteur qui a comparé.
"""
import argparse, base64, hashlib, os, re, shutil, subprocess, sys, tempfile

PREFIXE = "PLI1"
PIECES = {"A": "selfvault.html", "V": "coffre.selfvault"}
DPI = 300          # plancher mesuré : 200 passe, 150 échoue
EMPREINTE_CAR = 32  # ce que la page 1 imprime : SHA-256 tronqué


def outil(nom):
    if shutil.which(nom) is None:
        sys.exit("« %s » est introuvable. Installe poppler-utils et zbar-tools." % nom)
    return nom


def pages_en_images(source, travail):
    """Rend la liste des images à scruter, qu'on parte d'un PDF ou d'images."""
    if os.path.isdir(source):
        return sorted(os.path.join(source, f) for f in os.listdir(source)
                      if f.lower().endswith((".png", ".jpg", ".jpeg", ".tif", ".tiff", ".pnm")))
    if source.lower().endswith(".pdf"):
        subprocess.run([outil("pdftoppm"), "-r", str(DPI), "-gray", "-png",
                        source, os.path.join(travail, "page")], check=True)
        return sorted(os.path.join(travail, f) for f in os.listdir(travail)
                      if f.startswith("page") and f.endswith(".png"))
    return [source]


def fragments(images):
    """Tous les fragments lus, indexés par (pièce, rang). L'ordre n'importe pas.

    Le rang vit DANS les données : un pli scanné en désordre, ou dont les pages
    ont été mélangées, se reconstitue quand même.
    """
    lus, inconnus = {}, 0
    motif = re.compile(r"^%s\|([A-Z])\|(\d+)/(\d+)\|(.*)$" % PREFIXE, re.S)
    for img in images:
        sortie = subprocess.run([outil("zbarimg"), "--raw", "-q", img],
                                capture_output=True, text=True).stdout
        for ligne in sortie.split("\n"):
            ligne = ligne.strip()
            if not ligne:
                continue
            m = motif.match(ligne)
            if not m:
                inconnus += 1
                continue
            piece, rang, total, donnees = m.group(1), int(m.group(2)), int(m.group(3)), m.group(4)
            lus.setdefault(piece, {"total": total, "parts": {}})
            lus[piece]["parts"][rang] = donnees
            if lus[piece]["total"] != total:
                sys.exit("Le pli mélange deux tirages : la pièce %s s'annonce tantôt en %d "
                         "QR codes, tantôt en %d." % (piece, lus[piece]["total"], total))
    return lus, inconnus


def empreintes_imprimees(source):
    """Les empreintes de la page 1, si le PDF porte encore sa couche texte.

    Un pli réellement scanné n'en a pas : il faudra alors les donner à la main.
    Ne jamais deviner à leur place — c'est la comparaison qui a de la valeur.
    """
    if not source.lower().endswith(".pdf"):
        return {}
    texte = subprocess.run([outil("pdftotext"), "-l", "1", source, "-"],
                           capture_output=True, text=True).stdout
    trouve = {}
    for piece, motif in (("A", r"Empreinte du déchiffreur.*?\n\s*([0-9a-f][0-9a-f ]{30,})"),
                         ("V", r"Empreinte du coffre.*?\n\s*([0-9a-f][0-9a-f ]{30,})")):
        m = re.search(motif, texte, re.S | re.I)
        if m:
            trouve[piece] = m.group(1).replace(" ", "").strip()[:EMPREINTE_CAR]
    return trouve


def main():
    a = argparse.ArgumentParser(description="Reconstitue un coffre SelfVault depuis son pli.")
    a.add_argument("source", help="le pli scanné : un PDF, une image, ou un répertoire d'images")
    a.add_argument("-o", "--sortie", default=".", help="où écrire les fichiers reconstitués")
    a.add_argument("--empreinte-app", help="empreinte du déchiffreur, lue en page 1 du pli")
    a.add_argument("--empreinte-coffre", help="empreinte du coffre, lue en page 1 du pli")
    opt = a.parse_args()

    travail = tempfile.mkdtemp(prefix="lire-pli-")
    try:
        images = pages_en_images(opt.source, travail)
        if not images:
            sys.exit("Rien à lire dans « %s »." % opt.source)
        print("▸ %d page(s) à scruter" % len(images))
        lus, inconnus = fragments(images)
        if inconnus:
            print("  %d QR code(s) lisibles mais étrangers à ce pli — ignorés" % inconnus)

        # ── Ce qui manque, en ENTIER ─────────────────────────────────────────
        manques = []
        for piece in sorted(PIECES):
            if piece not in lus:
                manques.append("la pièce « %s » (%s) est entièrement absente" % (piece, PIECES[piece]))
                continue
            total, parts = lus[piece]["total"], lus[piece]["parts"]
            absents = [i for i in range(1, total + 1) if i not in parts]
            print("  pièce %s : %d/%d QR codes" % (piece, len(parts), total))
            if absents:
                manques.append("il manque, pour %s : %s"
                               % (PIECES[piece],
                                  ", ".join("%s%d/%d" % (piece, i, total) for i in absents)))
        if manques:
            print("\n✗ Pli incomplet. Rien n'a été écrit.")
            for m in manques:
                print("   " + m)
            print("\n   Rescanne les pages concernées à %d points par pouce au moins." % DPI)
            return 1

        # ── Reconstitution en mémoire, écriture seulement à la fin ───────────
        attendues = empreintes_imprimees(opt.source)
        if attendues:
            print("  empreintes lues sur la page 1 du pli lui-même")
        for piece, valeur in (("A", opt.empreinte_app), ("V", opt.empreinte_coffre)):
            if valeur:
                attendues[piece] = re.sub(r"[^0-9a-f]", "", valeur.lower())[:EMPREINTE_CAR]

        reconstitues, echec = {}, False
        for piece in sorted(PIECES):
            d = lus[piece]
            texte = "".join(d["parts"][i] for i in range(1, d["total"] + 1))
            try:
                brut = base64.b64decode(texte, validate=True)
            except Exception:
                print("✗ %s : les fragments ne se recollent pas en Base64 valide." % PIECES[piece])
                echec = True
                continue
            emp = hashlib.sha256(brut).hexdigest()
            court = emp[:EMPREINTE_CAR]
            groupe = " ".join(court[i:i + 4] for i in range(0, EMPREINTE_CAR, 4))
            if piece not in attendues:
                print("✗ %s : %d octets, empreinte %s" % (PIECES[piece], len(brut), groupe))
                print("   AUCUNE empreinte de référence — la comparaison de la page 1 n'a pas "
                      "eu lieu. Relance avec --empreinte-%s."
                      % ("app" if piece == "A" else "coffre"))
                echec = True
            elif attendues[piece] != court:
                print("✗ %s : empreinte %s, la page 1 annonce %s"
                      % (PIECES[piece], groupe,
                         " ".join(attendues[piece][i:i + 4] for i in range(0, len(attendues[piece]), 4))))
                echec = True
            else:
                print("✓ %s : %d octets, empreinte %s — concorde avec la page 1"
                      % (PIECES[piece], len(brut), groupe))
                reconstitues[piece] = brut

        if echec:
            print("\n✗ Rien n'a été écrit.")
            return 1

        os.makedirs(opt.sortie, exist_ok=True)
        for piece, brut in reconstitues.items():
            chemin = os.path.join(opt.sortie, PIECES[piece])
            with open(chemin, "wb") as f:
                f.write(brut)
            print("  écrit : %s" % chemin)
        print("\n✓ Pli complet et conforme. Ouvre %s dans un navigateur, puis charges-y %s."
              % (PIECES["A"], PIECES["V"]))
        return 0
    finally:
        for f in os.listdir(travail):
            os.remove(os.path.join(travail, f))
        os.rmdir(travail)


if __name__ == "__main__":
    sys.exit(main())
