import json,secrets,uuid,sys
from pathlib import Path
t=Path(r'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial');sys.path.insert(0,str(t))
from check_tasks import client_for,request,DECK
def run():
 s=json.loads((t/'.secrets/task-journey.json').read_text());a,b=s['alice'],s['bob'];report={'scope':'live four-state field extension and native completion consistency','checks':[],'full_journey':'unverified'}
 try:
  with client_for(a['user'],a['password']) as alice:
   board=request(alice,'POST',DECK+'/boards',json={'title':'Four states '+secrets.token_hex(5),'color':'317CCC'}).json();bp=f"{DECK}/boards/{board['id']}"
   acl=request(alice,'POST',bp+'/acl',json={'type':0,'participant':b['user'],'permissionEdit':True,'permissionShare':False,'permissionManage':False}).json()
   todo=request(alice,'POST',bp+'/stacks',json={'title':'工作列','order':1}).json();donecol=request(alice,'POST',bp+'/stacks',json={'title':'完成列','order':2}).json()
   request(alice,'PUT',f"/ocs/v2.php/apps/deck/api/v1.0/stacks/{donecol['id']}/done",params={'format':'json'},json={'boardId':board['id'],'isDone':True})
   label=request(alice,'POST',bp+'/labels',json={'title':'资料','color':'0082C9'}).json()
   card=request(alice,'POST','/ocs/v2.php/apps/cognos/api/v1.0/cards',params={'format':'json'},json={'requestId':str(uuid.uuid4()),'title':'Four-state task','stackId':todo['id'],'boardId':board['id'],'description':'Keep all fields','users':[{'id':b['user'],'type':0}],'labels':[{'id':label['id']}],'duedate':'2026-12-20T08:00:00Z'}).json()['ocs']['data']
   cid=card['id'];base=f'/index.php/apps/cognos/cards/{cid}'
   initial=request(alice,'GET',base+'/events').json();assert initial['currentState']['status']=='open' and initial['events'][0]['after_state']['status']=='open'
   report['checks'].append('new task has open status without rewriting any older history')
  with client_for(b['user'],b['password']) as bob:
   key=str(uuid.uuid4());payload={'requestId':key,'status':'in_progress','expectedStatus':'open'}
   started=request(bob,'PUT',base+'/state',json=payload).json();assert started['currentState']['status']=='in_progress' and started['card']['done'] is None
   for field in ['title','description','assignees','labels','duedate','startdate','color','owner','stack']:assert started['currentState'][field]==initial['currentState'][field]
   report['checks'].append('Bob starts task; fields and original column remain intact')
   e=request(bob,'GET',base+'/events').json()['events'];assert len(e)==2 and e[0]['kind']=='status_changed' and e[0]['actor_uid']==b['user']
   assert e[0]['before_state']['status']=='open' and e[0]['after_state']['status']=='in_progress'
   request(bob,'PUT',base+'/state',json={'requestId':str(uuid.uuid4()),'status':'in_progress','expectedStatus':'in_progress'})
   assert request(bob,'GET',base+'/events').json()['events']==e
   stale_key=str(uuid.uuid4());request(bob,'PUT',base+'/state',allowed=(409,),json={'requestId':stale_key,'status':'blocked','expectedStatus':'open'})
   blocked=request(bob,'PUT',base+'/state',json={'requestId':stale_key,'status':'blocked','expectedStatus':'in_progress'}).json();assert blocked['currentState']['status']=='blocked'
   request(bob,'PUT',base+'/state',allowed=(400,),json={'requestId':str(uuid.uuid4()),'status':'unknown'})
   report['checks'].append('same-state operation adds no event; stale/invalid states reject; corrected failed request can retry')
   old=request(bob,'PUT',base+'/state',json=payload);assert old.headers.get('X-Cognos-Replayed')=='true' and old.json()['currentState']['status']=='blocked'
   report['checks'].append('old accepted start replay returns current blocked state without reapplying obsolete intent')
   completed=request(bob,'PUT',base+'/state',json={'requestId':str(uuid.uuid4()),'status':'done','expectedStatus':'blocked'}).json();assert completed['currentState']['status']=='done' and completed['card']['done'] and completed['card']['stackId']==donecol['id']
   for field in ['title','description','assignees','labels','duedate','startdate','color','owner']:assert completed['currentState'][field]==initial['currentState'][field]
   report['checks'].append('completion sets native done and moves to configured completion column with fields retained')
   native_reopen=request(bob,'PUT',base+'/undone',json={'requestId':str(uuid.uuid4())}).json();assert native_reopen['done'] is None and native_reopen['stackId']==todo['id']
   restored=request(bob,'GET',base+'/events').json();assert restored['currentState']['status']=='blocked'
   report['checks'].append('existing reopen protocol restores prior non-completed phase and pre-completion column')
   reopened=request(bob,'PUT',base+'/state',json={'requestId':str(uuid.uuid4()),'status':'open','expectedStatus':'blocked'}).json();assert reopened['currentState']['status']=='open'
   final=request(bob,'GET',base+'/events').json()['events'];assert len(final)==6 and final[-2:]==e
   report['checks'].append('explicit open transition is recorded while all earlier snapshots remain unchanged')
  with client_for(a['user'],a['password']) as alice:
   assert request(alice,'GET',base+'/events').json()['currentState']==reopened['currentState']
   request(alice,'DELETE',bp+f"/acl/{acl['id']}")
  with client_for(b['user'],b['password']) as gone:
   request(gone,'PUT',base+'/state',allowed=(403,),json=payload)
  report['checks'].append('another member reads same four-state result; departure denies old operation replay')
  report['objects']={'board':board['id'],'todo':todo['id'],'done':donecol['id'],'card':cid};report['result']='passed'
  (t/'evidence/status-case.json').write_text(json.dumps({'objects':report['objects'],'card_url':f'/apps/deck/board/{board["id"]}/card/{cid}'}),encoding='utf-8')
 except Exception as exc:report['result']='failed';report['failure_type']=type(exc).__name__;raise
 finally:(t/'evidence/任务四状态-API验收.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
 print(json.dumps({'result':report['result'],'checks':len(report['checks'])}))
if __name__=='__main__':run()
