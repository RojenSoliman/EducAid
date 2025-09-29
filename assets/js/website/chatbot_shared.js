// Shared chatbot logic for all pages
(function(){
  document.addEventListener('DOMContentLoaded', function(){
    const apiUrl = (window.location.pathname.indexOf('/website/')!==-1)? '../chatbot/gemini_chat.php' : 'chatbot/gemini_chat.php';
    const toggle = document.getElementById('eaToggle');
    const panel  = document.getElementById('eaPanel');
    const close  = document.getElementById('eaClose');
    const body   = document.getElementById('eaBody');
    const input  = document.getElementById('eaInput');
    const send   = document.getElementById('eaSend');
    const typing = document.getElementById('eaTyping');
    if(!toggle||!panel) return;
    let isOpen=false; let busy=false;

    function toggleChat(){ isOpen=!isOpen; panel.style.display = isOpen?'block':'none'; if(isOpen){ input&&input.focus(); body.scrollTop=body.scrollHeight; }}

    toggle.addEventListener('click',toggleChat);
    close && close.addEventListener('click',toggleChat);

    async function sendMsg(){
      if(!input||busy) return; const text = input.value.trim(); if(!text) return; input.value='';
      addUser(text); typing.style.display='block'; busy=true; input.disabled=true;
      try {
        const res = await fetch(apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({message:text})});
        const payload = await res.json().catch(()=>null);
        if(res.ok && payload && payload.reply){
          addBot(formatChatbotResponse(payload.reply)+ diagnosticTag(payload));
        } else {
          let msg = 'Sorry, the assistant is temporarily unavailable.';
          if(payload && payload.error){
            msg = '⚠️ '+payload.error;
            if(payload.detail) msg += '<br><small>'+escapeHtml(payload.detail)+'</small>';
            if(payload.available_models && payload.available_models.length){
              msg += '<br><small>Available models:<br>'+payload.available_models.map(m=>escapeHtml(m)).join('<br>')+'</small>';
            }
          }
          addBot(msg);
        }
      } catch(e){ console.error('Chatbot error',e); addBot('Network error. Please retry.'); }
      finally { typing.style.display='none'; busy=false; input.disabled=false; input.focus(); body.scrollTop=body.scrollHeight; }
    }

    function diagnosticTag(p){
      if(!p.model_used) return '';
      return `<div class="cb-diag">`+
        `<small class="text-muted">Model: ${escapeHtml(p.model_used)} (${escapeHtml(p.api_version||'')})</small>`+
      `</div>`;
    }

    function escapeHtml(s){ return s.replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c])); }

    function addUser(text){ const d=document.createElement('div'); d.className='ea-chat__msg ea-chat__msg--user'; d.innerHTML='<div class="ea-chat__bubble ea-chat__bubble--user"></div>'; d.querySelector('.ea-chat__bubble').textContent=text; body.appendChild(d); body.scrollTop=body.scrollHeight; }
    function addBot(html){ const d=document.createElement('div'); d.className='ea-chat__msg'; d.innerHTML='<div class="ea-chat__bubble"></div>'; d.querySelector('.ea-chat__bubble').innerHTML=html; body.appendChild(d); body.scrollTop=body.scrollHeight; }

    send && send.addEventListener('click',sendMsg);
    input && input.addEventListener('keydown',e=>{ if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); sendMsg(); }});
    document.addEventListener('click',e=>{ if(!e.target.closest('.ea-chat')&&isOpen){ toggleChat(); }});
  });
})();

function formatChatbotResponse(text){
  return text
    .replace(/(?<!\*)\*(?!\*)/g,'')
    .replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>')
    .replace(/^[-•]\s*(.+)$/gm,'<div class="cb-item">• $1</div>')
    .replace(/\n\n+/g,'<div class="cb-gap"></div>')
    .replace(/\n/g,'<br>');
}
