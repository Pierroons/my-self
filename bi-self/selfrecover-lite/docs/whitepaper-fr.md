# SelfRecover-Lite — Whitepaper v1.0

**Durcir la récupération par e-mail, pour les sites qui la gardent**

*Le lien et l'appareil, jamais l'un sans l'autre.*

*Édition du 11 novembre 2026 — v1.0 — décrit la conception de SelfRecover-Lite. Aucune implémentation n'est publiée à cette date.*

---

## Contexte

SelfRecover retire l'e-mail de la récupération de compte : l'utilisateur garde en tête un mot de récupération, et le service n'a plus de boîte tierce à croire. Ce choix convient à un forum, à une communauté, à un public technique. Il ne convient pas à tous les sites. Une boutique en ligne qui demanderait à chaque client de retenir un secret de plus en perdrait une partie, et beaucoup de sites garderont l'e-mail quoi qu'on leur propose.

SelfRecover-Lite s'adresse à ces sites. Il garde le parcours habituel — « mot de passe oublié », un lien reçu par e-mail — et le durcit côté serveur, sans rien demander à retenir par défaut. Le prix est un parcours parfois plus long : quand la preuve habituelle manque, la récupération passe par un mot facultatif ou par un humain.

Un mode « Lite » de SelfRecover a existé sous forme de démonstration en v0.1.1 ; elle a été retirée le 18 août 2026. SelfRecover-Lite est une conception neuve, qui ne reprend ni son code ni son mécanisme.

Ce document décrit le mécanisme avant toute implémentation : ses pièces, ses règles, les variantes écartées et pourquoi, le modèle de menace, les limites, et ce que la conception emprunte à l'existant. L'annexe A le reprend étape par étape.

---

## Résumé

Une récupération SelfRecover-Lite exige deux preuves : **le lien** reçu à l'adresse du compte, qui prouve qu'on lit la boîte mail de cette adresse (« la boîte » dans la suite), et **un témoin**, c'est-à-dire un navigateur que le titulaire a enregistré sur ce compte, et dont il a confirmé l'enregistrement par la boîte. Le témoin est un secret aléatoire de 32 octets, posé par le serveur dans un cookie, dont le serveur ne garde que l'empreinte. Le lien ouvert sur n'importe quel appareil enregistré du compte, ou le code du même e-mail tapé sur l'un d'eux, réinitialise le mot de passe.

Sans appareil enregistré, deux substituts existent, toujours avec le lien : un **mot** facultatif, dérivé dans le navigateur comme dans SelfRecover, ou un **arbitre** humain (le service client du site), après un délai pendant lequel le titulaire est prévenu et peut s'opposer. Jamais le lien seul ; jamais le témoin seul.

Après toute récupération, une **fenêtre de reprise**, de 14 jours par défaut, permet au titulaire dépossédé de revenir par un appareil enregistré avant la récupération. La bibliothèque prend à sa charge le changement de mot de passe, le changement d'adresse et la fermeture des sessions ; un banc de conformité et un détecteur signalent ce qui la contourne (§10).

---

## 1. Le problème

La réinitialisation par e-mail repose sur une seule preuve : qui lit la boîte obtient le compte. Cette preuve fuit de plusieurs façons.

- **Le lien lu par un autre** : un e-mail transféré, un scanner de messagerie qui suit les liens, un lien intercepté ou resté dans un historique.
- **La boîte prise** : un mot de passe de messagerie volé ou réutilisé, une session de webmail volée par un logiciel espion, une adresse abandonnée puis réattribuée par son fournisseur.
- **Le tiers qui déclenche** : n'importe qui peut demander un lien pour l'adresse d'un autre, inonder sa boîte, ou épuiser le quota d'envoi du site.
- **Le mot de passe réutilisé** : la même fuite ouvre parfois la boîte et le site.

Les grands fournisseurs de comptes ajoutent des signaux (appareil connu, localisation, historique) et un examen humain. Un petit site n'a ni ces signaux ni cet examen : il a une table de comptes, un serveur loué et un service d'envoi d'e-mails.

SelfRecover-Lite cherche ce qu'un tel site peut ajouter sans démon, sans service tiers, et sans demander au client, par défaut, de retenir quoi que ce soit.

---

## 2. Le principe

**Deux clés, jamais l'une sans l'autre.**

| | ce qu'elle prouve | pourquoi elle ne suffit pas seule |
|---|---|---|
| **le lien** | qu'on lit maintenant la boîte de l'adresse du compte | la boîte fuit (§1) |
| **le témoin** | qu'on tient un navigateur que le titulaire a enregistré | un appareil se vole, un cookie se copie |

Quand le témoin manque — appareil neuf, cookies effacés, navigation privée —, il se remplace, dans cet ordre :

1. par le **mot**, facultatif, que le client a choisi à l'avance (§7.2) ;
2. par l'**arbitre**, un humain du service client, après un délai pendant lequel les appareils enregistrés sont prévenus (§7.3).

Le lien ne se remplace jamais pour réinitialiser un mot de passe : toute récupération commence par la preuve de la boîte. Seul le changement d'une adresse dont la boîte est perdue se fait sans elle, par l'arbitre (§10). Aucune preuve ne se replie en silence sur une autre, et le lien seul n'enregistre jamais un navigateur.

---

## 3. Le cadre

