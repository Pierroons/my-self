// JavaScript de l'accueil. La CSP du vhost n'admet que les scripts servis par
// le site : aucun <script> inline ni attribut on*= dans index.php.

function selfjusticeCopy() {
  var txt = document.getElementById('problem-input').value.trim();
  if (!txt) {
    document.getElementById('problem-input').focus();
    return;
  }
  var full = txt + "\n\nAnalyse " + location.host;
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(full).then(function() {
      var fb = document.getElementById('copy-feedback');
      fb.style.display = 'inline';
      setTimeout(function() { fb.style.display = 'none'; }, 4000);
    });
  } else {
    var ta = document.createElement('textarea');
    ta.value = full;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    var fb = document.getElementById('copy-feedback');
    fb.style.display = 'inline';
    setTimeout(function() { fb.style.display = 'none'; }, 4000);
  }
}

document.getElementById('copy-btn').addEventListener('click', selfjusticeCopy);

(function() {
  var form = document.getElementById('feedback-form');
  form.addEventListener('submit', function(ev) {
    ev.preventDefault();
    var fb = document.getElementById('fb-feedback');
    var btn = document.getElementById('fb-submit');
    fb.style.color = 'var(--text-muted)';
    fb.textContent = 'Envoi en cours…';
    btn.disabled = true;
    fetch('/api/feedback', { method: 'POST', body: new FormData(form) })
      .then(function(r) { return r.json().catch(function() { return { ok: false, error: 'Réponse illisible (code ' + r.status + ')' }; }); })
      .then(function(data) {
        if (data.ok) {
          fb.style.color = 'var(--success)';
          fb.textContent = '✓ ' + (data.message || 'Merci, feedback enregistré');
          form.reset();
        } else {
          fb.style.color = 'var(--danger)';
          fb.textContent = '✗ ' + (data.error || 'Erreur inconnue');
        }
      })
      .catch(function(err) {
        fb.style.color = 'var(--danger)';
        fb.textContent = '✗ Erreur réseau';
      })
      .finally(function() { btn.disabled = false; });
  });
})();

// #header-counter garde la valeur rendue par le serveur (requetes_ia) : ce
// script n'y écrit pas, sans quoi la page afficherait deux chiffres différents
// sous « consultations » selon qu'on la lit avec ou sans JavaScript.
(function() {
  fetch('/api/stats/by-ai', { cache: 'no-store' })
    .then(function(r) { return r.ok ? r.json() : null; })
    .then(function(data) {
      if (!data || !data.user_consultations) return;
      var labels = {
        claude: 'Claude',
        chatgpt: 'ChatGPT',
        perplexity: 'Perplexity'
      };
      var fam = data.user_consultations;
      var total = data.user_total || 0;
      var container = document.getElementById('stats-by-ai');
      if (!container) return;
      container.innerHTML = '';
      Object.keys(labels).forEach(function(key) {
        var count = fam[key] || 0;
        var pct = total > 0 ? ((count / total) * 100).toFixed(1) : '0.0';
        var cell = document.createElement('div');
        cell.style.cssText = 'background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 0.6rem 0.8rem;';
        cell.innerHTML =
          '<div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.4px;">' + labels[key] + '</div>' +
          '<div style="font-size: 1.3rem; font-weight: bold; color: var(--accent); margin-top: 0.2rem;">' + count.toLocaleString('fr-FR') + '</div>' +
          '<div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.1rem;">' + pct + ' %</div>';
        container.appendChild(cell);
      });
    })
    .catch(function() {
      var container = document.getElementById('stats-by-ai');
      if (container) container.innerHTML = '<div style="color: var(--text-muted); font-size: 0.85rem; grid-column: 1 / -1;">Statistiques indisponibles pour l\'instant.</div>';
    });
})();
