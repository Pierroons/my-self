<?php
/**
 * MySelf-Lab — English CONTENT dictionary, indexed by the French source text.
 *
 * Interface labels live in `en.php`, keyed by identifier. This file covers what
 * the content classes compose at runtime — the attack simulator, the SU
 * console, the moderation engine. Those build arrays of sentences, and giving
 * each one an identifier would scatter a hundred and sixty strings away from
 * the code that assembles them and alone gives them meaning.
 *
 * Indexing by the source text is the gettext convention. A sentence with no
 * entry here renders in French: the page stays readable, never hollow.
 *
 * ⚠️ Change a French sentence and its entry stops matching — the page silently
 * falls back to French. Grep this file whenever you reword a content class.
 */

declare(strict_types=1);

return [

    // ── SU console: user role ─────────────────────────────────────────────
    'Un membre lambda — le socle du modèle' => 'An ordinary member — the baseline of the model',
    'Ouvre SON mémo chiffré' => 'Opens THEIR OWN encrypted memo',
    'déchiffré côté client avec SA clé (jamais sur le serveur)' => 'decrypted client-side with THEIR key (never on the server)',
    'Tente de voir la file des promotions admin' => 'Tries to view the admin promotion queue',
    'refusé — réservé aux admins' => 'denied — admins only',
    'Tente de lire le mémo d\'un autre membre' => 'Tries to read another member\'s memo',
    'refusé — chiffré E2E, ce n\'est pas sa clé' => 'denied — end-to-end encrypted, not their key',
    'Ce qu\'un user PEUT' => 'What a user CAN do',
    'Lire et écrire SON propre mémo (chiffré E2E côté client)' => 'Read and write THEIR OWN memo (end-to-end encrypted client-side)',
    'Publier, voter, ouvrir un litige de récupération' => 'Post, vote, open a recovery case',
    'Gérer son profil' => 'Manage their profile',
    'Modérer, bannir, gracier' => 'Moderate, ban, pardon',
    'Voir ou trancher les demandes de promotion' => 'View or decide promotion requests',
    'Lire les mémos / DM des autres membres' => 'Read other members\' memos or private messages',
    'Un compte lambda n\'a AUCUN pouvoir sur les autres — la séparation des pouvoirs commence ici.'
        => 'An ordinary account holds NO power over anyone else — separation of powers starts here.',

    // ── SU console: admin role ────────────────────────────────────────────
    'Modère et PROPOSE — mais ne tranche pas les rôles' => 'Moderates and PROPOSES — but does not decide roles',
    'Bannit temporairement un membre abusif' => 'Temporarily bans an abusive member',
    'OK — action de modération' => 'OK — moderation action',
    'PROPOSE la promotion de user_lambda en admin' => 'PROPOSES promoting user_lambda to admin',
    'OK — demande créée, en attente du SU' => 'OK — request created, awaiting the SU',
    'Tente de s\'auto-promouvoir SU' => 'Tries to self-promote to SU',
    'refusé — un admin ne se promeut pas lui-même' => 'denied — an admin cannot promote themselves',
    'Tente de lire le mémo E2E de user_lambda' => 'Tries to read user_lambda\'s end-to-end encrypted memo',
    'chiffré, illisible' => 'encrypted, unreadable',
    'Rôle inconnu.' => 'Unknown role.',
    'Ce qu\'un admin PEUT' => 'What an admin CAN do',
    'Ce qu\'il NE PEUT PAS' => 'What they CANNOT do',
    'Modérer : ban / grâce, arbitrer les litiges L3' => 'Moderate: ban and pardon, arbitrate L3 cases',
    'PROPOSER une promotion (jamais l\'appliquer seul)' => 'PROPOSE a promotion (never apply it alone)',
    'Consulter les signaux de modération' => 'Review moderation signals',
    'Trancher une promotion (c\'est le rôle du SU)' => 'Decide a promotion (that is the SU\'s role)',
    'Se promouvoir lui-même / fabriquer un admin' => 'Promote themselves or manufacture an admin',
    'Lire le mémo E2E d\'un membre (blob chiffré)' => 'Read a member\'s end-to-end encrypted memo (ciphertext blob)',
    'L\'admin propose, il ne dispose pas : impossible de fabriquer de nouveaux admins seul.'
        => 'The admin proposes but does not dispose: no one can manufacture admins alone.',

    // ── SU console: admin panel preview ───────────────────────────────────
    'Ce que voit un admin dans /admin.php' => 'What an admin sees in /admin.php',
    'Reproduction fidèle, données fictives. À retenir : l\'admin déchiffre profils et messages privés, mais bute sur le mémo.'
        => 'Faithful reproduction, fictitious data. The point: an admin decrypts profiles and private messages, but hits a wall at the memo.',
    'comptes' => 'accounts',
    'nouveaux 24h' => 'new in 24h',
    'sujets/posts' => 'threads/posts',
    'échecs login 24h' => 'login failures 24h',
    'votes bloqués' => 'blocked votes',
    'rapports neufs' => 'new reports',
    '🔓 Échecs de login récents' => '🔓 Recent login failures',
    'Compte visé' => 'Target account',
    'Quand' => 'When',
    '🗳️ Votes neutralisés' => '🗳️ Neutralised votes',
    'Votant' => 'Voter',
    'Cible' => 'Target',
    'Raison' => 'Reason',
    'hier' => 'yesterday',
    '👥 Comptes' => '👥 Accounts',
    'Identifiant' => 'Identifier',
    'Réputation' => 'Reputation',
    'Créé' => 'Created',
    'Profil déchiffré' => 'Decrypted profile',
    'Modération' => 'Moderation',
    'voir (mémo)' => 'view (memo)',
    'bannir · gracier' => 'ban · pardon',
    '⚠️ Le profil EST lisible : clé serveur, pas clé utilisateur. Limite assumée.'
        => '⚠️ The profile IS readable: server key, not user key. An acknowledged limit.',
    '🧑‍⚖️ Litiges — récupération niveau 3' => '🧑‍⚖️ Cases — level 3 recovery',
    'Litige' => 'Case',
    'Compte revendiqué' => 'Claimed account',
    'Statut' => 'Status',
    'Actions' => 'Actions',
    'confirmer · refuser' => 'confirm · refuse',
    'Confirmer n\'ouvre aucun accès : le propriétaire repose lui-même ses secrets.'
        => 'Confirming grants no access: the owner sets their own secrets afterwards.',
    '📨 Rapports red team' => '📨 Red team reports',
    'Pseudo' => 'Handle',
    'Sévérité' => 'Severity',
    'Reçu' => 'Received',
    'lire · valider' => 'read · validate',
    'moyen' => 'medium',
    'nouveau' => 'new',
    '⚠️ Section la plus sensible : failles non corrigées. D\'où cette reproduction fictive.'
        => '⚠️ The most sensitive section: unfixed vulnerabilities. Hence this fictitious reproduction.',
    'bruteforce ?' => 'brute force?',
    'Sybil / pack' => 'Sybil / pack',
    'pack-voting' => 'pack voting',

    // ── SU console: L3 bundle of signals ──────────────────────────────────
    'IP déjà utilisée par ce compte' => 'IP already used by this account',
    'IP jamais vue pour ce compte' => 'IP never seen for this account',
    'Année de création' => 'Creation year',
    'Dernière connexion (mois)' => 'Last login (month)',
    'jamais connecté' => 'never logged in',
    'Fréquence d\'usage' => 'Usage frequency',
    'intensif' => 'heavy',
    'rare (~0 connexions)' => 'rare (~0 logins)',
    '0/1 passifs · 1/3 déclaratifs concordants' => '0/1 passive · 1/3 declarative signals matching',

    // ── SU console: what the admin cannot reach ───────────────────────────
    'Ce sur quoi l\'admin bute' => 'What the admin hits a wall on',
    'Mémo E2E : illisible même pour lui' => 'End-to-end encrypted memo: unreadable even to them',
    'Mot de récupération → jamais reçu, seule sa dérivée circule'
        => 'Recovery word → never received, only its derived key travels',
    'Codes de secours → Argon2id, aucun moyen de les relire'
        => 'Backup codes → Argon2id, no way to read them back',

    // ── SU console: superuser role ────────────────────────────────────────
    'Le juge de dernier recours — CLI / hors-ligne, jamais sur le web'
        => 'The court of last resort — command line, offline, never on the web',
    'Approuve la promotion de user_lambda' => 'Approves user_lambda\'s promotion',
    'OK — user_lambda devient admin (action tracée)' => 'OK — user_lambda becomes admin (action logged)',
    'Révoque un admin compromis' => 'Revokes a compromised admin',
    'OK — is_admin=0 + sessions coupées (tracé)' => 'OK — is_admin=0 and sessions cut (logged)',
    'Chaque action est écrite au journal' => 'Every action is written to the log',
    'MÊME le SU ne le déchiffre pas' => 'EVEN the SU cannot decrypt it',
    'Ce que le SU PEUT' => 'What the SU CAN do',
    'Approuver / rejeter les promotions (créer les admins)' => 'Approve or reject promotions (create admins)',
    'Révoquer un admin + couper ses sessions' => 'Revoke an admin and cut their sessions',
    'Auditer : tout est tracé, 0 admin fantôme' => 'Audit: everything is logged, no phantom admins',
    'Ce que MÊME le SU NE PEUT PAS' => 'What EVEN the SU CANNOT do',
    'Lire ton mémo chiffré E2E (la clé n\'a jamais touché le serveur)'
        => 'Read your end-to-end encrypted memo (the key never touched the server)',
    'Agir sans laisser de trace (log append-only + HMAC)'
        => 'Act without leaving a trace (append-only log, HMAC-chained)',
    'Exister sur le web : il vit en CLI, hors-ligne' => 'Exist on the web: it lives in the command line, offline',
    'Même le super-admin ne lit pas ton secret. Le pouvoir maximal reste borné par la crypto E2E et tracé par l\'audit.'
        => 'Even the super-admin cannot read your secret. Maximum power stays bounded by end-to-end encryption and bounded by the audit log.',
    // ── Attack simulator: database exfiltration ───────────────────────────
    'Exfiltration de la base de données' => 'Database exfiltration',
    'Voler les messages privés et données personnelles en dumpant la base SQLite.'
        => 'Steal private messages and personal data by dumping the SQLite database.',
    'Bob envoie un DM contenant son RIB à Alice' => 'Bob sends Alice a private message containing his bank details',
    'message chiffré AES-256-GCM avant insertion' => 'message encrypted with AES-256-GCM before insertion',
    'profil chiffré at-rest' => 'profile encrypted at rest',
    'L\'attaquant exfiltre la base et lit les tables dm + profiles'
        => 'The attacker exfiltrates the database and reads the dm and profiles tables',
    'il n\'obtient que des blobs base64' => 'all they get are base64 blobs',
    'Dump SQL brut (ce que voit l\'attaquant)' => 'Raw SQL dump (what the attacker sees)',
    'Alice connectée (ce que conserve le propriétaire)' => 'Alice logged in (what the owner still holds)',
    'neutralisé' => 'neutralised',
    'SelfDataGuard (chiffrement enveloppé AES-256-GCM, clé hors base)'
        => 'SelfDataGuard (envelope encryption, AES-256-GCM, key held outside the database)',

    // ── Attack simulator: Sybil and pack voting ───────────────────────────
    'Sybil + pack-voting (enterrement coordonné)' => 'Sybil and pack voting (coordinated burial)',
    'Créer de faux comptes pour faire chuter la réputation d\'un membre par un downvote coordonné.'
        => 'Create fake accounts to sink a member\'s reputation through coordinated downvotes.',
    'Un membre honnête soutient la cible (+1)' => 'An honest member supports the target (+1)',
    'réputation : %d' => 'reputation: %d',
    '3 faux comptes downvotent la cible en moins de 60 s' => '3 fake accounts downvote the target within 60 s',
    'réputation chute à : %d' => 'reputation drops to: %d',
    'Détection de pack-voting' => 'Pack voting detection',
    '%d votes annulés, réputation restaurée à : %d' => '%d votes cancelled, reputation restored to: %d',
    'Un compte créé à l\'instant tente de voter' => 'A freshly created account tries to vote',
    'Impact net sur la réputation : %d' => 'Net impact on reputation: %d',
    'Modération communautaire honnête' => 'Honest community moderation',
    'Réputation finale de la cible : %d (intègre)' => 'Target\'s final reputation: %d (intact)',
    'Les votes légitimes restent comptés, seuls les coordonnés tombent.'
        => 'Legitimate votes still count; only the coordinated ones fall.',
    'SelfModerate (détection pack-voting + anti-Sybil par seuils)'
        => 'SelfModerate (pack voting detection and threshold-based anti-Sybil)',

    // ── Attack simulator: CSRF and recovery phishing ──────────────────────
    'CSRF + phishing de récupération' => 'CSRF and recovery phishing',
    'Forcer une action au nom de la victime (CSRF) ou détourner sa récupération de compte (phishing).'
        => 'Force an action in the victim\'s name (CSRF) or hijack their account recovery (phishing).',
    'Un site tiers tente une action POST sans jeton CSRF' => 'A third-party site attempts a POST with no CSRF token',
    'rejetée (403)' => 'rejected (403)',
    'acceptée (!)' => 'accepted (!)',
    'Avec un faux jeton CSRF' => 'With a forged CSRF token',
    'Un site de phishing imite le forum pour dériver la clé de récupération'
        => 'A phishing site imitates the forum to derive the recovery key',
    'obtient une clé totalement différente' => 'gets a completely different key',
    'Tentatives de l\'attaquant' => 'Attacker attempts',
    'POST sans jeton CSRF : REJETÉ (403)' => 'POST with no CSRF token: REJECTED (403)',
    'POST avec faux jeton : REJETÉ (403)' => 'POST with forged token: REJECTED (403)',
    'Clé dérivée sur un faux site : %s' => 'Key derived on a fake site: %s',
    'Usage légitime' => 'Legitimate use',
    'POST avec le bon jeton CSRF : ✓ accepté' => 'POST with the correct CSRF token: ✓ accepted',
    'Clé dérivée sur le vrai site : %s' => 'Key derived on the real site: %s',
    'Les deux clés diffèrent → un faux site ne peut PAS reproduire la bonne.'
        => 'The two keys differ → a fake site CANNOT reproduce the right one.',

    // ── Attack simulator: remaining strings ──────────────────────────────
    'Recherche "RIB" / "Acacias" dans le dump : 0 résultat'
        => 'Searching the dump for "IBAN" or "Acacias": 0 results',
    'DM reçu : « Mon RIB : FR76 0000 0000 0000 0000 0000 000 — règlement marché »'
        => 'Private message received: "My IBAN: FR76 0000 0000 0000 0000 0000 000 — market payment"',
    'Profil bio : « Adresse réelle : 15 rue des Acacias, 33000 Bordeaux »'
        => 'Profile bio: "Real address: 15 rue des Acacias, 33000 Bordeaux"',
    'La donnée est intégralement conservée et utilisable par Alice — mais l\'attaquant ne récupère que du bruit chiffré.'
        => 'The data stays whole and usable for Alice — the attacker walks away with encrypted noise.',
    'Vote du membre établi : ✓ conservé (+1)' => 'Established member\'s vote: ✓ kept (+1)',
    'La manipulation coordonnée est annulée et la réputation restaurée, sans toucher aux votes honnêtes.'
        => 'Coordinated manipulation is cancelled and the reputation restored, without touching honest votes.',
    'L\'action légitime (bon jeton, vrai domaine) passe ; l\'attaquant cross-site et le phishing échouent.'
        => 'The legitimate action (right token, real site) goes through; the cross-site attacker and the phishing site both fail.',

    // ── Remaining content strings ────────────────────────────────────────
    '3 downvotes coordonnés → %d annulés (pack_voting)' => '3 coordinated downvotes → %d cancelled (pack voting)',
    'Faux compte neuf → %s' => 'Brand-new fake account → %s',
    'a pu voter' => 'was able to vote',
    'BLOQUÉ (anti-Sybil)' => 'BLOCKED (anti-Sybil)',
    '👤 Utilisateur (user_lambda)' => '👤 User (user_lambda)',
    '🛡️ Administrateur (admin_demo)' => '🛡️ Administrator (admin_demo)',
    '🔑 SuperUser (SU)' => '🔑 SuperUser (SU)',
    'log append-only + HMAC — infalsifiable' => 'append-only log, HMAC-chained — tamper-evident',
    'Alice renseigne son adresse dans son profil' => 'Alice fills in her address on her profile',

    // ── Moderation messages ──────────────────────────────────────────────
    'Paramètres de vote invalides.' => 'Invalid vote parameters.',
    'Tu ne peux pas voter pour toi-même.' => 'You cannot vote for yourself.',
    'Tu as déjà voté ici.' => 'You have already voted here.',
    'Vote enregistré mais neutralisé : trop d\'upvotes répétés vers ce membre (anti-farming).'
        => 'Vote recorded but neutralised: too many repeated upvotes towards this member (anti-farming).',
    'Vote enregistré mais neutralisé : trop de downvotes répétés vers ce membre (anti-farming).'
        => 'Vote recorded but neutralised: too many repeated downvotes towards this member (anti-farming).',
    'Compte trop récent : publie au moins un message ou attends 24 h pour pouvoir voter (anti-Sybil).'
        => 'Account too new: post at least once, or wait 24 h, before you can vote (anti-Sybil).',
    'Compte temporairement suspendu.' => 'Account temporarily suspended.',
    'Droit de vote retiré (réputation trop basse).' => 'Voting rights withdrawn (reputation too low).',

    // ── Red team report encryption ───────────────────────────────────────
    'Rapport vide ou chiffrement absent.' => 'Empty report, or encryption missing.',
    'Le rapport doit être chiffré. Recharge la page et réessaie.'
        => 'Reports must be encrypted. Reload the page and try again.',
    'Rapport trop volumineux.' => 'Report too large.',
];