- **Un choix fait à l'installation, une seule fois.** Une installation de SelfRecover choisit au départ entre trois formes : `tor-onion` (SelfRecover, derrière un service caché), `clearweb` · Standard (SelfRecover, sans e-mail) et `clearweb` · Lite (SelfRecover-Lite, avec l'e-mail). Chaque forme porte ses propres facteurs de récupération et ses propres routes, et refuse ceux des autres : rien ne se reconfigure ensuite pour passer de l'une à l'autre. Les durées que le site peut régler (le délai avant l'accord de l'arbitre, la fenêtre de reprise) se règlent aussi avant l'installation, entre un minimum et un maximum que la bibliothèque impose ; une fois l'installation lancée, plus rien ne se règle. Un compte relève donc d'une seule des deux bibliothèques. SelfRecover reste la réponse pour qui accepte de retirer l'e-mail ; Lite ne change rien à sa doctrine.
- **Jamais sous Tor.** La bibliothèque refuse d'être construite en profil `TOR_ONION` : un compte rattaché à une adresse e-mail est l'inverse de ce qu'on cherche derrière un service caché, et l'adresse d'origine d'une requête y est inconnue.
- **L'adresse d'origine.** La bibliothèque refuse comme adresse de client une adresse de bouclage, privée ou réservée. Derrière un proxy ou un CDN, l'hôte déclare ses proxys de confiance et la bibliothèque en tire l'adresse du client ; une configuration incohérente est refusée bruyamment. **Aucune garantie de ce document ne repose sur le seul frein par adresse** : une plage IPv6 entière tient dans les mains d'un seul attaquant.
- **Une bibliothèque PHP, sans démon ni tâche planifiée.** Elle n'envoie pas d'e-mail : elle rend le contenu et sa priorité, et l'hôte l'envoie. Elle ne hache pas le mot de passe du site : l'hôte le garde. Le changement de mot de passe, le changement d'adresse et la fermeture des sessions passent en revanche obligatoirement par elle (§10).
- **Les briques partagées avec SelfRecover** : le hachage Argon2id et son profil unique (`Crypto/Hashing`), le dériveur du navigateur (`client/sr-derive.js`), les profils de déploiement, les langues, le poivre (§9.1), et le cœur du niveau 3 (`Escalade`), extrait dans SelfRecover pour servir aux deux. La dépendance va dans un seul sens : Lite dépend de SelfRecover, jamais l'inverse.

---

## 4. Le témoin

### 4.1 Ce qu'il est

Un secret de **32 octets tirés au hasard** par le serveur, rangé dans un cookie que le serveur pose lui-même.

| attribut | valeur | raison |
|---|---|---|
| préfixe du nom | `__Secure-` | le navigateur n'accepte le cookie que sur une connexion chiffrée |
| domaine | aucun | le cookie reste attaché à l'hôte exact ; un sous-domaine ne le reçoit pas |
| chemin | le préfixe des routes de Lite (demande, récupération, enregistrement, renouvellement, retrait, opposition, retour, changement d'adresse) | le cookie ne part pas avec les autres requêtes du site : un serveur compromis ne voit le secret que des clients qui passent par ces routes pendant la compromission |
| `HttpOnly` | oui | aucun script de la page ne le lit |
| `SameSite` | `Lax` | une requête lancée par un autre site ne le porte pas, hors navigation de premier niveau |
| `Max-Age` | 400 jours | le plafond du brouillon RFC 6265bis (§5.5), appliqué par Chrome depuis la version 104 et par Firefox depuis la version 132 ; le renouvellement le repousse |
| réponse qui le pose | servie depuis la plage d'adresses du site | un navigateur qui ramène à 7 jours les cookies posés depuis une adresse tierce ne le raccourcit pas |

**Pourquoi un cookie posé par le serveur**, et non un stockage écrit par script ou une clé WebCrypto : certains navigateurs effacent, après sept jours d'usage sans interaction avec le site, ce qu'un script a écrit (stockage local, IndexedDB, et donc une clé qui y vit). Un client qui commande une fois par mois perdrait son témoin entre deux visites.

**Un cookie par compte** : son nom porte une empreinte du compte. Deux comptes ouverts dans un même navigateur ont chacun leur témoin, et enregistrer l'un ne retire jamais celui de l'autre.

**Ce que garde le serveur**, pour chaque témoin : l'empreinte du secret, la date d'enregistrement, la date de confirmation, la date de la dernière visite, et l'historique borné des empreintes que ce témoin a portées.

### 4.2 Ce qu'il ne contient pas

Le cookie ne contient **que le secret** : ni identifiant d'appareil, ni empreinte du navigateur, ni date, ni identifiant de compte en clair. Les dates vivent côté serveur. Un cookie volé ne révèle rien ; un cookie forgé n'est qu'un secret inconnu, qui ne fait rien.

La conception n'a **aucun état de risque** du compte qui conditionnerait la connexion au témoin, et **aucun cookie « non autorisé »**. Le témoin ne sert jamais à la connexion ordinaire, qui reste celle du site : il ne sert qu'à la récupération. Un état de risque qu'un événement extérieur fait basculer est un levier qu'un tiers finit par tirer contre le titulaire. La règle commune aux modules MySelf s'applique : un signal reçu peut durcir un parcours, jamais punir le titulaire.

### 4.3 L'enregistrement

1. Le client se connecte par mot de passe depuis un navigateur qui ne porte pas de témoin de ce compte. L'hôte lui demande : « enregistrer cet appareil ? oui / non ».
2. **Non** : la réponse est retenue sur ce navigateur (une préférence, pas un secret), et la question ne revient pas à chaque visite. Un ordinateur public ne s'enregistre pas.
3. **Oui** : le serveur pose un témoin **provisoire**. Un témoin provisoire n'ouvre rien et ne reçoit aucun avis. Un e-mail de confirmation d'un nouvel appareil part à l'adresse du compte.
4. **La confirmation ne vaut que dans le navigateur qui porte le témoin provisoire.** Le lien de l'e-mail, ouvert dans ce navigateur, confirme le témoin. Ouvert ailleurs — le navigateur interne d'une application de messagerie, un autre appareil —, il ne confirme rien : il le dit, et propose de taper **sur l'appareil à enregistrer** le code que porte le même e-mail.
5. **Chaque confirmation est annoncée** à l'adresse du compte et à tous les autres témoins confirmés.

**Pourquoi provisoire** : un mot de passe volé suffit à se connecter. Sans confirmation par la boîte, il suffirait aussi à poser un témoin qui survivrait au changement de mot de passe du titulaire. Avec elle, il faut le mot de passe **et** la boîte, et le titulaire en est averti.

Les e-mails de confirmation suivent l'espacement du §6.

Dans la suite, un **appareil enregistré** est un navigateur dont le témoin est confirmé.

### 4.4 Le renouvellement

Le secret change pendant la vie du témoin, pour qu'une copie ancienne finisse par se trahir.

- Le renouvellement est une requête de même origine vers la route de Lite, lancée depuis les pages où le client est connecté. Une requête qu'un autre site peut provoquer ne le déclenche jamais (le serveur lit l'en-tête `Sec-Fetch-Site`).
- **Il se fait à confirmation** : le serveur remet un secret neuf, et l'ancien reste valable jusqu'à ce que le neuf revienne. Une réponse perdue ou deux onglets ouverts ne retirent donc rien au titulaire.
- Un secret de **l'historique de ce témoin** qui revient après la période de grâce signale une copie : le témoin **retombe provisoire** et se reconfirme par la boîte. Un secret inconnu ne fait rien. Rien de ceci ne bloque le compte.

