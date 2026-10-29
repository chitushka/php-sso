<?php

$this->title = 'SSO Admin';
?>
<div id="app">
  <a class="skip-link" href="#admin-content">Skip to main content</a>
  <header class="topbar">
    <div class="brand">SSO Admin</div>
    <div aria-live="polite">{{ user ? user.username : 'not signed in' }}</div>
  </header>

  <main class="shell">
    <admin-navigation :view="view" @navigate="navigate" @logout="logout"></admin-navigation>

    <section
      id="admin-content"
      ref="adminContent"
      class="content"
      tabindex="-1"
      :aria-busy="isBusy ? 'true' : 'false'"
    >
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
        <label for="bootstrap-username">Username</label>
        <input id="bootstrap-username" v-model="bootstrapForm.username" autocomplete="username">
        <label for="bootstrap-email">Email</label>
        <input id="bootstrap-email" type="email" v-model="bootstrapForm.email" autocomplete="email">
        <label for="bootstrap-password">Password</label>
        <input id="bootstrap-password" type="password" v-model="bootstrapForm.password" autocomplete="new-password">
        <button class="primary" @click="createAdmin">Create admin</button>
      </div>

      <div class="panel login" v-if="view === 'login'">
        <h1>Login</h1>
        <template v-if="!mfaChallenge">
          <label for="login-username">Username or email</label>
          <input id="login-username" v-model="loginForm.username" autocomplete="username">
          <label for="login-password">Password</label>
          <input id="login-password" type="password" v-model="loginForm.password" autocomplete="current-password">
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
          <label for="login-mfa-code">MFA code</label>
          <input id="login-mfa-code" v-model="mfaLoginForm.code" inputmode="numeric" autocomplete="one-time-code">
          <button class="primary" @click="verifyMfaLogin">Verify MFA</button>
          <button @click="mfaChallenge = ''">Cancel</button>
        </template>
      </div>

      <div class="panel login" v-if="view === 'forgot'">
        <h1>Forgot password</h1>
        <label for="forgot-login">Username or email</label>
        <input id="forgot-login" v-model="forgotForm.login" autocomplete="username">
        <button class="primary" @click="forgotPassword">Send reset link</button>
        <button @click="view = 'login'">Back to login</button>
      </div>

      <div class="panel login" v-if="view === 'reset'">
        <h1>Reset password</h1>
        <label for="reset-token">Token</label>
        <input id="reset-token" v-model="resetForm.token" autocomplete="off">
        <label for="reset-password">New password</label>
        <input id="reset-password" type="password" v-model="resetForm.password" autocomplete="new-password">
        <label for="reset-password-confirm">Repeat password</label>
        <input id="reset-password-confirm" type="password" v-model="resetForm.confirm" autocomplete="new-password">
        <button class="primary" @click="resetPassword">Save password</button>
        <button @click="view = 'login'">Back to login</button>
      </div>

      <div class="panel login" v-if="view === 'dashboard' && user">
        <h2>Account</h2>
        <p class="muted">{{ user.email }} · {{ user.email_verified ? 'email verified' : 'email not verified' }}</p>
        <button v-if="!user.email_verified" @click="requestEmailVerification">Send verification email</button>
        <div class="grid">
          <div>
            <label for="profile-first-name">First name</label>
            <input id="profile-first-name" v-model="profileForm.first_name" autocomplete="given-name">
          </div>
          <div>
            <label for="profile-last-name">Last name</label>
            <input id="profile-last-name" v-model="profileForm.last_name" autocomplete="family-name">
          </div>
        </div>
        <label for="profile-attributes">Attributes JSON</label>
        <textarea id="profile-attributes" rows="3" v-model="profileForm.attributesJson"></textarea>
        <button class="primary" @click="updateProfile">Save profile</button>

        <h2>Password</h2>
        <div class="grid">
          <div>
            <label for="current-password">Current password</label>
            <input id="current-password" type="password" v-model="passwordForm.old_password" autocomplete="current-password">
          </div>
          <div>
            <label for="new-password">New password</label>
            <input id="new-password" type="password" v-model="passwordForm.new_password" autocomplete="new-password">
          </div>
          <div>
            <label for="new-password-confirm">Repeat password</label>
            <input id="new-password-confirm" type="password" v-model="passwordForm.confirm" autocomplete="new-password">
          </div>
        </div>
        <button class="primary" @click="changePassword">Change password</button>

        <h2>Account MFA</h2>
        <p class="muted">{{ user.mfa_enabled ? 'MFA enabled' : 'MFA disabled' }}</p>
        <template v-if="!user.mfa_enabled">
          <button class="primary" @click="setupMfa">Create TOTP secret</button>
          <div v-if="mfaSetup.secret">
            <label for="mfa-secret">Secret</label>
            <input id="mfa-secret" readonly :value="mfaSetup.secret">
            <label for="mfa-verify-code">Verify code</label>
            <input id="mfa-verify-code" v-model="mfaSetup.code" inputmode="numeric" autocomplete="one-time-code">
            <button class="primary" @click="enableMfa">Enable MFA</button>
            <p class="muted">{{ mfaSetup.otpauth_url }}</p>
          </div>
        </template>
        <template v-else>
          <label for="mfa-manage-code">TOTP or recovery code</label>
          <input id="mfa-manage-code" v-model="mfaManageForm.code" autocomplete="one-time-code">
          <button :disabled="isBusy" @click="regenerateRecoveryCodes">Regenerate recovery codes</button>
          <button :disabled="isBusy" @click="disableMfa">Disable MFA</button>
        </template>
        <pre v-if="mfaRecoveryCodes.length">{{ mfaRecoveryCodes }}</pre>

        <h2>Active consents</h2>
        <p class="muted" v-if="!consents.length">No active OAuth consents.</p>
        <div class="table-wrap" v-else>
          <table>
            <caption class="sr-only">Active OAuth consents</caption>
            <thead><tr><th scope="col">Application</th><th scope="col">Scopes</th><th scope="col">Updated</th><th scope="col">Action</th></tr></thead>
            <tbody>
              <tr v-for="consent in consents" :key="consent.client_id">
                <td>{{ consent.client_name }} ({{ consent.client_id }})</td>
                <td>{{ consent.scopes.join(' ') }}</td>
                <td>{{ consent.updated_at }}</td>
                <td><button :disabled="isBusy" :aria-label="'Revoke consent for ' + consent.client_name" @click="revokeConsent(consent.client_id)">Revoke</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="panel" v-if="view === 'clients'">
        <h1>OAuth clients</h1>
        <div class="grid">
          <div>
            <label for="client-name">Name</label>
            <input id="client-name" v-model="clientForm.name">
          </div>
          <div>
            <label for="client-id">Client ID</label>
            <input id="client-id" v-model="clientForm.client_id" autocomplete="off">
          </div>
          <div>
            <label for="client-type">Type</label>
            <select id="client-type" v-model="clientForm.type">
              <option value="confidential">confidential</option>
              <option value="public">public</option>
            </select>
          </div>
        </div>
        <label for="client-redirects">Redirect URIs, one per line</label>
        <textarea id="client-redirects" rows="3" v-model="clientForm.redirects"></textarea>
        <button class="primary" @click="createClient">Create client</button>
        <pre>{{ clients }}</pre>
      </div>

      <div class="panel" v-if="view === 'users'">
        <h1>Users</h1>
        <div class="grid">
          <div>
            <label for="user-username">Username</label>
            <input id="user-username" v-model="userForm.username" autocomplete="off">
          </div>
          <div>
            <label for="user-email">Email</label>
            <input id="user-email" type="email" v-model="userForm.email" autocomplete="off">
          </div>
          <div>
            <label for="user-password">Password</label>
            <input id="user-password" type="password" v-model="userForm.password" autocomplete="new-password">
          </div>
        </div>
        <button class="primary" @click="createUser">Create user</button>
        <div class="table-wrap">
          <table>
            <caption class="sr-only">Users</caption>
            <thead>
              <tr>
                <th scope="col">Username</th>
                <th scope="col">Email</th>
                <th scope="col">Status</th>
                <th scope="col">Assignments</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in users" :key="item.id">
                <td>{{ item.username }}</td>
                <td>{{ item.email }}</td>
                <td>{{ item.status }}</td>
                <td><button :aria-label="'Manage roles and groups for ' + item.username" @click="selectUser(item)">Manage roles/groups</button></td>
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
                <label class="sr-only" for="user-role-assignment">Role to assign</label>
                <select id="user-role-assignment" v-model="assignmentForms.userRoleId">
                  <option value="">Select role</option>
                  <option v-for="role in availableUserRoles" :key="role.id" :value="role.id">{{ role.code }}</option>
                </select>
                <button class="primary" @click="assignUserRole">Assign</button>
              </div>
              <ul class="chips">
                <li v-for="role in selectedUserRoles" :key="role.id">
                  {{ role.code }}
                  <button :disabled="isBusy" :aria-label="'Remove role ' + role.code" @click="removeUserRole(role.id)">remove</button>
                </li>
              </ul>
            </div>
            <div>
              <h3>Groups</h3>
              <div class="inline-form">
                <label class="sr-only" for="user-group-assignment">Group to assign</label>
                <select id="user-group-assignment" v-model="assignmentForms.userGroupId">
                  <option value="">Select group</option>
                  <option v-for="group in availableUserGroups" :key="group.id" :value="group.id">{{ group.code }}</option>
                </select>
                <button class="primary" @click="assignUserGroup">Assign</button>
              </div>
              <ul class="chips">
                <li v-for="group in selectedUserGroups" :key="group.id">
                  {{ group.code }}
                  <button :disabled="isBusy" :aria-label="'Remove group ' + group.code" @click="removeUserGroup(group.id)">remove</button>
                </li>
              </ul>
            </div>
          </div>

          <h3>Federated identity links</h3>
          <p class="muted" v-if="!selectedUserIdentities.length">No federated identities.</p>
          <div class="table-wrap" v-else>
            <table>
              <caption class="sr-only">Federated identities for selected user</caption>
              <thead><tr><th scope="col">Provider</th><th scope="col">Subject</th><th scope="col">Email</th><th scope="col">Action</th></tr></thead>
              <tbody>
                <tr v-for="identity in selectedUserIdentities" :key="identity.provider_id + ':' + identity.external_subject">
                  <td>{{ identity.provider_name }} ({{ identity.provider_code }})</td>
                  <td>{{ identity.external_subject }}</td>
                  <td>{{ identity.email }}</td>
                  <td><button :disabled="isBusy" :aria-label="'Unlink identity from ' + identity.provider_name" @click="unlinkFederatedIdentity(identity)">Unlink</button></td>
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
            <label for="role-code">Code</label>
            <input id="role-code" v-model="roleForm.code">
          </div>
          <div>
            <label for="role-name">Name</label>
            <input id="role-name" v-model="roleForm.name">
          </div>
        </div>
        <label for="role-description">Description</label>
        <textarea id="role-description" rows="2" v-model="roleForm.description"></textarea>
        <button class="primary" @click="createRole">Create role</button>
        <div class="table-wrap">
          <table>
            <caption class="sr-only">Roles</caption>
            <thead>
              <tr>
                <th scope="col">Code</th>
                <th scope="col">Name</th>
                <th scope="col">Description</th>
                <th scope="col">Permissions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="role in roles" :key="role.id">
                <td>{{ role.code }}</td>
                <td>{{ role.name }}</td>
                <td>{{ role.description }}</td>
                <td><button :aria-label="'Manage permissions for ' + role.code" @click="selectRole(role)">Manage permissions</button></td>
              </tr>
            </tbody>
          </table>
        </div>

        <section class="assignment" v-if="selectedRole">
          <h2>Role permissions: {{ selectedRole.code }}</h2>
          <div class="inline-form">
            <label class="sr-only" for="role-permission-assignment">Permission to assign</label>
            <select id="role-permission-assignment" v-model="assignmentForms.rolePermissionId">
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
              <button :disabled="isBusy" :aria-label="'Remove permission ' + permission.resource + ':' + permission.action" @click="removeRolePermission(permission.id)">remove</button>
            </li>
          </ul>
        </section>
      </div>

      <div class="panel" v-if="view === 'groups'">
        <h1>Groups</h1>
        <div class="grid">
          <div>
            <label for="group-code">Code</label>
            <input id="group-code" v-model="groupForm.code">
          </div>
          <div>
            <label for="group-name">Name</label>
            <input id="group-name" v-model="groupForm.name">
          </div>
        </div>
        <label for="group-description">Description</label>
        <textarea id="group-description" rows="2" v-model="groupForm.description"></textarea>
        <button class="primary" @click="createGroup">Create group</button>
        <div class="table-wrap">
          <table>
            <caption class="sr-only">Groups</caption>
            <thead>
              <tr>
                <th scope="col">Code</th>
                <th scope="col">Name</th>
                <th scope="col">Description</th>
                <th scope="col">Roles</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="group in groups" :key="group.id">
                <td>{{ group.code }}</td>
                <td>{{ group.name }}</td>
                <td>{{ group.description }}</td>
                <td><button :aria-label="'Manage roles for ' + group.code" @click="selectGroup(group)">Manage roles</button></td>
              </tr>
            </tbody>
          </table>
        </div>

        <section class="assignment" v-if="selectedGroup">
          <h2>Group roles: {{ selectedGroup.code }}</h2>
          <div class="inline-form">
            <label class="sr-only" for="group-role-assignment">Role to assign</label>
            <select id="group-role-assignment" v-model="assignmentForms.groupRoleId">
              <option value="">Select role</option>
              <option v-for="role in availableGroupRoles" :key="role.id" :value="role.id">{{ role.code }}</option>
            </select>
            <button class="primary" @click="assignGroupRole">Assign</button>
          </div>
          <ul class="chips">
            <li v-for="role in selectedGroupRoles" :key="role.id">
              {{ role.code }}
              <button :disabled="isBusy" :aria-label="'Remove role ' + role.code" @click="removeGroupRole(role.id)">remove</button>
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
            <label for="ldap-name">Name</label>
            <input id="ldap-name" v-model="ldapForm.name">
          </div>
          <div>
            <label for="ldap-host">Host</label>
            <input id="ldap-host" v-model="ldapForm.host">
          </div>
          <div>
            <label for="ldap-port">Port</label>
            <input id="ldap-port" type="number" v-model.number="ldapForm.port">
          </div>
          <div>
            <label for="ldap-bind-dn">Bind DN</label>
            <input id="ldap-bind-dn" v-model="ldapForm.bind_dn">
          </div>
          <div>
            <label for="ldap-bind-password">Bind password</label>
            <input id="ldap-bind-password" type="password" v-model="ldapForm.bind_password" autocomplete="new-password">
          </div>
          <div>
            <label for="ldap-base-dn">Base DN</label>
            <input id="ldap-base-dn" v-model="ldapForm.base_dn">
          </div>
        </div>
        <label for="ldap-user-filter">User filter</label>
        <input id="ldap-user-filter" v-model="ldapForm.user_filter">
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
            <label for="identity-code">Code</label>
            <input id="identity-code" v-model="identityForm.code" :disabled="!!identityForm.id">
          </div>
          <div>
            <label for="identity-name">Name</label>
            <input id="identity-name" v-model="identityForm.name">
          </div>
          <div>
            <label for="identity-type">Type</label>
            <select id="identity-type" v-model="identityForm.type">
              <option value="google">google</option>
              <option value="github">github</option>
              <option value="oidc">oidc</option>
            </select>
          </div>
          <div>
            <label for="identity-client-id">Client ID</label>
            <input id="identity-client-id" v-model="identityForm.client_id" autocomplete="off">
          </div>
          <div>
            <label for="identity-client-secret">Client secret</label>
            <input id="identity-client-secret" type="password" v-model="identityForm.client_secret" autocomplete="new-password">
          </div>
          <div>
            <label for="identity-scopes">Scopes</label>
            <input id="identity-scopes" v-model="identityForm.scopes">
          </div>
        </div>
        <label><input type="checkbox" v-model="identityForm.enabled"> Enabled</label>
        <div class="grid" v-if="identityForm.type === 'oidc'">
          <div>
            <label for="identity-authorize-url">Authorize URL</label>
            <input id="identity-authorize-url" type="url" v-model="identityForm.authorize_url">
          </div>
          <div>
            <label for="identity-token-url">Token URL</label>
            <input id="identity-token-url" type="url" v-model="identityForm.token_url">
          </div>
          <div>
            <label for="identity-userinfo-url">Userinfo URL</label>
            <input id="identity-userinfo-url" type="url" v-model="identityForm.userinfo_url">
          </div>
        </div>
        <button class="primary" @click="saveIdentityProvider">{{ identityForm.id ? 'Update provider' : 'Create provider' }}</button>
        <button @click="resetIdentityForm">Reset form</button>
        <div class="table-wrap">
          <table>
            <caption class="sr-only">Identity providers</caption>
            <thead>
              <tr>
                <th scope="col">Code</th>
                <th scope="col">Name</th>
                <th scope="col">Type</th>
                <th scope="col">Client ID</th>
                <th scope="col">Enabled</th>
                <th scope="col">Actions</th>
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
                  <button :aria-label="'Edit identity provider ' + provider.name" @click="editIdentityProvider(provider)">Edit</button>
                  <button :disabled="isBusy" :aria-label="'Delete identity provider ' + provider.name" @click="deleteIdentityProvider(provider)">Delete</button>
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
          <div class="metric"><strong>Database</strong><p class="muted">{{ operationalStatus.database?.status || 'degraded' }}</p></div>
          <div class="metric"><strong>Signing</strong><p class="muted">{{ operationalStatus.signing_keys?.status || 'degraded' }}</p></div>
          <div class="metric"><strong>Cleanup</strong><p class="muted">{{ operationalStatus.cleanup?.status || 'degraded' }} · {{ operationalStatus.cleanup?.last_success_at || 'not recorded' }}<template v-if="operationalStatus.cleanup?.age_seconds !== null && operationalStatus.cleanup?.age_seconds !== undefined"> · {{ operationalStatus.cleanup.age_seconds }}s ago</template></p></div>
          <div class="metric"><strong>Version</strong><p class="muted">{{ operationalStatus.version }} / {{ operationalStatus.environment }}</p></div>
        </div>

        <h2>OIDC signing keys</h2>
        <button class="primary" :disabled="isBusy" @click="rotateSigningKey">Rotate signing key</button>
        <div class="table-wrap">
          <table>
            <caption class="sr-only">OIDC signing keys</caption>
            <thead><tr><th scope="col">KID</th><th scope="col">Algorithm</th><th scope="col">Status</th><th scope="col">Created</th><th scope="col">Expires</th></tr></thead>
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

      <p v-if="isBusy" class="muted loading-status" role="status">Loading…</p>
      <p v-if="errorMessage" class="error-message" role="alert">{{ errorMessage }}</p>
      <p class="muted" role="status" aria-live="polite" aria-atomic="true">{{ message }}</p>
    </section>
  </main>
</div>

<script src="/js/vue.global.prod.js"></script>
<script src="/js/admin-render.js"></script>
<script src="/js/admin-api.js"></script>
<script src="/js/admin-navigation.js"></script>
<script src="/js/admin-ui.js"></script>
<script src="/js/admin-core.js"></script>
<script src="/js/admin-auth.js"></script>
<script src="/js/admin-oauth.js"></script>
<script src="/js/admin-access.js"></script>
<script src="/js/admin-providers.js"></script>
<script src="/js/admin-operations.js"></script>
<script src="/js/admin-app.js"></script>
