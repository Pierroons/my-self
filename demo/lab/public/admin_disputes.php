<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;
use Pierroons\MySelfLab\Security;

$pdo = Db::pdo();
$account = Auth::currentAccount($pdo);

// Garde : réservé aux admins. Pas d'indice d'existence pour les non-admins.
if (!$account || empty($account['is_admin'])) {
    render_header('Espace', $account);
    echo '<div class="card"><h1>404</h1><p class="muted">Page introuvable.</p></div>';
    render_footer();
    exit;
}
$csrf = Security::csrfToken(session_token() ?? '');
render_header('Litiges L3', $account);
?>
<h1>Récupérations assistées <span class="muted" style="font-size:13px">— litiges L3</span></h1>
<p class="muted" style="max-width:640px">Le faisceau ci-dessous = <strong>des faits bruts</strong>, jamais un score. Ils t'aident à décider ; ils n'ouvrent rien tout seuls. <strong>Accorder</strong> = tu confirmes l'identité, le demandeur re-choisira lui-même son secret (aucun mot de passe transmis). <strong>Refuser</strong> = compte clôturé.</p>
<div id="list"><p class="muted">chargement…</p></div>

<script nonce="<?= nonce() ?>">
var CSRF='<?= h($csrf) ?>';
function esc(s){return String(s==null?'':s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));}
function $(id){return document.getElementById(id);}
function get(u){return fetch(u,{headers:{'X-CSRF-Token':CSRF}}).then(r=>r.json());}
function post(u,p){return fetch(u,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify(p)}).then(r=>r.json());}

function facts(sig){
  if(!sig||!sig.passive) return '';
  var rows=(sig.passive||[]).map(s=>'<tr><td>passif</td><td>'+esc(s.label)+'</td><td>'+(s.ok?'✅':'—')+'</td><td class="muted">'+esc(s.detail||'')+'</td></tr>').join('');
  rows+=(sig.declarative||[]).map(s=>'<tr><td>déclaré</td><td>'+esc(s.label)+'</td><td>'+(s.ok?'✅':'❌')+'</td><td class="muted">dit «'+esc(s.dit)+'» / réel «'+esc(s.reel)+'»</td></tr>').join('');
  return '<div class="muted" style="font-size:12px;margin:4px 0">'+esc(sig.summary||'')+'</div><table style="width:100%;font-size:12px;border-collapse:collapse">'+rows+'</table>';
}

function render(disputes){
  if(!disputes.length){$('list').innerHTML='<p class="muted">Aucun litige en cours.</p>';return;}
  $('list').innerHTML=disputes.map(function(d){
    var flags=[];
    if(d.init_collisions>0) flags.push('⚠️ '+d.init_collisions+' demandeur(s) concurrent(s)');
    if(d.l2_prior_attempts>0) flags.push(d.l2_prior_attempts+' échec(s) L2 même source');
    return '<div class="card" data-num="'+esc(d.dispute_number)+'" style="margin-bottom:14px">'
      +'<div class="row" style="justify-content:space-between"><div><strong>'+esc(d.username)+'</strong> <span class="muted">— '+esc(d.dispute_number)+' · '+esc(d.status)+'</span></div></div>'
      +(flags.length?'<div style="color:#d4a056;font-size:12px">'+flags.join(' · ')+'</div>':'')
      +facts(d.signals)
      +'<div class="l3-msgs" style="max-height:180px;overflow:auto;border:1px solid #2a2a2a;border-radius:6px;padding:8px;margin:8px 0;font-size:13px"></div>'
      +'<div class="row" style="gap:6px"><input class="l3-in" placeholder="répondre au demandeur…" style="flex:1"><button class="btn l3-send">Envoyer</button></div>'
      +(d.status==='awaiting_admin'||d.status==='open'?'<div class="row" style="gap:8px;margin-top:8px"><button class="btn l3-grant">Accorder</button><button class="btn l3-refuse" style="border-color:#5a2a2a;color:#d96459">Refuser</button></div>':'')
      +'</div>';
  }).join('');
  document.querySelectorAll('.card[data-num]').forEach(function(card){
    var num=card.getAttribute('data-num');
    function poll(){post('/api/dispute_chat.php',{dispute_number:num}).then(function(d){
      if(!d.ok)return; card.querySelector('.l3-msgs').innerHTML=(d.messages||[]).map(m=>'<div style="margin:4px 0"><strong>'+(m.sender==='admin'?'toi (admin)':'demandeur')+' :</strong> '+esc(m.body)+'</div>').join('')||'<span class="muted">aucun message</span>';
    });}
    poll();
    card.querySelector('.l3-send').addEventListener('click',function(){var i=card.querySelector('.l3-in');if(!i.value.trim())return;post('/api/dispute_chat.php',{dispute_number:num,message:i.value.trim()}).then(()=>{i.value='';poll();});});
    var g=card.querySelector('.l3-grant'), r=card.querySelector('.l3-refuse');
    if(g)g.addEventListener('click',()=>{post('/api/admin_dispute_decide.php',{dispute_number:num,decision:'grant'}).then(load);});
    if(r)r.addEventListener('click',()=>{if(confirm('Refuser = clôture + suppression du compte. Confirmer ?'))post('/api/admin_dispute_decide.php',{dispute_number:num,decision:'refuse'}).then(load);});
  });
}
function load(){get('/api/admin_disputes.php').then(d=>render(d.disputes||[]));}
load(); setInterval(load, 8000);
</script>
<?php render_footer(); ?>
