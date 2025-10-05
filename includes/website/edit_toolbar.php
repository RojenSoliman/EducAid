<!-- 
  Modular Edit Mode Toolbar
  Reusable across all editable pages
  Floating sidebar design matching existing pages
-->
<?php
// Ensure this is only loaded in edit mode
if (!isset($IS_EDIT_MODE) || !$IS_EDIT_MODE) {
    return;
}

// Default configuration
$default_config = [
    'page_title' => 'Page Editor',
    'show_save' => true,
    'show_save_all' => true,
    'show_reset' => true,
    'show_reset_all' => true,
    'show_history' => true,
    'show_exit' => true,
    'show_dashboard' => true,
    'exit_url' => null,
    'dashboard_url' => '../modules/admin/homepage.php'
];

// Merge user config with defaults
$toolbar_config = isset($toolbar_config) ? array_merge($default_config, $toolbar_config) : $default_config;

// Auto-detect exit URL if not provided
if (!$toolbar_config['exit_url']) {
    $current_page = basename($_SERVER['PHP_SELF']);
    $toolbar_config['exit_url'] = $current_page;
}
?>

<style>
.lp-edit-toolbar {
    position: fixed;
    top: 70px;
    right: 12px;
    width: 320px;
    background: #fff;
    border: 1px solid #d1d9e0;
    border-radius: 12px;
    z-index: 4000;
    padding: 0.75rem 0.85rem 1.6rem;
    font-family: system-ui, sans-serif;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    overflow: visible;
    box-sizing: border-box;
    min-width: 280px;
    min-height: 320px;
}
.lp-edit-toolbar.lp-dragging {
    box-shadow: 0 10px 32px rgba(15,23,42,0.25);
    cursor: grabbing;
}
.lp-edit-toolbar textarea {
    font-size: 0.7rem;
}
.lp-edit-toolbar .form-label {
    font-size: 0.6rem;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    font-weight: 600;
    color: #64748b;
}
.lp-edit-badge {
    position: fixed;
    left: 12px;
    top: 70px;
    background: #1d4ed8;
    color: #fff;
    padding: 4px 10px;
    font-size: 0.65rem;
    font-weight: 600;
    letter-spacing: 0.5px;
    border-radius: 30px;
    z-index: 4000;
    display: flex;
    align-items: center;
    gap: 4px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}
.lp-edit-badge .dot {
    width: 6px;
    height: 6px;
    background: #22c55e;
    border-radius: 50%;
    box-shadow: 0 0 0 2px rgba(255,255,255,0.4);
    animation: pulse-dot 2s ease-in-out infinite;
}
@keyframes pulse-dot {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.7; transform: scale(1.1); }
}
.lp-toolbar-header {
    user-select: none;
    text-align: center;
    margin-bottom: 0.75rem;
}
.lp-toolbar-header .lp-toolbar-title {
    font-weight: 600;
    display: block;
}
.lp-toolbar-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.5rem;
}
.lp-toolbar-section {
    font-size: 0.6rem;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 0.25rem;
}
.lp-toolbar-divider {
    height: 1px;
    background: #e2e8f0;
    margin: 0.75rem 0;
}
.lp-toolbar-resizer {
    position: absolute;
    bottom: 4px;
    right: 4px;
    width: 16px;
    height: 16px;
    cursor: se-resize;
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #cbd5ff;
    background: linear-gradient(135deg, rgba(99,102,241,0.25), rgba(129,140,248,0.35));
    box-shadow: inset 0 0 0 1px rgba(79,70,229,0.35);
    touch-action: none;
}
.lp-toolbar-resizer::after {
    content: '';
    width: 8px;
    height: 8px;
    border-right: 2px solid rgba(79,70,229,0.65);
    border-bottom: 2px solid rgba(79,70,229,0.65);
    transform: translate(1px, 1px);
}
</style>

