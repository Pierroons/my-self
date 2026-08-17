#!/usr/bin/env python3
"""Génère le PDF schémas 'Mapping SelfRecover ⇄ SelfDataGuard' via WeasyPrint."""
from datetime import datetime
from zoneinfo import ZoneInfo
from pathlib import Path
from weasyprint import HTML
import os
import sys

MOIS = ["janvier","février","mars","avril","mai","juin","juillet","août",
        "septembre","octobre","novembre","décembre"]
now = datetime.now(ZoneInfo("Europe/Paris"))
DATE = f"{now.day} {MOIS[now.month-1]} {now.year} — {now:%H:%M}"
VERSION = "v1.0"
# Dossier de sortie portable : 1er argument CLI, sinon $OUTPUT_DIR, sinon ./out à côté du script.
_OUT_DIR = sys.argv[1] if len(sys.argv) > 1 else os.environ.get(
    "OUTPUT_DIR", os.path.join(os.path.dirname(os.path.abspath(__file__)), "out"))
OUT = Path(_OUT_DIR) / "MySelf - Mapping SelfRecover-SelfDataGuard.pdf"

CSS = """
@page { size:A4; margin:20mm 16mm 18mm 16mm; background:#0c1014;
  @top-right{content:"MySelf · Architecture"; color:#4a5b68; font-size:8pt;}
  @bottom-center{content:counter(page) " / " counter(pages); color:#4a5b68; font-size:8pt;} }
@page :first{ @top-right{content:"";} }
*{box-sizing:border-box;}
body{background:#0c1014; color:#e6edf3; font-family:'DejaVu Sans',sans-serif; font-size:10.2pt; line-height:1.5;}
h1{font-size:20pt; margin:0 0 4pt;}
h2{color:#3fb98c; font-size:13.5pt; margin:18pt 0 6pt; border-bottom:1px solid #243039; padding-bottom:3pt;}
p{margin:0 0 6pt;} strong{color:#fff;} em{color:#cdd9e2;}
code{font-family:'DejaVu Sans Mono',monospace; font-size:8.6pt; background:#1b242d; color:#9ad9bf; padding:1px 4px; border-radius:3px;}
pre{background:#0a0f13; border:1px solid #243039; border-radius:6px; padding:11px 12px;
    font-family:'DejaVu Sans Mono',monospace; font-size:8.2pt; line-height:1.32; color:#cdd9e2; white-space:pre;}
.cover{padding-top:55mm;}
.cover .kicker{color:#3fb98c; font-size:10pt; letter-spacing:2px; text-transform:uppercase;}
.cover h1{font-size:25pt; margin:10pt 0; line-height:1.15;}
.cover .sub{color:#9aa9b6; font-size:12pt; max-width:140mm;}
.cover .meta{margin-top:22mm; color:#6b7d8c; font-size:9pt; border-top:1px solid #243039; padding-top:8pt;}
.cover .meta b{color:#9ad9bf;}
.box{border-radius:7px; padding:10px 13px; margin:8pt 0; font-size:9.6pt;}
.box.warn{background:rgba(212,160,86,.10); border:1px solid rgba(212,160,86,.4);}
.box.ok{background:rgba(63,185,140,.10); border:1px solid rgba(63,185,140,.4);}
.box .bt{font-weight:bold; display:block; margin-bottom:3pt;}
.box.warn .bt{color:#d4a056;} .box.ok .bt{color:#3fb98c;}
.cap{color:#6b7d8c; font-size:8.4pt; margin:2pt 0 10pt;}
table{width:100%; border-collapse:collapse; margin:8pt 0; font-size:9.2pt;}
th{background:#1b242d; color:#9ad9bf; text-align:left; padding:6px 8px; border:1px solid #243039;}
td{padding:6px 8px; border:1px solid #243039; vertical-align:top;}
.glo dt{color:#3fb98c; font-weight:bold; margin-top:7pt; font-size:9.6pt;}
.glo dd{margin:1pt 0 0; color:#cdd9e2;}
.pb{page-break-before:always;}
"""