### 4.5 « Mes appareils » et le retrait

L'hôte affiche, depuis l'interface de la bibliothèque, les témoins du compte et la dernière visite de chacun. « Renouveler » ne vise que le navigateur courant.

- **Le titulaire ne retire un témoin qu'avec un témoin confirmé**, jamais avec le mot de passe seul : celui qui a volé le mot de passe ne peut pas retirer au titulaire son moyen de revenir.
- **Un témoin ne retire que des témoins plus récents que lui.** L'ancienneté, tenue côté serveur, ne sert qu'à ordonner les retraits.
- **L'arbitre peut retirer n'importe quel témoin**, y compris le plus ancien quand c'est l'appareil volé.
- Un témoin que le serveur n'a pas vu depuis 400 jours s'efface.

**« Déconnecter les autres appareils »** ferme la porte en un seul appui : toutes les autres sessions du compte sont fermées, et les témoins plus récents que celui du navigateur courant sont retirés. Un témoin plus ancien n'est pas retiré : sa demande part à l'arbitre, avec la chronologie. Fermer les sessions est permis à toute session ouverte, puisque chacun peut se reconnecter ; retirer un témoin exige un témoin confirmé. Sans cette borne, le voleur d'un appareil enregistré ferait le même geste contre le titulaire, puis changerait le mot de passe par la boîte.

---

## 5. Le lien et le code

### 5.1 Le lien

Le jeton est sans état :

```
jeton = HMAC-SHA256(clé du serveur, compte ‖ adresse ‖ empreinte du mot de passe ‖ horodatage)
```

Chaque champ est encodé avec sa longueur, pour qu'aucune concaténation ne se lise de deux façons. La clé vit hors de la base.

- **L'empreinte du mot de passe** rend l'usage unique gratuit : dès que le mot de passe change, tous les liens émis avant lui meurent, sans table de jetons.
- **L'adresse** fait mourir les liens au changement d'adresse.
- **L'horodatage** borne la vie du lien : **24 heures au plus**.
- **La dernière connexion n'y entre pas.** Elle permettrait à qui tient le mot de passe en vigueur de tuer chaque lien du titulaire en se reconnectant.

Le lien se construit depuis une origine configurée, jamais depuis l'en-tête `Host` de la requête. Le jeton voyage dans le **fragment** de l'URL (`#t=…`), que le navigateur n'envoie ni au serveur ni dans l'en-tête `Referer` ; la page pose en plus `Referrer-Policy: no-referrer`.

**Ouvrir le lien ne consomme rien.** Un GET n'a aucun effet : un scanner de messagerie qui suit le lien ne le brûle pas. La consommation est un POST de même origine, envoyé par la page après le contrôle des preuves. Le changement de mot de passe est une **écriture conditionnelle** : il n'a lieu que si le mot de passe en base vaut encore l'empreinte signée par le jeton. De deux consommations simultanées du même lien, une seule passe.

### 5.2 Le code

Le même e-mail porte un code de **8 caractères** tirés d'un alphabet sans caractères ambigus. Il sert quand l'e-mail est lu là où le lien ne peut pas servir : le navigateur interne d'une application de messagerie, un autre appareil.

- Il vaut ce que vaut le lien, et vit aussi longtemps.
- Il se tape **sur un appareil enregistré du compte** ou, pour confirmer un enregistrement, sur l'appareil à enregistrer.
- Ses essais se comptent sur le témoin qui le tape : **5 essais**, puis le code meurt. Un tiers sans témoin ne peut pas brûler le code du titulaire.
- Il n'apparaît ni dans l'objet ni en première ligne de l'e-mail, pour ne pas s'afficher sur un écran verrouillé.

### 5.3 Ce qu'aucun geste ne fait

**Aucun geste du client sur un appareil — un lien ouvert, un code tapé — n'ouvre la session d'un autre appareil.** Fait sur un appareil enregistré, il y réinitialise le mot de passe ; il n'active rien à distance. Un parcours où l'on valide sur un appareil la connexion d'un autre est celui qu'exploite l'hameçonnage par relais : l'attaquant fait la demande, la victime la valide.

---

## 6. L'envoi des e-mails

