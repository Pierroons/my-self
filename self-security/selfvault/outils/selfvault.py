"""Fabrique d'un coffre SELFVAULT2 — le format, ses règles, et rien d'autre.

Séparé de `faire_coffre.py` pour que le banc puisse fabriquer des coffres
DÉFECTUEUX : les fusionner rendrait tout durcissement inéprouvable.
"""
import base64, hashlib, hmac, json, math, os, re, secrets
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.kdf.pbkdf2 import PBKDF2HMAC
from cryptography.hazmat.primitives import hashes

FORMAT = "SELFVAULT2"
ITER = 600_000                     # OWASP Password Storage Cheat Sheet, relevé le 04/09/2026
ITER_MIN, ITER_MAX = 100_000, 10_000_000
ALPHABET = "23456789ABCDEFGHJKMNPQRSTVWXYZ"   # ni I, ni L, ni O, ni U, ni 0, ni 1

b64 = lambda x: base64.b64encode(x).decode()


def tirer_lettre() -> str:
    """Un caractère de l'alphabet, sans biais.

    `os.urandom(1)[0] % 30` favorise les seize premières lettres : 256 n'est pas
    un multiple de 30. L'écart mesuré est de 0,05 bit sur les 98 du code — le
    rejet ne corrige rien de sérieux, il évite d'avoir à expliquer un biais.
    """
    limite = 256 - (256 % len(ALPHABET))
    while True:
        b = os.urandom(1)[0]
        if b < limite:
            return ALPHABET[b % len(ALPHABET)]


def controle(corps: str) -> str:
    """Les cinq caractères de contrôle d'un code de récupération."""
    somme = hashlib.sha256(corps.encode()).digest()
    return "".join(ALPHABET[b % len(ALPHABET)] for b in somme[:5])


def code_recuperation() -> str:
    """20 caractères tirés au sort, plus 5 de contrôle. ~98 bits d'entropie."""
    corps = "".join(tirer_lettre() for _ in range(20))
    plein = corps + controle(corps)
    return "-".join(plein[i:i+5] for i in range(0, 25, 5))


def normaliser(s: str) -> str:
    """Forme canonique d'un secret : lettres et chiffres seuls, casse conservée.

    Un code recopié à la main perd ses tirets, ou les remplace par des espaces.
    Retirer toute la ponctuation des deux côtés rend ces variantes équivalentes,
    sans rien coûter en entropie : elle vient de l'alphabet, pas des séparateurs.
    """
    return "".join(c for c in s if c.isalnum())


def entete_canonique(coffre: dict, bornes: bool = True) -> bytes:
    """L'en-tête sous forme canonique — l'AAD de chaque opération AES-GCM.

    Une ligne par champ, séparateur `\\n`, en UTF-8. Ni JSON canonique ni tri de
    clés : la notice imprimée doit permettre de reconstruire cette chaîne
    exactement, et la sérialisation canonique de JSON ne se réimplémente pas
    juste du premier coup — échappement Unicode, format des nombres, ordre des
    clés. Une ligne par champ se relit à l'œil.

    Sans AAD, un coffre passé à `version: 99` s'ouvrait normalement et
    l'application affichait la valeur falsifiée comme si elle faisait foi, alors
    que le pli demande au dépositaire de se fier à ce numéro pour savoir quel
    coffre est le plus récent.
    """
    # 🔑 L'encodage doit être INJECTIF : deux en-têtes différents ne doivent jamais
    # produire la même chaîne. Interdire « | » dans le seul nom de serrure ne
    # suffit pas — `date` et `sel` entrent aussi dans l'AAD, et un « \n » glissé
    # dans l'un d'eux referme une ligne et en ouvre une autre. On peut alors
    # fabriquer deux coffres de DATES différentes ayant le même AAD, tous deux
    # affichant « en-tête authentifié ». Chaque champ est donc contraint à une
    # forme qui exclut les deux caractères structurants.
    verifier_champs(coffre, bornes=bornes)
    lignes = [coffre["format"],
              "version=%d" % coffre["version"],
              "date=%s" % coffre["date"],
              "engagement=%s" % coffre["engagement"]]
    for s in coffre["serrures"]:
        lignes.append("serrure=%s|%s|%d" % (s["nom"], s["sel"], s["iterations"]))
    return "\n".join(lignes).encode("utf-8")


B64 = r"[A-Za-z0-9+/]+={0,2}"
DATE = r"\d{4}-\d{2}-\d{2}"


