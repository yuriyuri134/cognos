import sys,json,hashlib
from pathlib import Path
t=Path(r'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial');sys.path.insert(0,str(t))
from check_tasks import client_for,request
state=json.loads((t/'.secrets/task-journey.json').read_text());case=json.loads((t/'evidence/personal-tasks-case.json').read_text());b=state['bob'];saved=t/'evidence/personal-tasks-restart-before.json'
with client_for(b['user'],b['password']) as client:
 history=request(client,'GET',f"/index.php/apps/cognos/cards/{case['card']}/events").json()
 row=next(r for r in request(client,'GET','/index.php/apps/cognos/tasks').json()['tasks'] if r['id']==case['card'])
 data={'card':case['card'],'currentState':history['currentState'],'event_sha256':hashlib.sha256(json.dumps(history['events'],sort_keys=True).encode()).hexdigest(),'list_row':row}
 if '--capture' in sys.argv:saved.write_text(json.dumps(data),encoding='utf-8');print('Captured existing blocked task and event digest before restart')
 else:
  assert data==json.loads(saved.read_text());assert row['status']=='blocked'
  report={'result':'passed','scope':'existing pre-restart task across actual four-service restart','checks':['fresh HTTP client reads the same task fields and blocked phase','personal list row matches pre-restart state','old event digest unchanged'],'card':case['card'],'full_journey':'unverified'}
  (t/'evidence/个人任务-重启验收.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8');print(json.dumps(report,ensure_ascii=False))
