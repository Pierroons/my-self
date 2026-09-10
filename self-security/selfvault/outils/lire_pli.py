#!/usr/bin/env python3
"""Reconstitue le déchiffreur et le coffre à partir du pli scanné.

C'est la seule pièce que le destinataire du coffre utilisera vraiment. Sans elle,
le pli demande de recalculer deux empreintes sans nommer d'outil pour le faire :
un ordre sans instrument.

  python3 lire_pli.py pli-scanne.pdf                 # ou un répertoire d'images
  python3 lire_pli.py pli-scanne.pdf -o /tmp/sorti

Dépendances système : `poppler-utils` (pdftoppm, pdftotext) et `zbar-tools`
(zbarimg). Aucune bibliothèque Python.

🔑 Quatre règles de conduite, toutes éprouvées par le banc :

- il insiste avant de conclure qu'il manque quelque chose : un balayage plus
  fin, puis d'autres résolutions quand la source est un PDF ;
- il nomme **tous** les QR codes manquants, pas le premier ;
- il n'écrit **aucun fichier partiel** : sans la totalité des fragments et sans
  concordance des empreintes, rien n'est posé sur le disque ;
- quand il ne peut PAS vérifier une empreinte contre celle qu'annonce le pli, il
  le dit et sort en échec. Un lecteur qui rend « reconstitué » sans avoir comparé
  ressemble trait pour trait à un lecteur qui a comparé.
"""
import argparse, base64, hashlib, os, re, shutil, subprocess, sys, tempfile

PREFIXE = "PLI1"
PIECES = {"A": "selfvault.html", "V": "coffre.selfvault"}
DPI = 300          # ce que le pli imprime
# 🔑 Sans ces deux recours, une lecture unique rend « pli incomplet » sur un pli
# intact. Mesuré le 09/09/2026 — la page qui perd A9 à 300 points par pouce le
# rend à 150, 200, 250, 350, 400, 500 et 600, et le même code extrait seul se
# relit à toutes les tailles. Ce que perd une rasterisation dépend de sa phase,
# donc de la version de poppler.
DPI_SECOURS = (400, 250)
# Les balayages valent pour TOUTES les sources, y compris un répertoire venu d'un
# scanner qu'on ne peut pas relancer — c'est le seul recours qui lui reste, puisque
# rerasteriser demande un PDF. Chacun échantillonne l'image sur d'autres lignes :
# un code que l'un manque, un autre le trouve. Ils ne se lancent que tant qu'il
# manque quelque chose, donc ils ne coûtent rien sur un pli qui se lit d'un coup.
# Au-delà d'une ligne sur trois, zbar ne rend plus rien du tout — mesuré.
BALAYAGES = ((),
             ("-Sx-density=2", "-Sy-density=2"),
             ("-Sx-density=3", "-Sy-density=3"),
             ("-Sx-density=1", "-Sy-density=2"),
             ("-Sx-density=2", "-Sy-density=1"))
EMPREINTE_CAR = 32  # ce que le pli imprime : SHA-256 tronqué


def outil(nom):
    if shutil.which(nom) is None:
        sys.exit("« %s » est introuvable. Installe poppler-utils et zbar-tools." % nom)
    return nom


def lancer(argv, tolere=()):
    """Exécute un outil externe et rend sa sortie. Un code inattendu arrête tout.

    🔑 Sans ce contrôle, un outil qui cède rend une sortie vide, et une sortie
    vide se lit exactement comme « cette page ne porte aucun QR code ». Le
    lecteur conclut alors que le pli est mal numérisé et demande de rescanner
    plus fin — alors que le scan était bon et que c'est lui qui a échoué. Celui
    qui ouvre le pli n'a aucun moyen de faire la différence, et pourrait croire
    le dépôt perdu.

    `zbarimg` rend 4 quand une image ne porte aucun code : c'est le cas normal
    d'une page de garde, il se tolère. Il rend 1 sur une image illisible ou
    absente — panne réelle. Les deux se distinguent, encore faut-il regarder.
    """
    fait = subprocess.run(argv, capture_output=True, text=True)
    if fait.returncode != 0 and fait.returncode not in tolere:
        detail = (fait.stderr or "").strip().split("\n")[0]
        sys.exit("« %s » a échoué (code %d) sur %s.\n"
                 "   Ce n'est pas la numérisation qui est en cause, mais l'outil de "
                 "lecture.%s"
                 % (os.path.basename(argv[0]), fait.returncode, argv[-1],
                    "\n   " + detail if detail else ""))
    return fait.stdout