def verifier_champs(coffre: dict, bornes: bool = True) -> None:
    """Contraint la forme de tout ce qui entre dans l'AAD. Lève, ou se tait.

    `bornes=False` laisse passer un nombre d'itérations hors plage. C'est la seule
    échappatoire, elle porte un nom, et un seul appelant s'en sert : le banc, qui a
    besoin de fabriquer le coffre que les lecteurs doivent refuser. Sans elle, la
    règle serait appliquée à la lecture et pas à l'écriture — un garde-fou qui
    porte le bon nom et ne s'active que d'un côté.
    """
    if coffre.get("format") != FORMAT:
        raise ValueError("format inconnu : %r" % coffre.get("format"))
    v = coffre.get("version")
    if not isinstance(v, int) or isinstance(v, bool) or v < 1:
        raise ValueError("version : entier positif attendu, reçu %r" % (v,))
    if not re.fullmatch(DATE, str(coffre.get("date"))):
        raise ValueError("date : AAAA-MM-JJ attendu, reçu %r" % (coffre.get("date"),))
    if not re.fullmatch(B64, str(coffre.get("engagement"))):
        raise ValueError("engagement : base64 attendu")
    serrures = coffre.get("serrures")
    if not isinstance(serrures, list) or not serrures:
        raise ValueError("coffre sans serrure")
    for s in serrures:
        if not isinstance(s.get("nom"), str) or "|" in s["nom"] or "\n" in s["nom"]:
            raise ValueError("nom de serrure invalide : %r" % (s.get("nom"),))
        if not re.fullmatch(B64, str(s.get("sel"))):
            raise ValueError("sel de « %s » : base64 attendu" % s["nom"])
        it = s.get("iterations")
        if not isinstance(it, int) or isinstance(it, bool) or it < 1:
            raise ValueError("itérations de « %s » : entier positif attendu, reçu %r" % (s["nom"], it))
        if bornes and not ITER_MIN <= it <= ITER_MAX:
            raise ValueError("itérations de « %s » hors bornes : %d" % (s["nom"], it))


def engagement(maitresse: bytes) -> str:
    """Engage la clé maîtresse, qu'AES-GCM ne s'engage pas à porter.

    AES-GCM n'est pas *key-committing* : un chiffré peut s'authentifier sous deux
    clés et rendre deux clairs. Un coffre multi-serrures remis volontairement à
    un tiers est exactement l'objet où cette propriété se cherche. Le HMAC
    permet de rejeter nommément une serrure qui rend la mauvaise clé, au lieu de
    laisser échouer le contenu sans dire pourquoi.
    """
    return b64(hmac.new(maitresse, FORMAT.encode() + b"/engagement", hashlib.sha256).digest())


# --- Le plancher d'entropie de la serrure mémorisée --------------------------
#
# C'est la condition qui rend PBKDF2 défendable ici, pas un confort. Le coffre est
# DÉLIBÉRÉMENT remis à un tiers : son chiffré est entre les mains de quelqu'un par
# conception, et hors ligne un tag AEAD est un oracle de validité gratuit. La
# sécurité de l'ensemble est celle de la serrure la moins chère à ouvrir — 600 000
# itérations ne rachètent pas une phrase de trente bits.
#
# 🔑 L'entropie est une propriété du TIRAGE, pas de la chaîne. Aucune inspection
# d'un texte ne dit s'il a été tiré au sort : « maison-maison-maison-maison-maison-
# maison » est composé de six mots de la liste et ne vaut que treize bits. Une
# vérification par appartenance des mots à la liste accepte donc les phrases
# inventées qu'elle prétend écarter. Le tirage se prouve à la source : seule une
# phrase rendue par `tirer_phrase()` porte sa mesure, et `fabriquer()` n'accepte
# rien d'autre.
#
# Le plancher est exprimé en BITS et non en mots, parce que la liste est
# substituable par `SELFRECOVER_WORDLIST`. Une liste diceware compte 6⁴ = 1 296
# mots ou 6⁵ = 7 776 : sur la courte, sept mots ne valent que 72 bits et il en
# faut huit. Un plancher en mots serait donc juste sur une liste et faux sur
# l'autre.
#
# Et l'entropie se calcule sur les mots DISTINCTS, jamais sur le nombre
# d'entrées : une liste de 7 776 lignes portant toutes le même mot ferait
# annoncer 90 bits pour zéro.
#
# La liste vit chez SelfRecover : une seule copie normative dans le dépôt.
BITS_MIN = 77.0                    # 6 mots sur la liste EFF longue ; 8 sur la courte
MOTS_DEFAUT = 7


class PhraseTiree(str):
    """Une phrase que ce processus vient de tirer, et qui porte sa mesure.

    Le type est la preuve. Une chaîne recopiée depuis un journal, un fichier ou un
    argument de ligne de commande redevient un `str` ordinaire et se fait refuser —
    ce qui est exactement ce qu'on veut : on ne sait pas comment elle a été obtenue.
    """
    __slots__ = ("bits", "mots")

    def __new__(cls, texte, bits, mots):
        objet = super().__new__(cls, texte)
        objet.bits, objet.mots = bits, mots
        return objet


