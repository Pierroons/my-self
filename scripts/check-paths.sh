#!/bin/bash
# Contrôle des chemins cités — un chemin écrit quelque part a-t-il encore sa cible ?
#
# 🔑 **Pourquoi ce script existe.** Une référence dont la cible a disparu ne casse
# pas : elle échoue de deux façons. Bruyamment — l'allowlist OPSEC qui cite un
# fichier renommé fait rougir l'audit, on corrige, cas confortable. Silencieusement
# — une règle `.gitignore` qui cite un chemin déplacé cesse d'ignorer, et le
# fichier qu'elle protégeait entre au prochain `git add -A`. Rien ne bloque, rien
# n'avertit.
#
# Le rangement du 17/08/2026 a produit les deux cas dans la même heure. Le second
# visait un document de mission pentest, une base de démonstration et deux
# artefacts cryptographiques, sur un dépôt public.
#
# Usage : bash scripts/check-paths.sh
# Sortie : 0 si tout résout, 1 sinon.

set -uo pipefail

# ⚠️ `cd "$(git rev-parse --show-toplevel)" || exit 1` ne gardait rien : hors
# dépôt, la substitution est vide et `cd ""` rend 0 en bash. Les `git ls-files`
# rendaient vide ensuite, et les quatre blocs annonçaient ✓ après n'avoir lu
# aucun fichier. Le code de sortie ne le disait pas non plus — il valait 1, mais
# parce que la copie de licence manquait, pas parce que rien n'avait été lu.
RACINE="$(git rev-parse --show-toplevel)" || exit 1
[ -n "$RACINE" ] || { echo "✗ hors dépôt git — aucun périmètre à contrôler" >&2; exit 1; }
cd "$RACINE" || exit 1

echec=0

echo "▸ Chemins cités — liens et ancres Markdown"
# 🔑 **Deux contrôles se partageaient ce travail, et chacun était aveugle à ce que
# l'autre regardait.** Le premier n'acceptait qu'un chemin relatif ou l'une de
# neuf extensions, et sa classe `[^)#]` excluait le `#` : tout lien ancré sortait
# de son périmètre. Le second ne regardait QUE les liens ancrés, par son
# `"#" not in cible`. Entre les deux, aucun lien vers un `.js`, un `.css`, un
# `.conf`, un `.service` ni vers un répertoire n'était vérifié — `](client/sr-derive.js)`
# et `](api/)` passaient sans être lus.
#
# Le second portait déjà la bonne expression et le bon test d'existence : les
# fusionner ne demandait que de retirer sa condition d'entrée. Le périmètre passe
# de 29 ancres à 291 liens, sans un mort de plus — mesuré avant d'écrire.
#
# ⚠️ L'ordre des opérations de `github-slugger` n'est pas intuitif et un seul
# écart fabrique des faux positifs : `trim()` s'applique AVANT la suppression de
# la ponctuation, jamais après. `## Facteur « cet appareil »` produit donc
# `facteur--cet-appareil-`, avec son tiret final — le retirer condamnait un lien
# parfaitement valide, et un garde-fou qui crie à tort finit désactivé.
python3 - <<'PY' || echec=1
import re, pathlib, subprocess, sys, collections

def slug(titre):
    t = re.sub(r'\[([^\]]*)\]\([^)]*\)', r'\1', titre)   # le TEXTE du lien, pas sa cible
    t = re.sub(r'[`*_~]', '', t).lower().strip()
    t = re.sub(r'[^\w\s-]', '', t, flags=re.UNICODE)
    return t.replace(' ', '-')

def fragments(p):
    vus, out = collections.Counter(), set()
    txt = sans_blocs(p.read_text(errors="ignore"))
    for ligne in txt.splitlines():
        m = re.match(r'\s{0,3}#{1,6}\s+(.*)', ligne)
        if not m: continue
        s = slug(m.group(1))
        if not s: continue
        # GitHub suffixe les doublons : -1, -2, …
        out.add(s if not vus[s] else f"{s}-{vus[s]}")
        vus[s] += 1
    for m in re.finditer(r'<a\s+(?:id|name)=["\']([^"\']+)["\']', txt):
        out.add(m.group(1).lower())
    return out

def sans_blocs(txt):
    # Un « # commentaire » dans un bloc shell n'est pas un titre Markdown.
    # Les sauts de ligne sont conservés pour ne pas décaler la lecture.
    return re.sub(r'```.*?```', lambda m: '\n' * m.group(0).count('\n'), txt, flags=re.S)

def prose(txt):
    # ⚠️ Un lien CITÉ n'est pas un lien. Le CHANGELOG de ce dépôt écrit
    # `](#section)` entre backticks pour EXPLIQUER ce contrôle — les lire comme
    # des liens le faisait rougir sur sa propre documentation.
    #
    # 🔑 Ce retrait du code inline ne vaut QUE pour chercher les liens. Des
    # titres du dépôt contiennent du code inline (`## 6. \`--keyfile-size\` est une parade…`) :
    # l'appliquer aussi à l'extraction des titres amputait leur slug et condamnait
    # des liens valides. `slug()` retire les backticks et garde leur contenu, ce
    # que fait GitHub.
    return re.sub(r'`[^`\n]*`', '', sans_blocs(txt))

