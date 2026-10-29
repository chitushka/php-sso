(function (global) {
  'use strict';

  global.AdminOperations = {
    data() {
      return {
        audit: [],
        operationalStatus: {},
        signingKeys: [],
      };
    },
    methods: {
      async loadAudit() {
        this.audit = await this.apiItems('/api/v1/audit');
      },
      async loadOperations() {
        this.operationalStatus = await this.api('/api/v1/operations/status');
        this.signingKeys = await this.api('/api/v1/operations/signing-keys');
      },
      async rotateSigningKey() {
        if (!this.confirmAction('Rotate the active signing key? The current key will enter its retirement period.')) {
          return;
        }
        const key = await this.api('/api/v1/operations/signing-keys/rotate', {
          method: 'POST',
          body: '{}'
        });
        this.message = 'Signing key rotated: ' + key.kid;
        await this.loadOperations();
      },
    }
  };
}(window));