- **Un espacement minimal par adresse** : le premier e-mail part tout de suite ; avant chacun des suivants, il faut attendre au moins 2 minutes, puis 10 minutes, 30 minutes, 2 heures, et ensuite 4 heures. Une demande trop tôt reçoit la réponse habituelle et ne fait rien partir ; l'e-mail suivant part à la demande suivante, une fois le délai passé. Aucune tâche planifiée n'est nécessaire.
- **Une demande portée par un témoin confirmé** part hors espacement : un tiers qui inonde l'adresse ne retarde pas le titulaire qui récupère depuis son appareil. Ces envois sont bornés par témoin et par jour.
- **Les avis** (récupération faite, appareil confirmé, changement d'adresse) échappent à l'espacement : ils naissent d'un événement du compte, qu'un tiers ne provoque pas.
- **La demande répond de la même façon, et dans le même temps, que le compte existe ou non.** Aucun e-mail ne part vers une adresse sans compte. L'envoi se fait après la réponse quand l'hôte le permet ; sinon, l'écart de temps dû à l'envoi est déclaré comme une limite de l'hôte, et se mesure.
- **Chaque e-mail porte une priorité**, pour que l'hôte protège son quota d'envoi.

---

## 7. Les chemins de récupération

### 7.1 Le chemin rapide : le lien et un témoin

Le client demande un lien. Il l'ouvre sur un appareil enregistré du compte, ou tape le code de l'e-mail sur l'un d'eux, puis choisit un nouveau mot de passe. C'est le chemin attendu dans la plupart des cas, et il ne demande rien de plus que le « mot de passe oublié » habituel.

La demande peut se faire sur un appareil et finir sur un autre : demandée sur l'ordinateur, la récupération se termine sur le téléphone enregistré qui lit l'e-mail.

### 7.2 Appareil neuf, avec le mot

Le **mot** est facultatif. Le client le choisit dans son compte quand il le souhaite ; une bulle d'information explique pourquoi le choisir tout de suite plutôt qu'après une perte, et recommande **un mot propre à ce site**, fortement quand le site chiffre ses données avec SelfDataGuard. Il est aussi proposé après une récupération.

- **Dérivation** : dans le navigateur, comme dans SelfRecover (`sr-derive.js`, mode `hostname` ; whitepaper de SelfRecover, §4). Le mot ne quitte jamais le navigateur ; seule son empreinte part. La page du mot redirige vers l'**hôte canonique** que le site déclare : le domaine nu, `www.` et `m.` donneraient trois empreintes différentes.
- **Force** : au moins 8 caractères, et jamais un mot seul des listes de mots ; la page le vérifie avant d'envoyer.
- **Le sel n'est servi que derrière un lien valide.** Sans la boîte, on n'obtient pas de quoi calculer une empreinte.
- **Essais** : 5 par lien, et un budget par compte et par jour compté **seulement à travers un lien valide**. Personne sans la boîte ne remplit ce budget à la place du titulaire.
- **Le poivre** : l'empreinte reçue passe par un HMAC clavé par un secret du serveur rangé hors de la base, avant le hachage Argon2id (§9.1).

Le lien et le mot donnent un nouveau mot de passe. Ils n'enregistrent pas le navigateur : l'enregistrement passe ensuite par la même question qu'à toute connexion.

### 7.3 Appareil neuf, sans mot : le niveau 3

Le dernier chemin passe par un humain, l'**arbitre** : le service client du site.

- **La preuve de la boîte vaut pour le navigateur qui la présente.** Le dossier s'ouvre avec le lien, dans le navigateur qui a fait la demande.
- **Règle d'antériorité** : sans témoin, un lien ne vaut que pour une demande **créée avant l'envoi** de l'e-mail. Le lecteur d'un e-mail transféré, qui demanderait après l'avoir lu, est refusé.
- **Les dossiers ne sont pas exclusifs** : plusieurs dossiers sur un même compte coexistent, et l'arbitre les voit regroupés. Un tiers ne peut pas occuper la place du titulaire.
- **Le faisceau** : l'arbitre juge sur des faits que la boîte ne livre pas. Ce que les e-mails du site révèlent (commandes, adresses de livraison) ne prouve rien, puisque le détenteur de la boîte les lit aussi.
- **Un délai avant que l'accord prenne effet** : 72 heures par défaut ; le site le règle entre 48 heures et 7 jours, avant l'installation (§3). Pendant ce délai, le titulaire est **prévenu** : un e-mail à l'adresse du compte dès l'accord, et un avis sur chaque appareil enregistré à sa prochaine visite.
- **S'opposer** : un témoin confirmé avant la demande peut s'opposer. L'opposition **suspend** l'accord et renvoie le dossier à l'arbitre. Ce n'est jamais un veto : un appareil volé ne bloque pas à lui seul une récupération légitime.

---

## 8. Après une récupération

### 8.1 L'avis

Toute récupération prévient le titulaire, à l'adresse du compte et sur ses appareils enregistrés.

### 8.2 La fenêtre de reprise

- Elle **s'ancre sur la récupération** et dure **14 jours** par défaut ; le site la règle entre 7 et 30 jours, avant l'installation (§3).
- Elle ne se ferme que **par sa fin ou par l'arbitre**. Un retour annule la récupération **sans fermer la fenêtre** : sinon un voleur qui tient la boîte et un ancien appareil enregistré referait une récupération à chaque retour, et le compte changerait de mains sans fin. Rien d'autre ne la déplace.
- **Pendant la fenêtre, le mot de passe ne change que par un retour** : ni changement normal ni seconde récupération n'y ont lieu. Les liens de retour et de contestation sont **signés sur la fenêtre**, et non sur l'empreinte du mot de passe : celui qui a récupéré ne peut pas les tuer en changeant le mot de passe.
- Le compte est signalé « en reprise ». La bibliothèque refuse le changement d'adresse. L'hôte devrait geler aussi les nouvelles adresses de livraison et les nouveaux moyens de paiement : c'est par eux qu'un usurpateur tire profit d'un compte de boutique.
- **Un changement d'adresse ouvre lui aussi une fenêtre** : l'ancienne adresse et tous les témoins sont prévenus, et le retour passe par l'ancienne adresse.

### 8.3 Le retour

Le retour est le chemin rapide, avec une condition de plus : **le lien et un témoin confirmé avant la récupération.**

- Il **annule les effets de la récupération** : les témoins enregistrés depuis sont retirés, les sessions fermées, et tous sont prévenus.
- **Un second retour dans la même fenêtre, ou un retour contesté, va à l'arbitre.** Deux appareils anciens ne se renvoient pas le compte en boucle.
- Le titulaire revenu ferme ensuite la porte par « déconnecter les autres appareils » (§4.5). Le prix : jusqu'à la fin de la fenêtre, il ne change pas non plus seul son mot de passe.

### 8.4 L'ancien mot de passe : une contestation, jamais un automatisme

L'ancien mot de passe (celui d'avant une récupération ; un changement normal le périme) **ouvre une contestation devant l'arbitre**, par une entrée propre à Lite : le lien de contestation et l'ancien mot de passe. Cette entrée échappe au frein de connexion de l'hôte, qu'un tiers pourrait remplir pour fermer la porte au titulaire.

