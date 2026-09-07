<?php

$this->title = 'SSO Admin';
?>
<div id="app">
  <header class="topbar">
    <div class="brand">SSO Admin</div>
    <div>{{ user ? user.username : 'not signed in' }}</div>
  </header>

  <main class="shell">
    <nav class="sidebar">
      <button :class="{active: view === 'dashboard'}" @click="view = 'dashboard'">Dashboard</button>
      <button :class="{active: view === 'bootstrap'}" @click="view = 'bootstrap'">Bootstrap</button>
      <button :class="{active: view === 'login'}" @click="view = 'login'">Login</button>
      <button :class="{active: view === 'clients'}" @click="view = 'clients'">OAuth Clients</button>
      <button class="primary" @click="logout">Logout</button>
    </nav>

    <section class="content">
      <div class="panel" v-if="view === 'dashboard'">
        <h1>Dashboard</h1>
        <div class="grid">
          <div class="metric">
            <strong>Bootstrap</strong>
            <p class="muted">{{ bootstrap.initialized ? 'initialized' : 'not initialized' }}</p>
          </div>
          <div class="metric">
            <strong>Issuer</strong>
            <p class="muted">{{ discovery.issuer || 'loading' }}</p>
          </div>
          <div class="metric">
            <strong>Clients</strong>
            <p class="muted">{{ clients.length }}</p>
          </div>
        </div>
      </div>

      <div class="panel login" v-if="view === 'bootstrap'">
        <h1>Bootstrap admin</h1>
        <label>Username</label>
        <input v-model="bootstrapForm.username">
        <label>Email</label>
        <input v-model="bootstrapForm.email">
        <label>Password</label>
        <input type="password" v-model="bootstrapForm.password">
        <button class="primary" @click="createAdmin">Create admin</button>
      </div>

      <div class="panel login" v-if="view === 'login'">
        <h1>Login</h1>
        <label>Username or email</label>
        <input v-model="loginForm.username">
        <label>Password</label>
        <input type="password" v-model="loginForm.password">
        <button class="primary" @click="login">Login</button>
      </div>

      <div class="panel" v-if="view === 'clients'">
        <h1>OAuth clients</h1>
        <div class="grid">
          <div>
            <label>Name</label>
            <input v-model="clientForm.name">
          </div>
          <div>
            <label>Client ID</label>
            <input v-model="clientForm.client_id">
          </div>
          <div>
            <label>Type</label>
            <select v-model="clientForm.type">
              <option value="confidential">confidential</option>
              <option value="public">public</option>
            </select>
          </div>
        </div>
        <label>Redirect URIs, one per line</label>
        <textarea rows="3" v-model="clientForm.redirects"></textarea>
        <button class="primary" @click="createClient">Create client</button>
        <pre>{{ clients }}</pre>
      </div>

      <p class="muted">{{ message }}</p>
    </section>
  </main>
</div>

<script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
<script>
const app = Vue.createApp({
  data() {
    return {
      view: 'dashboard',
      message: '',
      accessToken: localStorage.getItem('sso_access_token') || '',
      user: null,
      bootstrap: {},
      discovery: {},
      clients: [],
      bootstrapForm: { username: 'admin', email: 'admin@example.com', password: '' },
      loginForm: { username: 'admin', password: '' },
      clientForm: { name: 'Demo application', client_id: 'demo', type: 'confidential', redirects: 'http://localhost:3000/callback' }
    };
  },
  mounted() {
    this.refresh();
  },
  methods: {
    headers() {
      return this.accessToken ? { 'Authorization': 'Bearer ' + this.accessToken, 'Content-Type': 'application/json' } : { 'Content-Type': 'application/json' };
    },
    async api(url, options = {}) {
      const response = await fetch(url, Object.assign({ headers: this.headers() }, options));
      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(data.error || data.error_description || 'request failed');
      }
      return data;
    },
    async refresh() {
      this.bootstrap = await this.api('/api/v1/bootstrap/status');
      this.discovery = await this.api('/.well-known/openid-configuration');
      if (this.accessToken) {
        this.user = await this.api('/api/v1/auth/me').catch(() => null);
        this.clients = await this.api('/api/v1/oauth/clients').catch(() => []);
      }
    },
    async createAdmin() {
      const data = await this.api('/api/v1/bootstrap', { method: 'POST', body: JSON.stringify(this.bootstrapForm) });
      this.message = 'Admin created: ' + data.username;
      await this.refresh();
    },
    async login() {
      const data = await this.api('/api/v1/auth/login', { method: 'POST', body: JSON.stringify(this.loginForm) });
      this.accessToken = data.access_token;
      localStorage.setItem('sso_access_token', this.accessToken);
      this.user = data.user;
      this.message = 'Signed in';
      await this.refresh();
    },
    async logout() {
      await this.api('/api/v1/auth/logout', { method: 'POST' }).catch(() => {});
      this.accessToken = '';
      this.user = null;
      localStorage.removeItem('sso_access_token');
      this.message = 'Signed out';
    },
    async createClient() {
      const payload = {
        name: this.clientForm.name,
        client_id: this.clientForm.client_id,
        type: this.clientForm.type,
        redirect_uris: this.clientForm.redirects.split('\n').map(v => v.trim()).filter(Boolean),
        allowed_scopes: ['openid', 'profile', 'email']
      };
      const data = await this.api('/api/v1/oauth/clients', { method: 'POST', body: JSON.stringify(payload) });
      this.message = data.client_secret ? 'Client secret: ' + data.client_secret : 'Client created';
      await this.refresh();
    }
  }
});

app.mount('#app');
</script>
