const App = {
  token: null,
  user: null,
  csrfPromise: null,
  initialized: false,
  ready: null,

  async ensureCsrf() {
    if (this.token) return this.token;
    if (!this.csrfPromise) {
      this.csrfPromise = fetch('/api/?action=csrf', {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' }
      }).then(async r => {
        const d = await r.json().catch(() => null);
        if (!r.ok || !d?.token) throw new Error(d?.message || 'Could not start a secure session.');
        this.token = d.token;
        return this.token;
      }).finally(() => { this.csrfPromise = null; });
    }
    return this.csrfPromise;
  },

  async init() {
    try {
      await this.ensureCsrf();
      const s = await this.req('session');
      this.user = s.user || null;
    } catch (e) {
      this.user = null;
      // Auth pages should still be usable when there is no session.
      if (!location.pathname.startsWith('/login') && !location.pathname.startsWith('/register')) {
        this.toast(e.message || 'Unable to connect to the server.', 'error');
      }
    }
    this.initialized = true;
    this.paint();
    document.querySelectorAll('[data-logout]').forEach(b => b.onclick = () => this.logout());
    return this.user;
  },

  async req(action, opts = {}) {
    const params = new URLSearchParams();
    params.set('action', action);
    if (opts.query) Object.entries(opts.query).forEach(([k, v]) => params.set(k, v));
    const method = (opts.method || 'GET').toUpperCase();
    const isWrite = !['GET', 'HEAD'].includes(method);
    if (isWrite) await this.ensureCsrf();
    const headers = { Accept: 'application/json' };
    if (opts.body !== undefined) headers['Content-Type'] = 'application/json';
    if (isWrite || opts.csrf) headers['X-CSRF-Token'] = this.token || '';
    const r = await fetch('/api/?' + params.toString(), {
      method,
      credentials: 'same-origin',
      cache: 'no-store',
      headers,
      body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined
    });
    const text = await r.text();
    let d;
    try { d = JSON.parse(text); }
    catch { throw new Error('The server returned an invalid response. Check the PHP terminal for the exact error.'); }
    if (!r.ok || d.ok === false) throw new Error(d.message || 'Request failed.');
    return d;
  },

  paint() {
    document.querySelectorAll('[data-user-name]').forEach(x => x.textContent = this.user?.name || 'Guest');
    document.querySelectorAll('[data-user-role]').forEach(x => x.textContent = this.user?.role === 'assistant' ? 'Librarian' : this.user?.role === 'student' ? 'Student' : '');
    document.querySelectorAll('[data-member-id]').forEach(x => x.textContent = this.user?.student_id || '');
    document.querySelectorAll('[data-librarian-only]').forEach(x => x.classList.toggle('hidden', this.user?.role !== 'assistant'));
    document.querySelectorAll('[data-member-only]').forEach(x => x.classList.toggle('hidden', this.user?.role !== 'student'));
    const avatar = document.querySelector('[data-avatar]');
    if (avatar) avatar.textContent = (this.user?.name || 'G').slice(0, 1).toUpperCase();
  },

  async logout() {
    try { await this.req('logout', { method: 'POST', csrf: true }); }
    catch (_) {}
    finally { location.replace('/login'); }
  },

  toast(msg, type = 'ok') {
    const t = document.querySelector('#toast');
    if (!t) return;
    t.textContent = msg;
    t.className = 'toast show ' + (type === 'error' ? 'toast-error' : '');
    clearTimeout(this._toastTimer);
    this._toastTimer = setTimeout(() => { t.className = 'toast'; }, 3500);
  },
  open(id) { document.getElementById(id)?.classList.remove('hidden'); },
  close(id) { document.getElementById(id)?.classList.add('hidden'); },

  guard(role) {
    if (!this.initialized) return true; // page code runs after App.ready
    if (!this.user) { location.replace('/login'); return false; }
    if (role && this.user.role !== role) { location.replace('/dashboard'); return false; }
    return true;
  },

  redirectAfterAuth(user) {
    if (user?.role === 'student') location.replace('/student-dashboard');
    else if (user?.role === 'assistant') location.replace('/assistant-dashboard');
    else location.replace('/login');
  },

  money(v) { return '₹' + Number(v || 0).toFixed(2); },
  esc(v) { return String(v ?? '').replace(/[&<>'"]/g, m => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;' }[m])); }
};

window.App = App;
App.ready = new Promise(resolve => {
  const start = () => App.init().then(resolve).catch(() => resolve(null));
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
});
