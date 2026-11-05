const {spawn,execFileSync}=require('node:child_process');const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const container='cognos-nextcloud-trial-app-1',script='/tmp/check_work_management_concurrency.php';
const args=mode=>['exec','-u','www-data','-w','/var/www/html',container,'php',script,mode];
function processFor(mode,data){const child=spawn('docker',[...args(mode),Buffer.from(JSON.stringify(data)).toString('base64')],{windowsHide:true});let output='',error='',done=false,code;child.stdout.on('data',s=>output+=s);child.stderr.on('data',s=>error+=s);child.on('exit',c=>{done=true;code=c});return {child,get output(){return output},get error(){return error},get done(){return done},get code(){return code}};}
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
async function until(check,description){const end=Date.now()+30000;while(!check()){if(Date.now()>end)throw Error('timeout: '+description);await sleep(40);}}
async function main(){const results=[];for(const [first,second,allowed]of [['manage','finish',true],['finish','manage',false],['manage','pause',true],['pause','manage',false],['split','finish',true],['finish','split',false],['manage','unknown',true],['unknown','manage',false]]){
 const fixture=JSON.parse(execFileSync('docker',args('setup'),{encoding:'utf8'}));
 const hold=processFor('hold-'+first,fixture);await until(()=>hold.output.includes('LOCKED')||hold.done,'holder ready');assert(!hold.done,hold.error);
 const worker=processFor(second,fixture);await until(()=>worker.output.includes('ATTEMPT')||worker.done,'worker reached operation');assert(!worker.done,worker.error);
 await sleep(350);assert(!worker.done,'competing operation must wait for the held transaction');
 execFileSync('docker',['exec',container,'touch','/tmp/cognos-work-management-concurrency-'+fixture.token]);
 await until(()=>hold.done&&worker.done,'transactions complete');assert.equal(hold.code,0,hold.error);assert.equal(worker.code,0,worker.error);
 const result=JSON.parse(worker.output.slice(worker.output.indexOf('\n')+1));assert.equal(result.ok,allowed,JSON.stringify(result));if(!allowed)assert.equal(result.code,409);
 const state=JSON.parse(execFileSync('docker',[...args('verify'),Buffer.from(JSON.stringify(fixture)).toString('base64')],{encoding:'utf8'}));
 const hasFinish=first==='finish'||second==='finish',hasPause=first==='pause'||second==='pause',hasUnknown=first==='unknown'||second==='unknown';
 assert.equal(state.receipts,hasFinish?1:0);assert.equal(state.run_state,hasFinish?'delivered':hasUnknown?'unknown':'running');assert.equal(state.project_state,hasPause?'paused':'active');
 assert.equal(state.work_revision,fixture.expected+(first==='manage'||first==='split'?1:0));assert.equal(state.children,first==='split'?2:0);
 results.push(first+' holds lock; '+second+' waits; after commit '+(allowed?'succeeds':'is rejected with 409')+'; committed receipt/work versions verified');
 }
 const report={result:'pass',checks:results};fs.mkdirSync(path.join(__dirname,'evidence'),{recursive:true});fs.writeFileSync(path.join(__dirname,'evidence/work-management-concurrency.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report,null,2));}
main().catch(e=>{console.error(e.message);process.exitCode=1});