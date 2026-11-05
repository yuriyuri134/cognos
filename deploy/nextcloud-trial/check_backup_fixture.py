"""Engineering delivery-state fixture. No model or external work is executed."""
import importlib.util,json,os,uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parent
os.environ.update(COGNOS_USER='cognos-admin',COGNOS_APP_PASSWORD=(ROOT/'.secrets/codex-app-password').read_text().strip(),COGNOS_SUBJECT='恢复待交回工程记录')
def module(name,file):
 s=importlib.util.spec_from_file_location(name,file);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
m=module('backup_fixture_api',ROOT.parent.parent/'tools/cognos-space-mcp/server.py')
def web(route,body):return m.call_api('POST','',body=body,url=m.BASE+'/index.php/apps/cognos'+route)
p=web('/projects/data',{'title':'验证：整包备份与恢复（工程记录）','request_id':str(uuid.uuid4())})
loc=m.call_api('GET','',url=m.BASE+'/index.php/apps/cognos/projects/locations/'+p['location_id']+'/data')['location'];space=str(loc['creation_id'])
def run(purpose):return m.call_api('POST','/creation/'+space+'/runs',body={'subject':m.SUBJECT,'purpose':purpose})['run']['id']
pending=run('已写入独立待发送目录的回执工程样本；没有执行模型');inflight=run('未确认完成的恢复工程样本；不应因恢复而重新执行')
folder=ROOT/'.backups/v11-pending-fixture';folder.mkdir(parents=True,exist_ok=True)
b=module('backup_fixture_bridge',ROOT.parent.parent/'tools/cognos-space-mcp/claude_bridge.py');b.OUTBOX_DIR=folder
result={'run_id':pending,'result_id':uuid.uuid4().hex,'space_type':'creation','space_id':space,'subject':m.SUBJECT,'content':'这是备份恢复的待交回工程样本，没有调用模型或执行外部工作。恢复时仅重投原结果编号，不重新执行。','depends_on':[{'type':'location_content','id':p['location_id'],'version':'1','note':'固定原稿版本的工程回查'}]}
b.save_result(result);b.write_json(b.outbox('inflight')/(inflight+'.json'),{'run_id':inflight,'space_type':'creation','space_id':space,'subject':m.SUBJECT,'pid':0,'accepted_at':'engineering-fixture-not-a-live-process'})
f={'project_id':p['project_id'],'location_id':p['location_id'],'space_id':space,'pending_run':pending,'inflight_run':inflight,'result_id':result['result_id'],'outbox':str(folder),'note':'Engineering fixture; no model or external execution. Not a live bridge outbox.'}
(ROOT/'evidence/backup-fixture.json').write_text(json.dumps(f,ensure_ascii=False,indent=2),encoding='utf-8');print(json.dumps(f,ensure_ascii=False,indent=2))
