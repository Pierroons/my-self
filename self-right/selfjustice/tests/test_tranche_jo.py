#!/usr/bin/env python3
"""Garde-fou — un traité se découpe juste dans le Journal officiel, et le défi anti-robot se dit.

🔑 Les traités arrivent par CELLAR dans un seul numéro du Journal officiel, qui
les publie à la suite avec leurs protocoles, leurs intertitres et ses en-têtes
de page. Un découpage trop large fait déborder un traité sur le suivant ; un
filtrage trop lâche colle « TITRE II LIBERTÉS » à la fin d'un article — c'est
ce que la base servait pour sept articles de la Charte quand elle venait
d'EUR-Lex. Rien de tout cela ne change un compte d'articles : seul le texte le
montre.

Sans réseau : un Journal officiel fabriqué au modèle du vrai, et un serveur
local qui répond comme le défi d'AWS WAF.

    python3 tests/test_tranche_jo.py
"""

import http.server
import sys
import threading
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "tools"))
import build_eu_db as b  # noqa: E402

JO = """<?xml version="1.0" encoding="UTF-8"?>
<html><body>
<p class="hd-oj">Journal officiel de l'Union européenne C 202/1</p>
<p class="doc-ti">VERSIONS CONSOLIDÉES</p>
<p class="doc-ti">DU TRAITÉ SUR L'UNION EUROPÉENNE ET DU TRAITÉ SUR LE FONCTIONNEMENT</p>
<p class="doc-ti">TRAITÉ SUR L'UNION EUROPÉENNE (VERSION CONSOLIDÉE)</p>
<div>
<p class="ti-section-1">TITRE I</p><p class="ti-section-2">DISPOSITIONS COMMUNES</p>
<p class="ti-art">Article premier</p><p class="normal">Les Hautes Parties instituent l'Union.</p>
<p class="ti-art">Article 2</p><p class="normal">L'Union est fondée sur le respect de la dignité humaine.</p>
<p class="ti-section-1">TITRE II</p><p class="ti-section-2">DISPOSITIONS RELATIVES AUX PRINCIPES</p>
<p class="hd-date">7.6.2016</p>
<p class="ti-art">Article 3</p><p class="normal">L'Union se dote d'institutions.</p>
<p class="normal">EN FOI DE QUOI, les plénipotentiaires soussignés ont apposé leurs signatures.</p>
<p class="normal">Fait à Lisbonne.</p>
</div>
<p class="doc-ti">TRAITÉ SUR LE FONCTIONNEMENT DE L'UNION EUROPÉENNE (VERSION CONSOLIDÉE)</p>
<div>
<p class="ti-art">Article premier</p><p class="normal">Le présent traité organise le fonctionnement.</p>
<p class="ti-art">Article 2</p><p class="normal">Domaine d'application des traités.</p>
</div>
<p class="doc-ti">PROTOCOLES</p>
<div><p class="ti-art">Article premier</p><p class="normal">Statut de la Cour.</p></div>
</body></html>
"""

TUE = (r"TRAITÉ SUR L['’]UNION EUROPÉENNE \(VERSION CONSOLIDÉE\)", r"TRAITÉ SUR LE FONCTIONNEMENT")
TFUE = (r"TRAITÉ SUR LE FONCTIONNEMENT", r"PROTOCOLES?\b")

echecs = []


def verdict(ok, libelle):
    print(("  ✓ " if ok else "  ✗ ") + libelle)
    if not ok:
        echecs.append(libelle)


def articles(tranche, source):
    return {a["num"]: a["texte"] for a in b.parse_articles(b.tranche_jo(JO.encode(), *tranche), source)}


class Defi(http.server.BaseHTTPRequestHandler):
    appels = 0

    def do_GET(self):
        Defi.appels += 1
        self.send_response(202)
        self.send_header("x-amzn-waf-action", "challenge")
        self.send_header("Content-Length", "0")
        self.end_headers()

    def log_message(self, *a):
        pass


def main():
    print("▸ Un traité, ses articles, et rien d'autre")
    tue = articles(TUE, "TUE")
    verdict(sorted(tue) == ["1", "2", "3"], f"TUE : articles 1 à 3, sans ceux du TFUE ({sorted(tue)})")
    verdict("dignité humaine" in tue.get("2", ""), "TUE art. 2 porte son texte")
    verdict("TITRE" not in tue.get("2", "") and "PRINCIPES" not in tue.get("2", ""),
            "l'intertitre qui suit l'art. 2 ne s'y colle pas")
    verdict("7.6.2016" not in tue.get("3", ""), "l'en-tête de page ne se colle pas à l'art. 3")
    verdict("EN FOI DE QUOI" not in tue.get("3", "") and "Lisbonne" not in tue.get("3", ""),
            "la formule de signature n'appartient pas au dernier article")

    tfue = articles(TFUE, "TFUE")
    verdict(sorted(tfue) == ["1", "2"], f"TFUE : articles 1 et 2, sans le protocole ({sorted(tfue)})")
    verdict("Statut de la Cour" not in " ".join(tfue.values()), "le protocole ne déborde pas sur le TFUE")

    print("\n▸ Un titre absent fait échouer la source, il ne rend pas un traité vide")
    verdict(b.tranche_jo(JO.encode(), r"CHARTE DES DROITS FONDAMENTAUX", r"AVIS AU LECTEUR") == "",
            "titre de début introuvable → \"\"")

    print("\n▸ Le défi anti-robot se dit, il ne se rejoue pas")
    serveur = http.server.HTTPServer(("127.0.0.1", 0), Defi)
    threading.Thread(target=serveur.serve_forever, daemon=True).start()
    debut = time.monotonic()
    rendu = b.fetch_url(f"http://127.0.0.1:{serveur.server_port}/", max_retries=3)
    duree = time.monotonic() - debut
    serveur.shutdown()
    verdict(rendu == b"", "le défi rend un contenu vide")
    verdict(Defi.appels == 1 and duree < 2,
            f"une seule requête, sans attente ({Defi.appels} requête(s), {duree:.1f} s)")

    print()
    if echecs:
        print("✗ %d contrôle(s) en échec." % len(echecs))
        return 1
    print("✓ Le découpage du Journal officiel tient, et le défi anti-robot se dit.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
