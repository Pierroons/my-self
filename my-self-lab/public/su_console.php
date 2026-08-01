<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;

$pdo = Db::pdo();
$account = Auth::currentAccount($pdo);

render_header(t('su.title'), $account);
?>
<style>
.su-intro{background:rgba(63,185,140,.07);border:1px solid var(--border);border-left:3px solid var(--acc);border-radius:8px;padding:12px 16px;margin-bottom:18px;font-size:13px;color:var(--txt2);line-height:1.55}
.su-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:20px}
.su-card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:16px;text-align:center}
.su-card h3{margin:0 0 6px;font-size:16px}
.su-card .obj{font-size:12.5px;color:var(--txt2);margin:0 0 12px;line-height:1.5;min-height:34px}
.su-card .btn{width:100%}
.su-card.su .btn{border-color:var(--acc2)}
.result{margin-top:18px}.result.hidden{display:none}
.narration{background:#0a0f13;border:1px solid var(--border);border-radius:8px;padding:16px 18px;font-family:ui-monospace,monospace;font-size:13.5px;line-height:1.65;margin-bottom:14px}
.narration .step{padding:5px 0;color:#c5d1db}.narration .step b{color:var(--txt)}.narration .step .res{color:var(--acc)}
.panels{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:760px){.panels{grid-template-columns:1fr}}
.panel{border-radius:8px;padding:14px 16px;font-size:13.5px}
.panel.verte{background:rgba(63,185,140,.10);border:1px solid rgba(63,185,140,.35)}
.panel.rouge{background:rgba(217,100,89,.10);border:1px solid rgba(217,100,89,.35)}
.panel .ptitle{font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px}
.panel.verte .ptitle{color:var(--acc)}.panel.rouge .ptitle{color:var(--danger)}
.panel ul{margin:0;padding-left:16px;line-height:1.7}.panel li{word-break:break-word}
.verdict{margin-top:14px;background:rgba(63,185,140,.12);border:1px solid var(--acc2);border-radius:8px;padding:12px 16px}
.verdict .k{font-size:13.5px;color:var(--txt);font-style:italic}
.subttl{color:var(--txt2);font-size:13px;margin:-6px 0 12px}
.spinner{color:var(--txt2);font-size:13px}
.demo-tag{display:inline-block;background:rgba(63,185,140,.15);color:var(--acc);border-radius:5px;padding:2px 7px;font-size:11px;font-weight:600;margin-left:6px}
.su-term{margin-top:16px;background:#080c10;border:1px solid var(--border);border-radius:8px;overflow:hidden}
.su-term-head{background:#0d141a;padding:6px 12px;font-size:11.5px;color:var(--txt2);border-bottom:1px solid var(--border);font-family:ui-monospace,monospace}
.su-term-out{padding:12px 14px;font-family:ui-monospace,monospace;font-size:13px;line-height:1.55;color:#c5d1db;max-height:340px;overflow:auto;white-space:pre-wrap;word-break:break-word}
.su-term-out .cmd{color:var(--acc)}.su-term-out .lock{color:#fca5a5}
.su-term-inrow{display:flex;align-items:center;border-top:1px solid var(--border);padding:6px 12px;font-family:ui-monospace,monospace}
.su-term-inrow .pfx{color:var(--acc);margin-right:8px;font-size:13px}
.su-term-in{flex:1;background:transparent;border:none;outline:none;color:var(--txt);font-family:ui-monospace,monospace;font-size:13px}
</style>

<h1><?= t('su.h1') ?></h1>
<div class="su-intro"><?= t('su.intro') ?> <span class="demo-tag">DEMO</span></div>

<?php if (!$account): ?>
  <div class="card"><p class="muted"><?= t('su.login') ?></p></div>
<?php else: ?>

<div class="su-grid">
  <div class="su-card">
    <h3><?= t('su.user.h3') ?></h3>
    <p class="obj"><?= t('su.user.obj') ?></p>
    <button class="btn js-role" data-role="user"><?= h(t('su.user.btn')) ?></button>
  </div>
  <div class="su-card">
    <h3><?= t('su.admin.h3') ?></h3>
    <p class="obj"><?= t('su.admin.obj') ?></p>
    <button class="btn js-role" data-role="admin"><?= h(t('su.admin.btn')) ?></button>
  </div>
  <div class="su-card su">
    <h3><?= t('su.su.h3') ?></h3>
    <p class="obj"><?= t('su.su.obj') ?></p>
    <button class="btn js-role" data-role="su"><?= h(t('su.su.btn')) ?></button>
  </div>
</div>

<div class="result hidden" id="result"></div>

<script nonce="<?= nonce() ?>">
const SU = <?= json_encode(['running'=>t('su.js.running'),'termhead'=>t('su.js.termhead'),'banner1'=>t('su.js.banner1'),'banner2'=>t('su.js.banner2'),'prompt'=>t('su.js.prompt'),'wrongpw'=>t('su.js.wrongpw'),'error'=>t('su.js.error'),'inert'=>t('su.js.inert'),'confirm'=>t('su.js.confirm'),'refuse'=>t('su.js.refuse')], JSON_UNESCAPED_UNICODE) ?>;
function esc(s){return String(s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));}
function afficher(role){
  const box=document.getElementById('result');
  box.classList.remove('hidden');
  box.innerHTML='<div class="spinner">'+SU.running+'</div>';
  box.scrollIntoView({behavior:'smooth',block:'start'});
  labPost('/api/su_console.php',{role}).then(d=>{
    if(!d.ok){box.innerHTML='<div class="panel rouge">'+esc(d.message||'Erreur')+'</div>';return;}
    let h='<h2 style="margin-top:0">'+esc(d.titre)+'</h2>';
    h+='<div class="subttl">'+esc(d.sous_titre)+'</div>';
    h+='<div class="narration">';
    d.etapes.forEach((e,i)=>{h+='<div class="step"><b>'+(i+1)+'. '+esc(e.action)+'</b> → <span class="res">'+esc(e.resultat)+'</span></div>';});
    h+='</div><div class="panels">';
    h+='<div class="panel verte"><div class="ptitle">✅ '+esc(d.peut.label)+'</div><ul>';
    d.peut.lignes.forEach(l=>{h+='<li>'+esc(l)+'</li>';});
    h+='</ul></div>';
    h+='<div class="panel rouge"><div class="ptitle">🚫 '+esc(d.ne_peut_pas.label)+'</div><ul>';
    d.ne_peut_pas.lignes.forEach(l=>{h+='<li>'+esc(l)+'</li>';});
    h+='</ul></div></div>';
    h+='<div class="verdict"><div class="k">💡 '+esc(d.cle)+'</div></div>';
      // Aperçu du panneau admin — 5 sections, données fictives, actions inertes.
      if(d.apercu){
        const a=d.apercu;
        h+='<div class="panel" style="border-color:var(--border)">'
          +'<div class="ptitle">🖥️ '+esc(a.label)+'</div>'
          +'<p class="muted" style="font-size:12.5px;margin:4px 0 12px">'+esc(a.intro)+'</p>';
        a.sections.forEach(function(sec){
          h+='<div style="margin-bottom:14px">'
            +'<b style="font-size:13px">'+esc(sec.titre)+'</b>'
            +'<div style="overflow-x:auto"><table class="adm" style="width:100%;margin:5px 0;font-size:12px"><tr>'
            +sec.colonnes.map(c=>'<th>'+esc(c)+'</th>').join('')+'</tr>'
            +sec.lignes.map(r=>'<tr>'+r.map(c=>'<td>'+esc(c)+'</td>').join('')+'</tr>').join('')
            +'</table></div>';
          if(sec.faisceau && a.faisceau){
            const f=a.faisceau;
            const lig=(x,decl)=>'<li>'+(x.ok?'✅':'⬜')+' '+esc(x.label)
              +'<span class="muted"> — '+(decl
                  ? 'déclaré <b>'+esc(x.dit)+'</b> · réel <b>'+esc(x.reel)+'</b>'
                  : esc(x.detail))+'</span></li>';
            h+='<div style="border-left:2px solid var(--border);padding-left:10px;margin:6px 0 0">'
              +'<div style="display:flex;gap:16px;flex-wrap:wrap;font-size:12px">'
                +'<div style="flex:1;min-width:220px"><b style="color:var(--acc)">PASSIF</b>'
                  +'<span class="muted"> — constaté, non falsifiable</span>'
                  +'<ul style="margin:3px 0 0;padding-left:17px">'+f.passif.map(x=>lig(x,false)).join('')+'</ul></div>'
                +'<div style="flex:1;min-width:220px"><b style="color:var(--warn)">DÉCLARATIF</b>'
                  +'<span class="muted"> — affirmé, devinable</span>'
                  +'<ul style="margin:3px 0 0;padding-left:17px">'+f.declaratif.map(x=>lig(x,true)).join('')+'</ul></div>'
              +'</div>'
              +'<p class="muted" style="font-size:11.5px;margin:6px 0 0"><b>'+esc(f.resume)+'</b> · '+esc(SU.inert)+'</p>'
              +'</div>';
          }
          h+='<p class="muted" style="font-size:11.5px;margin:4px 0 0">'+esc(sec.cle)+'</p></div>';
        });
        h+='<div class="panel rouge" style="margin-top:6px"><div class="ptitle">🚫 '+esc(a.mur.label)+'</div><ul>'
          +a.mur.lignes.map(x=>'<li>'+esc(x)+'</li>').join('')+'</ul></div>'
          +'</div>';
      }
    if(d.role==='su'){
      h+='<div class="su-term">'
        +'<div class="su-term-head">'+SU.termhead+'</div>'
        +'<div class="su-term-out" id="suterm-out"></div>'
        +'<div class="su-term-inrow"><span class="pfx">su&gt;</span><input class="su-term-in" id="suterm-in" autocomplete="off" spellcheck="false" placeholder="help"></div>'
        +'</div>';
    }
    box.innerHTML=h;
    if(d.role==='su') initSuTerminal();
    box.scrollIntoView({behavior:'smooth',block:'start'});
  }).catch(e=>{box.innerHTML='<div class="panel rouge">'+SU.error+' '+esc(e.message)+'</div>';});
}
function initSuTerminal(){
  const out=document.getElementById('suterm-out'), inp=document.getElementById('suterm-in');
  if(!out||!inp) return;
  let mutations=[]; const hist=[]; let hi=-1;
  const pr=(t,c)=>{const d=document.createElement('div');if(c)d.className=c;d.textContent=t;out.appendChild(d);};
  const banner=()=>{pr(SU.banner1);pr(SU.banner2);};
  banner(); inp.focus();
  inp.addEventListener('keydown',e=>{
    if(e.key==='ArrowUp'){if(hist.length){hi=(hi<0?hist.length:hi)-1;if(hi<0)hi=0;inp.value=hist[hi]||'';}e.preventDefault();return;}
    if(e.key==='ArrowDown'){if(hi>=0){hi++;inp.value=hist[hi]||'';if(hi>=hist.length)hi=-1;}e.preventDefault();return;}
    if(e.key!=='Enter')return;
    const cmd=inp.value; inp.value=''; if(cmd.trim())hist.push(cmd); hi=-1;
    pr('su> '+cmd,'cmd');
    labPost('/api/su_console.php',{cmd,mutations}).then(d=>{
      if(!d.ok){pr(d.message||'erreur','lock');return;}
      if((d.output||[])[0]==='__CLEAR__'){out.innerHTML='';banner();return;}
      (d.output||[]).forEach(l=>pr(l,/Illisible|⛔|🔒/.test(l)?'lock':null));
      if(d.mutating&&cmd.trim())mutations.push(cmd.trim());
      out.scrollTop=out.scrollHeight;
    }).catch(err=>pr('erreur: '+err.message,'lock'));
  });
}
document.querySelectorAll('.js-role').forEach(b=>b.addEventListener('click',()=>{
  const role=b.dataset.role;
  if(role==='su'){
    const p=prompt(SU.prompt);
    if(p===null) return;                 // annulé
    if(p!=='test-su'){ alert(SU.wrongpw); return; }
  }
  afficher(role);
}));
</script>
<?php endif; ?>
<?php render_footer(); ?>
