import json,secrets,uuid,sys
from pathlib import Path
t=Path(r'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial');sys.path.insert(0,str(t))
from check_tasks import client_for,request,DECK
s=json.loads((t/'.secrets/task-journey.json').read_text());a,b=s['alice'],s['bob'];report={'scope':'read-only membership and archived task/board write rules','checks':[],'full_journey':'unverified'}
try:
 with client_for(a['user'],a['password']) as owner:
  board=request(owner,'POST',DECK+'/boards',json={'title':'Read only '+secrets.token_hex(5),'color':'317CCC'}).json();bp=f"{DECK}/boards/{board['id']}"
  request(owner,'POST',bp+'/acl',json={'type':0,'participant':b['user'],'permissionEdit':False,'permissionShare':False,'permissionManage':False})
  stack=request(owner,'POST',bp+'/stacks',json={'title':'工作列','order':1}).json()
  card=request(owner,'POST','/ocs/v2.php/apps/cognos/api/v1.0/cards',params={'format':'json'},json={'requestId':str(uuid.uuid4()),'title':'Readonly task','description':'Preserve me','stackId':stack['id'],'boardId':board['id']}).json()['ocs']['data'];cid=card['id'];base=f'/index.php/apps/cognos/cards/{cid}';ocs=f'/ocs/v2.php/apps/cognos/api/v1.0/cards/{cid}'
 with client_for(b['user'],b['password']) as reader:
  current=request(reader,'GET',base+'/events').json();assert current['canEdit'] is False
  request(reader,'PUT',base+'/state',allowed=(403,),json={'requestId':str(uuid.uuid4()),'status':'blocked'})
  request(reader,'PUT',ocs,allowed=(403,),params={'format':'json'},json={'requestId':str(uuid.uuid4()),'changes':{'description':'No'}})
  request(reader,'POST',ocs+'/assign',allowed=(403,),params={'format':'json'},json={'requestId':str(uuid.uuid4()),'userId':b['user']})
  assert request(reader,'GET',base+'/events').json()==current
 report['checks'].append('reader sees status/history but all three write paths deny without changes')
 with client_for(a['user'],a['password']) as owner:
  key=str(uuid.uuid4());payload={'requestId':key,'status':'in_progress','expectedStatus':'open'};request(owner,'PUT',base+'/state',json=payload)
  request(owner,'PUT',f'/index.php/apps/deck/cards/{cid}/archive',json={})
  archived=request(owner,'GET',base+'/events').json();assert archived['currentState']['archived'] and not archived['canEdit']
  for path,method,body,params in [(base+'/state','PUT',payload,{}),(base+'/done','PUT',{'requestId':str(uuid.uuid4())},{}),(ocs,'PUT',{'requestId':str(uuid.uuid4()),'changes':{'description':'No'}},{'format':'json'}),(ocs+'/assign','POST',{'requestId':str(uuid.uuid4()),'userId':a['user']},{'format':'json'})]:
   request(owner,method,path,allowed=(409,),json=body,params=params)
  assert request(owner,'GET',base+'/events').json()==archived
  report['checks'].append('archived task rejects status, completion, edits and assignment, including old replay')
  request(owner,'PUT',f'/index.php/apps/deck/cards/{cid}/unarchive',json={})
  unarchived=request(owner,'GET',base+'/events').json();assert unarchived['canEdit'] and unarchived['currentState']['status']=='in_progress'
  request(owner,'PUT',base+'/state',json={'requestId':str(uuid.uuid4()),'status':'blocked','expectedStatus':'in_progress'})
  report['checks'].append('unarchive retains prior phase and permits later explicit state change')
  request(owner,'PUT',bp,json={'title':board['title'],'color':board['color'],'archived':True})
  frozen=request(owner,'GET',base+'/events').json();assert not frozen['canEdit'] and not frozen['currentState']['archived']
  request(owner,'PUT',base+'/state',allowed=(409,),json={'requestId':str(uuid.uuid4()),'status':'open','expectedStatus':'blocked'})
  request(owner,'PUT',ocs,allowed=(409,),params={'format':'json'},json={'requestId':str(uuid.uuid4()),'changes':{'description':'No'}})
  request(owner,'POST',ocs+'/assign',allowed=(409,),params={'format':'json'},json={'requestId':str(uuid.uuid4()),'userId':a['user']})
  assert request(owner,'GET',base+'/events').json()==frozen
  report['checks'].append('archived board also freezes a non-archived task across all three writers')
  request(owner,'PUT',bp,json={'title':board['title'],'color':board['color'],'archived':False})
 report['result']='passed';report['objects']={'board':board['id'],'card':cid}
 (t/'evidence/status-readonly-case.json').write_text(json.dumps({'card_url':f'/apps/deck/board/{board["id"]}/card/{cid}','objects':report['objects']}),encoding='utf-8')
except Exception as exc:report['result']='failed';report['failure_type']=type(exc).__name__;raise
finally:(t/'evidence/任务状态-只读与归档验收.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps({'result':report['result'],'checks':len(report['checks'])}))
