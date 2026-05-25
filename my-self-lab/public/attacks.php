<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;

$pdo = Db::pdo();
$account = Auth::currentAccount($pdo);

render_header('Attack Simulator', $account);
?>
<style>
.atk-intro{background:rgba(217,100,89,.08);border:1px solid var(--border);border-left:3px solid var(--danger);border-radius:8px;padding:12px 16px;margin-bottom:18px;font-size:13px;color:var(--txt2)}
.atk-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;margin-bottom:20px}
.atk-card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:16px}
.atk-card h3{margin:0 0 6px;font-size:15px}
.atk-card .obj{font-size:12.5px;color:var(--txt2);margin:0 0 12px;line-height:1.5}
.atk-card .btn{width:100%}
.result{margin-top:18px}
.result.hidden{display:none}
.narration{background:#0a0f13;border:1px solid var(--border);border-radius:8px;padding:16px 18px;font-family:ui-monospace,monospace;font-size:13.5px;line-height:1.65;margin-bottom:14px}
.narration .step{padding:5px 0;color:#c5d1db}
.narration .step b{color:var(--txt)}
.narration .step .res{color:var(--acc)}
.panels{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:760px){.panels{grid-template-columns:1fr}}
.panel{border-radius:8px;padding:14px 16px;font-size:13.5px}
.panel.rouge{background:rgba(217,100,89,.10);border:1px solid rgba(217,100,89,.35)}
.panel.verte{background:rgba(63,185,140,.10);border:1px solid rgba(63,185,140,.35)}
.panel .ptitle{font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px}
.panel.rouge .ptitle{color:var(--danger)}
.panel.verte .ptitle{color:var(--acc)}
.panel ul{margin:0;padding-left:16px;line-height:1.7}
.panel li{word-break:break-word}
.verdict{margin-top:14px;background:rgba(63,185,140,.12);border:1px solid var(--acc2);border-radius:8px;padding:12px 16px}
.verdict .v{font-weight:700;color:var(--acc);font-size:14px}
.verdict .d{font-size:12.5px;color:var(--txt2);margin-top:4px}
.verdict .k{font-size:13px;color:var(--txt);margin-top:8px;font-style:italic}
.spinner{color:var(--txt2);font-size:13px}
</style>

<h1>🎯 Attack Simulator</h1>
<div class="atk-intro">
  Ces attaques s'exécutent <strong>réellement</strong> sur une base jetable isolée (SQLite en mémoire), via le vrai code de défense MySelf.
  La colonne <span style="color:var(--acc)">verte</span> prouve que la donnée reste <strong>pleinement utilisable pour le légitime</strong> — la sécurité ne casse pas l'usage.
</div>

<?php if (!$account): ?>
  <div class="card"><p class="muted"><a href="/login.php">Connecte-toi</a> pour lancer les simulations d'attaque.</p></div>
<?php else: ?>

<div class="atk-grid">
  <div class="atk-card">
    <h3>💾 Exfiltration de la base</h3>
    <p class="obj">Voler DM et données perso en dumpant la base.</p>
    <button class="btn js-lancer" data-scn="dump">Lancer l'attaque</button>
  </div>
  <div class="atk-card">
    <h3>🔓 Bruteforce login</h3>
    <p class="obj">Deviner un mot de passe par force brute.</p>
    <button class="btn js-lancer" data-scn="bruteforce">Lancer l'attaque</button>
  </div>
  <div class="atk-card">
    <h3>👥 Sybil + pack-voting</h3>
    <p class="obj">Faux comptes coordonnés pour enterrer un membre.</p>
    <button class="btn js-lancer" data-scn="packvoting">Lancer l'attaque</button>
  </div>
  <div class="atk-card">
    <h3>🕸️ CSRF + phishing</h3>
    <p class="obj">Forcer une action cross-site ou détourner la récupération.</p>
    <button class="btn js-lancer" data-scn="csrf">Lancer l'attaque</button>
  </div>
</div>

<div class="result hidden" id="result"></div>

<script nonce="<?= nonce() ?>">
function esc(s){return String(s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));}
function lancer(scenario, btn){
  const box=document.getElementById('result');
  box.classList.remove('hidden');
  box.innerHTML='<div class="spinner">⏳ Exécution de l\'attaque sur une base isolée…</div>';
  box.scrollIntoView({behavior:'smooth',block:'start'});
  labPost('/api/attack.php',{scenario}).then(d=>{
    if(!d.ok){box.innerHTML='<div class="panel rouge">'+esc(d.message||'Erreur')+'</div>';return;}
    let h='<h2 style="margin-top:0">'+esc(d.titre)+'</h2>';
    h+='<p class="muted">🎯 Objectif attaquant : '+esc(d.objectif)+'</p>';
    h+='<div class="narration">';
    d.etapes.forEach((e,i)=>{h+='<div class="step"><b>'+(i+1)+'. '+esc(e.action)+'</b> → <span class="res">'+esc(e.resultat)+'</span></div>';});
    h+='</div>';
    h+='<div class="panels">';
    h+='<div class="panel rouge"><div class="ptitle">🔴 '+esc(d.cote_attaquant.label)+'</div><ul>';
    d.cote_attaquant.lignes.forEach(l=>{h+='<li>'+esc(l)+'</li>';});
    h+='</ul></div>';
    h+='<div class="panel verte"><div class="ptitle">🟢 '+esc(d.cote_legitime.label)+'</div><ul>';
    d.cote_legitime.lignes.forEach(l=>{h+='<li>'+esc(l)+'</li>';});
    h+='</ul></div></div>';
    h+='<div class="verdict"><div class="v">🛡️ Attaque neutralisée — donnée conservée</div>';
    h+='<div class="d">Défense : '+esc(d.defense)+'</div>';
    h+='<div class="k">💡 '+esc(d.message_cle)+'</div></div>';
    box.innerHTML=h;
    box.scrollIntoView({behavior:'smooth',block:'start'});
  }).catch(e=>{box.innerHTML='<div class="panel rouge">Erreur : '+esc(e.message)+'</div>';});
}
document.querySelectorAll('.js-lancer').forEach(b=>b.addEventListener('click',()=>lancer(b.dataset.scn,b)));
</script>
<?php endif; ?>
<?php render_footer(); ?>
