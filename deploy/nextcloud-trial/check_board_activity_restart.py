import sys,json,hashlib
from pathlib import Path
t=Path(r'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial');sys.path.insert(0,str(t))
from check_tasks import ROOT,client_for,request
s=json.loads((ROOT/'.secrets/task-journey.json').read_text());b=s['bob'];case=json.loads((t/'evidence/board-activity-case.json').read_text());moved=json.loads((t/'evidence/board-activity-move-case.json').read_text())
with client_for(b['user'],b['password']) as client:
 feed=request(client,'GET',f"/index.php/apps/cognos/boards/{case['board']}/events").json();history=request(client,'GET',f"/index.php/apps/cognos/cards/{moved['card']}/events").json()
 assert len(history['events'])==1 and moved['marker'] not in json.dumps(history)
 data={'project_digest':hashlib.sha256(json.dumps(feed,sort_keys=True).encode()).hexdigest(),'filtered_task_digest':hashlib.sha256(json.dumps(history,sort_keys=True).encode()).hexdigest()}
 path=t/'evidence/board-activity-restart-before.json'
 if '--capture' in sys.argv:path.write_text(json.dumps(data),encoding='utf-8');print('Captured project feed and filtered migrated-task history before restart')
 else:
  assert data==json.loads(path.read_text());report={'scope':'existing project history and filtered moved task across actual four-service restart','result':'passed','checks':['project feed digest unchanged','moved task current state and one visible shared event unchanged','former private event still excluded'],'full_journey':'unverified'}
  (t/'evidence/项目动态-重启验收.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8');print(json.dumps(report,ensure_ascii=False))
