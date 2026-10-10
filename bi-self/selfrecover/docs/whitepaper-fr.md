# SelfRecover — Whitepaper v1.5

**Protocole de récupération de compte sans email**
*Ton mot. Tes sites. Sans email.*

*Édition du 10 octobre 2026 — v1.5 — décrit SelfRecover 0.12.1*

---

## Contexte

Le schéma qui coûte le plus cher est connu, et il se répète : une faille d'autorisation triviale — changer un identifiant dans une requête d'API suffit à lire le compte d'un autre — sur un service dont la récupération de compte passe par un canal email. L'ampleur ne tient alors qu'au nombre de lignes de la table.

La question est structurelle : pourquoi un service doit-il indexer une boîte mail pour établir qu'on est soi ? Tant qu'une boîte tierce est dans la chaîne de récupération, sa compromission devient l'angle d'attaque dominant. SelfRecover propose une réponse technique : supprimer le canal email (mode **Full**, le seul implémenté). Un mode **Lite**, qui le gardait en option, a été montré en démonstration en v0.1.1 ; cette démonstration a été retirée le 18 août 2026. Publié en avril 2026 (v0.1.0), sous AGPL-3.0-or-later depuis le 19 avril 2026.

Ce whitepaper décrit le protocole. Il ne vise aucun acteur en particulier — c'est une proposition open-source que les opérateurs publics et privés peuvent auditer, intégrer ou contester librement.

---

## Résumé

SelfRecover est un protocole de récupération de compte à connaissance partagée qui élimine la dépendance à l'email pour la réinitialisation de mot de passe. Il repose sur une dérivation HMAC-SHA256 effectuée côté client, clavée par le mot de récupération lui-même, dont le message porte le matériel de dérivation, la version du format et le sel du compte : le dériveur livré ne fait sortir du navigateur que l'empreinte du mot de récupération, et le serveur n'en range qu'un hachage Argon2id, propre au service et au compte (ce qui empêche la corrélation des empreintes entre services). Le mot de passe et la passphrase, eux, passent par le serveur : il engendre le mot de passe, sauf au niveau 3 où l'utilisateur le choisit, et la passphrase quand l'utilisateur n'apporte pas la sienne. Ce document décrit le protocole, son escalade en trois niveaux, son modèle de menaces, et les règles de déploiement obligatoires.

---

## 1. Le problème

Toute application web fait face à la même question : *que se passe-t-il quand un utilisateur oublie son mot de passe ?*

Depuis vingt ans, la réponse de l'industrie est toujours la même : **envoyer un email**. Cela crée une chaîne de dépendances :

- L'application doit intégrer un service SMTP (SendGrid, Mailgun, AWS SES, ou du self-hosted)
- L'utilisateur doit avoir une adresse email valide et la partager avec le service
- L'email doit réellement arriver (filtres anti-spam, greylisting, délivrabilité)
- Le lien de reset doit être cliqué dans un délai (15 à 60 minutes)
- Le modèle de sécurité est externalisé vers un tiers (Gmail, Outlook, ProtonMail)

**Pourquoi un site web a-t-il besoin d'un email pour établir qu'on est soi ?**

SelfRecover propose une autre réponse : la confiance reste entre l'utilisateur et le site. Pas d'intermédiaire. Pas d'email. Pas de tiers.

---

## 2. Le modèle SelfRecover

**Principe fondamental :**

> Mot de récupération seul = rien.
> Algorithme seul = rien.
> Mot de récupération + Algorithme = une empreinte que le serveur sait vérifier — un des deux facteurs du niveau 2.

SelfRecover est un système de récupération à connaissance partagée (split knowledge). L'utilisateur retient un mot. Le système fournit l'algorithme. Aucun des deux n'a de valeur sans l'autre.

**Ce que l'utilisateur retient :** un seul mot, qui ne s'écrit nulle part.

**Ce que l'utilisateur garde sur papier :** ses recovery codes (niveau 2) et sa passphrase diceware (niveau 1). Ils sont tirés au hasard — par le serveur, ou aux dés par l'utilisateur pour la passphrase (§5.5) ; personne n'a à les retenir.

Le mot seul ne rouvre aucun compte : au niveau 2, il faut aussi un recovery code ou l'appareil enrôlé (§5.4).

---

## 3. Fonctionnement technique

### 3.1 Inscription

Quand un nouveau compte est créé, le mot de récupération est immédiatement traité par dérivation HMAC-SHA256, dans le navigateur. Avec le dériveur livré, le mot brut n'atteint pas le serveur.

```
clé_dérivée = HMAC-SHA256(clé = mot_récupération, message = matériel + "|v2" + sel_compte)
```

Le `matériel` dépend d'un mode de dérivation obligatoire, décrit en §4. La sortie fait 64 caractères hexadécimaux minuscules.

Le serveur reçoit et stocke :

- `Argon2id(mot_de_passe)` — le mot de passe de connexion
- `Argon2id(passphrase)` — une passphrase diceware, engendrée côté serveur ou apportée par l'utilisateur, tirée aux dés (§5.5) (6 mots au moins, ≈ 77,5 bits pour six mots tirés uniformément ; une passphrase plus courte, émise avant le passage à six mots, reste valide jusqu'à son usage)
- `Argon2id(clé_dérivée)` — la clé de récupération dérivée par HMAC
- `sel_compte` — le sel du compte : 16 octets aléatoires rendus en 32 hexadécimaux minuscules, un par compte, engendré par le navigateur, pas un secret
- les 10 codes de récupération du premier lot, chacun sous deux formes (§5.4)

Toutes les empreintes Argon2id du serveur (`Hashing::hash`) suivent un seul profil : 64 Mio, 4 itérations, 2 fils (`Hashing::ARGON2`). Les codes portent en plus un index HMAC sous le sel du déploiement (§5.4), et le code de suivi du niveau 3 une empreinte SHA-256.

L'utilisateur reçoit la passphrase et ses codes une seule fois, et les conserve sur papier, hors ligne.

### 3.2 Authentification

La connexion ordinaire appartient à l'application : la bibliothèque n'en fournit pas. Elle exige seulement de pouvoir révoquer les sessions d'un compte (`revoquerSessions()`), ce que fait chaque récupération réussie.

### 3.3 Récupération

Trois niveaux, chacun avec ses propres garanties et modes d'échec :

| Niveau | Entrée | Résultat en cas de succès |
|--------|--------|---------------------------|
| **L1** | Nom du compte + passphrase diceware | Nouveau mot de passe et nouvelle passphrase |
| **L2** | Code de récupération + mot de récupération (dérivé HMAC) | Nouveau mot de passe et nouvelle passphrase |
| **L2, voie appareil** | Appareil enrôlé + mot de récupération | Nouveau mot de passe ; passphrase et codes inchangés |
| **L3** | Nom du compte + réponses de contexte | Faits bruts pour un arbitre humain ; en cas d'accord, l'utilisateur choisit son mot de passe et son mot mémorisé, le serveur émet 10 codes neufs et une passphrase neuve, tirée ou apportée, et retire les appareils enrôlés |

