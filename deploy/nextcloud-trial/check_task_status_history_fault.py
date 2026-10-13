import sys, json, uuid, secrets
from pathlib import Path
TRIAL=Path(r'C:\Users\lnp\Documents\Playground\cognos\deploy\nextcloud-trial')
sys.path.insert(0,str(TRIAL))
from check_tasks import ROOT,DECK,client_for,request
from check_task_creation_history_fault import sql

state=json.loads((ROOT/'.secrets/task-journey.json').read_text())
nonce=secrets.token_hex(6)
name='cognos_phase_fault_'+nonce
report={'scope':'isolated live four-state progress transaction','checks':[],'full_journey':'unverified'}
try:
    a=state['alice']
    with client_for(a['user'],a['password']) as client:
        board=request(client,'POST',DECK+'/boards',json={'title':'State fault '+nonce,'color':'317CCC'}).json()
        bp=f"{DECK}/boards/{board['id']}"
        todo=request(client,'POST',bp+'/stacks',json={'title':'待处理','order':1}).json()
        done=request(client,'POST',bp+'/stacks',json={'title':'已完成','order':2}).json()
        request(client,'PUT',f"/ocs/v2.php/apps/deck/api/v1.0/stacks/{done['id']}/done",params={'format':'json'},json={'boardId':board['id'],'isDone':True})
        card=request(client,'POST','/ocs/v2.php/apps/cognos/api/v1.0/cards',params={'format':'json'},json={'requestId':str(uuid.uuid4()),'title':'Fault card '+nonce,'description':'Retain me','stackId':todo['id'],'boardId':board['id']}).json()['ocs']['data']
        cid=int(card['id']); base=f'/index.php/apps/cognos/cards/{cid}'
        before=request(client,'GET',base+'/events').json()
        activity=int(sql(f"SELECT count(*) FROM oc_activity WHERE app='deck' AND object_id={cid};"))
        sql(f"""CREATE FUNCTION {name}() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.card_id={cid} AND NEW.kind='status_changed' THEN RAISE EXCEPTION 'isolated state journal fault'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER {name} BEFORE INSERT ON oc_cognos_task_events FOR EACH ROW EXECUTE FUNCTION {name}();""")
        key=str(uuid.uuid4())
        response=request(client,'PUT',base+'/state',allowed=(500,),json={'requestId':key,'status':'blocked','expectedStatus':'open'})
        report['checks'].append('journal INSERT failure returns HTTP 500')
    with client_for(a['user'],a['password']) as fresh:
        current=request(fresh,'GET',f"{bp}/stacks/{todo['id']}/cards/{cid}").json()
        assert current['done'] is None and current['stackId']==todo['id']
        assert current['description']==card['description'] and current['title']==card['title']
        report['checks'].append('fresh client reads original state, column, title and description')
        assert request(fresh,'GET',base+'/events').json()==before
        report['checks'].append('original journal remains unchanged')
    assert int(sql(f"SELECT count(*) FROM oc_activity WHERE app='deck' AND object_id={cid};"))==activity
    report['checks'].append('native activity rows also roll back')
    assert int(sql(f"SELECT count(*) FROM oc_cognos_task_requests WHERE request_id='{key}';"))==0
    report['checks'].append('failed operation leaves no recovery reservation')
    assert int(sql(f"SELECT count(*) FROM oc_cognos_task_progress WHERE card_id={cid};"))==0
    report['checks'].append('new progress row also rolls back')
    sql(f'DROP TRIGGER {name} ON oc_cognos_task_events; DROP FUNCTION {name}();')
    with client_for(a['user'],a['password']) as fresh:
        result=request(fresh,'PUT',base+'/state',json={'requestId':key,'status':'blocked','expectedStatus':'open'}).json()
        current=result['card'];assert result['currentState']['status']=='blocked' and current['done'] is None and current['stackId']==todo['id']
        events=request(fresh,'GET',base+'/events').json()['events']
        assert len(events)==2 and events[0]['kind']=='status_changed'
        report['checks'].append('same operation succeeds after removing the fault, with one state event')
    report['result']='passed'; report['card']=cid
except Exception as exc:
    report['result']='failed'; report['failure_type']=type(exc).__name__
    raise
finally:
    sql(f'DROP TRIGGER IF EXISTS {name} ON oc_cognos_task_events; DROP FUNCTION IF EXISTS {name}();')
    report['fault_hook_removed']=int(sql(f"SELECT count(*) FROM pg_trigger WHERE tgname='{name}';"))==0
    (ROOT/'evidence/任务四状态-历史故障回滚.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps(report,ensure_ascii=False))
