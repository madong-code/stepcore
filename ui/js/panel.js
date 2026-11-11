/* panel.js — 画布操作面板（LogicFlow Control 风格，画布右上角水平排列）
 * 基础操作：撤销 / 重做 / 放大 / 缩小 / 适应视图；
 * 文档操作：导入 / 导出 / 保存 / 清空（动作实现在 toolbar.js）；
 * 编辑操作：删除（统一入口 SC.deleteSelected，见 app.js）。
 */
window.SC = window.SC || {};
(function (SC) {
    // 20×20 线性图标（与 MES lf-control-item i 同风格：24 viewBox、stroke #474747、round 端点）
    const ICONS = {
        undo:   "%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23474747' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M3 7v6h6'/%3E%3Cpath d='M21 17a9 9 0 0 0-15-6.7L3 13'/%3E%3C/svg%3E",
        redo:   "%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23474747' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M21 7v6h-6'/%3E%3Cpath d='M3 17a9 9 0 0 1 15-6.7L21 13'/%3E%3C/svg%3E",
        zin:    "%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23474747' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cpath d='M21 21l-4.3-4.3'/%3E%3Cpath d='M11 8v6'/%3E%3Cpath d='M8 11h6'/%3E%3C/svg%3E",
        zout:   "%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23474747' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cpath d='M21 21l-4.3-4.3'/%3E%3Cpath d='M8 11h6'/%3E%3C/svg%3E",
        fit:    "%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23474747' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M8 3H5a2 2 0 0 0-2 2v3'/%3E%3Cpath d='M16 3h3a2 2 0 0 1 2 2v3'/%3E%3Cpath d='M8 21H5a2 2 0 0 1-2-2v-3'/%3E%3Cpath d='M16 21h3a2 2 0 0 0 2-2v-3'/%3E%3C/svg%3E",
        imp:    "%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23474747' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4'/%3E%3Cpath d='M7 10l5 5 5-5'/%3E%3Cpath d='M12 15V3'/%3E%3C/svg%3E",
        exp:    "%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23474747' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4'/%3E%3Cpath d='M7 8l5-5 5 5'/%3E%3Cpath d='M12 3v12'/%3E%3C/svg%3E",
        save:   "%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23474747' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z'/%3E%3Cpath d='M17 21v-8H7v8'/%3E%3Cpath d='M7 3v5h8'/%3E%3C/svg%3E",
        clear:  "%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23474747' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='12' cy='12' r='9'/%3E%3Cpath d='M8 8l8 8'/%3E%3Cpath d='M16 8l-8 8'/%3E%3C/svg%3E",
        del:    "%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23474747' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M3 6h18'/%3E%3Cpath d='M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2'/%3E%3Cpath d='M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6'/%3E%3C/svg%3E",
    };

    // 项定义：key / 文案 / 图标 / 分隔线
    const ITEMS = [
        { key: 'undo',  text: '撤销', icon: 'undo',  title: '撤销上一步操作' },
        { key: 'redo',  text: '重做', icon: 'redo',  title: '恢复被撤销的操作' },
        { key: 'zin',   text: '放大', icon: 'zin',   title: '放大画布' },
        { key: 'zout',  text: '缩小', icon: 'zout',  title: '缩小画布' },
        { key: 'fit',   text: '适应', icon: 'fit',   title: '画布内容适应视图' },
        { key: 'import', text: '导入', icon: 'imp',   title: '导入 JSON 文件', divider: true },
        { key: 'export', text: '导出', icon: 'exp',   title: '导出 JSON（右侧抽屉展示）' },
        { key: 'save',   text: '保存', icon: 'save',  title: '校验流程完整性并推送到父页' },
        { key: 'clear',  text: '清空', icon: 'clear', title: '清空当前画布' },
        { key: 'del',   text: '删除', icon: 'del',   title: '删除选中的节点与连线', divider: true },
    ];

    const state = { undo: false, redo: false };

    SC.initPanel = () => {
        const host = document.getElementById('canvas-ops');
        if (!host) return;
        host.innerHTML = '';
        for (const item of ITEMS) {
            if (item.divider) {
                const d = document.createElement('div');
                d.className = 'lf-control-divider';
                host.appendChild(d);
            }
            const el = document.createElement('div');
            el.className = 'lf-control-item';
            el.dataset.op = item.key;
            el.title = item.title;
            el.innerHTML = `<i style="background-image:url(&quot;data:image/svg+xml,${ICONS[item.icon]}&quot;)"></i><span>${item.text}</span>`;
            el.addEventListener('click', () => SC.panelAction(item.key));
            host.appendChild(el);
        }
        SC.syncPanel();
    };

    /** 面板动作分发（删除走统一入口 SC.deleteSelected，文档操作见 toolbar.js） */
    SC.panelAction = (key) => {
        const lf = SC.lf;
        if (!lf) return;
        try {
            switch (key) {
                case 'undo': lf.undo(); SC.select && SC.select(null); break;
                case 'redo': lf.redo(); SC.select && SC.select(null); break;
                case 'zin': lf.zoom(true); break;
                case 'zout': lf.zoom(false); break;
                case 'fit': lf.fitView(60, 60); break;
                case 'import': SC.doImport && SC.doImport(); break;
                case 'export': SC.openExportDrawer && SC.openExportDrawer(); break;
                case 'save': SC.doSave && SC.doSave(); break;
                case 'clear': SC.doClear && SC.doClear(); break;
                case 'del': SC.deleteSelected(); break;
            }
        } catch (e) { /* 画布销毁中忽略 */ }
    };

    /** 撤销/重做可用态同步（history:change 事件驱动，见 app.js） */
    SC.syncPanel = () => {
        const host = document.getElementById('canvas-ops');
        if (!host) return;
        for (const el of host.querySelectorAll('.lf-control-item')) {
            const key = el.dataset.op;
            const disabled = (key === 'undo' && !state.undo) || (key === 'redo' && !state.redo);
            el.classList.toggle('is-disabled', disabled);
        }
    };

    SC.setPanelHistory = (canUndo, canRedo) => {
        state.undo = !!canUndo;
        state.redo = !!canRedo;
        SC.syncPanel();
    };
})(window.SC);
