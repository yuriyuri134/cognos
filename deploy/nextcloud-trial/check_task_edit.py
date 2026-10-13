import json,uuid,secrets,sys
from pathlib import Path
ROOT=Path(r'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial');sys.path.insert(0,str(ROOT))
from check_tasks import client_for,request,DECK
def run():
 s=json.loads((ROOT/'.secrets/task-journey.json').read_text());a,b=s['alice'],s['bob']
 report={'scope':'live sparse field editing; lifecycle and assignment use their own endpoints','checks':[],'full_journey':'unverified'}
 try:
  with client_for(a['user'],a['password']) as alice:
   board=request(alice,'POST',DECK+'/boards',json={'title':'Sparse edit '+secrets.token_hex(5),'color':'317CCC'}).json();bp=f"{DECK}/boards/{board['id']}"
   acl=request(alice,'POST',bp+'/acl',json={'type':0,'participant':b['user'],'permissionEdit':True,'permissionShare':False,'permissionManage':False}).json()
   stack=request(alice,'POST',bp+'/stacks',json={'title':'待处理','order':1}).json();label=request(alice,'POST',bp+'/labels',json={'title':'资料','color':'0082C9'}).json()
   def make(title):
    return request(alice,'POST','/ocs/v2.php/apps/cognos/api/v1.0/cards',params={'format':'json'},json={'requestId':str(uuid.uuid4()),'title':title,'description':'Original description','stackId':stack['id'],'boardId':board['id'],'users':[{'id':b['user'],'type':0}],'labels':[{'id':label['id']}],'duedate':'2026-12-20T08:00:00Z','startdate':'2026-10-01T08:00:00Z','color':'317CCC'}).json()['ocs']['data']
   native=make('Native omitted completion')
   ndone=request(alice,'PUT',f"/index.php/apps/cognos/cards/{native['id']}/done",json={'requestId':str(uuid.uuid4())}).json()
   raw={key:ndone.get(key) for key in ['id','title','stackId','type','order','description','duedate','deletedAt','owner','archived','startdate','color']};raw['boardId']=board['id'];raw['description']='Native description edit'
   changed=request(alice,'PUT',f"/ocs/v2.php/apps/deck/api/v1.0/cards/{native['id']}",params={'format':'json'},json=raw).json()['ocs']['data']
   assert changed['done'] is None
   report['checks'].append('direct native whole-card update omitting done actually clears a completed task')
   card=make('Sparse completed task');cid=card['id'];base=f'/ocs/v2.php/apps/cognos/api/v1.0/cards/{cid}';events=f'/index.php/apps/cognos/cards/{cid}/events';cp=f"{bp}/stacks/{stack['id']}/cards/{cid}"
   request(alice,'PUT',f'/index.php/apps/cognos/cards/{cid}/done',json={'requestId':str(uuid.uuid4())});original=request(alice,'GET',cp).json()
   key=str(uuid.uuid4());payload={'requestId':key,'changes':{'description':'Only the description changed'},'expected':{'description':original['description']}}
   edited=request(alice,'PUT',base,params={'format':'json'},json=payload).json()['ocs']['data']
   assert edited['description']=='Only the description changed'
   for field in ['title','done','stackId','owner','assignedUsers','labels','duedate','startdate','color','order']:assert edited[field]==original[field]
   report['checks'].append('description-only edit preserves completion, assignees, labels, dates, color, owner and column')
   history=request(alice,'GET',events).json()['events'];assert len(history)==3 and history[0]['kind']=='edited' and history[0]['actor_uid']==a['user']
   assert history[0]['before_state']['description']==original['description'] and history[0]['after_state']['description']==edited['description']
   replay=request(alice,'PUT',base,params={'format':'json'},json=payload);assert replay.headers.get('X-Cognos-Replayed')=='true'
   request(alice,'PUT',base,params={'format':'json'},json={'requestId':str(uuid.uuid4()),'changes':{'description':edited['description']}})
   assert request(alice,'GET',events).json()['events']==history
   report['checks'].append('actor and before/after values are recorded once; replay and same-value no-op add no event')
  with client_for(b['user'],b['password']) as bob:
   newer=request(bob,'PUT',base,params={'format':'json'},json={'requestId':str(uuid.uuid4()),'changes':{'description':'Bob newer text'},'expected':{'description':edited['description']}}).json()['ocs']['data'];assert newer['done']==original['done']
  with client_for(a['user'],a['password']) as alice:
   stale=request(alice,'PUT',base,allowed=(409,),params={'format':'json'},json={'requestId':str(uuid.uuid4()),'changes':{'description':'Alice stale text'},'expected':{'description':edited['description']}})
   assert stale.json()['ocs']['data']['conflictFields']==['description']
   assert request(alice,'GET',cp).json()['description']=='Bob newer text'
   report['checks'].append('stale same-field edit conflicts instead of overwriting another member')
   ret=request(alice,'PUT',base,params={'format':'json'},json=payload);assert ret.headers.get('X-Cognos-Replayed')=='true' and ret.json()['ocs']['data']['description']=='Bob newer text'
   title=request(alice,'PUT',base,params={'format':'json'},json={'requestId':str(uuid.uuid4()),'changes':{'title':'Alice revised title'},'expected':{'title':original['title']}}).json()['ocs']['data'];assert title['description']=='Bob newer text'
   report['checks'].append('old operation replay returns current task; independent title edit retains newer description')
   baseline=request(alice,'GET',events).json()['events']
   for change in [{'title':''},{'done':None},{'stackId':999999},{'duedate':'2026-02-31T08:00:00Z'}]:
    request(alice,'PUT',base,allowed=(400,),params={'format':'json'},json={'requestId':str(uuid.uuid4()),'changes':change})
   assert request(alice,'GET',events).json()['events']==baseline
   request(alice,'PUT',base,allowed=(409,),params={'format':'json'},json={**payload,'changes':{'description':'Different request payload'}})
   report['checks'].append('invalid/unsupported fields and changed accepted request payload are rejected without another event')
   cleared=request(alice,'PUT',base,params={'format':'json'},json={'requestId':str(uuid.uuid4()),'changes':{'duedate':None,'startdate':None,'color':None}}).json()['ocs']['data']
   assert cleared['duedate'] is None and cleared['startdate'] is None and cleared['color'] is None and cleared['done']==original['done']
   latest=request(alice,'GET',events).json()['events'];assert latest[-3:]==history
   request(alice,'DELETE',bp+f"/acl/{acl['id']}")
  with client_for(b['user'],b['password']) as departed:
   request(departed,'PUT',base,allowed=(403,),params={'format':'json'},json=payload)
  report['checks'].append('explicit null clears only requested optional fields; original events unchanged; departure denies edits/replay')
  report['objects']={'board':board['id'],'stack':stack['id'],'card':cid};report['result']='passed'
  (ROOT/'evidence/edit-case.json').write_text(json.dumps({'objects':report['objects'],'card_url':f'/apps/deck/board/{board["id"]}/card/{cid}'}),encoding='utf-8')
 except Exception as exc:report['result']='failed';report['failure_type']=type(exc).__name__;raise
 finally:(ROOT/'evidence/任务稀疏编辑-API验收.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
 print(json.dumps({'result':report['result'],'checks':len(report['checks'])}))
if __name__=='__main__':run()
