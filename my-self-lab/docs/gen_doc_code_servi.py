#!/usr/bin/env python3
"""Génère le PDF pédagogique 'Faille du code servi (E2E web)' via WeasyPrint."""
from datetime import datetime
from zoneinfo import ZoneInfo
from pathlib import Path
from weasyprint import HTML
import os
import sys

MOIS = ["janvier", "février", "mars", "avril", "mai", "juin", "juillet",
        "août", "septembre", "octobre", "novembre", "décembre"]
now = datetime.now(ZoneInfo("Europe/Paris"))
DATE = f"{now.day} {MOIS[now.month-1]} {now.year} — {now:%H:%M}"
VERSION = "v1.0"

# Dossier de sortie portable : 1er argument CLI, sinon $OUTPUT_DIR, sinon ./out à côté du script.
_OUT_DIR = sys.argv[1] if len(sys.argv) > 1 else os.environ.get(
    "OUTPUT_DIR", os.path.join(os.path.dirname(os.path.abspath(__file__)), "out"))
OUT = Path(_OUT_DIR) / "MySelf-Lab - Faille du code servi (E2E web).pdf"

CSS = """
@page {
  size: A4; margin: 22mm 18mm 20mm 18mm;
  background: #0c1014;
  @top-right { content: "MySelf · Sécurité"; color: #4a5b68; font-size: 8pt; }
  @bottom-center { content: counter(page) " / " counter(pages); color: #4a5b68; font-size: 8pt; }
}
@page :first { @top-right { content: ""; } }
* { box-sizing: border-box; }
body { background:#0c1014; color:#e6edf3; font-family:'DejaVu Sans',sans-serif;
       font-size:10.3pt; line-height:1.55; }
h1 { color:#e6edf3; font-size:20pt; margin:0 0 4pt; }
h2 { color:#3fb98c; font-size:14pt; margin:20pt 0 6pt; border-bottom:1px solid #243039; padding-bottom:4pt; }
h3 { color:#9ad9bf; font-size:11.5pt; margin:13pt 0 4pt; }
p { margin:0 0 7pt; }
a { color:#6cb6ff; }
strong { color:#fff; }
em { color:#cdd9e2; }
code { font-family:'DejaVu Sans Mono',monospace; font-size:9pt; background:#1b242d;
       color:#9ad9bf; padding:1px 4px; border-radius:3px; }
pre { background:#0a0f13; border:1px solid #243039; border-radius:6px; padding:11px 13px;
      font-family:'DejaVu Sans Mono',monospace; font-size:8.4pt; line-height:1.45;
      color:#cdd9e2; white-space:pre; overflow:hidden; }
ul,ol { margin:0 0 7pt; padding-left:17pt; }
li { margin-bottom:3pt; }
.cover { text-align:left; padding-top:60mm; }
.cover .kicker { color:#3fb98c; font-size:10pt; letter-spacing:2px; text-transform:uppercase; }
.cover h1 { font-size:26pt; margin:10pt 0; line-height:1.15; }
.cover .sub { color:#9aa9b6; font-size:12pt; max-width:140mm; }
.cover .meta { margin-top:24mm; color:#6b7d8c; font-size:9pt; border-top:1px solid #243039;
               padding-top:8pt; }
.cover .meta b { color:#9ad9bf; }
.box { border-radius:7px; padding:10px 13px; margin:9pt 0; font-size:9.8pt; }
.box.warn { background:rgba(212,160,86,.10); border:1px solid rgba(212,160,86,.4); }
.box.danger { background:rgba(217,100,89,.10); border:1px solid rgba(217,100,89,.4); }
.box.ok { background:rgba(63,185,140,.10); border:1px solid rgba(63,185,140,.4); }
.box .bt { font-weight:bold; display:block; margin-bottom:3pt; }
.box.warn .bt { color:#d4a056; } .box.danger .bt { color:#d96459; } .box.ok .bt { color:#3fb98c; }
table { width:100%; border-collapse:collapse; margin:9pt 0; font-size:9.3pt; }
th { background:#1b242d; color:#9ad9bf; text-align:left; padding:6px 9px; border:1px solid #243039; }
td { padding:6px 9px; border:1px solid #243039; vertical-align:top; }
.glo dt { color:#3fb98c; font-weight:bold; margin-top:8pt; font-size:10pt; }
.glo dd { margin:1pt 0 0; padding-left:0; color:#cdd9e2; }
.tag { font-family:'DejaVu Sans Mono',monospace; font-size:8.5pt; padding:1px 6px;
       border-radius:9px; border:1px solid; }
.t-red { color:#d96459; border-color:#d96459; } .t-grn { color:#3fb98c; border-color:#3fb98c; }
.small { font-size:8.6pt; color:#9aa9b6; }
.pagebreak { page-break-before: always; }
"""

