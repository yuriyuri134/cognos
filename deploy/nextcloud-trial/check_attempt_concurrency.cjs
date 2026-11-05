const {spawn,execFileSync}=require('node:child_process');
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const container='cognos-nextcloud-trial-app-1',script='/tmp/check_attempt_concurrency.php';
const args=mode=>['exec','-u','www-data','-w','/var/www/html',container,'php',script,mode];
const encoded=data=>Buffer.from(JSON.stringify(data)).toString('base64');
function processFor(mode,data){const child=spawn('docker',[...args(mode),encoded(data)],{windowsHide:true});let output='',error='',done=false,code;child.stdout.on('data',s=>output+=s);child.stderr.on('data',s=>error+=s);child.on('exit',c=>{done=true;code=c});return {child,get output(){return output},get error(){return error},get done(){return done},get code(){return code}};}
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
async function until(check,description){const end=Date.now()+30000;while(!check()){if(Date.now()>end)throw Error('timeout: '+description);await sleep(40);}}
const outcome=p=>JSON.parse(p.output.slice(p.output.indexOf('\n')+1));
async function main(){const checks=[];
 for(const name of ['append','update']){
  const fixture=JSON.parse(execFileSync('docker',args('setup'),{encoding:'utf8'}));
  const hold=processFor('hold-'+name,fixture);await until(()=>hold.output.includes('LOCKED')||hold.done,'holder ready');assert(!hold.done,hold.error||hold.output);
  const worker=processFor(name,fixture);
  try{
   await until(()=>worker.output.includes('ATTEMPT')||worker.done,'worker reached operation');assert(!worker.done,worker.error||worker.output);
   await sleep(350);assert(!worker.done,'competing operation must wait for the held transaction');
  }finally{execFileSync('docker',['exec',container,'touch','/tmp/cognos-attempt-concurrency-'+fixture.token]);}
  await until(()=>hold.done&&worker.done,'transactions complete');assert.equal(hold.code,0,hold.error);assert.equal(worker.code,0,worker.error);
  const first=outcome(hold),second=outcome(worker);assert(first.ok);
  const state=JSON.parse(execFileSync('docker',[...args('verify'),encoded(fixture)],{encoding:'utf8'}));
  if(name==='append'){
   assert(second.ok,JSON.stringify(second));assert.equal(state.summaries.length,2);
   assert.equal(state.summaries[0].id,second.result.id);assert.equal(state.summaries[0].stage_order,2);
   assert.equal(state.summaries[1].id,first.result.id);assert.equal(state.summaries[1].stage_order,1);
   assert.equal(state.entry.latest_summary.id,second.result.id);
   checks.push('two appends to one attempt serialize: second waits; stage order 1 and 2 is unique; second summary is latest in detail and entry');
  }else{
   assert.equal(second.ok,false);assert.equal(second.code,409);assert.equal(state.revision,2);assert.equal(state.title,'先取得锁的一方的简介修改');
   checks.push('two introduction updates from revision 1 serialize: second waits then receives 409; first text remains at revision 2');
  }
 }
 const report={result:'pass',checks};fs.mkdirSync(path.join(__dirname,'evidence'),{recursive:true});fs.writeFileSync(path.join(__dirname,'evidence/attempt-concurrency.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));
}
main().catch(e=>{console.error(e.message);process.exitCode=1});
