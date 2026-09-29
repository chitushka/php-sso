(function (global) {
  'use strict';

  function headers(accessToken) {
    return accessToken
      ? { 'Authorization': 'Bearer ' + accessToken, 'Content-Type': 'application/json' }
      : { 'Content-Type': 'application/json' };
  }

  async function request(accessToken, url, options) {
    const response = await fetch(url, Object.assign({
      headers: headers(accessToken),
      credentials: 'same-origin'
    }, options || {}));
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error(data.error || data.error_description || 'request failed');
    }
    return data;
  }

  async function restoreSession() {
    const response = await fetch('/api/v1/auth/session-token', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: '{}'
    });
    if (!response.ok) {
      return null;
    }
    return response.json();
  }

  global.AdminApi = { headers, request, restoreSession };
}(window));