<!-- Floating Edit Toolbar -->
<div id="lp-edit-toolbar" class="lp-edit-toolbar shadow-sm">
    <div class="lp-toolbar-header mb-2">
        <strong class="small mb-0 lp-toolbar-title"><?php echo htmlspecialchars($toolbar_config['page_title']); ?></strong>
    </div>
    
    <div class="mb-2">
        <label class="form-label mb-1">Selected</label>
        <div id="lp-current-target" class="form-control form-control-sm bg-body-tertiary" 
             style="height:auto;min-height:32px;font-size:.65rem"></div>
    </div>
    
    <div class="mb-2">
        <label class="form-label mb-1">Text</label>
        <textarea id="lp-edit-text" class="form-control form-control-sm" rows="3" 
                  placeholder="Click an editable element"></textarea>
    </div>
    
    <div class="row g-2 mb-2">
        <div class="col-6">
            <label class="form-label mb-1">Text Color</label>
            <input type="color" id="lp-text-color" class="form-control form-control-color form-control-sm" value="#000000" />
        </div>
        <div class="col-6">
            <label class="form-label mb-1">BG Color</label>
            <input type="color" id="lp-bg-color" class="form-control form-control-color form-control-sm" value="#ffffff" />
        </div>
    </div>
    
    <div class="lp-toolbar-section">Block Controls</div>
    <div class="d-flex gap-2 mb-2">
        <?php if ($toolbar_config['show_reset']): ?>
        <button id="lp-reset-btn" class="btn btn-sm btn-outline-warning w-100" disabled>
            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Block
        </button>
        <?php endif; ?>
        
        <button id="lp-highlight-toggle" class="btn btn-sm btn-outline-primary w-100" data-active="1">
            <i class="bi bi-bounding-box-circles me-1"></i>Hide Boxes
        </button>
    </div>
    <div class="lp-toolbar-divider"></div>
    <div class="lp-toolbar-section">Page Actions</div>
    <div class="lp-toolbar-actions mb-3" data-lp-drag-ignore>
        <?php if ($toolbar_config['show_dashboard']): ?>
        <a href="<?php echo htmlspecialchars($toolbar_config['dashboard_url']); ?>" 
           class="btn btn-sm btn-outline-primary" 
           title="Return to Admin Dashboard">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
        </a>
        <?php endif; ?>

        <?php if ($toolbar_config['show_save']): ?>
        <button id="lp-save-btn" class="btn btn-sm btn-success" disabled>
            <i class="bi bi-save me-1"></i>Save
        </button>
        <?php endif; ?>

        <?php if ($toolbar_config['show_save_all']): ?>
        <button id="lp-save-all-btn" class="btn btn-sm btn-outline-success" title="Save all editable content">
            <i class="bi bi-cloud-arrow-up me-1"></i>Save All
        </button>
        <?php endif; ?>

        <?php if ($toolbar_config['show_reset_all']): ?>
        <button id="lp-reset-all" class="btn btn-sm btn-outline-danger" title="Reset ALL blocks">
            <i class="bi bi-trash3"></i>
        </button>
        <?php endif; ?>

        <?php if ($toolbar_config['show_history']): ?>
        <button id="lp-history-btn" class="btn btn-sm btn-outline-primary" title="View history">
            <i class="bi bi-clock-history"></i>
        </button>
        <?php endif; ?>

        <?php if ($toolbar_config['show_exit']): ?>
        <a href="<?php echo htmlspecialchars($toolbar_config['exit_url']); ?>" 
           id="lp-exit-btn" 
           class="btn btn-sm btn-outline-secondary" 
           title="Exit">
            <i class="bi bi-x-lg"></i>
        </a>
        <?php endif; ?>
    </div>
    
    <div class="text-end">
        <small class="text-muted" id="lp-status">Idle</small>
    </div>

    <span class="lp-toolbar-resizer" title="Resize toolbar" aria-hidden="true"></span>
</div>

<!-- Edit Mode Badge -->
<div class="lp-edit-badge">
    <span class="dot"></span> EDIT MODE
</div>

