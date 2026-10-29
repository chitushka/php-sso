(function (global) {
  'use strict';

  global.AdminOauth = {
    data() {
      return {
        clients: [],
        consents: [],
        clientForm: { name: 'Demo application', client_id: 'demo', type: 'confidential', redirects: 'http://localhost:3000/callback' },
      };
    },
    methods: {
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
      async revokeConsent(clientId) {
        const consent = this.consents.find(item => item.client_id === clientId);
        if (!this.confirmAction('Revoke consent for ' + (consent ? consent.client_name : clientId) + '?')) {
          return;
        }
        await this.api('/api/v1/auth/consents/' + encodeURIComponent(clientId), { method: 'DELETE' });
        this.consents = await this.api('/api/v1/auth/consents');
        this.message = 'Consent revoked';
      },
    }
  };
}(window));
