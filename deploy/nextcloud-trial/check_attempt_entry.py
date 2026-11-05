"""Actual MCP/OCS input snapshot check; no model is invoked by this engineering test."""
import base64, importlib.util, json, os, re, subprocess, tempfile, uuid
from pathlib import Path
root=Path(__file__).resolve().parent
fixture=json.loads((root/'evidence/attempts-api.json').read_text(encoding='utf-8-sig'))
os.environ.update(COGNOS_USER='cognos-admin',COGNOS_APP_PASSWORD=(root/'.secrets/codex-app-password').read_text().strip(),COGNOS_SUBJECT='尝试入口契约核验',COGNOS_TOOLS='')
spec=importlib.util.spec_from_file_location('attempt_mcp',root.parent.parent/'tools/cognos-space-mcp/server.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
def web(route,method='GET',body=None):return m.call_api(method,'',body=body,url=m.BASE+'/index.php/apps/cognos'+route)
loc=web('/projects/locations/'+fixture['location_id']+'/data')['location'];space=str(loc['creation_id']);m.BOUND_ID=space;m.BOUND_TYPE='creation'
args={'space_id':space};items=json.loads(m.run_tool('list_attempts',args))['items'];assert any(a['id']==fixture['attempt_id'] for a in items)
a=json.loads(m.run_tool('get_attempt',{**args,'attempt_id':fixture['attempt_id']}))['item'];assert a['summaries'][0]['id']==fixture['second_summary_id']
entered=m.run_tool('enter_space',args);assert fixture['attempt_id'] in entered and fixture['second_summary_id'] in entered
other=web('/projects/'+fixture['project_id']+'/locations','POST',{'parent_id':fixture['location_id'],'title':'入口核验：没有关联尝试的位置','request_id':str(uuid.uuid4())});otherloc=web('/projects/locations/'+other['location_id']+'/data')['location']
try:m.call_api('GET','/creation/'+str(otherloc['creation_id'])+'/attempts/'+a['id'])
except m.CognosAPIError as e:assert e.status==404
else:raise AssertionError('attempt must be linked to the requested position')
started=m.run_tool('start_work',{**args,'purpose':'工程核验：尝试简介与阶段记录确实进入不可变运行输入'});run_id=re.search(r'[a-f0-9]{32}',started).group()
php=r"""define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});$f=json_decode(base64_decode($argv[1]),true);$db=\OC::$server->get(\OCP\IDBConnection::class);$q=$db->getQueryBuilder();$input=$q->select('input_snapshot')->from('cognos_runs')->where($q->expr()->eq('id',$q->createNamedParameter($f['run_id'])))->executeQuery()->fetchOne();$x=json_decode($input,true);foreach(['attempt_id','summary_id'] as $key)if(!str_contains($x['system'],$f[$key]))throw new RuntimeException('missing snapshot '.$key);echo 'actual immutable input includes the attempt and latest summary identities';"""
f={'run_id':run_id,'attempt_id':a['id'],'summary_id':fixture['second_summary_id']}
checked=subprocess.run(['docker','exec','-u','www-data','-w','/var/www/html','cognos-nextcloud-trial-app-1','php','-r',php,base64.b64encode(json.dumps(f).encode()).decode()],capture_output=True,text=True,check=True);assert 'actual immutable input' in checked.stdout
with tempfile.TemporaryDirectory(prefix='cognos-attempt-handoff-') as tmp:
 os.environ['COGNOS_MCP_STATE_DIR']=tmp
 received=m.run_tool('hand_back',{**args,'run_id':run_id,'content':'工程核验完成：已通过通用 MCP 读取尝试与阶段版本，并检查实际运行输入；本次未调用模型，也未修改原尝试或阶段总结。'})
 assert '已交回' in received
report={'result':'pass','attempt_id':a['id'],'run_id':run_id,'checks':['actual MCP reads the same multi-position attempt and newest appended stage','unrelated location cannot read this attempt through the agent endpoint','actual immutable run input retains the attempt and stage identities; one result handed back, no model invoked']}
(root/'evidence/attempts-entry.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8');print(json.dumps(report,ensure_ascii=False,indent=2))
