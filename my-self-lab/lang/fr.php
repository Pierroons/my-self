<?php
/**
 * MySelf-Lab — dictionnaire français (langue de référence).
 *
 * Granularité volontairement grossière : une clé par section, valeur contenant
 * son HTML. Ces pages sont du contenu rédigé, pas des libellés d'interface —
 * découper chaque phrase produirait des centaines de clés et des traductions
 * hachées, sans rien gagner.
 *
 * Le français fait foi. Toute clé absente de `en.php` retombe ici, ce qui rend
 * une traduction partielle lisible plutôt que trouée.
 */

declare(strict_types=1);

return [

    // ── Navigation et gabarit commun ──────────────────────────────────────
    'nav.forum'        => 'Forum',
    'nav.moderation'   => 'Modération',
    'nav.attacks'      => '🎯 Attaques',
    'nav.security'     => '🔐 Sécurité',
    'nav.redteam'      => '🛡️ Red Team',
    'nav.su'           => '🔑 Console SU',
    'nav.messages'     => 'Messages',
    'nav.profile'      => 'Mon espace',
    'nav.admin'        => '🛠️ Admin',
    'nav.logout'       => 'Déconnexion',
    'nav.login'        => 'Connexion',
    'nav.register'     => 'Créer un compte',
    'nav.demo_tag'     => 'démo · noindex',

    'banner' => '🔬 Forum de démonstration MySelf — auth par <strong>SelfRecover</strong> '
              . '(sans email), messages privés chiffrés par <strong>SelfDataGuard</strong> '
              . '(résistants à l\'exfiltration de la base).',

    'footer' => 'MySelf-Lab · vitrine écosystème <a href="https://my-self.fr">MySelf</a> · '
              . 'co-construit par Pierroons &amp; Claude (Anthropic) · AGPL-3.0 · démonstration',

    // ── Titres de page (balise <title>) ───────────────────────────────────
    'title.security' => 'Architecture de sécurité',
    'title.redteam'  => 'Test red team — règles d\'engagement',

    // ── Page « Architecture de sécurité » ─────────────────────────────────
    'sec.h1' => '🔐 Architecture de sécurité',

    'sec.intro' => '<strong>Transparence assumée.</strong> Cette page documente nos défenses <em>et leurs limites</em>. '
        . 'Pas de sécurité par l\'obscurité : une red team mérite de savoir ce qu\'elle attaque. Tout le code est '
        . 'sous licence <strong>AGPL-3.0</strong>. Pour le cadre du test, voir les <a href="/redteam.php">règles d\'engagement</a> ; '
        . 'pour les démonstrations, l\'<a href="/attacks.php">Attack Simulator</a>.',

    'sec.1.h2' => '1. Authentification — SelfRecover <span class="pill">sans email</span>',
    'sec.1.body' => '<ul>'
        . '<li>Aucun email, aucun n° de téléphone. À l\'inscription : mot de récupération choisi → <code>password</code> (16 car.) + passphrase diceware EFF générés côté serveur.</li>'
        . '<li>Stockage : <code>Argon2id(password)</code>, <code>Argon2id(passphrase)</code>, <code>Argon2id(clé_dérivée)</code> — m=64&nbsp;Mo, t=4, p=2 (profil OWASP). <strong>Aucun secret en clair.</strong></li>'
        . '<li>Dérivation par domaine : <code>HMAC-SHA256(secret, domaine ‖ sel_du_site)</code> → un secret hameçonné sur un autre domaine ne produit pas la bonne clé.</li>'
        . '<li>Rate-limit progressif (5 échecs / 15 min) + limite d\'inscription par IP (anti-énumération, anti-spam).</li>'
        . '</ul>',

    'sec.2.h2' => '2. Chiffrement des données — deux modèles selon la sensibilité',
    'sec.2.body' => '<p><strong>a) Blind-key serveur</strong> (profil : bio, localisation, lien) — AES-256-GCM, clé dérivée d\'un secret serveur stocké <em>hors base et hors webroot</em>. Un dump SQL ne révèle que des blobs.</p>'
        . '<p><strong>b) Bout-en-bout côté client</strong> (mémo perso) — chiffré dans le <strong>navigateur</strong> (WebCrypto). <code>PBKDF2</code> (600k) → <code>HKDF</code> par étiquette → une <code>vault_key</code> aléatoire chiffre le mémo, wrappée dans deux enveloppes (mot de passe + passphrase de secours). <strong>Le serveur ne détient aucune clé.</strong></p>'
        . '<div class="mt">'
        . '<div class="ok"><h4>✅ Ce que ça protège</h4><ul>'
        . '<li>Blind-key : vol de disque, dump SQL, injection</li>'
        . '<li>E2E : <strong>même</strong> un accès admin ou root sur le serveur → le mémo reste illisible</li>'
        . '</ul></div>'
        . '<div class="no"><h4>⛔ Ce que ça ne protège pas (V1, assumé)</h4><ul>'
        . '<li>Blind-key : un admin / un RCE qui lit la clé peut déchiffrer le profil &amp; les DM</li>'
        . '<li>E2E : un serveur compromis <em>persistant</em> servant un JS piégé captant le mot de passe au déverrouillage (faille du « code servi »)</li>'
        . '</ul></div>'
        . '</div>',

    'sec.3.h2' => '3. Le socle commun — mapping SelfRecover ⇄ SelfDataGuard',
    'sec.3.body' => '<ul>'
        . '<li>Un seul <strong>secret racine</strong> mémorisé, une <strong>primitive de dérivation partagée</strong>, des <strong>clés filles séparées par étiquette</strong> (<code>auth</code> / <code>data-enc</code> / <code>data-recover</code>).</li>'
        . '<li>Règle d\'or : <strong>jamais la même clé pour l\'authentification et le chiffrement</strong> (le serveur voit l\'auth, il ne doit jamais pouvoir déchiffrer).</li>'
        . '<li>Récupération unifiée : le même mot/passphrase de secours rend l\'accès <em>et</em> les données. La force du secours = <strong>entropie de l\'entrée</strong> (passphrase diceware), pas la taille du hash.</li>'
        . '</ul>',

    'sec.4.h2' => '4. Durcissement applicatif',
    'sec.4.body' => '<ul>'
        . '<li><strong>CSRF</strong> : jeton HMAC lié à la session, vérifié sur chaque action d\'écriture (en-tête <code>X-CSRF-Token</code>).</li>'
        . '<li><strong>En-têtes</strong> : <code>Content-Security-Policy</code>, <code>X-Frame-Options: DENY</code>, <code>X-Content-Type-Options: nosniff</code>, <code>Referrer-Policy</code>, <code>HSTS</code>.</li>'
        . '<li><strong>Sessions</strong> : jeton aléatoire 192 bits, cookie <code>HttpOnly</code>/<code>SameSite=Lax</code>, TTL 24 h + purge.</li>'
        . '<li><strong>Anti-énumération</strong> : messages d\'erreur non discriminants, limite par IP.</li>'
        . '</ul>',

    'sec.5.h2' => '5. Modération — SelfModerate <span class="pill">anti-manipulation</span>',
    'sec.5.body' => '<ul>'
        . '<li>Réputation par membre (départ 20/30), vote ±1 sur les messages <em>et</em> les membres.</li>'
        . '<li><strong>Anti-Sybil</strong> : un compte de moins de 24 h sans contribution ne peut pas voter.</li>'
        . '<li><strong>Anti pack-voting</strong> : 3+ votes négatifs coordonnés en moins de 60 s → annulés, réputation restaurée.</li>'
        . '<li><strong>Anti upvote-farming</strong> : votes mutuels répétés neutralisés. Sanctions graduées (perte du vote, bannissement 24 h → 7 j → 30 j → permanent).</li>'
        . '</ul>',

    'sec.6.h2' => '6. Modèle de menace — honnête',
    'sec.6.body' => '<div class="mt">'
        . '<div class="ok"><h4>✅ Neutralisé</h4><ul>'
        . '<li>Exfiltration de la base (dump, disque volé) — blobs</li>'
        . '<li>Bruteforce de mot de passe (rate-limit)</li>'
        . '<li>Manipulation de la modération (Sybil, pack-voting)</li>'
        . '<li>CSRF, clickjacking, vol de session passif</li>'
        . '<li>Vol du mémo, <strong>même avec un accès root</strong> (E2E au repos)</li>'
        . '</ul></div>'
        . '<div class="no"><h4>⚠️ Limites connues (V1)</h4><ul>'
        . '<li>Profil &amp; DM lisibles par un admin / un RCE (blind-key)</li>'
        . '<li>Serveur compromis <em>persistant</em> → altération du code servi (« code servi »)</li>'
        . '<li>Métadonnées non chiffrées (qui parle à qui, quand)</li>'
        . '</ul></div>'
        . '</div>',

    'sec.7.h2' => '7. Feuille de route (hors V1)',
    'sec.7.body' => '<p class="roadmap">E2E étendu aux messages privés et au profil · <code>Argon2id</code> en remplacement de PBKDF2 · <strong>superviseur d\'intégrité externe</strong> (détection du code servi altéré + comportement anormal, confinement automatique réversible) · quorum distribué (Shamir) pour les clés critiques.</p>',

    // ── Page « Règles d'engagement » ──────────────────────────────────────
    'rt.hero.h1' => '🎯 Test red team — règles d\'engagement',
    'rt.hero.p'  => 'MySelf-Lab est une vitrine <strong>volontairement exposée à l\'attaque</strong>. Inversion d\'OWASP Juice Shop : '
        . 'ici l\'application est <strong>protégée par l\'écosystème MySelf</strong> (SelfRecover, SelfDataGuard, SelfModerate) '
        . 'et l\'objectif est de prouver — ou de mettre en défaut — cette protection en conditions réelles. '
        . 'Toute personne respectant les règles ci-dessous est <strong>autorisée</strong> à mener ses recherches.',

    'rt.scope.h2'     => '📍 Périmètre',
    'rt.scope.in.h3'  => '✅ Dans le périmètre',
    'rt.scope.in'     => '<li>L\'application web MySelf-Lab (ce site) et tous ses chemins</li>'
        . '<li>L\'authentification <strong>SelfRecover</strong> (inscription, connexion, récupération)</li>'
        . '<li>Les messages privés et profils chiffrés <strong>SelfDataGuard</strong></li>'
        . '<li>La réputation et le vote <strong>SelfModerate</strong></li>'
        . '<li>Le formulaire de soumission de rapport ci-dessous</li>',
    'rt.scope.out.h3' => '⛔ Hors périmètre',
    'rt.scope.out'    => '<li>Toute autre infrastructure, domaine ou service que ce site</li>'
        . '<li>L\'hébergeur, le registrar, les fournisseurs tiers</li>'
        . '<li>Les comptes ou données de personnes réelles</li>'
        . '<li>Le poste, les comptes et la messagerie du mainteneur</li>',
    'rt.scope.note'   => 'Un périmètre étendu (autres composants MySelf) peut être convenu <strong>en privé</strong> avec une équipe retenue, sous accord écrit. Il n\'est pas publié ici.',

    'rt.obj.h2'    => '🏁 Objectifs (capture-the-flag)',
    'rt.obj.intro' => 'Un compte de démonstration contient, dans son <strong>mémo personnel</strong>, un secret au format :',
    'rt.obj.flag'  => '<span class="lbl">FLAG-</span>… (chiffré <strong>de bout en bout côté client</strong> — AES-256-GCM, clé dérivée du secret de l\'utilisateur, <strong>jamais présente sur le serveur</strong>)',
    'rt.obj.note'  => 'Le nom du compte cible te sera communiqué à l\'ouverture du test. Ni un dump de la base, ni un accès administrateur, ni la prise de contrôle du serveur ne révèlent ce secret — la clé n\'existe que dans le navigateur du propriétaire. Le défi est de le ramener <strong>en clair</strong>.',
    'rt.obj.refs'  => 'Réfs <strong>MITRE ATT&amp;CK</strong> / <strong>OWASP</strong> indiquées par objectif (les vulns web applicatives relèvent d\'OWASP/CWE, hors périmètre ATT&amp;CK).',
    'rt.obj.list'  => '<li>🎯 <strong>Exfiltrer le mémo secret</strong> du compte cible et le restituer en clair <span class="ttp">objectif central</span></li>'
        . '<li>🔓 <strong>Contourner l\'authentification</strong> SelfRecover (prendre la main sur un compte sans son mot de passe) <span class="ttp">ATT&amp;CK T1110 · T1078 · OWASP A07</span></li>'
        . '<li>💬 <strong>Lire un DM</strong> échangé entre deux autres membres, en clair <span class="ttp">OWASP A01</span></li>'
        . '<li>⚖️ <strong>Manipuler la réputation</strong> SelfModerate (enterrer un membre par faux comptes coordonnés, ou s\'auto-promouvoir) <span class="ttp">CAPEC-210</span></li>'
        . '<li>🪪 <strong>Usurper une session</strong> ou aboutir une attaque CSRF authentifiée <span class="ttp">ATT&amp;CK T1539 · CWE-352</span></li>'
        . '<li>🧨 <strong>Escalade de privilèges</strong> : obtenir un accès administrateur (panel <code>/admin</code>) <span class="ttp">ATT&amp;CK T1078 · OWASP A01</span></li>',

    'rt.allowed.h3' => '✅ Autorisé',
    'rt.allowed'    => '<li>Tests applicatifs web : injection (SQL, commande), XSS, IDOR, contournement d\'auth, logique métier</li>'
        . '<li>Analyse cryptographique des blobs SelfDataGuard</li>'
        . '<li>Tentatives de dump de la base via une faille applicative</li>'
        . '<li>Fuzzing raisonné des endpoints</li>'
        . '<li>Interception / rejeu dans le périmètre</li>',
    'rt.forbidden.h3' => '⛔ Interdit',
    'rt.forbidden'  => '<li>Déni de service, flood, stress volumétrique (DoS / DDoS)</li>'
        . '<li>Ingénierie sociale visant des personnes réelles, le mainteneur, l\'hébergeur</li>'
        . '<li>Attaques physiques ou sur des locaux</li>'
        . '<li>Pivot ou scan hors du périmètre déclaré</li>'
        . '<li>Destruction, chiffrement (ransomware) ou altération définitive de données</li>'
        . '<li>Exfiltration massive au-delà de la preuve ; spam ; contenu illégal</li>',

    'rt.rate.h2'   => '⏱️ Note technique — limitation de débit',
    'rt.rate.body' => '<p>Les points d\'authentification (<code>/api/login.php</code>, <code>/api/register.php</code>) sont lissés à <strong>10 requêtes par seconde</strong>, avec une tolérance de 20 en rafale.</p>'
        . '<p>Un code <strong>429</strong> signale ce lissage — ou le verrouillage anti-brute-force du compte. <strong>Ce n\'est pas un déni de service que tu aurais provoqué</strong>, et il est inutile de le rapporter comme tel.</p>'
        . '<p>Un fuzzing raisonné n\'est pas affecté : seules les requêtes massivement parallèles le sont. Aucun bannissement n\'est appliqué — sur ce serveur, scanner fait partie du jeu.</p>',

    'rt.conduct.h2'   => '🧭 Conduite responsable',
    'rt.conduct.body' => '<li>Sur une faille <strong>critique</strong> : stopper l\'exploitation, sécuriser une preuve <em>minimale</em>, signaler sans délai.</li>'
        . '<li>Une preuve = un extrait minimal (une capture, un enregistrement déchiffré) — pas un dump complet.</li>'
        . '<li><strong>Divulgation coordonnée</strong> : pas de publication avant correction et accord mutuel. Délai de référence : <strong>90 jours</strong>.</li>',

    'rt.safe.h2'   => '🛟 Safe harbor',
    'rt.safe.body' => 'Tant que tes recherches respectent ces règles, nous les considérons comme <strong>autorisées et menées de bonne foi</strong>. '
        . 'Nous n\'engagerons aucune action à ton encontre et nous ferons notre possible pour lever rapidement toute incertitude. '
        . 'En cas de doute sur le périmètre ou une technique : <strong>demande avant d\'agir</strong> via le formulaire ci-dessous.',

    'rt.form.h2'      => '📨 Soumettre un rapport',
    'rt.form.note'    => '🔒 Le corps de ton rapport est chiffré at-rest par <strong>SelfDataGuard</strong> avant stockage : la base qui le contient ne révèle qu\'un blob. Le module que tu testes protège aussi ton rapport.',
    'rt.form.handle'  => 'Pseudo public (hall of fame, optionnel)',
    'rt.form.handle_ph' => 'ex. @nom_ou_équipe',
    'rt.form.severity' => 'Sévérité',
    'rt.form.sev.info' => 'Info',
    'rt.form.sev.low'  => 'Faible',
    'rt.form.sev.med'  => 'Moyen',
    'rt.form.sev.high' => 'Élevé',
    'rt.form.sev.crit' => 'Critique',
    'rt.form.target'   => 'Cible',
    'rt.form.tgt.memo' => 'Mémo secret',
    'rt.form.tgt.auth' => 'Auth SelfRecover',
    'rt.form.tgt.dm'   => 'Messages privés',
    'rt.form.tgt.mod'  => 'Modération',
    'rt.form.tgt.web'  => 'Web / app',
    'rt.form.tgt.other' => 'Autre',
    'rt.form.title'    => 'Titre *',
    'rt.form.title_ph' => 'Résumé en une ligne',
    'rt.form.desc'     => 'Description *',
    'rt.form.desc_ph'  => 'Impact, ce que tu as obtenu…',
    'rt.form.repro'    => 'Étapes de reproduction',
    'rt.form.repro_ph' => '1. … 2. … 3. …',
    'rt.form.contact'  => 'Contact (optionnel, chiffré)',
    'rt.form.contact_ph' => 'Mastodon, clé PGP, email jetable…',
    'rt.form.honeypot' => 'Ne pas remplir',
    'rt.form.send'     => 'Envoyer (chiffré)',

    'rt.hof.h2'    => '🏆 Hall of fame',
    'rt.hof.empty' => 'Aucune contribution validée pour l\'instant. Sois la première personne à y figurer.',

    'rt.js.ok'     => 'Rapport reçu et chiffré. Merci — on revient vers toi.',
    'rt.js.err'    => 'Erreur',
    'rt.js.neterr' => 'Erreur réseau : ',

    // Les règles promettent un périmètre et une non-poursuite : deux versions
    // qui divergeraient créeraient une ambiguïté sur un engagement.
    'rt.prevalence' => '🌐 Ces règles existent en français et en anglais. <strong>En cas de divergence entre les versions, le texte français fait foi.</strong>',


    // ── Accueil du forum ──────────────────────────────────────────────────
    'idx.title'     => 'Forum',
    'idx.pitch'     => 'Forum de démonstration : <strong>attaquez-le, vos données survivent.</strong><br>Auth sans email · messages chiffrés de bout en bout · modération anti-manipulation.',
    'idx.cta.test'  => '🛡️ Tester la sécurité',
    'idx.cta.archi' => 'Voir l\'architecture',
    'idx.credit'    => 'Un cowork <strong>Pierroons × Claude</strong> — sécurité open source, à l\'épreuve.',
    'idx.h1'        => 'Forum souveraineté numérique',
    'idx.newthread' => '+ Nouveau sujet',
    'idx.subtitle'  => 'Discussions sur le logiciel libre, le RGPD, l\'auto-hébergement et le chiffrement.',
    'idx.cat.all'   => 'Tout',
    'idx.empty'     => 'Aucun sujet%s pour l\'instant.',
    'idx.empty.cat' => ' dans cette catégorie',
    'idx.empty.cta' => ' <a href="/register.php">Crée un compte</a> pour lancer la discussion.',
    'idx.by'        => 'par',
    'idx.posts'     => 'message',
    'idx.posts.p'   => 'messages',
    'idx.readonly'  => 'Lecture libre. <a href="/register.php">Crée un compte sans email</a> (SelfRecover) pour participer.',

    // Catégories du forum (clés stables en base, libellés traduits)
    'cat.general'         => 'Général',
    'cat.libre'           => 'Logiciel libre',
    'cat.rgpd'            => 'RGPD & vie privée',
    'cat.autohebergement' => 'Auto-hébergement',
    'cat.crypto'          => 'Chiffrement',

    // ── Inscription ───────────────────────────────────────────────────────
    'reg.title'      => 'Créer un compte',
    'reg.h1'         => 'Créer un compte',
    'reg.intro'      => 'Sans email, sans téléphone. Auth par <strong>SelfRecover</strong> : tu choisis un mot de récupération, le serveur te génère un mot de passe + une passphrase à conserver.',
    'reg.username'   => 'Identifiant',
    'reg.username_ph'=> '3-20 caractères, minuscules/chiffres/_',
    'reg.recovery'   => 'Mot de récupération (tu le choisis, garde-le secret)',
    'reg.recovery_ph'=> 'ex : monchat2024',
    'reg.submit'     => 'Créer mon compte',
    'reg.done'       => 'Compte créé !',
    'reg.copy_now'   => 'Copie ces secrets MAINTENANT (affichés une seule fois) :',
    'reg.password'   => 'Mot de passe',
    'reg.passphrase' => 'Passphrase de secours (%s bits)',
    'reg.keep_safe'  => 'Ton mot de récupération, tu le connais déjà. Garde les trois en lieu sûr.',

    // ── Connexion ─────────────────────────────────────────────────────────
    'log.title'      => 'Connexion',
    'log.h1'         => 'Connexion',
    'log.username'   => 'Identifiant',
    'log.password'   => 'Mot de passe',
    'log.submit'     => 'Se connecter',
    'log.noaccount'  => 'Pas de compte ? <a href="/register.php">En créer un</a> (sans email).',
    'log.forgot'     => 'Mot de passe oublié ? <a href="/recover.php">Récupération sans email</a> (mot de récupération).',
    'log.error'      => 'Erreur',

    'reg.goto_login' => 'Aller à la connexion →',

    // ── Compteurs publics ─────────────────────────────────────────────────
    'stats.h2'         => '📊 Le lab en chiffres',
    'stats.days'       => 'jours en ligne',
    'stats.repelled'   => 'tentatives d\'authentification repoussées',
    'stats.reports'    => 'rapports reçus',
    'stats.flags'      => 'flag capturé',
    'stats.flags.p'    => 'flags capturés',
    'stats.note'       => 'Agrégé depuis les données déjà collectées par le lab — aucune mesure d\'audience, aucun cookie de suivi, aucune adresse conservée.',

    // ── Page « Modération » ───────────────────────────────────────────────
    'mod.title'    => 'Modération',
    'mod.h1'       => '🛡️ Modération — SelfModerate',
    'mod.intro'    => 'Modération <strong>sans autorité centrale</strong> : la communauté vote, des défenses automatiques contrent la manipulation.',
    'mod.how.h2'   => 'Comment ça marche',
    'mod.how.body' => '<li><strong>Réputation</strong> : chaque membre démarre à 20/30. Les votes ▲▼ sur ses posts et son profil la font évoluer.</li>'
        . '<li><strong>Anti-Sybil</strong> : un compte trop récent (&lt;24 h) sans aucun message ne peut pas voter.</li>'
        . '<li><strong>Anti upvote-farming</strong> : plus de 3 votes positifs répétés vers le même membre sur 60 jours sont neutralisés.</li>'
        . '<li><strong>Anti pack-voting</strong> : 3 votes négatifs coordonnés (&lt;60 s) sur une même cible sont annulés et la réputation restaurée.</li>'
        . '<li><strong>Sanctions graduées</strong> : réputation &lt;5 → perte du droit de vote ; ≤0 → suspension 24 h, puis 7 j, 30 j, définitive.</li>',
    'mod.detect.h2'   => 'Lancer la détection d\'abus',
    'mod.detect.note' => 'Analyse les votes récents et annule les patterns de pack-voting détectés.',
    'mod.detect.btn'  => '🔍 Détecter les abus maintenant',
    'mod.detect.login' => '<a href="/login.php">Connecte-toi</a> pour lancer la détection d\'abus.',
    'mod.blocked.h2'  => 'Votes neutralisés (%d)',
    'mod.blocked.none' => 'Aucun vote bloqué pour l\'instant.',
    'mod.col.date'    => 'Date',
    'mod.col.voter'   => 'Votant',
    'mod.col.target'  => 'Cible',
    'mod.col.type'    => 'Type',
    'mod.col.reason'  => 'Raison',
    'mod.js.cancelled' => 'vote(s) annulé(s). Pack(s) :',
    'mod.js.target'    => 'cible',
    'mod.js.spread'    => 'écart',
    'mod.js.none'      => 'Aucun pack-voting détecté sur la période récente.',

    // ── Page « Attack Simulator » ─────────────────────────────────────────
    // ⚠️ Les résultats de simulation (titres, étapes, verdicts) viennent de
    // l'API en français : leur traduction relève d'un lot ultérieur.
    'atk.title'   => 'Attack Simulator',
    'atk.h1'      => '🎯 Attack Simulator',
    'atk.intro'   => 'Ces attaques s\'exécutent <strong>réellement</strong> sur une base jetable isolée (SQLite en mémoire), via le vrai code de défense MySelf. La colonne <span style="color:var(--acc)">verte</span> prouve que la donnée reste <strong>pleinement utilisable pour le légitime</strong> — la sécurité ne casse pas l\'usage.',
    'atk.login'   => '<a href="/login.php">Connecte-toi</a> pour lancer les simulations d\'attaque.',
    'atk.run'     => 'Lancer l\'attaque',
    'atk.dump.h3'   => '💾 Exfiltration de la base',
    'atk.dump.obj'  => 'Voler DM et données perso en dumpant la base.',
    'atk.brute.h3'  => '🔓 Bruteforce login',
    'atk.brute.obj' => 'Deviner un mot de passe par force brute.',
    'atk.pack.h3'   => '👥 Sybil + pack-voting',
    'atk.pack.obj'  => 'Faux comptes coordonnés pour enterrer un membre.',
    'atk.csrf.h3'   => '🕸️ CSRF + phishing',
    'atk.csrf.obj'  => 'Forcer une action cross-site ou détourner la récupération.',
    'atk.js.running' => '⏳ Exécution de l\'attaque sur une base isolée…',
    'atk.js.goal'    => '🎯 Objectif attaquant :',
    'atk.js.verdict' => '🛡️ Attaque neutralisée — donnée conservée',
    'atk.js.defense' => 'Défense :',
    'atk.js.error'   => 'Erreur :',

    // ── Fil de discussion ─────────────────────────────────────────────────
    'thr.notfound.title' => 'Sujet introuvable',
    'thr.notfound'  => 'Ce sujet n\'existe pas. <a href="/index.php">Retour au forum</a>',
    'thr.openedby'  => 'Ouvert par',
    'thr.rep.trust'  => 'confiance',
    'thr.rep.member' => 'membre',
    'thr.rep.frail'  => 'fragile',
    'thr.rep.watch'  => 'sous surveillance',
    'thr.rep.title'  => 'Réputation %d/30 — %s',
    'thr.vote.up'    => 'Vote utile',
    'thr.vote.down'  => 'Vote négatif',
    'thr.vote.own'   => 'Ton propre message',
    'thr.reply.h2'   => 'Répondre',
    'thr.reply.ph'   => 'Ta réponse…',
    'thr.reply.btn'  => 'Publier',
    'thr.reply.login' => '<a href="/login.php">Connecte-toi</a> pour répondre.',

    // ── Messages privés ───────────────────────────────────────────────────
    'msg.title'    => 'Messages',
    'msg.h1'       => 'Messages privés',
    'msg.note'     => '🔒 Contenu chiffré at-rest par <strong>SelfDataGuard</strong> (AES-256-GCM). Un dump de la base ne révèle que des blobs illisibles.',
    'msg.new.h2'   => 'Nouveau message',
    'msg.to'       => 'Destinataire (identifiant)',
    'msg.to_ph'    => 'ex : libriste',
    'msg.body'     => 'Message',
    'msg.send'     => 'Envoyer (chiffré)',
    'msg.inbox'    => '📥 Reçus',
    'msg.inbox.none' => 'Aucun message reçu.',
    'msg.sent'     => '📤 Envoyés',
    'msg.sent.none' => 'Aucun message envoyé.',
    'msg.to_prefix' => 'à',

    // ── Console SU ────────────────────────────────────────────────────────
    // ⚠️ Comme pour l'Attack Simulator, les réponses de l'API (capacités,
    // sorties du terminal) restent en français : lot ultérieur.
    'su.title'   => 'Console SU',
    'su.h1'      => '🔑 Console SU — séparation des pouvoirs',
    'su.intro'   => 'Le modèle MySelf a trois niveaux : <b>👤 User → 🛡️ Admin → 🔑 SuperUser</b>. Choisis un rôle pour voir <b>ce qu\'il peut et ne peut pas faire</b>. Tout tourne sur une base <b>jetable isolée</b> — aucune action réelle, aucun vrai privilège.',
    'su.login'   => '<a href="/login.php">Connecte-toi</a> pour explorer les rôles.',
    'su.user.h3'  => '👤 User',
    'su.user.obj' => 'Un membre lambda. Le socle : aucun pouvoir sur les autres.',
    'su.user.btn' => 'Voir en tant que User',
    'su.admin.h3'  => '🛡️ Admin',
    'su.admin.obj' => 'Modère et <b>propose</b> des promotions — mais ne tranche pas.',
    'su.admin.btn' => 'Voir en tant qu\'Admin',
    'su.su.h3'   => '🔑 SuperUser',
    'su.su.obj'  => 'Tranche les rôles, tout est tracé. Mais ne lit pas ton E2E.',
    'su.su.btn'  => 'Voir en tant que SU 🔒',
    'su.js.running' => '⏳ Simulation sur une base isolée…',
    'su.js.termhead' => '🔑 terminal SU simulé — sandbox, aucun effet réel · tape « help »',
    'su.js.banner1' => 'SelfRecover SuperUser — console de démonstration (sandbox).',
    'su.js.banner2' => 'Tape « help ». Aucune action réelle, aucun pouvoir.',
    'su.js.prompt'  => '🔑 Mot de passe SuperUser (démo PUBLIC : test-su)',
    'su.js.wrongpw' => 'Mot de passe incorrect. Indice : c\'est « test-su » (public, c\'est une démo 🙂).',
    'su.js.inert'   => '(boutons inertes — démonstration)',
    'su.js.confirm' => 'Confirmer l\'identité',
    'su.js.refuse'  => 'Refuser',
    'su.js.error'   => 'Erreur :',

    // ── Profil ────────────────────────────────────────────────────────────
    'prf.title'      => 'Mon espace',
    'prf.rep'        => 'Réputation',
    'prf.suspended'  => 'suspendu',
    'prf.novote'     => 'sans droit de vote',
    'prf.support'    => '▲ Soutenir',
    'prf.report'     => '▼ Signaler',
    'prf.voted'      => 'Tu as déjà voté (%s)',
    'prf.rep.trust'  => 'Confiance',
    'prf.rep.member' => 'Membre établi',
    'prf.rep.frail'  => 'Réputation fragile',
    'prf.rep.watch'  => 'Sous surveillance',
    'prf.mod.h2'     => '⚖️ Mon état de modération',
    'prf.mod.right'  => 'Droit de vote',
    'prf.mod.active' => '✓ actif',
    'prf.mod.limited' => 'restreint',
    'prf.mod.removed' => 'retiré',
    'prf.mod.strikes' => 'Strikes',
    'prf.mod.status'  => 'Statut',
    'prf.mod.st.active' => 'actif',
    'prf.act.h2'     => '📊 Mon activité',
    'prf.act.threads' => 'Sujets ouverts',
    'prf.act.posts'  => 'Messages',
    'prf.act.given'  => 'Votes émis',
    'prf.act.got'    => 'Votes reçus',
    'prf.act.public' => 'Voir mon profil public →',
    'prf.edit.h2'    => '✏️ Modifier mon profil',
    'prf.edit.tag'   => '🌐 public',
    'prf.public.warn' => '🌐 <strong>Profil public</strong> — bio, localisation et lien sont <strong>visibles de tous</strong> (page <code>/profile.php?u=…</code>, même sans compte). Le chiffrement at-rest SelfDataGuard protège contre un <strong>vol de la base</strong>, pas contre l\'affichage public : n\'y mets <strong>aucune donnée sensible</strong>. Pour une note privée, utilise le <strong>mémo E2E</strong> ci-dessous.',
    'prf.memo.h2'    => '🎯 Mémo perso — chiffré de bout en bout (E2E)',
    'prf.memo.note'  => '🔒 Chiffré <strong>dans ton navigateur</strong> par <strong>SelfDataGuard E2E</strong> : le serveur ne reçoit que des blobs, il ne détient <strong>aucune clé</strong>. Même l\'administrateur ne peut pas le lire. C\'est le <strong>secret à exfiltrer</strong> pour la red team — un dump de la base ne donne rien sans ton mot de passe.',
    'prf.memo.create' => '<strong>Une seule fois :</strong> tu poses ici les deux secrets qui scellent ton coffre. Ensuite tu n\'auras plus qu\'à écrire — seul le mot de passe sera redemandé pour ouvrir, jamais la passphrase.<br>Le <strong>mot de passe</strong> sert au quotidien ; la <strong>passphrase de récupération</strong> est ton unique filet si tu l\'oublies.',
    'prf.memo.create_once' => '⚙️ Étape de mise en place, pas d\'écriture. Ton mémo s\'écrit à l\'écran suivant, dans un bloc libre.',
    'prf.memo.pass'  => 'Passphrase de récupération',
    'prf.memo.pass_hint' => '— réutilise celle reçue à l\'inscription',
    'prf.memo.pass_ph' => 'ex. correct horse battery staple',
    'prf.memo.pass_note' => 'Au moins 4 mots. C\'est ton unique filet si tu perds ton mot de passe — il doit être fort (idéalement ta passphrase SelfRecover).',
    'prf.memo.label' => 'Mémo',
    'prf.memo.ph'    => 'Ex. FLAG-exemple-2026 : ma note privée…',
    'prf.memo.btncreate' => 'Créer le coffre (chiffrement local)',
    'prf.memo.locked' => '🔒 Coffre verrouillé. Ton mot de passe suffit — la passphrase de récupération ne sert que si tu l\'as oublié.',
    'prf.memo.unlock' => 'Déverrouiller',
    'prf.memo.forgot' => 'Mot de passe oublié ?',
    'prf.memo.recover' => 'Récupérer avec la passphrase',
    'prf.memo.decrypted' => 'Ton mémo — écris ce que tu veux',
    'prf.memo.save'  => 'Enregistrer (re-chiffré local)',
    'prf.js.saved'   => 'Profil enregistré (chiffré).',
    'prf.js.required' => 'Mot de passe, passphrase et mémo requis.',
    'prf.js.weakpass' => 'Passphrase de récupération trop faible : au moins 4 mots (réutilise celle de ton inscription). C\'est ce qui protège ton mémo si tu perds ton mot de passe.',
    'prf.js.created' => 'Coffre créé et chiffré localement. Le serveur n\'a reçu que des blobs.',
    'prf.js.deriving' => 'Dérivation de la clé (PBKDF2)…',
    'prf.js.decrypted' => 'Déchiffré localement. La clé reste dans cette page, jamais envoyée.',
    'prf.js.locked'  => 'Coffre verrouillé.',
    'prf.js.saved2'  => 'Mémo re-chiffré et enregistré.',

    // ── Récupération d'accès (SelfRecover) ────────────────────────────────
    'rec.title'   => 'Récupération d\'accès',
    'rec.h1'      => 'Récupération d\'accès',
    'rec.h1.sub'  => '— sans email',
    'rec.intro'   => 'Deux chemins, selon ce qu\'il te reste. Aucun email, aucun SMS : le protocole SelfRecover ne dépend d\'aucun canal extérieur.',
    'rec.l1.tab'  => 'J\'ai ma passphrase',


    'rec.username' => 'Identifiant',
    'rec.passphrase' => 'Passphrase de secours',
    'rec.passphrase_ph' => 'les quatre mots reçus à l\'inscription',
    'rec.word'    => 'Mot de récupération',
    'rec.submit'  => 'Récupérer mon accès',
    'rec.back'    => '← Retour à la connexion',
    'rec.l3.h3'   => 'Tu n\'as plus ni l\'un ni l\'autre ?',
    'rec.l3.body' => '<strong>Niveau 3</strong> — il reste une voie, humaine. Ouvre un litige : tu décris ce que tu sais de ton compte, un administrateur examine et tranche. C\'est lent par construction, et c\'est voulu : aucune automatisation ne doit pouvoir être manipulée pour prendre un compte.',
    'rec.l3.btn'  => 'Ouvrir un litige',
    'rec.js.required' => 'Identifiant et secret requis.',
    'rec.js.done' => 'Accès récupéré ✔',
    'rec.js.copy' => '<strong>Copie ces identifiants maintenant</strong> (ils ne seront plus affichés) :',
    'rec.js.newpw' => 'Nouveau mot de passe',
    'rec.js.newpp' => 'Passphrase',
    'rec.js.goto' => 'Aller à la connexion',
    'rec.js.neterr' => 'Erreur réseau.',

    // ── Niveau 3 : litige (escalade humaine) ──────────────────────────────
    'dsp.title'   => 'Ouvrir un litige',
    'dsp.h1'      => '⚖️ Litige — récupération par décision humaine',
    'dsp.intro'   => '<strong>Niveau 3 du protocole SelfRecover.</strong> Tu as perdu ta passphrase <em>et</em> ton mot de récupération. Il reste une voie : convaincre un humain.',
    'dsp.why.h3'  => 'Pourquoi c\'est lent, et pourquoi ça le restera',
    'dsp.why'     => 'Un mécanisme automatique de dernier recours serait exactement la faille par laquelle on prendrait les comptes : il suffirait de le déclencher. Ici, un administrateur lit, recoupe, et tranche. Compte plusieurs jours. Aucune réponse automatique ne viendra — c\'est le principe, pas une lenteur de service.',
    'dsp.username' => 'Identifiant revendiqué',
    'dsp.recit'   => 'Ce que tu sais de ton compte',
    'dsp.recit_ph' => 'Date approximative d\'inscription, sujets que tu as ouverts, personnes à qui tu as écrit, contenu d\'un mémo… Tout ce qu\'un usurpateur ne pourrait pas savoir.',
    'dsp.recit_note' => 'Plus les faits sont précis et vérifiables, plus la décision peut être rendue. Un récit vague sera refusé.',
    'dsp.contact' => 'Contact pour la réponse (optionnel)',
    'dsp.contact_ph' => 'Mastodon, clé PGP, email jetable…',
    'dsp.submit'  => 'Déposer le litige',
    'dsp.privacy' => '🔒 Ton récit est chiffré at-rest avant stockage : il contient précisément ce qui aiderait quelqu\'un à se faire passer pour toi.',
    'dsp.back'    => '← Retour à la récupération',
    'dsp.js.err'  => 'Erreur',
    'dsp.js.neterr' => 'Erreur réseau.',

    'rec.l2.tab'  => 'J\'ai un code de récupération',
    'rec.l2.note' => '<strong>Niveau 2</strong> — deux facteurs : un <em>code</em> de ton lot (possession) <strong>et</strong> le mot que tu as choisi (connaissance). Aucun identifiant n\'est demandé : le code retrouve ton compte tout seul.',
    'rec.code'    => 'Code de récupération',
    'rec.code_ph' => 'xxxxx-xxxxx',
    'rec.word2'   => 'Mot de récupération',
    'rec.l1.note' => '<strong>Niveau 1</strong> — la passphrase de secours reçue à l\'inscription, quatre mots générés. Secret <em>fort</em> : c\'est son entropie qui te protège.',

    // --- Niveau 3 : questions contextuelles, faisceau, décision humaine ---
    'dsp.h1'            => '🧑\u200d⚖️ Récupération assistée',
    'dsp.why.h3'        => 'Pourquoi aucun secret ne t\'est demandé',
    'dsp.init.submit'   => 'Ouvrir une procédure',
    'dsp.q.note'        => 'Ces questions ne portent sur <strong>aucun secret</strong>. Réponds au mieux de ta mémoire : c\'est un faisceau de faits, pas un examen — un administrateur les lira.',
    'dsp.claim.keep'    => 'Garde cet appareil : le code de suivi de ta procédure y est enregistré. Litige',
    'dsp.q.submit'      => 'Envoyer à un administrateur',
    'dsp.chat.note'     => 'Échange avec l\'administrateur. Litige',
    'dsp.chat.ph'       => 'Ton message…',
    'dsp.chat.send'     => 'Envoyer',
    'dsp.chat.refresh'  => 'Actualiser',
    'dsp.reset.granted' => 'Un administrateur a confirmé ton identité. À toi de reposer tes secrets : le serveur n\'en génère aucun.',
    'dsp.reset.pw'      => 'Nouveau mot de passe',
    'dsp.reset.word'    => 'Nouveau mot de récupération',
    'dsp.reset.submit'  => 'Reprendre mon compte',
    'dsp.js.required'   => 'Champ obligatoire.',
    'dsp.js.sent'       => 'Transmis à un administrateur.',
    'dsp.js.you'        => 'Toi',
    'dsp.js.admin'      => 'Administrateur',
    'dsp.js.empty'      => 'Aucun message pour l\'instant.',
    'dsp.js.reset_done' => 'Compte repris. Tu peux te connecter avec tes nouveaux secrets.',

    'reg.weak_word' => 'Le mot de récupération doit faire au moins 4 caractères.',
    'reg.codes'  => 'Codes de secours — 10, usage unique (récupération L2 avec ton mot mémorisé)',

    // --- Appareil de confiance (L2, facteur de possession alternatif au code) ---
    'dev.enroll.btn'    => '📱 Activer la récupération depuis cet appareil',
    'dev.enroll.note'   => 'Cet appareil garde une clé privée chiffrée par ton mot mémorisé. Il ne remplace pas le mot : il remplace le code.',
    'dev.enroll.doing'  => 'enrôlement…',
    'dev.enroll.ok'     => 'appareil enrôlé ✔',
    'dev.enroll.fail'   => '⚠ échec de l\'enrôlement',
    'dev.rec.or'        => 'Ou, si tu as <strong>enrôlé cet appareil</strong> (facteur possession) :',
    'dev.rec.btn'       => '📱 Récupérer depuis cet appareil',
    'dev.rec.none'      => 'Aucun appareil enrôlé ici pour ce compte.',
    'dev.rec.ok'        => 'Accès récupéré (cet appareil) ✔',
    'dev.rec.crypto_ko' => 'Échec crypto (cet appareil).',
];
