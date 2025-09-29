<?php /* Chatbot widget include */ ?>
<div class="ea-chat">
  <button class="ea-chat__toggle" id="eaToggle"><i class="bi bi-chat-dots-fill"></i> Chat with EducAid</button>
  <div class="ea-chat__panel" id="eaPanel" style="display:none">
    <div class="ea-chat__header">
      <span>🤖 EducAid Assistant</span>
      <button class="ea-chat__close" id="eaClose" aria-label="Close chat">×</button>
    </div>
    <div class="ea-chat__body" id="eaBody">
      <div class="ea-chat__msg"><div class="ea-chat__bubble">👋 Hi! I'm your EducAid Assistant. Ask me about eligibility, documents, process, deadlines, or contact info.</div></div>
      <div class="ea-typing" id="eaTyping" style="display:none">EducAid Assistant is typing...</div>
    </div>
    <div class="ea-chat__footer">
      <input class="ea-chat__input" id="eaInput" placeholder="Type your message…" />
      <button class="ea-chat__send" id="eaSend">Send</button>
    </div>
  </div>
</div>
