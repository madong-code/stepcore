<?php
/**
 * stepcore demo · 宿主页
 *
 * 左侧以 iframe 嵌入自研 UI 设计器（/ui/），右侧展示：
 *   - 设计器导出的 v2 路线 JSON
 *   - 调 /api/parse 得到的校验问题与引擎走查序列
 * 通过 postMessage 与 iframe 双向通信（flow.ready / flow.load / flow.save）。
 */

declare(strict_types=1);

// 示例路线：start → 工序A → 判定 →(branch) 工序B / 工序C → 汇合 → 结束
$sample = [
    'schema_version' => 2,
    'meta'           => ['code' => 'demo', 'name' => '演示路线', 'version' => '1.0', 'graph_hash' => null],
    'nodes'          => [
        ['node_key' => 'nd_start', 'type' => 'start', 'name' => '开始', 'properties' => (object)[], 'steps' => [], 'ext' => (object)[]],
        ['node_key' => 'nd_a', 'type' => 'step', 'name' => '工序 A', 'properties' => (object)[], 'steps' => [], 'ext' => (object)[]],
        ['node_key' => 'nd_d', 'type' => 'decision', 'name' => '判定', 'properties' => (object)[], 'steps' => [], 'ext' => (object)[]],
        ['node_key' => 'nd_b', 'type' => 'step', 'name' => '工序 B', 'properties' => (object)[], 'steps' => [], 'ext' => (object)[]],
        ['node_key' => 'nd_c', 'type' => 'step', 'name' => '工序 C', 'properties' => (object)[], 'steps' => [], 'ext' => (object)[]],
        ['node_key' => 'nd_j', 'type' => 'join', 'name' => '汇合', 'properties' => (object)[], 'steps' => [], 'ext' => (object)[]],
        ['node_key' => 'nd_end', 'type' => 'end', 'name' => '结束', 'properties' => (object)[], 'steps' => [], 'ext' => (object)[]],
    ],
    'transitions'    => [
        ['edge_key' => 'ed_1', 'from_node_key' => 'nd_start', 'to_node_key' => 'nd_a', 'mode' => 'straight', 'properties' => ['trigger' => ['type' => 'manual'], 'priority' => 100, 'enabled' => true, 'weight' => null], 'ext' => []],
        ['edge_key' => 'ed_2', 'from_node_key' => 'nd_a', 'to_node_key' => 'nd_d', 'mode' => 'straight', 'properties' => ['trigger' => ['type' => 'manual'], 'priority' => 100, 'enabled' => true, 'weight' => null], 'ext' => []],
        ['edge_key' => 'ed_3', 'from_node_key' => 'nd_d', 'to_node_key' => 'nd_b', 'mode' => 'branch', 'properties' => ['trigger' => ['type' => 'auto'], 'priority' => 100, 'enabled' => true, 'weight' => 1], 'ext' => []],
        ['edge_key' => 'ed_4', 'from_node_key' => 'nd_d', 'to_node_key' => 'nd_c', 'mode' => 'branch', 'properties' => ['trigger' => ['type' => 'auto'], 'priority' => 100, 'enabled' => true, 'weight' => 1], 'ext' => []],
        ['edge_key' => 'ed_5', 'from_node_key' => 'nd_b', 'to_node_key' => 'nd_j', 'mode' => 'branch', 'properties' => ['trigger' => ['type' => 'manual'], 'priority' => 100, 'enabled' => true, 'weight' => null], 'ext' => []],
        ['edge_key' => 'ed_6', 'from_node_key' => 'nd_c', 'to_node_key' => 'nd_j', 'mode' => 'branch', 'properties' => ['trigger' => ['type' => 'manual'], 'priority' => 100, 'enabled' => true, 'weight' => null], 'ext' => []],
        ['edge_key' => 'ed_7', 'from_node_key' => 'nd_j', 'to_node_key' => 'nd_end', 'mode' => 'straight', 'properties' => ['trigger' => ['type' => 'manual'], 'priority' => 100, 'enabled' => true, 'weight' => null], 'ext' => []],
    ],
    'layout'         => [
        'nodes'    => [
            'nd_start' => ['x' => 80, 'y' => 200],
            'nd_a'     => ['x' => 220, 'y' => 200],
            'nd_d'     => ['x' => 360, 'y' => 200],
            'nd_b'     => ['x' => 520, 'y' => 120],
            'nd_c'     => ['x' => 520, 'y' => 300],
            'nd_j'     => ['x' => 680, 'y' => 200],
            'nd_end'   => ['x' => 820, 'y' => 200],
        ],
        'viewport' => [],
        'groups'   => [],
    ],
    'extensions'     => [],
];
$sampleJson = \json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StepCore Demo · 流程设计器联调</title>
    <style>
        /* tokens 与设计器 designer.css 同源 */
        :root {
            --primary: #2563EB; --primary-d: #1D4ED8; --primary-l: #0EA5E9;
            --bg: #F4F7FB; --panel: #FFFFFF; --bg-2: #EEF2F7;
            --text: #0F172A; --text-2: #5B6B7E;
            --ok: #16A34A; --err: #DC2626; --warn: #D97706;
            --border: #E2E8F0;
            --sp-2: 8px; --sp-3: 12px; --sp-4: 16px;
            --r-md: 8px; --r-lg: 10px;
            --fs-xs: 12px; --fs-sm: 13px; --fs-md: 14px; --fs-lg: 15px;
            --footer-h: 36px;
            --bar-h: 44px;
            --shadow: 0 1px 2px rgba(15,23,42,.06), 0 8px 24px rgba(15,23,42,.08);
            --shadow-sm: 0 1px 3px rgba(15,23,42,.08);
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0; font-family: Roboto, "Segoe UI", system-ui, -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif;
            color: var(--text); background: var(--bg); font-size: var(--fs-sm);
            display: flex; flex-direction: column; overflow: hidden;
            -webkit-font-smoothing: antialiased;
        }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 999px; border: 2px solid transparent; background-clip: content-box; }
        ::-webkit-scrollbar-thumb:hover { background: #94A3B8; background-clip: content-box; }
        ::-webkit-scrollbar-track { background: transparent; }

        /* ---------- 底部操作条（宿主页调试操作收进底部细条，页面头部仅保留设计器工具栏一行） ---------- */
        footer {
            min-height: var(--footer-h); flex: none;
            padding: 0 var(--sp-4); background: linear-gradient(90deg, var(--primary), var(--primary-l));
            color: #fff; display: flex; align-items: center; gap: var(--sp-2); z-index: 5;
        }
        footer .title { font-size: var(--fs-sm); font-weight: 600; margin: 0; white-space: nowrap; margin-right: auto; }
        footer .hint { font-size: var(--fs-xs); opacity: .85; white-space: nowrap; }

        /* ---------- 全屏设计器 + 调试抽屉 ---------- */
        main { flex: 1; display: flex; flex-direction: column; min-height: 0; }

        .bar {
            min-height: var(--bar-h); flex: none;
            padding: 0 var(--sp-3); background: var(--bg-2); border-bottom: 1px solid var(--border);
            display: flex; gap: var(--sp-2); align-items: center;
        }
        .bar .title { font-size: var(--fs-md); font-weight: 600; color: var(--text); margin-right: auto; }
        .bar .meta { font-size: var(--fs-xs); color: var(--text-2); }

        button.ui {
            font-family: inherit; font-size: var(--fs-sm); line-height: 1; cursor: pointer;
            padding: 8px 14px; border-radius: var(--r-md);
            border: 1px solid var(--border); background: #fff; color: var(--text);
            transition: all .15s ease; white-space: nowrap;
        }
        button.ui:hover { border-color: var(--primary); color: var(--primary); }
        button.ui:active { transform: translateY(1px); }
        button.ui.primary { background: var(--primary); border-color: var(--primary); color: #fff; font-weight: 600; }
        button.ui.primary:hover { background: var(--primary-d); color: #fff; }
        button.ui:focus-visible { outline: 2px solid var(--primary); outline-offset: 1px; }

        iframe { flex: 1; border: 0; width: 100%; background: #fff; }

        /* ---------- 调试抽屉（右侧滑出，默认收起） ---------- */
        .drawer-mask {
            position: fixed; inset: 0; z-index: 70; background: rgba(15, 23, 42, .35);
            opacity: 0; pointer-events: none; transition: opacity .2s ease;
        }
        .drawer-mask.show { opacity: 1; pointer-events: auto; }
        .drawer {
            position: fixed; top: 0; right: 0; bottom: 0; z-index: 71;
            width: min(560px, 92vw); display: flex; flex-direction: column;
            background: var(--panel); box-shadow: -8px 0 24px rgba(15, 23, 42, .18);
            transform: translateX(105%); transition: transform .22s ease;
        }
        .drawer.show { transform: translateX(0); }
        .drawer-head {
            display: flex; align-items: center; justify-content: space-between; flex: none;
            padding: 0 var(--sp-3); min-height: var(--bar-h); background: var(--bg-2); border-bottom: 1px solid var(--border);
        }
        .drawer-head .title { font-size: var(--fs-md); font-weight: 600; }
        .drawer-head .meta { font-size: var(--fs-xs); color: var(--text-2); }
        .drawer-body { flex: 1; overflow: auto; padding: var(--sp-3); min-height: 0; }

        /* ---------- 右侧结果区 ---------- */
        .section { display: flex; flex-direction: column; min-height: 0; }
        #sec-json pre { height: auto; max-height: 320px; }

        .empty {
            color: var(--text-2); text-align: center; margin-top: 40px; font-size: var(--fs-sm); line-height: 1.8;
        }
        pre {
            background: #0F172A; color: #E2E8F0; padding: var(--sp-3);
            border-radius: var(--r-lg); font-size: var(--fs-xs); line-height: 1.6;
            overflow: auto; margin: 0; height: 100%;
        }

        h3.sec-label { font-size: var(--fs-xs); font-weight: 600; letter-spacing: .4px; color: var(--text-2); margin: 0 0 var(--sp-2); }

        .issues { font-size: var(--fs-sm); display: flex; align-items: center; gap: var(--sp-2); flex-wrap: wrap; }
        .pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 999px; font-weight: 500; font-size: var(--fs-sm);
        }
        .pill.ok   { background: #ECFDF5; color: var(--ok); border: 1px solid #BBF7D0; }
        .pill.bad  { background: #FEF2F2; color: var(--err); border: 1px solid #FECACA; }
        .pill.info { background: #EFF6FF; color: var(--primary-d); border: 1px solid #BFDBFE; }
        .pill.wait { background: var(--bg-2); color: var(--text-2); }
        .issues .count { font-size: var(--fs-xs); color: var(--text-2); }

        .walk { list-style: none; padding: 0; margin: 0; }
        .walk li {
            display: flex; align-items: center; gap: var(--sp-2);
            padding: 8px var(--sp-3); border: 1px solid var(--border); border-radius: var(--r-md);
            margin-bottom: var(--sp-2); font-size: var(--fs-sm); background: #fff; transition: box-shadow .15s;
        }
        .walk li:hover { box-shadow: var(--shadow-sm); }
        .badge {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 68px; padding: 3px 8px; border-radius: 999px;
            font-size: var(--fs-xs); font-weight: 600; letter-spacing: .2px; flex: none;
        }
        .badge.straight   { background: #F1F5F9; color: #475569; }
        .badge.branch     { background: #EFF6FF; color: var(--primary-d); }
        .badge.join       { background: #FFFBEB; color: var(--warn); }
        .badge.tail       { background: #ECFDF5; color: var(--ok); }
        .badge.rework     { background: #FAF5FF; color: #9333EA; }
        .badge.blocked, .badge.error { background: #FEF2F2; color: var(--err); }
        .walk .name { font-weight: 500; }
        .walk .detail { color: var(--text-2); font-size: var(--fs-xs); }
        .walk .arrow { color: #94A3B8; flex: none; }

        /* ---------- toast ---------- */
        .toast-host {
            position: fixed; bottom: calc(var(--footer-h) + 10px); left: 50%;
            transform: translateX(-50%); z-index: 60;
            display: flex; flex-direction: column; align-items: center; gap: 6px; pointer-events: none;
        }
        .toast {
            font-size: var(--fs-sm); color: #fff; padding: 8px 16px;
            border-radius: 999px; box-shadow: var(--shadow);
            background: rgba(15, 23, 42, .85); backdrop-filter: blur(4px);
            animation: toast-in .18s ease;
        }
        .toast.ok { background: rgba(22, 163, 74, .92); }
        .toast.err { background: rgba(220, 38, 38, .92); }
        .toast.out { opacity: 0; transition: opacity .25s ease; }
        @keyframes toast-in { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }

        /* ---------- 响应式 ---------- */
        @media (max-width: 1024px) {
            footer .hint { display: none; }
            .drawer { width: 100vw; }
        }
        @media (max-width: 560px) {
            footer .title { font-size: var(--fs-md); }
            .bar .meta { display: none; }
        }
    </style>
</head>
<body>
    <main>
        <iframe id="designer" src="/ui/" title="StepCore 流程设计器"></iframe>
    </main>

    <footer>
        <span class="title">StepCore 流程设计器 · 联调演示（iframe 嵌入 / postMessage / LogicFlow 本地内置）</span>
        <span class="hint">设计器保存后自动解析 → 点「调试面板」查看导出 JSON 与引擎走查</span>
        <button id="btn-sample" class="ui primary">发送示例到设计器</button>
        <button id="btn-parse" class="ui">重新解析</button>
        <button id="btn-debug" class="ui">调试面板</button>
    </footer>

    <!-- 调试抽屉：导出 JSON + 引擎解析结果（默认收起，不遮挡设计器） -->
    <div id="drawer-mask" class="drawer-mask"></div>
    <aside id="debug-drawer" class="drawer" aria-hidden="true">
        <div class="drawer-head">
            <span class="title">调试面板</span>
            <span class="meta" id="drawer-meta"></span>
            <button id="btn-drawer-close" class="ui" style="padding:6px 10px;font-size:12px;">✕ 关闭</button>
        </div>
        <div class="drawer-body">
            <div class="section" id="sec-json">
                <h3 class="sec-label">导出 JSON　<span class="meta" id="json-meta"></span>
                    <button id="btn-copy" class="ui" style="padding:4px 10px;font-size:12px;margin-left:8px;">复制</button>
                </h3>
                <pre id="json"><span class="empty">设计器尚未保存
在设计器中编辑或点击「保存并推送父页」后自动同步</span></pre>
            </div>

            <div class="section" id="sec-result" style="margin-top:16px;">
                <h3 class="sec-label">引擎解析结果　<span class="meta" id="parse-meta"></span></h3>
                <div id="issues" class="issues"><span class="pill wait">等待设计器保存…</span></div>
                <h3 class="sec-label" style="margin-top:16px;">走查序列（从起点按引擎解析）</h3>
                <ul id="walk" class="walk"></ul>
                <div id="walk-empty" class="empty">暂无走查数据</div>
            </div>
        </div>
    </aside>

    <div id="toast-host" class="toast-host"></div>

    <script>
        const SAMPLE = <?= $sampleJson ?>;
        const designer = document.getElementById('designer');
        let currentGraph = null;

        // ---- toast 轻反馈 ----
        let toastTimer = null;
        function toast(msg, type = '') {
            const host = document.getElementById('toast-host');
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
        }

        function postToDesigner(msg) {
            designer.contentWindow.postMessage(msg, '*');
        }

        // 走查步骤类型 → 徽章样式与中文说明
        const TYPE_LABEL = {
            straight: '直行', branch: '分支', join: '汇合', tail: '尾站',
            rework: '返工', blocked: '阻塞', error: '异常',
        };

        // 收到设计器消息
        window.addEventListener('message', (e) => {
            const data = e.data;
            if (!data || typeof data !== 'object') return;
            if (data.type === 'flow.ready') {
                // 设计器就绪：载入示例；若消息偶发丢失，1200ms 后重试直至收到回传
                loadSampleWithRetry();
                return;
            }
            if (data.type === 'flow.save') {
                currentGraph = data.payload.graph;
                renderJson(currentGraph);
                parseGraph(currentGraph);
            }
        });

        function loadSampleWithRetry(attempt = 0) {
            postToDesigner({ type: 'flow.load', payload: { graph: SAMPLE } });
            if (attempt < 2) {
                setTimeout(() => { if (!currentGraph) loadSampleWithRetry(attempt + 1); }, 1200);
            }
        }

        function renderJson(graph) {
            const json = JSON.stringify(graph, null, 2);
            document.getElementById('json').textContent = json;
            const meta = '节点 ' + graph.nodes.length + ' · 连线 ' + graph.transitions.length;
            document.getElementById('json-meta').textContent = meta;
            document.getElementById('drawer-meta').textContent = meta;
        }

        // ---- 调试抽屉开关 ----
        const drawer = document.getElementById('debug-drawer');
        const drawerMask = document.getElementById('drawer-mask');
        function toggleDrawer(show) {
            const on = show ?? !drawer.classList.contains('show');
            drawer.classList.toggle('show', on);
            drawerMask.classList.toggle('show', on);
        }
        document.getElementById('btn-debug').onclick = () => toggleDrawer();
        document.getElementById('btn-drawer-close').onclick = () => toggleDrawer(false);
        drawerMask.addEventListener('click', () => toggleDrawer(false));

        document.getElementById('btn-copy').onclick = async () => {
            if (!currentGraph) { toast('暂无可复制的 JSON，请先在设计器中保存', 'err'); return; }
            const text = JSON.stringify(currentGraph, null, 2);
            try {
                await navigator.clipboard.writeText(text);
                toast('JSON 已复制到剪贴板', 'ok');
            } catch (_) {
                // 剪贴板 API 不可用时回退
                const ta = document.createElement('textarea');
                ta.value = text; document.body.appendChild(ta); ta.select();
                document.execCommand('copy'); ta.remove();
                toast('JSON 已复制到剪贴板', 'ok');
            }
        };

        async function parseGraph(graph) {
            const issuesEl = document.getElementById('issues');
            const walkEl = document.getElementById('walk');
            const walkEmpty = document.getElementById('walk-empty');
            issuesEl.innerHTML = '<span class="pill wait">解析中…</span>';
            walkEl.innerHTML = '';
            walkEmpty.style.display = 'none';
            try {
                const resp = await fetch('/api/parse', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(graph),
                });
                const r = await resp.json();
                if (!r.ok) {
                    issuesEl.innerHTML = '<span class="pill bad">解析失败：' + (r.error || '未知错误') + '</span>';
                    return;
                }
                document.getElementById('parse-meta').textContent = '节点 ' + r.node_count + ' · 边 ' + r.edge_count + ' · hash ' + r.graph_hash.slice(0, 8) + '…';

                const issues = r.issues || [];
                issuesEl.innerHTML = issues.length === 0
                    ? '<span class="pill ok">✓ 无校验问题</span>'
                    : '<span class="pill bad">✗ ' + issues.length + ' 个问题</span>' + issues.map((i) => '<span class="count">· ' + i + '</span>').join(' ');

                const steps = r.walkthrough || [];
                walkEl.innerHTML = steps.map((step, idx) => {
                    const cls = step.type === 'blocked' || step.type === 'error' ? step.type : step.type;
                    const badge = '<span class="badge ' + cls + '">' + (TYPE_LABEL[step.type] || step.type) + '</span>';
                    const detail = step.detail ? '<span class="detail">' + step.detail + '</span>'
                        : (step.blocked ? '<span class="detail">（' + step.blocked + '）</span>'
                        : (step.targets && step.targets.length
                            ? '<span class="arrow">→</span><span class="detail">分支 ' + step.targets.map((t) => t.to_node_key).join(' / ') + '</span>'
                            : (step.to ? '<span class="arrow">→</span><span class="detail">' + step.to + '</span>' : '')));
                    return '<li>' + (idx + 1 ? '<span class="detail" style="min-width:18px;">' + (idx + 1) + '.</span>' : '') + badge
                        + '<span class="name">' + (step.from_name || step.from) + '</span>' + detail + '</li>';
                }).join('');
                walkEmpty.style.display = steps.length ? 'none' : 'block';
            } catch (err) {
                issuesEl.innerHTML = '<span class="pill bad">请求失败：' + err.message + '</span>';
            }
        }

        document.getElementById('btn-sample').onclick = () => {
            postToDesigner({ type: 'flow.load', payload: { graph: SAMPLE } });
            toast('示例已发送到设计器', 'ok');
        };
        document.getElementById('btn-parse').onclick = () => {
            if (currentGraph) parseGraph(currentGraph);
            else toast('请先在设计器中保存/导出', 'err');
        };
    </script>
</body>
</html>