---

## 4. Dérivation HMAC — Un mot, unique partout

C'est l'innovation centrale de SelfRecover.

Quand l'utilisateur tape son mot de récupération, le navigateur calcule une clé dérivée spécifique au service **avant que quoi que ce soit ne quitte le client**. Le composant livré qui porte ce calcul est `client/sr-derive.js` ; les vecteurs figés que toute réimplémentation doit retrouver vivent dans `tests/vecteurs-derivation.json`.

```javascript
// Forme abrégée de client/sr-derive.js. Le mot mémorisé va en CLÉ, jamais en message.
const VERSION = 'v2';

function matériel(mode, label) {
    // Lu dans le navigateur, jamais reçu du réseau.
    if (mode === 'hostname') return location.hostname.toLowerCase();
    if (mode === 'label' && label) return label;      // pris tel quel ; vide, il lève
    throw new Error("mode obligatoire : 'hostname' ou 'label' — il n'y a pas de défaut");
}

async function srDerive(mot, sel, { mode, label }) {
    if (!/^[0-9a-f]{32}$/.test(sel)) {
        throw new Error('sel obligatoire — 32 hexadécimaux, un par compte');
    }
    const enc = new TextEncoder();
    const message = matériel(mode, label) + '|' + VERSION + sel;
    const clé = await crypto.subtle.importKey(
        'raw', enc.encode(mot),
        { name: 'HMAC', hash: 'SHA-256' },
        false, ['sign']
    );
    const sig = await crypto.subtle.sign('HMAC', clé, enc.encode(message));
    return Array.from(new Uint8Array(sig))
        .map(b => b.toString(16).padStart(2, '0')).join('');
}
```

**Propriétés clés :**

- Le même mot donne une empreinte différente pour chaque compte (le sel) et pour chaque matériel : chaque nom d'hôte en mode `'hostname'`, chaque label en mode `'label'`
- Avec le dériveur livré, le mot brut ne quitte pas le navigateur
- Le serveur ne reçoit que la clé dérivée. C'est lui qui sert le dériveur : un serveur compromis pourrait servir une autre page (§10.2)
- Le résultat fait toujours 256 bits, quelle que soit la longueur de l'input
- Fonctionne sur tous les appareils — mêmes maths, même résultat
- La version vit **dans le message** : pendant une bascule, un service peut dériver sous deux versions et accepter les deux
- Chaque compte a son propre sel, engendré par le navigateur et non secret : il empêche que deux personnes ayant choisi le même mot produisent la même empreinte

### 4.1 Le mode de dérivation — obligatoire, sans défaut

Le `matériel` décide de ce à quoi l'empreinte est liée, et l'arbitrage dépend du transport. La bibliothèque ne le tranche pas à la place de l'intégrateur, et surtout pas par un défaut : un défaut est choisi par tout le monde, donc c'est trancher sans le dire. Un appel sans mode lève.

| Mode | Le `matériel` vaut… | Ce qu'il apporte | Ce qu'il coûte |
|------|--------------------|------------------|----------------|
| `'hostname'` | `location.hostname`, **lu dans le navigateur**, mis en minuscules | Anti-hameçonnage réel : une page qui imite le service obtient une empreinte dérivée de **sa propre** adresse, sans valeur contre le vrai serveur | Perdre l'adresse, c'est perdre la récupération L2 de tous les comptes. Le prix n'est pas le même partout : sur le web ordinaire un domaine s'expire, se perd, se rachète ; sur un service caché v3 l'adresse **est** la clé publique du service, elle ne dépend d'aucun registrar |
| `'label'` | une étiquette stable fournie par l'intégrateur, prise **telle quelle** | Survit à un changement d'adresse | **Aucun anti-hameçonnage.** Une copie servie ailleurs, avec le même label, produit exactement l'empreinte que le serveur a enregistrée |

Le matériel doit être **lu** dans le navigateur, jamais reçu du réseau : un matériel que le serveur fournit est un matériel que n'importe quel serveur peut fournir, y compris celui d'une page qui imite le service.

**Résistance au phishing passif — en mode `'hostname'` seulement.** Le clone recopie la page, donc recopie la bibliothèque, qui lit alors l'adresse du clone : la clé obtenue ne vaut rien contre le vrai serveur, et le clone n'a rien fait de mal pour cela. En mode `'label'` cette résistance n'existe pas — le label voyage avec la copie. La limite honnête, valable dans les deux modes : un site de phishing actif qui contrôle sa propre page récolte le mot brut et dérive ensuite ce qu'il veut (hors périmètre, comme tout protocole in-browser) ; et un mot brut réutilisé ailleurs reste réutilisable — la dérivation ne sauve pas un secret connu et réutilisé.

### 4.2 La route du sel — elle répond toujours

Au niveau 2, le navigateur dérive avant que le compte soit identifié : c'est le code qui l'identifie. Il lui faut donc d'abord le sel du compte, qu'il demande en présentant le code, sur une route publique et sans authentification. Elle répond **toujours** un sel : celui du compte pour un code connu, consommé ou non ; sinon un faux, de même forme, stable d'un essai à l'autre, tiré du sel du déploiement et calculé dans les deux cas. Une route qui refuserait un code inconnu permettrait d'éprouver les codes au prix d'une requête, sans payer un seul Argon2id.

La bibliothèque n'expose pas de route ; elle fournit la garde, `Recovery::selDeDerivation($code)`, et l'interface facultative `SelParCodeInterface`, que seul le stockage qui sert cette route doit implémenter.

---

## 5. Escalade de récupération en 3 niveaux

### 5.1 Niveau 1 — Mot de passe oublié

