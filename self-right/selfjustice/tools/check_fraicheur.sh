#!/bin/sh
# Contrôle quotidien de la fraîcheur des bases servies par l'API Self-Right.
#
# 🔑 Pourquoi ce script existe. Le serveur MCP tourne chez l'utilisateur : il ne
# peut pas porter le jeton d'alerte de l'exploitant. Les rôles sont donc
# répartis — le MCP annonce le retard dans chaque réponse, et le serveur
# prévient l'exploitant. Or cette seconde moitié ne vivait que dans les scripts
# de synchronisation, qui tournent les 1er et 15. Un décrochage le 2 se
# découvrait le 15 : treize jours de droit périmé servi à tous les utilisateurs,
# chacun voyant le bandeau sans que personne d'autre soit prévenu.
#
# 🔑 Le contrôle porte sur la date du CONTENU, jamais sur celle de la dernière
# exécution. Un cron qui tourne à l'heure en reconstruisant une base identique
# passe tous les contrôles d'exécution en servant du droit mort — c'est
# exactement ce qui s'est produit pendant treize mois.
#
# Lecture seule, aucune écriture, rien qui puisse casser le service.
#
#   ./check_fraicheur.sh            # contrôle et alerte si retard
#   ./check_fraicheur.sh --verbeux  # affiche l'état de chaque base

# La configuration locale se charge AVANT les défauts, sinon elle ne peut rien
# surcharger. Elle porte le jeton d'alerte — d'où le fichier à part, en 600, qui
# laisse ce script lisible et copiable sans emporter de secret — et les adresses
# de l'instance, qui n'ont pas à être publiées avec le code qui l'interroge.
[ -r "$HOME/.check-fraicheur.env" ] && . "$HOME/.check-fraicheur.env"

