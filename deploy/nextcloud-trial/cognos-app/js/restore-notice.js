(() => {
  'use strict';
  const show=()=>{if(document.getElementById('cognos-restore-notice'))return;const banner=document.createElement('div');banner.id='cognos-restore-notice';banner.setAttribute('role','status');banner.textContent='这是隔离恢复副本。记录保留备份时状态；不能派新工作或启动外部 AI。';Object.assign(banner.style,{position:'fixed',bottom:'0',left:'0',right:'0',zIndex:'20000',padding:'10px 16px',background:'#563d13',color:'#fff',fontSize:'14px',lineHeight:'1.5',borderTop:'2px solid #edc269'});document.body.append(banner);document.body.style.paddingBottom='80px';};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',show,{once:true});else show();
})();
