#!/bin/bash
# Contrôle des versions — chaque module dit-il partout la même version, et cette version a-t-elle son tag ?
#
# 🔑 **Pourquoi ce script existe.** Jusqu'au 27/09/2026, la version d'un module n'existait qu'en texte,
# recopiée à la main dans ses badges, son pilier, le README racine et les pages servies, et le tag signé
# était un geste à part que rien ne réclamait. L'audit du 27/09 a trouvé SelfJustice annoncée en v0.4.0
# avec un seul tag, v0.1.0 ; SelfAct en trois versions selon le fichier, sans aucun tag. `modules.json`
# est désormais la source unique : ce script vérifie que chaque porteur qu'il déclare la répète, et que
# la version annoncée a été publiée.
#
# Usage : bash scripts/check-versions.sh                        les porteurs disent la version de modules.json
#         bash scripts/check-versions.sh --tags                 chaque version a son tag <id>-v<version>
#         bash scripts/check-versions.sh --publications         chaque version est publiée, et ce qui est
#                                                               déclaré retiré ne sert plus (aucun secret dédié)
#         bash scripts/check-versions.sh --artefacts-orphelins  aucun paquet du compte n'est publié au nom
#                                                               de ce dépôt sans être déclaré (gh authentifié)
# Sortie : 0 si tout concorde, 1 sinon, 2 si modules.json est illisible ou si un point n'a pas pu être établi.
#
# 🔑 **Pourquoi les deux derniers modes existent.** L'image ghcr de SelfRecover est restée figée en v0.4.0
# du 28/07 au 28/09/2026 : le rangement du 18/08 avait supprimé le Dockerfile et le workflow qui la
# construisaient, et quatre tags sont passés ensuite sans que rien ne le dise. Les porteurs de version
# vivant hors du dépôt échappent au grep : ces deux modes vont les demander au registre.
#
# En mode --tags, une version sans tag est signalée en jaune, puis en rouge au bout de
# VERSIONS_DELAI_JOURS jours (7 par défaut), comptés depuis le jour où modules.json porte cette version.

set -uo pipefail
cd "$(git rev-parse --show-toplevel)" || exit 2

mode="${1:-porteurs}"
case "$mode" in
  porteurs|--tags|--publications|--artefacts-orphelins) ;;
  *) echo "usage : $0 [--tags|--publications|--artefacts-orphelins]" >&2; exit 2 ;;
esac

MODE="$mode" DELAI="${VERSIONS_DELAI_JOURS:-7}" python3 - <<'PY'
import json, os, re, subprocess, sys, time, urllib.error, urllib.parse, urllib.request

def lire_manifeste(texte):
    donnees = json.loads(texte)
    modules = donnees["modules"]
    ids = [m["id"] for m in modules]
    if len(ids) != len(set(ids)):
        raise ValueError("identifiant de module en double")
    for m in modules:
        v = m.get("version")
        if v is not None and not re.fullmatch(r"\d+\.\d+\.\d+", v):
            raise ValueError(f"{m['id']} : version « {v} » hors du format X.Y.Z")
    return modules

try:
    texte_manifeste = open("modules.json", encoding="utf-8").read()
    modules = lire_manifeste(texte_manifeste)
except (OSError, ValueError, KeyError, json.JSONDecodeError) as e:
    print(f"  ✗ modules.json illisible : {e}")
    sys.exit(2)

versionnes = [m for m in modules if m.get("version")]
retires = json.loads(texte_manifeste).get("artefacts_retires", [])
maintenant = time.time()


def depuis_quand(ident, version):
    """Horodatage du plus ancien commit depuis lequel modules.json porte cette version, sans interruption.
    Une version présente seulement dans l'arbre de travail date de maintenant."""
    tete = subprocess.run(["git", "show", "HEAD:modules.json"], capture_output=True, text=True)
    def version_dans(texte):
        try:
            return next((m.get("version") for m in lire_manifeste(texte) if m["id"] == ident), None)
        except (ValueError, KeyError, json.JSONDecodeError):
            return None
    if tete.returncode != 0 or version_dans(tete.stdout) != version:
        return maintenant
    journal = subprocess.run(["git", "log", "--format=%H %ct", "--", "modules.json"],
                             capture_output=True, text=True).stdout.split("\n")
    depuis = maintenant
    for ligne in filter(None, journal):
        sha, quand = ligne.split()
        texte = subprocess.run(["git", "show", f"{sha}:modules.json"], capture_output=True, text=True).stdout
        if version_dans(texte) != version:
            break
        depuis = int(quand)
    return depuis


