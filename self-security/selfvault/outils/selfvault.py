"""Fabrique d'un coffre SELFVAULT3 — le format, ses règles, et rien d'autre.

Séparé de `faire_coffre.py` pour que le banc puisse fabriquer des coffres
DÉFECTUEUX : les fusionner rendrait tout durcissement inéprouvable.
"""
import base64, hashlib, hmac, json, math, os, re, secrets
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.kdf.pbkdf2 import PBKDF2HMAC
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.hazmat.primitives.asymmetric.utils import (decode_dss_signature,
                                                             encode_dss_signature)
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat
from cryptography.exceptions import InvalidSignature

FORMAT = "SELFVAULT3"
ITER = 600_000                     # OWASP Password Storage Cheat Sheet, relevé le 04/09/2026
ITER_MIN, ITER_MAX = 100_000, 10_000_000
# JSON n'a pas d'entiers : Python rend un entier exact, JavaScript un flottant à
# 53 bits. Au-delà, `version=9007199254740993` et `…992` s'écrivent pareil dans
# l'AAD côté JS — deux fichiers différents, un seul AAD, tous deux « authentifiés ».
# Or c'est ce numéro que le pli fait servir à détruire l'ancien coffre.
VERSION_MAX = 99_999
# `ITER_MAX` borne le coût d'UNE serrure ; rien ne bornait leur NOMBRE, et
# l'ouverture dérive PBKDF2 pour chacune avant toute authentification. Un fichier
# de 200 serrures à `ITER_MAX` fige le navigateur cinq minutes, et il tient dans
# les QR codes d'un pli.
SERRURES_MAX = 8
# Le tirage d'un mot se fait sur 16 bits : `65536 - (65536 % len)` vaut ZÉRO dès
# que la liste dépasse 65 536 entrées, et la boucle de rejet ne sort jamais.
# Python, lui, tirait sans broncher — la même liste donnait 112 bits ici et un
# onglet figé là-bas.
MOTS_MAX = 65_536
# Les seuls champs que le format porte. Ce qui n'est pas là n'est signé par rien.
CHAMPS = {"format", "version", "date", "engagement", "cle_publique",
          "serrures", "nonce", "contenu", "signature"}
CHAMPS_SERRURE = {"nom", "sel", "iterations", "nonce", "enveloppe"}
ALPHABET = "23456789ABCDEFGHJKMNPQRSTVWXYZ"   # ni I, ni L, ni O, ni U, ni 0, ni 1

b64 = lambda x: base64.b64encode(x).decode()


def alea(n: int) -> bytes:
    """`n` octets de hasard, en refusant un bloc constant.

    Une panne du générateur ne se voit pas. Un `getRandomValues` qui ne remplit
    rien rend un tableau de zéros, et la mesure affichée reste celle du tirage
    prévu, pour un secret qui n'en porte aucun — le pendant JavaScript de cette fonction est né
    de là. Deux coffres tirés par un tel générateur partagent clé et nonce, ce
    qui rend leurs deux clairs par un simple XOR.

    Le contrôle ne prouve rien sur la qualité du hasard : il FALSIFIE le cas où
    il n'y en a pas. Un bloc honnête de seize octets a une chance sur 2¹²⁰ d'être
    constant ; on peut donc le refuser sans jamais gêner personne.
    """
    b = os.urandom(n)
    if n >= 8 and len(set(b)) == 1:
        raise RuntimeError("le générateur d'aléa a rendu %d octets tous identiques. "
                           "Rien de ce qui serait tiré ici ne vaudrait ce qu'on en dirait." % n)
    return b


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
    if len(set(corps)) == 1:
        raise RuntimeError("les vingt caractères tirés sont identiques — le générateur "
                           "d'aléa est en panne. Probabilité d'un tirage honnête : 2⁻⁹³.")
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
              "engagement=%s" % coffre["engagement"],
              "cle_publique=%s" % coffre["cle_publique"]]
    for s in coffre["serrures"]:
        lignes.append("serrure=%s|%s|%d" % (s["nom"], s["sel"], s["iterations"]))
    return "\n".join(lignes).encode("utf-8")


