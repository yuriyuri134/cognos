from pathlib import Path
import sys,json,uuid,secrets
ROOT=Path(r'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial')
sys.path.insert(0,str(ROOT))
from check_tasks import DECK,client_for,request
def run():
 state=json.loads((ROOT/'.secrets/task-journey.json').read_text());a,b=state['alice'],state['bob']; outsider=state['outsider']
 report={'scope':'live assignment transaction API','checks':[],'full_journey':'unverified'}
 try:
  with client_for(a['user'],a['password']) as alice:
   board=request(alice,'POST',DECK+'/boards',json={'title':'Assignments '+secrets.token_hex(5),'color':'317CCC'}).json();bp=f"{DECK}/boards/{board['id']}"
   acl=request(alice,'POST',bp+'/acl',json={'type':0,'participant':b['user'],'permissionEdit':True,'permissionShare':False,'permissionManage':False}).json()
   stack=request(alice,'POST',bp+'/stacks',json={'title':'待处理','order':1}).json()
   card=request(alice,'POST','/ocs/v2.php/apps/cognos/api/v1.0/cards',params={'format':'json'},json={'requestId':str(uuid.uuid4()),'title':'Assignment task','description':'Keep this task intact','stackId':stack['id'],'boardId':board['id']}).json()['ocs']['data']
   cid=card['id'];base=f'/ocs/v2.php/apps/cognos/api/v1.0/cards/{cid}';events=f'/index.php/apps/cognos/cards/{cid}/events';cp=f"{bp}/stacks/{stack['id']}/cards/{cid}"
   # Completing in a normal column allows a direct preservation check on assignments.
   request(alice,'PUT',f'/index.php/apps/cognos/cards/{cid}/done',json={'requestId':str(uuid.uuid4())})
   original=request(alice,'GET',cp).json()
   key=str(uuid.uuid4());payload={'requestId':key,'userId':b['user'],'type':0,'boardId':board['id']}
   assigned=request(alice,'POST',base+'/assign',params={'format':'json'},json=payload).json()['ocs']['data']
   assert assigned['participant']['uid']==b['user'] and assigned['cardId']==cid
   replay=request(alice,'POST',base+'/assign',params={'format':'json'},json=payload)
   assert replay.headers.get('X-Cognos-Replayed')=='true' and replay.json()['ocs']['data']==assigned
   report['checks'].append('assignment and replay return the same native assignment acknowledgement')
   history=request(alice,'GET',events).json()['events'];assert len(history)==3 and history[0]['kind']=='assigned'
   assert history[0]['actor_uid']==a['user'] and len(history[0]['after_state']['assignees'])==1
   request(alice,'POST',base+'/assign',params={'format':'json'},json={**payload,'requestId':str(uuid.uuid4())})
   assert request(alice,'GET',events).json()['events']==history
   report['checks'].append('same-state assignment is a no-op without duplicate events')
   current=request(alice,'GET',cp).json()
   for field in ['title','description','done','stackId','owner','labels','duedate','startdate','color']:assert current[field]==original[field]
   report['checks'].append('assignment preserves completed state and unrelated task fields')
   request(alice,'POST',base+'/assign',allowed=(400,),params={'format':'json'},json={'requestId':str(uuid.uuid4()),'userId':outsider['user']})
   assert request(alice,'GET',events).json()['events']==history
   report['checks'].append('project outsider assignment is rejected without event or task changes')
  with client_for(b['user'],b['password']) as bob:
   current=request(bob,'GET',cp).json();assert any(x['participant']['uid']==b['user'] for x in current['assignedUsers'])
   unkey=str(uuid.uuid4());unpayload={'requestId':unkey,'userId':b['user'],'type':0}
   removed=request(bob,'PUT',base+'/unassign',params={'format':'json'},json=unpayload).json()['ocs']['data'];assert removed['id']==assigned['id']
   replay=request(bob,'PUT',base+'/unassign',params={'format':'json'},json=unpayload);assert replay.headers.get('X-Cognos-Replayed')=='true'
   assert replay.json()['ocs']['data']==removed
   current=request(bob,'GET',cp).json();assert not current['assignedUsers']
   for field in ['title','description','done','stackId','owner','labels','duedate','startdate','color']:assert current[field]==original[field]
   final=request(bob,'GET',events).json()['events'];assert len(final)==4 and final[0]['kind']=='unassigned' and final[0]['actor_uid']==b['user']
   assert final[1:]==history and final[0]['before_state']['assignees'] and not final[0]['after_state']['assignees']
   report['checks'].append('Bob cancels own assignment; completion and original event snapshots remain intact')
   request(bob,'PUT',base+'/unassign',params={'format':'json'},json={**unpayload,'requestId':str(uuid.uuid4())})
   assert request(bob,'GET',events).json()['events']==final
   report['checks'].append('cancellation replay and absent-assignee no-op create no duplicate events')
  with client_for(a['user'],a['password']) as alice:
   assert not request(alice,'GET',cp).json()['assignedUsers']
   stale=request(alice,'POST',base+'/assign',allowed=(409,),params={'format':'json'},json=payload)
   assert stale.headers.get('X-Cognos-Operation-Confirmed')=='true'
   assert not request(alice,'GET',cp).json()['assignedUsers']
   assert request(alice,'GET',events).json()['events']==final
   report['checks'].append('old assignment replay after cancellation reports changed state without reassigning or another event')
   request(alice,'POST',base+'/assign',allowed=(409,),params={'format':'json'},json={**payload,'userId':a['user']})
   request(alice,'DELETE',bp+f"/acl/{acl['id']}")
  with client_for(b['user'],b['password']) as gone:
   request(gone,'PUT',base+'/unassign',allowed=(403,),params={'format':'json'},json=unpayload)
  report['checks'].append('cross-member fresh read agrees; changed request input conflicts; departure denies replay')
  report['objects']={'board':board['id'],'stack':stack['id'],'card':cid};report['result']='passed'
 except Exception as exc:report['result']='failed';report['failure_type']=type(exc).__name__;raise
 finally:(ROOT/'evidence/任务指派取消-API验收.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
 print(json.dumps({'result':report['result'],'checks':len(report['checks'])}))
if __name__=='__main__':run()