if os.environ["MODE"] == "porteurs":
    print("▸ Versions — chaque porteur dit la version de modules.json")
    ecarts, lus = [], 0
    for m in versionnes:
        for fichier, marque in m["porteurs"]:
            if not os.path.isfile(fichier):
                ecarts.append(f"{m['id']} : porteur absent — {fichier}")
                continue
            lignes = [(n, l) for n, l in enumerate(open(fichier, encoding="utf-8", errors="replace"), 1) if marque in l]
            if not lignes:
                # Une marque disparue rendrait le porteur invisible au contrôle : on le dit.
                ecarts.append(f"{m['id']} : marque « {marque} » introuvable dans {fichier}")
                continue
            for n, l in lignes:
                lus += 1
                trouve = re.search(r"\d+\.\d+\.\d+", l[l.index(marque) + len(marque):])
                if not trouve or trouve.group(0) != m["version"]:
                    dit = trouve.group(0) if trouve else "aucune version"
                    ecarts.append(f"{fichier}:{n} — {m['nom']} y est en {dit}, modules.json dit {m['version']}")
    # Une marque ne lit que la première version qui la suit, et seulement sur sa ligne. Dans un
    # fichier porteur, toute ligne de tableau ou de badge qui nomme un module et porte une version
    # doit donc être déclarée, et chacune de ses versions doit être celle d'un des modules qu'elle
    # nomme : le texte alternatif d'un badge précède sa marque, et une ligne de tableau non déclarée
    # n'est lue par personne. Les noms sont bornés, pour que SelfRecover ne se lise pas dans
    # SelfRecover-LUKS. Dans un fichier qui ne porte qu'un module, un badge qui n'en nomme aucun
    # est le sien : c'est le badge « Status » d'un README de module.
    par_nom = {m["nom"]: m for m in versionnes}
    nom_de_module = re.compile(r"(?<![-\w])(?:" + "|".join(
        re.escape(nom) for nom in sorted(par_nom, key=len, reverse=True)) + r")(?![-\w])")
    signalees = {e.split(" — ")[0] for e in ecarts}
    for fichier in sorted({f for m in versionnes for f, _ in m["porteurs"] if os.path.isfile(f)}):
        marques = [marque for m in versionnes for f, marque in m["porteurs"] if f == fichier]
        proprietaires = sorted({m["nom"] for m in versionnes for f, _ in m["porteurs"] if f == fichier})
        for n, l in enumerate(open(fichier, encoding="utf-8", errors="replace"), 1):
            if not (l.lstrip().startswith("|") or "img.shields.io/badge" in l):
                continue
            nommes = sorted(set(nom_de_module.findall(l)))
            if not nommes and len(proprietaires) == 1 and "img.shields.io/badge" in l:
                nommes = proprietaires
            versions = re.findall(r"(?<![\d.])\d+\.\d+\.\d+(?![\d.])", l)
            if not nommes or not versions or f"{fichier}:{n}" in signalees:
                continue
            attendues = {par_nom[nom]["version"] for nom in nommes}
            for v in versions:
                if v not in attendues:
                    ecarts.append(f"{fichier}:{n} — {', '.join(nommes)} y est en {v}, modules.json dit "
                                  + ", ".join(sorted(attendues)))
            if not any(marque in l for marque in marques):
                ecarts.append(f"{fichier}:{n} — {', '.join(nommes)} y porte une version, mais la ligne "
                              "n'est déclarée sous aucune marque de modules.json")
    if ecarts:
        print(f"  ✗ {len(ecarts)} écart(s)")
        for e in ecarts:
            print("     " + e)
        sys.exit(1)
    print(f"  ✓ {lus} porteur(s) de {len(versionnes)} module(s) disent la version de modules.json")
    sys.exit(0)


def depot_github():
    """owner/repo, lu sur le remote plutôt qu'écrit en dur."""
    url = subprocess.run(["git", "remote", "get-url", "origin"], capture_output=True, text=True).stdout.strip()
    trouve = re.search(r"[:/]([^/:]+/[^/]+?)(?:\.git)?$", url)
    return trouve.group(1) if trouve else None


def http(url, entetes=None):
    """(code, corps). Le code 0 dit « je n'ai pas pu demander » — il ne vaut jamais « absent ».
    Seul https est suivi : ces URL sont bâties sur des valeurs lues dans modules.json, et urllib
    ouvrirait tout aussi volontiers un file:// qu'une adresse."""
    if not url.startswith("https://"):
        return 0, b""
    try:
        with urllib.request.urlopen(urllib.request.Request(url, headers=entetes or {}), timeout=15) as r:
            return r.status, r.read()
    except urllib.error.HTTPError as e:
        return e.code, b""
    except (urllib.error.URLError, TimeoutError, OSError):
        return 0, b""