def rasteriser(source, travail, dpi):
    """Un PDF → ses pages en images, à la résolution demandée."""
    prefixe = "page%d" % dpi
    lancer([outil("pdftoppm"), "-r", str(dpi), "-gray", "-png",
            source, os.path.join(travail, prefixe)])
    return sorted(os.path.join(travail, f) for f in os.listdir(travail)
                  if f.startswith(prefixe) and f.endswith(".png"))


def pages_en_images(source, travail):
    """Rend la liste des images à scruter, qu'on parte d'un PDF ou d'images."""
    if os.path.isdir(source):
        return sorted(os.path.join(source, f) for f in os.listdir(source)
                      if f.lower().endswith((".png", ".jpg", ".jpeg", ".tif", ".tiff", ".pnm")))
    if source.lower().endswith(".pdf"):
        return rasteriser(source, travail, DPI)
    return [source]


def fragments(images, lus=None, options=()):
    """Tous les fragments lus, indexés par (pièce, rang). L'ordre n'importe pas.

    Le rang vit DANS les données : un pli scanné en désordre, ou dont les pages
    ont été mélangées, se reconstitue quand même.

    `lus` permet de verser une seconde lecture dans la première : deux passes du
    même PDF se complètent rang par rang, et deux lectures d'un même rang restent
    comparées comme si elles venaient de deux pages.
    """
    lus, inconnus, divergents = ({} if lus is None else lus), 0, []
    # `\d` désigne en Python TOUS les chiffres d'Unicode : « PLI1|V|١/٣| » se
    # laissait lire, et `int("١")` vaut 1. Le déchiffreur JavaScript, lui, ne
    # reconnaît que `[0-9]` et rendait `null` sur la même ligne.
    motif = re.compile(r"^%s\|([A-Z])\|([0-9]+)/([0-9]+)\|(.*)$" % PREFIXE, re.S)
    for img in images:
        sortie = lancer([outil("zbarimg"), "--raw", "-q"] + list(options) + [img],
                        tolere=(4,))
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
            # Un même rang se lit deux fois quand deux balayages parcourent la
            # même source, ou quand deux tirages sont mêlés dans le même
            # répertoire. Les octets doivent alors coïncider : s'ils divergent,
            # la dernière lue gagnerait en silence, et rien ne dirait laquelle
            # est la bonne.
            if rang in lus[piece]["parts"] and lus[piece]["parts"][rang] != donnees:
                divergents.append("%s%d/%d" % (piece, rang, total))
            lus[piece]["parts"][rang] = donnees
            if lus[piece]["total"] != total:
                sys.exit("Le pli mélange deux tirages : la pièce %s s'annonce tantôt en %d "
                         "QR codes, tantôt en %d." % (piece, lus[piece]["total"], total))
    return lus, inconnus, sorted(set(divergents))


def complet(lus):
    """Les deux pièces sont-elles là, tous rangs présents ?"""
    for piece in PIECES:
        d = lus.get(piece)
        if not d or any(i not in d["parts"] for i in range(1, d["total"] + 1)):
            return False
    return True


def scruter(images, lus=None):
    """Lit toutes les images, et insiste tant qu'il manque un code.

    Un code manquant ne veut pas dire une page abîmée : il peut se dérober à un
    balayage et se rendre au suivant. On ne change donc pas de pages, on change
    de manière de les regarder.

    Les codes étrangers ne se comptent qu'à la première passe : les repasses
    reliraient les mêmes, et le compte doublerait sans que rien de neuf n'ait été
    vu.
    """
    inconnus, divergents, premiere = 0, [], True
    for options in BALAYAGES:
        if options and not complet(lus or {}):
            # Le réglage est nommé : quatre lignes identiques ne disent pas
            # combien de recours ont été tentés avant de renoncer.
            print("  il manque des codes — relecture en %s"
                  % " ".join(o.lstrip("-S") for o in options))
        lus, encore, aussi = fragments(images, lus, options)
        if premiere:
            inconnus, premiere = encore, False
        divergents = sorted(set(divergents) | set(aussi))
        if complet(lus):
            break
    return lus, inconnus, divergents