<script>
(function() {
    const toolbar = document.getElementById('lp-edit-toolbar');
    if (!toolbar) return;

    const resizer = toolbar.querySelector('.lp-toolbar-resizer');
    const storageKey = 'lp-toolbar-state::' + window.location.pathname;
    const marginX = 12;
    const marginY = 12;
    const minWidth = 280;
    const minHeight = 320;

    const readPoint = (evt) => ({
        x: evt.clientX ?? (evt.touches && evt.touches[0] ? evt.touches[0].clientX : undefined),
        y: evt.clientY ?? (evt.touches && evt.touches[0] ? evt.touches[0].clientY : undefined)
    });

    let currentState = null;

    const initialRect = toolbar.getBoundingClientRect();
    const initialState = {
        top: initialRect.top,
        left: initialRect.left,
        width: initialRect.width,
        height: initialRect.height
    };

    const clampSize = (width, height) => {
        const maxWidth = Math.max(minWidth, window.innerWidth - marginX * 2);
        const maxHeight = Math.max(minHeight, window.innerHeight - marginY * 2);
        return {
            width: Math.min(Math.max(minWidth, width), maxWidth),
            height: Math.min(Math.max(minHeight, height), maxHeight)
        };
    };

    const clampPosition = (top, left, width, height) => {
        const maxLeftCandidate = window.innerWidth - width - marginX;
        const maxTopCandidate = window.innerHeight - height - marginY;
        const maxLeft = maxLeftCandidate < marginX ? marginX : maxLeftCandidate;
        const maxTop = maxTopCandidate < marginY ? marginY : maxTopCandidate;
        return {
            top: Math.min(Math.max(marginY, top), maxTop),
            left: Math.min(Math.max(marginX, left), maxLeft)
        };
    };

    const applyState = (nextState) => {
        if (!nextState) return;
        const base = currentState || {};
        const merged = {
            top: nextState.top ?? base.top ?? marginY,
            left: nextState.left ?? base.left ?? (window.innerWidth - (toolbar.offsetWidth || minWidth) - marginX),
            width: nextState.width ?? base.width ?? toolbar.offsetWidth,
            height: nextState.height ?? base.height ?? toolbar.offsetHeight
        };

        const size = clampSize(merged.width, merged.height);
        const pos = clampPosition(merged.top, merged.left, size.width, size.height);

        const normalized = {
            top: Math.round(pos.top),
            left: Math.round(pos.left),
            width: Math.max(minWidth, Math.round(size.width)),
            height: Math.max(minHeight, Math.round(size.height))
        };

        toolbar.style.width = normalized.width + 'px';
        toolbar.style.height = normalized.height + 'px';
        toolbar.style.top = normalized.top + 'px';
        toolbar.style.left = normalized.left + 'px';
        toolbar.style.right = 'auto';
        toolbar.style.bottom = 'auto';

        currentState = normalized;
        return currentState;
    };

    const defaultState = () => ({ ...initialState });

    const loadState = () => {
        try {
            const raw = localStorage.getItem(storageKey);
            if (!raw) {
                applyState(defaultState());
                return;
            }
            const data = JSON.parse(raw);
            if (typeof data === 'object' && data !== null) {
                applyState(data);
            } else {
                applyState(defaultState());
            }
        } catch (err) {
            applyState(defaultState());
        }
    };

    const saveState = () => {
        if (!currentState) return;
        try {
            localStorage.setItem(storageKey, JSON.stringify(currentState));
        } catch (err) {
            // ignore storage failures
        }
    };

    loadState();

    const ensureVisible = () => {
        const rect = toolbar.getBoundingClientRect();
        const margin = 24;
        const offscreen = rect.right < margin || rect.left > window.innerWidth - margin || rect.bottom < margin || rect.top > window.innerHeight - margin;
        if (offscreen) {
            applyState(defaultState());
            saveState();
        }
    };

    ensureVisible();

    window.lpResetToolbarLayout = () => {
        try { localStorage.removeItem(storageKey); } catch (err) { /* ignore */ }
        applyState(defaultState());
        saveState();
    };

    let dragActive = false;
    let dragPointerId = null;
    let offsetX = 0;
    let offsetY = 0;

    const interactiveSelectors = 'a, button, select, textarea, input';
    const isInteractiveElement = (el) => {
        if (!el) return false;
        if (el.closest('.lp-toolbar-resizer')) return true;
        if (el.closest('[data-lp-drag-ignore]')) return true;
        return !!el.closest(interactiveSelectors);
    };

    const startDrag = (evt) => {
        if (evt.button !== undefined && evt.button !== 0) return;
        if (isInteractiveElement(evt.target)) return;

        const point = readPoint(evt);
        if (point.x === undefined || point.y === undefined) return;

        const rect = toolbar.getBoundingClientRect();
        offsetX = point.x - rect.left;
        offsetY = point.y - rect.top;
        dragActive = true;
        dragPointerId = evt.pointerId ?? null;
        toolbar.classList.add('lp-dragging');
        toolbar.style.transition = 'none';
        if (toolbar.setPointerCapture && evt.pointerId !== undefined) {
            toolbar.setPointerCapture(evt.pointerId);
        }
        evt.preventDefault();
    };

    const moveDrag = (evt) => {
        if (!dragActive) return;
        if (dragPointerId !== null && evt.pointerId !== undefined && evt.pointerId !== dragPointerId) return;
        const point = readPoint(evt);
        if (point.x === undefined || point.y === undefined) return;
        const left = point.x - offsetX;
        const top = point.y - offsetY;
        applyState({ top, left });
    };

    const endDrag = (evt) => {
        if (!dragActive) return;
        if (dragPointerId !== null && evt.pointerId !== undefined && evt.pointerId !== dragPointerId) return;
        dragActive = false;
        dragPointerId = null;
        toolbar.classList.remove('lp-dragging');
        toolbar.style.transition = '';
        if (toolbar.releasePointerCapture && evt.pointerId !== undefined) {
            try { toolbar.releasePointerCapture(evt.pointerId); } catch (err) { /* ignore */ }
        }
        saveState();
        ensureVisible();
    };

    toolbar.addEventListener('pointerdown', startDrag);
    toolbar.addEventListener('pointermove', moveDrag);
    toolbar.addEventListener('pointerup', endDrag);
    toolbar.addEventListener('pointercancel', endDrag);

    let resizeActive = false;
    let resizePointerId = null;
    let initialSize = null;
    let initialPoint = null;

    const startResize = (evt) => {
        if (!resizer) return;
        if (evt.button !== undefined && evt.button !== 0) return;
        const point = readPoint(evt);
        if (point.x === undefined || point.y === undefined) return;
        resizeActive = true;
        resizePointerId = evt.pointerId ?? null;
        initialPoint = point;
        initialSize = { width: toolbar.offsetWidth, height: toolbar.offsetHeight };
        if (resizer.setPointerCapture && evt.pointerId !== undefined) {
            resizer.setPointerCapture(evt.pointerId);
        }
        evt.preventDefault();
    };

    const moveResize = (evt) => {
        if (!resizeActive) return;
        if (resizePointerId !== null && evt.pointerId !== undefined && evt.pointerId !== resizePointerId) return;
        const point = readPoint(evt);
        if (point.x === undefined || point.y === undefined) return;
        const deltaX = point.x - initialPoint.x;
        const deltaY = point.y - initialPoint.y;
        const width = initialSize.width + deltaX;
        const height = initialSize.height + deltaY;
        applyState({ width, height });
    };

    const endResize = (evt) => {
        if (!resizeActive) return;
        if (resizePointerId !== null && evt.pointerId !== undefined && evt.pointerId !== resizePointerId) return;
        resizeActive = false;
        resizePointerId = null;
        if (resizer && resizer.releasePointerCapture && evt.pointerId !== undefined) {
            try { resizer.releasePointerCapture(evt.pointerId); } catch (err) { /* ignore */ }
        }
        saveState();
        ensureVisible();
    };

    if (resizer) {
        resizer.addEventListener('pointerdown', startResize);
        resizer.addEventListener('pointermove', moveResize);
        resizer.addEventListener('pointerup', endResize);
        resizer.addEventListener('pointercancel', endResize);
    }

    window.addEventListener('resize', () => {
        if (!currentState) return;
        applyState(currentState);
        saveState();
        ensureVisible();
    });

    toolbar.addEventListener('dblclick', (evt) => {
        if (isInteractiveElement(evt.target)) return;
        evt.preventDefault();
        applyState(defaultState());
        saveState();
    });
})();
</script>
