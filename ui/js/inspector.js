/* inspector.js — 右侧属性面板（节点 / 连线可编辑字段） */
window.SC = window.SC || {};
(function (SC) {
    SC.initInspector = () => {
        const el = document.getElementById('inspector');
        const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        const field = (label, input) => `<label class="f"><span>${label}</span>${input}</label>`;
        const textOf = (t) => (t && t.value) ? t.value : (t || '');

        const renderNode = (d) => {
            const p = d.properties || {};
            el.innerHTML =
                '<div class="insp-title">节点属性</div>' +
                '<div class="insp-group-title">基础信息</div>' +
                field('名称', `<input id="f_name" value="${esc(textOf(d.text))}">`) +
                field('类型', `<select id="f_type">${SC.NODE_TYPES.map((t) => `<option value="${t.type}" ${t.type === p.scType ? 'selected' : ''}>${t.label}</option>`).join('')}</select>`) +
                field('节点键（自动生成）', `<input id="f_key" value="${esc(d.id)}" disabled>`) +
                '<div class="insp-group-title">扩展数据</div>' +
                field('元数据(ext JSON)', `<textarea id="f_ext" rows="4">${esc(JSON.stringify(p.scExt || {}, null, 2))}</textarea>`) +
                '<div class="insp-actions"><button id="f_del">删除节点</button></div>';
            document.getElementById('f_name').oninput = (e) => SC.lf.updateText(d.id, e.target.value);
            document.getElementById('f_type').onchange = (e) => {
                const nd = SC.lf.getNodeDataById(d.id);
                const np = { scType: e.target.value, scName: textOf(nd.text), scProps: p.scProps || {}, scSteps: p.scSteps || [], scExt: p.scExt || {} };
                SC.lf.deleteNode(d.id);
                const nid = SC.lf.addNode({ id: d.id, type: SC.shapeFor(e.target.value), x: nd.x, y: nd.y, text: np.scName, properties: np });
                SC.select('node', nid.id);
            };
            const extEl = document.getElementById('f_ext');
            if (extEl) extEl.oninput = (e) => {
                try { const v = JSON.parse(e.target.value || '{}'); SC.lf.setProperties(d.id, Object.assign({}, p, { scExt: v })); }
                catch (_) { /* 非法 JSON 暂不回写 */ }
            };
            document.getElementById('f_del').onclick = () => SC.deleteElement && SC.deleteElement('node', d.id);
        };

        const renderEdge = (d) => {
            const p = d.properties || {};
            el.innerHTML =
                '<div class="insp-title">连线属性</div>' +
                '<div class="insp-group-title">基础信息</div>' +
                field('从 → 到', `<input value="${esc(d.sourceNodeId)} → ${esc(d.targetNodeId)}" disabled>`) +
                field('类型(mode)', `<select id="f_mode">${SC.EDGE_MODES.map((m) => `<option value="${m}" ${m === p.scMode ? 'selected' : ''}>${m}</option>`).join('')}</select>`) +
                field('触发(trigger)', `<select id="f_trig">${SC.TRIGGER_TYPES.map((t) => `<option value="${t}" ${t === p.scTrigger ? 'selected' : ''}>${t}</option>`).join('')}</select>`) +
                '<div class="insp-group-title">流转参数</div>' +
                field('权重(weight)', `<input id="f_w" type="number" value="${p.scWeight != null ? p.scWeight : ''}">`) +
                field('优先级(priority)', `<input id="f_p" type="number" value="${p.scPriority != null ? p.scPriority : 100}">`) +
                `<label class="f-switch"><span>启用</span><input id="f_en" type="checkbox" ${p.scEnabled !== false ? 'checked' : ''}><span class="track"></span></label>` +
                '<div class="insp-actions"><button id="f_del">删除连线</button></div>';
            document.getElementById('f_mode').onchange = (e) => { SC.lf.setProperties(d.id, Object.assign({}, p, { scMode: e.target.value })); SC.lf.updateText(d.id, e.target.value); };
            document.getElementById('f_trig').onchange = (e) => SC.lf.setProperties(d.id, Object.assign({}, p, { scTrigger: e.target.value }));
            document.getElementById('f_w').oninput = (e) => SC.lf.setProperties(d.id, Object.assign({}, p, { scWeight: e.target.value === '' ? null : Number(e.target.value) }));
            document.getElementById('f_p').oninput = (e) => SC.lf.setProperties(d.id, Object.assign({}, p, { scPriority: Number(e.target.value) }));
            document.getElementById('f_en').onchange = (e) => SC.lf.setProperties(d.id, Object.assign({}, p, { scEnabled: e.target.checked }));
            document.getElementById('f_del').onclick = () => SC.deleteElement && SC.deleteElement('edge', d.id);
        };

        /** 未选中元素：展示「路线信息」编辑组（编码/名称，保存前置校验依赖） */
        const renderMeta = () => {
            el.innerHTML =
                '<div class="insp-title">路线信息</div>' +
                '<div class="insp-group-title">基础信息</div>' +
                field('编码(code) · 保存必填', `<input id="f_meta_code" value="${esc(SC._meta.code || '')}" placeholder="例如：route-demo">`) +
                field('名称(name) · 保存必填', `<input id="f_meta_name" value="${esc(SC._meta.name || '')}" placeholder="例如：演示路线">`) +
                '<div class="empty" style="margin-top:24px">未选择节点 / 连线<br><span class="hint">点击画布中的节点或连线进行编辑</span></div>';
            document.getElementById('f_meta_code').oninput = (e) => { SC._meta.code = e.target.value; };
            document.getElementById('f_meta_name').oninput = (e) => { SC._meta.name = e.target.value; };
        };

        SC.renderInspector = () => {
            const sel = SC.selected;
            if (!sel) { renderMeta(); return; }
            try {
                if (sel.kind === 'node') { const d = SC.lf.getNodeDataById(sel.id); if (d) return renderNode(d); }
                else { const d = SC.lf.getEdgeDataById(sel.id); if (d) return renderEdge(d); }
            } catch (_) { /* 元素已删除 */ }
            el.innerHTML = '<div class="empty">元素不存在</div>';
        };
        SC.renderInspector();
    };
})(window.SC);
