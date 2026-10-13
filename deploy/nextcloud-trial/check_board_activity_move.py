import sys,json,uuid,secrets
from pathlib import Path
t=Path(r'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial');sys.path.insert(0,str(t))
from check_tasks import ROOT,DECK,client_for,request
s=json.loads((ROOT/'.secrets/task-journey.json').read_text());a,b=s['alice'],s['bob'];saved=t/'evidence/board-activity-move-case.json'
if '--reproduce' in sys.argv:
 with client_for(a['user'],a['password']) as alice:
  nonce=secrets.token_hex(5);marker='PRIVATE_TEST_ONLY_'+nonce
  private=request(alice,'POST',DECK+'/boards',json={'title':'Private move '+nonce,'color':'317CCC'}).json()
  shared=request(alice,'POST',DECK+'/boards',json={'title':'Shared move '+nonce,'color':'317CCC'}).json()
  request(alice,'POST',f"{DECK}/boards/{shared['id']}/acl",json={'type':0,'participant':b['user'],'permissionEdit':True,'permissionShare':False,'permissionManage':False})
  ps=request(alice,'POST',f"{DECK}/boards/{private['id']}/stacks",json={'title':'私有','order':1}).json();ss=request(alice,'POST',f"{DECK}/boards/{shared['id']}/stacks",json={'title':'共享','order':1}).json()
  card=request(alice,'POST','/ocs/v2.php/apps/cognos/api/v1.0/cards',params={'format':'json'},json={'requestId':str(uuid.uuid4()),'title':'Move boundary','description':marker,'stackId':ps['id'],'boardId':private['id']}).json()['ocs']['data'];cid=card['id']
  for i in range(52):request(alice,'PUT',f'/index.php/apps/cognos/cards/{cid}/state',json={'requestId':str(uuid.uuid4()),'status':'in_progress' if i%2==0 else 'open','expectedStatus':'open' if i%2==0 else 'in_progress'})
  request(alice,'PUT',f'/ocs/v2.php/apps/cognos/api/v1.0/cards/{cid}',params={'format':'json'},json={'requestId':str(uuid.uuid4()),'changes':{'description':'Public description'}})
  moved=request(alice,'PUT',f'/ocs/v2.php/apps/deck/api/v1.0/cards/{cid}',params={'format':'json'},json={'id':cid,'title':'Move boundary','stackId':ss['id'],'type':'plain','order':0,'description':'Public description','duedate':None,'deletedAt':0,'boardId':shared['id'],'owner':a['user'],'done':None}).json()['ocs']['data'];assert moved['stackId']==ss['id']
  private_events=request(alice,'GET',f"/index.php/apps/cognos/boards/{private['id']}/events").json()
  case={'card':cid,'private_board':private['id'],'shared_board':shared['id'],'marker':marker,'private_first_page':private_events}
  saved.write_text(json.dumps(case),encoding='utf-8')
 with client_for(b['user'],b['password']) as bob:
  history=request(bob,'GET',f'/index.php/apps/cognos/cards/{cid}/events').json();assert history['currentState']['description']=='Public description'
  leaked=marker in json.dumps(history['events']);assert leaked
 (t/'evidence/任务跨看板-历史泄漏复现.json').write_text(json.dumps({'scope':'new synthetic private/shared trial boards','current_description_public':True,'old_private_marker_leaked':leaked,'result':'reproduced','card':cid},ensure_ascii=False,indent=2),encoding='utf-8');print('Reproduced private journal leak after native cross-board move')
else:
 case=json.loads(saved.read_text());cid=case['card'];report={'scope':'historical board permission filtering before pagination','checks':[],'full_journey':'unverified'}
 with client_for(b['user'],b['password']) as bob:
  history=request(bob,'GET',f'/index.php/apps/cognos/cards/{cid}/events').json();assert history['events']==[] and history['nextBeforeId'] is None
  report['checks'].append('shared-board member reads public current task but no former private events or cursor')
  request(bob,'PUT',f'/index.php/apps/cognos/cards/{cid}/state',json={'requestId':str(uuid.uuid4()),'status':'blocked','expectedStatus':'open'})
  history=request(bob,'GET',f'/index.php/apps/cognos/cards/{cid}/events').json();assert len(history['events'])==1 and history['events'][0]['board_id']==case['shared_board'] and history['nextBeforeId'] is None
  assert case['marker'] not in json.dumps(history);report['checks'].append('new shared event remains visible without leaking hidden private snapshots or creating an extra page')
 with client_for(a['user'],a['password']) as alice:
  private=request(alice,'GET',f"/index.php/apps/cognos/boards/{case['private_board']}/events").json();assert private==case['private_first_page']
  first=request(alice,'GET',f'/index.php/apps/cognos/cards/{cid}/events').json();second=request(alice,'GET',f'/index.php/apps/cognos/cards/{cid}/events',params={'beforeId':first['nextBeforeId']}).json();assert len(first['events'])==50 and len(second['events'])==5 and second['nextBeforeId'] is None
  report['checks'].append('owner can still read all 55 original/current events across 50+5 pages; original private project history is unchanged')
  acl=request(alice,'POST',f"{DECK}/boards/{case['private_board']}/acl",json={'type':0,'participant':b['user'],'permissionEdit':False,'permissionShare':False,'permissionManage':False}).json()
 try:
  with client_for(b['user'],b['password']) as bob:
   first=request(bob,'GET',f'/index.php/apps/cognos/cards/{cid}/events').json();second=request(bob,'GET',f'/index.php/apps/cognos/cards/{cid}/events',params={'beforeId':first['nextBeforeId']}).json();assert case['marker'] in json.dumps(first['events']+second['events'])
  report['checks'].append('explicit permission to original board grants access to its retained history')
 finally:
  with client_for(a['user'],a['password']) as alice:request(alice,'DELETE',f"{DECK}/boards/{case['private_board']}/acl/{acl['id']}")
 with client_for(b['user'],b['password']) as bob:assert len(request(bob,'GET',f'/index.php/apps/cognos/cards/{cid}/events').json()['events'])==1
 report['checks'].append('revoking former-board permission hides its history again in a fresh client');report['result']='passed'
 (t/'evidence/任务跨看板-历史权限修复验收.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8');print(json.dumps(report,ensure_ascii=False))
