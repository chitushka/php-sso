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
      selectedUser: null,
      selectedUserRoles: [],
      selectedUserGroups: [],
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
