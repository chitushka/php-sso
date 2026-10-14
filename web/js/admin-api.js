(function (global) {
  'use strict';

  function headers(accessToken) {
    return accessToken
      ? { 'Authorization': 'Bearer ' + accessToken, 'Content-Type': 'application/json' }
      : { 'Content-Type': 'application/json' };
  }

  function cookie(name) {
    const prefix = encodeURIComponent(name) + '=';
    const values = document.cookie ? document.cookie.split(';') : [];
    for (const value of values) {
      const entry = value.trim();
      if (entry.indexOf(prefix) === 0) {
        try {
          return decodeURIComponent(entry.slice(prefix.length));
        } catch (error) {
          return '';
        }
      }
    }
    return '';
  }

  async function request(accessToken, url, options) {
    const response = await fetch(url, Object.assign({
      headers: headers(accessToken),
      credentials: 'same-origin'
    }, options || {}));
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const apiError = data.error && typeof data.error === 'object' ? data.error.message : data.error;
      throw new Error(apiError || data.error_description || 'request failed');
    }
    return data;
  }

  async function restoreSession() {
    const csrfToken = cookie('sso_csrf');
    const response = await fetch('/api/v1/auth/session-token', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: '{}'
    });
    if (!response.ok) {
      return null;
    }
    return response.json();
  }

  global.AdminApi = { headers, request, restoreSession };
}(window));