def empreintes_imprimees(source):
    """Les empreintes annoncées par le pli, si le PDF porte encore sa couche texte.

    Un pli réellement scanné n'en a pas : il faudra alors les donner à la main.
    Ne jamais deviner à leur place — c'est la comparaison qui a de la valeur.

    Le document entier est parcouru, pas sa première page : les empreintes ont
    déménagé le jour où le pli a été scindé en trois parties, et un `-l 1` les a
    silencieusement perdues.
    """
    if not source.lower().endswith(".pdf"):
        return {}
    texte = lancer([outil("pdftotext"), source, "-"])
    trouve = {}
    for piece, motif in (("A", r"^\s*Déchiffreur\s*\n\s*([0-9a-f][0-9a-f ]{30,})"),
                         ("V", r"^\s*Coffre\s*\n\s*([0-9a-f][0-9a-f ]{30,})")):
        m = re.search(motif, texte, re.M | re.I)
        if m:
            trouve[piece] = m.group(1).replace(" ", "").strip()[:EMPREINTE_CAR]
    return trouve


def main():
    a = argparse.ArgumentParser(description="Reconstitue un coffre SelfVault depuis son pli.")
    a.add_argument("source", help="le pli scanné : un PDF, une image, ou un répertoire d'images")
    a.add_argument("-o", "--sortie", default=".", help="où écrire les fichiers reconstitués")
    a.add_argument("--empreinte-app", help="empreinte du déchiffreur, lue sur le pli")
    a.add_argument("--empreinte-coffre", help="empreinte du coffre, lue sur le pli")
    opt = a.parse_args()

    travail = tempfile.mkdtemp(prefix="lire-pli-")
    try:
        images = pages_en_images(opt.source, travail)
        if not images:
            sys.exit("Rien à lire dans « %s »." % opt.source)
        print("▸ %d page(s) à scruter" % len(images))
        lus, inconnus, divergents = scruter(images)

        # Un PDF se relit à une autre résolution tant qu'il manque un code : ce
        # qui se dérobe à une échelle se rend à la suivante. On ne le fait que
        # pour un PDF — un répertoire d'images vient d'un scanner, et lui seul
        # peut le relancer.
        est_pdf = not os.path.isdir(opt.source) and opt.source.lower().endswith(".pdf")
        for secours in (DPI_SECOURS if est_pdf else ()):
            if complet(lus):
                break
            print("  relecture du PDF à %d points par pouce" % secours)
            lus, _, aussi = scruter(rasteriser(opt.source, travail, secours), lus)
            divergents = sorted(set(divergents) | set(aussi))

        if divergents:
            print("\n✗ Deux lectures d'un même QR code ne donnent pas la même chose : %s"
                  % ", ".join(divergents))
            print("   L'une des deux copies est abîmée. Rescanne, ou retire la page douteuse.")
            print("   Rien n'a été écrit.")
            return 1
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
            # Ce lecteur réessaie déjà seul sur un PDF ; sur un répertoire
            # d'images, seule la personne qui tient le scanner le peut.
            print("\n   Renumérise les pages concernées à une AUTRE résolution — %s —"
                  % ", ".join("%d" % d for d in (DPI,) + DPI_SECOURS))
            print("   puis relance. Un code manquant ne veut pas dire un pli abîmé.")
            return 1

        # ── Reconstitution en mémoire, écriture seulement à la fin ───────────
        attendues = empreintes_imprimees(opt.source)
        if attendues:
            print("  empreintes lues sur le pli lui-même")
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
                print("   AUCUNE empreinte de référence — la comparaison annoncée par le pli "
                      "n'a pas eu lieu. Relance avec --empreinte-%s."
                      % ("app" if piece == "A" else "coffre"))
                echec = True
            elif attendues[piece] != court:
                print("✗ %s : empreinte %s, le pli annonce %s"
                      % (PIECES[piece], groupe,
                         " ".join(attendues[piece][i:i + 4] for i in range(0, len(attendues[piece]), 4))))
                echec = True
            else:
                print("✓ %s : %d octets, empreinte %s — concorde avec le pli"
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
