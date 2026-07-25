<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Auth;
render_header('Récupération', Auth::currentAccount(Db::pdo()));
?>
<h1>Récupération d'accès <span class="muted" style="font-size:13px">— sans email</span></h1>
<p class="muted" style="max-width:520px">Trois niveaux, du plus simple au plus fort. Tu n'utilises que ce dont tu te souviens ; si un niveau échoue, on te propose le suivant.</p>

<div class="card" style="max-width:520px">
  <div id="msg"></div>

  <!-- ÉTAPE L1 : passphrase -->
  <section id="step-l1">
    <div class="seg-title"><strong>Niveau 1</strong> — passphrase de secours</div>
    <div class="field"><label>Identifiant</label><input id="l1-user" autocomplete="off"></div>
    <div class="field"><label>Passphrase (les 4 mots générés à l'inscription)</label><input id="l1-pass" type="password" autocomplete="off"></div>
    <button class="btn" id="l1-btn">Récupérer avec ma passphrase</button>
    <p class="muted" style="font-size:12px"><a href="#" id="to-l2">Passphrase perdue ? → mot mémorisé + code de secours</a></p>
  </section>

  <!-- ÉTAPE L2 : mot mémorisé + recovery code -->
  <section id="step-l2" style="display:none">
    <div class="seg-title"><strong>Niveau 2</strong> — mot mémorisé + code de secours <span class="muted">(2 facteurs)</span></div>
    <div class="field"><label>Un de tes codes de secours (xxxxx-xxxxx)</label><input id="l2-code" autocomplete="off" placeholder="ex : 9zd4e-59pfp"></div>
    <div class="field"><label>Ton mot de récupération mémorisé</label><input id="l2-word" type="password" autocomplete="off"></div>
    <button class="btn" id="l2-btn">Récupérer (code + mot)</button>
    <div style="border-top:1px solid #2a2a2a;margin:12px 0;padding-top:12px">
      <div class="muted" style="font-size:12px">Ou, si tu as <strong>enrôlé cet appareil</strong> (facteur possession) :</div>
      <div class="field"><label>Identifiant</label><input id="dev-user" autocomplete="off"></div>
      <div class="field"><label>Ton mot mémorisé</label><input id="dev-word" type="password" autocomplete="off"></div>
      <button class="btn" id="dev-btn">📱 Récupérer depuis cet appareil</button>
    </div>
    <p class="muted" style="font-size:12px"><a href="#" id="to-l3">Tout perdu ? → récupération assistée par un administrateur</a></p>
  </section>

  <!-- ÉTAPE L3 : récupération assistée -->
  <section id="step-l3" style="display:none">
    <div class="seg-title"><strong>Niveau 3</strong> — récupération assistée (décision humaine)</div>
    <div id="l3-init">
      <p class="muted">On ne te demande aucun secret. Tu répondras à quelques questions de contexte, puis un administrateur examinera ta demande dans un chat. <strong>Garde le code de suivi</strong> qui s'affichera : il protège ta procédure.</p>
      <div class="field"><label>Identifiant</label><input id="l3-user" autocomplete="off"></div>
      <button class="btn" id="l3-init-btn">Démarrer la récupération assistée</button>
    </div>
    <div id="l3-questions" style="display:none">
      <p class="muted">Litige <strong id="l3-num"></strong> — code de suivi enregistré sur cet appareil.</p>
      <div id="l3-qfields"></div>
      <button class="btn" id="l3-submit-btn">Envoyer à l'administrateur</button>
    </div>
    <div id="l3-chat" style="display:none">
      <p class="muted">Statut : <strong id="l3-status">en attente d'un administrateur…</strong></p>
      <div id="l3-messages" style="max-height:220px;overflow:auto;border:1px solid #2a2a2a;border-radius:6px;padding:8px;margin-bottom:8px;font-size:13px"></div>
      <div class="row" style="gap:6px"><input id="l3-msg" placeholder="écris à l'administrateur…" style="flex:1"><button class="btn" id="l3-send-btn">Envoyer</button></div>
      <div id="l3-reset" style="display:none;margin-top:12px">
        <div class="seg-title">Accès accordé — reprends ton compte</div>
        <p class="muted" style="font-size:12px">Tu choisis toi-même ton nouveau mot de passe et ton nouveau mot mémorisé. Le serveur n'en génère aucun.</p>
        <div class="field"><label>Nouveau mot de passe (8+ caractères)</label><input id="l3-newpw" type="password"></div>
        <div class="field"><label>Nouveau mot de récupération mémorisé</label><input id="l3-newword" type="password"></div>
        <button class="btn" id="l3-reset-btn">Reprendre l'accès</button>
      </div>
    </div>
  </section>

  <div id="result" style="display:none"></div>
</div>
<p class="muted" style="max-width:520px"><a href="/login.php">← Retour à la connexion</a></p>

<script src="/js/sr-derive.js"></script>
<script src="/js/sr-device.js"></script>
<script nonce="<?= nonce() ?>">
function esc(s){return String(s==null?'':s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));}
function $(id){return document.getElementById(id);}
function toast(t,ok){$('msg').innerHTML='<div class="toast '+(ok?'ok':'err')+'">'+esc(t)+'</div>';}
function show(id){['step-l1','step-l2','step-l3'].forEach(s=>$(s).style.display=s===id?'block':'none');}
function showCreds(title,c,note){
  $('msg').innerHTML='<div class="toast ok">'+esc(title)+'</div>';
  ['step-l1','step-l2','step-l3'].forEach(s=>$(s).style.display='none');
  var h='<p><strong>Copie ça maintenant</strong> (plus jamais affiché) :</p>';
  if(c.password) h+='<div class="field"><label>Mot de passe</label><input value="'+esc(c.password)+'" readonly></div>';
  if(c.passphrase) h+='<div class="field"><label>Passphrase (L1)</label><input value="'+esc(c.passphrase)+'" readonly></div>';
  if(c.recovery_codes) h+='<div class="field"><label>Codes de secours</label><textarea readonly rows="5" style="width:100%;font-family:monospace">'+esc(c.recovery_codes.join('\n'))+'</textarea></div>';
  if(note) h+='<p class="muted">⚠️ '+esc(note)+'</p>';
  h+='<a class="btn" href="/login.php">Aller à la connexion →</a>';
  var r=$('result'); r.style.display='block'; r.innerHTML=h;
}
function post(url,payload){return fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}).then(r=>r.json().then(d=>({status:r.status,d})));}

