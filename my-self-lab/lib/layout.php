<?php
/**
 * MySelf-Lab — layout HTML commun (header + footer + CSS thème souveraineté).
 */

declare(strict_types=1);

use Pierroons\MySelfLab\Auth;
use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Security;

function render_header(string $title, ?array $account = null): void
{
    $account ??= Auth::currentAccount(Db::pdo());
    // Token CSRF injecté pour les fetch POST authentifiés
    $sessTok = $_COOKIE[Auth::cookieName()] ?? '';
    $csrf = ($account && preg_match('/^[a-f0-9]{48}$/', $sessTok)) ? Security::csrfToken($sessTok) : '';
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — MySelf-Lab</title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/svg+xml" href="/img/favicon.svg">
<style>
:root{
  --bg:#0c1014; --card:#141b22; --elev:#1b242d; --border:#243039;
  --txt:#e6edf3; --txt2:#9aa9b6; --muted:#6b7d8c;
  --acc:#3fb98c; --acc2:#2d8f6a; --warn:#d4a056; --danger:#d96459; --link:#6cb6ff;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);font-family:-apple-system,'Segoe UI',Roboto,sans-serif;font-size:14.5px;line-height:1.55}
a{color:var(--link);text-decoration:none}
a:hover{text-decoration:underline}
.topbar{background:var(--elev);border-bottom:1px solid var(--border);padding:12px 20px;display:flex;align-items:center;gap:16px}
.topbar .brand{font-weight:700;font-size:16px;color:var(--txt)}
.topbar .brand .acc{color:var(--acc)}
.topbar .tag{font-size:11px;color:var(--muted);border:1px solid var(--border);padding:1px 7px;border-radius:10px}
.topbar nav{margin-left:auto;display:flex;align-items:center;gap:14px;font-size:13px}
.topbar nav .user{color:var(--acc)}
.banner{background:linear-gradient(90deg,rgba(63,185,140,0.12),transparent);border-bottom:1px solid var(--border);padding:8px 20px;font-size:12px;color:var(--txt2)}
.wrap{max-width:980px;margin:0 auto;padding:22px 20px}
.btn{display:inline-block;padding:8px 15px;background:var(--acc2);color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13.5px;font-weight:600;font-family:inherit;text-decoration:none}
.btn:hover{background:var(--acc);text-decoration:none}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--txt2)}
.btn-ghost:hover{border-color:var(--acc);color:var(--acc);background:transparent}
.card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:16px 18px;margin-bottom:12px}
h1{font-size:22px;margin:0 0 4px}
h2{font-size:16px;margin:0 0 10px}
.muted{color:var(--muted);font-size:12.5px}
.cat-pill{display:inline-block;font-size:11px;padding:1px 8px;border-radius:10px;background:var(--elev);color:var(--txt2);border:1px solid var(--border)}
.field{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}
.field label{font-size:12px;color:var(--txt2);text-transform:uppercase;letter-spacing:.3px;font-weight:600}
.field input,.field select,.field textarea{background:#0a0f13;border:1px solid var(--border);color:var(--txt);border-radius:6px;padding:9px 11px;font-family:inherit;font-size:14px}
.field input:focus,.field textarea:focus,.field select:focus{outline:none;border-color:var(--acc)}
.toast{padding:10px 14px;border-radius:7px;margin-bottom:14px;font-size:13.5px}
.toast.ok{background:rgba(63,185,140,.12);border:1px solid var(--acc2);color:var(--acc)}
.toast.err{background:rgba(217,100,89,.12);border:1px solid var(--danger);color:var(--danger)}
.thread-row{display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--border)}
.thread-row:last-child{border-bottom:none}
.thread-row .meta{margin-left:auto;text-align:right;color:var(--muted);font-size:12px;white-space:nowrap}
.post{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin-bottom:10px}
.post .head{display:flex;gap:10px;align-items:center;margin-bottom:8px;font-size:12.5px;color:var(--txt2)}
.post .head .auteur{color:var(--acc);font-weight:600}
.post .contenu{white-space:pre-wrap;color:var(--txt)}
.cats{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.cats a{font-size:12.5px;padding:4px 11px;border-radius:14px;border:1px solid var(--border);color:var(--txt2)}
.cats a.active{background:var(--acc2);color:#fff;border-color:var(--acc2)}
.rep-badge{font-size:10.5px;border:1px solid;padding:0 6px;border-radius:9px;margin-left:6px;font-weight:600}
footer{border-top:1px solid var(--border);margin-top:30px;padding:18px 20px;text-align:center;color:var(--muted);font-size:11.5px}
.hero{text-align:center;padding:32px 20px 26px;border:1px solid var(--border);border-radius:14px;background:radial-gradient(120% 120% at 50% -10%,rgba(63,185,140,.12),transparent 65%);margin-bottom:24px}
.hero svg{color:var(--txt)}
.hero .pitch{max-width:560px;margin:16px auto 18px;color:var(--txt2);font-size:14px;line-height:1.6}
.hero .pitch strong{color:var(--acc)}
.hero .cta{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
</style>
</head>
<body>
<div class="topbar">
  <a class="brand" href="/index.php" style="display:inline-flex;align-items:center;gap:7px">
    <svg viewBox="20 2 178 170" width="20" height="20" aria-hidden="true" style="color:var(--acc);flex:none">
      <g fill="none" stroke="currentColor" stroke-width="18" stroke-linecap="square" stroke-linejoin="miter"><path d="M 36 158 L 36 70 L 100 138 L 158 50 L 158 158"/><path d="M 158 50 L 174 28"/></g>
      <circle cx="180" cy="20" r="11" fill="currentColor"/>
    </svg>
    MySelf<span class="acc">·Lab</span>
  </a>
  <span class="tag">démo · noindex</span>
  <nav>
    <a href="/index.php">Forum</a>
    <a href="/moderation.php">Modération</a>
    <a href="/attacks.php">🎯 Attaques</a>
    <a href="/security.php">🔐 Sécurité</a>
    <a href="/redteam.php">🛡️ Red Team</a>
    <?php if ($account): ?>
      <a href="/messages.php">Messages</a>
      <a href="/profile.php">Mon espace</a>
      <?php if (!empty($account['is_admin'])): ?><a href="/admin.php" style="color:var(--warn)">🛠️ Admin</a><?php endif; ?>
      <span class="user">@<?= h($account['username']) ?></span>
      <a href="#" id="lab-logout" class="btn-ghost" style="padding:4px 10px">Déconnexion</a>
    <?php else: ?>
      <a href="/login.php">Connexion</a>
      <a href="/register.php" class="btn" style="padding:5px 12px">Créer un compte</a>
    <?php endif; ?>
  </nav>
</div>
<div class="banner">
  🔬 Forum de démonstration MySelf — auth par <strong>SelfRecover</strong> (sans email), messages privés chiffrés par <strong>SelfDataGuard</strong> (résistants à l'exfiltration de la base).
</div>
<script nonce="<?= nonce() ?>">window.LAB_CSRF = <?= json_encode($csrf) ?>;</script>
<div class="wrap">
<?php
}

function render_footer(): void
{
    ?>
</div>
<footer>
  MySelf-Lab · vitrine écosystème <a href="https://my-self.fr">MySelf</a> · co-construit par Pierroons &amp; Claude (Anthropic) · AGPL-3.0 · démonstration
</footer>
<script nonce="<?= nonce() ?>">
// window.LAB_CSRF est défini dans le header (scope où le token est calculé).
function labPost(url, data){
  return fetch(url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.LAB_CSRF||''},body:JSON.stringify(data||{})}).then(r=>r.json());
}
function lab_logout(){
  fetch('/api/logout.php',{method:'POST',headers:{'X-CSRF-Token':window.LAB_CSRF||''}}).then(()=>location.href='/index.php');
}
// Confort global : cliquer un champ en lecture seule sélectionne son contenu (remplace les onclick="this.select()").
document.addEventListener('click', function(e){ if (e.target && e.target.matches && e.target.matches('input[readonly]')) e.target.select(); });
// Déconnexion (remplace l'ancien onclick inline du header).
var _logoutLink = document.getElementById('lab-logout');
if (_logoutLink) _logoutLink.addEventListener('click', function(e){ e.preventDefault(); lab_logout(); });
</script>
</body>
</html>
<?php
}