- L'utilisateur fournit : le nom du compte + la passphrase diceware (exacte, aux espaces près ; le nom est mis en minuscules)
- En cas de succès : un mot de passe **et** une passphrase neufs, rendus une seule fois ; l'ancienne passphrase ne vaut plus rien et les sessions ouvertes tombent. Le mot mémorisé et les appareils enrôlés ne changent pas. L'âge de la passphrase qui vient de servir est rendu : il informe, il ne refuse jamais
- L'affichage (masqué par défaut, confirmation `"J'ai noté"`) relève de la page
- Rate limit : 12 échecs / 15 minutes par adresse (valeurs par défaut, réglées par l'intégrateur), et **aucun frein par compte**. Un tel compteur vivrait dans une table que l'intégrateur partage avec sa page de connexion : n'importe qui le remplirait sous le nom d'un tiers et fermerait la seule voie qu'un titulaire puisse emprunter seul. La règle retenue est que **le frein ne refuse jamais le bon secret** ; le compteur subsiste et n'alimente plus qu'un signal. Le frein par adresse n'existe que sous le profil de déploiement `clearweb` : derrière un service caché, rien ne borne le nombre d'essais, et lisser le débit de la route appartient au déploiement (§11.3, où il devient la seule borne de ce niveau). Le profil est obligatoire, sans défaut
- Classement silencieux : un essai dont **tous les mots existent** dans les listes, devant une porte qui ne s'ouvre pas, est compté sous une étiquette à lui. `essaisPlausiblesL1()` rend ce compte et `ESSAIS_PLAUSIBLES_SIGNALES` dit à partir de combien — 3 — il y a de quoi réveiller quelqu'un ; la bibliothèque ne compare rien à ce seuil et ne décide d'aucun accès. Le message, le délai et le nombre d'essais ne changent pas. ⚠️ **Ce compte ne voyage jamais dans la réponse** : une réponse part sur le réseau, et qui connaît un nom de compte public y lirait qu'un tiers cherche en ce moment l'ordre de mots qu'il possède. Les listes sont publiques ; les essais des autres ne le sont pas. Le compteur est de plus remplissable par qui connaît le nom : rien ne doit en dépendre
- Anti-bot : hors de portée de la bibliothèque — le champ honeypot et le contrôle de timing vivent sur la page

### 5.2 Niveau 2 — Passphrase perdue (2FA sans identifiant)

Le L2 est un **vrai 2FA** — possession **et** connaissance — **sans identifiant à retenir** :

- **Possession** : un *recovery code* (parmi les 10 remis à l'inscription). Il **localise** le compte via un lookup HMAC (plus d'énumération) et sert de facteur de possession.
- **Connaissance** : le *mot mémorisé*, dérivé HMAC côté client (avec le dériveur livré, le mot brut ne quitte pas le navigateur).

Le serveur vérifie les **deux** (Argon2id) et renvoie une **erreur générique** ne révélant jamais lequel a échoué. En cas de succès, le code est consommé ; le serveur engendre un mot de passe neuf, et une passphrase neuve si l'utilisateur n'apporte pas la sienne (§5.5) ; les deux sont rendus une seule fois, et les sessions ouvertes tombent. Il rend aussi le nom du compte et le nombre de codes restants. Une variante optionnelle — le **facteur « cet appareil »** — offre une seconde voie de L2 (voir §5.4).

Aucune bascule automatique vers L3. Le niveau 2 ne demande aucun identifiant, mais le code qu'il reçoit nomme son compte, et c'est ce que compte son frein par compte : une fenêtre courte (5 échecs en 15 minutes par défaut), puis, après 20 échecs depuis le dernier réarmement, la suspension de la récupération par code pour ce compte. Elle se lève par un lot de codes neuf — le niveau 3 en émet un —, par une récupération par code réussie, ou par une récupération par passphrase réussie. Ce refus-là nomme son état, sinon le titulaire ne saurait pas quoi faire ; il apprend donc à qui détient déjà un code de ce compte que ce code vise un compte réel. Le compteur par adresse s'y ajoute sous le profil `clearweb`. C'est la personne qui décide d'ouvrir un dossier.

### 5.3 Niveau 3 — Accès totalement perdu

- Entrée : lien discret `"J'ai perdu tous mes accès"` sur la page de login
- L'utilisateur fournit le nom de son compte (celui du niveau 1) ; son navigateur génère un **code de suivi** (sésame). À l'ouverture, seule son empreinte (SHA-256) part, et c'est tout ce que le serveur range ; aux étapes suivantes, le code lui-même se présente et le serveur le hache à chaque fois — un journal des corps de requête le capterait donc (anti-timing : délai forcé)
- Un litige au numéro **non devinable** (`LIT-` suivi de 16 hexadécimaux) est ouvert. Si un litige est déjà ouvert pour ce compte, ou si l'ouverture y est gelée (§6.1), le demandeur reçoit le refus d'un nom inconnu, au même délai (`ouverture_refusee`) : le numéro n'est **pas redivulgué**, et rien n'apprend à un tiers qu'un dossier existe. La tentative concurrente est signalée à l'admin (« multi-demandeur »)
- L'utilisateur répond à quelques **questions de contexte** (année de création du compte, période de dernière connexion, fréquence d'usage) — **aucun secret n'est demandé**
- Le serveur assemble un **faisceau de faits bruts** présenté à l'administrateur :
  - **Contexte** : ce que le serveur tient déjà — création du compte, dernière connexion, nombre de connexions, codes restants, refus récents, et ce que l'adaptateur du déploiement ajoute, que la bibliothèque n'interprète pas
  - **Déclaratif** : chaque réponse confrontée au réel, marquée `concorde`, `diverge` ou `indisponible` — ce dernier quand le serveur ne tient pas la donnée, ce qui n'est pas une divergence
  - **Avertissement** : il rappelle à l'arbitre que ces réponses sont devinables ; elles orientent la conversation, elles ne prouvent rien
  - Le faisceau ne porte **aucun signal passif** : ni adresse, ni empreinte de navigateur
- **Aucun score chiffré n'est calculé.** Ces faits n'ouvrent **jamais** le compte automatiquement, ils aident seulement un **administrateur humain** à trancher dans le chat
- Cooldown : 1 heure entre chaque soumission
- Ouverture freinée avant toute recherche du compte : 10 par adresse, par heure (valeur par défaut). Un plafond de 20 pour tout le service existe aussi, mais il n'est lu **que sous le profil `tor-onion`**, où aucune adresse ne discrimine : sous `clearweb` le frein par adresse mord toujours, et un plafond global n'y ajouterait qu'un interrupteur général que des requêtes anonymes suffisent à tirer (§6.1). Pas de frein par compte, exprès : un tiers pourrait sinon fermer le niveau 3 au titulaire sans que rien n'apparaisse à l'arbitre. Le harcèlement d'un compte se voit autrement : la tentative concurrente se compte et se montre
- Le code de suivi se présente à chaque étape — dépôt des réponses, fil de discussion, état, reprise. Le dossier expire au bout de 24 h tant que personne n'a tranché. Un accord court 7 jours à partir de la décision ; passé ce délai, il tombe et l'arbitrage est à refaire. La reprise clôt le dossier et efface l'empreinte du code de suivi. Si le titulaire a perdu son code de suivi, un arbitre peut abandonner le dossier en cours : cela ne rend aucun accès, cela libère la place pour un dossier neuf

### 5.4 Les foyers de possession de L2 — recovery codes & facteur appareil

