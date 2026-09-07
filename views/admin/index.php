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
      <button :class="{active: view === 'users'}" @click="view = 'users'">Users</button>
      <button :class="{active: view === 'roles'}" @click="view = 'roles'">Roles</button>
      <button :class="{active: view === 'groups'}" @click="view = 'groups'">Groups</button>
      <button :class="{active: view === 'ldap'}" @click="view = 'ldap'">LDAP</button>
      <button :class="{active: view === 'audit'}" @click="view = 'audit'">Audit</button>
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
          <div class="metric">
            <strong>Users</strong>
            <p class="muted">{{ users.length }}</p>
          </div>
          <div class="metric">
            <strong>Roles</strong>
            <p class="muted">{{ roles.length }}</p>
          </div>
          <div class="metric">
            <strong>LDAP providers</strong>
            <p class="muted">{{ ldapProviders.length }}</p>
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

      <div class="panel" v-if="view === 'users'">
        <h1>Users</h1>
        <div class="grid">
          <div>
            <label>Username</label>
            <input v-model="userForm.username">
          </div>
          <div>
            <label>Email</label>
            <input v-model="userForm.email">
          </div>
          <div>
            <label>Password</label>
            <input type="password" v-model="userForm.password">
          </div>
        </div>
        <button class="primary" @click="createUser">Create user</button>
        <pre>{{ users }}</pre>
      </div>

      <div class="panel" v-if="view === 'roles'">
        <h1>Roles</h1>
        <div class="grid">
          <div>
            <label>Code</label>
            <input v-model="roleForm.code">
          </div>
          <div>
            <label>Name</label>
            <input v-model="roleForm.name">
          </div>
        </div>
        <label>Description</label>
        <textarea rows="2" v-model="roleForm.description"></textarea>
        <button class="primary" @click="createRole">Create role</button>
        <pre>{{ roles }}</pre>
        <h2>Permissions</h2>
        <pre>{{ permissions }}</pre>
      </div>

      <div class="panel" v-if="view === 'groups'">
        <h1>Groups</h1>
        <div class="grid">
          <div>
            <label>Code</label>
            <input v-model="groupForm.code">
          </div>
          <div>
            <label>Name</label>
            <input v-model="groupForm.name">
          </div>
        </div>
        <label>Description</label>
        <textarea rows="2" v-model="groupForm.description"></textarea>
        <button class="primary" @click="createGroup">Create group</button>
        <pre>{{ groups }}</pre>
      </div>

      <div class="panel" v-if="view === 'audit'">
        <h1>Audit</h1>
        <button class="primary" @click="loadAudit">Refresh audit</button>
        <pre>{{ audit }}</pre>
      </div>

      <div class="panel" v-if="view === 'ldap'">
        <h1>LDAP providers</h1>
        <div class="grid">
          <div>
            <label>Name</label>
            <input v-model="ldapForm.name">
          </div>
          <div>
            <label>Host</label>
            <input v-model="ldapForm.host">
          </div>
          <div>
            <label>Port</label>
            <input type="number" v-model.number="ldapForm.port">
          </div>
          <div>
            <label>Bind DN</label>
            <input v-model="ldapForm.bind_dn">
          </div>
          <div>
            <label>Bind password</label>
            <input type="password" v-model="ldapForm.bind_password">
          </div>
          <div>
            <label>Base DN</label>
            <input v-model="ldapForm.base_dn">
          </div>
        </div>
        <label>User filter</label>
        <input v-model="ldapForm.user_filter">
        <div class="grid">
          <label><input type="checkbox" v-model="ldapForm.use_tls"> Use TLS</label>
          <label><input type="checkbox" v-model="ldapForm.start_tls"> StartTLS</label>
          <label><input type="checkbox" v-model="ldapForm.enabled"> Enabled</label>
        </div>
        <button class="primary" @click="testLdap">Test</button>
        <button class="primary" @click="createLdapProvider">Create provider</button>
        <pre>{{ ldapProviders }}</pre>
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
      users: [],
      roles: [],
      permissions: [],
      groups: [],
      audit: [],
      ldapProviders: [],
      bootstrapForm: { username: 'admin', email: 'admin@example.com', password: '' },
      loginForm: { username: 'admin', password: '' },
      clientForm: { name: 'Demo application', client_id: 'demo', type: 'confidential', redirects: 'http://localhost:3000/callback' },
      userForm: { username: '', email: '', password: '' },
      roleForm: { code: '', name: '', description: '' },
      groupForm: { code: '', name: '', description: '' },
      ldapForm: {
        name: '',
        host: '',
        port: 389,
        use_tls: false,
        start_tls: false,
        bind_dn: '',
        bind_password: '',
        base_dn: '',
        user_filter: '(&(objectClass=user)(sAMAccountName={username}))',
        username_attribute: 'sAMAccountName',
        email_attribute: 'mail',
        display_name_attribute: 'displayName',
        group_attribute: 'memberOf',
        enabled: true
      }
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
        this.users = await this.api('/api/v1/users').catch(() => []);
        this.roles = await this.api('/api/v1/roles').catch(() => []);
        this.permissions = await this.api('/api/v1/permissions').catch(() => []);
        this.groups = await this.api('/api/v1/groups').catch(() => []);
        this.ldapProviders = await this.api('/api/v1/ldap/providers').catch(() => []);
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
    },
    async createUser() {
      const data = await this.api('/api/v1/users', { method: 'POST', body: JSON.stringify(this.userForm) });
      this.message = 'User created: ' + data.username;
      this.userForm = { username: '', email: '', password: '' };
      await this.refresh();
    },
    async createRole() {
      const data = await this.api('/api/v1/roles', { method: 'POST', body: JSON.stringify(this.roleForm) });
      this.message = 'Role created: ' + data.code;
      this.roleForm = { code: '', name: '', description: '' };
      await this.refresh();
    },
    async createGroup() {
      const data = await this.api('/api/v1/groups', { method: 'POST', body: JSON.stringify(this.groupForm) });
      this.message = 'Group created: ' + data.code;
      this.groupForm = { code: '', name: '', description: '' };
      await this.refresh();
    },
    async loadAudit() {
      this.audit = await this.api('/api/v1/audit');
    },
    async testLdap() {
      await this.api('/api/v1/ldap/providers/test', { method: 'POST', body: JSON.stringify(this.ldapForm) });
      this.message = 'LDAP connection is ok';
    },
    async createLdapProvider() {
      const data = await this.api('/api/v1/ldap/providers', { method: 'POST', body: JSON.stringify(this.ldapForm) });
      this.message = 'LDAP provider created: ' + data.name;
      this.ldapForm.name = '';
      this.ldapForm.bind_password = '';
      await this.refresh();
    }
  }
});

app.mount('#app');
</script>
