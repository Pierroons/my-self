<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/forum.php';
require_once __DIR__ . '/../lib/moderate.php';
require_once __DIR__ . '/../lib/layout.php';

use Pierroons\MySelfLab\Db;
use Pierroons\MySelfLab\Forum;
use Pierroons\MySelfLab\Moderate;
use Pierroons\MySelfLab\Auth;

$pdo = Db::pdo();
$account = Auth::currentAccount($pdo);
$threadId = (int) ($_GET['id'] ?? 0);
$thread = Forum::getThread($pdo, $threadId);

/** Badge réputation coloré selon le niveau. */
function rep_badge(int $rep): string {
    if ($rep >= 25) { $c = '#3fb98c'; $lbl = t('thr.rep.trust'); }
    elseif ($rep >= 15) { $c = '#9aa9b6'; $lbl = t('thr.rep.member'); }
    elseif ($rep >= 5) { $c = '#d4a056'; $lbl = t('thr.rep.frail'); }
    else { $c = '#d96459'; $lbl = t('thr.rep.watch'); }
    return '<span class="rep-badge" style="color:' . $c . ';border-color:' . $c . '" title="' . h(t('thr.rep.title', $rep, $lbl)) . '">★ ' . $rep . '</span>';
}

if (!$thread) {
    render_header(t('thr.notfound.title'), $account);
    echo '<div class="card"><p class="muted">' . t('thr.notfound') . '</p></div>';
    render_footer();
    exit;
}
$posts = Forum::listPosts($pdo, $threadId);

render_header($thread['titre'], $account);
?>
<p class="muted"><a href="/index.php?cat=<?= h($thread['categorie']) ?>">← <?= h(Forum::CATEGORIES[$thread['categorie']] ?? 'Forum') ?></a></p>
<h1><?= h($thread['titre']) ?></h1>
<p class="muted"><?= h(t('thr.openedby')) ?> <strong>@<?= h($thread['auteur']) ?></strong> le <?= date('d/m/Y', (int) $thread['created_at']) ?></p>

