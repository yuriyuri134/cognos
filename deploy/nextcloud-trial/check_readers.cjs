/* Readers on real conversations, as cognos-admin (calls the real Claude through the bridge, writes data):
 * picks the N longest Initium conversations (default 3, READERS_N to change, or READERS_IDS=12,34), asks the readers
 * to read each one now, waits until they are back (up to ~12 min each), then opens each conversation and screenshots
 * the readers panel on the right, and opens the Norm space it was placed in (if any).
 * Report in evidence/readers-detail.json (what each reader read, where they differ, where it was placed);
 * screenshots evidence/readers-<id>.png and evidence/readers-space-<id>.png.
 * Placing is automatic and can be undone on the page (撤回).
 */
const live = require('./lib/live.cjs');
const fs = require('node:fs'), path = require('node:path');

live.run('readers', async checks => {
  const {req} = live.client();
  const list = (await req('/creations/data')).creations || [];
  let ids = (process.env.READERS_IDS || '').split(',').map(Number).filter(Boolean);
  if (!ids.length) {
    const sized = [];
    for (const c of list) sized.push([c.id, ((await req('/creations/' + c.id + '/messages')).messages || []).length]);
    sized.sort((a, b) => b[1] - a[1]);
    ids = sized.slice(0, Number(process.env.READERS_N || 3)).map(x => x[0]);
  }
  const report = [];
  for (const id of ids) {
    const started = await req('/creations/' + id + '/readers', 'POST', {}, 202).catch(e => ({error: e.message}));
    if (started.error) { report.push({id, error: started.error}); continue; }
    let r;
    for (let i = 0; i < 144; i++) { // 12 min
      await live.sleep(5000);
      r = await req('/creations/' + id + '/readers');
      if (r.latest && r.latest.state !== 'reading') break;
    }
    const d = r.latest || {};
    const res = d.result || {};
    report.push({id, title: (list.find(c => c.id === id) || {}).title, state: d.state, error: d.error,
      seconds: d.updated_at - d.created_at,
      readings: (res.readings || []).map(x => ({label: x.label, what: x.what, where: x.where})),
      differ: res.compare && res.compare.differ, needs: res.compare && res.compare.needs,
      placed: d.applied, raw_compare: res.compare_raw});
    checks.push('conversation ' + id + ': readers ' + d.state + (d.applied ? ', placed: ' + d.applied.action + ' ' + (d.applied.space_title || '') : ''));
  }
  fs.writeFileSync(path.join(live.EVIDENCE, 'readers-detail.json'), JSON.stringify(report, null, 1));
  const b = await live.browser();
  try {
    await b.login();
    for (const x of report) {
      await b.go(live.APP + '/creations/' + x.id);
      await b.until('!!document.querySelector(".cg-readers")');
      await live.sleep(1500);
      await b.shoot('readers-' + x.id + '.png');
      if (x.placed && x.placed.space_id) {
        await b.go(live.APP + '/norm/projects/' + x.placed.space_id);
        await b.until('document.querySelectorAll("#cg-space-parts section").length > 0');
        await live.sleep(1500);
        await b.shoot('readers-space-' + x.id + '.png');
      }
    }
    checks.push('screenshots of the readers panel and the spaces taken');
    if (b.errors.length) throw Error('uncaught page errors: ' + b.errors.join(' | '));
  } finally { b.close(); }
  if (report.some(x => x.state !== 'done')) throw Error('not all readings came back done: ' + JSON.stringify(report.map(x => [x.id, x.state, x.error])));
});