# 🔑 Le défaut pointe vers un domaine d'exemple. Sans configuration, le contrôle
# échoue bruyamment au lieu d'interroger une instance qui n'est pas la bonne —
# un contrôle qui mesure la mauvaise cible est pire qu'un contrôle mort.
API=${SELFRIGHT_API_URL:-https://justice.example.org/api}
ACT=${SELFRIGHT_ACT_URL:-https://justice.example.org/act/api}
NTFY_URL="${NTFY_URL:-}"
NTFY_TOKEN="${NTFY_TOKEN:-}"

# L'état de la veille : dates ET volumes. Sans lui, on ne peut pas voir une
# date qui avance pendant qu'un volume stagne — le cas du marqueur menteur.
ETAT_FICHIER="${CHECK_FRAICHEUR_ETAT:-$HOME/.check-fraicheur.state}"

VERBEUX=""
[ "${1:-}" = "--verbeux" ] && VERBEUX=1

RAPPORT=$(API="$API" ACT="$ACT" VERBEUX="$VERBEUX" ETAT="$ETAT_FICHIER" python3 - <<'PY'
import datetime as dt, json, os, pathlib, re, urllib.request

API, ACT = os.environ["API"].rstrip("/"), os.environ["ACT"].rstrip("/")
VERBEUX = bool(os.environ.get("VERBEUX"))

MOIS = {m: i for i, m in enumerate(
    "janvier février mars avril mai juin juillet août septembre octobre novembre décembre".split(), 1)}

def lire(url):
    with urllib.request.urlopen(url, timeout=20) as r:
        return json.loads(r.read())

def parse(v):
    """L'API date la jurisprudence en ISO et les autres bases en toutes lettres."""
    s = str(v or "").strip()
    if re.fullmatch(r"\d{4}-\d{2}-\d{2}", s[:10]):
        return dt.date.fromisoformat(s[:10])
    m = re.match(r"(\d{1,2})\s+([a-zéûà]+)\s+(\d{4})", s, re.IGNORECASE)
    if m and m.group(2).lower() in MOIS:
        return dt.date(int(m.group(3)), MOIS[m.group(2).lower()], int(m.group(1)))
    return None

def derniere_echeance(j):
    """Cadence des 1er et 15. Le jour même d'une échéance, la synchronisation
    n'a pas encore tourné : on exige la précédente, sinon l'alerte crie deux
    fois par mois sur une base saine — et une alarme qui crie pour rien finit
    ignorée le jour où elle a raison."""
    if j.day > 15:  return j.replace(day=15)
    if j.day > 1:   return j.replace(day=1)
    veille = j - dt.timedelta(days=1)
    return veille.replace(day=15) if veille.day >= 15 else veille.replace(day=1)

def volume(bloc):
    """Le compteur qui fait foi pour cette base, quel que soit son nom."""
    for cle in ("decisions", "articles", "total"):
        if isinstance(bloc.get(cle), int):
            return bloc[cle]
    return None


def empreinte(bloc, prefixe=""):
    """Tous les compteurs entiers du bloc, sous-comptes compris.

    🔑 Le total seul ne suffit pas à dire qu'une base n'a pas bougé. Le
    21/08/2026, le catalogue SelfAct a été resynchronisé et a rendu 1 895
    modèles — exactement le compte de la copie du 3 août qu'il remplaçait, alors
    que douze de ses seize catégories avaient changé. La règle du trompe-l'œil,
    qui ne comparait que le total, a donc crié sur une synchronisation réelle.
    Ce qu'elle doit chercher est l'immobilité complète, pas l'égalité d'un seul
    nombre.
    """
    plat = {}
    for cle, val in (bloc or {}).items():
        chemin = f"{prefixe}{cle}"
        if isinstance(val, bool):
            continue
        if isinstance(val, int):
            plat[chemin] = val
        elif isinstance(val, dict):
            plat.update(empreinte(val, chemin + "."))
    return plat or None

sources, volumes, empreintes, erreurs = {}, {}, {}, []
try:
    st = lire(f"{API}/status")
    for cle, nom in (("legi", "LEGI"), ("eu", "conventionnalité"), ("jurisprudence", "jurisprudence")):
        bloc = st.get(cle) or {}
        sources[nom] = bloc.get("last_update")
        volumes[nom] = volume(bloc)
        empreintes[nom] = empreinte(bloc)
except Exception as e:
    erreurs.append(f"/status injoignable ({type(e).__name__})")

try:
    meta = (lire(f"{ACT}/catalog").get("meta") or {})
    # ⚠️ La même date porte deux noms selon la route : last_sync ici,
    # last_update sur les situations. Vérifié, pas supposé.
    sources["catalogue SelfAct"] = meta.get("last_sync") or meta.get("last_update")
    volumes["catalogue SelfAct"] = volume(meta)
    empreintes["catalogue SelfAct"] = empreinte(meta)
except Exception as e:
    erreurs.append(f"/act/api/catalog injoignable ({type(e).__name__})")

# 🔑 **Un renvoi peut mourir sans que rien ne change de date.** Le rapprochement
# gabarit → ressource officielle est curé à la main contre le catalogue d'un
# jour donné ; celui-ci se resynchronise les 1er et 15, et une ressource retirée
# par l'administration laisse un renvoi vers rien. Mesuré le 22/08/2026 :
# R48318 « Demande de conciliation » figurait au catalogue du 3 août, plus à
# celui du 21.
#
# Le banc du dépôt ne peut pas voir cette dérive — il mesure contre la copie
# versionnée du catalogue, qui est justement celle d'avant. Seule une sonde qui
# interroge l'instance le peut, et c'est ici. La route nomme déjà les
# identifiants qu'elle ne résout plus : il suffit de les lire.
try:
    orphelins = {
        cle: g["inconnus"]
        for cle, g in (lire(f"{ACT}/gabarits").get("gabarits") or {}).items()
        if g.get("inconnus")
    }
    if orphelins:
        detail = "; ".join(f"{c} → {', '.join(ids)}" for c, ids in sorted(orphelins.items()))
        erreurs.append(
            f"renvois officiels morts dans data/gabarits.json ({detail}) : "
            "la démarche paraît sans équivalent officiel alors qu'elle en a"
        )
except Exception as e:
    erreurs.append(f"/act/api/gabarits injoignable ({type(e).__name__})")

today = dt.date.today()
attendu = derniere_echeance(today)
retards, lignes = [], []

# 🔑 Une date fraîche ne prouve pas un contenu frais. Le marqueur de fraîcheur
# est écrit par le script de synchronisation lui-même : s'il rejoue une fenêtre
# déjà moissonnée, il avance sa date sans rien ajouter, et un contrôle qui ne
# regarde que la date répond « à jour » en toute bonne foi. Mesuré le 09/08/2026
# sur Judilibre — 7 760 décisions retéléchargées, +0 en base, marqueur écrit.
etat_precedent = {}
try:
    etat_precedent = json.loads(pathlib.Path(os.environ["ETAT"]).read_text())
except Exception:
    pass

# `vu_le` : le jour où cette date-là est apparue pour la première fois. C'est ce
# qui permet de distinguer une base qui n'avance plus d'une base dont l'amont
# n'a rien publié de neuf.
etat_courant = {}
for nom in sources:
    date_brute = str(sources.get(nom) or "")
    veille = etat_precedent.get(nom) or {}
    inchangee = veille.get("date") == date_brute and veille.get("vu_le")
    etat_courant[nom] = {
        "date": date_brute,
        "volume": volumes.get(nom),
        "empreinte": empreintes.get(nom),
        "vu_le": veille["vu_le"] if inchangee else today.isoformat(),
    }

# 🔑 **Ne jamais comparer la date d'un contenu au calendrier de nos crons.**
# Deux natures de dates arrivent ici sous le même nom. Le catalogue SelfAct
# publie l'horodatage de sa propre synchronisation — comparable à une échéance.
# LEGI publie la date du dernier diff que la DILA a mis en ligne : elle précède
# forcément notre passage, et n'avance plus jusqu'au suivant. Le contrôle
# exigeait pourtant des deux qu'elles atteignent le 1er ou le 15.
#
# Résultat mesuré du 17 au 21/08/2026 : sept alertes consécutives « LEGI :
# arrêtée au 2026-08-14 » sur une base parfaitement à jour de son amont — la
# synchronisation du 15 avait bien tourné et pris le dernier diff publié. Le 20,
# le catalogue SelfAct a réellement décroché ; son alerte est arrivée en
# cinquième ligne d'un message qui criait pour rien depuis quatre jours.
#
# D'où deux règles qui ne parlent que de mouvement, vraies quelle que soit la
# nature de la date, et dont aucune ne peut être vraie d'un service sain :
#   1. une date qui RECULE — une donnée ne rajeunit pas toute seule ;
#   2. une date qui n'a pas bougé alors qu'une échéance est passée depuis.
for nom, courant in etat_courant.items():
    d = parse(courant["date"])
    veille = etat_precedent.get(nom) or {}
    d_old = parse(veille.get("date"))
    vu_le = dt.date.fromisoformat(courant["vu_le"])

    if d is None:
        retards.append(f"{nom} : date illisible (« {courant['date'] or 'rien'} »)")
        lignes.append(f"  ✗ {nom:20} illisible : {courant['date']!r}")
    elif d_old and d < d_old:
        retards.append(
            f"{nom} : date en RECUL, {d_old.isoformat()} → {d.isoformat()} "
            f"— du contenu plus ancien a écrasé du contenu plus récent"
        )
        lignes.append(f"  ✗ {nom:20} {d.isoformat()}  RECUL (depuis {d_old.isoformat()})")
    elif attendu > vu_le:
        # ⚠️ Aucun compte de jours dans le message : c'est la seule part qui
        # change d'un matin à l'autre pour un fait unique, et la garde de
        # silence plus bas compare les messages tels quels.
        retards.append(
            f"{nom} : figée au {d.isoformat()} depuis le {vu_le.isoformat()}, "
            f"l'échéance du {attendu.isoformat()} n'a rien apporté"
        )
        lignes.append(
            f"  ✗ {nom:20} {d.isoformat()}  FIGÉE depuis {vu_le.isoformat()}"
            f" ({(today - d).days} j de contenu)"
        )
    else:
        lignes.append(f"  ✓ {nom:20} {d.isoformat()}  (vue le {vu_le.isoformat()})")

for nom, courant in etat_courant.items():
    veille = etat_precedent.get(nom)
    if not veille or courant["volume"] is None or veille.get("volume") is None:
        continue
    d_now, d_old = parse(courant["date"]), parse(veille.get("date"))
    # L'égalité porte sur TOUS les compteurs, sous-comptes compris : le total
    # peut retomber sur le même nombre pendant que la répartition change. Un
    # état d'avant l'empreinte n'en a pas — on retombe alors sur le total seul,
    # le temps d'un passage.
    e_now, e_old = courant.get("empreinte"), veille.get("empreinte")
    fige = (e_now == e_old) if (e_now and e_old) else (courant["volume"] == veille["volume"])
    if d_now and d_old and d_now > d_old and fige:
        retards.append(
            f"{nom} : date avancée au {d_now.isoformat()} sans aucun ajout "
            f"({courant['volume']} inchangé) — synchronisation en trompe-l'œil"
        )
        lignes.append(f"  ✗ {nom:20} date +1 · volume figé à {courant['volume']}")
    elif courant["volume"] < veille["volume"]:
        retards.append(
            f"{nom} : volume en BAISSE, {veille['volume']} → {courant['volume']}"
        )
        lignes.append(f"  ✗ {nom:20} volume en baisse ({veille['volume']} → {courant['volume']})")

# 🔑 **Une sonde qui échoue ne doit pas effacer sa mémoire.** Le 19/08/2026,
# une coupure réseau a rendu toutes les sources injoignables : `etat_courant`
# était vide, il a écrasé le fichier, et l'historique des volumes a disparu.
# Une baisse survenue avant la panne devient alors impossible à recouper, et le
# passage suivant repart de zéro sans rien signaler — le contrôle se tait
# exactement quand il devrait crier.
#
# La garde d'origine ne couvrait que l'échec d'écriture, pas l'écriture d'un
# état vide. On fusionne donc : l'ancien sert de socle, les mesures du jour ne
# remplacent que ce qu'elles ont réellement observé.
etat_a_ecrire = {**etat_precedent, **etat_courant}

# Une source qu'on n'a pas pu mesurer garde sa valeur de référence — il faut le
# dire, sinon la comparaison de demain porte sur une veille silencieusement plus
# ancienne qu'on ne le croit.
muettes = [n for n in etat_precedent if n not in etat_courant]
if muettes:
    erreurs.append(
        "non mesurées ce passage, référence d'avant conservée : " + ", ".join(sorted(muettes))
    )

try:
    pathlib.Path(os.environ["ETAT"]).write_text(json.dumps(etat_a_ecrire, indent=1))
except Exception as e:
    erreurs.append(f"état non enregistré ({type(e).__name__}) : la comparaison de volume sera muette demain")

if VERBEUX:
    print(f"# Échéance exigible : {attendu.isoformat()} (contrôle du {today.isoformat()})")
    print("\n".join(lignes))
    if not etat_precedent:
        print("# Première mesure : la comparaison de volume commencera demain.")

# Une source injoignable est un retard : ne pas distinguer « en retard » de
# « impossible à vérifier » serait dire « tout va bien » quand on ne sait pas.
probs = retards + erreurs
print("ALERTE " + " | ".join(probs) if probs else "OK")
PY
)

echo "$RAPPORT" | sed '/^ALERTE\|^OK$/d'
ETAT=$(echo "$RAPPORT" | grep -E "^(ALERTE|OK)" | head -1)

case "$ETAT" in
    OK) rm -f "${CHECK_FRAICHEUR_SILENCE_FICHIER:-$HOME/.check-fraicheur.dernier-cri}"
        [ -n "$VERBEUX" ] && echo "OK — toutes les bases sont à jour." ; exit 0 ;;
esac

MESSAGE=${ETAT#ALERTE }
logger -t check-fraicheur "$MESSAGE"
echo "RETARD : $MESSAGE"

# 🔑 **Le même fait ne se notifie qu'une fois.** Une source figée reste figée
# plusieurs jours — la cadence de publication de certaines bases les laisse
# immobiles jusqu'à deux semaines. Sans garde, le contrôle envoyait une alerte
# chaque matin pour un seul événement, et un canal qui répète devient un canal
# qu'on n'ouvre plus. Le journal local, lui, garde chaque passage : c'est la
# notification qu'on espace, pas la mesure.
#
# ⚠️ Aucun message ne porte de compteur qui avance : c'est la condition pour
# que la signature soit le message lui-même. Un « soit 2 jours » puis « soit
# 3 jours » décrirait le même fait sous deux signatures et n'empêcherait rien —
# le compte de jours reste donc dans la sortie verbeuse, pas dans l'alerte.
#
# Cet état sert à se taire, pas à mesurer : le perdre fait envoyer une alerte de
# trop, jamais une de moins. C'est le bon sens de la défaillance — l'inverse de
# l'état de volume, dont la perte rend le contrôle muet.
SIGNATURE="$MESSAGE"
SILENCE_FICHIER="${CHECK_FRAICHEUR_SILENCE_FICHIER:-$HOME/.check-fraicheur.dernier-cri}"
SILENCE_SECONDES=${CHECK_FRAICHEUR_SILENCE:-86400}

DEJA=""; QUAND=0
[ -r "$SILENCE_FICHIER" ] && IFS='|' read -r QUAND DEJA < "$SILENCE_FICHIER"
MAINTENANT=$(date +%s)
if [ "$SIGNATURE" = "$DEJA" ] && [ $((MAINTENANT - ${QUAND:-0})) -lt "$SILENCE_SECONDES" ]; then
    [ -n "$VERBEUX" ] && echo "  (déjà notifié, silence en cours)"
    exit 0
fi

if [ -n "$NTFY_URL" ]; then
    curl -s -m 10 -H "Authorization: Bearer $NTFY_TOKEN" \
         -H "Title: Self-Right — base en retard" -H "Priority: high" \
         -d "$MESSAGE" "$NTFY_URL" >/dev/null 2>&1 \
      && { echo "  alerte envoyée"
           # La signature n'est retenue qu'après un envoi réussi : un canal
           # muet ne doit pas déclencher le silence du lendemain.
           printf '%s|%s\n' "$MAINTENANT" "$SIGNATURE" > "$SILENCE_FICHIER"; } \
      || echo "  ⚠️ envoi de l'alerte en échec"
else
    echo "  (aucun canal configuré : créer ~/.check-fraicheur.env avec NTFY_URL et NTFY_TOKEN)"
fi
exit 1
