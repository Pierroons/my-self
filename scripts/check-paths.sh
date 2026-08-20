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
cd "$(git rev-parse --show-toplevel)" || exit 1

echec=0

echo "▸ Chemins cités — liens Markdown"
python3 - <<'PY' || echec=1
import re, pathlib, subprocess, sys
morts = []
for f in subprocess.run(["git","ls-files","*.md"],capture_output=True,text=True).stdout.split():
    p = pathlib.Path(f)
    for m in re.finditer(r'\]\((\.{0,2}/[^)#]+|[A-Za-z0-9_][^):#]*\.(?:md|html|php|sh|json|ya?ml|docx|pdf))\)', p.read_text(errors="ignore")):
        t = m.group(1)
        if t.startswith(("http", "mailto")): continue
        if not (p.parent / t).exists(): morts.append(f"{f} → {t}")
if morts:
    print("  ✗ " + str(len(morts)) + " lien(s) mort(s)")
    for m in morts: print("     " + m)
    sys.exit(1)
print("  ✓ tous les liens résolvent")
PY

echo "▸ Chemins cités — règles .gitignore"
# Une règle peut légitimement viser ce qui n'existe pas encore (/vendor/, /tmp/).
# Le signal n'est donc pas « la cible manque » mais « la cible manque ET un
# fichier du même nom existe ailleurs » — c'est-à-dire : elle a été déplacée.
python3 - <<'PY' || echec=1
import pathlib, subprocess, sys
suspects = []
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
print("  ✓ aucune règle orpheline détectée")
PY

echo "▸ Chemins cités — workflows GitHub"
python3 - <<'EOF' || echec=1
import pathlib, re, sys
morts = []
for wf in sorted(pathlib.Path(".github/workflows").glob("*.yml")):
    for n, line in enumerate(wf.read_text().splitlines(), 1):
        m = re.match(r"\s*(context|working-directory|dockerfile|file):\s*(\S+)", line)
        if not m: continue
        chemin = m.group(2).strip("'\"")
        if chemin.startswith("$") or chemin == ".": continue
        if not pathlib.Path(chemin).exists():
            morts.append(f"{wf}:{n}  {m.group(1)}: {chemin}")
if morts:
    print("  \u2717 " + str(len(morts)) + " chemin(s) mort(s)")
    for x in morts: print("     " + x)
    sys.exit(1)
print("  \u2713 tous les chemins de workflow résolvent")
EOF

exit $echec
