(function (global) {
  'use strict';

  global.AdminProviders = {
    data() {
      return {
        ldapProviders: [],
        brokerProviders: [],
        identityProviders: [],
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
    methods: {
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
        if (!this.confirmAction('Delete identity provider ' + provider.name + '? External sign-in through it will stop working.')) {
          return;
        }
        await this.api('/api/v1/identity-providers/' + provider.id, { method: 'DELETE' });
        this.message = 'Identity provider deleted';
        await this.refresh();
      }
    }
  };
}(window));
