/* app.js — 启动 LogicFlow 并接线工具栏 / 操作面板 / 属性面板 / postMessage 桥
 * 删除统一入口（对齐 MES 设计器三入口）：Delete/Backspace 键盘、右键菜单、操作面板删除按钮，
 * 删除后统一清理选中态/属性面板/右键菜单引用，避免残留异常状态。
 */
window.SC = window.SC || {};
document.addEventListener('DOMContentLoaded', () => {
    const SC = window.SC;
    if (typeof LogicFlow === 'undefined') return; // 资源未加载，由页面错误提示处理

    const container = document.getElementById('lf');
    const workspace = document.getElementById('workspace');
    const lf = new LogicFlow({
        container,
        grid: { size: 12, type: 'dot', config: { color: '#dbe3ec', radius: 1 } },
        adjustEdge: true,
        keyboard: { enabled: true },
        edgeType: 'polyline',
    });
    lf.setTheme(SC.LF_THEME);
    // 注册规格节点（sc:pill / sc:rect / sc:diamond，尺寸样式对齐 MES 设计器）
    SC.registerNodes(lf);
    SC.lf = lf;

    SC.selected = null; // { kind:'node'|'edge', id }
    SC.select = (kind, id) => {
        SC.selected = kind ? { kind, id } : null;
        if (SC.renderInspector) SC.renderInspector();
        SC.updateStatus();
        // 选择元素时收起窄屏 overlay 面板，保证画布可视区域
        if (workspace && kind) workspace.classList.remove('show-left');
    };

    // ---- toast 轻反馈 ----
    let toastTimer = null;
    SC.toast = (msg, type = '') => {
        const host = document.getElementById('toast-host');
        if (!host) return;
        host.innerHTML = '';
        const t = document.createElement('div');
        t.className = 'toast' + (type ? ' ' + type : '');
        t.textContent = msg;
        host.appendChild(t);
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => {
            t.classList.add('out');
            setTimeout(() => t.remove(), 300);
        }, 2200);
    };

    // ---- 状态栏（结构化统计） ----
    SC.updateStatus = () => {
        const d = lf.getGraphData();
        const nodesEl = document.getElementById('stat-nodes');
        const edgesEl = document.getElementById('stat-edges');
        const selEl = document.getElementById('stat-sel');
        if (nodesEl) nodesEl.textContent = String(d.nodes.length);
        if (edgesEl) edgesEl.textContent = String(d.edges.length);
        if (selEl) {
            if (!SC.selected) {
                selEl.textContent = '空闲';
                selEl.classList.add('none');
            } else {
                const data = SC.selected.kind === 'node' ? lf.getNodeDataById(SC.selected.id) : lf.getEdgeDataById(SC.selected.id);
                const name = data ? (data.text && data.text.value ? data.text.value : SC.selected.id) : SC.selected.id;
                selEl.textContent = (SC.selected.kind === 'node' ? '节点 · ' : '连线 · ') + name;
                selEl.classList.remove('none');
            }
        }
    };

    // ================= 删除统一实现（键盘 / 右键菜单 / 操作面板 三入口共用） =================

    /** 键盘事件目标处于可输入元素内时忽略删除快捷键（对齐 MES isEditableEventTarget） */
    function isEditableEventTarget(target) {
        const el = target;
        if (!el) return false;
        const tag = (el.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable) return true;
        return !!(el.closest && el.closest('input, textarea, select, [contenteditable="true"]'));
    }

    /** 删除后统一清理：失效选中态、属性面板、右键菜单引用 */
    function cleanupAfterDelete() {
        if (SC.selected) {
            const s = SC.selected;
            let exists = false;
            try {
                exists = s.kind === 'node'
                    ? !!lf.getNodeModelById?.(s.id)
                    : !!lf.getEdgeModelById?.(s.id);
            } catch (_) { exists = false; }
            if (!exists) SC.select(null);
        }
        SC.closeCtxMenu && SC.closeCtxMenu();
        SC.updateStatus();
    }

    /** 删除当前选中元素（先边后节点；LogicFlow 删除节点时自动级联清理关联连线） */
    SC.deleteSelected = () => {
        let sel;
        try {
            sel = lf.getSelectElements?.() ?? { nodes: [], edges: [] };
        } catch (_) { sel = { nodes: [], edges: [] }; }
        const nodes = (sel.nodes || []).map((n) => String(n.id)).filter(Boolean);
        const edges = (sel.edges || []).map((e) => String(e.id)).filter(Boolean);
        // 兜底：LF 多选态为空时回退到属性面板记录的选中元素
        if (!nodes.length && !edges.length && SC.selected) {
            if (SC.selected.kind === 'node') nodes.push(SC.selected.id);
            else edges.push(SC.selected.id);
        }
        if (!nodes.length && !edges.length) {
            SC.toast('请先选中要删除的节点或连线', 'err');
            return;
        }
        edges.forEach((id) => { try { lf.deleteEdge(id); } catch (_) {} });
        nodes.forEach((id) => { try { lf.deleteNode(id); } catch (_) {} });
        cleanupAfterDelete();
        SC.toast(`已删除 ${nodes.length} 个节点、${edges.length} 条连线`, 'ok');
    };

    /** 删除单个元素（右键菜单入口） */
    SC.deleteElement = (kind, id) => {
        if (!id) return;
        try {
            if (kind === 'node') lf.deleteNode(id);
            else lf.deleteEdge(id);
        } catch (_) { return; }
        cleanupAfterDelete();
        SC.toast(kind === 'node' ? '已删除节点' : '已删除连线', 'ok');
    };

    // ---- 右键菜单（查看属性 / 删除节点 / 删除连线） ----
    const ctxMenu = document.getElementById('ctx-menu');
    SC.closeCtxMenu = () => {
        if (ctxMenu) ctxMenu.classList.add('hidden');
    };
    function openCtxMenu(kind, id, cx, cy) {
        if (!ctxMenu) return;
        const items = kind === 'node'
            ? [
                { text: '查看属性', danger: false, act: () => SC.select('node', id) },
                { text: '删除节点', danger: true, act: () => SC.deleteElement('node', id) },
            ]
            : [
                { text: '删除连线', danger: true, act: () => SC.deleteElement('edge', id) },
            ];
        ctxMenu.innerHTML = '';
        for (const item of items) {
            const el = document.createElement('div');
            el.className = 'ctx-item' + (item.danger ? ' danger' : '');
            el.textContent = item.text;
            el.onclick = () => { SC.closeCtxMenu(); item.act(); };
            ctxMenu.appendChild(el);
        }
        ctxMenu.classList.remove('hidden');
        // 先显示再测量，位置钳制在视口内
        const w = ctxMenu.offsetWidth || 120;
        const h = ctxMenu.offsetHeight || 60;
        ctxMenu.style.left = Math.min(Math.max(cx, 4), window.innerWidth - w - 4) + 'px';
        ctxMenu.style.top = Math.min(Math.max(cy, 4), window.innerHeight - h - 4) + 'px';
    }

    document.addEventListener('mousedown', (e) => {
        if (!ctxMenu || ctxMenu.classList.contains('hidden')) return;
        if (e.target && ctxMenu.contains(e.target)) return;
        SC.closeCtxMenu();
    });

    // ---- 窄屏面板切换 ----
    const togglePanel = (btnId, side) => {
        const btn = document.getElementById(btnId);
        if (!btn) return;
        btn.onclick = () => {
            const cls = 'show-' + side;
            const other = side === 'left' ? 'show-right' : 'show-left';
            workspace.classList.toggle(cls);
            workspace.classList.remove(other);
        };
    };
    togglePanel('btn-toggle-left', 'left');
    togglePanel('btn-toggle-right', 'right');
    // 点击遮罩（overlay 之外的画布区域）收起面板
    workspace.addEventListener('click', (e) => {
        if (e.target === workspace) workspace.classList.remove('show-left', 'show-right');
    });

    // 初始预置一个起点（render 为异步，作为渲染链头节点，flow.ready 在其完成后发出）
    SC._renderChain = SC.doRender({
        nodes: [{ id: SC.uid('nd'), type: 'sc:pill', x: 160, y: 180, text: '开始',
            properties: { scType: 'start', scName: '开始', scProps: {}, scSteps: [], scExt: {} } }],
        edges: [],
    });

    // ---- 选择联动 ----
    lf.on('node:click', ({ data }) => { SC.closeCtxMenu(); SC.select('node', data.id); });
    lf.on('edge:click', ({ data }) => { SC.closeCtxMenu(); SC.select('edge', data.id); });
    lf.on('blank:click', () => {
        SC.select(null);
        SC.closeCtxMenu();
        workspace.classList.remove('show-left', 'show-right');
    });

    // ---- 右键菜单（节点/连线） ----
    lf.on('node:contextmenu', ({ data, e }) => {
        const native = e?.originalEvent ?? e;
        native?.preventDefault?.();
        openCtxMenu('node', data.id, native?.clientX ?? window.__lastMouse?.x ?? 0, native?.clientY ?? window.__lastMouse?.y ?? 0);
    });
    lf.on('edge:contextmenu', ({ data, e }) => {
        const native = e?.originalEvent ?? e;
        native?.preventDefault?.();
        openCtxMenu('edge', data.id, native?.clientX ?? window.__lastMouse?.x ?? 0, native?.clientY ?? window.__lastMouse?.y ?? 0);
    });

    // ---- 连线规则拦截提示（ConnectRule 校验失败时 LF2 发出 connection:not-allowed） ----
    lf.on('connection:not-allowed', ({ msg } = {}) => SC.toast(msg || '不允许添加该连线', 'err'));

    // ---- 连线时选择 mode ----
    lf.on('connection', ({ data }) => {
        const id = data.id;
        lf.setProperties(id, { scMode: 'straight', scTrigger: 'manual', scPriority: 100, scEnabled: true, scWeight: null });
        lf.updateText(id, 'straight');
        const m = window.__lastMouse || { x: window.innerWidth / 2, y: window.innerHeight / 2 };
        SC.openModePopup(id, m.x, m.y);
    });

    // ---- 结构/位置变化 → 防抖自动推送父页 ----
    let timer = null;
    ['node:add', 'node:delete', 'edge:add', 'edge:delete', 'node:move', 'node:drag', 'node:drop', 'edge:drop', 'text:update']
        .forEach((ev) => lf.on(ev, () => { clearTimeout(timer); timer = setTimeout(() => SC.postSave && SC.postSave(), 400); SC.updateStatus(); }));

    // ---- 撤销/重做可用态 → 操作面板（payload 形状容错：{data:{...}} 或直接 {...}） ----
    lf.on('history:change', (payload) => {
        const d = payload?.data ?? payload ?? {};
        SC.setPanelHistory(!!d.undoAble, !!d.redoAble);
        SC.updateStatus();
    });

    document.addEventListener('mousemove', (e) => { window.__lastMouse = { x: e.clientX, y: e.clientY }; });

    // ---- 窗口尺寸变化 → 同步 LF 画布尺寸（防止视口变化后画布高度不足导致内容截断） ----
    let resizeTimer = null;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => { try { lf.resize(); } catch (_) {} }, 150);
    });

    // ---- 键盘删除（Delete / Backspace）：捕获阶段接管，统一走 SC.deleteSelected ----
    try { lf.keyboard?.off?.('backspace'); } catch (_) { /* 无内置快捷键忽略 */ }
    document.addEventListener('keydown', (e) => {
        if (e.isComposing) return;
        if (e.key !== 'Delete' && e.key !== 'Backspace') return;
        if (isEditableEventTarget(e.target)) return;
        const hasSel = !!(lf.getSelectElements?.() ?? {}).nodes?.length;
        if (!SC.selected && !hasSel) return;
        e.preventDefault();
        e.stopPropagation();
        SC.deleteSelected();
    }, true);

    // 连线类型弹窗：位置钳制在视口内，避免溢出/遮挡
    SC.openModePopup = (edgeId, cx, cy) => {
        const popup = document.getElementById('mode-popup');
        popup.innerHTML = '';
        popup.classList.remove('hidden');
        const w = popup.offsetWidth || 180;
        const h = popup.offsetHeight || 150;
        const left = Math.min(Math.max(cx, 8), window.innerWidth - w - 8);
        const top = Math.min(Math.max(cy, 8), window.innerHeight - h - 8);
        popup.style.left = left + 'px';
        popup.style.top = top + 'px';
        const title = document.createElement('div'); title.className = 'popup-title'; title.textContent = '选择连线类型';
        popup.appendChild(title);
        SC.EDGE_MODES.forEach((m) => {
            const b = document.createElement('button'); b.textContent = m;
            b.onclick = () => {
                const props = Object.assign({}, lf.getEdgeDataById(edgeId).properties, { scMode: m });
                lf.setProperties(edgeId, props);
                lf.updateText(edgeId, m);
                popup.classList.add('hidden');
            };
            popup.appendChild(b);
        });
        setTimeout(() => {
            const close = (e2) => { if (!popup.contains(e2.target)) { popup.classList.add('hidden'); document.removeEventListener('pointerdown', close); } };
            document.addEventListener('pointerdown', close);
        }, 0);
    };

    SC.app = { lf };
    SC.initInspector();
    SC.initToolbar();
    SC.initPanel();
    SC.initIO();
    SC.updateStatus();
    // 初始渲染完成后才通知父页，避免父页立即回发 flow.load 与渲染中的 mobx invariant 冲突
    SC._renderChain.then(() => SC.postReady());
});