B64 = r"[A-Za-z0-9+/]+={0,2}"
_b64 = lambda x: isinstance(x, str) and re.fullmatch(B64, x) is not None
# `\d` désigne en Python TOUS les chiffres d'Unicode, en JavaScript les seuls
# `[0-9]`. Une date en chiffres arabo-indiens passait donc la fabrique et rendait
# le coffre définitivement illisible par le déchiffreur imprimé dans le pli.
DATE = r"[0-9]{4}-[0-9]{2}-[0-9]{2}"


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
    # 🔑 Le sceau signe une PROJECTION du fichier — les champs que les deux chaînes
    # canoniques nomment —, pas le JSON. Une clé qu'il ne nomme pas n'est donc
    # couverte par rien. Tant qu'aucun lecteur n'en consomme, c'est sans effet ;
    # le jour où un champ neuf serait affiché, il ne serait pas signé et rien ne
    # le dirait. On refuse donc ce qu'on ne nomme pas : étendre le format, c'est
    # le renuméroter, comme la notice imprimée l'exige.
    inconnus = set(coffre) - CHAMPS
    if inconnus:
        raise ValueError("champ inconnu dans l'en-tête : %s. Ce format ne porte que %s."
                         % (", ".join(sorted(inconnus)), ", ".join(sorted(CHAMPS))))
    for s in coffre.get("serrures") or []:
        if isinstance(s, dict) and set(s) - CHAMPS_SERRURE:
            raise ValueError("champ inconnu dans une serrure : %s"
                             % ", ".join(sorted(set(s) - CHAMPS_SERRURE)))
    v = coffre.get("version")
    # JSON n'a pas d'entiers. JavaScript lit `1.0` et `1` comme un seul et même
    # nombre et ne peut pas les séparer ; exiger `int` ici creusait un écart que
    # l'autre côté ne pouvait pas fermer — le déchiffreur imprimé ouvrait un
    # fichier que la référence déclarait malformé. On accepte donc un flottant de
    # valeur entière, et rien d'autre : `1.5` reste refusé des deux côtés.
    if isinstance(v, bool) or not isinstance(v, (int, float)) \
       or v != int(v) or not 1 <= v <= VERSION_MAX:
        raise ValueError("version : entier de 1 à %d attendu, reçu %r" % (VERSION_MAX, v))
    d = coffre.get("date")
    if not isinstance(d, str) or not re.fullmatch(DATE, d):
        raise ValueError("date : AAAA-MM-JJ attendu, reçu %r" % (d,))
    # 🔑 Le type se contrôle AVANT la forme : « None », « True » et « 12345 » sont
    # du base64 valide. Une regex appliquée à `str(x)` porte sur la
    # représentation de la valeur et jamais sur son type, et laisse alors deux
    # en-têtes différents rendre le même AAD.
    if not _b64(coffre.get("engagement")):
        raise ValueError("engagement : chaîne base64 attendue")
    # 65 octets, préfixe 0x04 : un point de courbe non compressé, la forme que
    # `crypto.subtle.exportKey('raw')` rend et que `importKey` reprend.
    p = coffre.get("cle_publique")
    if not _b64(p):
        raise ValueError("cle_publique : chaîne base64 attendue")
    brut = base64.b64decode(p)
    if len(brut) != 65 or brut[0] != 4:
        raise ValueError("cle_publique : point P-256 non compressé attendu "
                         "(65 octets, préfixe 0x04), reçu %d octets" % len(brut))
    serrures = coffre.get("serrures")
    if not isinstance(serrures, list) or not serrures:
        raise ValueError("coffre sans serrure")
    if len(serrures) > SERRURES_MAX:
        raise ValueError("%d serrures : le format en accepte %d au plus. Chacune coûte "
                         "une dérivation complète avant qu'on sache si elle est la bonne."
                         % (len(serrures), SERRURES_MAX))
    for s in serrures:
        if not isinstance(s.get("nom"), str) or "|" in s["nom"] or "\n" in s["nom"]:
            raise ValueError("nom de serrure invalide : %r" % (s.get("nom"),))
        if not _b64(s.get("sel")):
            raise ValueError("sel de « %s » : chaîne base64 attendue" % s["nom"])
        it = s.get("iterations")
        if not isinstance(it, int) or isinstance(it, bool) or it < 1:
            raise ValueError("itérations de « %s » : entier positif attendu, reçu %r" % (s["nom"], it))
        if bornes and not ITER_MIN <= it <= ITER_MAX:
            raise ValueError("itérations de « %s » hors bornes : %d" % (s["nom"], it))


def verifier_chiffres(coffre: dict) -> None:
    """Contraint la forme de ce qui entre dans le MESSAGE SIGNÉ sans être dans l'AAD.

    Séparé de `verifier_champs` parce que l'AAD est arrêté avant que ces
    valeurs existent : les exiger là refuserait tout coffre en cours de
    fabrication. Leur forme base64 est ce qui rend l'encodage du message signé
    injectif, exactement comme pour les champs de l'en-tête.
    """
    for cle in ("nonce", "contenu"):
        if not _b64(coffre.get(cle)):
            raise ValueError("%s : chaîne base64 attendue" % cle)
    for s in coffre.get("serrures") or []:
        for cle in ("nonce", "enveloppe"):
            if not _b64(s.get(cle)):
                raise ValueError("%s de « %s » : chaîne base64 attendue"
                                 % (cle, s.get("nom")))


