(function (global) {
  'use strict';

  global.AdminAuth = {
    data() {
      return {
        mfaChallenge: '',
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
      };
    },
    methods: {
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
        if (!this.confirmAction('Disable MFA for this account? Existing recovery codes will stop working.')) {
          return;
        }
  
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
        if (!this.confirmAction('Regenerate recovery codes? All existing recovery codes will stop working.')) {
          return;
        }
  
        const data = await this.api('/api/v1/auth/mfa/recovery-codes', {
          method: 'POST',
          body: JSON.stringify({ code: this.mfaManageForm.code })
        });
        this.mfaManageForm.code = '';
        this.mfaRecoveryCodes = data.recovery_codes || [];
        this.message = 'Recovery codes regenerated. Save them now.';
      },
    }
  };
}(window));