// --- L1 ---
$('l1-btn').addEventListener('click',function(){
  var u=$('l1-user').value.trim(), p=$('l1-pass').value;
  if(!u||!p){toast('Identifiant et passphrase requis.');return;}
  $('l3-user').value=u; $('l2-word').dataset.user=u;
  post('/api/recover_l1.php',{username:u,passphrase:p}).then(({d})=>{
    if(d.ok){showCreds('Accès récupéré (L1) ✔',d.credentials,d.note);return;}
    toast(d.message||'Échec.');
    if(d.escalate==='l2'){show('step-l2');toast('Passphrase incorrecte plusieurs fois — essaie le niveau 2.');}
  });
});
$('to-l2').addEventListener('click',e=>{e.preventDefault();show('step-l2');});
$('to-l3').addEventListener('click',e=>{e.preventDefault();show('step-l3');});

// --- L2 ---
$('l2-btn').addEventListener('click',async function(){
  var code=$('l2-code').value.trim().toLowerCase(), word=$('l2-word').value;
  if(!code||!word){toast('Code et mot mémorisé requis.');return;}
  var memorized_derived_key=await srDerive(word); // le mot ne quitte pas le navigateur
  post('/api/recover_l2_code.php',{code,memorized_derived_key}).then(({d})=>{
    if(d.ok){showCreds('Accès récupéré (L2) ✔',d.credentials,d.low_codes_warning||d.note);return;}
    toast(d.message||'Échec.');
    if(d.escalate==='l3'){show('step-l3');toast('Trop de tentatives — passe à la récupération assistée.');}
  });
});

// --- Facteur appareil (depuis L2) ---
$('dev-btn').addEventListener('click',async function(){
  var u=$('dev-user').value.trim(), w=$('dev-word').value;
  if(!u||!w){toast('Identifiant et mot mémorisé requis.');return;}
  if(!srDeviceHas(u)){toast('Aucun appareil enrôlé ici pour ce compte.');return;}
  try{ var r=await srDeviceRecover(u,w);
       if(r.ok){showCreds('Accès récupéré (cet appareil) ✔',r.credentials,r.note);return;}
       toast(r.message||'Échec.'); }
  catch(e){ toast('Échec crypto (cet appareil).'); }
});