def jeton_github():
    """Le jeton de la CI, sinon celui de gh. Sans lui, l'API anonyme plafonne à 60 requêtes par heure
    et rend 403 : le contrôle se déclarerait alors indéterminé à longueur de journée, ce qu'on finit
    par ne plus lire."""
    depuis_env = os.environ.get("GITHUB_TOKEN") or os.environ.get("GH_TOKEN")
    if depuis_env:
        return depuis_env
    gh = subprocess.run(["gh", "auth", "token"], capture_output=True, text=True)
    return gh.stdout.strip() if gh.returncode == 0 else ""


def release_publiee(depot, tag):
    entetes = {"Accept": "application/vnd.github+json", "User-Agent": "check-versions"}
    jeton = jeton_github()
    if jeton:
        entetes["Authorization"] = f"Bearer {jeton}"
    chemin = urllib.parse.quote(f"{depot}/releases/tags/{tag}", safe="/")
    code, _ = http(f"https://api.github.com/repos/{chemin}", entetes)
    return code


FORME_REFERENCE = re.compile(r"[a-z0-9]+(?:[._-][a-z0-9]+)*(?:/[a-z0-9]+(?:[._-][a-z0-9]+)*)+\Z")


def interroger_ghcr(reference):
    """(« sert » | « absent » | « inconnu », tags) — ce que le registre donne à un anonyme, sans secret.
    Il établit ce que le PUBLIC obtient, non ce que le compte détient : supprimé, passé en privé ou
    jamais publié, le registre refuse le jeton de lecture de la même façon (403), et celui qui voudrait
    tirer l'image se heurte au même refus. Ce que le compte détient encore se mesure par
    --artefacts-orphelins, qui demande un gh authentifié."""
    if not FORME_REFERENCE.match(reference):
        return "malformée", []
    code, corps = http(f"https://ghcr.io/token?scope=repository:{reference}:pull&service=ghcr.io")
    if code in (401, 403, 404):
        return "absent", []
    if code != 200:
        return "inconnu", []
    try:
        jeton = json.loads(corps)["token"]
    except (ValueError, KeyError):
        return "inconnu", []
    code, corps = http(f"https://ghcr.io/v2/{reference}/tags/list", {"Authorization": f"Bearer {jeton}"})
    if code in (401, 403, 404):
        return "absent", []
    if code != 200:
        return "inconnu", []
    try:
        return "sert", json.loads(corps).get("tags") or []
    except ValueError:
        return "inconnu", []