Le L2 combine toujours **connaissance** (le mot mémorisé) et **possession**. Deux foyers de possession sont proposés ; l'utilisateur en dispose d'au moins un.

**Recovery codes — le foyer papier universel.**
- Un lot de **10 codes** est généré à l'inscription et affiché **une seule fois** (format `xxxxx-xxxxx` : 10 hexadécimaux minuscules, 40 bits chacun).
- Stockage double, jamais en clair : `code_lookup = HMAC-SHA256(clé = sel du déploiement, code)` (recherche sans identifiant) **et** `code_hash = Argon2id(code)` (vérification + résistance à une fuite de base). Le sel du déploiement se garde comme un secret de service, hors racine web, et ne tourne pas sans réémettre toutes les feuilles : le changer rend introuvables tous les codes émis.
- **Usage unique**, régénérables à la demande (le nouveau lot remplace l'ancien). Ils se gardent sur papier, rangés hors ligne.

**Facteur « cet appareil » — le foyer cryptographique, optionnel.**
- Une paire **ECDSA P-256** est générée dans le navigateur. La clé privée est **chiffrée au repos** par une clé AES-256-GCM dérivée du **mot mémorisé** via **Argon2id** en JavaScript (t=3, m=64 Mio, p=1, sous un sel propre au blob), écrit dans la bibliothèque et confronté aux vecteurs de libsodium — aucun binaire embarqué. **Ce profil est celui de SelfDataGuard** (`Primitives::ARGON2_OPSLIMIT` et `ARGON2_MEMLIMIT`), et un banc tient les deux égaux : `p=1` est le seul degré de parallélisme que `sodium_crypto_pwhash` sache produire, donc le seul profil dont l'oracle PHP puisse vérifier les vecteurs — c'est ce qui permet de tenir deux langages d'accord sur les mêmes empreintes. Le blob obtenu porte sa version et ses paramètres de dérivation ; un blob qui annonce des paramètres sous ce plancher est refusé. Le lieu de rangement relève de l'intégration : l'implémentation de référence emploie `localStorage`, IndexedDB restant préférable et à faire.
- Le serveur ne détient **que la clé publique**. La récupération consiste à **signer un défi** (32 octets, TTL 5 min, usage unique) : le navigateur déchiffre la clé privée avec le mot, signe, le serveur vérifie.
- Sur cette voie, le serveur ne vérifie pas le mot : il vérifie une signature. Le mot n'y est tenu que par le chiffrement du blob — un blob volé se travaille hors ligne, sans compteur d'essais, au seul coût d'Argon2id. En cas de succès, un mot de passe neuf est rendu ; la passphrase et les codes ne changent pas.
- C'est un 2FA cryptographique **appareil + connaissance**, sans TPM ni matériel. Protection **logicielle** (assumée), device-bound. **À désactiver en profil Tor/onion**, où le stockage local ne survit pas à la session — c'est un choix d'intégration, non un automatisme à ce jour. Le recovery code papier reste le plancher.
- ⚠️ **Enrôler un appareil n'ajoute pas un facteur : à cet instant, le mot suffit.** Qui connaît le mot mémorisé peut enrôler **sa propre** clé, puis s'authentifier avec elle — le chemin ne passe ni par un recovery code, ni par la passphrase. L'enrôlement appartient donc à une **session déjà ouverte**, et il revient à l'application d'en tirer le nom du compte plutôt que du corps de la requête. Le protocole exige de l'application qu'elle l'**affirme** explicitement (`Titulaire::AUTHENTIFIE`), et freine ce chemin par compte et par adresse (5 et 12 échecs sur 15 minutes, valeurs par défaut) ; il ne peut pas vérifier la session lui-même — **c'est une affirmation, pas une preuve**. Un appareil déjà enrôlé, lui, reste deux facteurs réels : son blob chiffré et le mot.
- La reprise au niveau 3 retire tous les appareils enrôlés ; les niveaux 1 et 2 ne les retirent pas.

### 5.5 La passphrase apportée par l'utilisateur

Partout où une passphrase est émise — l'inscription, et les renouvellements des niveaux 1, 2 et 3 —,
l'utilisateur peut apporter la sienne, tirée aux dés, au lieu d'en recevoir une tirée par le serveur.
Rien d'apporté : le serveur tire, comme avant.

- **Le contrôle** (`Recovery::validerPassphraseApportee()`) : six mots au moins, chacun dans la liste
  anglaise de l'EFF ou dans la liste française d'Arthur Pons, aucun répété, un plafond d'octets. La
  passphrase est rangée sous une forme unique : minuscules, une espace entre les mots. C'est cette
  forme qu'on hache, qu'on rend et qu'on fait noter, parce que la vérification ne convertit pas la saisie en minuscules.
- **Le refus de forme** dit la position d'un mot, jamais le mot. Il est jugé avant tout frein, sans
  trace ni délai : il ne dépend que de la saisie, pas du compte, et le tracer laisserait n'importe qui
  charger le frein d'un autre. Aucun refus de passphrase ne consomme le code du niveau 2 ni le dossier
  du niveau 3.
- **L'ancienne passphrase ne revient pas**, jugé une fois les facteurs vérifiés : au niveau 1, ni
  telle quelle ni ses mots dans un autre ordre ; aux niveaux 2 et 3, telle quelle. Au niveau 3, elle ne peut
  pas non plus égaler le mot de passe choisi. La bibliothèque ne garde pas d'historique : une
  passphrase plus ancienne que la dernière n'est pas reconnue.
- **Ce que le contrôle ne mesure pas : le hasard.** Six mots choisis de tête passent, et ne valent pas
  six mots tirés. La même passphrase scelle la serrure « passphrase » du coffre SelfDataGuard, qu'on
  attaque hors ligne : une passphrase devinable y devient la porte la moins chère.

À l'inscription, qui appartient à l'application, celle-ci passe la saisie au contrôle et hache la
forme rendue. Aux renouvellements, elle la passe en `nouvellePassphrase` à `parPassphrase()`,
`parCode()` ou `Escalade::reEnroler()`.

---

## 6. Système de litiges et interface admin

Un dossier (`LIT-` suivi de 16 hexadécimaux) s'ouvre quand la personne le demande, jamais automatiquement. Avec l'adaptateur fourni, il apparaît dans la console d'arbitrage dès son ouverture ; son faisceau, dès le dépôt des réponses (`awaiting_admin`).

- Chaque litige a un numéro **non devinable**, le faisceau de faits (bruts, jamais un score), des compteurs de tentatives et de refus, un compteur de tentatives concurrentes (« multi-demandeur »), et un statut (`open`, `awaiting_admin`, `accepted`, `refused`, `closed`)
- L'admin retrouve les litiges ouverts dans son tableau de bord
- Un chat bidirectionnel est disponible entre l'admin et l'utilisateur, dont l'accès est conditionné par le code de suivi (polling, pas de WebSocket temps réel pour rester simple)
- `purger()` efface les dossiers périmés — ni les refusés, dont le compte informe l'arbitre, ni les acceptés, qu'un titulaire peut encore venir consommer. 🔴 **Rien dans la bibliothèque ne l'appelle** : elle n'a pas d'horloge, et aucun de ses chemins n'invoque cette méthode. C'est au déploiement de la lancer périodiquement — une unité `systemd` prête à poser vit dans `deploy/bi-self/`, et l'outil qu'elle lance dans `bi-self/selfrecover/tools/purger.php`.

### 6.1 Clôture du litige — Décision admin

Quand l'admin examine un litige, deux options existent :

**Option 1 — Accorder la récupération (débloquer) :**

- L'admin vérifie l'identité via l'échange chat
- Le litige passe en `accepted`. **Le serveur ne génère ni ne transmet aucun mot de passe** : aucun secret ne circule dans le chat
- L'utilisateur **re-définit lui-même** son mot de passe et son mot mémorisé depuis sa page de récupération (modèle de ré-enrôlement). Le mot de passe fait entre 12 et 4 096 caractères ; il est soumis en clair, et le serveur le range sans l'émettre. Le navigateur engendre un nouveau sel et dérive le mot mémorisé, qui n'arrive jamais en clair. Le serveur engendre 10 codes neufs, et une passphrase si le titulaire n'apporte pas la sienne (§5.5), affichés une fois, coupe les sessions et retire tous les appareils enrôlés — le titulaire réenrôle celui qu'il utilise. Le dossier passe en `closed` et l'empreinte du code de suivi est effacée

**Option 2 — Refuser la récupération :**

- L'admin ne considère pas la preuve d'identité suffisante
- Le dossier passe en `refused`, avec la date et le nom de qui a tranché
- **Le compte n'est pas touché** : ni supprimé, ni banni, ni vidé de ses codes. Il reste connectable
- Le refus est **compté**, et ce compte est rendu à l'arbitre : au **3ᵉ dans une fenêtre glissante de 30 jours**, la réponse porte `gel_suggere`. **Aucun gel n'est posé par ce compteur.** C'est un administrateur qui gèle l'ouverture, explicitement, pour 7 jours ; il la rouvre quand il veut, et la trace du dégel est conservée. Pendant le gel, une demande d'ouverture reçoit le refus d'un nom inconnu (§5.3) : le gel ne se lit pas du dehors

🔑 **Ce qui se durcit est la procédure, jamais le compte.** Une version antérieure de ce document annonçait un ban de 24 h et la suppression définitive au 3ᵉ refus ; l'implémentation qui s'en approchait le plus supprimait le compte dès le **premier**. Les deux étaient fautives pour la même raison : un refus dit « ce demandeur ne m'a pas convaincu », pas « ce compte est illégitime ». Si le demandeur était un imposteur, supprimer détruit le compte de sa victime ; s'il était le titulaire mal jugé, cela punit un innocent. Et un attaquant incapable de voler un compte pouvait le faire effacer en accumulant des refus — **l'échec devenait une arme**.

**Raisonnement, corrigé en 0.12.0.** Ce document affirmait que « le gel coûte à qui insiste sans convaincre, sans rien coûter au titulaire, qui continue de se connecter normalement ». Les deux moitiés étaient fausses.

La première : le compteur de refus porte sur le **compte visé**, jamais sur le demandeur. Or l'empreinte du sésame est choisie par l'appelant, et le nom d'un compte est semi-public : un tiers ouvrait trois dossiers sur un nom affiché, se faisait refuser trois fois, et le gel tombait sur le titulaire.

La seconde : « il continue de se connecter normalement » décrit quelqu'un qui n'existe pas à cette étape. Le niveau 3 s'adresse à qui n'a plus ni mot de passe, ni passphrase, ni feuille de codes. La phrase « le compte n'est pas touché » reste vraie — les secrets sont intacts — mais la conclusion qu'on en tirait ne l'est pas : fermer la procédure de cette personne, c'est fermer sa dernière porte.

D'où la forme actuelle : **le compteur informe, l'arbitre décide.** Un compteur ne sait pas qui insiste ; un humain qui lit le faisceau, les dossiers concurrents et le fil, si. Le comptage porte toujours sur les **dossiers refusés** et non sur les dépôts : trois soumissions dans un même dossier restent un seul refus, sinon l'insistance d'un titulaire honnête pèserait autant qu'une campagne hostile.

### 6.2 Super-utilisateur (SU) — gouvernance des administrateurs

Le super-utilisateur n'est pas dans la bibliothèque : le laboratoire l'implémente (`demo/lab/selfrecover-su`, console en ligne de commande). Ce qui suit décrit ce modèle de référence.

SelfRecover gouverne **un seul droit** : celui de trancher les litiges de niveau 3. Deux rôles le portent — l'**administrateur** tranche, le **super-utilisateur** gouverne les administrateurs eux-mêmes.

**Le protocole ne vérifie pas ce droit.** Le schéma de référence ne porte aucune colonne de droit, et le composant d'arbitrage ne connaît ni HTTP, ni sessions, ni le rôle de l'appelant. La qualité d'administrateur est donc une **affirmation de l'application**, reçue telle quelle — la même limite que pour l'enrôlement d'un appareil (§ 5.4).

**Un déploiement peut définir des rôles que SelfRecover ignore.** Une modération de salon, par exemple, qui efface des propos et bannit temporairement sans jamais approcher un dossier de récupération : le protocole n'en sait rien, et cette séparation relève des routes de l'application.

**Ancrage et secret.** Le SU **n'existe pas en base de données** : il est ancré au serveur (l'accès au serveur vaut autorisation). Son secret est **hors base et hors code** — un fichier hors racine web, ou une variable d'environnement. C'est un **modèle de Kerckhoffs** : la sécurité repose sur le secret, jamais sur l'obscurité d'un code qui, lui, est public. Le SU est une **interface en ligne de commande**, jamais exposée sur le web ni en distant.

