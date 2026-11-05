"""Real Codex MCP entry, then a real Claude revision, then Codex drift-aware hand-back.

The Codex run id persists across two independent CLI invocations; no claim that both models generate continuously.
"""
from __future__ import annotations
import argparse
import importlib.util
import json
import os
from pathlib import Path
import time

ROOT=Path(__file__).resolve().parent
REPO=ROOT.parent.parent
spec=importlib.util.spec_from_file_location('v12_helpers',ROOT/'check_v12.py');helpers=importlib.util.module_from_spec(spec);spec.loader.exec_module(helpers)
spec=importlib.util.spec_from_file_location('codex_entry',REPO/'tools/cognos-space-mcp/codex_entry.py');entry=importlib.util.module_from_spec(spec);spec.loader.exec_module(entry)
report={'at':time.strftime('%Y-%m-%dT%H:%M:%S'),'checks':[]}
parser=argparse.ArgumentParser();parser.add_argument('--resume-case',type=Path);options=parser.parse_args()
case=options.resume_case or ROOT/'evidence'/'codex-entry'/time.strftime('%Y%m%d-%H%M%S')
if options.resume_case:
    report=json.loads((case/'report.json').read_text(encoding='utf-8'));report.pop('error',None);report.pop('result',None)


def tools_used(path):
    calls=[]
    for line in (path/'events.jsonl').read_text(encoding='utf-8').splitlines():
        try: event=json.loads(line)
        except ValueError: continue
        item=event.get('item',{})
        if item.get('type')=='mcp_tool_call' and item.get('status')=='completed': calls.append(item.get('tool'))
    return sorted(set(calls))


def codex(prompt,phase):
    out=case/phase
    entry.run(report['space_id'],prompt,out,subject='Codex',model='gpt-6.1-sol')
    calls=tools_used(out);report[phase+'_tools']=calls
    return calls


