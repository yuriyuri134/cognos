"""Actual create, complete, reopen, no-op/replay and durable history checks."""
import json
import secrets
import uuid
from check_tasks import ROOT, DECK, client_for, request


def run():
    state=json.loads((ROOT/'.secrets/task-journey.json').read_text())
    report={'scope':'live Cognos creation and completion/reopening; other mutations unverified',
            'checks':[],'full_journey':'unverified'}
    try:
        a,b=state['alice'],state['bob']
        with client_for(a['user'],a['password']) as alice:
            board=request(alice,'POST',DECK+'/boards',json={'title':'Lifecycle '+secrets.token_hex(5),'color':'317CCC'}).json()
            bp=f"{DECK}/boards/{board['id']}"
            acl=request(alice,'POST',bp+'/acl',json={'type':0,'participant':b['user'],'permissionEdit':True,'permissionShare':False,'permissionManage':False}).json()
            todo=request(alice,'POST',bp+'/stacks',json={'title':'待处理','order':1}).json()
            completed=request(alice,'POST',bp+'/stacks',json={'title':'已完成','order':2}).json()
            request(alice,'PUT',f"/ocs/v2.php/apps/deck/api/v1.0/stacks/{completed['id']}/done",params={'format':'json'},
                json={'boardId':board['id'],'isDone':True})
            made=request(alice,'POST','/ocs/v2.php/apps/cognos/api/v1.0/cards',params={'format':'json'},
                json={'requestId':str(uuid.uuid4()),'title':'Lifecycle task','stackId':todo['id'],'boardId':board['id'],
                    'description':'Keep this description through state changes','users':[{'id':b['user'],'type':0}]}).json()['ocs']['data']
        base=f"/index.php/apps/cognos/cards/{made['id']}"
        cp=f"{bp}/stacks/{todo['id']}/cards/{made['id']}"
        with client_for(b['user'],b['password']) as bob:
            original_events=request(bob,'GET',base+'/events').json()['events']
            assert len(original_events)==1 and original_events[0]['kind']=='created'
            assert original_events[0]['actor_uid']==a['user'] and original_events[0]['before_state'] is None
            report['checks'].append('creation history records Alice and the initial snapshot')
            key=str(uuid.uuid4())
            done=request(bob,'PUT',base+'/done',json={'requestId':key}).json()
            assert done['done'] and done['stackId']==completed['id']
            assert done['description']==made['description'] and done['title']==made['title']
            assert any(x['participant']['uid']==b['user'] for x in done['assignedUsers'])
            report['checks'].append('completion moves to configured done column and retains title/description/assignee')
            events=request(bob,'GET',base+'/events').json()['events']
            assert len(events)==2 and events[0]['kind']=='completed' and events[0]['actor_uid']==b['user']
            assert events[0]['before_state']['done'] is None and events[0]['after_state']['done']
            report['checks'].append('completion history includes stable actor and before/after state')
            request(bob,'PUT',base+'/done',json={'requestId':str(uuid.uuid4())})
            assert request(bob,'GET',base+'/events').json()['events']==events
            replay=request(bob,'PUT',base+'/done',json={'requestId':key})
            assert replay.headers.get('X-Cognos-Replayed')=='true'
            report['checks'].append('already completed and replayed operations produce no duplicate events')
            reopened=request(bob,'PUT',base+'/undone',json={'requestId':str(uuid.uuid4())}).json()
            assert reopened['done'] is None and reopened['stackId']==todo['id']
            assert reopened['description']==made['description']
            report['checks'].append('reopening restores the pre-completion column and retains description')
            latest=request(bob,'GET',base+'/events').json()['events']
            assert len(latest)==3 and latest[0]['kind']=='reopened' and latest[1:]==events
            report['checks'].append('old event snapshots remain unchanged after reopening')
            stale=request(bob,'PUT',base+'/done',json={'requestId':key})
            assert stale.json()['done'] is None and stale.headers.get('X-Cognos-Replayed')=='true'
            assert request(bob,'GET',base+'/events').json()['events']==latest
            report['checks'].append('retrying an old completion after reopening does not reapply obsolete intent')
        with client_for(a['user'],a['password']) as alice:
            assert request(alice,'GET',cp).json()['done'] is None
            assert request(alice,'GET',base+'/events').json()['events']==latest
            request(alice,'DELETE',bp+f"/acl/{acl['id']}")
        with client_for(b['user'],b['password']) as departed:
            request(departed,'GET',base+'/events',allowed=(403,))
            request(departed,'PUT',base+'/done',allowed=(403,),json={'requestId':key})
        report['checks'].append('another member sees the same state/history; departure denies history and replay')
        report['objects']={'board':board['id'],'todo':todo['id'],'completed':completed['id'],'card':made['id']}
        report['result']='passed'
        (ROOT/'evidence/lifecycle-case.json').write_text(json.dumps({'objects':report['objects'],'card_url':f"/apps/deck/board/{board['id']}/card/{made['id']}"}),encoding='utf-8')
    except Exception as exc:
        report['result']='failed';report['failure_type']=type(exc).__name__
        if isinstance(exc,RuntimeError): report['failure']=str(exc)
        raise
    finally:
        (ROOT/'evidence/任务生命周期-API验收.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
    print(json.dumps({'result':report['result'],'checks':len(report['checks'])}))


if __name__=='__main__':
    try: run()
    except Exception as exc: raise SystemExit('Lifecycle check failed: '+type(exc).__name__)