<style>
.rep-badge{font-size:10.5px;border:1px solid;padding:0 6px;border-radius:9px;margin-left:6px;font-weight:600}
.post-layout{display:flex;gap:12px}
.votebox{display:flex;flex-direction:column;align-items:center;gap:2px;min-width:38px;flex-shrink:0}
.votebox button{background:transparent;border:1px solid var(--border);color:var(--txt2);width:30px;height:26px;border-radius:5px;cursor:pointer;font-size:13px;line-height:1}
.votebox button:hover:not(:disabled){border-color:var(--acc);color:var(--acc)}
.votebox button.actif{background:var(--acc2);color:#fff;border-color:var(--acc2)}
.votebox button:disabled{opacity:.35;cursor:not-allowed}
.votebox .score{font-weight:700;font-size:14px;font-feature-settings:'tnum'}
.votebox .score.pos{color:var(--acc)}
.votebox .score.neg{color:var(--danger)}
.post-body{flex:1;min-width:0}
</style>

<?php
$canVote = false; $whyNot = '';
if ($account) { [$canVote, $whyNot] = Moderate::canVote($pdo, (int) $account['id']); }
?>

<?php if ($account && !$canVote): ?>
  <div class="toast" style="background:rgba(212,160,86,.12);border:1px solid var(--warn);color:var(--warn)">⚠ <?= h($whyNot) ?></div>
<?php endif; ?>

<?php foreach ($posts as $p):
  $pid = (int) $p['id'];
  $score = Moderate::score($pdo, 'post', $pid);
  $rep = Moderate::getReputation($pdo, (int) $p['account_id'])['reputation'];
  $mine = $account ? Moderate::userVote($pdo, (int) $account['id'], 'post', $pid) : null;
  $isAuthor = $account && (int) $account['id'] === (int) $p['account_id'];
?>
  <div class="post">
    <div class="post-layout">
      <div class="votebox" data-post="<?= $pid ?>">
        <button data-post="<?= $pid ?>" data-val="1" class="<?= $mine === 1 ? 'actif' : '' ?>" <?= (!$canVote || $isAuthor || $mine !== null) ? 'disabled' : '' ?> title="<?= h(t($isAuthor ? 'thr.vote.own' : 'thr.vote.up')) ?>">▲</button>
        <span class="score <?= $score > 0 ? 'pos' : ($score < 0 ? 'neg' : '') ?>" id="score-<?= $pid ?>"><?= $score ?></span>
        <button data-post="<?= $pid ?>" data-val="-1" class="<?= $mine === -1 ? 'actif' : '' ?>" <?= (!$canVote || $isAuthor || $mine !== null) ? 'disabled' : '' ?> title="<?= h(t('thr.vote.down')) ?>">▼</button>
      </div>
      <div class="post-body">
        <div class="head"><span class="auteur">@<?= h($p['auteur']) ?></span><?= rep_badge($rep) ?><span class="muted" style="margin-left:8px"><?= date('d/m/Y H:i', (int) $p['created_at']) ?></span></div>
        <div class="contenu"><?= h($p['contenu']) ?></div>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<?php if ($account && $canVote) { render_vote_reason_dialog(); } ?>

<script nonce="<?= nonce() ?>">
function votePost(postId, value, reason, code){
  labPost('/api/vote.php',{target_type:'post',target_id:postId,value:value,reason:reason||null,reason_code:code||null}).then(d=>{
    if(d.ok){
      const s=document.getElementById('score-'+postId);
      s.textContent=d.score;
      s.className='score '+(d.score>0?'pos':(d.score<0?'neg':''));
      // désactive les 2 boutons (vote unique) + marque l'actif
      document.querySelectorAll('.votebox[data-post="'+postId+'"] button').forEach(b=>b.disabled=true);
      if(!d.blocked){
        const btn=document.querySelector('.votebox[data-post="'+postId+'"] button[data-val="'+value+'"]');
        if(btn)btn.classList.add('actif');
      }
      if(d.blocked){alert(d.message);}
    } else { alert(d.message||<?= json_encode(t('log.error')) ?>); }
  });
}
// Le ▲ part directement : un vote positif ne sanctionne personne, il n'a donc
// personne à qui rendre des comptes. Le ▼ demande d'abord ce qu'on reproche.
document.querySelectorAll('.votebox button[data-val]').forEach(function(b){
  b.addEventListener('click', function(){
    const post = +b.dataset.post, val = +b.dataset.val;
    if (val === 1 || typeof demanderMotif !== 'function') { votePost(post, val); return; }
    demanderMotif(function(texte, code){ votePost(post, -1, texte, code); });
  });
});
</script>

<?php if ($account): ?>
  <div class="card" style="margin-top:18px">
    <h2><?= h(t('thr.reply.h2')) ?></h2>
    <div id="msg"></div>
    <div class="field">
      <textarea id="contenu" rows="4" placeholder="<?= h(t('thr.reply.ph')) ?>"></textarea>
    </div>
    <button class="btn" id="btn-repondre"><?= h(t('thr.reply.btn')) ?></button>
  </div>
  <script nonce="<?= nonce() ?>">
  function repondre(){
    const c = document.getElementById('contenu').value.trim();
    if(!c){return;}
    labPost('/api/post_create.php',{thread_id:<?= $threadId ?>,message:c})
      .then(d=>{
        if(d.ok){location.reload();}
        else{document.getElementById('msg').innerHTML='<div class="toast err">'+(d.message||'Erreur')+'</div>';}
      });
  }
  document.getElementById('btn-repondre').addEventListener('click', repondre);
  </script>
<?php else: ?>
  <p class="muted" style="text-align:center;margin-top:18px"><?= t('thr.reply.login') ?></p>
<?php endif; ?>
<?php render_footer(); ?>