def message_signe(coffre: dict, bornes: bool = True) -> bytes:
    """Ce que la signature couvre : l'AAD, PLUS ce que l'AAD ne peut pas couvrir.

    L'AAD est arrêté AVANT tout chiffrement — il est l'entrée des chiffrements.
    Il ne peut donc pas porter les chiffrés eux-mêmes. Or le contenu n'est
    authentifié que par la clé maîtresse, et TOUTE serrure rend la clé maîtresse :
    le dépositaire ouvre la sienne, rechiffre ce qu'il veut sous cette même clé
    avec le même AAD, et l'application affiche à la titulaire « en-tête
    authentifié » au-dessus d'un texte qu'elle n'a pas écrit. La relation est
    symétrique — elle peut réécrire ce que le dépositaire lira.

    Le message signé prolonge donc l'AAD, dans le même style : une ligne par
    champ, séparateur `\n`, UTF-8. Les trois champs ajoutés sont du base64, dont
    la forme exclut déjà `|` et le retour à la ligne : l'encodage reste injectif
    par la même raison, et la notice imprimée le décrit en trois lignes.
    """
    verifier_chiffres(coffre)
    lignes = [entete_canonique(coffre, bornes=bornes).decode("utf-8"),
              "nonce=%s" % coffre["nonce"],
              "contenu=%s" % coffre["contenu"]]
    for s in coffre["serrures"]:
        lignes.append("scelle=%s|%s" % (s["nonce"], s["enveloppe"]))
    return "\n".join(lignes).encode("utf-8")


def signer(prive, coffre: dict, bornes: bool = True) -> str:
    """Signe le coffre. La signature est rendue BRUTE — r et s sur 32 octets chacun.

    C'est le format de WebCrypto. `cryptography` rend du DER, que le navigateur
    ne sait pas lire : la conversion a lieu ici, une fois, plutôt que dans une
    notice qui devrait alors décrire l'ASN.1.
    """
    r, s = decode_dss_signature(prive.sign(message_signe(coffre, bornes),
                                           ec.ECDSA(hashes.SHA256())))
    return b64(r.to_bytes(32, "big") + s.to_bytes(32, "big"))


def signature_tenue(coffre: dict, bornes: bool = True) -> bool:
    """Vrai si le coffre est exactement celui que la fabrique a scellé."""
    try:
        brut = base64.b64decode(coffre["signature"])
        if len(brut) != 64:
            return False
        pub = ec.EllipticCurvePublicKey.from_encoded_point(
            ec.SECP256R1(), base64.b64decode(coffre["cle_publique"]))
        pub.verify(encode_dss_signature(int.from_bytes(brut[:32], "big"),
                                        int.from_bytes(brut[32:], "big")),
                   message_signe(coffre, bornes), ec.ECDSA(hashes.SHA256()))
        return True
    except (InvalidSignature, ValueError, KeyError, TypeError, AttributeError, IndexError):
        return False


