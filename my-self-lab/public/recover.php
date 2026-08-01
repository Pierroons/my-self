<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;

render_header(t('rec.title'), Auth::currentAccount(Db::pdo()));
?>
<style>
.rec-tabs{display:flex;gap:8px;margin-bottom:16px}
.rec-tabs button{flex:1;padding:9px 12px;background:transparent;border:1px solid var(--border);border-radius:6px;color:var(--txt2);font-family:inherit;font-size:13px;cursor:pointer}
.rec-tabs button.on{border-color:var(--acc);color:var(--acc);background:rgba(63,185,140,.08);font-weight:600}
.rec-note{font-size:12.5px;color:var(--txt2);line-height:1.6;margin:0 0 14px}
.rec-l3{background:var(--elev);border:1px solid var(--border);border-left:3px solid var(--warn);border-radius:8px;padding:14px 16px;margin-top:16px;font-size:13px;color:var(--txt2);line-height:1.6}
.rec-l3 h3{margin:0 0 6px;font-size:13.5px;color:var(--warn)}
</style>

<h1><?= h(t('rec.h1')) ?> <span class="muted" style="font-size:13px"><?= h(t('rec.h1.sub')) ?></span></h1>

<div class="card" style="max-width:460px">
  <p class="muted" style="margin-top:0"><?= h(t('rec.intro')) ?></p>

  <!--
    Deux chemins présentés côte à côte plutôt qu'un formulaire unique : les
    deux secrets n'ont pas la même nature, et le dire évite qu'un utilisateur
    saisisse l'un en croyant fournir l'autre.
  -->
  <div class="rec-tabs">
    <button id="tab-l1" class="on"><?= h(t('rec.l1.tab')) ?></button>
    <button id="tab-l2"><?= h(t('rec.l2.tab')) ?></button>
  </div>

  <div id="msg"></div>

  <div id="form">
    <p class="rec-note" id="note-l1"><?= t('rec.l1.note') ?></p>
    <p class="rec-note" id="note-l2" style="display:none"><?= t('rec.l2.note') ?></p>

    <div class="field" id="f-user"><label><?= h(t('rec.username')) ?></label><input id="username" autocomplete="off"></div>

    <div class="field" id="f-l1">
      <label><?= h(t('rec.passphrase')) ?></label>
      <input id="passphrase" type="password" autocomplete="off" placeholder="<?= h(t('rec.passphrase_ph')) ?>">
    </div>
    <div class="field" id="f-l2" style="display:none">
      <label><?= h(t('rec.code')) ?></label>
      <input id="code" autocomplete="off" placeholder="<?= h(t('rec.code_ph')) ?>">
    </div>
    <div class="field" id="f-l2b" style="display:none">
      <label><?= h(t('rec.word2')) ?></label>
      <input id="recovery" type="password" autocomplete="off">
    </div>

    <button class="btn" id="btn-recup"><?= h(t('rec.submit')) ?></button>
  </div>

  <div id="result" style="display:none"></div>

  <div class="rec-l3">
    <h3><?= h(t('rec.l3.h3')) ?></h3>
    <?= t('rec.l3.body') ?>
    <p style="margin:10px 0 0"><a class="btn btn-ghost" href="/dispute.php" style="padding:5px 12px"><?= h(t('rec.l3.btn')) ?></a></p>
  </div>
</div>

<p class="muted" style="max-width:460px"><a href="/login.php"><?= h(t('rec.back')) ?></a></p>

<script nonce="<?= nonce() ?>">
const REC = <?= json_encode([
    'required' => t('rec.js.required'),
    'done'     => t('rec.js.done'),
    'copy'     => t('rec.js.copy'),
    'newpw'    => t('rec.js.newpw'),
    'newpp'    => t('rec.js.newpp'),
    'goto'     => t('rec.js.goto'),
    'neterr'   => t('rec.js.neterr'),
    'err'      => t('log.error'),
], JSON_UNESCAPED_UNICODE) ?>;

function esc(s){return String(s==null?'':s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));}

let niveau = 'l1';
function basculer(n){
  niveau = n;
  document.getElementById('tab-l1').classList.toggle('on', n==='l1');
  document.getElementById('tab-l2').classList.toggle('on', n==='l2');
  document.getElementById('f-l1').style.display = n==='l1' ? '' : 'none';
  document.getElementById('f-l2').style.display = n==='l2' ? '' : 'none';
  document.getElementById('f-l2b').style.display = n==='l2' ? '' : 'none';
  // Aucun identifiant au niveau 2 : le code localise le compte par lui-même.
  document.getElementById('f-user').style.display = n==='l1' ? '' : 'none';
  document.getElementById('note-l1').style.display = n==='l1' ? '' : 'none';
  document.getElementById('note-l2').style.display = n==='l2' ? '' : 'none';
  document.getElementById('msg').innerHTML = '';
}
document.getElementById('tab-l1').addEventListener('click', ()=>basculer('l1'));
document.getElementById('tab-l2').addEventListener('click', ()=>basculer('l2'));

function recuperer(btn){
  const username = document.getElementById('username').value.trim();
  const secret = niveau==='l1'
    ? document.getElementById('passphrase').value
    : document.getElementById('code').value;
  if((niveau==='l1' && !username) || !secret){
    document.getElementById('msg').innerHTML = '<div class="toast err">'+esc(REC.required)+'</div>';
    return;
  }
  btn.disabled = true;
  // Le champ transmis désigne le niveau : le client n'indique pas lui-même
  // contre quel secret il souhaite être comparé.
  const corps = niveau==='l1'
    ? {username, passphrase: secret}
    : {recovery_code: secret, recovery_word: document.getElementById('recovery').value};
  fetch('/api/recover.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(corps)})
    .then(r=>r.json()).then(d=>{
      btn.disabled = false;
      if(!d.ok){document.getElementById('msg').innerHTML='<div class="toast err">'+esc(d.message||REC.err)+'</div>';return;}
      document.getElementById('form').style.display='none';
      document.querySelector('.rec-tabs').style.display='none';
      document.getElementById('msg').innerHTML='<div class="toast ok">'+esc(REC.done)+'</div>';
      const r=document.getElementById('result');
      r.style.display='block';
      r.innerHTML='<p>'+REC.copy+'</p>'
        +'<div class="field"><label>'+esc(REC.newpw)+'</label><input value="'+esc(d.credentials.password)+'" readonly></div>'
        +'<div class="field"><label>'+esc(REC.newpp)+'</label><input value="'+esc(d.credentials.passphrase)+'" readonly></div>'
        +'<p class="muted">⚠️ '+esc(d.note||'')+'</p>'
        +'<a class="btn" href="/login.php">'+esc(REC.goto)+'</a>';
    }).catch(e=>{btn.disabled=false;document.getElementById('msg').innerHTML='<div class="toast err">'+esc(REC.neterr)+'</div>';});
}
document.getElementById('btn-recup').addEventListener('click', function(){ recuperer(this); });
</script>
<?php render_footer(); ?>