**Séparation des pouvoirs.** Un administrateur **ne se promeut pas lui-même** : il **propose** la promotion d'un autre compte, et le SU **tranche** (observation obligatoire). Le SU nomme le **premier** administrateur, une seule fois ; ensuite, la base en garde **toujours au moins un** — le dernier ne se révoque qu'en nommant son successeur dans la même transaction. Le SU peut révoquer des administrateurs (une révocation coupe les sessions), **auditer** l'état (croiser la colonne de droit du schéma applicatif avec le journal → détecter les **administrateurs fantômes** et les mettre en **quarantaine automatique**), et, si sa passphrase est perdue, repartir d'une « coquille vide » (révocation de tous les administrateurs, gel du journal). En cas de compromission, un **reset de la base** supprime tous les comptes et le secret SU : un compte trafiqué ne se reconnaît pas de l'intérieur, donc aucun n'est gardé. Les deux resets rouvrent la nomination du premier administrateur.

**Journal d'audit infalsifiable sans trace.** Chaque action du SU est journalisée hors base et hors racine web, en quatre couches : **append-only** au niveau système de fichiers (`chattr +a`), **chaîne de hachage** (toute altération casse la chaîne), **HMAC par entrée** (clé propre à l'instance, `SELFRECOVER_SU_AUDIT_SECRET` — distincte de la passphrase SU : en changer ne rompt pas la chaîne ; elle se tourne par `rotate-audit-key`, qui re-signe le journal sans en changer les empreintes), et **externalisation** vers un canal de notification (action + cible + heure uniquement, jamais le contexte forensique).