BODY = """
<div class="cover">
  <div class="kicker">MySelf · Note de sécurité</div>
  <h1>La faille du code servi</h1>
  <div class="sub">Pourquoi le chiffrement de bout en bout dans un navigateur ne survit pas
  à un serveur compromis qui <em>persiste</em> — et comment réduire, voire supprimer, ce risque.</div>
  <div class="meta">
    <b>Sujet&nbsp;:</b> RCE + persistance + altération du code livré → piège des futures connexions<br>
    <b>Profil de menace&nbsp;:</b> APT (présence durable), à distinguer du « smash &amp; grab » (ransomware)<br>
    <b>Document&nbsp;:</b> {{VERSION}} — généré le {{DATE}} (Europe/Paris)<br>
    <b>Contexte&nbsp;:</b> écosystème MySelf — module SelfDataGuard, vitrine MySelf-Lab
  </div>
</div>

<div class="pagebreak"></div>

<h2>1. Le point de départ : la promesse du E2E web</h2>
<p>Le <strong>chiffrement de bout en bout</strong> (E2E) côté navigateur consiste à chiffrer la donnée
<em>dans le navigateur de l'utilisateur</em>, avec une clé dérivée d'un secret que lui seul connaît
(son mot de passe / sa passphrase). Le serveur ne reçoit alors que des <strong>blobs chiffrés</strong> :
il stocke sans jamais voir le contenu en clair, ni la clé.</p>
<p>La promesse est forte : même si quelqu'un vole la base de données, il n'obtient que du charabia.
C'est exactement ce qu'on veut opposer à un vol de disque, un dump SQL via injection, ou un backup
dérobé. On parle de protection des données <strong>au repos</strong> (<code>at-rest</code>).</p>
<div class="box ok"><span class="bt">Ce que le E2E web gagne réellement</span>
Contre un attaquant qui entre, copie la base et repart : il ne récupère que des blobs illisibles.
C'est le profil typique d'une attaque par rançongiciel (<code>ransomware</code>) ou d'une exfiltration
rapide. Sur ce terrain, le E2E fait le travail.</div>

<h2>2. La faille : le navigateur fait confiance au code qu'on lui envoie</h2>
<p>Voici le détail que presque personne ne voit au premier abord. Quand tu ouvres l'application,
ton navigateur <strong>télécharge le code</strong> (HTML + JavaScript) depuis le serveur, puis
<strong>l'exécute aussitôt</strong>. C'est ce code, et lui seul, qui réalise le chiffrement E2E.</p>
<p>Or le navigateur n'a <strong>aucun moyen natif</strong> de savoir si ce code est « le bon ». Il
exécute ce qu'on lui sert, point. Le chiffrement de bout en bout déplace donc la confiance :</p>
<ul>
  <li>Avant : « je fais confiance au serveur pour <em>stocker</em> mes données. »</li>
  <li>Après E2E : « je fais confiance au serveur pour me <em>livrer un code honnête</em> qui chiffre. »</li>
</ul>
<p>La confiance n'a pas disparu — elle a changé de place. Et c'est là que loge la faille :
<strong>si l'attaquant contrôle durablement le serveur, il contrôle le code livré</strong>. Il peut
remplacer le module de chiffrement par une version piégée qui capture le secret au moment où
l'utilisateur le tape.</p>
<div class="box danger"><span class="bt">L'idée en une phrase</span>
Le E2E protège la donnée déjà chiffrée et posée sur le disque. Il ne protège pas le
<em>moment</em> où la clé est fabriquée dans le navigateur — si le code qui la fabrique a été
remplacé par du code malveillant servi par un serveur compromis.</div>

<h2>3. Le scénario, étape par étape</h2>
<p>Déroulons une intrusion concrète. L'attaquant a trouvé une exécution de code à distance
(<code>RCE</code>) — par exemple via une dépendance vulnérable ou une faille applicative.</p>
<pre>
 T0  RCE obtenue        L'attaquant peut exécuter du code sur le serveur.

 T1  Smash & grab       Il dumpe la base de données.
                        -> il n'obtient que des blobs E2E. ILLISIBLES.        [E2E gagne]

 T2  Décision : rester  Au lieu de repartir, il PERSISTE (webshell, tâche
                        planifiée, modification d'un fichier servi...).

 T3  Backdoor du code   Il modifie le JavaScript de l'app servi au navigateur.
                        Il ajoute, au moment du login :
                            envoyer(passphrase) -> https://serveur-attaquant/collecte

 T4  Login légitime     L'utilisateur se connecte NORMALEMENT. Tout marche.
                        Mais sa passphrase part aussi chez l'attaquant.

 T5  Game over          Avec la passphrase, l'attaquant dérive la clé et
                        déchiffre TOUT : le passé (les blobs de T1) et le futur.
</pre>
<p>Remarque cruciale : à <strong>T4</strong>, rien ne semble anormal côté utilisateur. La page
fonctionne, le mot de passe est accepté, les données s'affichent. Le chiffrement E2E est, sur le
papier, « intact » — sauf que l'attaquant a intercepté la clé <em>à la source</em>, avant même
qu'elle serve à chiffrer.</p>

<h2>4. Smash &amp; grab vs APT : deux mondes différents</h2>
<p>Tout repose sur cette distinction. Le E2E web bat l'un et pas l'autre.</p>
<table>
  <tr><th style="width:18%"></th><th>« Smash &amp; grab »</th><th>APT (menace persistante)</th></tr>
  <tr><td><strong>Comportement</strong></td>
      <td>Entre, copie, chiffre/part vite.</td>
      <td>Entre, reste caché, observe, agit dans la durée.</td></tr>
  <tr><td><strong>Cible</strong></td>
      <td>Données au repos (base, fichiers).</td>
      <td>Le flux vivant : sessions, code servi, secrets tapés.</td></tr>
  <tr><td><strong>Exemple</strong></td>
      <td>Rançongiciel de masse.</td>
      <td>Espionnage, vol ciblé prolongé.</td></tr>
  <tr><td><strong>Le E2E web&nbsp;?</strong></td>
      <td><span class="tag t-grn">protège</span> il ne récupère que des blobs.</td>
      <td><span class="tag t-red">ne protège pas seul</span> il peut piéger le code.</td></tr>
</table>
<div class="box warn"><span class="bt">Pourquoi c'est rassurant malgré tout</span>
La grande majorité des incidents médiatisés (rançongiciels, fuites de bases) sont des
« smash &amp; grab ». Le E2E les neutralise. L'APT capable de backdoorer le code servi demande un
attaquant plus déterminé, plus bruyant, et qui <em>reste</em> — ce qui ouvre des fenêtres de détection.</div>

<h2>5. Le fond du problème : « Trusting Trust »</h2>
<p>Cette faille n'est pas un bug à corriger : c'est une <strong>limite structurelle</strong>. À un
moment, il faut bien faire confiance à du code qu'on n'a pas écrit ni vérifié soi-même. Le E2E web
ne supprime pas cette confiance, il la <em>déplace</em> vers « le serveur me livre un code honnête ».</p>
<p>C'est l'idée développée par Ken Thompson dans sa conférence <em>Reflections on Trusting Trust</em>
(remise du prix Turing, 1984) : on ne peut pas avoir une confiance totale dans un code que l'on n'a
pas intégralement produit et vérifié de la base au sommet. Appliqué au web : tant que <strong>ton
serveur</strong> livre le code de crypto à chaque visite, un serveur compromis = un code potentiellement
compromis.</p>

<h2>6. Cas réels</h2>
<p>Ce principe n'a rien de théorique. Deux exemples documentés l'illustrent, à deux échelles.</p>
<div class="box danger"><span class="bt">Stuxnet (2010) — le principe à l'échelle étatique</span>
Ce sabotage des centrifugeuses d'enrichissement iraniennes s'insérait entre le logiciel de
programmation industriel et l'automate cible&nbsp;: il <strong>modifiait le code envoyé à la machine
tout en renvoyant aux opérateurs des relevés normaux</strong> — l'intermédiaire piégé qui ment,
exactement comme l'étape T4. Il allait plus loin&nbsp;: il avait <strong>volé des certificats de
signature légitimes</strong> pour faire passer son code pour du code de confiance, ce qui subvertit
le remède (d) « code signé ». Leçon&nbsp;: une signature ne vaut que si la clé qui signe est, elle,
restée sûre.</div>
<div class="box danger"><span class="bt">Magecart / formjacking — le mécanisme exact, sur le web</span>
Famille d'attaques qui <strong>injecte du JavaScript malveillant dans le code servi</strong> par un
site, pour capturer ce que l'utilisateur saisit au moment où il le tape. Cas emblématique&nbsp;:
la compromission du site de British Airways en 2018, où le code injecté volait les numéros de carte
à la frappe (sanctionnée par l'autorité britannique de protection des données). C'est
<strong>littéralement</strong> le scénario T3→T4 de ce document — seule la donnée volée change
(carte bancaire au lieu de passphrase).</div>
<div class="box warn"><span class="bt">Fausse bonne idée&nbsp;: « se re-vérifier soi-même »</span>
On pense souvent à forcer le code à se re-contrôler périodiquement (toutes les X secondes). Le piège&nbsp;:
si c'est le composant compromis — ou le serveur compromis qui le sert — qui réalise la vérification,
il <strong>ment</strong> et se déclare authentique. Re-vérifier plus souvent contre une source
empoisonnée ne fait qu'empoisonner plus souvent. La vérification n'a de valeur que si elle vient d'un
<strong>ancrage de confiance extérieur</strong> au composant vérifié (puce TPM/HSM via attestation à
distance, registre public, client natif signé). À ne pas confondre avec la ré-authentification de
session (utile, mais elle traite le vol de session, pas le code servi).</div>

<h2>7. Comment combler — du plus efficace au plus marginal</h2>
<p>On classe par impact réel. La vérité honnête : pour une application <em>web pure</em>, on
<strong>réduit</strong> fortement le risque ; pour le <strong>supprimer</strong>, il faut sortir la
crypto du flux web.</p>

<h3>a) Empêcher le RCE en amont — le vrai remède (90 % du combat)</h3>
<p>Pas de prise de contrôle = pas de code piégé. C'est, de loin, la meilleure défense. Concrètement :
dépendances à jour, moindre privilège du process, <code>webroot</code> en lecture seule,
désactivation des fonctions dangereuses (<code>disable_functions</code>), pare-feu applicatif,
<strong>isolation forte (la VM cloisonnée)</strong>. Toute la « défense en profondeur » sert ici.</p>

<h3>b) Immutabilité &amp; instances éphémères — efface la persistance</h3>
<p>Si le serveur est reconstruit régulièrement depuis une image <strong>immuable</strong> (et restauré
depuis un <em>snapshot</em> propre après chaque test), la persistance de l'attaquant ne survit pas :
le code piégé est écrasé par la version saine. Une <strong>VM jetable qu'on restaure</strong> est
exactement cette stratégie. C'est la réponse la plus pragmatique pour MySelf-Lab.</p>

<h3>c) Surveillance d'intégrité (FIM / HIDS)</h3>
<p>Surveiller que les fichiers servis (le bundle JavaScript) ne changent pas hors déploiement
officiel : on stocke des empreintes (hashes) connues et on alerte à toute modification (outils type
AIDE, Tripwire). Ça ne <em>bloque</em> pas l'attaque, mais ça la <strong>détecte</strong> — ce qui,
face à un APT qui mise sur la discrétion, est précieux.</p>

<h3>d) Sortir la crypto du code servi — ce qui SUPPRIME la faille</h3>
<p>La seule façon d'éliminer vraiment le problème : ne plus laisser le serveur web livrer le code de
chiffrement à chaque visite. On le déplace dans une <strong>application native</strong> ou une
<strong>extension de navigateur</strong>, installée une fois, <strong>signée</strong>, et mise à jour
par un canal contrôlé (magasin d'applications, signature vérifiée). Le serveur web ne peut alors plus
altérer la crypto à la volée. C'est précisément le modèle adopté par les gestionnaires de secrets
sérieux (apps de bureau / mobiles / extensions). Coût&nbsp;: élevé — c'est un autre produit.</p>

<h3>e) Subresource Integrity (SRI) — utile mais ne couvre pas CE cas</h3>
<p><code>SRI</code> permet d'attacher à une ressource (un fichier JS) une empreinte que le navigateur
vérifie avant exécution. <strong>Limite à comprendre</strong> : l'empreinte est écrite dans le HTML…
lui-même servi par ton serveur. Un serveur compromis change le JS <em>et</em> son empreinte en même
temps. SRI protège contre une <em>ressource tierce</em> (un CDN) compromise, <strong>pas contre ton
propre serveur</strong> compromis. À ne pas confondre.</p>

<h3>f) Builds reproductibles &amp; transparence — la voie « haute confiance »</h3>
<p>Permettre à un tiers de vérifier que le code servi correspond <em>exactement</em> au code source
public (builds reproductibles), éventuellement journalisé dans un registre public infalsifiable
(logique de « transparency log »). Très robuste, mais lourd à mettre en place — réservé aux projets
à fort enjeu.</p>

<div class="box ok"><span class="bt">Synthèse des remèdes</span>
Pour une web app : <strong>(a) empêcher le RCE</strong> + <strong>(b) instance éphémère/restaurée</strong>
+ <strong>(c) détection d'intégrité</strong> couvrent l'immense majorité du risque.
Pour l'<strong>immunité totale</strong> de la confidentialité, il faut <strong>(d) un client natif/signé</strong>.</div>

<h2>8. Application concrète à MySelf-Lab</h2>
<p>La stratégie déjà retenue répond bien au problème, sans sur-ingénierie :</p>
<ul>
  <li><strong>VM cloisonnée + restauration depuis snapshot</strong> après chaque test → la persistance
  d'un attaquant ne survit pas (remède b). Un éventuel code piégé est effacé au retour à l'état propre.</li>
  <li><strong>Pare-feu VM ↛ réseau local</strong> → même rootée, la VM est un cul-de-sac : aucun pivot
  vers l'infrastructure réelle.</li>
  <li><strong>Durcissement</strong> du serveur (moindre privilège, webroot en lecture seule, fonctions
  dangereuses désactivées) → fait monter le coût d'un RCE (remède a).</li>
  <li><strong>E2E sur les données sensibles</strong> (mémo, messages privés) → neutralise le scénario
  « smash &amp; grab » / rançongiciel, qui est le plus fréquent et le plus médiatisé.</li>
</ul>
<div class="box warn"><span class="bt">Le bon « claim », honnête et défendable</span>
« Vos données sensibles sont chiffrées de bout en bout : un vol ou un dump de la base ne livre que
des blobs. La persistance d'un attaquant est traitée par l'isolation, le durcissement et
l'éphémérité de l'instance. L'immunité totale, y compris contre l'altération du code servi, relèverait
d'un client natif signé — hors périmètre de cette vitrine. » <span class="small">Dire cela, c'est
être plus crédible qu'un concurrent qui promet l'invulnérabilité absolue.</span></div>

<div class="pagebreak"></div>
<h2>Glossaire</h2>
<dl class="glo">
  <dt>RCE — Remote Code Execution</dt>
  <dd>Exécution de code à distance : l'attaquant parvient à faire tourner <em>son</em> code sur le
  serveur. La prise de contrôle la plus grave côté serveur.</dd>

  <dt>Persistance</dt>
  <dd>Capacité de l'attaquant à <em>rester</em> dans le système après l'intrusion initiale (porte
  dérobée, tâche planifiée, fichier modifié), pour y revenir ou agir dans la durée.</dd>

  <dt>Backdoor (porte dérobée)</dt>
  <dd>Mécanisme caché, ajouté par l'attaquant, lui permettant un accès ou une action discrète —
  ici, du code injecté dans le JavaScript pour voler le secret.</dd>

  <dt>APT — Advanced Persistent Threat</dt>
  <dd>Menace persistante avancée : un attaquant déterminé qui s'installe durablement et discrètement,
  par opposition à une attaque rapide et opportuniste.</dd>

  <dt>Smash &amp; grab</dt>
  <dd>Littéralement « casser et rafler ». Intrusion rapide : on entre, on copie/chiffre, on repart.
  Profil typique des rançongiciels.</dd>

  <dt>E2E — End-to-End Encryption (chiffrement de bout en bout)</dt>
  <dd>La donnée est chiffrée et déchiffrée uniquement aux extrémités (chez l'utilisateur). Les
  intermédiaires, dont le serveur, ne voient que du chiffré.</dd>

  <dt>At-rest / in-transit / in-use</dt>
  <dd>Trois états de la donnée : <em>au repos</em> (stockée), <em>en transit</em> (sur le réseau),
  <em>en cours d'usage</em> (en mémoire, en clair, au moment du traitement). Le E2E web protège bien
  l'« au repos » mais expose l'« en cours d'usage » si le code est piégé.</dd>

  <dt>Bundle JS</dt>
  <dd>Le paquet de code JavaScript que le serveur envoie au navigateur et que celui-ci exécute pour
  faire fonctionner l'application (y compris la crypto).</dd>

  <dt>KDF — Key Derivation Function (dérivation de clé)</dt>
  <dd>Fonction qui transforme un mot de passe en clé de chiffrement robuste (ex. Argon2, PBKDF2).
  Coûteuse à dessein, pour ralentir les attaques par force brute.</dd>

  <dt>SRI — Subresource Integrity</dt>
  <dd>Empreinte attachée à une ressource pour que le navigateur refuse de l'exécuter si elle a été
  modifiée. Protège contre une ressource tierce altérée, pas contre ton propre serveur compromis.</dd>

  <dt>FIM / HIDS — File Integrity Monitoring / Host Intrusion Detection</dt>
  <dd>Surveillance qui alerte quand des fichiers sensibles changent de façon inattendue (AIDE,
  Tripwire). Sert à <em>détecter</em> une altération du code servi.</dd>

  <dt>Reproducible build (build reproductible)</dt>
  <dd>Compilation déterministe : à partir du même code source, on obtient un binaire/bundle
  identique, vérifiable par un tiers. Permet de prouver que le code servi = le code source public.</dd>

  <dt>Supply chain (chaîne d'approvisionnement logicielle)</dt>
  <dd>L'ensemble des dépendances et outils utilisés pour construire un logiciel. Une faille dans une
  dépendance peut ouvrir un RCE — d'où l'importance de les tenir à jour.</dd>

  <dt>Defense in depth (défense en profondeur)</dt>
  <dd>Empiler plusieurs couches de protection indépendantes, pour qu'une seule faille ne suffise pas
  à tout compromettre.</dd>

  <dt>Infrastructure immuable / éphémère</dt>
  <dd>Serveurs jamais modifiés en place : on les reconstruit depuis une image saine et on les
  remplace régulièrement. Toute altération de l'attaquant est effacée au remplacement.</dd>

  <dt>Trusting Trust</dt>
  <dd>Principe (Ken Thompson, 1984) : on ne peut pas avoir une confiance absolue dans un code qu'on
  n'a pas intégralement produit et vérifié soi-même. La confiance se déplace, elle ne disparaît jamais.</dd>

  <dt>TOFU — Trust On First Use</dt>
  <dd>« Confiance à la première utilisation » : on accepte une clé/un code la première fois, puis on
  vérifie qu'il ne change pas ensuite. Modèle pragmatique, mais vulnérable si la première fois est
  déjà piégée.</dd>

  <dt>Hidden service (.onion)</dt>
  <dd>Service exposé via le réseau Tor sans IP publique ni port ouvert : la connexion se fait par
  rendez-vous chiffré. Utilisé ici pour exposer la vitrine sans révéler l'adresse de l'hôte.</dd>

  <dt>WAF — Web Application Firewall</dt>
  <dd>Pare-feu spécialisé qui filtre les requêtes web malveillantes avant qu'elles n'atteignent
  l'application.</dd>

  <dt>Moindre privilège</dt>
  <dd>N'accorder à chaque composant que les droits strictement nécessaires : limite ce qu'un
  attaquant peut faire après une prise de contrôle.</dd>
</dl>

<h2 class="small" style="border:none;color:#6b7d8c">Référence</h2>
<p class="small">Ken Thompson, <em>Reflections on Trusting Trust</em>, Communications of the ACM,
vol. 27, n°8, août 1984 (conférence de remise du prix Turing). Concept fondateur de la confiance
dans le code non vérifié.</p>
"""

html = ("<!DOCTYPE html><html lang='fr'><head><meta charset='utf-8'>"
        "<style>" + CSS + "</style></head><body>"
        + BODY.replace("{{DATE}}", DATE).replace("{{VERSION}}", VERSION)
        + "</body></html>")

OUT.parent.mkdir(parents=True, exist_ok=True)
HTML(string=html).write_pdf(str(OUT))
print("PDF généré :", OUT)
print("Taille :", OUT.stat().st_size, "octets")
