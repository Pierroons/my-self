<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;

render_header(t('dsp.title'), Auth::currentAccount(Db::pdo()));
?>
<h1><?= t('dsp.h1') ?></h1>

<div class="card" style="max-width:560px">
  <p class="muted" style="margin-top:0"><?= t('dsp.intro') ?></p>

  <div style="background:var(--elev);border:1px solid var(--border);border-left:3px solid var(--warn);border-radius:8px;padding:13px 15px;margin:14px 0;font-size:13px;color:var(--txt2);line-height:1.6">
    <strong style="color:var(--warn);display:block;margin-bottom:5px"><?= h(t('dsp.why.h3')) ?></strong>
    <?= h(t('dsp.why')) ?>
  </div>

  <div id="msg"></div>
  <div id="form">
    <div class="field"><label><?= h(t('dsp.username')) ?></label><input id="username" autocomplete="off"></div>
    <div class="field">
      <label><?= h(t('dsp.recit')) ?></label>
      <textarea id="recit" rows="6" placeholder="<?= h(t('dsp.recit_ph')) ?>"></textarea>
      <span class="muted" style="font-size:11px"><?= h(t('dsp.recit_note')) ?></span>
    </div>
    <div class="field"><label><?= h(t('dsp.contact')) ?></label><input id="contact" maxlength="200" placeholder="<?= h(t('dsp.contact_ph')) ?>"></div>
    <button class="btn" id="btn-dispute"><?= h(t('dsp.submit')) ?></button>
  </div>

  <p class="muted" style="font-size:12px;margin:14px 0 0"><?= t('dsp.privacy') ?></p>
</div>

<p class="muted" style="max-width:560px"><a href="/recover.php"><?= h(t('dsp.back')) ?></a></p>

<script nonce="<?= nonce() ?>">
const DSP = <?= json_encode(['err' => t('dsp.js.err'), 'neterr' => t('dsp.js.neterr')], JSON_UNESCAPED_UNICODE) ?>;
function esc(s){return String(s==null?'':s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));}
document.getElementById('btn-dispute').addEventListener('click', function(){
  const btn = this;
  btn.disabled = true;
  fetch('/api/dispute.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
    username: document.getElementById('username').value.trim(),
    recit:    document.getElementById('recit').value,
    contact:  document.getElementById('contact').value
  })}).then(r=>r.json()).then(d=>{
    btn.disabled = false;
    const box = document.getElementById('msg');
    if(d.ok){
      document.getElementById('form').style.display='none';
      box.innerHTML = '<div class="toast ok">'+esc(d.message)+'</div>';
    } else {
      box.innerHTML = '<div class="toast err">'+esc(d.message||DSP.err)+'</div>';
    }
  }).catch(e=>{btn.disabled=false;document.getElementById('msg').innerHTML='<div class="toast err">'+esc(DSP.neterr)+'</div>';});
});
</script>
<?php render_footer(); ?>
