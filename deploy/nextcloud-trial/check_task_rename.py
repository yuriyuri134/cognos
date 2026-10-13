import json,uuid,sys
from pathlib import Path
t=Path(r'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial');sys.path.insert(0,str(t))
from check_tasks import request,client_for
s=json.loads((t/'.secrets/task-journey.json').read_text());f=json.loads((t/'evidence/edit-case.json').read_text())['objects'];cid=f['card']
with client_for(s['alice']['user'],s['alice']['password']) as a:
 before=request(a,'GET',f'/index.php/apps/cognos/cards/{cid}/events').json()['currentState']
 body={'requestId':str(uuid.uuid4()),'title':'Native rename '+str(uuid.uuid4())[:8],'expectedTitle':before['title'],'boardId':f['board']}
 response=request(a,'PUT',f'/index.php/apps/cognos/cards/{cid}/rename',json=body)
 card=response.json();assert card['id']==cid and card['title']==body['title']
 after=request(a,'GET',f'/index.php/apps/cognos/cards/{cid}/events').json()['currentState']
 for field in ['description','done','stack','assignees','labels','duedate','startdate','color']:assert after[field]==before[field]
 request(a,'PUT',f'/ocs/v2.php/apps/cognos/api/v1.0/cards/{cid}',allowed=(409,),params={'format':'json'},json={'requestId':str(uuid.uuid4()),'boardId':f['board']+10000,'changes':{'description':'Wrong board'}})
 assert request(a,'GET',f'/index.php/apps/cognos/cards/{cid}/events').json()['currentState']==after
report={'scope':'normal route rename response and supplied board relation','result':'passed','checks':['normal rename returns native raw card shape and preserves other fields','wrong supplied board is rejected without changing task']}
(t/'evidence/任务改名-HTTP验收.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8');print(json.dumps(report,ensure_ascii=False))