morts, ancres_ok, fichiers_ok, cache = [], 0, 0, {}
for f in subprocess.run(["git","ls-files","*.md"],capture_output=True,text=True).stdout.split():
    p = pathlib.Path(f)
    for m in re.finditer(r'\]\(([^)\s]+)\)', prose(p.read_text(errors="ignore"))):
        cible = m.group(1)
        if cible.startswith(("http", "mailto", "#!")): continue
        chemin, _, frag = cible.partition("#")
        # ⚠️ Un lien ancré à la racine du dépôt se résout depuis elle. `p.parent / "/x"`
        # rend un chemin absolu SYSTÈME, qui n'existerait jamais et sortirait en faux
        # mort. Aucune occurrence aujourd'hui — mais le périmètre vient de décupler.
        if chemin.startswith("/"): dest = pathlib.Path(chemin.lstrip("/"))
        elif chemin == "":         dest = p
        else:                      dest = p.parent / chemin
        if not dest.exists():
            morts.append(f"{f} → {cible}  (fichier absent)"); continue
        fichiers_ok += 1
        if not frag: continue
        if dest.suffix != ".md": continue
        if dest not in cache: cache[dest] = fragments(dest)
        if frag.lower() in cache[dest]: ancres_ok += 1
        else: morts.append(f"{f} → {cible}")

# Le contre-témoin fait partie du verdict : sans lui, « 0 mort » ne distingue pas
# « tout résout » de « le motif n'a rien trouvé à regarder ».
if morts:
    print(f"  ✗ {len(morts)} lien(s) mort(s) sur {len(morts) + fichiers_ok} vérifié(s)")
    for x in morts: print("     " + x)
    sys.exit(1)
print(f"  ✓ les {fichiers_ok} liens vérifiés résolvent, dont {ancres_ok} ancre(s)")
PY

echo "▸ Chemins cités — code inline"
# 🔑 **Un chemin se cite aussi hors d'un lien.** « Copier `deploy/nginx-bi-self.conf` »
# guide l'installateur autant qu'un lien, et les deux contrôles au-dessus ne lisent
# que les `](…)` : l'audit du 27/09/2026 a trouvé cinq chemins morts qu'ils rendaient
# verts. Ne comptent que les chemins du dépôt — premier segment connu du dépôt ou du
# dossier du fichier —, et trois familles sont écartées parce qu'elles ne sont pas
# des défauts : l'histoire (un CHANGELOG cite ce qui existait), ce que git ignore
# (un fichier créé à l'exécution), et les exceptions nommées ci-dessous avec leur raison.
python3 - <<'PY' || echec=1
import re, pathlib, subprocess, sys
EXCEPTIONS = {
    "scripts/local-top/": "chemin de l'initramfs du système, pas du dépôt",
    ".github/pull_request_template.md": "lu par les agents s'il existe, absent le plus souvent",
}
suivis = subprocess.run(["git", "ls-files"], capture_output=True, text=True).stdout.split()
tete = {p.split("/")[0] for p in suivis}
def ignore(chemin):
    return subprocess.run(["git", "check-ignore", "-q", "--no-index", str(chemin)]).returncode == 0
morts, lus = [], 0
for f in subprocess.run(["git", "ls-files", "*.md"], capture_output=True, text=True).stdout.split():
    if re.search(r"(^|/)CHANGELOG\.md$", f): continue
    p = pathlib.Path(f)
    texte = re.sub(r"```.*?```", lambda m: "\n" * m.group(0).count("\n"), p.read_text(errors="ignore"), flags=re.S)
    for n, ligne in enumerate(texte.splitlines(), 1):
        for m in re.finditer(r"`([A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_.-]+)+/?)`", ligne):
            t = m.group(1)
            if any(t.startswith(e) for e in EXCEPTIONS): continue
            if t.split("/")[0] not in tete and not (p.parent / t.split("/")[0]).exists(): continue
            lus += 1
            candidats = [p.parent / t, pathlib.Path(t)]
            if any(c.exists() for c in candidats) or any(ignore(c) for c in candidats): continue
            morts.append(f"{f}:{n} → {t}")
if morts:
    print(f"  ✗ {len(morts)} chemin(s) mort(s) sur {lus} cité(s) en code inline")
    for x in morts: print("     " + x)
    sys.exit(1)
print(f"  ✓ les {lus} chemins du dépôt cités en code inline résolvent")
PY

