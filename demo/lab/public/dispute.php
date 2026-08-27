<?php
/**
 * Niveau 3 de SelfRecover — récupération assistée par un humain.
 *
 * 🔑 **Aucun secret n'est demandé ici.** C'est le niveau où l'utilisateur a tout
 * perdu : lui réclamer un secret reviendrait à exiger précisément ce qu'il n'a
 * plus. On collecte un faisceau de FAITS qu'un administrateur lit, jamais un
 * score — un nombre invite le relecteur à l'entériner au lieu de lire.
 *
 * Le sésame est généré dans ce navigateur et n'en sort que sous forme de
 * SHA-256 : un demandeur L3 n'ayant par définition aucune session, c'est lui
 * qui protège le fil du litige. L'identifiant, semi-public, ne doit pas suffire
 * à y lire ni à y écrire.
 */

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

  <!-- Étape 1 — ouverture du litige : un identifiant, rien d'autre. -->
  <div id="step-init">
    <div class="field"><label><?= h(t('dsp.username')) ?></label><input id="username" autocomplete="off"></div>
    <button class="btn" id="btn-init"><?= h(t('dsp.init.submit')) ?></button>
  </div>

  <!-- Étape 2 — questions contextuelles, aucune n'étant un secret. -->
  <div id="step-questions" style="display:none">
    <p class="muted" style="font-size:12.5px"><?= t('dsp.q.note') ?></p>
    <p class="muted" style="font-size:12.5px"><?= t('dsp.claim.keep') ?> <strong id="num"></strong></p>
    <div id="qfields"></div>
    <button class="btn" id="btn-submit"><?= h(t('dsp.q.submit')) ?></button>
  </div>

  <!-- Étape 3 — le fil avec l'administrateur. -->
  <div id="step-chat" style="display:none">
    <p class="muted" style="font-size:12.5px"><?= t('dsp.chat.note') ?> <strong id="num2"></strong></p>
    <div id="thread" style="max-height:280px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;padding:10px;margin-bottom:10px;font-size:13px"></div>
    <div class="field"><textarea id="chat-msg" rows="3" placeholder="<?= h(t('dsp.chat.ph')) ?>"></textarea></div>
    <button class="btn" id="btn-chat"><?= h(t('dsp.chat.send')) ?></button>
    <button class="btn btn-ghost" id="btn-refresh"><?= h(t('dsp.chat.refresh')) ?></button>
  </div>

  <!-- Étape 4 — ré-enrôlement PAR LE PROPRIÉTAIRE, une fois l'accord donné.
       Le serveur ne génère ni ne transmet de mot de passe : brancher une
       régénération sur le bouton « accepter » de l'admin recréerait le chemin
       automatique que ce niveau existe pour éviter. -->
  <div id="step-reset" style="display:none">
    <div class="toast ok"><?= h(t('dsp.reset.granted')) ?></div>
    <div class="field"><label><?= h(t('dsp.reset.pw')) ?></label><input id="new-pw" type="password" autocomplete="off"></div>
    <div class="field"><label><?= h(t('dsp.reset.word')) ?></label><input id="new-word" type="password" autocomplete="off"></div>
    <button class="btn" id="btn-reset"><?= h(t('dsp.reset.submit')) ?></button>
  </div>

  <p class="muted" style="font-size:12px;margin:14px 0 0"><?= t('dsp.privacy') ?></p>
</div>

<p class="muted" style="max-width:560px"><a href="/recover.php"><?= h(t('dsp.back')) ?></a></p>

<script src="/js/sr-derive.js"></script>
<script nonce="<?= nonce() ?>">
const DSP = <?= json_encode([
  'err'      => t('dsp.js.err'),
  'neterr'   => t('dsp.js.neterr'),
  'required' => t('dsp.js.required'),
  'sent'     => t('dsp.js.sent'),
  'you'      => t('dsp.js.you'),
  'admin'    => t('dsp.js.admin'),
  'empty'    => t('dsp.js.empty'),
  'done'     => t('dsp.js.reset_done'),
], JSON_UNESCAPED_UNICODE) ?>;

function esc(s){return String(s==null?'':s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));}
function toast(t, ok){ document.getElementById('msg').innerHTML =
  '<div class="toast '+(ok?'ok':'err')+'">'+esc(t)+'</div>'; }
function montrer(id){
  ['step-init','step-questions','step-chat','step-reset'].forEach(function(s){
    document.getElementById(s).style.display = (s===id) ? '' : 'none';
  });
}
function post(url, corps){
  return fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify(corps)}).then(r=>r.json());
}

// Le sésame reste sur cet appareil : seul son SHA-256 part au serveur, qui ne
// peut donc pas le rejouer ni le déduire de l'identifiant.
let etat = JSON.parse(localStorage.getItem('l3_case') || 'null');

async function sha256hex(s){
  const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(s));
  return Array.from(new Uint8Array(buf)).map(b=>b.toString(16).padStart(2,'0')).join('');
}

