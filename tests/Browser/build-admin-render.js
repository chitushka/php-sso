const fs = require('fs');
const path = require('path');
const { compile } = require('@vue/compiler-dom');

const root = path.resolve(process.argv[2] || '.');
const checkOnly = process.argv.includes('--check');
const viewPath = path.join(root, 'views/admin/index.php');
const outputPath = path.join(root, 'web/js/admin-render.js');
const view = fs.readFileSync(viewPath, 'utf8');
const match = view.match(/<div id="app">([\s\S]*?)<\/div>\s*<script src="\/js\/vue\.global\.prod\.js"><\/script>/);

if (!match) {
  throw new Error('Unable to extract the admin Vue template');
}

const compiled = compile(match[1], {
  mode: 'function',
  prefixIdentifiers: true,
  hoistStatic: true,
  cacheHandlers: true
});
const generated = [
  '(function (global) {',
  "  'use strict';",
  '',
  '  global.AdminRender = (function (Vue) {',
  ...compiled.code.split('\n').map(line => '    ' + line),
  '  }(global.Vue));',
  '}(window));',
  ''
].join('\n');

if (checkOnly) {
  if (!fs.existsSync(outputPath) || fs.readFileSync(outputPath, 'utf8') !== generated) {
    throw new Error('web/js/admin-render.js is stale; regenerate it with build-admin-render.js');
  }
} else {
  fs.writeFileSync(outputPath, generated);
}
