const assert=require('node:assert/strict'),{finalSse}=require('./chatgpt_browser.cjs');
const frame=x=>'data: '+JSON.stringify(x)+'\n';
const message=(channel,text,end_turn)=>({author:{role:'assistant'},channel,content:{content_type:'text',parts:[text]},end_turn});
assert.equal(finalSse(frame({message:message('analysis','private reasoning',true)})),null);
assert.equal(finalSse(frame({message:message('final','ordinary reply',true)})),'ordinary reply');
const delta=frame({v:{message:message('final','',false)}})+frame({p:'',o:'patch',v:[{p:'/message/content/parts/0',o:'append',v:'聊天端回答'},{p:'/message/end_turn',o:'replace',v:true}]});
assert.equal(finalSse(delta),'聊天端回答');
assert.equal(finalSse(frame({message:message('final','unfinished',false)})),null);
console.log('ChatGPT full/delta stream parsing passed; unfinished and internal reasoning excluded.');
