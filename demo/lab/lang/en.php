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
        . '<li>Storage: <code>Argon2id(password)</code>, <code>Argon2id(passphrase)</code>, <code>Argon2id(derived_key)</code> — m=%3$d&nbsp;MB, t=%4$d, p=%5$d (OWASP profile). <strong>No secret is ever stored in the clear.</strong></li>'
        . '<li>Derivation bound to the hostname the browser reads: <code>HMAC-SHA256(key = memorised word, message = hostname ‖ "|v2" ‖ account salt)</code> → a word phished on another address does not yield the right key.</li>'
        . '<li>Progressive rate limiting (%1$d failures / %2$d min) plus a per-IP sign-up cap (anti-enumeration, anti-spam).</li>'
        . '</ul>',

    'sec.2.h2' => '2. Data encryption — two models, by sensitivity',
    'sec.2.body' => '<p><strong>a) Server blind-key</strong> (profile: bio, location, link) — XChaCha20-Poly1305, key derived from a server secret held <em>outside the database and outside the webroot</em>. A SQL dump yields nothing but blobs.</p>'
        . '<p><strong>b) Client-side end-to-end</strong> (personal memo) — encrypted in the <strong>browser</strong> (WebCrypto). <code>Argon2id</code> (64 MiB, the SelfRecover profile) → <code>HKDF</code> per label → a random <code>vault_key</code> encrypts the memo, itself wrapped in two envelopes (password and recovery passphrase). <strong>The server holds no key.</strong></p>'
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

    'sec.3.h2' => '3. One key per use — SelfRecover, the memo, SelfDataGuard',
    'sec.3.body' => '<ul>'
        . '<li><strong>Each use derives its own key, through its own derivation.</strong> Access goes through SelfRecover: your browser computes an <code>HMAC-SHA256</code> fingerprint of your word, bound to the site name and salted per account; the server only keeps an Argon2id of it. The memo draws two child keys, separated by <code>HKDF</code> label: <code>data-enc</code> from your password, <code>data-recover</code> from your recovery passphrase.</li>'
        . '<li>Cardinal rule: <strong>never the same key for authentication and encryption</strong>. The server sees authentication; it must never be able to decrypt.</li>'
        . '<li>Recovery: if your memo\'s recovery passphrase is also your SelfRecover one, a single secret restores access <em>and</em> the memo — through two separate derivations, with no shared key. Recovery strength comes from the <strong>entropy of the input</strong> (diceware passphrase), not from hash length.</li>'
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
        . '<li>Per-member reputation (starting at %1$d/%2$d), ±1 votes on posts <em>and</em> on members, a reason required for a downvote.</li>'
        . '<li><strong>Anti-Sybil</strong>: an account under %3$s old with no contribution cannot vote.</li>'
        . '<li><strong>Pack</strong>: voters linked to each other (private messages both ways) hitting the same target → votes cancelled, reputation restored. <strong>Burst</strong> of unlinked votes → flagged to an arbiter, nothing cancelled.</li>'
        . '<li><strong>Anti-farming</strong>: repeated votes from one member to another are neutralised. Graduated sanctions — loss of voting rights, then a ban of %4$s; the last tier repeats, never permanent, and every ban is written to the moderation journal.</li>'
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
        . '<li>Profiles and private messages readable by whoever obtains the server key (<code>.blindkey</code>), for instance through an RCE — not through the admin panel</li>'
        . '<li><em>Persistently</em> compromised server → tampering with the served code</li>'
        . '<li>Metadata is not encrypted (who talks to whom, and when)</li>'
        . '</ul></div>'
        . '</div>',

    'sec.7.h2' => '7. Roadmap (beyond V1)',
    'sec.7.body' => '<p class="roadmap">E2E extended to private messages and profiles · an <strong>external integrity supervisor</strong> (detecting tampered served code and abnormal behaviour, with reversible automatic containment) · distributed quorum (Shamir) for critical keys.</p>',

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
    'rt.obj.intro' => '<strong>Season 2</strong> — two flags, in two demonstration accounts:',
    'rt.obj.flag'  => '<span class="lbl">FLAG-E2E-</span>… in the <strong>personal memo</strong> of <code>ctf_alpha</code> — encrypted <strong>in the browser</strong> (Argon2id → HKDF → AES-256-GCM), key <strong>never present on the server</strong>.<br><span class="lbl">FLAG-DM-</span>… in a <strong>private message</strong> from <code>ctf_beta</code> to <code>ctf_gamma</code> — encrypted at rest on the server by SelfDataGuard (XChaCha20-Poly1305), with a key held outside the database.',
    'rt.obj.note'  => '<strong>FLAG-E2E</strong>: neither a database dump nor administrator access reveals it — the key is only ever derived in its owner\'s browser. <strong>Full control of the server does defeat it</strong>: that server is what ships the derivation script, and whoever controls it can ship another one that harvests the key on the next unlock. "End-to-end" therefore holds as long as the server ships the published code. <strong>FLAG-DM</strong>: a dump alone is not enough, but the server key (<code>.blindkey</code>) brings it down — the accepted limit of server-side encryption. The challenge is to bring either one back <strong>in the clear</strong>.',
    'rt.obj.refs'  => '<strong>MITRE ATT&amp;CK</strong> and <strong>OWASP</strong> references are given per objective (web application vulnerabilities map to OWASP/CWE, outside the ATT&amp;CK scope).',
    'rt.obj.list'  => '<li>🎯 <strong>Exfiltrate FLAG-E2E</strong>, the memo of <code>ctf_alpha</code>, and produce it in the clear <span class="ttp">core objective</span></li>'
        . '<li>🔓 <strong>Bypass SelfRecover authentication</strong> (take over an account without its password) <span class="ttp">ATT&amp;CK T1110 · T1078 · OWASP A07</span></li>'
        . '<li>💬 <strong>Read FLAG-DM</strong>, the message from <code>ctf_beta</code> to <code>ctf_gamma</code>, in the clear <span class="ttp">OWASP A01</span></li>'
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

    'rt.limits.h2'    => '📐 Known and accepted limits',
    'rt.limits.intro' => '<p>What follows is already established, and published so you don\'t spend time on it: <strong>a documented limit is not a finding</strong>. Going past one is a finding: a working exploitation of any of these boundaries, not the argument that it exists.</p>',
    'rt.limits.body'  => '<li><strong>The memo vault has two locks.</strong> Its key is drawn at random, then sealed twice: under the account password, and under a passphrase specific to the vault. Anyone who obtains the blobs attacks the cheaper of the two <strong>offline</strong>, with no attempt counter; the cost per attempt is a single Argon2id derivation at <code>t=3, m=64 MiB, p=1</code>. "End-to-end" says the server never sees the key — not that the memo withstands a weak password, nor that the server always ships the same code: it is the server that delivers the derivation script, and whoever controls it can deliver another.</li>'
                       . '<li><strong>A third party shuts an account\'s login in five requests.</strong> '
                       . 'The failed-login counter is fed by the SUBMITTED name, which nobody needs to own: five '
                       . 'failures lock the login for fifteen minutes — the correct password included — and a steady '
                       . 'burst keeps it shut. That counter does not reach recovery: its throttle counts '
                       . 'elsewhere, and the correct passphrase opens a level-1 '
                       . 'recovery while the login is locked. The recovery throttles — levels 1, 2 and 3 — and the '
                       . 'device-enrolment one count under a label derived from a server secret, which a third party '
                       . 'cannot forge: the login counter is the only one left under the name in the clear. '
                       . 'The per-ORIGIN ceiling now counts recovery doors only: an address saturated with failed '
                       . 'logins keeps its recovery open. ⚠️ Saturating an address with failed RECOVERY attempts still '
                       . 'throttles recovery coming from it — denial of service per origin, out of scope, and you must '
                       . 'share your target\'s address to reach them.</li>'
                       . '<li><strong>A third party can occupy an account\'s only level-3 case slot.</strong> '
                       . 'Opening a case requires nothing but an account name, and an account has only one active '
                       . 'case at a time: whoever opens first holds the slot, and the account holder cannot clear it '
                       . 'themselves. Their way out goes through the arbiter, who has an abandon action — it closes a '
                       . 'case without counting anything. A refusal no longer freezes anything by itself: it is '
                       . 'counted and reported to the arbiter, and only an arbiter places a freeze, which they lift. '
                       . 'This is denial of service, hence out of scope — published here because all it takes is a '
                       . 'name.</li>'
        . '<li><strong>The two passphrases do not share a floor.</strong> The <em>account</em> recovery passphrase is generated with 6 words — <strong>77.55 bits</strong> — and every typed word is validated against the 7776-word list; typing is nonetheless accepted from 4 words, that is <strong>51.70 bits</strong>. The <em>memo vault</em> passphrase goes through at 4 words and 16 characters, <strong>with no list validation and through a browser-side check</strong>: four made-up words will seal it.</li>'
        . '<li><strong>The browser holds the device\'s second factor, not the server.</strong> An enrolled device\'s private key lives in browser storage, encrypted under a key derived from the memorised word — a word whose floor, <strong>four characters</strong>, is itself checked by the browser. The server only ever verifies a signature: whoever obtains that blob attacks the word <strong>offline</strong>, with no attempt counter. The device really is a second factor; it simply is not guarded where you would expect.</li>',

    'rt.conduct.h2'   => '🧭 Responsible conduct',
    'rt.conduct.body' => '<li>On a <strong>critical</strong> finding: stop exploiting, secure <em>minimal</em> proof, report without delay.</li>'
        . '<li>Proof means a minimal extract — a screenshot, one decrypted record — not a full dump.</li>'
        . '<li><strong>Coordinated disclosure</strong>: no publication before a fix and mutual agreement. Reference window: <strong>90 days</strong>.</li>',

    'rt.safe.h2'   => '🛟 Safe harbour',
    'rt.safe.body' => 'As long as your research follows these rules, we consider it <strong>authorised and carried out in good faith</strong>. '
        . 'We will take no action against you, and we will do our best to clear up any uncertainty quickly. '
        . 'If in doubt about the scope or a technique: <strong>ask before you act</strong>, using the form below.',

    'rt.form.h2'      => '📨 Submit a report',
    'rt.form.note'    => '🔒 The body of your report is encrypted <strong>in your browser</strong>, with PGP, to the programme\'s public key, before it is sent: the server only stores a message it cannot read.',
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

    'rt.flag.h2'           => '🚩 Flags — instant check',
    'rt.flag.intro'        => 'Got a flag? Paste it here for an immediate answer. The handle is free and optional — what counts is who gets there first.',
    'rt.flag.intact'       => 'nobody has pulled this one yet',
    'rt.flag.anonyme'      => 'someone anonymous',
    'rt.flag.captures'     => '%d capture(s)',
    'rt.flag.pseudo'       => 'Handle (optional)',
    'rt.flag.btn'          => 'Check',
    'rt.suivi.h2'          => '📬 Track a report',
    'rt.suivi.intro'       => 'The number and token returned when you filed your report give you its status. The report itself stays unreadable to the server: it is encrypted to a single key.',
    'rt.suivi.num'         => 'No.',
    'rt.suivi.jeton'       => 'Tracking token',
    'rt.suivi.btn'         => 'Check',
    'rt.js.statut'         => 'Status:',
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
    'idx.pitch'     => 'Demonstration forum: <strong>attack it, your data survives.</strong><br>Auth with no email · end-to-end encrypted memo, messages encrypted at rest · anti-manipulation moderation.',
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
    'reg.username_ph'=> '%d-%d characters, lowercase/digits/_',
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
    'mod.how.body' => '<li><strong>Reputation</strong>: every member starts at %1$d/%2$d. ▲▼ votes on their posts and profile make it move; a ▼ vote requires a reason.</li>'
        . '<li><strong>Anti-Sybil</strong>: an account under %3$s old with no post at all cannot vote.</li>'
        . '<li><strong>Anti-farming</strong>: beyond %4$d votes from one member to another over %5$d days, the next ones are neutralised.</li>'
        . '<li><strong>Pack</strong>: voters linked to each other (private messages both ways) hitting the same target have their votes cancelled, and the target\'s reputation is restored. <strong>Burst</strong>: several unlinked votes in the same window are not cancelled — the target is flagged to an arbiter.</li>'
        . '<li><strong>Graduated sanctions</strong>: reputation &lt;%6$d → loss of voting rights; at 0 → a ban of %7$s, the last tier repeats, never permanent. A ban blocks voting and posting; private messages stay open. When the ban ends, reputation goes back to %1$d.</li>',
    'mod.detect.h2'   => 'Run abuse detection',
    'mod.detect.note' => 'Analyses recent votes: cancels the votes of recognised packs, flags bursts.',
    'mod.detect.btn'  => '🔍 Detect abuse now',
    'mod.detect.login' => 'Detection runs on every downvote; triggering it by hand is reserved to administrators.',
    'mod.blocked.h2'  => 'Neutralised votes (%d)',
    'mod.blocked.none' => 'No blocked vote yet.',
    'mod.blocked.anonyme' => 'Reasons are public, their authors are not: who voted against whom is for moderators only.',
    'mod.col.date'    => 'Date',
    'mod.col.voter'   => 'Voter',
    'mod.col.target'  => 'Target',
    'mod.col.type'    => 'Type',
    'mod.col.reason'  => 'Reason',
    'mod.js.cancelled' => 'vote(s) cancelled. Pack(s):',
    'mod.js.target'    => 'target',
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
    'msg.note'     => '🔒 Content encrypted at rest by <strong>SelfDataGuard</strong> (XChaCha20-Poly1305). A database dump reveals nothing but unreadable blobs.',
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
    'prf.pass.h2'       => 'Your backup passphrase',
    'prf.pass.age'      => 'Issued %d days ago',
    'prf.pass.inconnue' => 'This account predates the tracking: we do not know when your passphrase was issued.',
    'prf.pass.ancienne' => 'It is over two years old. Nothing forces you to change it — but if you are no longer sure where you put it, now is the time to check, while you still have access to your account.',
    'prf.pass.jamais'   => 'It never expires. A backup passphrase is for when everything else is lost, sometimes years later: expiring it would kill it at the exact moment it is needed. What protects it is that it works only once — the moment it is used, you get a new one and the old one is dead.',
    'prf.title'      => 'My space',
    'prf.rep'        => 'Reputation',
    'prf.banned.until' => 'banned until %s',
    'prf.mod.ban.h'    => 'Ban in progress',
    'prf.mod.ban.txt'  => 'Until %s, you can neither vote nor post. Your private messages stay open. Reason: %s',
    'prf.mod.ban.auto' => 'your reputation fell to zero',
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
    'prf.mod.meute.h'   => 'Took part in a pile-on',
    'prf.mod.meute.warn' => 'Some of the votes you cast were cancelled: they formed a group with those of someone you exchange private messages with. Nothing is taken from you this time. Another episode would suspend your right to vote for a week.',
    'prf.mod.meute.mute' => 'Your right to vote is suspended until %s. The rest of the forum stays open: you can write, reply and start a topic.',
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
    'prf.memo.note'  => '🔒 Encrypted <strong>in your browser</strong> (Argon2id then AES-GCM): the server only ever receives blobs and holds <strong>no key</strong>. Even the administrator cannot read it. This is the <strong>secret to exfiltrate</strong> for the red team — a database dump yields nothing without your password.',
    'prf.memo.create' => '<strong>Once, and only once:</strong> you set here the two secrets that seal your vault. After this you will only write — the password alone reopens it, never the passphrase.<br>The <strong>password</strong> is for everyday use; the <strong>recovery passphrase</strong> is your only safety net if you forget it.',
    'prf.memo.create_once' => '⚙️ Setup step, not writing. Your memo is written on the next screen, in a free-form block.',
    'prf.memo.pass'  => 'Recovery passphrase',
    'prf.memo.pw'    => 'Vault password',
    'prf.memo.pw_hint' => '— not your account password',
    'prf.memo.pass_hint' => '— not the one from your account',
    'prf.memo.pass_ph' => 'e.g. correct horse battery staple',
    'prf.memo.pass_note' => 'At least 4 words; 6 or more for a secret that matters. This is your only safety net if you lose your password. Use two secrets that belong to this vault only: the server receives your account ones — the password at every sign-in, the passphrase whenever you recover your account.',
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
    'prf.js.required' => 'Password and passphrase are required.',
    'prf.js.weakpass' => 'Recovery passphrase too weak: at least 4 words (reuse the one from your sign-up). This is what protects your memo if you lose your password.',
    'prf.js.created' => 'Vault created and encrypted locally. The server only received blobs.',
    'prf.js.deriving' => 'Deriving the key (Argon2id, one to a few seconds)…',
    'prf.js.sealing' => 'Sealing the vault: two Argon2id derivations, a few seconds…',
    'prf.js.oldvault' => 'This vault was sealed before the move to Argon2id and can no longer be read. Create it again.',
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
    'rec.passphrase_ph' => 'the words received at sign-up',
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
    'rec.l1.note' => '<strong>Level 1</strong> — the backup passphrase you received at sign-up, six generated words. A <em>strong</em> secret: its entropy is what protects you.',

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
    'dsp.done.h3'       => 'Account recovered — write these down now',
    'dsp.done.copy'     => 'They will not be shown again. Your password is the one you just chose; '
                         . 'the passphrase and codes below are new, and they replace the previous '
                         . 'ones, which are now worthless.',
    'dsp.done.pp'       => 'Recovery passphrase (level 1)',
    'dsp.done.codes'    => 'Recovery codes, single use each (level 2)',
    'dsp.done.login'    => 'Go to login',

    'reg.weak_word' => 'The recovery word must be at least %d characters long.',
    'reg.codes'  => 'Backup codes — %d, single use (L2 recovery with your memorized word)',

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
