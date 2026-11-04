const {spawn,execFileSync}=require('node:child_process');const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const container='cognos-nextcloud-trial-app-1',script='/tmp/check_dispatch_queue_concurrency.php';
const args=mode=>['exec','-u','www-data','-w','/var/www/html',container,'php',script,mode];
function processFor(mode,data){const child=spawn('docker',[...args(mode),Buffer.from(JSON.stringify(data)).toString('base64')],{windowsHide:true});let output='',error='',done=false,code;child.stdout.on('data',s=>output+=s);child.stderr.on('data',s=>error+=s);child.on('exit',c=>{done=true;code=c});return {child,get output(){return output},get error(){return error},get done(){return done},get code(){return code}};}
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
async function until(check,description){const end=Date.now()+30000;while(!check()){if(Date.now()>end)throw Error('timeout: '+description);await sleep(40);}}
async function main(){
 const fixture={space:'cold-queue-'+crypto.randomUUID(),token:crypto.randomUUID()};const results=[];let firstId;
 for(const op of ['enqueue','claim']){
  fixture.token=crypto.randomUUID();const hold=processFor('hold-'+op,fixture);await until(()=>hold.output.includes('LOCKED')||hold.done,'holder locked');assert(!hold.done,hold.error);
  const worker=processFor(op,fixture);await until(()=>worker.output.includes('ATTEMPT')||worker.done,'worker attempted');assert(!worker.done,worker.error);
  await sleep(300);assert(!worker.done,'operation must block behind real database transaction');
  execFileSync('docker',['exec',container,'touch','/tmp/cognos-queue-release-'+fixture.token]);await until(()=>hold.done&&worker.done,'complete');assert.equal(hold.code,0,hold.error);assert.equal(worker.code,0,worker.error);
  const a=JSON.parse(hold.output.slice(hold.output.indexOf('\n')+1)),b=JSON.parse(worker.output.slice(worker.output.indexOf('\n')+1));
  if(op==='enqueue'){assert(a.id!==b.id);assert.equal(Number(a.ref_id),1);assert.equal(Number(b.ref_id),2);firstId=a.id;results.push('cold queue scope race preserves both enqueues');}
  else{assert.equal(a.id,firstId);assert.equal(b,null);results.push('competing claim waits and cannot acquire another item while first is claimed');}
 }
 const report={result:'pass',checks:results};fs.mkdirSync(path.join(__dirname,'evidence'),{recursive:true});fs.writeFileSync(path.join(__dirname,'evidence/dispatch-queue-concurrency.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));
}
main().catch(e=>{console.error(e.message);process.exitCode=1});