def _liste_mots():
    """Rend la liste de mots, après avoir vérifié qu'elle en est une.

    Aucun seuil de taille : c'est le plancher en bits qui décide, et lui se
    mesure. Ce qui est vérifié ici, c'est ce dont le calcul d'entropie dépend —
    des entrées non vides, et pas de doublon, faute de quoi `len()` compterait
    des mots qui n'existent pas.
    """
    ici = os.path.dirname(os.path.abspath(__file__))
    for c in (os.environ.get("SELFRECOVER_WORDLIST"),
              os.path.join(ici, "..", "..", "..", "bi-self", "selfrecover", "assets", "eff_fr.json")):
        if not c or not os.path.exists(c):
            continue
        mots = json.load(open(c, encoding="utf-8"))
        if not isinstance(mots, list) or not mots:
            raise ValueError("%s n'est pas une liste de mots" % c)
        if not all(isinstance(m, str) and m.strip() for m in mots):
            raise ValueError("%s porte des entrées vides ou non textuelles" % c)
        doublons = len(mots) - len(set(mots))
        if doublons:
            raise ValueError("%s porte %d doublon(s) : l'entropie se calcule sur les mots "
                             "distincts, une liste qui se répète en annonce plus qu'elle n'en a"
                             % (c, doublons))
        return mots
    raise FileNotFoundError("liste de mots introuvable — définir SELFRECOVER_WORDLIST")


def tirer_phrase(n: int = MOTS_DEFAUT) -> PhraseTiree:
    """Tire une phrase de passe. Le générateur la DONNE, il ne la demande pas."""
    mots = _liste_mots()
    bits = n * math.log2(len(mots))
    if bits < BITS_MIN:
        raise ValueError("%d mots sur une liste de %d n'en font que %.1f bits — plancher %.0f. "
                         "Sur cette liste il en faut %d."
                         % (n, len(mots), bits, BITS_MIN, math.ceil(BITS_MIN / math.log2(len(mots)))))
    return PhraseTiree("-".join(secrets.choice(mots) for _ in range(n)), bits, n)


def bits_de(secret) -> float:
    """Entropie d'un secret, ou None si son tirage n'est pas établi.

    Deux formes seulement : un code de récupération, dont la somme de contrôle
    atteste la fabrication, et une phrase rendue par `tirer_phrase()`. Une phrase
    reçue sous forme de simple chaîne n'est pas mesurable — voir plus haut.
    """
    plein = normaliser(secret)
    if len(plein) == 25 and all(c in ALPHABET for c in plein) and controle(plein[:20]) == plein[20:]:
        return 20 * math.log2(len(ALPHABET))
    if isinstance(secret, PhraseTiree):
        return secret.bits
    return None


def fabriquer(contenu: str, serrures, version: int, date: str,
              iterations=ITER, maitresse=None, engagement_de=None,
              contenu_sans_aad=False, bornes=True) -> dict:
    """Fabrique un coffre. `serrures` est une liste de (nom, secret).

    `version` et `date` sont exigés, sans valeur par défaut. Sous leur forme
    précédente ils étaient des littéraux : tout coffre naissait « version 1 du
    2026-09-04 », y compris fabriqué des années plus tard — et l'AAD certifiait
    cette date. Or le pli demande au dépositaire de détruire l'ancien pli d'après
    ce numéro : deux plis portant le même n'étaient pas départageables.

    `maitresse`, `engagement_de` et `contenu_sans_aad` ne servent qu'au banc : ils
    permettent de fabriquer un coffre dont l'engagement porte sur une autre clé,
    ou dont le contenu n'est pas lié à l'en-tête — les seuls moyens d'éprouver ces
    deux vérifications, qu'un en-tête simplement retouché ne fait pas rougir.
    """
    if not isinstance(version, int) or isinstance(version, bool) or version < 1:
        raise ValueError("version : entier positif attendu, reçu %r" % (version,))
    if not re.fullmatch(r"\d{4}-\d{2}-\d{2}", str(date)):
        raise ValueError("date : AAAA-MM-JJ attendu, reçu %r" % (date,))
    for nom, secret in serrures:
        bits = bits_de(secret)
        if bits is None:
            raise ValueError(
                "serrure « %s » : tirage non établi. Une phrase reçue sous forme de "
                "chaîne n'est pas mesurable, quels que soient ses mots — passer par "
                "tirer_phrase()." % nom)
        if bits < BITS_MIN:
            raise ValueError("serrure « %s » : %.1f bits, plancher %.0f" % (nom, bits, BITS_MIN))
    maitresse = maitresse or os.urandom(32)
    coffre = {
        "format": FORMAT,
        "version": version,
        "date": date,
        "engagement": engagement(engagement_de if engagement_de is not None else maitresse),
        "serrures": [{"nom": nom, "sel": b64(os.urandom(16)),
                      "iterations": iterations, "nonce": b64(os.urandom(12))}
                     for nom, _ in serrures],
        "nonce": b64(os.urandom(12)),
    }
    # L'en-tête est arrêté AVANT tout chiffrement : il est l'AAD des enveloppes
    # comme du contenu.
    aad = entete_canonique(coffre, bornes=bornes)
    for cible, (_, secret) in zip(coffre["serrures"], serrures):
        kdf = PBKDF2HMAC(algorithm=hashes.SHA256(), length=32,
                         salt=base64.b64decode(cible["sel"]), iterations=cible["iterations"])
        cle = kdf.derive(normaliser(secret).encode())
        cible["enveloppe"] = b64(AESGCM(cle).encrypt(
            base64.b64decode(cible["nonce"]), maitresse, aad))
    coffre["contenu"] = b64(AESGCM(maitresse).encrypt(
        base64.b64decode(coffre["nonce"]), contenu.encode(), None if contenu_sans_aad else aad))
    return coffre