Il ne déclenche jamais rien d'automatique : ni retour, ni verrou, ni durcissement. Après un vol d'identifiants, « l'ancien » mot de passe peut être celui de l'attaquant. Le refus d'un mauvais ancien mot de passe coûte le même temps qu'un autre refus (une empreinte factice est calculée), et les essais sont comptés par lien de contestation : l'entrée n'est pas un oracle sans fin.

Hors de la contestation, l'ancien mot de passe ne sert qu'à rouvrir l'archive d'un coffre SelfDataGuard (§9.2) : il n'y rend que ce qu'il ouvrait déjà avant la récupération.

L'arbitre voit la chronologie que la bibliothèque tient : qui a récupéré, quand, depuis quoi, et qui conteste.

### 8.5 La limite

Un attaquant qui tenait le mot de passe **et** la boîte avant la récupération, et s'en est servi pour enregistrer et confirmer un appareil, ne se distingue pas du titulaire. L'arbitre tranche sur la chronologie.

---

## 9. Le poivre et les données chiffrées

### 9.1 Le poivre

Un secret du serveur, rangé hors de la base, qui entre dans l'empreinte du mot (§7.2) et dans les enveloppes du coffre (§9.2).

- **Mode local, par défaut** : un fichier hors du dossier servi par le web, d'au moins 32 octets, dont l'absence ou la faiblesse fait échouer bruyamment la bibliothèque ; jamais dans la sauvegarde de la base ; une copie hors du serveur ; le compte de la base n'a pas le droit de lire des fichiers.
- **Mode distant, en option** : une autre machine tient le poivre et ne rend que des calculs ; le serveur du site ne le voit jamais.

Ce que le poivre change : une base volée seule ne suffit pas à essayer des mots hors ligne. Ce qu'il ne change pas : un serveur compromis en entier tient le fichier du mode local. Sa perte rend inutilisables les empreintes du mot et les enveloppes des témoins ; d'où la copie hors du serveur.

### 9.2 Le coffre SelfDataGuard

Un site qui chiffre les données de ses clients avec SelfDataGuard scelle la clé de chaque coffre sous plusieurs serrures. Avec un mot de passe oublié, la serrure du mot de passe est perdue ; sans autre serrure, le coffre deviendrait illisible pour tous. Lite en ajoute.

- **Chaque témoin confirmé scelle aussi le coffre** : une enveloppe dont la clé se calcule côté serveur à partir du secret du témoin et du poivre, rescellée à chaque renouvellement du témoin. **Le chemin rapide garde les données.**
- Le mot, s'il existe, est une serrure, comme dans SelfDataGuard.
- **Les enveloppes archivées** sont rescellées à chaque renouvellement, comme les vivantes : un témoin d'avant qui revient retrouve l'archive.
- **Niveau 3 sans mot** : l'ancien coffre est **archivé avec toutes ses enveloppes**. Il se rouvre avec **n'importe quel verrou d'avant** : l'ancien mot de passe, un témoin confirmé avant la récupération, ou le mot. Les témoins enregistrés après la récupération ne l'ouvrent pas ; celui qui a récupéré par l'arbitre repart d'un coffre vide.
- **En option**, le séquestre de SelfDataGuard (un gardien hors de la machine), pour les sites qui en ont les moyens.

Comme pour toutes les serrures de SelfDataGuard, le serveur ouvre le coffre le temps d'une requête. Le chemin restreint du cookie limite les requêtes qui portent le secret d'un témoin.

---

## 10. Ce que la bibliothèque impose à l'hôte

Une garantie écrite dans un guide d'intégration et laissée à l'hôte finit par être oubliée sans bruit. Lite prend donc à sa charge ce dont ses garanties dépendent.

- **Changer le mot de passe, changer l'adresse, fermer les sessions** sont des fonctions de la bibliothèque. Elle sait si un changement est normal ou vient d'une récupération, vérifie la fenêtre, refuse ce qui est gelé, et **ferme toutes les autres sessions** à chaque changement de mot de passe ou d'adresse.
- **Changer l'adresse e-mail est un geste de récupération, en tout temps** : un clic dans un e-mail envoyé à l'**ancienne** adresse, **et** un témoin confirmé ou le mot. Jamais le mot de passe seul. Sans l'ancienne boîte, l'arbitre.
- **Un banc de conformité est livré à l'hôte**, à lancer à l'installation et dans son intégration continue : il vérifie qu'aucune route connue ne change le mot de passe ou l'adresse sans passer par Lite.
- **Un détecteur de changement hors de Lite** : la bibliothèque retient l'empreinte du mot de passe et l'adresse qu'elle a posées. Si elle les retrouve changées sans elle (une arrière-boutique, une requête directe en base), elle le signale et prévient le titulaire. Le banc voit les routes qu'il connaît ; le détecteur voit le résultat.
- **Le stockage** : tout seuil comparé à une expression se lie comme un entier (SQLite compare autrement une valeur liée comme texte) ; toute table qu'un nom peut faire écrire est bornée par construction ; le niveau d'isolement requis est déclaré pour chaque moteur (SQLite, MariaDB, PostgreSQL).

---

## 11. Le modèle de menace

### 11.1 Les adversaires

