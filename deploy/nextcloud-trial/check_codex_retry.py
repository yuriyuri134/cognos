"""A real Codex process reuses the confirmed receipt instead of creating a new run or result."""
import importlib.util,json,os,time
from pathlib import Path
root=Path(__file__).resolve().parent;repo=root.parent.parent
spec=importlib.util.spec_from_file_location('entry',repo/'tools/cognos-space-mcp/codex_entry.py');entry=importlib.util.module_from_spec(spec);spec.loader.exec_module(entry)
spec=importlib.util.spec_from_file_location('helpers',root/'check_v12.py');helpers=importlib.util.module_from_spec(spec);spec.loader.exec_module(helpers)
f=json.loads((root/'evidence/codex-entry.json').read_text(encoding='utf-8'))
case=next(p for p in (root/'evidence/codex-entry').iterdir() if (p/'report.json').exists() and json.loads((p/'report.json').read_text(encoding='utf-8')).get('result_id')==f['result_id'])
states=[json.loads(p.read_text(encoding='utf-8')) for p in (case/'handoff-state').glob('*.json')]
pending=next(s['pending'] for s in states if s.get('pending',{}).get('result_id')==f['result_id'])
os.environ.update(COGNOS_URL='http://127.0.0.1:8780',COGNOS_USER='cognos-admin',COGNOS_APP_PASSWORD=(root/'.secrets/codex-app-password').read_text(encoding='utf-8').strip(),COGNOS_MCP_STATE_DIR=str(case/'handoff-state'))
before=helpers.req('/creations/'+f['space_id']+'/messages')['messages']
out=root/'evidence/codex-retry'/time.strftime('%Y%m%d-%H%M%S')
entry.run(f['space_id'],f'''你是 Codex，现在只核对一份已交回结果的回执，不重新做事、不登记新运行。
用 hand_back，space_id={f['space_id']}，run_id={f['codex_run_id']}，content 必须原样为以下 JSON 字符串的值：
{json.dumps(pending['content'],ensure_ascii=False)}
这次只取原回执，不改写、不重复工作。工具确认已交回后结束。''',out,subject='Codex',model='gpt-6.1-sol')
events=[json.loads(line) for line in (out/'events.jsonl').read_text(encoding='utf-8').splitlines()]
calls=[e['item'] for e in events if e.get('item',{}).get('type')=='mcp_tool_call' and e['item'].get('status')=='completed']
assert any(c['tool']=='hand_back' and '已交回。' in str(c['result']) for c in calls)
assert not any(c['tool']=='start_work' for c in calls)
after=helpers.req('/creations/'+f['space_id']+'/messages')['messages'];assert len(before)==len(after)
report={'result':'pass','run_id':f['codex_run_id'],'result_id':f['result_id'],'checks':['a new real Codex process confirmed the original hand-back receipt without another run or any extra message']}
(root/'evidence/codex-retry.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8');print(json.dumps(report,ensure_ascii=False,indent=2))
