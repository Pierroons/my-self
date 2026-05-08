/* SelfDataGuard demo — vanilla JS to drive the API endpoints */

const $ = (sel) => document.querySelector(sel);

async function call(endpoint, body, method = 'POST') {
  const opts = { method, headers: { 'Content-Type': 'application/json' } };
  if (body && method === 'POST') opts.body = JSON.stringify(body);
  try {
    const res = await fetch('api/' + endpoint, opts);
    const data = await res.json().catch(() => ({}));
    return { ok: res.ok, status: res.status, data };
  } catch (e) {
    return { ok: false, status: 0, data: { error: String(e) } };
  }
}

function show(target, result) {
  const el = $(target);
  el.classList.remove('ok', 'err');
  el.classList.add(result.ok ? 'ok' : 'err');
  el.textContent = JSON.stringify(result.data, null, 2);
}

function formData(form) {
  const fd = new FormData(form);
  const obj = {};
  fd.forEach((v, k) => { obj[k] = String(v); });
  return obj;
}

// -- Register ----------------------------------------------------------------

$('#register-form').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const f = formData(ev.target);
  const fields = {};
  ['email', 'phone', 'address', 'iban'].forEach((k) => {
    if (f[k]) fields[k] = f[k];
  });
  const res = await call('register.php', {
    userId: f.userId,
    password: f.password,
    memorized: f.memorized || null,
    fields,
    indexed: ['email', 'phone'],
  });
  show('#register-result', res);
});

// -- Login : adapt label to selected mode ------------------------------------

const loginMode = $('#login-mode');
const loginLabel = $('#login-secret-label');
const loginInput = $('#login-secret');

function syncLoginLabel() {
  if (loginMode.value === 'memorized') {
    loginLabel.firstChild.textContent = 'Mot mémorisé ';
    loginInput.placeholder = 'le(s) mot(s) de récupération choisi(s) à l\'inscription';
  } else {
    loginLabel.firstChild.textContent = 'Mot de passe ';
    loginInput.placeholder = 'votre mot de passe ≥12 caractères';
  }
  loginInput.value = '';
}
loginMode.addEventListener('change', syncLoginLabel);
syncLoginLabel();

// -- Login -------------------------------------------------------------------

$('#login-form').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const f = formData(ev.target);
  const res = await call('login.php', {
    userId: f.userId,
    secret: f.secret,
    mode: f.mode,
  });
  show('#login-result', res);
});

// -- Find by indexed field ---------------------------------------------------

$('#find-form').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const f = formData(ev.target);
  const res = await call('find_user.php', {
    fieldName: f.fieldName,
    value: f.value,
  });
  // Surface the actual outcome (found / not found) more clearly
  const el = $('#find-result');
  el.classList.remove('ok', 'err');
  if (!res.ok) {
    el.classList.add('err');
    el.textContent = JSON.stringify(res.data, null, 2);
  } else if (res.data.userId) {
    el.classList.add('ok');
    el.textContent = `✅ TROUVÉ — userId = "${res.data.userId}"\n\nRéponse complète :\n` + JSON.stringify(res.data, null, 2);
  } else {
    el.classList.add('err');
    el.textContent = `❌ NON TROUVÉ — aucun utilisateur n'a "${f.fieldName}" = "${f.value}" en index.\n\nRéponse complète :\n` + JSON.stringify(res.data, null, 2);
  }
});

// -- Rotate password ---------------------------------------------------------

$('#rotate-form').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const f = formData(ev.target);
  const res = await call('change_password.php', {
    userId: f.userId,
    oldPassword: f.oldPassword,
    newPassword: f.newPassword,
  });
  show('#rotate-result', res);
});

// -- Inspect DB --------------------------------------------------------------

async function refreshInspector() {
  const res = await call('inspect_db.php', null, 'GET');
  show('#inspect-result', res);
}

$('#inspect-btn').addEventListener('click', refreshInspector);

// Refresh the backend view after every action (decoupled from each handler)
['#register-form', '#login-form', '#find-form', '#rotate-form', '#delete-form']
  .forEach((sel) => {
    const form = document.querySelector(sel);
    if (form) form.addEventListener('submit', () => setTimeout(refreshInspector, 100));
  });

// -- Delete ------------------------------------------------------------------

$('#delete-form').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const f = formData(ev.target);
  if (!confirm(`Vraiment supprimer l'utilisateur "${f.userId}" et tous ses champs chiffrés ?`)) return;
  const res = await call('delete.php', {
    userId: f.userId,
    password: f.password,
  });
  show('#delete-result', res);
});

// Auto-load DB inspection on page open
window.addEventListener('DOMContentLoaded', refreshInspector);