| adversaire | ce qu'il tient | ce qu'il obtient | ce qui l'arrête |
|---|---|---|---|
| le tiers | l'adresse e-mail du titulaire | des demandes, espacées ; aucune réponse qui distingue un compte existant | l'espacement par adresse ; aucun compteur qui refuse par compte ; la demande portée par un témoin passe hors espacement |
| le lecteur du lien | un e-mail transféré, un scanner, un lien intercepté | rien sans témoin ni mot ; au niveau 3, rien s'il a demandé après l'envoi | le témoin ; un GET qui ne consomme rien ; la règle d'antériorité |
| le détenteur de la boîte | la boîte, sans appareil ni mot | un dossier de niveau 3, jugé sur des faits hors de la boîte, après un délai où le titulaire est prévenu et peut s'opposer ; s'il passe, une fenêtre (14 jours par défaut) où le titulaire revient par un appareil d'avant | le témoin ; le faisceau ; le délai ; la fenêtre |
| le voleur du mot de passe | le mot de passe du site | la connexion ordinaire, hors du champ de Lite ; un témoin provisoire qui n'ouvre rien | la confirmation par la boîte ; le mot de passe seul ne retire aucun témoin, ne change ni l'adresse ni le mot |
| le voleur de cookie | le témoin copié, sans la boîte | rien seul ; présenté après un renouvellement, le témoin retombe provisoire | la boîte ; le renouvellement à confirmation |
| le voleur de cookie et de session de messagerie | le témoin et la boîte | une récupération par le chemin rapide | l'avis de récupération ; le retour par un autre appareil d'avant ; limite déclarée (§12) |
| le téléphone volé déverrouillé | le témoin et la boîte dans la même poche | une récupération | limite déclarée ; l'arbitre retire le témoin ; le retour depuis un autre appareil d'avant |
| l'ordinateur partagé | un navigateur commun | rien sur le compte d'un autre : un témoin par compte, et un enregistrement ne retire jamais celui d'un autre | la question à l'enregistrement ; la confirmation par la boîte |
| la base volée | les tables | ni secret de témoin (empreintes seules), ni essai hors ligne du mot sans le poivre, ni jeton à rejouer (sans état, clé hors base) | l'empreinte des témoins ; le poivre et la clé du HMAC hors base |
| le serveur compromis | le code, la clé et le poivre local | le secret des témoins qui se présentent pendant la compromission, sur les seules routes de Lite | le chemin restreint du cookie ; le mode distant du poivre ; limite commune à toute bibliothèque côté serveur |
| l'employé du service client | la console de l'arbitre | un accord de niveau 3, visible du titulaire : délai, avis, opposition, fenêtre | le délai et l'avis ; la chronologie tenue par la bibliothèque |
| l'intégrateur qui se trompe | une route qui change le mot de passe sans Lite | une garantie rompue sans bruit | le banc de conformité ; le détecteur de changement |

### 11.2 Les trois questions du tiers

Chaque porte de Lite est examinée avec trois questions : avec le seul identifiant public d'un autre, que peut-on déclencher sur son compte ? Et si on recommence sans fin ? Et avec une partie d'un secret, que coûte chaque essai faux ?

| porte | l'identifiant seul | sans fin | une partie du secret |
|---|---|---|---|
| demande de lien | une demande, même réponse que pour un compte inexistant | l'espacement borne les e-mails par adresse | — |
| ouverture du lien | rien : un GET ne consomme rien | — | le jeton est un HMAC-SHA256 |
| code | rien sans témoin du compte | essais comptés sur le témoin qui tape | 5 essais, puis le code meurt |
| mot | le sel n'est servi que derrière un lien valide | 5 essais par lien ; budget par compte rempli seulement à travers un lien valide | essais bornés ; empreinte poivrée |
| enregistrement | rien sans le mot de passe ; provisoire sans la boîte | e-mails de confirmation espacés | — |
| renouvellement | rien sans le secret | une requête venue d'un autre site est ignorée | un secret inconnu ne fait rien |
| retrait | rien sans témoin confirmé | — | — |
| niveau 3 | rien sans la boîte ; dossiers non exclusifs | dossiers regroupés pour l'arbitre | — |
| contestation | rien sans le lien de contestation | essais comptés par lien | même temps de refus |

Le principe commun : **aucun compteur ne refuse, par compte, ce qu'un tiers peut remplir.** Les bancs de l'implémentation rejoueront chaque ligne de ce tableau, en temps simulé.

---

## 12. Les limites assumées

- **L'e-mail reste obligatoire.** Toute récupération commence par le lien envoyé à l'adresse du compte : Lite ajoute une seconde preuve à l'e-mail, il ne s'en passe pas. Qui perd l'accès à sa boîte mail ne peut plus changer d'adresse que par l'arbitre ; qui la prend tient déjà l'une des deux preuves. Pour retirer la boîte mail du circuit de récupération, il faut SelfRecover.
- **Le téléphone volé déverrouillé** porte souvent le témoin et la boîte : il récupère le compte. Le titulaire est prévenu, et revient par un autre appareil d'avant pendant la fenêtre, ou par l'arbitre.
- **Le même mot de passe pour la boîte et pour le site** : rien de ce module ne l'arrête à la connexion. Une double authentification à la connexion le ferait ; elle est hors du champ de Lite.
- **La navigation privée et les navigateurs internes** n'ont pas de témoin durable : ils passent par le code tapé sur un appareil enregistré, par le mot, ou par l'arbitre.
- **Sans SelfDataGuard**, les données en clair du site sont lisibles par qui tient le compte.
- **Un client sans aucun appareil d'avant, sans ancien mot de passe et sans mot** perd l'archive de son coffre SelfDataGuard.
- **L'attaquant qui tenait le mot de passe et la boîte** avant la récupération ne se distingue pas du titulaire (§8.5).
- **Qui a perdu l'accès à sa boîte** passe par l'arbitre pour changer d'adresse : aucun chemin automatique ne s'ouvre sans la boîte.
- **La part des clients sans témoin** (appareil neuf, cookies effacés) fixe combien de récupérations passent par le mot ou par l'arbitre. Elle se mesurera chez un site réel.

---

## 13. Les variantes écartées

Ces variantes ont été examinées pendant la conception.

