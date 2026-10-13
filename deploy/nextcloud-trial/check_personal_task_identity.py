import json,sys
from pathlib import Path
t=Path(r'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial');sys.path.insert(0,str(t))
from check_tasks import client_for,request,ocs,DECK
s=json.loads((t/'.secrets/task-journey.json').read_text());case=json.loads((t/'evidence/personal-tasks-case.json').read_text())
a,b,out=s['alice'],s['bob'],s['outsider'];acl=None;original=None;changed=False
report={'scope':'same display name and shared board do not make assignments interchangeable','checks':[]}
try:
 with client_for('cognos-admin',(t/'.secrets/admin-password').read_text().strip()) as admin:
  path='/ocs/v1.php/cloud/users/'+out['user'];original=ocs(admin,'GET',path)['displayname'];name=ocs(admin,'GET','/ocs/v1.php/cloud/users/'+b['user'])['displayname'];ocs(admin,'PUT',path,data={'key':'displayname','value':name});changed=True
 with client_for(a['user'],a['password']) as alice:acl=request(alice,'POST',f"{DECK}/boards/{case['board']}/acl",json={'type':0,'participant':out['user'],'permissionEdit':False,'permissionShare':False,'permissionManage':False}).json()['id']
 with client_for(out['user'],out['password']) as client:
  current=request(client,'GET',f"/index.php/apps/cognos/cards/{case['card']}/events").json();assert current['currentState']['id']==case['card']
  assert case['card'] not in {r['id'] for r in request(client,'GET','/index.php/apps/cognos/tasks').json()['tasks']}
 report['checks'].append('same-name member can read shared task but does not discover it as a personal assignment')
 with client_for(b['user'],b['password']) as client:assert case['card'] in {r['id'] for r in request(client,'GET','/index.php/apps/cognos/tasks').json()['tasks']}
 report['checks'].append('actual assigned UID still discovers the task');report['result']='passed'
finally:
 if acl is not None:
  with client_for(a['user'],a['password']) as alice:request(alice,'DELETE',f"{DECK}/boards/{case['board']}/acl/{acl}")
 if changed:
  with client_for('cognos-admin',(t/'.secrets/admin-password').read_text().strip()) as admin:ocs(admin,'PUT','/ocs/v1.php/cloud/users/'+out['user'],data={'key':'displayname','value':original})
 report['temporary_name_and_membership_restored']=True
 (t/'evidence/个人任务-同名隔离验收.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps(report,ensure_ascii=False))