BODY = """
<div class="cover">
  <div class="kicker">MySelf · Note d'architecture</div>
  <h1>Mapping<br>SelfRecover ⇄ SelfDataGuard</h1>
  <div class="sub">Un seul secret racine, une primitive de dérivation partagée, des clés filles
  cloisonnées. Comment l'accès et le chiffrement des données reposent sur la même fondation —
  sans jamais partager la même clé.</div>
  <div class="meta">
    <b>Principe&nbsp;:</b> SelfRecover = racine de confiance · SelfDataGuard = consommateur de clés<br>
    <b>Document&nbsp;:</b> {{VERSION}} — généré le {{DATE}} (Europe/Paris)<br>
    <b>Écosystème&nbsp;:</b> Self-Security (Recover · DataGuard · KeyGuard · Guard)
  </div>
</div>

<div class="pb"></div>
<h2>1. Le principe en une page</h2>
<p>Retrouver un <strong>accès</strong> et chiffrer une <strong>donnée</strong> reposent sur le même
geste&nbsp;: <em>dériver une clé d'un secret mémorisé, par domaine, sans dépôt central du secret.</em>
On factorise donc <strong>une primitive commune</strong>, et on en tire des <strong>clés filles
séparées par étiquette</strong> (HKDF). Règle d'or&nbsp;: <strong>jamais la même clé pour
l'authentification et pour le chiffrement</strong> — le serveur voit l'auth, il ne doit jamais
pouvoir déchiffrer.</p>
<div class="box ok"><span class="bt">Rôles</span>
<strong>SelfRecover</strong> gère le secret racine et sait en dériver des clés (avec récupération sans
email). <strong>SelfDataGuard</strong> consomme ces clés pour chiffrer en bout-en-bout. La
<strong>récupération est unifiée</strong>&nbsp;: le même mot/passphrase de secours rend l'accès ET
les données.</div>

<h2>2. Schéma A — l'arbre de dérivation (côté navigateur)</h2>
<pre>
   SECRET RACINE (mémorisé par l'humain)
   +------------------+-----------------------+
   |  password         |  passphrase diceware |   2 secrets :
   |  (login)          |  (récupération)      |   l'un fort, l'autre TRÈS fort
   +--------+----------+-----------+----------+
            |   KDF lent (PBKDF2 / Argon2, salé)
            v                      v
       master_key            recover_key
            |  HKDF                 |  HKDF
   +--------+--------+              |
   v        v        v              v
auth_hash data-enc (futurs)   data-recover
   |        |                       |
   |        +----------+   +--------+
   v                   v   v
(-> serveur,       déballe VAULT_KEY
 prouve l'accès)   (la vraie clé du coffre)
</pre>
<p class="cap">Les étiquettes HKDF distinctes ("auth", "data-enc", "data-recover") garantissent que
connaître l'une ne révèle pas les autres.</p>

<h2>3. Schéma B — le coffre et ses deux enveloppes</h2>
<pre>
  mémo en clair --chiffré par--> VAULT_KEY --> [ blob chiffré ]
                                     |
                +--------------------+--------------------+
        wrap par data-enc (password)        wrap par data-recover (passphrase)
                |                                         |
                v                                         v
          [ enveloppe A ]                          [ enveloppe B ]
</pre>
<p>Pour lire&nbsp;: ouvrir <strong>A</strong> avec le password <em>ou</em> <strong>B</strong> avec la
passphrase de secours. Changer de password&nbsp;= re-fabriquer la seule enveloppe A&nbsp;; le blob ne
bouge pas. Cette indirection (<code>vault_key</code>) découple l'accès des données.</p>

<h2>4. Schéma C — la frontière de confiance</h2>
<pre>
  NAVIGATEUR (zone de confiance)        |   SERVEUR (aveugle)
  ------------------------------        |   --------------------
  - password / passphrase               |   - auth_hash       (opaque)
  - master_key, recover_key  (éphém.)   |   - blob chiffré    (opaque)
  - data-enc, data-recover              |   - enveloppe A     (opaque)
  - vault_key (déballée à la volée)     |   - enveloppe B     (opaque)
  - mémo EN CLAIR                       |
                                        |   root ici = QUE des blobs
  ======================================+==========================
            seul l'auth_hash franchit la ligne -->
</pre>
<div class="box ok"><span class="bt">Conséquence</span>
Un attaquant qui prend le contrôle du serveur récupère des blobs et un hash d'authentification.
<strong>Rien de déchiffrable</strong> sans le secret de l'utilisateur, qui n'a jamais quitté son
navigateur.</div>

<div class="pb"></div>
<h2>5. Sécurité adaptative — la force du secret suit la sensibilité</h2>
<p>On n'impose pas le même effort pour des photos de vacances et pour un RIB. Principe&nbsp;: rendre
l'attaque <strong>plus chère que la valeur de la cible</strong>. On module la longueur du secret de
secours (en mots diceware EFF, ~13 bits par mot) selon la donnée à protéger&nbsp;:</p>
<table>
  <tr><th>Sensibilité</th><th>Secret de secours</th><th>Entropie</th><th>Résiste à…</th></tr>
  <tr><td>Public / jetable</td><td>chiffrement inutile</td><td>—</td><td>—</td></tr>
  <tr><td>Faible (photos perso banales)</td><td>3 mots diceware</td><td>~39 bits</td><td>l'opportuniste, le dump de masse</td></tr>
  <tr><td>Moyenne (docs perso, finances)</td><td>4–5 mots</td><td>~52–65 bits</td><td>un attaquant motivé hors-ligne</td></tr>
  <tr><td>Critique (RIB, médical, pro)</td><td>6 mots <em>+ quorum</em></td><td>~77 bits +</td><td>un adversaire déterminé / étatique</td></tr>
</table>
<div class="box warn"><span class="bt">Plancher à respecter</span>
Un <strong>seul mot du dictionnaire</strong> (~16 bits) reste trop faible, même pour du peu sensible&nbsp;:
le coût d'ajouter un 2ᵉ ou 3ᵉ mot est minime, le gain est multiplicatif (chaque mot ≈ ×7776).
Plancher conseillé&nbsp;: <strong>3 mots</strong>.</div>

<h2>6. Pourquoi le secret de secours doit résister HORS-LIGNE</h2>
<p>On pourrait croire qu'un secret faible est protégé par le blocage anti-bruteforce de SelfRecover.
C'est vrai <strong>en ligne</strong> (chaque essai passe par le serveur, qui ralentit et détecte).
Mais notre modèle de menace suppose que <strong>le serveur peut être compromis et le blob volé</strong>.</p>
<div class="box warn"><span class="bt">Le point décisif</span>
Dès que l'attaquant <strong>possède une copie du blob chiffré</strong>, il l'attaque <strong>hors-ligne</strong>,
chez lui, à sa vitesse&nbsp;: <strong>plus aucun blocage, aucune détection</strong>. Il « a toute la
liste devant lui ». Le KDF lent ne fait que multiplier le temps par essai&nbsp;: il achète des ordres
de grandeur, il ne sauve pas un secret à faible entropie. C'est pourquoi <code>data-recover</code> —
le wrap volable — exige une passphrase à forte entropie, pas un mot court.</div>
<p>Rappel essentiel&nbsp;: la solidité d'un secret dérivé tient à l'<strong>entropie de l'entrée</strong>
(le nombre de secrets possibles), <em>pas</em> à la taille du hash de sortie. Un hash de 256 bits
calculé sur 676 entrées possibles reste cassable en 676 essais.</p>

<h2>Glossaire</h2>
<dl class="glo">
  <dt>KDF — Key Derivation Function</dt>
  <dd>Transforme un secret en clé, lentement et avec un sel, pour rendre chaque tentative coûteuse
  (ex. PBKDF2, Argon2).</dd>
  <dt>HKDF</dt>
  <dd>Dérive plusieurs sous-clés indépendantes d'une même clé mère, via des étiquettes distinctes.
  C'est ce qui sépare « auth » de « chiffrement ».</dd>
  <dt>vault_key (clé de coffre)</dt>
  <dd>Clé aléatoire qui chiffre réellement la donnée&nbsp;; elle est elle-même chiffrée (wrappée) par
  les clés dérivées du secret. Permet de changer de password sans re-chiffrer la donnée.</dd>
  <dt>Wrap (enveloppe)</dt>
  <dd>Chiffrer une clé avec une autre clé. Ici&nbsp;: deux enveloppes de la même vault_key (password
  + passphrase de secours).</dd>
  <dt>Entropie</dt>
  <dd>Mesure du nombre de secrets possibles. Plus elle est haute, plus le bruteforce est long.
  Exprimée en bits&nbsp;: +1 bit = ×2 d'efforts pour l'attaquant.</dd>
  <dt>Diceware</dt>
  <dd>Méthode de passphrase formée de mots tirés au hasard d'une liste (EFF&nbsp;: 7776 mots).
  Chaque mot ajoute ~13 bits d'entropie.</dd>
</dl>
"""

html = ("<!DOCTYPE html><html lang='fr'><head><meta charset='utf-8'>"
        "<style>"+CSS+"</style></head><body>"
        + BODY.replace("{{DATE}}",DATE).replace("{{VERSION}}",VERSION)
        + "</body></html>")
OUT.parent.mkdir(parents=True, exist_ok=True)
HTML(string=html).write_pdf(str(OUT))
print("PDF généré :", OUT)
print("Taille :", OUT.stat().st_size, "octets")