| variante | raison du rejet |
|---|---|
| **Le lien seul**, le parcours habituel | qui lit la boîte obtient le compte (§1) |
| **Le témoin seul** : un appareil connu suffit à réinitialiser | un appareil volé ou un cookie copié suffirait |
| **L'appareil connu comme signal**, pesé avec d'autres | un petit site n'a pas les autres signaux, et un signal pesé se contourne sans bruit |
| **Le lien ou le code liés au seul navigateur de la demande**, sans témoin | arrête le scanner, pas le détenteur de la boîte, qui fait sa propre demande et lit son propre e-mail |
| **Le code inversé** : affiché sur l'écran de la demande, tapé dans la page ouverte depuis l'e-mail | une action sur un appareil ouvrirait la session d'un autre : le schéma de l'hameçonnage par relais (§5.3) |
| **Une reprise par la boîte seule, défaite ensuite par l'ancien mot de passe** | après un vol d'identifiants, « l'ancien » mot de passe peut être celui de l'attaquant, et un mot de passe fuité défait une récupération légitime |
| **Un accès accordé automatiquement après un délai** | qui détient le mot de passe en vigueur annule chaque attente, et le titulaire sans appareil reste dehors |
| **L'enregistrement immédiat à la connexion** | un mot de passe volé poserait un témoin qui survit ; d'où le témoin provisoire, confirmé par la boîte |
| **Une quarantaine de quelques jours pour un témoin neuf** | remplacée par la confirmation par la boîte, qui coûte un e-mail au lieu d'une attente |
| **Un seul mot pour tous les sites de la famille** | avec SelfDataGuard, le mot ouvre le coffre ; un script qui écrème la page d'un site moins protégé le capterait pour tous les autres |
| **« Tout changement de mot de passe retire les témoins »** | le voleur du mot de passe retirerait au titulaire son moyen de revenir |
| **La dernière connexion dans le jeton du lien** | qui tient le mot de passe tuerait chaque lien du titulaire en se reconnectant |
| **Un état de risque du compte, un gel automatique** | un levier que le tiers tire contre le titulaire |
| **Un compteur d'échecs par compte qui refuse** | le tiers brûlerait les essais du titulaire |
| **Un cookie au nom fixe** | sur un navigateur partagé, enregistrer un compte écraserait le témoin d'un autre |
| **Un stockage écrit par script, ou une clé WebCrypto** | effacé par certains navigateurs après sept jours d'usage sans interaction avec le site |
| **Une place unique par compte au niveau 3** | un tiers l'occuperait et en écarterait le titulaire ; d'où les dossiers non exclusifs |
| **L'ancienneté qui fait le propriétaire** | l'appareil volé le plus ancien ne se retirerait plus ; d'où l'arbitre, qui peut retirer tout témoin |
| **Le facteur « appareil » de SelfRecover** : une clé d'appareil, gardée par le navigateur, qui récupère le compte sans e-mail | il ferait double emploi avec le témoin (l'appareil) et le mot (ce qu'on sait) ; il vit dans un stockage écrit par script, que certains navigateurs effacent après sept jours sans interaction avec le site ; et il récupérerait sans le lien, ce que Lite interdit |
| **Un module autonome qui recopie le hachage de SelfRecover** | deux profils de hachage finiraient par diverger ; le profil Argon2id reste unique |
| **Un retour qui ferme la fenêtre** | le voleur d'un ancien appareil enregistré, qui tient aussi la boîte, referait une récupération après chaque retour : le compte changerait de mains sans fin |
| **Un bouton qui retire tous les autres témoins, plus anciens compris** | le voleur d'un appareil enregistré appuierait le premier, puis changerait le mot de passe par la boîte : le titulaire n'aurait plus ni mot de passe ni témoin |

---

## 14. L'antériorité

Chaque brique de Lite est publiée ailleurs, et ce document les cite. Ce que la conception a de propre tient à leur assemblage. Sources lues le 10 novembre 2026.

| brique | publiée par | ce que Lite en fait |
|---|---|---|
| un secret aléatoire posé à la connexion, remplacé à chaque usage | Charles Miller, « Persistent Login Cookie Best Practice », 19 janvier 2004 | le témoin |
| le réemploi d'un secret remplacé signale un vol | Barry Jaspan, « Improved Persistent Login Cookie Best Practice », 1er novembre 2006 ; IETF RFC 9700 (janvier 2025), §4.14.2 | le témoin retombe provisoire, sans bloquer le compte |
| un cookie émis à une authentification réussie, qui dispense la connexion suivante d'une épreuve supplémentaire (un test anti-robot chez Pinkas et Sander, le verrouillage chez OWASP) | B. Pinkas et T. Sander, « Securing Passwords Against Dictionary Attacks », ACM CCS 2002 ; OWASP, « Slow Down Online Guessing Attacks with Device Cookies » (A. Dedov), 2015 | rien à la connexion ordinaire : le témoin ne sert qu'à la récupération |
| la récupération conditionnée à un appareil déjà utilisé | demande de brevet US 2012/0166553 A1, « Using social graph for account recovery » (priorité du 23 décembre 2010) ; pratique documentée des grands fournisseurs de comptes, qui en font un signal parmi d'autres ou une preuve suffisante | ni signal ni preuve suffisante : l'une des deux clés, et n'importe quel appareil enregistré du compte |
| le lien lié au navigateur de la demande | le principe de PKCE (IETF RFC 7636) appliqué aux liens de connexion par e-mail, en usage chez des fournisseurs d'identité | écarté comme mécanisme seul (§13) ; repris au niveau 3 : la preuve vaut pour le navigateur qui la présente |
| un code et un lien dans le même e-mail | pratique documentée de fournisseurs d'identité, où le code se tape dans le navigateur de la demande | le code se tape sur n'importe quel appareil enregistré du compte |
| le jeton sans état, signé sur l'empreinte du mot de passe, la dernière connexion et l'adresse | le générateur de jetons de réinitialisation du cadriciel Django | repris, sans la dernière connexion (§5.1) |
| la dérivation d'un mot par une fonction pseudo-aléatoire clavée par le mot de passe et appliquée au nom de domaine | B. Ross, C. Jackson, N. Miyake, D. Boneh, J. C. Mitchell, « Stronger Password Authentication Using Browser Extensions » (PwdHash), USENIX Security 2005 | la dérivation de SelfRecover, reprise pour le mot |
| la notification de toute récupération ; un code par e-mail valable 24 heures au plus | NIST SP 800-63B-4 (juillet 2025), §4.2.3 et §4.2.1.2 | repris |
| la fermeture des sessions au changement de mot de passe | OWASP, « Forgot Password Cheat Sheet » ; documentation de Django | imposée par la bibliothèque (§10) |
| la fenêtre d'annulation, le tiers de confiance, l'examen humain | les récupérations sociales de portefeuilles à fenêtre d'annulation ; le « trusted referee » de NIST SP 800-63A-4 (juillet 2025, §2.1.2 et §3.15.1), repris par la FIDO Alliance dans « Recommended Account Recovery Practices for FIDO Relying Parties » (février 2019) ; l'examen humain des grands fournisseurs | l'arbitre, le délai avant l'accord, la fenêtre de reprise |

**Ce que l'assemblage a de propre**, d'après les seules sources lues :

1. la règle « le lien et un témoin, jamais l'un sans l'autre », où le témoin est **n'importe quel** navigateur enregistré du compte, et non le seul navigateur de la demande ;
2. la grille de substitution, toujours avec le lien : témoin, puis mot dérivé du nom d'hôte, puis arbitre humain ;
3. le refus qu'un lien enregistre à lui seul un navigateur, quand la pratique publiée fait l'inverse ; l'enregistrement provisoire, confirmé par la boîte dans le navigateur même ;
4. après la récupération : les liens de retour et de contestation signés sur la fenêtre, le retour par un témoin d'avant, l'ancien mot de passe réduit à une contestation devant l'arbitre ;
5. le coffre chiffré scellé par chaque témoin, et son archive que rouvre n'importe quel verrou d'avant.

---

## 15. État et suite

- Ce document précède le code. L'implémentation suivra avec ses bancs : un banc du tiers en temps simulé, le banc de conformité livré à l'hôte, des bancs à plusieurs processus pour les écritures conditionnelles, sur SQLite, MariaDB et PostgreSQL.
- Un changement de conception passe par une nouvelle édition datée de ce document.
- SelfRecover-Lite sera publié sous licence AGPL-3.0-or-later, comme les autres modules MySelf.

**GitHub :** https://github.com/Pierroons/my-self

---

## Annexe A — Le procédé, étape par étape

### A.1 Enregistrer un témoin

1. Le client se connecte par mot de passe depuis un navigateur sans témoin du compte.
2. L'hôte lui demande s'il veut enregistrer cet appareil ; un refus est retenu sur ce navigateur.
3. Sur accord, le serveur tire 32 octets au hasard, les pose dans un cookie propre au compte (§4.1), range leur empreinte et marque le témoin provisoire.
4. La bibliothèque rend un e-mail de confirmation, avec un lien et un code, à l'adresse du compte ; l'hôte l'envoie, sous l'espacement du §6.
5. Le lien ouvert dans le navigateur qui porte le témoin provisoire, ou le code tapé dans ce navigateur, confirme le témoin.
6. La confirmation est annoncée à l'adresse du compte et aux autres témoins confirmés.

### A.2 Récupérer un compte

1. Le client donne son adresse. La réponse est la même que le compte existe ou non, et prend le même temps quand l'hôte envoie l'e-mail après la réponse (§6). Sous l'espacement, ou hors espacement si la demande porte un témoin confirmé, la bibliothèque rend un e-mail portant un lien (jeton HMAC sans état, dans le fragment de l'URL) et un code.
2. Sur un appareil qui porte un témoin confirmé du compte, la page du lien lit le jeton dans le fragment et l'envoie par un POST de même origine, avec le cookie du témoin. Le serveur vérifie le jeton (signature, âge de 24 heures au plus, empreinte du mot de passe encore en place) et le témoin (empreinte connue, confirmée, du même compte). Le nouveau mot de passe s'écrit sous condition que l'empreinte signée soit encore celle de la base. Sur un appareil qui porte le témoin mais pas l'e-mail, le code tapé tient lieu de lien.
3. Sans témoin, avec le mot : derrière un lien valide, le serveur sert le sel ; le navigateur dérive l'empreinte sur l'hôte canonique ; le serveur la passe par le poivre et la compare à son hachage Argon2id, sous les bornes d'essais du §7.2.
4. Sans témoin ni mot : un dossier de niveau 3 s'ouvre avec le lien, dans le navigateur de la demande, si la demande précède l'envoi de l'e-mail. L'arbitre juge sur un faisceau de faits hors de la boîte. Son accord prend effet après le délai réglé par le site ; le titulaire est prévenu dès l'accord, et un témoin confirmé avant la demande peut le suspendre.
5. La récupération faite, l'avis part à l'adresse du compte et vers les appareils enregistrés, et la fenêtre de reprise s'ouvre.

### A.3 Pendant la fenêtre de reprise

1. Le mot de passe ne change que par un retour ; l'adresse ne change pas ; les autres sessions sont fermées à chaque changement.
2. Le retour : un lien signé sur la fenêtre et un témoin confirmé avant la récupération. Il annule les effets de la récupération et prévient tout le monde. Un second retour, ou un retour contesté, va à l'arbitre.
3. La contestation : un lien signé sur la fenêtre et l'ancien mot de passe ouvrent un dossier devant l'arbitre, sans aucun effet automatique.
4. La fenêtre se ferme par sa fin ou par l'arbitre ; un retour ne la ferme pas.

---

*SelfRecover-Lite — la récupération par e-mail, avec une seconde clé que la boîte ne donne pas.*
