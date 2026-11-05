<?php
declare(strict_types=1);
define('OC_CONSOLE',1);$_SERVER['SCRIPT_FILENAME']='/var/www/html/console.php';require '/var/www/html/lib/base.php';
set_exception_handler(function($e){fwrite(STDERR,(string)$e);exit(1);});
use OCA\Cognos\Service\{AttemptService,ProjectService,WorkItemService,CreationSpaceService,SpaceService,RunService,RestructureService};
$db=\OC::$server->get(\OCP\IDBConnection::class);$a=\OC::$server->get(AttemptService::class);$p=\OC::$server->get(ProjectService::class);$work=\OC::$server->get(WorkItemService::class);$creations=\OC::$server->get(CreationSpaceService::class);$spaces=\OC::$server->get(SpaceService::class);$runs=\OC::$server->get(RunService::class);$restructure=\OC::$server->get(RestructureService::class);
$uid='cognos-admin';$other='cognos-attempt-contract-outsider';$rid=fn()=>bin2hex(random_bytes(16));$checks=[];
function check(bool $yes,string $text):void{global $checks;if(!$yes)throw new RuntimeException($text);$checks[]=$text;}
function reject(callable $fn,int $code,string $text):void{try{$fn();}catch(Throwable $e){if($e->getCode()!==$code)throw $e;check(true,$text);return;}throw new RuntimeException('not rejected: '.$text);}
function edit(array $d):array{return array_intersect_key($d,array_flip(['title','introduction','focus','state','next_entry','location_ids','refs']));}
$f=$p->create($uid,'验证：尝试简介与阶段总结 '.time(),$rid());$child=$p->addLocation($uid,$f['project_id'],$f['location_id'],'同一尝试的第二入口',$rid());$loc=$p->location($uid,$f['location_id']);$foreign=$p->create($other,'验证：其他账号材料 '.time(),$rid());$floc=$p->location($other,$foreign['location_id']);
$message=$creations->addMessage($loc['creation_id'],$uid,'user','原消息：保留作者和原位置，不拷贝成新的原文');
$foreignMessage=$creations->addMessage($floc['creation_id'],$other,'user','别的账号的原消息');
$trace=$spaces->addTrace('creation',(string)$loc['creation_id'],['kind'=>'open','source'=>'原','author'=>$uid,'content'=>'原来的开放探索仍留在原处']);
$foreignTrace=$spaces->addTrace('creation',(string)$floc['creation_id'],['kind'=>'open','source'=>'原','author'=>$other,'content'=>'别的账号探索']);
$run=$runs->create('creation',(string)$loc['creation_id'],'conversation','creation:'.$loc['creation_id'],'验证原运行引用，不执行模型','合约验证 AI',$uid,[]);
$foreignRun=$runs->create('creation',(string)$floc['creation_id'],'conversation','creation:'.$floc['creation_id'],'别的账号运行','其他 AI',$other,[]);
$w=$work->create($uid,$f['location_id'],['description'=>'只引用的工作，不自动改变状态'],$rid());$fw=$work->create($other,$foreign['location_id'],['description'=>'别的账号的工作'],$rid());
$refs=[['type'=>'message','id'=>$message['id']],['type'=>'trace','id'=>$trace['id']],['type'=>'run','id'=>$run['id']],['type'=>'location_content','id'=>$loc['id'],'version'=>1],['type'=>'work_item','id'=>$w['id'],'version'=>1],['type'=>'link','id'=>'https://example.com/unacquired','note'=>'只留链接，没取得正文']];
$request=$rid();$minimal=['title'=>'一句话就可留下'];$created=$a->create($uid,$f['location_id'],$minimal,$request);
check($a->create($uid,$f['location_id'],$minimal,$request)===$created,'minimal attempt needs only a title; same request replays the same identity');
$d=$a->detail($uid,$created['id']);check($d['introduction']===''&&$d['summaries']===[]&&$d['location_ids']===[$f['location_id']],'unknown introduction and stage details remain empty');
$data=['title'=>'理论推演也可以是尝试','introduction'=>str_repeat('留下仍可继续的原问题。',150),'focus'=>'现在看可回查的来源','state'=>'open','next_entry'=>'从第二个入口再看一次','location_ids'=>[$f['location_id'],$child['location_id']],'refs'=>$refs];
$updateId=$rid();$updated=$a->update($uid,$created['id'],1,$data,$updateId);check($a->update($uid,$created['id'],1,$data,$updateId)===$updated,'update receipt replay does not create another version');
$d=$a->detail($uid,$created['id']);check($d['revision']===2&&count($d['revisions'])===2&&$d['revisions'][1]['snapshot']['title']===$minimal['title']&&$d['author']===$uid,'full old introduction, authors and original relationships remain in immutable revisions');
check(count($a->list($uid,$f['project_id']))===1&&$a->list($uid,$f['project_id'],$child['location_id'])[0]['id']===$created['id'],'one attempt appears once in project and at both stable location entrances');
reject(fn()=>$a->update($uid,$created['id'],1,$data,$rid()),409,'stale attempt version is rejected');
reject(fn()=>$a->detail($other,$created['id']),404,'another account cannot read an attempt');
reject(fn()=>$a->update($other,$created['id'],2,$data,$rid()),404,'another account cannot edit an attempt');
reject(fn()=>$a->create($uid,$f['location_id'],['title'=>'非法关联','location_ids'=>[$f['location_id'],$foreign['location_id']]],$rid()),404,'locations belonging to another account cannot be linked');
reject(fn()=>$a->update($uid,$created['id'],2,array_replace($data,['location_ids'=>[]]),$rid()),0,'an explicit empty location list is refused');
foreach([['message',$foreignMessage['id'],null],['trace',$foreignTrace['id'],null],['run',$foreignRun['id'],null],['location_content',$foreign['location_id'],1],['work_item',$fw['id'],1]] as [$type,$id,$version]){
 $ref=['type'=>$type,'id'=>$id];if($version!==null)$ref['version']=$version;
 reject(fn()=>$a->create($uid,$f['location_id'],['title'=>'不能引入不可访问原记录','refs'=>[$ref]],$rid()),404,'source ownership checked for '.$type);
 reject(fn()=>$a->readReference($uid,$ref),404,'direct source read refuses foreign '.$type);
}
foreach(['location_content','work_item'] as $type){$id=$type==='work_item'?$w['id']:$loc['id'];reject(fn()=>$a->readReference($uid,['type'=>$type,'id'=>$id,'version'=>99999]),404,'nonexistent exact source version is rejected for '.$type);}
reject(fn()=>$a->create($uid,$f['location_id'],['title'=>'缺版本','refs'=>[['type'=>'work_item','id'=>$w['id']]]],$rid()),0,'versioned work reference cannot silently follow current version');
reject(fn()=>$a->readReference($uid,['type'=>'link','id'=>'javascript:alert(1)']),0,'external reference cannot introduce an executable URL');
foreach($refs as $ref){$read=$a->readReference($uid,$ref);check($read['status']===(($ref['type']==='link')?'link_only':'available'),'explicit reference reads actual '.$ref['type'].' record');if($ref['type']==='message')check($read['record']['content']===$message['content']&&$read['record']['role']==='user','original message retains its original role and content');if($ref['type']==='trace')check($read['record']['author']===$uid&&$read['record']['content']===$trace['content'],'original trace retains its source author and content');if($ref['type']==='link')check($read['record']===null,'unacquired link never claims to contain the remote document');}
$summaryData=['content'=>'这一阶段只确认实际取得的范围，仍有未解问题。','covered_refs'=>$refs];$summaryId=$rid();$s1=$a->addSummary($uid,$created['id'],$summaryData,$summaryId);check($a->addSummary($uid,$created['id'],$summaryData,$summaryId)===$s1,'append summary request is idempotent');
$revised=['title'=>'后来的修订','content'=>'原判断有变化，旧版仍可回查。'];$summaryUpdateId=$rid();$sr=$a->updateSummary($uid,$s1['id'],1,$revised,$summaryUpdateId);check($a->updateSummary($uid,$s1['id'],1,$revised,$summaryUpdateId)===$sr,'summary revision receipt is idempotent');
$stage=$a->detail($uid,$created['id'])['summaries'][0];check($stage['revision']===2&&$stage['author']===$uid&&count($stage['covered_refs'])===6&&count($stage['revisions'])===2&&$stage['revisions'][1]['snapshot']['content']===$summaryData['content'],'summary revision retains exact original content, author and covered refs; omitted refs do not disappear');
reject(fn()=>$a->updateSummary($uid,$s1['id'],1,$revised,$rid()),409,'stale summary version is rejected');
reject(fn()=>$a->updateSummary($other,$s1['id'],2,$revised,$rid()),404,'other account cannot revise a stage summary');
$manyRefs=[];for($i=0;$i<12;$i++)$manyRefs[]=['type'=>'link','id'=>'https://example.com/stage-source/'.$i,'note'=>str_repeat('备注',2000)];
$s2=$a->addSummary($uid,$created['id'],['content'=>str_repeat('后来这一阶段仍然开放。',180),'coverage'=>str_repeat('范围说明。',90),'covered_refs'=>$manyRefs],$rid());
$a->updateSummary($uid,$s1['id'],2,['content'=>'修订前一阶段不替代后一阶段。','stage_order'=>99999],$rid());
$ordered=$a->detail($uid,$created['id'])['summaries'];check($ordered[0]['id']===$s2['id']&&$ordered[0]['stage_order']===2&&$ordered[1]['stage_order']===1,'same-second stages use service-assigned append order; revision cannot forge a later stage');
$projection=$a->entrySummary($uid,$f['location_id']);$first=array_values(array_filter($projection['items'],fn($item)=>$item['id']===$created['id']))[0];
check($first['latest_summary']['id']===$s2['id']&&$first['text_truncated']&&mb_strlen($first['introduction'])===1200&&mb_strlen($first['latest_summary']['content'])===1500&&mb_strlen($first['latest_summary']['coverage'])===300,'bounded entry reports truncation and selects newest stage by creation rather than latest revision');
check(count($first['latest_summary']['covered_refs'])===10&&$first['latest_summary']['covered_refs_total']===12&&$first['latest_summary']['covered_refs_omitted']===2&&!array_key_exists('note',$first['latest_summary']['covered_refs'][0]),'entry source projection keeps ten exact identities, reports omissions and excludes long source notes');
$fullStage=$a->detail($uid,$created['id'])['summaries'][0];check(count($fullStage['covered_refs'])===12&&mb_strlen($fullStage['covered_refs'][0]['note'])===4000,'entry source limits never truncate stored source references or detailed stage notes');
$a2=$a->create($uid,$f['location_id'],['title'=>'同一份实测服务另一个尝试','refs'=>[['type'=>'work_item','id'=>$w['id'],'version'=>1]]],$rid());
$closed=edit($a->detail($uid,$created['id']));$closed['state']='ended';unset($closed['refs'],$closed['location_ids']);$a->update($uid,$created['id'],2,$closed,$rid());
$d=$a->detail($uid,$created['id']);check(count($d['refs'])===6&&count($d['location_ids'])===2&&$work->get($uid,$w['id'])['state']==='open','ending an attempt preserves omitted relationships and never completes referenced work');
$w0=$work->get($uid,$w['id']);$work->update($uid,$w['id'],1,['description'=>'后来修改的工作安排'],'open','','独立修改具体工作',$rid());
$original=$a->readReference($uid,['type'=>'work_item','id'=>$w['id'],'version'=>1]);check($original['record']['snapshot']['description']===$w0['description']&&$original['record']['snapshot']['revision']===1,'a pinned work reference reads the old work arrangement after later edits');
$params=['location_id'=>$child['location_id']];$preview=$restructure->preview($uid,'independent',$params);$moved=$restructure->apply($uid,'independent',$params,$preview['basis'],$rid());
check($a->list($uid,$moved['project_id'],$child['location_id'])[0]['id']===$created['id']&&$a->detail($uid,$created['id'])['revision']===3,'reorganization changes project entrance but preserves attempt identity and historical relationships');
$p->changeLifecycle($uid,$f['project_id'],'pause',1,$rid(),'验证暂停不强制结束尝试','');$d=edit($a->detail($uid,$created['id']));$d['state']='paused';$a->update($uid,$created['id'],3,$d,$rid());check($a->detail($uid,$created['id'])['state']==='paused','attempt state is editable independently while the project is paused');
$sourceCreation=$creations->create($uid,'验证：稍后不可访问的来源');$sourceMessage=$creations->addMessage($sourceCreation['id'],$uid,'user','原记录只在这里，不拷贝');
$missing=$a->create($uid,$f['location_id'],['title'=>'留住稍后缺失的来源','refs'=>[['type'=>'message','id'=>$sourceMessage['id']]]],$rid());
$q=$db->getQueryBuilder();$q->update('cognos_creations')->set('user_id',$q->createNamedParameter($other))->where($q->expr()->eq('id',$q->createNamedParameter($sourceCreation['id'],\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeStatement();
$lost=$a->detail($uid,$missing['id']);check($lost['refs'][0]['status']==='missing'&&$lost['refs'][0]['id']===(string)$sourceMessage['id'],'lost source access is shown as missing without replacing its identity');
$a->update($uid,$missing['id'],1,['title'=>'还能编辑简介，旧引用仍留着'],$rid());check($a->detail($uid,$missing['id'])['refs'][0]['status']==='missing','editing an attempt may retain an already-recorded missing reference');
reject(fn()=>$a->readReference($uid,['type'=>'message','id'=>$sourceMessage['id']]),404,'source read rechecks current access');
for($i=0;$i<6;$i++)$a->create($uid,$f['location_id'],['title'=>'入口有界检查 '.$i],$rid());$entry=$a->entrySummary($uid,$f['location_id'],5);
check(count($entry['items'])===5&&$entry['total']===9&&$entry['omitted']===4,'entry limit reports all omitted attempts rather than hiding their existence');
check($spaces->getTrace('creation',(string)$loc['creation_id'],$trace['id'])['content']===$trace['content'],'existing exploratory trace remains unchanged');
echo json_encode(['result'=>'pass','project_id'=>$f['project_id'],'location_id'=>$f['location_id'],'attempt_id'=>$created['id'],'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
