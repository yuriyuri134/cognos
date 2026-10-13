import sys,json,secrets,uuid
from pathlib import Path
t=Path(r'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial');sys.path.insert(0,str(t))
from check_tasks import ROOT,DECK,client_for,request
state=json.loads((ROOT/'.secrets/task-journey.json').read_text());a,b=state['alice'],state['bob']
report={'scope':'live personal task filtering, current status and permission isolation','checks':[],'full_journey':'unverified'}
try:
 with client_for(a['user'],a['password']) as alice:
  board=request(alice,'POST',DECK+'/boards',json={'title':'Personal tasks '+secrets.token_hex(4),'color':'317CCC'}).json();bp=f"{DECK}/boards/{board['id']}"
  acl=request(alice,'POST',bp+'/acl',json={'type':0,'participant':b['user'],'permissionEdit':True,'permissionShare':False,'permissionManage':False}).json()
  stack=request(alice,'POST',bp+'/stacks',json={'title':'任务','order':1}).json()
  def create(title,users):
   return request(alice,'POST','/ocs/v2.php/apps/cognos/api/v1.0/cards',params={'format':'json'},json={'requestId':str(uuid.uuid4()),'title':title,'stackId':stack['id'],'boardId':board['id'],'users':users}).json()['ocs']['data']
  own=create('Personal member task',[{'id':b['user'],'type':0}]);other=create('Other member task',[{'id':a['user'],'type':0}]);unassigned=create('Unassigned control',[])
 with client_for(b['user'],b['password']) as bob:
  def rows(status='all',**extra):return request(bob,'GET','/index.php/apps/cognos/tasks',params={'status':status,**extra}).json()['tasks']
  mine={row['id']:row for row in rows()};assert own['id'] in mine and other['id'] not in mine and unassigned['id'] not in mine
  assert mine[own['id']]['status']=='open' and mine[own['id']]['board_id']==board['id'];report['checks'].append('only stable UID assignment appears; other assignee and unassigned controls stay out')
  for before,after in [('open','in_progress'),('in_progress','blocked'),('blocked','done')]:
   request(bob,'PUT',f"/index.php/apps/cognos/cards/{own['id']}/state",json={'requestId':str(uuid.uuid4()),'status':after,'expectedStatus':before})
   assert own['id'] in {r['id'] for r in rows(after)} and own['id'] not in {r['id'] for r in rows(before)}
  report['checks'].append('four status filters use current native completion and stored phase; completed tasks remain discoverable')
  for status in ['all','open','in_progress','blocked','done']:
   assert all(row['id']<own['id'] for row in rows(status,beforeId=own['id']))
  request(bob,'GET','/index.php/apps/cognos/tasks',params={'status':'bogus'},allowed=(400,));request(bob,'GET','/index.php/apps/cognos/tasks',params={'beforeId':0},allowed=(400,))
  report['checks'].append('cursor filters strictly older IDs and invalid filter/cursor reject')
 with client_for(a['user'],a['password']) as alice:
  request(alice,'PUT',bp,json={'title':board['title'],'color':'317CCC','archived':True})
 with client_for(b['user'],b['password']) as bob:
  assert own['id'] not in {r['id'] for r in request(bob,'GET','/index.php/apps/cognos/tasks').json()['tasks']}
 with client_for(a['user'],a['password']) as alice:
  request(alice,'PUT',bp,json={'title':board['title'],'color':'317CCC','archived':False})
  request(alice,'DELETE',bp+f"/acl/{acl['id']}")
 with client_for(b['user'],b['password']) as bob:
  assert own['id'] not in {r['id'] for r in request(bob,'GET','/index.php/apps/cognos/tasks').json()['tasks']}
 report['checks'].append('archived board and revoked membership disappear in fresh clients')
 with client_for('','') as anonymous:request(anonymous,'GET','/index.php/apps/cognos/tasks',allowed=(401,))
 report['checks'].append('anonymous list request requires authentication')
 # Restore only this new test board for browser follow-up; native ACL removal cancels assignment.
 with client_for(a['user'],a['password']) as alice:
  request(alice,'POST',bp+'/acl',json={'type':0,'participant':b['user'],'permissionEdit':True,'permissionShare':False,'permissionManage':False})
  request(alice,'POST',f"/ocs/v2.php/apps/cognos/api/v1.0/cards/{own['id']}/assign",params={'format':'json'},json={'requestId':str(uuid.uuid4()),'userId':b['user'],'type':0})
 report['result']='passed';report['card']=own['id']
 (t/'evidence/personal-tasks-case.json').write_text(json.dumps({'card':own['id'],'board':board['id'],'card_url':f"/apps/deck/board/{board['id']}/card/{own['id']}"}),encoding='utf-8')
except Exception as e:report['result']='failed';report['failure_type']=type(e).__name__;raise
finally:(t/'evidence/个人任务-API验收.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps(report,ensure_ascii=False))
