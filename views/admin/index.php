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
      <button :class="{active: view === 'identity'}" @click="view = 'identity'">Federation</button>
      <button :class="{active: view === 'operations'}" @click="view = 'operations'">Operations</button>
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
        <template v-if="!mfaChallenge">
          <label>Username or email</label>
          <input v-model="loginForm.username">
          <label>Password</label>
          <input type="password" v-model="loginForm.password">
          <button class="primary" @click="login">Login</button>
          <button @click="view = 'forgot'">Forgot password</button>
          <div v-if="brokerProviders.length">
            <p class="muted">External sign-in</p>
            <button v-for="provider in brokerProviders" :key="provider.code" @click="startBrokerLogin(provider.code)">
              Continue with {{ provider.name }}
            </button>
          </div>
        </template>
        <template v-else>
          <p class="muted">Enter TOTP or recovery code.</p>
          <label>MFA code</label>
          <input v-model="mfaLoginForm.code">
          <button class="primary" @click="verifyMfaLogin">Verify MFA</button>
          <button @click="mfaChallenge = ''">Cancel</button>
        </template>
      </div>

      <div class="panel login" v-if="view === 'forgot'">
        <h1>Forgot password</h1>
        <label>Username or email</label>
        <input v-model="forgotForm.login">
        <button class="primary" @click="forgotPassword">Send reset link</button>
        <button @click="view = 'login'">Back to login</button>
      </div>

      <div class="panel login" v-if="view === 'reset'">
        <h1>Reset password</h1>
        <label>Token</label>
        <input v-model="resetForm.token">
        <label>New password</label>
        <input type="password" v-model="resetForm.password">
        <label>Repeat password</label>
        <input type="password" v-model="resetForm.confirm">
        <button class="primary" @click="resetPassword">Save password</button>
        <button @click="view = 'login'">Back to login</button>
      </div>

      <div class="panel login" v-if="view === 'dashboard' && user">
        <h2>Account</h2>
        <p class="muted">{{ user.email }} · {{ user.email_verified ? 'email verified' : 'email not verified' }}</p>
        <button v-if="!user.email_verified" @click="requestEmailVerification">Send verification email</button>
        <div class="grid">
          <div>
            <label>First name</label>
            <input v-model="profileForm.first_name">
          </div>
          <div>
            <label>Last name</label>
            <input v-model="profileForm.last_name">
          </div>
        </div>
        <label>Attributes JSON</label>
        <textarea rows="3" v-model="profileForm.attributesJson"></textarea>
        <button class="primary" @click="updateProfile">Save profile</button>

        <h2>Password</h2>
        <div class="grid">
          <div>
            <label>Current password</label>
            <input type="password" v-model="passwordForm.old_password">
          </div>
          <div>
            <label>New password</label>
            <input type="password" v-model="passwordForm.new_password">
          </div>
          <div>
            <label>Repeat password</label>
            <input type="password" v-model="passwordForm.confirm">
          </div>
        </div>
        <button class="primary" @click="changePassword">Change password</button>

        <h2>Account MFA</h2>
        <p class="muted">{{ user.mfa_enabled ? 'MFA enabled' : 'MFA disabled' }}</p>
        <template v-if="!user.mfa_enabled">
          <button class="primary" @click="setupMfa">Create TOTP secret</button>
          <div v-if="mfaSetup.secret">
            <label>Secret</label>
            <input readonly :value="mfaSetup.secret">
            <label>Verify code</label>
            <input v-model="mfaSetup.code">
            <button class="primary" @click="enableMfa">Enable MFA</button>
            <p class="muted">{{ mfaSetup.otpauth_url }}</p>
          </div>
        </template>
        <template v-else>
          <label>TOTP or recovery code</label>
          <input v-model="mfaManageForm.code">
          <button @click="regenerateRecoveryCodes">Regenerate recovery codes</button>
          <button @click="disableMfa">Disable MFA</button>
        </template>
        <pre v-if="mfaRecoveryCodes.length">{{ mfaRecoveryCodes }}</pre>

        <h2>Active consents</h2>
        <p class="muted" v-if="!consents.length">No active OAuth consents.</p>
        <div class="table-wrap" v-else>
          <table>
            <thead><tr><th>Application</th><th>Scopes</th><th>Updated</th><th>Action</th></tr></thead>
            <tbody>
              <tr v-for="consent in consents" :key="consent.client_id">
                <td>{{ consent.client_name }} ({{ consent.client_id }})</td>
                <td>{{ consent.scopes.join(' ') }}</td>
                <td>{{ consent.updated_at }}</td>
                <td><button @click="revokeConsent(consent.client_id)">Revoke</button></td>
              </tr>
            </tbody>
          </table>
        </div>
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
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Username</th>
                <th>Email</th>
                <th>Status</th>
                <th>Assignments</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in users" :key="item.id">
                <td>{{ item.username }}</td>
                <td>{{ item.email }}</td>
                <td>{{ item.status }}</td>
                <td><button @click="selectUser(item)">Manage roles/groups</button></td>
              </tr>
            </tbody>
          </table>
        </div>

        <section class="assignment" v-if="selectedUser">
          <h2>User assignments: {{ selectedUser.username }}</h2>
          <div class="grid">
            <div>
              <h3>Direct roles</h3>
              <div class="inline-form">
                <select v-model="assignmentForms.userRoleId">
                  <option value="">Select role</option>
                  <option v-for="role in availableUserRoles" :key="role.id" :value="role.id">{{ role.code }}</option>
                </select>
                <button class="primary" @click="assignUserRole">Assign</button>
              </div>
              <ul class="chips">
                <li v-for="role in selectedUserRoles" :key="role.id">
                  {{ role.code }}
                  <button @click="removeUserRole(role.id)">remove</button>
                </li>
              </ul>
            </div>
            <div>
              <h3>Groups</h3>
              <div class="inline-form">
                <select v-model="assignmentForms.userGroupId">
                  <option value="">Select group</option>
                  <option v-for="group in availableUserGroups" :key="group.id" :value="group.id">{{ group.code }}</option>
                </select>
                <button class="primary" @click="assignUserGroup">Assign</button>
              </div>
              <ul class="chips">
                <li v-for="group in selectedUserGroups" :key="group.id">
                  {{ group.code }}
                  <button @click="removeUserGroup(group.id)">remove</button>
                </li>
              </ul>
            </div>
          </div>

          <h3>Federated identity links</h3>
          <p class="muted" v-if="!selectedUserIdentities.length">No federated identities.</p>
          <div class="table-wrap" v-else>
            <table>
              <thead><tr><th>Provider</th><th>Subject</th><th>Email</th><th>Action</th></tr></thead>
              <tbody>
                <tr v-for="identity in selectedUserIdentities" :key="identity.provider_id + ':' + identity.external_subject">
                  <td>{{ identity.provider_name }} ({{ identity.provider_code }})</td>
                  <td>{{ identity.external_subject }}</td>
                  <td>{{ identity.email }}</td>
                  <td><button @click="unlinkFederatedIdentity(identity)">Unlink</button></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
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
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Description</th>
                <th>Permissions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="role in roles" :key="role.id">
                <td>{{ role.code }}</td>
                <td>{{ role.name }}</td>
                <td>{{ role.description }}</td>
                <td><button @click="selectRole(role)">Manage permissions</button></td>
              </tr>
            </tbody>
          </table>
        </div>

        <section class="assignment" v-if="selectedRole">
          <h2>Role permissions: {{ selectedRole.code }}</h2>
          <div class="inline-form">
            <select v-model="assignmentForms.rolePermissionId">
              <option value="">Select permission</option>
              <option v-for="permission in availableRolePermissions" :key="permission.id" :value="permission.id">
                {{ permission.resource }}:{{ permission.action }}
              </option>
            </select>
            <button class="primary" @click="assignRolePermission">Assign</button>
          </div>
          <ul class="chips">
            <li v-for="permission in selectedRolePermissions" :key="permission.id">
              {{ permission.resource }}:{{ permission.action }}
              <button @click="removeRolePermission(permission.id)">remove</button>
            </li>
          </ul>
        </section>
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
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Description</th>
                <th>Roles</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="group in groups" :key="group.id">
                <td>{{ group.code }}</td>
                <td>{{ group.name }}</td>
                <td>{{ group.description }}</td>
                <td><button @click="selectGroup(group)">Manage roles</button></td>
              </tr>
            </tbody>
          </table>
        </div>

        <section class="assignment" v-if="selectedGroup">
          <h2>Group roles: {{ selectedGroup.code }}</h2>
          <div class="inline-form">
            <select v-model="assignmentForms.groupRoleId">
              <option value="">Select role</option>
              <option v-for="role in availableGroupRoles" :key="role.id" :value="role.id">{{ role.code }}</option>
            </select>
            <button class="primary" @click="assignGroupRole">Assign</button>
          </div>
          <ul class="chips">
            <li v-for="role in selectedGroupRoles" :key="role.id">
              {{ role.code }}
              <button @click="removeGroupRole(role.id)">remove</button>
            </li>
          </ul>
        </section>
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

      <div class="panel" v-if="view === 'identity'">
        <h1>Identity providers</h1>
        <p class="muted">Register callback URL as {{ discovery.issuer }}/oauth2/broker/{code}/callback.</p>
        <div class="grid">
          <div>
            <label>Code</label>
            <input v-model="identityForm.code" :disabled="!!identityForm.id">
          </div>
          <div>
            <label>Name</label>
            <input v-model="identityForm.name">
          </div>
          <div>
            <label>Type</label>
            <select v-model="identityForm.type">
              <option value="google">google</option>
              <option value="github">github</option>
              <option value="oidc">oidc</option>
            </select>
          </div>
          <div>
            <label>Client ID</label>
            <input v-model="identityForm.client_id">
          </div>
          <div>
            <label>Client secret</label>
            <input type="password" v-model="identityForm.client_secret">
          </div>
          <div>
            <label>Scopes</label>
            <input v-model="identityForm.scopes">
          </div>
        </div>
        <label><input type="checkbox" v-model="identityForm.enabled"> Enabled</label>
        <div class="grid" v-if="identityForm.type === 'oidc'">
          <div>
            <label>Authorize URL</label>
            <input v-model="identityForm.authorize_url">
          </div>
          <div>
            <label>Token URL</label>
            <input v-model="identityForm.token_url">
          </div>
          <div>
            <label>Userinfo URL</label>
            <input v-model="identityForm.userinfo_url">
          </div>
        </div>
        <button class="primary" @click="saveIdentityProvider">{{ identityForm.id ? 'Update provider' : 'Create provider' }}</button>
        <button @click="resetIdentityForm">Reset form</button>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Type</th>
                <th>Client ID</th>
                <th>Enabled</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="provider in identityProviders" :key="provider.id">
                <td>{{ provider.code }}</td>
                <td>{{ provider.name }}</td>
                <td>{{ provider.type }}</td>
                <td>{{ provider.client_id }}</td>
                <td>{{ provider.enabled ? 'yes' : 'no' }}</td>
                <td>
                  <button @click="editIdentityProvider(provider)">Edit</button>
                  <button @click="deleteIdentityProvider(provider)">Delete</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="panel" v-if="view === 'operations'">
        <h1>Operational status</h1>
        <button class="primary" @click="loadOperations">Refresh status</button>
        <div class="grid" v-if="operationalStatus.status">
          <div class="metric"><strong>Overall</strong><p class="muted">{{ operationalStatus.status }}</p></div>
          <div class="metric"><strong>Database</strong><p class="muted">{{ operationalStatus.database?.ready ? 'ready' : 'unavailable' }}</p></div>
          <div class="metric"><strong>Signing</strong><p class="muted">{{ operationalStatus.signing_keys?.ready ? 'ready' : 'degraded' }}</p></div>
          <div class="metric"><strong>Last cleanup</strong><p class="muted">{{ operationalStatus.cleanup?.last_success_at || 'not recorded' }}</p></div>
          <div class="metric"><strong>Version</strong><p class="muted">{{ operationalStatus.version }} / {{ operationalStatus.environment }}</p></div>
        </div>

        <h2>OIDC signing keys</h2>
        <button class="primary" @click="rotateSigningKey">Rotate signing key</button>
        <div class="table-wrap">
          <table>
            <thead><tr><th>KID</th><th>Algorithm</th><th>Status</th><th>Created</th><th>Expires</th></tr></thead>
            <tbody>
              <tr v-for="key in signingKeys" :key="key.kid">
                <td>{{ key.kid }}</td>
                <td>{{ key.alg }}</td>
                <td>{{ key.status }}</td>
                <td>{{ key.created_at }}</td>
                <td>{{ key.expires_at || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <p class="muted">{{ message }}</p>
    </section>
  </main>
</div>

<script src="/js/vue.global.prod.js"></script>
<script src="/js/admin-api.js"></script>
<script nonce="<?= htmlspecialchars((string)(Yii::$app->params['_cspNonce'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
const app = Vue.createApp({
  data() {
    return {
      view: 'dashboard',
      message: '',
      accessToken: '',
      user: null,
      mfaChallenge: '',
      bootstrap: {},
      discovery: {},
      clients: [],
      users: [],
      roles: [],
      permissions: [],
      groups: [],
      audit: [],
      ldapProviders: [],
      brokerProviders: [],
      identityProviders: [],
      consents: [],
      operationalStatus: {},
      signingKeys: [],
      selectedUser: null,
      selectedUserRoles: [],
      selectedUserGroups: [],
      selectedUserIdentities: [],
      selectedRole: null,
      selectedRolePermissions: [],
      selectedGroup: null,
      selectedGroupRoles: [],
      assignmentForms: {
        userRoleId: '',
        userGroupId: '',
        rolePermissionId: '',
        groupRoleId: ''
      },
      bootstrapForm: { username: 'admin', email: 'admin@example.com', password: '' },
      loginForm: { username: 'admin', password: '' },
      forgotForm: { login: '' },
      resetForm: { token: '', password: '', confirm: '' },
      profileForm: { first_name: '', last_name: '', attributesJson: '{}' },
      passwordForm: { old_password: '', new_password: '', confirm: '' },
      mfaLoginForm: { code: '' },
      mfaSetup: { secret: '', otpauth_url: '', code: '' },
      mfaManageForm: { code: '' },
      mfaRecoveryCodes: [],
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
      },
      identityForm: {
        id: '',
        code: '',
        name: '',
        type: 'google',
        client_id: '',
        client_secret: '',
        authorize_url: '',
        token_url: '',
        userinfo_url: '',
        scopes: 'openid profile email',
        enabled: true
      }
    };
  },
  async mounted() {
    await this.restoreSession();
    const params = new URLSearchParams(window.location.search);
    if (params.get('broker_error')) {
      this.message = 'External sign-in failed';
      history.replaceState({}, document.title, '/');
    }
    if (params.get('token') && window.location.pathname.indexOf('reset-password') !== -1) {
      this.resetForm.token = params.get('token');
      this.view = 'reset';
    }
    if (params.get('token') && window.location.pathname.indexOf('verify-email') !== -1) {
      await this.verifyEmail(params.get('token')).catch(error => {
        this.message = error.message;
      });
    }
    await this.refresh();
  },
  computed: {
    availableUserRoles() {
      const assigned = new Set(this.selectedUserRoles.map(item => item.id));
      return this.roles.filter(item => !assigned.has(item.id));
    },
    availableUserGroups() {
      const assigned = new Set(this.selectedUserGroups.map(item => item.id));
      return this.groups.filter(item => !assigned.has(item.id));
    },
    availableRolePermissions() {
      const assigned = new Set(this.selectedRolePermissions.map(item => item.id));
      return this.permissions.filter(item => !assigned.has(item.id));
    },
    availableGroupRoles() {
      const assigned = new Set(this.selectedGroupRoles.map(item => item.id));
      return this.roles.filter(item => !assigned.has(item.id));
    }
  },
  methods: {
    async restoreSession() {
      try {
        const data = await AdminApi.restoreSession();
        if (!data) return;
        this.accessToken = data.access_token || '';
        this.user = data.user || null;
      } catch (error) {
        this.accessToken = '';
      }
    },
    headers() {
      return AdminApi.headers(this.accessToken);
    },
    async api(url, options = {}) {
      return AdminApi.request(this.accessToken, url, options);
    },
    async apiItems(url) {
      const page = await this.api(url);
      return Array.isArray(page.items) ? page.items : [];
    },
    async refresh() {
      this.bootstrap = await this.api('/api/v1/bootstrap/status');
      this.discovery = await this.api('/.well-known/openid-configuration');
      this.brokerProviders = await this.api('/api/v1/broker/providers').catch(() => []);
      if (this.accessToken) {
        this.user = await this.api('/api/v1/auth/me').catch(() => null);
        this.syncProfileForm();
        this.clients = await this.apiItems('/api/v1/oauth/clients').catch(() => []);
        this.users = await this.apiItems('/api/v1/users').catch(() => []);
        this.roles = await this.apiItems('/api/v1/roles').catch(() => []);
        this.permissions = await this.apiItems('/api/v1/permissions').catch(() => []);
        this.groups = await this.apiItems('/api/v1/groups').catch(() => []);
        this.ldapProviders = await this.apiItems('/api/v1/ldap/providers').catch(() => []);
        this.identityProviders = await this.apiItems('/api/v1/identity-providers').catch(() => []);
        this.consents = await this.api('/api/v1/auth/consents').catch(() => []);
        await this.loadOperations().catch(() => {});
        await this.reloadSelectedAssignments();
      }
    },
    async createAdmin() {
      const data = await this.api('/api/v1/bootstrap', { method: 'POST', body: JSON.stringify(this.bootstrapForm) });
      this.message = 'Admin created: ' + data.username;
      await this.refresh();
    },
    async login() {
      const data = await this.api('/api/v1/auth/login', { method: 'POST', body: JSON.stringify(this.loginForm) });
      if (data.mfa_required) {
        this.mfaChallenge = data.mfa_token;
        this.message = 'MFA verification required';
        return;
      }

      this.accessToken = data.access_token;
      this.user = data.user;
      this.message = 'Signed in';
      await this.refresh();
    },
    startBrokerLogin(code) {
      window.location.href = '/oauth2/broker/' + encodeURIComponent(code) + '/login?continue=/';
    },
    syncProfileForm() {
      if (!this.user) {
        return;
      }

      this.profileForm = {
        first_name: this.user.first_name || '',
        last_name: this.user.last_name || '',
        attributesJson: JSON.stringify(this.user.attributes || {}, null, 2)
      };
    },
    async updateProfile() {
      let attributes = {};
      try {
        attributes = JSON.parse(this.profileForm.attributesJson || '{}');
      } catch (error) {
        this.message = 'Attributes JSON is invalid';
        return;
      }

      this.user = await this.api('/api/v1/auth/profile', {
        method: 'PUT',
        body: JSON.stringify({
          first_name: this.profileForm.first_name,
          last_name: this.profileForm.last_name,
          attributes: attributes
        })
      });
      this.syncProfileForm();
      this.message = 'Profile updated';
    },
    async forgotPassword() {
      await this.api('/api/v1/auth/password/forgot', {
        method: 'POST',
        body: JSON.stringify({ login: this.forgotForm.login })
      });
      this.forgotForm.login = '';
      this.message = 'If the account exists, a reset link was sent.';
      this.view = 'login';
    },
    async resetPassword() {
      if (this.resetForm.password !== this.resetForm.confirm) {
        this.message = 'Passwords do not match';
        return;
      }

      await this.api('/api/v1/auth/password/reset', {
        method: 'POST',
        body: JSON.stringify({ token: this.resetForm.token, password: this.resetForm.password })
      });
      this.resetForm = { token: '', password: '', confirm: '' };
      this.message = 'Password reset. Sign in with the new password.';
      this.view = 'login';
    },
    async changePassword() {
      if (this.passwordForm.new_password !== this.passwordForm.confirm) {
        this.message = 'Passwords do not match';
        return;
      }

      await this.api('/api/v1/auth/password/change', {
        method: 'POST',
        body: JSON.stringify({
          old_password: this.passwordForm.old_password,
          new_password: this.passwordForm.new_password
        })
      });
      this.passwordForm = { old_password: '', new_password: '', confirm: '' };
      this.message = 'Password changed';
    },
    async requestEmailVerification() {
      await this.api('/api/v1/auth/email/request', { method: 'POST' });
      this.message = 'Verification email sent.';
    },
    async verifyEmail(token) {
      await this.api('/api/v1/auth/email/verify', {
        method: 'POST',
        body: JSON.stringify({ token: token })
      });
      this.message = 'Email verified.';
      history.replaceState({}, document.title, '/');
    },
    async verifyMfaLogin() {
      const data = await this.api('/api/v1/auth/mfa/verify', {
        method: 'POST',
        body: JSON.stringify({ mfa_token: this.mfaChallenge, code: this.mfaLoginForm.code })
      });
      this.accessToken = data.access_token;
      this.user = data.user;
      this.mfaChallenge = '';
      this.mfaLoginForm.code = '';
      this.message = 'Signed in';
      await this.refresh();
    },
    async logout() {
      await this.api('/api/v1/auth/logout', { method: 'POST' }).catch(() => {});
      this.accessToken = '';
      this.user = null;
      this.message = 'Signed out';
    },
    async setupMfa() {
      this.mfaSetup = Object.assign({ code: '' }, await this.api('/api/v1/auth/mfa/enroll', { method: 'POST' }));
      this.mfaRecoveryCodes = [];
    },
    async enableMfa() {
      const data = await this.api('/api/v1/auth/mfa/activate', {
        method: 'POST',
        body: JSON.stringify({ code: this.mfaSetup.code })
      });
      this.mfaRecoveryCodes = data.recovery_codes || [];
      this.mfaSetup = { secret: '', otpauth_url: '', code: '' };
      this.message = 'MFA enabled. Save recovery codes now: ' + this.mfaRecoveryCodes.join(', ') + '. Sign in again.';
      this.accessToken = '';
      this.user = null;
    },
    async disableMfa() {
      await this.api('/api/v1/auth/mfa/disable', {
        method: 'POST',
        body: JSON.stringify({ code: this.mfaManageForm.code })
      });
      this.mfaManageForm.code = '';
      this.mfaRecoveryCodes = [];
      this.message = 'MFA disabled. Sign in again.';
      this.accessToken = '';
      this.user = null;
    },
    async regenerateRecoveryCodes() {
      const data = await this.api('/api/v1/auth/mfa/recovery-codes', {
        method: 'POST',
        body: JSON.stringify({ code: this.mfaManageForm.code })
      });
      this.mfaManageForm.code = '';
      this.mfaRecoveryCodes = data.recovery_codes || [];
      this.message = 'Recovery codes regenerated. Save them now.';
    },
    async createClient() {
      const payload = {
        name: this.clientForm.name,
        client_id: this.clientForm.client_id,
        type: this.clientForm.type,
        redirect_uris: this.clientForm.redirects.split('\n').map(v => v.trim()).filter(Boolean),
        allowed_scopes: ['openid', 'profile', 'email'],
        enabled: true
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
    async selectUser(user) {
      this.selectedUser = user;
      await this.loadUserAssignments();
    },
    async loadUserAssignments() {
      if (!this.selectedUser) {
        return;
      }

      this.selectedUserRoles = await this.api('/api/v1/users/' + this.selectedUser.id + '/roles');
      this.selectedUserGroups = await this.api('/api/v1/users/' + this.selectedUser.id + '/groups');
      this.selectedUserIdentities = await this.api('/api/v1/users/' + this.selectedUser.id + '/federated-identities');
    },
    async assignUserRole() {
      if (!this.selectedUser || !this.assignmentForms.userRoleId) {
        return;
      }

      await this.api('/api/v1/users/' + this.selectedUser.id + '/roles', {
        method: 'POST',
        body: JSON.stringify({ role_id: this.assignmentForms.userRoleId })
      });
      this.assignmentForms.userRoleId = '';
      this.message = 'User role assigned';
      await this.loadUserAssignments();
    },
    async removeUserRole(roleId) {
      if (!this.selectedUser) {
        return;
      }

      await this.api('/api/v1/users/' + this.selectedUser.id + '/roles/' + roleId, { method: 'DELETE' });
      this.message = 'User role removed';
      await this.loadUserAssignments();
    },
    async assignUserGroup() {
      if (!this.selectedUser || !this.assignmentForms.userGroupId) {
        return;
      }

      await this.api('/api/v1/users/' + this.selectedUser.id + '/groups', {
        method: 'POST',
        body: JSON.stringify({ group_id: this.assignmentForms.userGroupId })
      });
      this.assignmentForms.userGroupId = '';
      this.message = 'User group assigned';
      await this.loadUserAssignments();
    },
    async removeUserGroup(groupId) {
      if (!this.selectedUser) {
        return;
      }

      await this.api('/api/v1/users/' + this.selectedUser.id + '/groups/' + groupId, { method: 'DELETE' });
      this.message = 'User group removed';
      await this.loadUserAssignments();
    },
    async unlinkFederatedIdentity(identity) {
      if (!this.selectedUser) return;
      await this.api('/api/v1/users/' + this.selectedUser.id + '/federated-identities', {
        method: 'DELETE',
        body: JSON.stringify({
          provider_id: identity.provider_id,
          external_subject: identity.external_subject
        })
      });
      this.message = 'Federated identity unlinked';
      await this.loadUserAssignments();
    },
    async selectRole(role) {
      this.selectedRole = role;
      await this.loadRolePermissions();
    },
    async loadRolePermissions() {
      if (!this.selectedRole) {
        return;
      }

      this.selectedRolePermissions = await this.api('/api/v1/roles/' + this.selectedRole.id + '/permissions');
    },
    async assignRolePermission() {
      if (!this.selectedRole || !this.assignmentForms.rolePermissionId) {
        return;
      }

      await this.api('/api/v1/roles/' + this.selectedRole.id + '/permissions', {
        method: 'POST',
        body: JSON.stringify({ permission_id: this.assignmentForms.rolePermissionId })
      });
      this.assignmentForms.rolePermissionId = '';
      this.message = 'Role permission assigned';
      await this.loadRolePermissions();
    },
    async removeRolePermission(permissionId) {
      if (!this.selectedRole) {
        return;
      }

      await this.api('/api/v1/roles/' + this.selectedRole.id + '/permissions/' + permissionId, { method: 'DELETE' });
      this.message = 'Role permission removed';
      await this.loadRolePermissions();
    },
    async selectGroup(group) {
      this.selectedGroup = group;
      await this.loadGroupRoles();
    },
    async loadGroupRoles() {
      if (!this.selectedGroup) {
        return;
      }

      this.selectedGroupRoles = await this.api('/api/v1/groups/' + this.selectedGroup.id + '/roles');
    },
    async assignGroupRole() {
      if (!this.selectedGroup || !this.assignmentForms.groupRoleId) {
        return;
      }

      await this.api('/api/v1/groups/' + this.selectedGroup.id + '/roles', {
        method: 'POST',
        body: JSON.stringify({ role_id: this.assignmentForms.groupRoleId })
      });
      this.assignmentForms.groupRoleId = '';
      this.message = 'Group role assigned';
      await this.loadGroupRoles();
    },
    async removeGroupRole(roleId) {
      if (!this.selectedGroup) {
        return;
      }

      await this.api('/api/v1/groups/' + this.selectedGroup.id + '/roles/' + roleId, { method: 'DELETE' });
      this.message = 'Group role removed';
      await this.loadGroupRoles();
    },
    async reloadSelectedAssignments() {
      if (this.selectedUser) {
        const freshUser = this.users.find(item => item.id === this.selectedUser.id);
        this.selectedUser = freshUser || null;
        if (this.selectedUser) {
          await this.loadUserAssignments();
        }
      }

      if (this.selectedRole) {
        const freshRole = this.roles.find(item => item.id === this.selectedRole.id);
        this.selectedRole = freshRole || null;
        if (this.selectedRole) {
          await this.loadRolePermissions();
        }
      }

      if (this.selectedGroup) {
        const freshGroup = this.groups.find(item => item.id === this.selectedGroup.id);
        this.selectedGroup = freshGroup || null;
        if (this.selectedGroup) {
          await this.loadGroupRoles();
        }
      }
    },
    async loadAudit() {
      this.audit = await this.apiItems('/api/v1/audit');
    },
    async revokeConsent(clientId) {
      await this.api('/api/v1/auth/consents/' + encodeURIComponent(clientId), { method: 'DELETE' });
      this.consents = await this.api('/api/v1/auth/consents');
      this.message = 'Consent revoked';
    },
    async loadOperations() {
      this.operationalStatus = await this.api('/api/v1/operations/status');
      this.signingKeys = await this.api('/api/v1/operations/signing-keys');
    },
    async rotateSigningKey() {
      const key = await this.api('/api/v1/operations/signing-keys/rotate', {
        method: 'POST',
        body: '{}'
      });
      this.message = 'Signing key rotated: ' + key.kid;
      await this.loadOperations();
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
    },
    resetIdentityForm() {
      this.identityForm = {
        id: '',
        code: '',
        name: '',
        type: 'google',
        client_id: '',
        client_secret: '',
        authorize_url: '',
        token_url: '',
        userinfo_url: '',
        scopes: 'openid profile email',
        enabled: true
      };
    },
    editIdentityProvider(provider) {
      this.identityForm = Object.assign({}, provider, { client_secret: '' });
    },
    async saveIdentityProvider() {
      const payload = Object.assign({}, this.identityForm);
      const url = payload.id ? '/api/v1/identity-providers/' + payload.id : '/api/v1/identity-providers';
      const method = payload.id ? 'PUT' : 'POST';
      delete payload.id;
      const data = await this.api(url, { method: method, body: JSON.stringify(payload) });
      this.message = 'Identity provider saved: ' + data.code;
      this.resetIdentityForm();
      await this.refresh();
    },
    async deleteIdentityProvider(provider) {
      await this.api('/api/v1/identity-providers/' + provider.id, { method: 'DELETE' });
      this.message = 'Identity provider deleted';
      await this.refresh();
    }
  }
});

app.mount('#app');
</script>