def main():
    req=helpers.req;rid=helpers.rid
    if options.resume_case and (report.get('new_decision') or report.get('claude_delegation_id')):
        raise RuntimeError('案例已经进入后续外部动作阶段，请核对已有运行；本脚本不会自动重复派发')
    if not options.resume_case:
        p=req('/projects/data','POST',{'title':'验证：Codex 与 Claude 共同进入 '+time.strftime('%H:%M:%S'),'request_id':rid()})
        loc=p['location_id'];pid=p['project_id']
        req(f'/projects/locations/{loc}/content','PUT',{'expected_revision':1,'content':'入口设计：先给当前工作稿，材料暂时只记录链接。','request_id':rid(),'source_message_ids':[]})
        decision=req(f'/projects/{pid}/decisions','POST',{'content':'验证场景：先核对来源，不自动采用或启动工作子项。','request_id':rid()})
        work=req(f'/projects/locations/{loc}/work-items','POST',{'description':'核对当前材料的作者、取得范围与后来的更正','owner':'待明确安排','deliverable':'一份来源核验建议','request_id':rid()})
        space=str(req(f'/projects/locations/{loc}/data')['location']['creation_id'])
        report.update(project_id=pid,location_id=loc,space_id=space,work_id=work['id'],initial_decision=decision['decision_id'])
    pid=report['project_id'];loc=report['location_id'];space=report['space_id'];work={'id':report['work_id']}
    os.environ.update(COGNOS_URL='http://127.0.0.1:8780',COGNOS_USER='cognos-admin',
                      COGNOS_APP_PASSWORD=(ROOT/'.secrets/codex-app-password').read_text(encoding='utf-8').strip(),
                      COGNOS_MCP_STATE_DIR=str(case/'handoff-state'))
    if not options.resume_case:
        calls=codex(f'''你是 Cognos 里的 Codex 参与者，进入 creation {space} 核对这一处入口和来源核验工作。
    使用 Cognos MCP：enter_space 读适用决定和当前稿；start_work 登记目的为“Codex 核对来源安排”；note_basis 记下当前内容版本 2；list_work_items 和 get_work_item 读取工作 {work['id']}；preview_work_split 预览把它拆成“核对作者和原文位置”“核对取得范围与后来更正”两份。不修改、不采用、不真的拆分，也不执行子项。
    这次先保留未完运行，不用 hand_back。最终简短说明看到的约束、待核实内容和 run_id。后续会有人改稿，再由你按同一 run_id 接着核对。''','phase1')
        for tool in ('enter_space','start_work','note_basis','list_work_items','get_work_item','preview_work_split'): assert tool in calls, f'actual Codex did not call {tool}'
    runs=helpers.overview(pid,loc)['activity']['runs'];own=[r for r in runs if r['subject']=='Codex'];assert len(own)==1
    run_id=own[0]['run_id'];report['codex_run_id']=run_id
    assert req('/projects/work/'+work['id'])['item']['state']=='open'
    report['checks'].append('actual Codex used the common MCP entry/start/basis/work read/preview path; preview did not split the work')
    new=req(f'/projects/{pid}/decisions','POST',{'content':'验证场景新增决定：来源摘要必须注明实际取得范围。','request_id':rid()});report['new_decision']=new['decision_id']
    d=req(f'/projects/locations/{loc}/delegations','POST',{'ask':'这是多参与者接入验证。请用 write_content 把当前稿改为：入口设计：先给适用的决定和当前工作稿；材料摘要同时注明作者、原文位置和实际取得范围。除此不采用、不执行工作子项。','may_edit':True,'may_adopt':False,'request_id':rid()})
    assert d['state']=='sent',d.get('note')
    end=time.time()+240
    while time.time()<end:
        overview=helpers.overview(pid,loc);current=next(x for x in overview['delegations'] if x['id']==d['delegation_id'])
        if current['state']=='returned': break
        if current.get('run_state') in ('failed','unknown'): raise RuntimeError('Claude run became '+current['run_state'])
        time.sleep(3)
    assert current['state']=='returned'
    assert req(f'/projects/locations/{loc}/data')['location']['revision']==3
    report['claude_delegation_id']=d['delegation_id'];report['claude_run_id']=current['run_id']
    calls=codex(f'''继续核对 Cognos creation {space}。你之前登记的运行是 {run_id}，这次沿用它，不要 start_work 新开。
先 check_changes 看同一运行起步后新增的决定和依据差异，再 enter_space 看当前情况。给出一段简短评审：回应新增决定，指出当前稿与旧版有何变化、来源核验工作还有哪些未取得的证据。不要采用、修改当前稿或真正拆分工作。
用 hand_back 把评审交回原运行；先看工具返回的差异，按实际显示的差异确认交回（需要时 acknowledged=true）。请确保工具返回已交回后结束。''','phase2')
    assert 'check_changes' in calls and 'hand_back' in calls
    overview=helpers.overview(pid,loc);recent=next(r for r in overview['activity']['recent'] if r['run_id']==run_id)
    assert recent['state']=='delivered' and recent['drift_seen_at']
    assert any(x['current']==3 for x in recent['basis_changed'])
    assert req('/projects/work/'+work['id'])['item']['state']=='open'
    states=[json.loads(f.read_text(encoding='utf-8')) for f in (case/'handoff-state').glob('*.json')]
    pending=next(st['pending'] for st in states if st.get('pending',{}).get('run_id')==run_id)
    messages=req('/creations/'+space+'/messages')['messages'];answers=[m for m in messages if m['content']==pending['content'] and m['role']=='assistant']
    assert len(answers)==1
    report['result_id']=pending['result_id'];report['result_message_id']=answers[0]['id'];report['checks'].append('real Claude edited the shared draft after Codex started; Codex saw the new decision and actual revision diff, returned exactly once through the same run id, and left work state unchanged')
    report['result']='pass'


if __name__=='__main__':
    try: main()
    except Exception as error:
        report.update(result='fail',error=str(error));raise
    finally:
        path=ROOT/'evidence/codex-entry.json';path.parent.mkdir(parents=True,exist_ok=True);path.write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
        if case.exists(): (case/'report.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
        print(json.dumps(report,ensure_ascii=False,indent=2))
