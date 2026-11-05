"""Real HTTP checks for granted MCP work writes, changing drift and a lost write receipt."""
import importlib.util,json,os,subprocess,tempfile
from pathlib import Path
from unittest.mock import patch
root=Path(__file__).resolve().parent;repo=root.parent.parent
spec=importlib.util.spec_from_file_location('helpers',root/'check_v12.py');helpers=importlib.util.module_from_spec(spec);spec.loader.exec_module(helpers)
f=json.loads(subprocess.check_output(['docker','exec','-u','www-data','-w','/var/www/html','cognos-nextcloud-trial-app-1','php','/tmp/check_work_management.php','seed'],text=True,encoding='utf-8'))
os.environ.update(COGNOS_APP_PASSWORD=(root/'.secrets/codex-app-password').read_text(encoding='utf-8').strip(),COGNOS_SUBJECT=f['authority']['subject'],COGNOS_BOUND_SPACE_ID=f['space_id'])
spec=importlib.util.spec_from_file_location('work_mcp',repo/'tools/cognos-space-mcp/server.py');server=importlib.util.module_from_spec(spec);spec.loader.exec_module(server)
report={'project_id':f['project_id'],'work_id':f['work_id'],'checks':[]}

def w():return helpers.req('/projects/work/'+f['work_id'])['item']

def args(work,space,run):return {'space_id':space,'work_id':work['id'],'run_id':run,'expected_revision':work['revision'],
    'description':work['description'],'owner':work['owner'],'deliverable':'经许可补充的交付稿','acceptance':work['acceptance'],'state':'in_progress','note':'验证一次有授权的修改'}

def main():
    before=w();a=args(before,f['space_id'],f['authority']['run_id']);bad=f['denied'];bw=helpers.req('/projects/work/'+bad['work_id'])['item']
    with tempfile.TemporaryDirectory() as state,patch.dict(os.environ,{'COGNOS_MCP_STATE_DIR':state}):
        with patch.object(server,'BOUND_ID',bad['space_id']):
            try:server.run_tool('update_work_item',args(bw,bad['space_id'],bad['authority']['run_id']));raise AssertionError('missing grant accepted')
            except server.CognosAPIError as e:assert e.status==403
        assert helpers.req('/projects/work/'+bad['work_id'])['item']['revision']==bw['revision']
        report['checks'].append('same-account agent without explicit work-maintenance grant is refused by the agent endpoint, no work mutation')
        helpers.req('/projects/'+f['project_id']+'/decisions','POST',{'content':'验证：修改前先说明范围','request_id':helpers.rid()})
        first=server.run_tool('update_work_item',a);assert '本次没有修改工作' in first;assert w()['revision']==before['revision']
        helpers.req('/projects/'+f['project_id']+'/decisions','POST',{'content':'验证：确认前又多了一条要求','request_id':helpers.rid()})
        a['acknowledged']=True
        again=server.run_tool('update_work_item',a);assert '本次没有修改工作' in again;assert w()['revision']==before['revision']
        original=server.call_api;sent=[];lost=True
        def call(method,path,params=None,body=None,url=None):
            nonlocal lost
            if method=='PUT':sent.append(body['request_id'])
            data=original(method,path,params,body,url)
            if method=='PUT' and lost:lost=False;raise RuntimeError('simulated lost response after server commit')
            return data
        with patch.object(server,'call_api',side_effect=call):
            try:server.run_tool('update_work_item',a);raise AssertionError('receipt loss not injected')
            except RuntimeError as e:assert 'simulated lost response' in str(e)
            committed=w();assert committed['revision']==before['revision']+1
            result=json.loads(server.run_tool('update_work_item',a));assert result['revision']==committed['revision']
        assert len(sent)==2 and sent[0]==sent[1];after=w();assert len(after['events'])==len(before['events'])+1
        event=after['events'][0];assert event['actor_subject']==f['authority']['subject'] and event['run_id']==f['authority']['run_id'] and event['drift_token']
        report['checks'].append('new decision blocks write, stale confirmation shows new drift; matching acknowledgment records the actual AI and drift fingerprint')
        report['checks'].append('lost HTTP write receipt is retried with the same operation id, returns the committed revision and creates no extra work version')
    report['result']='pass'

try:main()
except Exception as e:report.update(result='fail',error=str(e));raise
finally:(root/'evidence/work-management-api.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8');print(json.dumps(report,ensure_ascii=False,indent=2))