---

## 7. Détection anti-abus

**Ce que la bibliothèque applique**

- **Compteurs** : par adresse au niveau 1, par compte et par adresse au niveau 2 et à l'enrôlement d'un appareil, avec la suspension du niveau 2 après 20 échecs ; par adresse et pour tout le service à l'ouverture d'un dossier de niveau 3. Le frein par adresse n'existe que sous le profil `clearweb`. Au niveau 1, le compteur par compte ne ferme plus de porte : il classe
- **Délai forcé** sur chaque refus qui tait un état
- **Message de refus unique**, pour que rien ne trie les comptes qui existent — sauf deux exceptions assumées : la suspension du niveau 2, qui doit se dire, et l'ouverture d'un dossier de niveau 3 (§10.1)

**Ce qui appartient à l'intégrateur**, parce qu'il faut des routes, des pages ou
un navigateur que la bibliothèque n'a pas : le champ honeypot, le contrôle de
timing du formulaire, une preuve de travail devant la route d'ouverture, la
corrélation par empreinte de navigateur, et toute politique de notification ou de
blocage bâtie dessus.

---

## 8. Ce que rend la bibliothèque

Chaque méthode rend `ok`. Tout refus porte un `message` destiné à la personne, la plupart des succès aussi (pas `etat()`, `fil()` ni `ouvrirDefi()`), et certains refus un `error` stable que l'application peut journaliser ou traduire : `invalid_derived_key`, `l2_suspendu`, `trop_de_demandes`, `ouverture_refusee`, `compte_inconnu` (hors ouverture), `sesame_invalide`, `expire`, `accord_perime`, `passphrase_invalide`, `passphrase_deja_servie`, `passphrase_egale_mot_de_passe`, entre autres.

La bibliothèque ne remonte rien elle-même ; elle enregistre seulement les tentatives dont ses freins ont besoin. Ce qu'un rapport de diagnostic contient relève de l'application ; elle ne doit y mettre ni le mot de récupération (brut ou dérivé), ni la passphrase, ni le mot de passe, ni les codes.

---

## 9. Protection contre les attaques actives

Ce qui suit est un motif d'intégration, que ni la bibliothèque ni les démos ne fournissent : il faut des sessions, des rôles et un canal de notification que la bibliothèque n'a pas.

