/* toolbar.js — 节点面板（左侧）+ 导入/导出/清空/保存 */
window.SC = window.SC || {};
(function (SC) {
    SC.initToolbar = () => {
        const palette = document.getElementById('palette');

        // 节点面板（MES 规范）：纯图标瓦片 + title 悬浮说明；mousedown 直接发起 lf.dnd.startDrag，
        // 落点即鼠标位置（对齐 template/admin/src/plugin/mes palette.vue 交互）。
        // 添加节点仅支持拖拽放置，点击瓦片不触发任何添加行为。
        SC.NODE_TYPES.forEach((t) => {
            const b = document.createElement('button');
            b.className = 'palette-item';
            b.title = t.label + ' · 拖拽到画布添加';
            b.innerHTML = `<span class="item-icon" style="background:${t.color}">${t.icon}</span>`;
            b.addEventListener('mousedown', (e) => {
                e.preventDefault();
                SC.startPaletteDrag(t);
            });
            palette.appendChild(b);
        });

        SC.bindFileInput();

        // 兼容顶部工具栏按钮（当前工具栏仅保留品牌，四个操作按钮统一归属画布右上角操作面板）
        const bind = (id, fn) => { const el = document.getElementById(id); if (el) el.onclick = fn; };
        bind('btn-import', SC.doImport);
        bind('btn-export', SC.openExportDrawer);
        bind('btn-save', SC.doSave);
        bind('btn-clear', SC.doClear);
    };

    /** 文件导入：选择 JSON 并载入画布 */
    SC.doImport = () => document.getElementById('file-input').click();

    SC.bindFileInput = () => {
        document.getElementById('file-input').onchange = (e) => {
            const f = e.target.files[0];
            if (!f) return;
            const r = new FileReader();
            r.onload = () => {
                try {
                    SC.loadV2(JSON.parse(r.result));
                    SC.toast('导入成功', 'ok');
                } catch (err) { SC.toast('JSON 解析失败：' + err.message, 'err'); }
                e.target.value = '';
            };
            r.readAsText(f);
        };
    };

    /** 保存并推送父页（前置校验见 model.js validateBeforeSave） */
    SC.doSave = () => {
        const v = SC.validateBeforeSave();
        if (!v.ok) { SC.toast('流程不完整，禁止保存：' + v.errors.join('；'), 'err'); return; }
        SC.postSave();
        SC.toast('已推送到父页', 'ok');
    };

    /** 清空画布（需确认） */
    SC.doClear = () => {
        if (confirm('确认清空当前画布？')) { SC.lf.render({ nodes: [], edges: [] }); SC.select(null); SC.postSave(); }
    };

    /** 导出抽屉：格式化展示当前画布 v2 JSON，复制 / 下载 / 关闭 */
    SC.openExportDrawer = () => {
        const drawer = document.getElementById('export-drawer');
        const mask = document.getElementById('export-drawer-mask');
        const pre = document.getElementById('export-json');
        if (!drawer || !mask || !pre) return;
        pre.textContent = JSON.stringify(SC.exportV2(SC.lf), null, 2);
        document.getElementById('mode-popup')?.classList.add('hidden');
        SC.closeCtxMenu && SC.closeCtxMenu();
        mask.classList.add('show');
        drawer.classList.add('show');
    };
    SC.closeExportDrawer = () => {
        document.getElementById('export-drawer')?.classList.remove('show');
        document.getElementById('export-drawer-mask')?.classList.remove('show');
    };
    SC.initExportDrawer = () => {
        const mask = document.getElementById('export-drawer-mask');
        const btnCopy = document.getElementById('btn-export-copy');
        const btnDown = document.getElementById('btn-export-download');
        const btnClose = document.getElementById('btn-export-close');
        mask?.addEventListener('click', SC.closeExportDrawer);
        btnClose?.addEventListener('click', SC.closeExportDrawer);
        btnCopy?.addEventListener('click', async () => {
            const pre = document.getElementById('export-json');
            const text = pre?.textContent || '';
            if (!text) return;
            try {
                await navigator.clipboard.writeText(text);
                SC.toast('已复制到剪贴板', 'ok');
            } catch (_) {
                // 剪贴板 API 不可用时退化为选中文本 + execCommand
                const range = document.createRange();
                range.selectNodeContents(pre);
                const sel = getSelection();
                sel.removeAllRanges(); sel.addRange(range);
                const ok = document.execCommand('copy');
                sel.removeAllRanges();
                SC.toast(ok ? '已复制到剪贴板' : '复制失败，请手动复制', ok ? 'ok' : 'err');
            }
        });
        btnDown?.addEventListener('click', () => {
            const pre = document.getElementById('export-json');
            const text = pre?.textContent || '';
            if (!text) return;
            const blob = new Blob([text], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a'); a.href = url; a.download = 'route-v2.json'; a.click();
            URL.revokeObjectURL(url);
            SC.toast('已导出 route-v2.json', 'ok');
        });
    };
    SC.initExportDrawer();

    /** 面板拖拽入口：LogicFlow DnD，ghost 跟随鼠标，落点即建节点（无需 HTML5 DnD 事件穿透） */
    SC.startPaletteDrag = (t) => {
        const lf = SC.lf;
        if (!lf) return;
        try {
            lf.dnd.startDrag({
                type: SC.shapeFor(t.type),
                text: t.label,
                properties: { scType: t.type, scName: t.label, scProps: {}, scSteps: [], scExt: {} },
            });
        } catch (_) {
            SC.toast('画布尚未就绪，请稍后再试', 'err');
        }
    };

    SC.loadV2 = (v2) => {
        const data = SC.toLFData(v2);
        // LF2 render 为异步且不支持并发调用（mobx invariant），统一串行排队执行
        SC._renderChain = (SC._renderChain || Promise.resolve())
            .then(() => SC.doRender(data))
            .then(() => {
                if (SC.lf.resize) SC.lf.resize();
                // 载入后内容适应视图，保证任何窗口尺寸下节点完整可见
                try { SC.lf.fitView && SC.lf.fitView(60, 60); } catch (_) {}
                SC.select(null);
                // 载入完成后立即推送父页，保证「载入即同步解析」的联调闭环
                if (SC.postSave) SC.postSave();
            })
            .catch((e) => { if (SC.toast) SC.toast('载入失败：' + (e && e.message ? e.message : e), 'err'); });
    };
})(window.SC);
