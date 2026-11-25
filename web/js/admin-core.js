(function (global) {
  'use strict';

  global.AdminCore = {
    data() {
      return {
        view: 'dashboard',
        message: '',
        errorMessage: '',
        pendingRequests: 0,
        accessToken: '',
        user: null,
        bootstrap: {},
        discovery: {},
      };
    },
    async mounted() {
      if (window.location.pathname === '/login') {
        this.view = 'login';
      }
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
      isBusy() {
        return this.pendingRequests > 0;
      },
    },
    methods: {
      authorizationContinuation() {
        const target = new URLSearchParams(window.location.search).get('continue');
        if (!target || target.length > 8192 || !target.startsWith('/oauth2/authorize?') || /[\x00-\x20\x7f\\]/.test(target)) {
          return null;
        }
        try {
          const url = new URL(target, window.location.origin);
          return url.origin === window.location.origin && url.pathname === '/oauth2/authorize' && !url.hash
            ? url.pathname + url.search
            : null;
        } catch (error) {
          return null;
        }
      },
      resumeAuthorization() {
        const target = this.authorizationContinuation();
        if (this.user && target) {
          window.location.replace(target);
          return true;
        }
        return false;
      },
      navigate(view) {
        this.view = view;
        this.errorMessage = '';
        this.$nextTick(() => this.$refs.adminContent.focus());
      },
      confirmAction(message) {
        return window.AdminUi.confirmAction(message);
      },
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
        this.pendingRequests += 1;
        this.errorMessage = '';
        try {
          return await AdminApi.request(this.accessToken, url, options);
        } finally {
          this.pendingRequests = Math.max(0, this.pendingRequests - 1);
        }
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
          if (this.resumeAuthorization()) {
            return;
          }
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
    }
  };
}(window));