document.getElementById('btn-init').addEventListener('click', async function(){
  const u = document.getElementById('username').value.trim();
  if(!u){ toast(DSP.required); return; }
  const btn = this; btn.disabled = true;
  try{
    const sesame = Array.from(crypto.getRandomValues(new Uint8Array(32)))
      .map(b=>b.toString(16).padStart(2,'0')).join('');
    const d = await post('/api/recover_l3_init.php', { username: u, claim_hash: await sha256hex(sesame) });
    if(!d.ok){ toast(d.message || DSP.err); return; }
    if(d.dispute_number){
      etat = { num: d.dispute_number, sesame: sesame };
      localStorage.setItem('l3_case', JSON.stringify(etat));
    }
    afficherQuestions(d.questions || [], d.note);
  }catch(e){ toast(DSP.neterr); }
  finally{ btn.disabled = false; }
});

function afficherQuestions(questions, note){
  if(note) toast(note, true);
  document.getElementById('num').textContent = etat ? etat.num : '—';
  const box = document.getElementById('qfields');
  box.innerHTML = questions.map(function(q){
    if(q.type === 'select'){
      return '<div class="field"><label>'+esc(q.label)+'</label><select id="q-'+esc(q.key)+'">'+
        (q.options||[]).map(o=>'<option value="'+esc(o)+'">'+esc(o)+'</option>').join('')+'</select></div>';
    }
    return '<div class="field"><label>'+esc(q.label)+'</label><input id="q-'+esc(q.key)+
      '" autocomplete="off"'+(q.type==='year'?' inputmode="numeric"':'')+'></div>';
  }).join('');
  box.dataset.keys = questions.map(q=>q.key).join(',');
  montrer('step-questions');
}

document.getElementById('btn-submit').addEventListener('click', async function(){
  if(!etat){ toast(DSP.err); return; }
  const btn = this; btn.disabled = true;
  try{
    const answers = {};
    (document.getElementById('qfields').dataset.keys || '').split(',').filter(Boolean)
      .forEach(function(k){ const el = document.getElementById('q-'+k); if(el) answers[k] = el.value; });
    const d = await post('/api/recover_l3.php',
      { dispute_number: etat.num, claim_secret: etat.sesame, answers: answers });
    if(!d.ok){ toast(d.message || DSP.err); return; }
    toast(d.message || DSP.sent, true);
    ouvrirChat();
  }catch(e){ toast(DSP.neterr); }
  finally{ btn.disabled = false; }
});

async function ouvrirChat(){
  document.getElementById('num2').textContent = etat.num;
  montrer('step-chat');
  await rafraichir();
}

async function rafraichir(){
  if(!etat) return;
  try{
    const d = await post('/api/dispute_chat.php', { dispute_number: etat.num, claim_secret: etat.sesame });
    if(!d.ok){ toast(d.message || DSP.err); return; }
    const fil = document.getElementById('thread');
    const msgs = d.messages || [];
    fil.innerHTML = msgs.length ? msgs.map(function(m){
      const qui = m.sender === 'admin' ? DSP.admin : DSP.you;
      return '<p style="margin:0 0 8px"><strong>'+esc(qui)+'</strong> — '+esc(m.body)+'</p>';
    }).join('') : '<p class="muted" style="margin:0">'+esc(DSP.empty)+'</p>';
    fil.scrollTop = fil.scrollHeight;
    // L'accord de l'admin n'ouvre rien : il déverrouille seulement l'étape où
    // le propriétaire repose lui-même ses secrets.
    if(d.status === 'granted') montrer('step-reset');
  }catch(e){ toast(DSP.neterr); }
}

document.getElementById('btn-chat').addEventListener('click', async function(){
  const champ = document.getElementById('chat-msg');
  if(!champ.value.trim()){ toast(DSP.required); return; }
  const btn = this; btn.disabled = true;
  try{
    const d = await post('/api/dispute_chat.php',
      { dispute_number: etat.num, claim_secret: etat.sesame, message: champ.value });
    if(!d.ok){ toast(d.message || DSP.err); return; }
    champ.value = '';
    await rafraichir();
  }catch(e){ toast(DSP.neterr); }
  finally{ btn.disabled = false; }
});
document.getElementById('btn-refresh').addEventListener('click', rafraichir);

document.getElementById('btn-reset').addEventListener('click', async function(){
  const pw = document.getElementById('new-pw').value;
  const mot = document.getElementById('new-word').value;
  if(!pw || !mot){ toast(DSP.required); return; }
  const btn = this; btn.disabled = true;
  try{
    // Le nouveau mot mémorisé est dérivé ici : comme partout ailleurs, il ne
    // quitte pas ce navigateur.
    // Un sel NEUF : le mot mémorisé est remplacé, donc son sel n'a aucune raison
      // d'être réutilisé. Le garder laisserait deux empreintes successives du
      // même compte partager leur matériau.
      const selNeuf = srEngendrerSel();
      const d = await post('/api/recover_l3_reset.php', {
      dispute_number: etat.num, claim_secret: etat.sesame,
      password: pw, recovery_salt: selNeuf,
        recovery_derived_key: await srDerive(mot, selNeuf, { mode: 'hostname' })
    });
    if(!d.ok){ toast(d.message || DSP.err); return; }
    localStorage.removeItem('l3_case');
    toast(DSP.done, true);
    montrer('');
  }catch(e){ toast(DSP.neterr); }
  finally{ btn.disabled = false; }
});

// Reprise d'une procédure en cours : le sésame est sur cet appareil, donc on
// peut rouvrir le fil sans redemander quoi que ce soit.
if(etat && etat.num) ouvrirChat();
</script>
<?php render_footer(); ?>