Si un utilisateur légitime se connecte normalement et que le serveur détecte une activité suspecte (tentatives L1 échouées, dossiers d'arbitrage ouverts), un modal s'affiche :

> **Vérification de sécurité**
> Une activité inhabituelle a été détectée sur ton compte.
> *As-tu essayé de récupérer ton compte récemment ?*
> `[ Oui, c'était moi ]`  `[ Non, ce n'était pas moi ]`

- **Oui** → les tentatives échouées sont effacées, l'utilisateur continue normalement ; les dossiers refusés restent comptés, pour informer un arbitre (§6.1)
- **Non** → protection renforcée activée en arrière-plan :
  - Nouveau mot de passe généré et affiché à l'utilisateur
  - Sessions révoquées : qui tenait le compte est éjecté
  - Admin notifié

L'utilisateur voit un message rassurant `"Ton compte est maintenant sécurisé"` — pas un log technique. L'admin gère l'investigation en arrière-plan.

---

## 10. Modèle de menaces et limites

### 10.1 Menaces traitées

- **Phishing passif** — en mode `'hostname'` : un clone dérive de sa propre adresse et obtient une clé différente. En mode `'label'`, aucune protection. Un phishing actif qui contrôle sa page est hors périmètre dans les deux cas
- **Piratage de l'email** — il n'y a aucun email dans le protocole
- **Panne du fournisseur SMTP** — pas de dépendance SMTP
- **Confiance tiers** — seuls le site et l'utilisateur sont impliqués
- **Force brute freinée** — par adresse au niveau 1, par compte et par adresse au niveau 2 et à l'enrôlement, suspension du niveau 2 après 20 échecs, coût Argon2id par essai côté serveur. ⚠️ Au niveau 1 derrière un service caché, aucun frein de la bibliothèque ne s'applique : seuls le coût par essai et l'entropie de la passphrase s'y opposent
- **Énumération par bot** — *partiellement*. Fermée aux niveaux 1 et 2 et à l'enrôlement : refus unique au premier, aucun identifiant demandé au second, compteur tiré du nom soumis au troisième. La route du sel répond toujours un sel, vrai ou faux (§4.2). Un refus du niveau 2 nomme pourtant un état : la suspension, qui apprend à qui détient déjà un code que ce code vise un compte réel. Ouverte au niveau 3, où la réponse utile EST la distinction — un succès rend un numéro de dossier, un nom inconnu ne peut pas en rendre. Les refus, eux, ne se distinguent pas : nom inconnu, dossier déjà ouvert et procédure gelée rendent le même `ouverture_refusee`, au même délai. Ce qui s'y oppose est le coût : un frein par adresse avant la recherche du compte, le plafond de service qui le remplace sous `tor-onion` (§5.3), ce délai sur chaque refus, et une preuve de travail devant la route — que la bibliothèque ne peut pas imposer puisqu'elle n'a pas de route
- **Blanchiment de réputation sociale** — la bibliothèque n'offre aucun renommage de compte ; verrouiller le nom après l'inscription revient à l'application

### 10.2 CRITIQUE — Accès root serveur (sudo)

**C'est la limite la plus importante.**

SelfRecover protège les données de récupération par dérivation HMAC, hachage Argon2id et connaissance partagée. Mais **aucune de ces protections ne compte si un attaquant obtient un accès root au serveur**.

**La vulnérabilité :**

- Certains environnements Linux accordent un sudo sans mot de passe par défaut (`NOPASSWD: ALL` dans sudoers). Cas notables : **Raspberry Pi OS** (user `pi`) et les **images cloud** (AMIs Ubuntu AWS/DigitalOcean/GCP pour le user `ubuntu`, Amazon Linux pour `ec2-user`, etc.). La plupart des installations desktop/serveur (Debian, Ubuntu iso, Fedora, Arch) n'ont **pas** ce problème par défaut — mais `/etc/sudoers.d/` se vérifie toujours à l'installation.
- Si un attaquant compromet le compte utilisateur (fuite de clé SSH, vulnérabilité web, etc.), il passe root sans aucune friction
- Avec root : accès direct à la base de données, remplacement des hash de mot de passe, modification du code — y compris du dériveur servi au navigateur, qui pourrait alors capter le mot mémorisé —, extraction des clés — SelfRecover devient décoratif

Ce n'est pas un risque théorique. C'est le point de défaillance unique qui contourne l'intégralité du protocole.

**RÈGLE DE DÉPLOIEMENT OBLIGATOIRE :**

- Retirer `NOPASSWD` de sudoers immédiatement après l'installation de l'OS
- Définir une passphrase diceware forte (minimum 6 mots, 8 recommandés) comme mot de passe de l'utilisateur sudo
- `sudo` doit exiger cette passphrase pour chaque escalade de privilèges
- La passphrase doit être conservée hors ligne uniquement (papier, pas numérique)
- L'authentification SSH doit utiliser des clés (pas de login par mot de passe)

**Implémentation (Debian / Ubuntu / Raspberry Pi OS) :**

```bash
# 1. Changer le mot de passe de l'utilisateur pour une passphrase diceware forte
echo "user:<passphrase-diceware>" | sudo chpasswd

# 2. Modifier sudoers : remplacer "user ALL=(ALL) NOPASSWD: ALL" par "user ALL=(ALL) ALL"
sudo visudo -f /etc/sudoers.d/010_user-nopasswd

# 3. Vérifier : cette commande doit échouer avec "il est nécessaire de saisir un mot de passe"
sudo -k && sudo -n whoami
```

Un déploiement SelfRecover sans sudo durci, c'est un verrou sur une porte sans mur. **Cette règle est non négociable.**

### 10.3 L2 exige deux facteurs — aucun ne suffit seul

Le L2 exige **deux** facteurs : un recovery code (possession) **et** le mot mémorisé (connaissance). Le mot mémorisé seul compromis (ingénierie sociale, regard par-dessus l'épaule, note négligemment écrite) ne suffit pas — il manque encore un recovery code. Le risque réel est la compromission **simultanée** des deux facteurs (le mot **et** un recovery code, ou le mot **et** l'appareil enrôlé). C'est le modèle 2FA standard : il ne peut pas être corrigé sans canal de communication externe — ce que SelfRecover rejette explicitement.

Sur la voie de l'appareil, le serveur ne vérifie pas le mot : un blob volé se travaille hors ligne, sans compteur, au seul coût d'Argon2id. Cette voie demande un mot robuste.

Aucun système ne protège contre le vol simultané de tous ses facteurs. Une clé privée SSH qui fuite donne l'accès au serveur. Une seed phrase qui fuite vide un portefeuille. Un recovery code **et** le mot mémorisé volés ensemble ouvrent le compte. Le modèle de sécurité est identique.

SelfRecover part du principe que :

- L'utilisateur traite son mot de récupération comme une clé de maison — pas sur un post-it, pas partagée dans un chat
- En mode `'hostname'`, la dérivation limite les dégâts au seul nom d'hôte concerné (l'empreinte est inutilisable ailleurs) ; en mode `'label'`, elle ne les limite qu'aux services qui ne partagent pas le même label
- Le frein par adresse, les freins par compte du niveau 2 et de l'enrôlement, et la suspension du niveau 2, ralentissent la force brute en ligne — au niveau 1 derrière un service caché, aucun d'eux ne s'applique ; hors ligne, seul le coût d'Argon2id la freine
- Le serveur ne peut pas compenser la négligence humaine — aucun système ne le peut

**Un secret protégé reste sûr ; un secret négligé est exposé.** Ce n'est pas une faille — c'est le contrat fondamental de tout système de sécurité basé sur un secret.

### 10.4 Autres limites (par conception)

- Si l'utilisateur a oublié son mot mémorisé et perdu sa passphrase, il ne reste que le niveau 3 : un arbitre humain. S'il refuse, il n'y a pas d'autre recours ; un nouveau dossier reste possible — un refus, même répété, ne ferme plus l'ouverture de lui-même. Seul un administrateur peut la geler, et la rouvrir
- Une passphrase apportée ne vaut que le hasard de ses dés, que la bibliothèque ne peut pas vérifier (§5.5)

Ces limites sont voulues. Un système avec des recours infinis a une surface d'attaque infinie.

---

## 11. Checklist de sécurité au déploiement

SelfRecover ne peut pas protéger les comptes si le serveur qui l'héberge est mal sécurisé. Cette checklist est **obligatoire** avant tout déploiement en production.

### 11.1 Accès serveur

- [ ] Retirer `NOPASSWD` de sudoers — imposer une passphrase diceware (6+ mots) pour toute escalade de privilèges
- [ ] Authentification SSH par clé uniquement — désactiver le login par mot de passe (`PasswordAuthentication no`)
- [ ] Firewall actif (UFW / iptables) — exposer uniquement les ports 80, 443 et SSH

### 11.2 Base de données

- [ ] Prepared statements (PDO / requêtes paramétrées) pour TOUTES les requêtes SQL — sans exception
- [ ] Utilisateur BDD avec privilèges minimaux (`SELECT`, `INSERT`, `UPDATE`, `DELETE` uniquement — pas de `GRANT`, pas de `DROP`)
- [ ] Pas de phpMyAdmin ou Adminer exposé sur internet
- [ ] Backups chiffrés au repos (gpg ou openssl) — un dump en clair est une faille
- [ ] Stockage des backups isolé du web root — pas accessible via HTTP

### 11.3 Application

- [ ] HTTPS obligatoire sur le web ordinaire — en mode `'hostname'` la dérivation lit le nom d'hôte, et sans TLS rien ne garantit que la page servie vient bien de ce service ; sur un service caché v3, l'adresse est la clé publique du service (§4.1)
- [ ] Rate limiting sur tous les endpoints de recovery (nginx `limit_req` ou applicatif). ⚠️ Sous le profil `tor-onion`, c'est la **seule** borne du niveau 1 : la bibliothèque n'y freine ni par compte ni par adresse, et chaque essai qui atteint la comparaison paie un Argon2id. Une file qui fait attendre sans rien mémoriser n'exclut aucun compte ; un plafond pour tout le service, lui, se vide par un seul tiers
- [ ] Headers de sécurité : CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy
- [ ] PHP : `disable_functions`, `open_basedir`, `expose_php off`
- [ ] Scripts init et migration bloqués en production (deny all ou supprimer)