// --- L3 ---
var L3={num:null,claim:null};
$('l3-init-btn').addEventListener('click',async function(){
  var u=$('l3-user').value.trim();
  if(!u){toast('Identifiant requis.');return;}
  // sésame généré côté client ; on n'envoie que son SHA-256 (claim_hash).
  var claim=[...crypto.getRandomValues(new Uint8Array(16))].map(b=>b.toString(16).padStart(2,'0')).join('');
  var buf=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(claim));
  var claim_hash=[...new Uint8Array(buf)].map(b=>b.toString(16).padStart(2,'0')).join('');
  post('/api/recover_l3_init.php',{username:u,claim_hash}).then(({d})=>{
    if(!d.ok){toast(d.message||'Échec.');return;}
    if(d.already_open){toast(d.note,true);return;}
    L3.num=d.dispute_number; L3.claim=claim;
    try{localStorage.setItem('l3_'+d.dispute_number,claim);}catch(e){}
    $('l3-num').textContent=d.dispute_number;
    $('l3-qfields').innerHTML=d.questions.map(q=>{
      if(q.type==='select') return '<div class="field"><label>'+esc(q.label)+'</label><select data-k="'+esc(q.key)+'">'+q.options.map(o=>'<option>'+esc(o)+'</option>').join('')+'</select></div>';
      return '<div class="field"><label>'+esc(q.label)+'</label><input data-k="'+esc(q.key)+'" autocomplete="off"></div>';
    }).join('');
    $('l3-init').style.display='none'; $('l3-questions').style.display='block';
    toast('Litige '+d.dispute_number+' ouvert. Note le code de suivi (gardé sur cet appareil).',true);
  });
});
$('l3-submit-btn').addEventListener('click',function(){
  var answers={};
  $('l3-qfields').querySelectorAll('[data-k]').forEach(el=>answers[el.dataset.k]=el.value);
  post('/api/recover_l3.php',{dispute_number:L3.num,claim_secret:L3.claim,answers}).then(({d})=>{
    if(!d.ok){toast(d.message||'Échec.');return;}
    $('l3-questions').style.display='none'; $('l3-chat').style.display='block';
    toast(d.message,true); pollChat();
  });
});
function pollChat(){
  post('/api/dispute_chat.php',{dispute_number:L3.num,claim_secret:L3.claim}).then(({d})=>{
    if(!d.ok)return;
    $('l3-messages').innerHTML=(d.messages||[]).map(m=>'<div style="margin:4px 0"><strong>'+(m.sender==='admin'?'👤 admin':'toi')+' :</strong> '+esc(m.body)+'</div>').join('')||'<span class="muted">aucun message</span>';
    $('l3-status').textContent = d.status==='granted' ? 'accès accordé ✔' : (d.status==='refused' ? 'refusé' : 'en attente d\'un administrateur…');
    $('l3-reset').style.display = d.status==='granted' ? 'block' : 'none';
    if(d.status!=='resolved' && d.status!=='refused' && d.status!=='closed') setTimeout(pollChat,3000);
  });
}
$('l3-send-btn').addEventListener('click',function(){
  var m=$('l3-msg').value.trim(); if(!m)return;
  post('/api/dispute_chat.php',{dispute_number:L3.num,claim_secret:L3.claim,message:m}).then(()=>{$('l3-msg').value='';pollChat();});
});
$('l3-reset-btn').addEventListener('click',async function(){
  var pw=$('l3-newpw').value, word=$('l3-newword').value;
  if(pw.length<8){toast('Mot de passe : 8 caractères minimum.');return;}
  if(word.length<4){toast('Mot de récupération : 4 caractères minimum.');return;}
  var recovery_derived_key=await srDerive(word);
  post('/api/recover_l3_reset.php',{dispute_number:L3.num,claim_secret:L3.claim,password:pw,recovery_derived_key}).then(({d})=>{
    if(!d.ok){toast(d.message||'Échec.');return;}
    try{localStorage.removeItem('l3_'+L3.num);}catch(e){}
    showCreds('Accès rétabli ✔',Object.assign({password:pw},d.credentials),d.note);
  });
});
</script>
<?php render_footer(); ?>
