const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function environment(target, user = null) {
  const replacements = [];
  const location = {
    origin: 'https://sso.example.test',
    search: '?continue=' + encodeURIComponent(target),
    replace: url => replacements.push(url),
  };
  const window = { location };
  const context = vm.createContext({ window, URL, URLSearchParams });
  for (const module of ['core', 'auth']) {
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../../web/js/admin-' + module + '.js'), 'utf8'), context);
  }
  const receiver = { user, ...window.AdminCore.methods };
  return { window, receiver, replacements };
}

test('only a same-origin authorize request can resume after authentication', () => {
  const target = '/oauth2/authorize?client_id=app&redirect_uri=https%3A%2F%2Fapp.example.test%2Fcallback&state=xyz';
  const guest = environment(target);
  assert.equal(guest.receiver.authorizationContinuation(), target);
  assert.equal(guest.receiver.resumeAuthorization(), false);
  const signedIn = environment(target, { id: 'user' });
  assert.equal(signedIn.receiver.resumeAuthorization(), true);
  assert.deepEqual(signedIn.replacements, [target]);
});

for (const target of [
  'https://evil.test/', '//evil.test/', '/\\evil.test', '/api/v1/auth/me', '/oauth2/authorize/../login?x=1',
  '/oauth2/authorize?client_id=app#fragment', '/oauth2/authorize?x=\r\nLocation:evil', '',
]) {
  test('unsafe continuation is ignored: ' + JSON.stringify(target), () => {
    const { receiver, replacements } = environment(target, { id: 'user' });
    assert.equal(receiver.authorizationContinuation(), null);
    assert.equal(receiver.resumeAuthorization(), false);
    assert.deepEqual(replacements, []);
  });
}

test('federation returns to the central login page with the validated OAuth request', () => {
  const target = '/oauth2/authorize?client_id=app&state=abc';
  const { window, receiver } = environment(target);
  window.AdminAuth.methods.startBrokerLogin.call(receiver, 'corporate');
  const broker = new URL(window.location.href, window.location.origin);
  const callback = new URL(broker.searchParams.get('continue'), window.location.origin);
  assert.equal(callback.pathname, '/login');
  assert.equal(callback.searchParams.get('continue'), target);
});
