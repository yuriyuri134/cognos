import json,sys,uuid,secrets
from pathlib import Path
t=Path(r'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial');sys.path.insert(0,str(t))
from check_tasks import ROOT,DECK,client_for,request
s=json.loads((ROOT/'.secrets/task-journey.json').read_text());a=s['alice'];case=json.loads((t/'evidence/board-activity-case.json').read_text())
report={'scope':'deleted task and archived board business history retention','checks':[],'full_journey':'unverified'};archived=False
try:
 with client_for(a['user'],a['password']) as alice:
  board=request(alice,'GET',f"{DECK}/boards/{case['board']}").json();bp=f"{DECK}/boards/{case['board']}";feed=f"/index.php/apps/cognos/boards/{case['board']}/events"
  stacks=request(alice,'GET',bp+'/stacks').json();stack=next(row['id'] for row in stacks if not row.get('deletedAt'))
  card=request(alice,'POST','/ocs/v2.php/apps/cognos/api/v1.0/cards',params={'format':'json'},json={'requestId':str(uuid.uuid4()),'title':'Disposable activity '+secrets.token_hex(5),'stackId':stack,'boardId':case['board']}).json()['ocs']['data']
  before=next(row for row in request(alice,'GET',feed).json()['events'] if row['card_id']==card['id']);assert before['task_url'] is not None
  request(alice,'DELETE',f"{bp}/stacks/{stack}/cards/{card['id']}")
  after=next(row for row in request(alice,'GET',feed).json()['events'] if row['id']==before['id']);assert after['task_url'] is None
  for field in ['before_state','after_state','actor_uid','actor_name','created_at']:assert after[field]==before[field]
  report['checks'].append('deleting only a newly created disposable task retains original event but removes its stale task link')
  before_archive=request(alice,'GET',feed).json()['events']
  request(alice,'PUT',bp,json={'title':board['title'],'color':board['color'],'archived':True});archived=True
  assert request(alice,'GET',feed).json()['events']==before_archive
  report['checks'].append('authorized archived project history remains readable with unchanged snapshots')
  empty=request(alice,'POST',DECK+'/boards',json={'title':'Empty activity '+secrets.token_hex(4),'color':'317CCC'}).json()
  assert request(alice,'GET',f"/index.php/apps/cognos/boards/{empty['id']}/events").json()=={'board':{'id':empty['id'],'title':empty['title']},'events':[],'nextBeforeId':None}
  report['checks'].append('empty project read contains no copied events from another project')
  (t/'evidence/board-activity-empty-case.json').write_text(json.dumps({'board_url':f"/apps/deck/board/{empty['id']}"}),encoding='utf-8')
 report['result']='passed'
finally:
 if archived:
  with client_for(a['user'],a['password']) as alice:request(alice,'PUT',bp,json={'title':board['title'],'color':board['color'],'archived':False})
 (t/'evidence/项目动态-历史保留验收.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps(report,ensure_ascii=False))
