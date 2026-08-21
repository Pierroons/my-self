<?php
/**
 * MySelf-Lab — English dictionary.
 *
 * Any key missing here falls back to French rather than rendering empty, so a
 * partial translation stays readable. The French text remains authoritative:
 * see the prevalence clause at the bottom of the rules of engagement.
 *
 * Security wording follows the terms the field actually uses — "scope",
 * "safe harbour", "coordinated disclosure" — rather than literal translations
 * of the French, which would read as machine output to the audience this page
 * is written for.
 */

declare(strict_types=1);

return [

    // ── Navigation and shared layout ──────────────────────────────────────
    'nav.forum'        => 'Forum',
    'nav.moderation'   => 'Moderation',
    'nav.attacks'      => '🎯 Attacks',
    'nav.security'     => '🔐 Security',
    'nav.redteam'      => '🛡️ Red Team',
    'nav.su'           => '🔑 SU console',
    'nav.messages'     => 'Messages',
    'nav.profile'      => 'My space',
    'nav.admin'        => '🛠️ Admin',
    'nav.logout'       => 'Log out',
    'nav.login'        => 'Log in',
    'nav.register'     => 'Create an account',
    'nav.demo_tag'     => 'demo · noindex',

    'banner' => '🔬 MySelf demonstration forum — authentication by '
              . '<strong>SelfRecover</strong> (no email), private messages encrypted by '
              . '<strong>SelfDataGuard</strong> (resistant to database exfiltration).',

    'footer' => 'MySelf-Lab · showcase for the <a href="https://my-self.fr">MySelf</a> '
              . 'ecosystem · built by Pierroons &amp; Claude (Anthropic) · AGPL-3.0 · '
              . 'demonstration',

    // ── Page titles (<title> tag) ─────────────────────────────────────────
    'title.security' => 'Security architecture',
    'title.redteam'  => 'Red team engagement rules',

    // ── "Security architecture" page ──────────────────────────────────────
    'sec.h1' => '🔐 Security architecture',

    'sec.intro' => '<strong>Deliberate transparency.</strong> This page documents our defences <em>and their limits</em>. '
        . 'No security through obscurity: a red team deserves to know what it is attacking. All code is '
        . 'licensed <strong>AGPL-3.0</strong>. For the test framework, see the <a href="/redteam.php">rules of engagement</a>; '
        . 'for demonstrations, the <a href="/attacks.php">Attack Simulator</a>.',

    'sec.1.h2' => '1. Authentication — SelfRecover <span class="pill">no email</span>',
    'sec.1.body' => '<ul>'
        . '<li>No email, no phone number. On sign-up: you choose a recovery word → a 16-character <code>password</code> and an EFF diceware passphrase are generated server-side.</li>'
        . '<li>Storage: <code>Argon2id(password)</code>, <code>Argon2id(passphrase)</code>, <code>Argon2id(derived_key)</code> — m=64&nbsp;MB, t=4, p=2 (OWASP profile). <strong>No secret is ever stored in the clear.</strong></li>'
        . '<li>Per-domain derivation: <code>HMAC-SHA256(secret, domain ‖ site_salt)</code> → a secret phished on another domain does not yield the right key.</li>'
        . '<li>Progressive rate limiting (5 failures / 15 min) plus a per-IP sign-up cap (anti-enumeration, anti-spam).</li>'
        . '</ul>',

    'sec.2.h2' => '2. Data encryption — two models, by sensitivity',
    'sec.2.body' => '<p><strong>a) Server blind-key</strong> (profile: bio, location, link) — AES-256-GCM, key derived from a server secret held <em>outside the database and outside the webroot</em>. A SQL dump yields nothing but blobs.</p>'
        . '<p><strong>b) Client-side end-to-end</strong> (personal memo) — encrypted in the <strong>browser</strong> (WebCrypto). <code>PBKDF2</code> (600k) → <code>HKDF</code> per label → a random <code>vault_key</code> encrypts the memo, itself wrapped in two envelopes (password and recovery passphrase). <strong>The server holds no key.</strong></p>'
        . '<div class="mt">'
        . '<div class="ok"><h4>✅ What this protects</h4><ul>'
        . '<li>Blind-key: stolen disk, SQL dump, injection</li>'
        . '<li>E2E: <strong>even</strong> admin or root access on the server leaves the memo unreadable</li>'
        . '</ul></div>'
        . '<div class="no"><h4>⛔ What it does not (V1, acknowledged)</h4><ul>'
        . '<li>Blind-key: an admin, or an RCE that reads the key, can decrypt profiles and private messages</li>'
        . '<li>E2E: a <em>persistently</em> compromised server serving tampered JavaScript that captures the password at unlock — the "served code" problem</li>'
        . '</ul></div>'
        . '</div>',

    'sec.3.h2' => '3. The shared foundation — SelfRecover ⇄ SelfDataGuard',
    'sec.3.body' => '<ul>'
        . '<li>One memorised <strong>root secret</strong>, one <strong>shared derivation primitive</strong>, and <strong>child keys separated by label</strong> (<code>auth</code> / <code>data-enc</code> / <code>data-recover</code>).</li>'
        . '<li>Cardinal rule: <strong>never the same key for authentication and encryption</strong>. The server sees authentication; it must never be able to decrypt.</li>'
        . '<li>Unified recovery: the same recovery word or passphrase restores access <em>and</em> data. Recovery strength comes from the <strong>entropy of the input</strong> (diceware passphrase), not from hash length.</li>'
        . '</ul>',

    'sec.4.h2' => '4. Application hardening',
    'sec.4.body' => '<ul>'
        . '<li><strong>CSRF</strong>: session-bound HMAC token, verified on every write action (<code>X-CSRF-Token</code> header).</li>'
        . '<li><strong>Headers</strong>: <code>Content-Security-Policy</code>, <code>X-Frame-Options: DENY</code>, <code>X-Content-Type-Options: nosniff</code>, <code>Referrer-Policy</code>, <code>HSTS</code>.</li>'
        . '<li><strong>Sessions</strong>: 192-bit random token, <code>HttpOnly</code>/<code>SameSite=Lax</code> cookie, 24 h TTL with purge.</li>'
        . '<li><strong>Anti-enumeration</strong>: non-discriminating error messages, per-IP limits.</li>'
        . '</ul>',

    'sec.5.h2' => '5. Moderation — SelfModerate <span class="pill">anti-manipulation</span>',
    'sec.5.body' => '<ul>'
        . '<li>Per-member reputation (starting at 20/30), ±1 votes on posts <em>and</em> on members.</li>'
        . '<li><strong>Anti-Sybil</strong>: an account under 24 h old with no contribution cannot vote.</li>'
        . '<li><strong>Anti pack-voting</strong>: 3 or more coordinated downvotes within 60 s are cancelled and the reputation restored.</li>'
        . '<li><strong>Anti upvote-farming</strong>: repeated mutual votes are neutralised. Graduated sanctions — loss of voting rights, then 24 h → 7 d → 30 d → permanent ban.</li>'
        . '</ul>',

    'sec.6.h2' => '6. Threat model — stated honestly',
    'sec.6.body' => '<div class="mt">'
        . '<div class="ok"><h4>✅ Mitigated</h4><ul>'
        . '<li>Database exfiltration (dump, stolen disk) — blobs only</li>'
        . '<li>Password brute-forcing (rate limiting)</li>'
        . '<li>Moderation manipulation (Sybil, pack-voting)</li>'
        . '<li>CSRF, clickjacking, passive session theft</li>'
        . '<li>Memo theft, <strong>even with root access</strong> (E2E at rest)</li>'
        . '</ul></div>'
        . '<div class="no"><h4>⚠️ Known limits (V1)</h4><ul>'
        . '<li>Profiles and private messages readable by an admin or an RCE (blind-key)</li>'
        . '<li><em>Persistently</em> compromised server → tampering with the served code</li>'
        . '<li>Metadata is not encrypted (who talks to whom, and when)</li>'
        . '</ul></div>'
        . '</div>',

    'sec.7.h2' => '7. Roadmap (beyond V1)',
    'sec.7.body' => '<p class="roadmap">E2E extended to private messages and profiles · <code>Argon2id</code> replacing PBKDF2 · an <strong>external integrity supervisor</strong> (detecting tampered served code and abnormal behaviour, with reversible automatic containment) · distributed quorum (Shamir) for critical keys.</p>',

    // ── "Rules of engagement" page ────────────────────────────────────────
    'rt.hero.h1' => '🎯 Red team test — rules of engagement',
    'rt.hero.p'  => 'MySelf-Lab is a showcase <strong>deliberately exposed to attack</strong>. It inverts OWASP Juice Shop: '
        . 'here the application is <strong>protected by the MySelf ecosystem</strong> (SelfRecover, SelfDataGuard, SelfModerate), '
        . 'and the goal is to prove — or disprove — that protection under real conditions. '
        . 'Anyone following the rules below is <strong>authorised</strong> to carry out research here.',

    'rt.scope.h2'     => '📍 Scope',
    'rt.scope.in.h3'  => '✅ In scope',
    'rt.scope.in'     => '<li>The MySelf-Lab web application (this site) and all its paths</li>'
        . '<li><strong>SelfRecover</strong> authentication (sign-up, log-in, recovery)</li>'
        . '<li><strong>SelfDataGuard</strong> encrypted private messages and profiles</li>'
        . '<li><strong>SelfModerate</strong> reputation and voting</li>'
        . '<li>The report submission form below</li>',
    'rt.scope.out.h3' => '⛔ Out of scope',
    'rt.scope.out'    => '<li>Any infrastructure, domain or service other than this site</li>'
        . '<li>The server\'s <strong>SSH</strong> service (port 22) — authentication attempts there trigger a network-level block that would also cut off your access to this site</li>'
        . '<li>The hosting provider, the registrar, third-party suppliers</li>'
        . '<li>Accounts or data belonging to real people</li>'
        . '<li>The maintainer\'s machine, accounts and mailboxes</li>',
    'rt.scope.note'   => 'An extended scope (other MySelf components) may be agreed <strong>privately</strong> with a selected team, under written agreement. It is not published here.',

    'rt.obj.h2'    => '🏁 Objectives (capture the flag)',
    'rt.obj.intro' => 'A demonstration account holds, in its <strong>personal memo</strong>, a secret in the form:',
    'rt.obj.flag'  => '<span class="lbl">FLAG-</span>… (encrypted <strong>end-to-end, client-side</strong> — AES-256-GCM, key derived from the user\'s secret, <strong>never present on the server</strong>)',
    'rt.obj.note'  => 'The target account name will be given to you when the test opens. Neither a database dump, nor administrator access, nor full control of the server reveals this secret — the key exists only in its owner\'s browser. The challenge is to bring it back <strong>in the clear</strong>.',
    'rt.obj.refs'  => '<strong>MITRE ATT&amp;CK</strong> and <strong>OWASP</strong> references are given per objective (web application vulnerabilities map to OWASP/CWE, outside the ATT&amp;CK scope).',
    'rt.obj.list'  => '<li>🎯 <strong>Exfiltrate the secret memo</strong> from the target account and produce it in the clear <span class="ttp">core objective</span></li>'
        . '<li>🔓 <strong>Bypass SelfRecover authentication</strong> (take over an account without its password) <span class="ttp">ATT&amp;CK T1110 · T1078 · OWASP A07</span></li>'
        . '<li>💬 <strong>Read a private message</strong> exchanged between two other members, in the clear <span class="ttp">OWASP A01</span></li>'
        . '<li>⚖️ <strong>Manipulate SelfModerate reputation</strong> (bury a member with coordinated fake accounts, or promote yourself) <span class="ttp">CAPEC-210</span></li>'
        . '<li>🪪 <strong>Hijack a session</strong> or land an authenticated CSRF attack <span class="ttp">ATT&amp;CK T1539 · CWE-352</span></li>'
        . '<li>🧨 <strong>Escalate privileges</strong>: obtain administrator access (the <code>/admin</code> panel) <span class="ttp">ATT&amp;CK T1078 · OWASP A01</span></li>',

    'rt.allowed.h3' => '✅ Allowed',
    'rt.allowed'    => '<li>Web application testing: injection (SQL, command), XSS, IDOR, authentication bypass, business logic</li>'
        . '<li>Cryptanalysis of SelfDataGuard blobs</li>'
        . '<li>Attempts to dump the database through an application flaw</li>'
        . '<li>Reasoned fuzzing of endpoints</li>'
        . '<li>Interception and replay within scope</li>',
    'rt.forbidden.h3' => '⛔ Forbidden',
    'rt.forbidden'  => '<li>Denial of service, flooding, volumetric stress (DoS / DDoS)</li>'
        . '<li>Social engineering aimed at real people, the maintainer or the hosting provider</li>'
        . '<li>Physical attacks, or attacks on premises</li>'
        . '<li>Pivoting or scanning outside the declared scope</li>'
        . '<li>Destruction, encryption (ransomware) or permanent alteration of data</li>'
        . '<li>Mass exfiltration beyond proof; spam; illegal content</li>',

    'rt.rate.h2'   => '⏱️ Technical note — rate limiting',
    'rt.rate.body' => '<p>The authentication endpoints (<code>/api/login.php</code>, <code>/api/register.php</code>) are rate-limited to <strong>10 requests per second</strong>, with a burst allowance of 20.</p>'
        . '<p>A <strong>429</strong> response signals this rate limiting — or the account\'s anti-brute-force lockout. <strong>It is not a denial of service you caused</strong>, and there is no need to report it as one.</p>'
        . '<p>Reasoned fuzzing is unaffected: only massively parallel requests are. No ban is applied — on this server, scanning is part of the game.</p>',

    'rt.conduct.h2'   => '🧭 Responsible conduct',
    'rt.conduct.body' => '<li>On a <strong>critical</strong> finding: stop exploiting, secure <em>minimal</em> proof, report without delay.</li>'
        . '<li>Proof means a minimal extract — a screenshot, one decrypted record — not a full dump.</li>'
        . '<li><strong>Coordinated disclosure</strong>: no publication before a fix and mutual agreement. Reference window: <strong>90 days</strong>.</li>',

    'rt.safe.h2'   => '🛟 Safe harbour',
    'rt.safe.body' => 'As long as your research follows these rules, we consider it <strong>authorised and carried out in good faith</strong>. '
        . 'We will take no action against you, and we will do our best to clear up any uncertainty quickly. '
        . 'If in doubt about the scope or a technique: <strong>ask before you act</strong>, using the form below.',

    'rt.form.h2'      => '📨 Submit a report',
    'rt.form.note'    => '🔒 The body of your report is encrypted at rest by <strong>SelfDataGuard</strong> before storage: the database holding it reveals nothing but a blob. The module you are testing protects your report too.',
    'rt.form.handle'  => 'Public handle (hall of fame, optional)',
    'rt.form.handle_ph' => 'e.g. @name_or_team',
    'rt.form.severity' => 'Severity',
    'rt.form.sev.info' => 'Info',
    'rt.form.sev.low'  => 'Low',
    'rt.form.sev.med'  => 'Medium',
    'rt.form.sev.high' => 'High',
    'rt.form.sev.crit' => 'Critical',
    'rt.form.target'   => 'Target',
    'rt.form.tgt.memo' => 'Secret memo',
    'rt.form.tgt.auth' => 'SelfRecover auth',
    'rt.form.tgt.dm'   => 'Private messages',
    'rt.form.tgt.mod'  => 'Moderation',
    'rt.form.tgt.web'  => 'Web / app',
    'rt.form.tgt.other' => 'Other',
    'rt.form.title'    => 'Title *',
    'rt.form.title_ph' => 'One-line summary',
    'rt.form.desc'     => 'Description *',
    'rt.form.desc_ph'  => 'Impact, what you obtained…',
    'rt.form.repro'    => 'Steps to reproduce',
    'rt.form.repro_ph' => '1. … 2. … 3. …',
    'rt.form.contact'  => 'Contact (optional, encrypted)',
    'rt.form.contact_ph' => 'Mastodon, PGP key, throwaway email…',
    'rt.form.honeypot' => 'Do not fill in',
    'rt.form.send'     => 'Send (encrypted)',

    'rt.hof.h2'    => '🏆 Hall of fame',
    'rt.hof.empty' => 'No validated contribution yet. Be the first to appear here.',

    'rt.js.encrypting' => '🔐 Encrypting your report in your browser…',
    'rt.js.cryptoerr'  => 'Encryption failed — report NOT sent:',
    'rt.js.ok'     => 'Report received and encrypted. Thank you — we will get back to you.',
    'rt.js.err'    => 'Error',
    'rt.js.neterr' => 'Network error: ',

    'rt.prevalence' => '🌐 These rules exist in French and English. <strong>In case of discrepancy between versions, the French text prevails.</strong>',


    // ── Forum home ────────────────────────────────────────────────────────
    'idx.title'     => 'Forum',
    'idx.pitch'     => 'Demonstration forum: <strong>attack it, your data survives.</strong><br>Auth with no email · end-to-end encrypted messages · anti-manipulation moderation.',
    'idx.cta.test'  => '🛡️ Test the security',
    'idx.cta.archi' => 'See the architecture',
    'idx.credit'    => 'A Pierroons × Claude collaboration — open source security, put to the test.',
    'idx.h1'        => 'Digital sovereignty forum',
    'idx.newthread' => '+ New thread',
    'idx.subtitle'  => 'Discussions on free software, GDPR, self-hosting and encryption.',
    'idx.cat.all'   => 'All',
    'idx.empty'     => 'No thread%s yet.',
    'idx.empty.cat' => ' in this category',
    'idx.empty.cta' => ' <a href="/register.php">Create an account</a> to start the discussion.',
    'idx.by'        => 'by',
    'idx.posts'     => 'post',
    'idx.posts.p'   => 'posts',
    'idx.readonly'  => 'Reading is open. <a href="/register.php">Create an account without email</a> (SelfRecover) to take part.',

    // Forum categories (keys stay stable in the database, labels are translated)
    'cat.general'         => 'General',
    'cat.libre'           => 'Free software',
    'cat.rgpd'            => 'GDPR & privacy',
    'cat.autohebergement' => 'Self-hosting',
    'cat.crypto'          => 'Encryption',

    // ── Sign-up ───────────────────────────────────────────────────────────
    'reg.title'      => 'Create an account',
    'reg.h1'         => 'Create an account',
    'reg.intro'      => 'No email, no phone number. Authentication by <strong>SelfRecover</strong>: you choose a recovery word, the server generates a password and a passphrase for you to keep.',
    'reg.username'   => 'Username',
    'reg.username_ph'=> '3-20 characters, lowercase/digits/_',
    'reg.recovery'   => 'Recovery word (you choose it, keep it secret)',
    'reg.recovery_ph'=> 'e.g. mycat2024',
    'reg.submit'     => 'Create my account',
    'reg.done'       => 'Account created!',
    'reg.copy_now'   => 'Copy these secrets NOW — they are shown only once:',
    'reg.password'   => 'Password',
    'reg.passphrase' => 'Recovery passphrase (%s bits)',
    'reg.keep_safe'  => 'You already know your recovery word. Keep all three somewhere safe.',

    // ── Log in ────────────────────────────────────────────────────────────
    'log.title'      => 'Log in',
    'log.h1'         => 'Log in',
    'log.username'   => 'Username',
    'log.password'   => 'Password',
    'log.submit'     => 'Log in',
    'log.noaccount'  => 'No account? <a href="/register.php">Create one</a> (no email needed).',
    'log.forgot'     => 'Forgot your password? <a href="/recover.php">Recovery without email</a> (recovery word).',
    'log.error'      => 'Error',

    'reg.goto_login' => 'Go to log in →',

    // ── Public counters ───────────────────────────────────────────────────
    'stats.h2'         => '📊 The lab in numbers',
    'stats.days'       => 'days online',
    'stats.repelled'   => 'authentication attempts repelled',
    'stats.reports'    => 'reports received',
    'stats.flags'      => 'flag captured',
    'stats.flags.p'    => 'flags captured',
    'stats.note'       => 'Aggregated from data the lab already collects — no audience measurement, no tracking cookie, no address retained.',

    // ── "Moderation" page ─────────────────────────────────────────────────
    'mod.title'    => 'Moderation',
    'mod.h1'       => '🛡️ Moderation — SelfModerate',
    'mod.intro'    => 'Moderation <strong>without central authority</strong>: the community votes, automated defences counter manipulation.',
    'mod.how.h2'   => 'How it works',
    'mod.how.body' => '<li><strong>Reputation</strong>: every member starts at 20/30. ▲▼ votes on their posts and profile make it move.</li>'
        . '<li><strong>Anti-Sybil</strong>: an account under 24 h old with no post at all cannot vote.</li>'
        . '<li><strong>Anti upvote-farming</strong>: more than 3 repeated upvotes towards the same member over 60 days are neutralised.</li>'
        . '<li><strong>Anti pack-voting</strong>: 3 coordinated downvotes (&lt;60 s) on the same target are cancelled and the reputation restored.</li>'
        . '<li><strong>Graduated sanctions</strong>: reputation &lt;5 → loss of voting rights; ≤0 → 24 h suspension, then 7 d, 30 d, permanent.</li>',
    'mod.detect.h2'   => 'Run abuse detection',
    'mod.detect.note' => 'Analyses recent votes and cancels any pack-voting pattern found.',
    'mod.detect.btn'  => '🔍 Detect abuse now',
    'mod.detect.login' => '<a href="/login.php">Log in</a> to run abuse detection.',
    'mod.blocked.h2'  => 'Neutralised votes (%d)',
    'mod.blocked.none' => 'No blocked vote yet.',
    'mod.col.date'    => 'Date',
    'mod.col.voter'   => 'Voter',
    'mod.col.target'  => 'Target',
    'mod.col.type'    => 'Type',
    'mod.col.reason'  => 'Reason',
    'mod.js.cancelled' => 'vote(s) cancelled. Pack(s):',
    'mod.js.target'    => 'target',
    'mod.js.spread'    => 'spread',
    'mod.js.none'      => 'No pack-voting detected over the recent period.',

    // ── "Attack Simulator" page ───────────────────────────────────────────
    // ⚠️ Simulation results (titles, steps, verdicts) come from the API in
    // French; translating them belongs to a later batch.
    'atk.title'   => 'Attack Simulator',
    'atk.h1'      => '🎯 Attack Simulator',
    'atk.intro'   => 'These attacks run <strong>for real</strong> against an isolated throwaway database (in-memory SQLite), through the actual MySelf defence code. The <span style="color:var(--acc)">green</span> column proves the data stays <strong>fully usable for its legitimate owner</strong> — security does not break usage.',
    'atk.login'   => '<a href="/login.php">Log in</a> to run the attack simulations.',
    'atk.run'     => 'Run the attack',
    'atk.dump.h3'   => '💾 Database exfiltration',
    'atk.dump.obj'  => 'Steal private messages and personal data by dumping the database.',
    'atk.brute.h3'  => '🔓 Login brute-force',
    'atk.brute.obj' => 'Guess a password by brute force.',
    'atk.pack.h3'   => '👥 Sybil + pack-voting',
    'atk.pack.obj'  => 'Coordinated fake accounts to bury a member.',
    'atk.csrf.h3'   => '🕸️ CSRF + phishing',
    'atk.csrf.obj'  => 'Force a cross-site action or hijack the recovery flow.',
    'atk.js.running' => '⏳ Running the attack against an isolated database…',
    'atk.js.goal'    => '🎯 Attacker goal:',
    'atk.js.verdict' => '🛡️ Attack neutralised — data preserved',
    'atk.js.defense' => 'Defence:',
    'atk.js.error'   => 'Error:',

    // ── Discussion thread ─────────────────────────────────────────────────
    'thr.notfound.title' => 'Thread not found',
    'thr.notfound'  => 'This thread does not exist. <a href="/index.php">Back to the forum</a>',
    'thr.openedby'  => 'Opened by',
    'thr.rep.trust'  => 'trusted',
    'thr.rep.member' => 'member',
    'thr.rep.frail'  => 'fragile',
    'thr.rep.watch'  => 'under watch',
    'thr.rep.title'  => 'Reputation %d/30 — %s',
    'thr.vote.up'    => 'Helpful',
    'thr.vote.down'  => 'Downvote',
    'thr.vote.own'   => 'Your own post',
    'thr.reason.h2'      => 'Why this vote?',
    'thr.reason.motif'   => 'Reason',
    'thr.reason.texte'   => 'Explain',
    'thr.reason.aide'    => 'The person concerned will read this, without your name. Say what you fault the message for.',
    'thr.reason.compteur'=> 'characters',
    'thr.reason.envoyer' => 'Send vote',
    'thr.reason.annuler' => 'Cancel',
    'vote.reason.hors_sujet'         => 'Off topic',
    'vote.reason.agressif'           => 'Aggressive',
    'vote.reason.desinformation'     => 'Misinformation',
    'vote.reason.entraide'           => 'Helpful to others',
    'vote.reason.contribution_utile' => 'Useful contribution',
    'vote.reason.autre'              => 'Other',
    'thr.reply.h2'   => 'Reply',
    'thr.reply.ph'   => 'Your reply…',
    'thr.reply.btn'  => 'Post',
    'thr.reply.login' => '<a href="/login.php">Log in</a> to reply.',

    // ── Private messages ──────────────────────────────────────────────────
    'msg.title'    => 'Messages',
    'msg.h1'       => 'Private messages',
    'msg.note'     => '🔒 Content encrypted at rest by <strong>SelfDataGuard</strong> (AES-256-GCM). A database dump reveals nothing but unreadable blobs.',
    'msg.new.h2'   => 'New message',
    'msg.to'       => 'Recipient (username)',
    'msg.to_ph'    => 'e.g. libriste',
    'msg.body'     => 'Message',
    'msg.send'     => 'Send (encrypted)',
    'msg.inbox'    => '📥 Inbox',
    'msg.inbox.none' => 'No message received.',
    'msg.sent'     => '📤 Sent',
    'msg.sent.none' => 'No message sent.',
    'msg.to_prefix' => 'to',

    // ── SU console ────────────────────────────────────────────────────────
    // ⚠️ As with the Attack Simulator, API responses (capabilities, terminal
    // output) remain in French; later batch.
    'su.title'   => 'SU console',
    'su.h1'      => '🔑 SU console — separation of powers',
    'su.intro'   => 'The MySelf model has three levels: <b>👤 User → 🛡️ Admin → 🔑 SuperUser</b>. Pick a role to see <b>what it can and cannot do</b>. Everything runs on an <b>isolated throwaway database</b> — no real action, no real privilege.',
    'su.login'   => '<a href="/login.php">Log in</a> to explore the roles.',
    'su.user.h3'  => '👤 User',
    'su.user.obj' => 'An ordinary member. The baseline: no power over anyone else.',
    'su.user.btn' => 'View as User',
    'su.admin.h3'  => '🛡️ Admin',
    'su.admin.obj' => 'Moderates and <b>proposes</b> promotions — but does not decide.',
    'su.admin.btn' => 'View as Admin',
    'su.su.h3'   => '🔑 SuperUser',
    'su.su.obj'  => 'Decides on roles, everything is logged. But cannot read your E2E data.',
    'su.su.btn'  => 'View as SU 🔒',
    'su.js.running' => '⏳ Simulating on an isolated database…',
    'su.js.termhead' => '🔑 simulated SU terminal — sandbox, no real effect · type "help"',
    'su.js.banner1' => 'SelfRecover SuperUser — demonstration console (sandbox).',
    'su.js.banner2' => 'Type "help". No real action, no real power.',
    'su.js.prompt'  => '🔑 SuperUser password (PUBLIC demo: test-su)',
    'su.js.wrongpw' => 'Wrong password. Hint: it is "test-su" — public, this is a demo 🙂.',
    'su.js.inert'   => '(buttons inert — demonstration)',
    'su.js.confirm' => 'Confirm identity',
    'su.js.refuse'  => 'Refuse',
    'su.js.error'   => 'Error:',

    // ── Profile ───────────────────────────────────────────────────────────
    'prf.title'      => 'My space',
    'prf.rep'        => 'Reputation',
    'prf.suspended'  => 'suspended',
    'prf.novote'     => 'no voting rights',
    'prf.support'    => '▲ Support',
    'prf.report'     => '▼ Report',
    'prf.voted'      => 'You already voted (%s)',
    'prf.rep.trust'  => 'Trusted',
    'prf.rep.member' => 'Established member',
    'prf.rep.frail'  => 'Fragile reputation',
    'prf.rep.watch'  => 'Under watch',
    'prf.mod.h2'     => '⚖️ My moderation status',
    'prf.mod.right'  => 'Voting rights',
    'prf.mod.active' => '✓ active',
    'prf.mod.limited' => 'restricted',
    'prf.mod.removed' => 'removed',
    'prf.mod.strikes' => 'Strikes',
    'prf.mod.status'  => 'Status',
    'prf.mod.st.active' => 'active',
    'prf.convalescent'  => 'recovering',
    'prf.mod.conv.h'    => 'Recovering',
    'prf.mod.conv.txt'  => 'Your reputation dropped below the voting threshold. It climbs back one point per quiet day, up to %d. Nothing to prove, just time.',
    'prf.reasons.h2'    => '💬 Reasons you received',
    'prf.reasons.anon'  => 'Without their author\'s name, and dated to the day: that is what the protocol promises the person concerned.',
    'prf.reasons.empty' => 'No reason received yet.',
    'prf.act.h2'     => '📊 My activity',
    'prf.act.threads' => 'Threads opened',
    'prf.act.posts'  => 'Posts',
    'prf.act.given'  => 'Votes cast',
    'prf.act.got'    => 'Votes received',
    'prf.act.public' => 'View my public profile →',
    'prf.edit.h2'    => '✏️ Edit my profile',
    'prf.edit.tag'   => '🌐 public',
    'prf.public.warn' => '🌐 <strong>Public profile</strong> — bio, location and link are <strong>visible to everyone</strong> (page <code>/profile.php?u=…</code>, even without an account). SelfDataGuard at-rest encryption protects against a <strong>database theft</strong>, not against public display: put <strong>no sensitive data</strong> here. For a private note, use the <strong>E2E memo</strong> below.',
    'prf.memo.h2'    => '🎯 Personal memo — end-to-end encrypted',
    'prf.memo.note'  => '🔒 Encrypted <strong>in your browser</strong> by <strong>SelfDataGuard E2E</strong>: the server only ever receives blobs and holds <strong>no key</strong>. Even the administrator cannot read it. This is the <strong>secret to exfiltrate</strong> for the red team — a database dump yields nothing without your password.',
    'prf.memo.create' => '<strong>Once, and only once:</strong> you set here the two secrets that seal your vault. After this you will only write — the password alone reopens it, never the passphrase.<br>The <strong>password</strong> is for everyday use; the <strong>recovery passphrase</strong> is your only safety net if you forget it.',
    'prf.memo.create_once' => '⚙️ Setup step, not writing. Your memo is written on the next screen, in a free-form block.',
    'prf.memo.pass'  => 'Recovery passphrase',
    'prf.memo.pass_hint' => '— reuse the one from your sign-up',
    'prf.memo.pass_ph' => 'e.g. correct horse battery staple',
    'prf.memo.pass_note' => 'At least 4 words. This is your only safety net if you lose your password — it must be strong (ideally your SelfRecover passphrase).',
    'prf.memo.label' => 'Memo',
    'prf.memo.ph'    => 'e.g. FLAG-example-2026: my private note…',
    'prf.memo.btncreate' => 'Create the vault (local encryption)',
    'prf.memo.locked' => '🔒 Vault locked. Your password is enough — the recovery passphrase only matters if you have forgotten it.',
    'prf.memo.unlock' => 'Unlock',
    'prf.memo.forgot' => 'Forgot your password?',
    'prf.memo.recover' => 'Recover with the passphrase',
    'prf.memo.decrypted' => 'Your memo — write whatever you want',
    'prf.memo.save'  => 'Save (re-encrypted locally)',
    'prf.js.saved'   => 'Profile saved (encrypted).',
    'prf.js.required' => 'Password, passphrase and memo are required.',
    'prf.js.weakpass' => 'Recovery passphrase too weak: at least 4 words (reuse the one from your sign-up). This is what protects your memo if you lose your password.',
    'prf.js.created' => 'Vault created and encrypted locally. The server only received blobs.',
    'prf.js.deriving' => 'Deriving the key (PBKDF2)…',
    'prf.js.decrypted' => 'Decrypted locally. The key stays in this page and is never sent.',
    'prf.js.locked'  => 'Vault locked.',
    'prf.js.saved2'  => 'Memo re-encrypted and saved.',

    // ── Access recovery (SelfRecover) ─────────────────────────────────────
    'rec.title'   => 'Access recovery',
    'rec.h1'      => 'Access recovery',
    'rec.h1.sub'  => '— without email',
    'rec.intro'   => 'Two paths, depending on what you still have. No email, no SMS: the SelfRecover protocol depends on no outside channel.',
    'rec.l1.tab'  => 'I have my passphrase',


    'rec.username' => 'Username',
    'rec.passphrase' => 'Backup passphrase',
    'rec.passphrase_ph' => 'the four words received at sign-up',
    'rec.word'    => 'Recovery word',
    'rec.submit'  => 'Recover my access',
    'rec.back'    => '← Back to log in',
    'rec.l3.h3'   => 'You have neither one?',
    'rec.l3.body' => '<strong>Level 3</strong> — one path remains, a human one. Open a dispute: you describe what you know about your account, an administrator reviews it and decides. It is slow by design, and deliberately so: no automated process should be manipulable into handing over an account.',
    'rec.l3.btn'  => 'Open a dispute',
    'rec.js.required' => 'Username and secret required.',
    'rec.js.done' => 'Access recovered ✔',
    'rec.js.copy' => '<strong>Copy these credentials now</strong> — they will not be shown again:',
    'rec.js.newpw' => 'New password',
    'rec.js.newpp' => 'Passphrase',
    'rec.js.goto' => 'Go to log in',
    'rec.js.neterr' => 'Network error.',

    // ── Level 3: dispute (human escalation) ───────────────────────────────
    'dsp.title'   => 'Open a dispute',
    'dsp.h1'      => '⚖️ Dispute — recovery by human decision',
    'dsp.intro'   => '<strong>Level 3 of the SelfRecover protocol.</strong> You have lost your passphrase <em>and</em> your recovery word. One path remains: convincing a human.',
    'dsp.why.h3'  => 'Why it is slow, and why it will stay that way',
    'dsp.why'     => 'An automated last-resort mechanism would be exactly the flaw through which accounts get taken: you would only need to trigger it. Here, an administrator reads, cross-checks and decides. Expect several days. No automated reply will come — that is the principle, not slow service.',
    'dsp.username' => 'Claimed username',
    'dsp.recit'   => 'What you know about your account',
    'dsp.recit_ph' => 'Approximate sign-up date, threads you opened, people you wrote to, the content of a memo… Anything an impersonator could not know.',
    'dsp.recit_note' => 'The more precise and verifiable the facts, the more a decision can be reached. A vague account will be refused.',
    'dsp.contact' => 'Contact for the reply (optional)',
    'dsp.contact_ph' => 'Mastodon, PGP key, throwaway email…',
    'dsp.submit'  => 'Submit the dispute',
    'dsp.privacy' => '🔒 Your account is encrypted at rest before storage: it contains precisely what would help someone impersonate you.',
    'dsp.back'    => '← Back to recovery',
    'dsp.js.err'  => 'Error',
    'dsp.js.neterr' => 'Network error.',

    'rec.l2.tab'  => 'I have a recovery code',
    'rec.l2.note' => '<strong>Level 2</strong> — two factors: a <em>code</em> from your batch (possession) <strong>and</strong> the word you chose (knowledge). No username is asked for: the code finds your account on its own.',
    'rec.code'    => 'Recovery code',
    'rec.code_ph' => 'xxxxx-xxxxx',
    'rec.word2'   => 'Recovery word',
    'rec.l1.note' => '<strong>Level 1</strong> — the backup passphrase you received at sign-up, four generated words. A <em>strong</em> secret: its entropy is what protects you.',

    // --- Level 3: contextual questions, body of signals, human decision ---
    'dsp.h1'            => '🧑\u200d⚖️ Assisted recovery',
    'dsp.why.h3'        => 'Why no secret is asked of you',
    'dsp.init.submit'   => 'Open a case',
    'dsp.q.note'        => 'These questions involve <strong>no secret</strong>. Answer from memory as best you can: this is a body of facts, not an exam — an administrator will read them.',
    'dsp.claim.keep'    => 'Keep this device: your case passcode is stored on it. Case',
    'dsp.q.submit'      => 'Send to an administrator',
    'dsp.chat.note'     => 'Conversation with the administrator. Case',
    'dsp.chat.ph'       => 'Your message…',
    'dsp.chat.send'     => 'Send',
    'dsp.chat.refresh'  => 'Refresh',
    'dsp.reset.granted' => 'An administrator confirmed your identity. Setting your secrets is up to you: the server generates none.',
    'dsp.reset.pw'      => 'New password',
    'dsp.reset.word'    => 'New recovery word',
    'dsp.reset.submit'  => 'Take back my account',
    'dsp.js.required'   => 'This field is required.',
    'dsp.js.sent'       => 'Sent to an administrator.',
    'dsp.js.you'        => 'You',
    'dsp.js.admin'      => 'Administrator',
    'dsp.js.empty'      => 'No messages yet.',
    'dsp.js.reset_done' => 'Account recovered. You can log in with your new secrets.',

    'reg.weak_word' => 'The recovery word must be at least 4 characters long.',
    'reg.codes'  => 'Backup codes — 10, single use (L2 recovery with your memorized word)',

    // --- Trusted device (L2, possession factor, alternative to the code) ---
    'dev.enroll.btn'    => '📱 Enable recovery from this device',
    'dev.enroll.note'   => 'This device keeps a private key encrypted with your memorized word. It does not replace the word — it replaces the code.',
    'dev.enroll.doing'  => 'enrolling…',
    'dev.enroll.ok'     => 'device enrolled ✔',
    'dev.enroll.fail'   => '⚠ enrolment failed',
    'dev.rec.or'        => 'Or, if you have <strong>enrolled this device</strong> (possession factor):',
    'dev.rec.btn'       => '📱 Recover from this device',
    'dev.rec.none'      => 'No device enrolled here for this account.',
    'dev.rec.ok'        => 'Access recovered (this device) ✔',
    'dev.rec.crypto_ko' => 'Crypto failure (this device).',
];
