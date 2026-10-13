import json,sys,uuid,secrets
from pathlib import Path
t=Path(r'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial');sys.path.insert(0,str(t))
from check_tasks import ROOT,DECK,client_for,request
s=json.loads((ROOT/'.secrets/task-journey.json').read_text());a,b,out=s['alice'],s['bob'],s['outsider']
report={'scope':'live permission-gated project task activity and pagination','checks':[],'full_journey':'unverified'}
try:
 with client_for(a['user'],a['password']) as alice:
  board=request(alice,'POST',DECK+'/boards',json={'title':'Project activity '+secrets.token_hex(4),'color':'317CCC'}).json();bp=f"{DECK}/boards/{board['id']}";feed=f"/index.php/apps/cognos/boards/{board['id']}/events"
  acl=request(alice,'POST',bp+'/acl',json={'type':0,'participant':b['user'],'permissionEdit':True,'permissionShare':False,'permissionManage':False}).json()
  stack=request(alice,'POST',bp+'/stacks',json={'title':'任务','order':1}).json()
  assert request(alice,'GET',feed).json()['events']==[];report['checks'].append('new project has an explicit empty business timeline')
  card=request(alice,'POST','/ocs/v2.php/apps/cognos/api/v1.0/cards',params={'format':'json'},json={'requestId':str(uuid.uuid4()),'title':'Timeline collaboration','stackId':stack['id'],'boardId':board['id'],'users':[{'id':b['user'],'type':0}]}).json()['ocs']['data'];cid=card['id'];base=f'/index.php/apps/cognos/cards/{cid}'
 with client_for(b['user'],b['password']) as bob:
  request(bob,'PUT',base+'/state',json={'requestId':str(uuid.uuid4()),'status':'in_progress','expectedStatus':'open'})
  request(bob,'PUT',f'/ocs/v2.php/apps/cognos/api/v1.0/cards/{cid}',params={'format':'json'},json={'requestId':str(uuid.uuid4()),'changes':{'description':'Started by B'}})
  first=request(bob,'GET',feed).json()['events'];assert len(first)==3 and first[0]['actor_uid']==b['user'] and first[-1]['actor_uid']==a['user'];assert all(e['card_id']==cid and e['task_url'].endswith('/card/'+str(cid)) for e in first)
  assert first[1]['before_state']['status']=='open' and first[1]['after_state']['status']=='in_progress';report['checks'].append('shared project records A creation and B status/edit with stable actors, before/after and task links')
  # More than one real page on one task; no synthetic journal inserts.
  for i in range(52):request(bob,'PUT',base+'/state',json={'requestId':str(uuid.uuid4()),'status':'blocked' if i%2==0 else 'in_progress','expectedStatus':'in_progress' if i%2==0 else 'blocked'})
  one=request(bob,'GET',feed).json();two=request(bob,'GET',feed,params={'beforeId':one['nextBeforeId']}).json()
  assert len(one['events'])==50 and len(two['events'])==5 and two['nextBeforeId'] is None
  ids=[e['id'] for e in one['events']+two['events']];assert len(ids)==len(set(ids))==55 and ids==sorted(ids,reverse=True)
  old={e['id']:e for e in one['events']+two['events']};assert all(old[e['id']]==e for e in first)
  card_one=request(bob,'GET',base+'/events').json();card_two=request(bob,'GET',base+'/events',params={'beforeId':card_one['nextBeforeId']}).json();assert [e['id'] for e in card_one['events']+card_two['events']]==ids
  report['checks'].append('55 real events paginate 50+5 without duplicates or extra final cursor; card and project pages agree and old snapshots stay intact')
  request(bob,'GET',feed,params={'beforeId':0},allowed=(400,));request(bob,'GET',base+'/events',params={'beforeId':0},allowed=(400,))
  report['checks'].append('invalid project and card event cursors reject')
 with client_for(a['user'],a['password']) as alice:
  assert request(alice,'GET',feed).json()['events']==one['events']
  request(alice,'DELETE',bp+f"/acl/{acl['id']}")
 with client_for(b['user'],b['password']) as bob:request(bob,'GET',feed,allowed=(403,))
 with client_for(out['user'],out['password']) as outsider:request(outsider,'GET',feed,allowed=(403,))
 with client_for('','') as anonymous:request(anonymous,'GET',feed,allowed=(401,))
 report['checks'].append('both members read identical events; departed member, outsider and anonymous cannot read project timeline')
 with client_for(a['user'],a['password']) as alice:
  request(alice,'POST',bp+'/acl',json={'type':0,'participant':b['user'],'permissionEdit':True,'permissionShare':False,'permissionManage':False})
  # Native departure clears assignments; restore this new test task explicitly for UI follow-up.
  request(alice,'POST',f'/ocs/v2.php/apps/cognos/api/v1.0/cards/{cid}/assign',params={'format':'json'},json={'requestId':str(uuid.uuid4()),'userId':b['user'],'type':0})
 report['result']='passed';report['card']=cid
 (t/'evidence/board-activity-case.json').write_text(json.dumps({'board':board['id'],'card':cid,'board_url':f"/apps/deck/board/{board['id']}",'card_url':f"/apps/deck/board/{board['id']}/card/{cid}"}),encoding='utf-8')
except Exception as e:report['result']='failed';report['failure_type']=type(e).__name__;raise
finally:(t/'evidence/项目动态-API验收.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps(report,ensure_ascii=False))
