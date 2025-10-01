/**
 * Topbar Settings JavaScript
/** Clean lightweight Topbar Settings script (password verification removed) */
class TopbarSettings {
    constructor() {
        this.init();
    }
    init() {
        this.bindTextInputs();
        this.bindColorPickers();
        this.updatePreview();
    }
    bindTextInputs() {
        const map = [
            { field: 'topbar_email', preview: 'preview-email', fallback: 'educaid@generaltrias.gov.ph' },
            { field: 'topbar_phone', preview: 'preview-phone', fallback: '(046) 886-4454' },
            { field: 'topbar_office_hours', preview: 'preview-hours', fallback: 'Mon–Fri 8:00AM - 5:00PM' }
        ];
        map.forEach(cfg => {
            const el = document.getElementById(cfg.field);
            const pv = document.getElementById(cfg.preview);
            if (el && pv) {
                el.addEventListener('input', () => {
                    pv.textContent = el.value || cfg.fallback;
                });
            }
        });
    }
    bindColorPickers() {
        ['topbar_bg_color','topbar_bg_gradient','topbar_text_color','topbar_link_color',
         'header_bg_color','header_border_color','header_text_color','header_icon_color','header_hover_bg','header_hover_icon_color']
            .forEach(id => {
                const input = document.getElementById(id);
                if (input) {
                    input.addEventListener('input', () => {
                        const next = input.nextElementSibling;
                        if (next && next.tagName === 'INPUT') next.value = input.value;
                        this.updatePreview();
                    });
                }
            });
    }
    updatePreview() {
        const bg = this.val('topbar_bg_color','#2e7d32');
        const grad = this.val('topbar_bg_gradient','#1b5e20');
        const txt = this.val('topbar_text_color','#ffffff');
        const link = this.val('topbar_link_color','#e8f5e9');
        const bar = document.querySelector('.preview-topbar');
        const email = document.getElementById('preview-email');
        if (bar) {
            bar.style.background = `linear-gradient(135deg, ${bg} 0%, ${grad} 100%)`;
            bar.style.color = txt;
        }
        if (email) email.style.color = link;

        // Header preview elements
        const hdr = document.getElementById('preview-header');
        if (hdr) {
            hdr.style.background = this.val('header_bg_color', '#ffffff');
            hdr.style.borderColor = this.val('header_border_color', '#e1e7e3');
            const title = document.getElementById('preview-header-title');
            if (title) title.style.color = this.val('header_text_color', '#2e7d32');
            const iconColor = this.val('header_icon_color', '#2e7d32');
            hdr.querySelectorAll('button, i').forEach(el => { el.style.color = iconColor; });
            const hoverBg = this.val('header_hover_bg', '#e9f5e9');
            const hoverIcon = this.val('header_hover_icon_color', '#1b5e20');
            // simple hover simulation: attach listeners once
            if (!hdr.dataset.hoverBound) {
                hdr.dataset.hoverBound = '1';
                hdr.querySelectorAll('button').forEach(btn => {
                    btn.addEventListener('mouseenter', ()=>{ btn.style.background = hoverBg; btn.style.color = hoverIcon; });
                    btn.addEventListener('mouseleave', ()=>{ btn.style.background = '#f8fbf8'; btn.style.color = iconColor; });
                });
            }
        }
    }
    val(id, fallback='') {
        const el = document.getElementById(id);
        return el && el.value ? el.value : fallback;
    }
    validate() {
        const required = ['topbar_email','topbar_phone','topbar_office_hours'];
        let ok = true;
        this.clearErrors();
        required.forEach(id => {
            const el = document.getElementById(id);
            if (el && !el.value.trim()) { this.fieldError(el,'This field is required'); ok = false; }
        });
        const email = document.getElementById('topbar_email');
        if (email && email.value.trim()) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!re.test(email.value.trim())) { this.fieldError(email,'Please enter a valid email address'); ok = false; }
        }
        return ok;
    }
    fieldError(el,msg){
        el.classList.add('is-invalid');
        let fb = el.parentNode.querySelector('.invalid-feedback');
        if(!fb){
            fb=document.createElement('div');
            fb.className='invalid-feedback';
            el.parentNode.appendChild(fb);
        }
        fb.textContent=msg;
    }
    clearErrors(){
        document.querySelectorAll('.is-invalid').forEach(e=>e.classList.remove('is-invalid'));
        document.querySelectorAll('.invalid-feedback').forEach(e=>e.remove());
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const ts = new TopbarSettings();
    const form = document.getElementById('settingsForm');
    if(form){
        form.addEventListener('submit', e => {
            if(!ts.validate()){
                e.preventDefault();
                const alertDiv = document.createElement('div');
                alertDiv.className='alert alert-danger alert-dismissible fade show';
                alertDiv.innerHTML=`<i class="bi bi-exclamation-triangle me-2"></i>Please correct the errors below before submitting.<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
                const container=document.querySelector('.container-fluid');
                if(container) container.insertBefore(alertDiv, container.firstChild);
            }
        });
    }
});