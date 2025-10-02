/* Shared inline content editor (landing & about)
 * Usage: ContentEditor.init({ page:'landing'|'about', saveEndpoint, resetAllEndpoint?, history:{fetchEndpoint,rollbackEndpoint?}, refreshAfterSave?(keys) })
 */
(function(global){
  const CE={};
  const qs=(s,r=document)=>r.querySelector(s);
  const qsa=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const rgbToHex=rgb=>{ if(!rgb) return null; const m=rgb.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i); if(!m) return null; return '#'+[m[1],m[2],m[3]].map(v=>('0'+parseInt(v,10).toString(16)).slice(-2)).join(''); };
  const esc=s=>(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));

  CE.init=function(cfg){
    cfg=Object.assign({page:'generic'},cfg||{});
    const tb=qs('#lp-edit-toolbar');
    if(!tb) return; // toolbar not present -> not in edit mode
    document.body.classList.add('lp-editing');
    const els=qsa('[data-lp-key]');
    els.forEach(el=>el.classList.add('lp-edit-highlight'));

    const state={target:null,saving:false,original:new Map()};
    const txt=qs('#lp-edit-text'), label=qs('#lp-current-target'), tc=qs('#lp-text-color'), bc=qs('#lp-bg-color'), saveBtn=qs('#lp-save-btn'), saveAllBtn=qs('#lp-save-all-btn'), resetBtn=qs('#lp-reset-btn'), resetAllBtn=qs('#lp-reset-all'), hiBtn=qs('#lp-highlight-toggle'), status=qs('#lp-status'), histBtn=qs('#lp-history-btn');

    const setStatus=(msg,type='muted')=>{ if(status){ status.textContent=msg; status.className='text-'+(type==='error'?'danger': type==='success'?'success':'muted'); }};
    const select=el=>{ state.target=el; label.textContent=el.dataset.lpKey; txt.value=el.innerText.trim(); const cs=getComputedStyle(el); tc.value=rgbToHex(cs.color)||'#000000'; bc.value=rgbToHex(cs.backgroundColor)||'#ffffff'; };
    const dirty=el=>{ el.dataset.lpDirty='1'; if(saveBtn) saveBtn.disabled=false; };

    els.forEach(el=>{ if(!state.original.has(el.dataset.lpKey)) state.original.set(el.dataset.lpKey,el.innerHTML); el.addEventListener('click',e=>{ if(!tb.contains(e.target)){ e.preventDefault(); e.stopPropagation(); select(el);} }); });
    if(txt) txt.addEventListener('input',()=>{ if(!state.target) return; state.target.innerText=txt.value; dirty(state.target); });
    if(tc) tc.addEventListener('input',()=>{ if(state.target){ state.target.style.color=tc.value; dirty(state.target);} });
    if(bc) bc.addEventListener('input',()=>{ if(state.target){ state.target.style.backgroundColor=bc.value; dirty(state.target);} });
    if(resetBtn) resetBtn.addEventListener('click',()=>{ if(!state.target) return; const k=state.target.dataset.lpKey; const orig=state.original.get(k); if(orig!==undefined) state.target.innerHTML=orig; state.target.style.color=''; state.target.style.backgroundColor=''; state.target.removeAttribute('data-lp-dirty'); saveBtn.disabled=!qsa('[data-lp-dirty="1"]').length; setStatus('Block reset'); });
    if(resetAllBtn && cfg.resetAllEndpoint) resetAllBtn.addEventListener('click',async()=>{ if(!confirm('Reset ALL blocks?')) return; setStatus('Resetting...'); try{ const r=await fetch(cfg.resetAllEndpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'reset_all'})}); const d=await r.json(); if(d.success){ els.forEach(el=>{ const o=state.original.get(el.dataset.lpKey); if(o!==undefined) el.innerHTML=o; el.style.color=''; el.style.backgroundColor=''; el.removeAttribute('data-lp-dirty'); }); if(saveBtn) saveBtn.disabled=true; setStatus('All reset','success'); } else setStatus(d.message||'Reset failed','error'); }catch(e){ setStatus(e.message,'error'); }});
    if(hiBtn) hiBtn.addEventListener('click',()=>{ const on=hiBtn.getAttribute('data-active')==='1'; qsa('.lp-edit-highlight').forEach(el=>el.style.outline= on?'none':''); hiBtn.setAttribute('data-active',on?'0':'1'); hiBtn.innerHTML= on?'<i class="bi bi-bounding-box"></i> Show Boxes':'<i class="bi bi-bounding-box-circles"></i> Hide Boxes'; });

    const save=async(dirtyOnly)=>{ if(state.saving) return; const list=dirtyOnly? qsa('[data-lp-dirty="1"]'): qsa('[data-lp-key]'); if(!list.length){ setStatus('Nothing to save'); return; } const payload=list.map(el=>({key:el.dataset.lpKey,html:el.innerHTML,styles:{color:el.style.color||'',backgroundColor:el.style.backgroundColor||''}})); state.saving=true; setStatus('Saving...'); if(saveBtn) saveBtn.disabled=true; if(!dirtyOnly && saveAllBtn) saveAllBtn.disabled=true; try{ const res=await fetch(cfg.saveEndpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({blocks:payload})}); const data=await res.json(); if(!data.success) throw new Error(data.message||'Save failed'); list.forEach(el=>el.removeAttribute('data-lp-dirty')); if(cfg.refreshAfterSave) await cfg.refreshAfterSave(payload.map(p=>p.key)); setStatus('Saved','success'); }catch(e){ setStatus(e.message,'error'); if(saveBtn) saveBtn.disabled=false; if(!dirtyOnly && saveAllBtn) saveAllBtn.disabled=false; } finally { state.saving=false; } };
    if(saveBtn) saveBtn.addEventListener('click',()=>save(true));
    if(saveAllBtn) saveAllBtn.addEventListener('click',()=>save(false));
    window.addEventListener('beforeunload',e=>{ if(qsa('[data-lp-dirty="1"]').length){ e.preventDefault(); e.returnValue=''; }});

    // History modal module
    const Hist=(function(){
      let modal,listEl,filter,blockSel,limitSel,actionSel,closeBtn,loadBtn,preview,applyBtn,cancelBtn,notice,live=null;
      function ensure(){
        if(modal) return;
        modal=document.createElement('div');
        modal.className='lp-history-modal';
        modal.innerHTML=`<div class="lp-hist-backdrop"></div><div class="lp-hist-dialog"><div class="lp-hist-header d-flex justify-content-between align-items-center"><strong class="small mb-0">${cfg.page==='about'?'About':'Landing'} History</strong><div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-primary" data-load><i class="bi bi-arrow-repeat"></i></button><button type="button" class="btn btn-sm btn-outline-secondary" data-close><i class="bi bi-x"></i></button></div></div><div class="lp-hist-body"><div class="row g-2 mb-2"><div class="col-4"><input data-filter type="text" class="form-control form-control-sm" placeholder="Filter key"/></div><div class="col-3"><select data-limit class="form-select form-select-sm"><option value="25">25</option><option value="50" selected>50</option><option value="100">100</option></select></div><div class="col-3"><select data-block class="form-select form-select-sm"><option value="">All Blocks</option></select></div><div class="col-2"><select data-action class="form-select form-select-sm"><option value="">All</option><option value="update">Update</option><option value="reset_all">Reset</option><option value="rollback">Rollback</option></select></div></div><div class="d-flex gap-2"><div style="flex:1;min-height:250px;max-height:330px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;padding:.45rem;background:#fff;font-size:.7rem" data-list></div><div style="flex:1;display:flex;flex-direction:column;gap:.4rem"><div style="flex:1;border:1px solid #e2e8f0;border-radius:8px;padding:.5rem;background:#f8fafc;overflow:auto" data-preview>(select)</div><div class="d-flex gap-2"><button class="btn btn-sm btn-outline-success w-100" data-apply disabled><i class="bi bi-eye"></i> Preview</button><button class="btn btn-sm btn-outline-warning w-100" data-cancel disabled><i class="bi bi-x"></i> Cancel</button></div><div class="small text-warning-emphasis" data-notice style="display:none;">Preview active. Cancel to revert.</div></div></div></div><div class="lp-hist-footer small text-end text-muted">Double‑click preview to apply rollback permanently.</div></div>`;
        document.body.appendChild(modal);
        listEl=qs('[data-list]',modal); filter=qs('[data-filter]',modal); blockSel=qs('[data-block]',modal); limitSel=qs('[data-limit]',modal); actionSel=qs('[data-action]',modal); closeBtn=qs('[data-close]',modal); loadBtn=qs('[data-load]',modal); preview=qs('[data-preview]',modal); applyBtn=qs('[data-apply]',modal); cancelBtn=qs('[data-cancel]',modal); notice=qs('[data-notice]',modal);
        closeBtn.addEventListener('click',hide); modal.querySelector('.lp-hist-backdrop').addEventListener('click',hide); loadBtn.addEventListener('click',load); filter.addEventListener('input',applyFilter); actionSel.addEventListener('change',load);
        listEl.addEventListener('click',e=>{ const item=e.target.closest('.lp-hist-item'); if(!item) return; qsa('.lp-hist-item',listEl).forEach(x=>x.classList.remove('active')); item.classList.add('active'); preview.innerHTML=item._html||'(empty)'; preview.style.color=item._textColor||''; preview.style.backgroundColor=item._bgColor||'#f8fafc'; applyBtn.disabled=false; applyBtn._sel=item; });
        applyBtn.addEventListener('click',()=>{ const it=applyBtn._sel; if(!it) return; const key=it.getAttribute('data-key'); const target=document.querySelector('[data-lp-key="'+CSS.escape(key)+'"]'); if(!target){ alert('Block not on page'); return; } if(live && live.key!==key) revert(); if(!live){ live={ key, el:target, originalHtml:target.innerHTML, originalTextColor:target.style.color, originalBgColor:target.style.backgroundColor }; } target.innerHTML=it._html||''; target.style.color=it._textColor||''; target.style.backgroundColor=it._bgColor||''; notice.style.display='block'; cancelBtn.disabled=false; });
        cancelBtn.addEventListener('click',()=>revert());
        if(cfg.history.rollbackEndpoint){ preview.addEventListener('dblclick', async ()=>{ const sel=applyBtn._sel; if(!sel) return; if(!confirm('Apply rollback permanently?')) return; try{ const r=await fetch(cfg.history.rollbackEndpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({audit_id: sel.getAttribute('data-id')})}); const d=await r.json(); if(!d.success) throw new Error(d.message||'Rollback failed'); if(cfg.refreshAfterSave) await cfg.refreshAfterSave([d.block_key]); alert('Rollback applied'); }catch(err){ alert('Rollback error: '+err.message); } }); }
      }
      function revert(){ if(!live) return; const {el,originalHtml,originalTextColor,originalBgColor}=live; el.innerHTML=originalHtml; el.style.color=originalTextColor; el.style.backgroundColor=originalBgColor; live=null; cancelBtn.disabled=true; applyBtn.disabled=!applyBtn._sel; notice.style.display='none'; }
      async function load(){
        listEl.innerHTML='<div class="text-muted small">Loading...</div>';
        const block=blockSel.value.trim(); const limit=limitSel.value; const action=actionSel.value.trim();
        try{
          const r=await fetch(cfg.history.fetchEndpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({block,limit,action_type:action})});
          const d=await r.json();
          if(!d.success){ listEl.innerHTML='<div class="text-danger small">Failed</div>'; return; }
          const recs=d.records||[];
          if(blockSel.options.length===1){ const keys=[...new Set(recs.map(r=>r.block_key))].sort(); keys.forEach(k=>{ const o=document.createElement('option'); o.value=k; o.textContent=k; blockSel.appendChild(o); }); }
          listEl.innerHTML='';
          if(!recs.length){ listEl.innerHTML='<div class="text-muted small">No records</div>'; return; }
          recs.forEach(r=>{ const div=document.createElement('div'); div.className='lp-hist-item'; div.setAttribute('data-id',r.audit_id); div.setAttribute('data-key',r.block_key); div._html=r.html; div._textColor=r.text_color; div._bgColor=r.bg_color; div.innerHTML=`<div class=\"d-flex justify-content-between\"><span class=\"text-primary\">${esc(r.block_key)}</span><span class=\"badge text-bg-light border\">${esc(r.action_type)}</span></div><div class=\"text-muted small\">${esc(r.created_at)}</div>`; listEl.appendChild(div); });
          applyFilter();
        }catch(err){ listEl.innerHTML='<div class="text-danger small">Error</div>'; }
      }
      function applyFilter(){ const term=filter.value.toLowerCase(); qsa('.lp-hist-item',listEl).forEach(it=>{ const key=it.getAttribute('data-key').toLowerCase(); it.style.display= term && !key.includes(term)?'none':'block'; }); }
      function show(){ ensure(); modal.classList.add('show'); load(); }
      function hide(){ if(modal){ modal.classList.remove('show'); if(live) revert(); } }
      return { open: show, close: hide };
    })();

    if(histBtn && cfg.history){ histBtn.addEventListener('click',()=>Hist.open()); }
  };

  global.ContentEditor=CE;
})(window);