### 11.4 Monitoring

- [ ] Logger toutes les tentatives de recovery (niveau, succès/échec, IP — jamais le mot de récupération)
- [ ] Alerter sur les échecs répétés L2/L3 pour un même compte
- [ ] Vérification automatisée des backups (test de restauration périodique)

### 11.5 Intégration de la bibliothèque

- [ ] Déclarer le profil de déploiement, `clearweb` ou `tor-onion` : il est obligatoire, sans défaut
- [ ] Garder le sel du déploiement hors racine web, et ne jamais le tourner sans réémettre toutes les feuilles de codes
- [ ] Servir la route du sel par `Recovery::selDeDerivation()`, et la freiner
- [ ] Enrôlement d'un appareil : tirer le nom du compte de la session, jamais du corps de la requête, et passer `Titulaire::AUTHENTIFIE`
- [ ] Vérifier la qualité d'arbitre avant `trancher()`, `degeler()` et `abandonner()` : la bibliothèque ne la vérifie pas
- [ ] Appeler `purger()` depuis une tâche planifiée : la bibliothèque n'a pas d'horloge
- [ ] Poser une preuve de travail devant la route d'ouverture du niveau 3
- [ ] Avec l'adaptateur fourni, construire `StockagePdo` avec l'hôte de dérivation
- [ ] Passphrase apportée : passer la saisie à `Recovery::validerPassphraseApportee()`, hacher et afficher la forme rendue, poser `autocapitalize="none"` sur les champs passphrase

Un déploiement qui ignore cette checklist n'est pas un déploiement SelfRecover — c'est une passoire.

---

## 12. Guide d'intégration

### 12.1 Pré-requis

- PHP 8.1+ avec `ext-json`, `ext-mbstring` et `ext-openssl` — les contraintes que porte `composer.json` —, et un PHP qui fournit `PASSWORD_ARGON2ID`, ce que `composer.json` ne peut pas exiger. L'implémentation de référence est en PHP ; il n'en existe pas d'autre côté serveur
- Une base SQL. Le schéma et l'adaptateur fournis ciblent SQLite (`ext-pdo_sqlite`) ; ailleurs, les types de colonnes et quatre constructions de l'adaptateur (cinq requêtes) se réécrivent — l'en-tête de `schema.sql` les nomme, ou l'intégrateur écrit son propre adaptateur de `StorageInterface`
- Navigateur moderne avec JavaScript et Web Crypto API : `client/sr-derive.js` y dérive le mot mémorisé par `crypto.subtle`. Le facteur « cet appareil » ajoute `client/argon2id.js` puis `client/sr-kdf.js`, Web Crypto n'offrant pas Argon2id
- HTTPS obligatoire sur le web ordinaire (§11.3)

### 12.2 Distribution prévue

```bash
composer require pierroons/selfrecover   # future lib PHP
npm install selfrecover                  # future lib JS
```

Pas encore publiées : la bibliothèque s'installe depuis un clone du dépôt, par un dépôt Composer de type `path`. Pour la voir à l'œuvre : la démo servie ([`demo/bi-self-duo/`](../../../demo/bi-self-duo/)), le laboratoire ([MySelf-Lab](../../../demo/lab/)), et [les outils autonomes](../tools/) pour les pages qui ne dépendent d'aucun serveur.

---

## 13. Comparaison avec les solutions existantes

| Feature | Reset par email | WebAuthn / Passkey | **SelfRecover** |
|---------|:---:|:---:|:---:|
| Pas de SMTP | ✗ | ✓ | ✓ |
| Pas de tiers | ✗ | ✗ (vendor lock-in) | ✓ |
| Fonctionne sur tous les appareils | ✓ | ~ (lié à l'appareil) | ✓ |
| Récupération possible hors ligne | ✗ | ✗ | ~ (le user détient le secret) |
| Anti-phishing par conception | ✗ | ✓ | ~ (passif seulement, et en mode `'hostname'` seulement — §4.1) |
| Isolation par site | ✓ | ✓ | ✓ |
| Coût zéro pour l'utilisateur | ✓ | ✓ | ✓ |
| Complexité d'implémentation | haute (SMTP) | haute (FIDO2) | faible |

SelfRecover n'est pas un remplacement pour WebAuthn. C'est un complément, surtout pour les sites qui ne veulent pas embarquer de l'authentification liée à l'appareil et ne veulent pas non plus s'appuyer sur SMTP.

---

## 14. Feuille de route

- [x] Spécification du protocole (v1.5)
- [x] Implémentation de référence (ce dépôt)
- [x] Livres blancs EN + FR
- [x] Démo servie (`demo/bi-self-duo/`) et laboratoire (`demo/lab/`) — la démo autonome a été retirée le 18 août 2026
- [x] Bibliothèque PHP extraite — PSR-4, son propre `composer.json`, consommée par un dépôt `path`
- [x] Les trois niveaux dans la bibliothèque, niveau 3 compris (`Escalade`)
- [x] Facteur « cet appareil », et son chiffrement local en Argon2id (`client/sr-kdf.js`)
- [x] Implémentation fournie du stockage (`schema.sql` + `StockagePdo`)
- [x] Profil de déploiement obligatoire, freins par compte aux niveaux 2 et à l'enrôlement, suspension du niveau 2
- [x] Retrait des appareils à la reprise du niveau 3, échéance de l'accord
- [x] Garde de la route du sel (`Recovery::selDeDerivation`)
- [x] Passphrase apportée par l'utilisateur, tirée aux dés (`Recovery::validerPassphraseApportee`)
- [ ] Audit de sécurité externe (communauté bienvenue)
- [ ] Publication sur Packagist (`composer require pierroons/selfrecover`)
- [ ] Paquet JS (`npm install selfrecover`) — le dériveur est livré comme `client/sr-derive.js`, il n'est pas paqueté
- [ ] Plugin WordPress
- [ ] Package Laravel
- [ ] Portages vers Python, Go, Rust, Node

---

## 15. Contribuer

SelfRecover est open source sous licence AGPL-3.0-or-later (bascule depuis MIT le 19/04/2026).

- Audits de sécurité et tests d'intrusion bienvenus
- Retours d'expérience de déploiements en production
- Portages vers d'autres langages et frameworks

**GitHub :** https://github.com/Pierroons/my-self/tree/main/bi-self/selfrecover

---

*SelfRecover — parce qu'une identité ne devrait pas dépendre d'une boîte mail.*
