// A disconnected ChatGPT entry must not create another Chrome login environment.
const assert = require('node:assert/strict');
const cp = require('node:child_process');
const helper = require('./chatgpt_browser.cjs');
(async () => {
  let spawned = 0;
  const spawn = cp.spawn;
  cp.spawn = () => { spawned++; throw Error('Unexpected browser process'); };
  try {
    for (const connection of [await helper.status(), await helper.open()]) {
      assert.equal(connection.ready, false);
      assert.equal(connection.transport, 'existing_chrome');
      assert.equal(connection.state, 'connection_unavailable');
      assert.doesNotMatch(connection.message, /请.*登录|打开聊天端/);
    }
    await assert.rejects(() => helper.reply({prompt: 'test', reasoning_effort: 'default'}), /当前未连接/);
    assert.equal(spawned, 0);
  } finally { cp.spawn = spawn; }
  console.log('Disconnected ChatGPT never starts a separate Chrome profile or sends a model request.');
})().catch(error => { console.error(error.message); process.exitCode = 1; });