echo "▸ Chemins cités — règles .gitignore"
# Une règle peut légitimement viser ce qui n'existe pas encore (/vendor/, /tmp/).
# Le signal n'est donc pas « la cible manque » mais « la cible manque ET un
# fichier du même nom existe ailleurs » — c'est-à-dire : elle a été déplacée.
python3 - <<'PY' || echec=1
import pathlib, subprocess, sys
suspects, examinees = [], 0
tous = {p.as_posix() for p in pathlib.Path(".").rglob("*") if ".git/" not in p.as_posix()}
for gi in subprocess.run(["git","ls-files","*.gitignore",".gitignore"],capture_output=True,text=True).stdout.split():
    base = pathlib.Path(gi).parent
    for n, line in enumerate(pathlib.Path(gi).read_text().splitlines(), 1):
        s = line.strip()
        if not s or s.startswith(("#", "!")) or "/" not in s.rstrip("/"): continue
        # Outillage régénérable : un `vendor/` existe forcément ailleurs sans que
        # la règle soit orpheline pour autant.
        if s.strip("/") in {"vendor", "node_modules", ".venv", "tmp", "cache", ".phpunit.cache"}: continue
        cible = s.lstrip("/").split("*")[0].rstrip("/")
        if not cible: continue
        examinees += 1
        if (base / cible).exists(): continue
        feuille = cible.rsplit("/", 1)[-1]
        if feuille and any(t.endswith("/" + feuille) for t in tous):
            # 🔑 Le discriminant qui manquait, et sans lui ce contrôle rougit
            # sur son fonctionnement normal. Une cible absente est le cas
            # ORDINAIRE d'une règle efficace : le fichier vit sur la machine et
            # n'est pas versionné, donc il ne se trouve pas dans un clone frais.
            # La CI n'en voit jamais aucun. Le 20/08/2026, `scripts/deploy.sh`
            # et `demo/lab/data/` — deux règles qui protègent des fichiers bien
            # présents — ont fait échouer `main` pour cette seule raison.
            # Un déplacement, lui, laisse une trace : git a connu ce chemin.
            # Jamais suivi = garde-fou préventif, pas orphelin.
            connu = subprocess.run(
                ["git", "log", "--all", "--oneline", "-1", "--", (base / cible).as_posix()],
                capture_output=True, text=True).stdout.strip()
            if not connu:
                continue
            suspects.append(f"{gi}:{n}  {s}  (a existé dans l'historique, "
                            "et une cible de ce nom existe ailleurs)")
if suspects:
    print("  ✗ " + str(len(suspects)) + " règle(s) probablement orpheline(s)")
    for x in suspects: print("     " + x)
    sys.exit(1)
print(f"  ✓ aucune règle orpheline sur {examinees} règle(s) examinée(s)")
PY

echo "▸ Chemins cités — workflows GitHub"
python3 - <<'EOF' || echec=1
import pathlib, re, sys
morts, examines = [], 0
rep = pathlib.Path(".github/workflows")
for wf in sorted(list(rep.glob("*.yml")) + list(rep.glob("*.yaml"))):
    for n, line in enumerate(wf.read_text().splitlines(), 1):
        m = re.match(r"\s*(context|working-directory|dockerfile|file):\s*(\S+)", line)
        if not m: continue
        chemin = m.group(2).strip("'\"")
        if chemin.startswith("$") or chemin == ".": continue
        examines += 1
        if not pathlib.Path(chemin).exists():
            morts.append(f"{wf}:{n}  {m.group(1)}: {chemin}")
if morts:
    print("  \u2717 " + str(len(morts)) + " chemin(s) mort(s)")
    for x in morts: print("     " + x)
    sys.exit(1)
print(f"  \u2713 les {examines} chemin(s) de workflow résolvent")
EOF

# ── Copies qui doivent rester identiques ────────────────────────────────────
#
# 🔑 Un texte de licence se copie — c'est un document légal verbatim, et l'AGPL
# demande qu'une copie accompagne chaque programme distribué. Un paquet Python
# publié doit donc porter le sien. Mais deux copies peuvent diverger, et
# personne ne relit 34 000 octets : la duplication n'est acceptable que
# surveillée.
#
# ⚠️ Un lien symbolique aurait évité la copie. Il casse la construction : la
# sdist le stocke tel quel, et l'extraction refuse un lien pointant hors de
# l'archive. Mesuré le 21/08/2026.
echo "▸ Copies verbatim — le texte de licence"
# Chaque paquet distribuable indépendamment porte sa copie ; la liste grandit
# avec eux.
COPIES_LICENCE=(
    self-right/selfjustice/mcp/LICENSE
)
for copie in "${COPIES_LICENCE[@]}"; do
    if [ ! -f "$copie" ]; then
        echo "  ✗ $copie manque — le paquet publié n'emporterait pas sa licence" >&2
        echec=1
    elif cmp -s LICENSE "$copie"; then
        echo "  ✓ $copie identique à LICENSE"
    else
        echo "  ✗ $copie a divergé de LICENSE" >&2
        echec=1
    fi
done

exit $echec