def empreinte_sceau(coffre: dict) -> str:
    """Ce qui rattache un coffre à l'acte de fabrication qui l'a produit.

    Le sceau prouve qu'un coffre n'a pas bougé ; il ne prouve pas d'où il vient.
    La clé publique naît dans le coffre et ne renvoie à rien d'extérieur : qui
    connaît un code d'ouverture — il est imprimé sur le pli — peut fabriquer un
    coffre entièrement neuf, cohérent et scellé. Ce qui les distingue est la paire
    tirée à la fabrication, neuve à chaque coffre.

    On empreinte la clé publique et non le FICHIER : l'empreinte d'un fichier
    change dès qu'un outil réécrit le JSON avec un autre espacement, ce qui
    déclencherait de fausses alertes chez quelqu'un dont le coffre est intact.

    Rendue en groupes de quatre, la forme sous laquelle le pli l'imprime.
    """
    h = hashlib.sha256(base64.b64decode(coffre["cle_publique"])).hexdigest()[:32]
    return " ".join(h[i:i + 4] for i in range(0, 32, 4))


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
# mots ou 6⁵ = 7 776 : le même plancher demande huit mots sur la longue et dix
# sur la courte. Un plancher en mots serait donc juste sur une liste et faux sur
# l'autre.
#
# Et l'entropie se calcule sur les mots DISTINCTS, jamais sur le nombre
# d'entrées : une liste de 7 776 lignes portant toutes le même mot ferait
# annoncer l'entropie de 7 776 mots pour un seul.
#
# La liste vit chez SelfRecover : une seule copie normative dans le dépôt.
#
# La valeur se lit face à la plus grosse machine SHA-256 en service : le réseau
# Bitcoin, 8,62·10²⁰ H/s relevés le 06/09/2026, soit 2,16·10¹⁵ essais PBKDF2 à
# 600 000 itérations par seconde. À ce rythme, 77 bits tombent en 1,1 an — et en
# 9,5 heures après vingt ans de progrès matériel au doublement bisannuel. Le pli
# promet vingt ans : le plancher doit les tenir.
BITS_MIN = 96.0                    # 8 mots sur la liste EFF longue ; 10 sur la courte
MOTS_DEFAUT = 8                    # au-dessus du plancher sur les deux listes diceware


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
        # 🔑 La distinction se mesure APRÈS `normaliser()`, parce que c'est cette
        # forme-là que la KDF reçoit. Deux entrées qui ne diffèrent que par un
        # tiret ou une apostrophe — `au-delà` et `audelà`, `aujourd'hui` et
        # `aujourdhui` — sont un seul mot pour la clé dérivée. Une liste de
        # 2 592 racines en trois variantes annonçait 90,5 bits pour 79,4 réels.
        doublons = len(mots) - len({normaliser(m) for m in mots})
        if doublons:
            raise ValueError("%s porte %d doublon(s) une fois normalisés : l'entropie se "
                             "calcule sur ce que la dérivation reçoit, et elle ne reçoit "
                             "ni tiret ni apostrophe" % (c, doublons))
        if len(mots) > MOTS_MAX:
            raise ValueError("%s porte %d mots : le tirage sans biais du navigateur en "
                             "accepte %d au plus. Au-delà, sa borne de rejet tombe à zéro "
                             "et l'onglet se fige sans rien dire."
                             % (c, len(mots), MOTS_MAX))
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
              contenu_sans_aad=False, bornes=True,
              sans_signature=False, signature_de=None, cle_signature=None) -> dict:
    """Fabrique un coffre. `serrures` est une liste de (nom, secret).

    `version` et `date` sont exigés, sans valeur par défaut. Sous leur forme
    précédente ils étaient des littéraux : tout coffre naissait « version 1 du
    2026-09-04 », y compris fabriqué des années plus tard — et l'AAD certifiait
    cette date. Or le pli demande au dépositaire de détruire l'ancien pli d'après
    ce numéro : deux plis portant le même n'étaient pas départageables.

    `sans_signature`, `signature_de` et `cle_signature` ne servent qu'au banc : le
    premier fabrique un coffre non scellé, le deuxième un coffre dont la signature
    est celle d'une AUTRE clé que celle qu'il publie. Sans eux, la vérification du
    sceau serait inéprouvable.

    Le troisième impose la paire au lieu d'en tirer une, et il répond à un piège :
    le sceau intercepte désormais tout en-tête retouché AVANT l'AAD. Sans le
    moyen de re-sceller un coffre falsifié, l'AAD ne serait plus éprouvé par
    rien — il resterait juste, et plus rien ne le dirait.

    `maitresse`, `engagement_de` et `contenu_sans_aad` ne servent qu'au banc : ils
    permettent de fabriquer un coffre dont l'engagement porte sur une autre clé,
    ou dont le contenu n'est pas lié à l'en-tête — les seuls moyens d'éprouver ces
    deux vérifications, qu'un en-tête simplement retouché ne fait pas rougir.
    """
    if not isinstance(version, int) or isinstance(version, bool) or version < 1:
        raise ValueError("version : entier positif attendu, reçu %r" % (version,))
    if not re.fullmatch(DATE, str(date)):
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
    maitresse = maitresse or alea(32)
    # 🔑 La paire de signature naît ici et meurt à la fin de cette fonction. La
    # clé privée n'est écrite nulle part, ne quitte pas ce processus, et n'est
    # remise à personne — pas même à la titulaire. C'est ce qui rend le coffre
    # IMMUABLE : plus personne au monde ne peut en produire une autre version qui
    # se dise authentique.
    prive = cle_signature or ec.generate_private_key(ec.SECP256R1())
    coffre = {
        "format": FORMAT,
        "version": version,
        "date": date,
        "engagement": engagement(engagement_de if engagement_de is not None else maitresse),
        "cle_publique": b64(prive.public_key().public_bytes(
            Encoding.X962, PublicFormat.UncompressedPoint)),
        "serrures": [{"nom": nom, "sel": b64(alea(16)),
                      "iterations": iterations, "nonce": b64(alea(12))}
                     for nom, _ in serrures],
        "nonce": b64(alea(12)),
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
    # Le sceau vient en dernier : il couvre l'en-tête ET les chiffrés, que l'AAD
    # ne peut pas couvrir puisqu'il leur sert d'entrée.
    if not sans_signature:
        coffre["signature"] = signer(signature_de or prive, coffre, bornes=bornes)
    return coffre
