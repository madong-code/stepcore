# 13 · UI 设计器（基于 LogicFlow）

`packages/stepcore/ui/` 是一个**以 iframe 远程嵌入**的流程设计器。画布底层使用
[LogicFlow](https://site.logic-flow.cn/)（通过 HTML `<script>` 直接引入 UMD 包，**无模块化构建、无打包步骤**），
经 `postMessage` 与宿主通信。本文只说明「接入方式」与「基本操作」，不涉及内部实现原理。

> 与内核的关系：设计器只负责产出符合 `route-graph-v2.json` 的 v2 路线 JSON（结构见
> [02-data-model.md](02-data-model.md)），引擎消费由 `FlowGraphParser` / `FlowTransitionResolver` 完成。

## 1. 接入方式

### 1.1 托管设计器

`ui/` 是纯静态站点，可放在任意 Web 服务器下：

- 跟随本包 demo：`/ui/` 即 `packages/stepcore/ui/`（见 `demo/README.md`）。
- 独立部署：把 `ui/` 整个目录拷贝到你的静态资源目录，由 nginx / CDN / 对象存储托管均可。
- LogicFlow 以 `<script src="…/logic-flow.min.js">` 引入，UMD 包已**本地内置**在 `ui/vendor/`，无需 npm install / 构建 / 联网 CDN。

### 1.2 iframe 嵌入

```html
<iframe id="designer" src="https://your-host.example/ui/" style="width:100%;height:600px;border:0;"></iframe>
```

### 1.3 postMessage 通信协议

设计器与父页通过 `postMessage` 双向通信，**天然支持跨域**。消息统一为
`{ type, payload }` 结构：

| 方向 | type | payload | 说明 |
|------|------|---------|------|
| 父 → iframe | `flow.load` | `{ graph: <v2 JSON> }` | 父页向设计器载入已有路线 |
| iframe → 父 | `flow.ready` | — | 设计器加载完成，可接收 `flow.load` |
| iframe → 父 | `flow.save` | `{ graph: <v2 JSON> }` | 设计器模型变化 / 点击保存时推送 |

未知消息类型会被静默忽略。

### 1.4 来源（origin）配置

默认推送目标为 `*`（同源演示可用）。跨域部署时可在设计器页面注入：

```html
<script>window.DESIGNER_TARGET_ORIGIN = 'https://your-host.example';</script>
```

### 1.5 父页最小示例

```html
<iframe id="designer" src="/ui/"></iframe>
<script>
  const designer = document.getElementById('designer');

  // 收到设计器保存/变更
  window.addEventListener('message', (e) => {
    const d = e.data;
    if (d?.type === 'flow.ready') {
      // 可选：载入示例路线
      designer.contentWindow.postMessage({ type: 'flow.load', payload: { graph: SAMPLE_GRAPH } }, '*');
    }
    if (d?.type === 'flow.save') {
      // d.payload.graph 即 v2 路线 JSON，可提交后端 /api/parse 做引擎解析
      fetch('/api/parse', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(d.payload.graph),
      });
    }
  });
</script>
```

## 2. 基本操作

| 操作 | 做法 |
|------|------|
| **添加节点** | 左侧「节点面板」点击类型（开始 / 工序 / 判定 / 分叉 / 汇合 / 首件返工 / 结束），在画布中心附近生成节点 |
| **移动节点** | 在画布中拖动节点；拖空白处可平移画布视图 |
| **连线** | 鼠标悬停节点时四周出现锚点，从锚点拖出到目标节点松开，弹出「连线类型」选择（straight/branch/join/rework/conditional/event/timer/rollback/jump/cancel） |
| **编辑属性** | 单击节点或连线，右侧属性面板显示可编辑字段（名称 / 节点键 / 类型、连线 mode / 触发 / 权重 / 优先级 / 启用），修改即时回写并实时重绘 |
| **删除** | 选中节点或连线后按 `Delete` 键，或在属性面板底部点「删除」 |
| **导入 JSON** | 点「导入 JSON」选择本地 v2 路线文件，载入到画布 |
| **导出 JSON** | 点「导出 JSON」下载当前设计为 `route-v2.json` |
| **保存并推送父页** | 点「保存并推送父页」主动发送 `flow.save`；模型变化也会自动（防抖）推送 |

## 3. 数据格式（摘要）

导出 / 推送的 `graph` 结构（完整定义见 `src/schema/route-graph-v2.json` 与 [02-data-model.md](02-data-model.md)）：

```json
{
  "schema_version": 2,
  "meta": { "code": "designer", "version": "1.0", "graph_hash": null },
  "nodes": [
    { "node_key": "nd_1", "type": "step", "name": "工序A", "properties": {}, "steps": [], "ext": {} }
  ],
  "transitions": [
    { "edge_key": "ed_1", "from_node_key": "nd_1", "to_node_key": "nd_2",
      "mode": "straight", "properties": { "trigger": {"type":"manual"}, "priority": 100, "enabled": true, "weight": null }, "ext": {} }
  ],
  "layout": { "nodes": { "nd_1": {"x":80,"y":160} }, "viewport": {}, "groups": [] },
  "extensions": {}
}
```

## 4. 本地演示

最快的端到端体验见 `demo/README.md`：一条命令启动后，左侧设计器、右侧实时展示引擎解析结果。