# --publications
if os.environ["MODE"] == "--publications":
    print("▸ Publications — ce que ce dépôt publie hors de lui")
    delai = int(os.environ["DELAI"])
    depot = depot_github()
    if not depot:
        print("  ✗ aucun dépôt GitHub lisible sur le remote origin")
        sys.exit(2)
    rouges, jaunes, muets, conformes = [], [], [], 0

    # Chaque version courante a sa release. Les tags anciens ne sont pas regardés : une release
    # qu'on n'a pas faite en son temps ne se rattrape pas, et la crier chaque matin la rendrait muette.
    for m in versionnes:
        attendu = f"{m['id']}-v{m['version']}"
        code = release_publiee(depot, attendu)
        if code == 200:
            conformes += 1
        elif code == 404:
            jours = int((maintenant - depuis_quand(m["id"], m["version"])) // 86400)
            ligne = f"{m['nom']} v{m['version']} : pas de release {attendu}, annoncée depuis {jours} jour(s)"
            (rouges if jours >= delai else jaunes).append(ligne)
        else:
            muets.append(f"release {attendu} : l'API GitHub a rendu {code or 'rien'}")

    # Une image déclarée porte la version annoncée.
    for m in (m for m in versionnes if m.get("ghcr")):
        etat, tags = interroger_ghcr(m["ghcr"])
        if etat == "malformée":
            muets.append(f"ghcr {m['ghcr']} : référence de forme inattendue, pas interrogée")
        elif etat == "inconnu":
            muets.append(f"ghcr {m['ghcr']} : le registre n'a pas répondu")
        elif etat == "sert" and f"v{m['version']}" in tags:
            conformes += 1
        else:
            jours = int((maintenant - depuis_quand(m["id"], m["version"])) // 86400)
            porte = ", ".join(tags) if etat == "sert" else "ne sert plus rien au public"
            ligne = (f"{m['nom']} v{m['version']} : ghcr {m['ghcr']} — {porte}, "
                     f"annoncée depuis {jours} jour(s)")
            (rouges if jours >= delai else jaunes).append(ligne)

    # Un artefact déclaré retiré ne répond plus. Sans délai : une résurrection n'est pas un retard.
    for a in retires:
        if a.get("canal") != "ghcr":
            muets.append(f"{a.get('canal')} {a.get('reference')} : canal que ce contrôle ne sait pas interroger")
            continue
        etat, _ = interroger_ghcr(a["reference"])
        if etat == "malformée":
            muets.append(f"ghcr {a['reference']} : référence de forme inattendue, pas interrogée")
        elif etat == "sert":
            rouges.append(f"ghcr {a['reference']} : déclaré retiré le {a['date']}, sert encore le public")
        elif etat == "absent":
            conformes += 1
        else:
            muets.append(f"ghcr {a['reference']} : le registre n'a pas répondu")

    attendus = len(versionnes) + sum(1 for m in versionnes if m.get("ghcr")) + len(retires)
    print(f"  ✓ {conformes} publication(s) conforme(s) sur {attendus}")
    for j in jaunes:
        print(f"  • {j} — à publier avant {delai} jours")
    for x in muets:
        print(f"  ? {x}")
    if rouges:
        print(f"  ✗ {len(rouges)} publication(s) en défaut")
        for r in rouges:
            print("     " + r)
        sys.exit(1)
    # Un point qu'on n'a pas pu établir n'est pas un point vert.
    sys.exit(2 if muets else 0)


# --artefacts-orphelins
if os.environ["MODE"] == "--artefacts-orphelins":
    print("▸ Publications — un artefact de ce dépôt que plus personne ne déclare")
    depot = depot_github()
    if not depot:
        print("  ✗ aucun dépôt GitHub lisible sur le remote origin")
        sys.exit(2)
    inventaire = subprocess.run(
        ["gh", "api", "user/packages?package_type=container", "--jq", '.[] | [.name, .repository.full_name] | @tsv'],
        capture_output=True, text=True)
    if inventaire.returncode != 0:
        # La sortie de gh n'est pas recopiée : elle peut porter un chemin local ou un nom de compte,
        # et le journal d'un dépôt public se lit de partout, le jour où ce mode y tournerait.
        print(f"  ? l'inventaire du compte demande un gh authentifié en read:packages (gh a rendu "
              f"{inventaire.returncode}) — pour voir pourquoi : gh api user/packages?package_type=container")
        sys.exit(2)

    declares = {m["ghcr"] for m in modules if m.get("ghcr")}
    orphelins, reconnus = [], 0
    for ligne in filter(None, inventaire.stdout.split("\n")):
        champs = ligne.split("\t")
        nom, rattache = champs[0], (champs[1] if len(champs) > 1 else "")
        if rattache != depot:
            continue  # un paquet d'un dépôt voisin a son propre contrôle
        if any(d.split("/")[-1] == nom for d in declares):
            reconnus += 1
        else:
            orphelins.append(f"{nom} : publié au nom de {rattache}, déclaré dans aucun module")
    print(f"  ✓ {reconnus} artefact(s) déclaré(s)")
    if orphelins:
        print(f"  ✗ {len(orphelins)} artefact(s) que rien ne revendique")
        for o in orphelins:
            print("     " + o)
        sys.exit(1)
    sys.exit(0)


# --tags
print("▸ Versions — chaque version annoncée a son tag")
delai = int(os.environ["DELAI"])
tags = set(subprocess.run(["git", "tag", "-l"], capture_output=True, text=True).stdout.split())

rouges, jaunes, publies = [], [], 0
for m in versionnes:
    attendu = f"{m['id']}-v{m['version']}"
    if attendu in tags or any(t.startswith(attendu + "-") for t in tags):
        publies += 1
        continue
    jours = int((maintenant - depuis_quand(m["id"], m["version"])) // 86400)
    ligne = f"{m['nom']} v{m['version']} : pas de tag {attendu}, annoncée depuis {jours} jour(s)"
    (rouges if jours >= delai else jaunes).append(ligne)

print(f"  ✓ {publies} version(s) sur {len(versionnes)} ont leur tag")
for j in jaunes:
    print(f"  • {j} — à taguer avant {delai} jours")
if rouges:
    print(f"  ✗ {len(rouges)} version(s) annoncée(s) depuis {delai} jours ou plus sans tag")
    for r in rouges:
        print("     " + r)
    sys.exit(1)
sys.exit(0)
PY
