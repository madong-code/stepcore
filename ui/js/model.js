/* model.js — v2 路线 JSON ↔ LogicFlow 图数据 双向适配 + 主题与类型定义 */
window.SC = window.SC || {};
(function (SC) {
    // 节点类型规范：字形图标与中性色对齐 MES 设计器面板；shape 为 nodes.js 注册的规格节点
    const NODE_TYPES = [
        { type: 'start',     label: '开始',     shape: 'sc:pill',    color: '#6b7785', icon: '▶' },
        { type: 'step',      label: '工序',     shape: 'sc:rect',    color: '#6b7785', icon: '⚙' },
        { type: 'fa_rework', label: '首件返工', shape: 'sc:rect',    color: '#6b7785', icon: '★' },
        { type: 'decision',  label: '判定',     shape: 'sc:diamond', color: '#6b7785', icon: '◆' },
        { type: 'split',     label: '分叉',     shape: 'sc:rect',    color: '#6b7785', icon: '⋀' },
        { type: 'join',      label: '汇合',     shape: 'sc:rect',    color: '#6b7785', icon: '⋁' },
        { type: 'end',       label: '结束',     shape: 'sc:pill',    color: '#6b7785', icon: '■' },
    ];
    SC.NODE_TYPES = NODE_TYPES;
    const BY_TYPE = Object.fromEntries(NODE_TYPES.map((n) => [n.type, n]));

    SC.typeLabel = (t) => (BY_TYPE[t] ? BY_TYPE[t].label : t);
    SC.shapeFor  = (t) => (BY_TYPE[t] ? BY_TYPE[t].shape : 'sc:rect');

    /** 渲染串行化：LF2 render 为异步且不可并发（mobx invariant），本版本 render() 不返回 Promise，
     * 以 graph:rendered 事件作为完成信号（超时 3s 兜底）。注意：事件回调参数为图数据而非错误，
     * 仅当 render 同步抛出 Error 时才视为失败。 */
    SC.doRender = (data) => new Promise((resolve, reject) => {
        const lf = SC.lf;
        let done = false;
        const finish = (payload) => {
            if (done) return;
            done = true;
            try { lf.off('graph:rendered', finish); } catch (_) {}
            if (payload instanceof Error) reject(payload); else resolve();
        };
        lf.on('graph:rendered', finish);
        try { lf.render(data); } catch (e) { finish(e); return; }
        setTimeout(finish, 3000);
    });

    SC.EDGE_MODES = ['straight', 'branch', 'join', 'rework', 'conditional', 'event', 'timer', 'rollback', 'jump', 'cancel'];
    SC.TRIGGER_TYPES = ['manual', 'auto', 'auto_ng', 'event', 'timer', 'threshold', 'rollback', 'reversal'];

    SC.LF_THEME = {
        polyline: { stroke: '#6b7785', strokeWidth: 1.5 },
        arrow:    { offset: 10, verticalLength: 4 },
        nodeText: { color: '#1d2129', fontSize: 12, fontWeight: 500 },
        edgeText: { color: '#475569', fontSize: 12, fontWeight: 500 },
        anchor:   { stroke: '#6b7785', fill: '#ffffff', r: 3.5 },
        anchorLine: { stroke: '#6b7785', strokeWidth: 1, strokeDasharray: '3,2' },
        snapline: { stroke: '#2563EB', strokeWidth: 1 },
    };

    let _seq = 0;
    SC.uid = (p) => { _seq++; return p + '_' + Date.now().toString(36) + _seq; };

    /** 路线元信息：loadV2 载入时由 v2.meta 回填，可在属性面板「路线信息」组编辑，导出/推送时携带 */
    SC._meta = { code: 'designer', name: '', version: '1.0' };

    SC.toLFData = (v2) => {
        const meta = v2.meta || {};
        SC._meta = {
            code: meta.code || '',
            name: meta.name || '',
            version: meta.version || '1.0',
        };
        const nodes = (v2.nodes || []).map((n) => {
            const pos = (v2.layout && v2.layout.nodes && v2.layout.nodes[n.node_key]) || { x: 120, y: 120 };
            return {
                id: n.node_key,
                type: SC.shapeFor(n.type),
                x: pos.x, y: pos.y,
                text: n.name || n.node_key,
                properties: { scType: n.type, scName: n.name || '', scProps: n.properties || {}, scSteps: n.steps || [], scExt: n.ext || {} },
            };
        });
        const edges = (v2.transitions || []).map((e) => ({
            id: e.edge_key,
            type: 'polyline',
            sourceNodeId: e.from_node_key,
            targetNodeId: e.to_node_key,
            text: e.mode || 'straight',
            properties: {
                scMode: e.mode || 'straight',
                scTrigger: (e.properties && e.properties.trigger && e.properties.trigger.type) || 'manual',
                scPriority: (e.properties && e.properties.priority != null) ? e.properties.priority : 100,
                scEnabled: !(e.properties && e.properties.enabled === false),
                scWeight: (e.properties && e.properties.weight != null) ? e.properties.weight : null,
            },
        }));
        return { nodes, edges };
    };

    SC.fromLFData = (lfData) => {
        const layoutNodes = {};
        const nodes = (lfData.nodes || []).map((n) => {
            const p = n.properties || {};
            layoutNodes[n.id] = { x: Math.round(n.x), y: Math.round(n.y) };
            const text = (n.text && n.text.value) ? n.text.value : (n.text || p.scName || n.id);
            return {
                node_key: n.id, type: p.scType || 'step', name: text,
                properties: p.scProps || {}, steps: p.scSteps || [], ext: p.scExt || {},
            };
        });
        const transitions = (lfData.edges || []).map((e) => {
            const p = e.properties || {};
            return {
                edge_key: e.id, from_node_key: e.sourceNodeId, to_node_key: e.targetNodeId, mode: p.scMode || 'straight',
                properties: {
                    trigger: { type: p.scTrigger || 'manual' },
                    priority: p.scPriority != null ? p.scPriority : 100,
                    enabled: p.scEnabled !== false,
                    weight: p.scWeight != null ? p.scWeight : null,
                },
                ext: {},
            };
        });
        return {
            schema_version: 2,
            meta: {
                code: SC._meta.code || '',
                name: SC._meta.name || '',
                version: SC._meta.version || '1.0',
                graph_hash: null,
            },
            nodes, transitions,
            layout: { nodes: layoutNodes, viewport: {}, groups: [] },
            extensions: {},
        };
    };

    /** 保存前置校验：必填项 + 流程完整性
     * 1) 路线编码/名称必填；2) 全部节点名称非空；
     * 3) 结构完整性：必须有开始/结束节点、无未连接的孤立节点、开始节点可达所有节点、
     *    非结束节点不允许流程中断（无出边）；未通过禁止推送父页。
     */
    SC.validateBeforeSave = () => {
        const errors = [];
        const code = String(SC._meta.code || '').trim();
        const name = String(SC._meta.name || '').trim();
        if (!code) errors.push('未配置路线编码');
        if (!name) errors.push('未配置路线名称');

        const data = SC.lf ? SC.lf.getGraphData() : { nodes: [], edges: [] };
        const nodes = data.nodes || [];
        const edges = data.edges || [];
        const typeOf = (n) => ((n.properties && n.properties.scType) || 'step');
        const nameOf = (n) => {
            const t = (n.text && n.text.value) ? n.text.value : ((n.properties && n.properties.scName) || '');
            return String(t).trim() || n.id;
        };
        const names = (list) => list.map(nameOf).join('、');

        const unnamed = nodes.filter((n) => {
            const t = (n.text && n.text.value) ? n.text.value : ((n.properties && n.properties.scName) || '');
            return !String(t).trim();
        });
        if (unnamed.length) errors.push(unnamed.length + ' 个节点未填写名称');

        if (!nodes.some((n) => typeOf(n) === 'start')) errors.push('缺少开始节点');
        if (!nodes.some((n) => typeOf(n) === 'end')) errors.push('缺少结束节点');

        // 连接度统计
        const outDeg = {}, inDeg = {};
        edges.forEach((e) => {
            if (e.sourceNodeId != null) outDeg[e.sourceNodeId] = (outDeg[e.sourceNodeId] || 0) + 1;
            if (e.targetNodeId != null) inDeg[e.targetNodeId] = (inDeg[e.targetNodeId] || 0) + 1;
        });
        const orphans = nodes.filter((n) => !outDeg[n.id] && !inDeg[n.id]);
        if (orphans.length) errors.push(orphans.length + ' 个节点未连接：' + names(orphans));

        // 从开始节点出发的可达性（BFS）
        if (nodes.length && edges.length) {
            const starts = nodes.filter((n) => typeOf(n) === 'start').map((n) => n.id);
            const adj = {};
            edges.forEach((e) => { (adj[e.sourceNodeId] = adj[e.sourceNodeId] || []).push(e.targetNodeId); });
            const seen = new Set(starts);
            const queue = starts.slice();
            while (queue.length) {
                const cur = queue.shift();
                (adj[cur] || []).forEach((next) => { if (!seen.has(next)) { seen.add(next); queue.push(next); } });
            }
            const unreachable = nodes.filter((n) => !seen.has(n.id) && (outDeg[n.id] || inDeg[n.id]));
            if (unreachable.length) errors.push('从开始节点不可达：' + names(unreachable));
        }

        // 流程中断：已接入流程的非结束节点没有任何出边
        const deadEnds = nodes.filter((n) => typeOf(n) !== 'end' && inDeg[n.id] && !outDeg[n.id]);
        if (deadEnds.length) errors.push('流程中断（无出边）：' + names(deadEnds));

        return { ok: errors.length === 0, errors };
    };

    SC.exportV2 = (lf) => SC.fromLFData(lf.getGraphData());
})(window.SC);
