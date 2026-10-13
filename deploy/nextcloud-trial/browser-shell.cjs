const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),http=require('node:http');
const {spawn}=require('node:child_process');
const readline=require('node:readline');
const state=JSON.parse(fs.readFileSync(path.join(__dirname,'.secrets/task-journey.json'),'utf8'));
const out=process.env.COGNOS_TRIAL_EVIDENCE_DIR || path.join(__dirname,'evidence');
fs.mkdirSync(out,{recursive:true});
const port=9821;
const profile=fs.mkdtempSync(path.join(os.tmpdir(),'cognos-ui-journey-'));
const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless=new','--no-sandbox','--disable-gpu','--no-first-run',`--remote-debugging-port=${port}`,`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:'ignore'});
const wait=ms=>new Promise(r=>setTimeout(r,ms));
function get(url){return new Promise((r,j)=>{http.get(url,res=>{let body='';res.on('data',d=>body+=d);res.on('end',()=>{try{r(JSON.parse(body))}catch(e){j(e)}})}).on('error',j)})}
let ws,counter=0;const pending=new Map(), requests=[], failedRequests=[], droppedResponses=[], faultErrors=[];let dropNextResponse=false;
function call(method,params={}){return new Promise((resolve,reject)=>{const id=++counter;const timer=setTimeout(()=>{pending.delete(id);reject(new Error('Timeout: '+method))},15000);pending.set(id,{resolve:v=>{clearTimeout(timer);resolve(v)},reject:e=>{clearTimeout(timer);reject(e)}});ws.send(JSON.stringify({id,method,params}))})}
async function evaluate(expression){const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw new Error('Evaluation failed');return r.result.value}
async function until(expression){for(let i=0;i<100;i++){try{if(await evaluate(expression))return}catch{}await wait(300)}throw new Error('Condition timeout')}
async function command(c){
 if(c.injectTaskFeedback){await evaluate(fs.readFileSync(path.join(__dirname,'cognos-app/js/task-feedback.js'),'utf8'));return true}
 if(c.networkSummary){for(const r of requests.filter(r=>r.status>=400&&r.path.includes('/apps/cognos/'))){try{const b=await call('Network.getResponseBody',{requestId:r.id});const m=JSON.parse(b.body).ocs?.meta?.message;if(['Transaction unavailable','Creation history unavailable; task was not saved','Task creation failed; no task was saved','Task request rejected; no task was saved'].includes(m))r.reason=m;}catch{}}return {requests,failedRequests,droppedResponses,faultErrors};}
 if(c.dropNextReopenResponse){dropNextResponse=true;await call('Fetch.enable',{patterns:[{urlPattern:'http://127.0.0.1:8780/index.php/apps/cognos/cards/*/undone',requestStage:'Response'}]});return true}
 if(c.dropNextEditResponse){dropNextResponse=true;await call('Fetch.enable',{patterns:[{urlPattern:'http://127.0.0.1:8780/ocs/v2.php/apps/cognos/api/v1.0/cards/*',requestStage:'Response'}]});return true}
 if(c.dropNextPhaseResponse){dropNextResponse=true;await call('Fetch.enable',{patterns:[{urlPattern:'http://127.0.0.1:8780/index.php/apps/cognos/cards/*/state',requestStage:'Response'}]});return true}
 if(c.dropNextCreateResponse){dropNextResponse=true;await call('Fetch.enable',{patterns:[{urlPattern:'http://127.0.0.1:8780/ocs/v2.php/apps/cognos/api/v1.0/cards*',requestStage:'Response'}]});return true}
 if(c.offline!==undefined){await call('Network.enable');await call('Network.emulateNetworkConditions',{offline:c.offline,latency:0,downloadThroughput:-1,uploadThroughput:-1});return true}
 if(c.latency!==undefined){await call('Network.enable');await call('Network.emulateNetworkConditions',{offline:false,latency:c.latency,downloadThroughput:-1,uploadThroughput:-1});return true}
 if(c.click){const point=await evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(c.click)});if(!e)throw new Error('Missing control');e.scrollIntoView({block:'center',inline:'nearest'});const r=e.getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2}})()`);await call('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point});await call('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point});return true}
 if(c.selectAllAndType){const q=c.selectAllAndType;await evaluate(`document.querySelector(${JSON.stringify(q.selector)}).focus()`);await call('Input.dispatchKeyEvent',{type:'rawKeyDown',key:'a',code:'KeyA',modifiers:2,windowsVirtualKeyCode:65});await call('Input.dispatchKeyEvent',{type:'keyUp',key:'a',code:'KeyA',modifiers:2,windowsVirtualKeyCode:65});await call('Input.insertText',{text:q.text});return true}
 if(c.eval)return evaluate(c.eval);
 if(c.wait)return until(c.wait).then(()=>true);
 if(c.navigate){await call('Page.navigate',{url:c.navigate});return true}
 if(c.viewport){await call('Emulation.setDeviceMetricsOverride',{width:c.viewport,height:844,deviceScaleFactor:1,mobile:c.viewport<600});return true}
 if(c.screenshot){if(!/^[\w\u4e00-\u9fff-]+$/.test(c.screenshot))throw new Error('Invalid name');const r=await call('Page.captureScreenshot');fs.writeFileSync(path.join(out,c.screenshot+'.png'),Buffer.from(r.data,'base64'));return c.screenshot}
 if(c.login){const cred=state[c.login];await call('Page.navigate',{url:'http://127.0.0.1:8780/index.php/login'});await until('!!document.querySelector("#password")');await evaluate(`(()=>{for(const [s,v] of ${JSON.stringify([['#user',cred.user],['#password',cred.password]])}){const e=document.querySelector(s);e.value=v;e.dispatchEvent(new Event('input',{bubbles:true}));}document.querySelector('#password').closest('form').requestSubmit()})()`);await until('!location.pathname.includes("/login")');return 'logged in '+c.login}
 if(c.logout){await evaluate(`(()=>{const a=document.querySelector('a[href*="logout"]');if(a)a.click();else location.href='/index.php/logout?requesttoken='+encodeURIComponent(OC.requestToken)})()`);await until('location.pathname.includes("login")');return true}
 if(c.close){ws.close();chrome.kill();process.exit(0)}
 throw new Error('Unknown command');
}
async function main(){let targets;for(let i=0;i<100;i++){try{targets=await get(`http://127.0.0.1:${port}/json`);break}catch{}await wait(100)}
 ws=new WebSocket(targets.find(t=>t.type==='page').webSocketDebuggerUrl);
 await new Promise((r,j)=>{ws.addEventListener('open',r,{once:true});ws.addEventListener('error',j,{once:true})});
 ws.addEventListener('message',e=>{const d=JSON.parse(e.data);if(d.method==='Fetch.requestPaused'){const p=d.params;if(dropNextResponse&&p.responseStatusCode===200){dropNextResponse=false;droppedResponses.push({path:new URL(p.request.url).pathname,response_status:p.responseStatusCode});call('Fetch.failRequest',{requestId:p.requestId,errorReason:'Failed'}).then(()=>call('Fetch.disable')).catch(()=>faultErrors.push('response interception failed'));}else{call('Fetch.continueRequest',{requestId:p.requestId}).catch(()=>faultErrors.push('response continuation failed'));}}if(d.method==='Network.requestWillBeSent'){const p=d.params;const u=new URL(p.request.url);if((u.pathname.includes('/apps/deck/')||u.pathname.includes('/apps/cognos/'))){let keys=[],operationId;try{const body=JSON.parse(p.request.postData||'{}');keys=Object.keys(body);if(typeof body.requestId==='string'&&/^[A-Za-z0-9_-]{16,64}$/.test(body.requestId))operationId=body.requestId;}catch{} requests.push({id:p.requestId,method:p.request.method,path:u.pathname,body_keys:keys,operation_id:operationId});}}if(d.method==='Network.responseReceived'){const r=requests.find(r=>r.id===d.params.requestId);if(r){r.status=d.params.response.status;r.replayed=Object.entries(d.params.response.headers).some(([k,v])=>k.toLowerCase()==='x-cognos-replayed'&&v==='true');}}if(d.method==='Network.loadingFailed' && requests.some(r=>r.id===d.params.requestId))failedRequests.push({id:d.params.requestId,error:d.params.errorText});if(d.id){const p=pending.get(d.id);if(!p)return;pending.delete(d.id);d.error?p.reject(new Error(d.error.message)):p.resolve(d.result)}});
 await call('Page.enable');await call('Network.enable');await call('Emulation.setDeviceMetricsOverride',{width:1280,height:844,deviceScaleFactor:1,mobile:false});
 console.log('READY');
 const lines=readline.createInterface({input:process.stdin});let queue=Promise.resolve();
 lines.on('line',line=>{queue=queue.then(async()=>{try{console.log(JSON.stringify({result:await command(JSON.parse(line))}))}catch(e){console.log(JSON.stringify({error:e.message}))}})});
}
main().catch(e=>{console.log(JSON.stringify({error:e.message}));chrome.kill();process.exitCode=1});
