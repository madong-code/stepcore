/* nodes.js — 自定义节点注册：尺寸与样式规范对齐 template/admin/src/plugin/mes 设计器
 * NODE_SIZE：工序/分叉/汇合/返工 148×40 r8；开始/结束 84×40 r20 胶囊；判定 56×40 r4
 * 样式：白底 + 中性描边 #6b7785，选中 strokeWidth 2；返工节点底色 #fff7e8（同 MES 自定义节点）
 * 注意：尺寸必须通过 setAttributes() 设置（MES 同款写法）；在 initNodeData 中赋值 width/height
 * 会在部分 LF2 基类构造时序下触发 mobx invariant（可观察属性初始化阶段禁止写入）。
 */
window.SC = window.SC || {};
(function (SC) {
    const NODE_SIZE = {
        step:    { width: 148, height: 40, radius: 8 },
        pill:    { width: 84,  height: 40, radius: 20 },
        diamond: { width: 56,  height: 40, radius: 4 },
    };
    SC.NODE_SIZE = NODE_SIZE;
    SC.NODE_STROKE = '#6b7785';
    SC.NODE_CUSTOM_FILL = '#fff7e8';

    /** 连线规则基类混入（Model.ConnectRule，对齐 template/admin/src/plugin/mes 设计器）：
     * 1. 禁止节点自连 2. 同源→同目标仅允许一条连线 3. 结束节点禁止出边 4. 开始节点禁止入边。
     * 校验失败时 LF2 发出 connection:not-allowed（payload 含 message），由 app.js 统一 toast 提示。 */
    function withConnectRules(Base) {
        return class extends Base {
            getConnectedSourceRules() {
                const rules = super.getConnectedSourceRules ? super.getConnectedSourceRules() : [];
                rules.push({
                    message: '不允许节点自连',
                    validate: (source, target) => source.id !== target.id,
                });
                rules.push({
                    message: '两节点间已存在连线',
                    validate: (source, target) => !this.graphModel.edges.some(
                        (e) => e.sourceNodeId === source.id && e.targetNodeId === target.id,
                    ),
                });
                rules.push({
                    message: '结束节点不允许向下连线',
                    validate: () => (this.properties || {}).scType !== 'end',
                });
                return rules;
            }
            getConnectedTargetRules() {
                const rules = super.getConnectedTargetRules ? super.getConnectedTargetRules() : [];
                rules.push({
                    message: '开始节点不允许被连线',
                    validate: () => (this.properties || {}).scType !== 'start',
                });
                return rules;
            }
        };
    }

    /** 通用矩形系 Model 工厂（sc:rect / sc:pill）；Base 由调用方传入（UMD 全局基类） */
    function makeRectModel(Base, size, opts) {
        opts = opts || {};
        return class extends Base {
            setAttributes() {
                super.setAttributes();
                this.width = size.width;
                this.height = size.height;
                this.radius = size.radius;
            }
            getNodeStyle() {
                const style = super.getNodeStyle();
                const p = this.properties || {};
                style.fill = opts.fillFor ? opts.fillFor(p) : '#ffffff';
                style.stroke = SC.NODE_STROKE;
                style.strokeWidth = this.isSelected ? 2 : 1;
                return style;
            }
            getTextStyle() {
                const style = super.getTextStyle();
                style.color = '#1d2129';
                style.fontSize = 12;
                style.fontWeight = 500;
                return style;
            }
        };
    }

    /** 判定菱形 Model（56×40，同 MES decision）。注意：LF2 DiamondNodeModel 的 width/height 是
     * 由 points（x/y/rx/ry）派生的只读 getter，只能通过 rx/ry 控制尺寸，直接赋值 width 会抛错。 */
    function makeDiamondModel(Base) {
        return class extends Base {
            setAttributes() {
                super.setAttributes();
                this.rx = NODE_SIZE.diamond.width / 2;
                this.ry = NODE_SIZE.diamond.height / 2;
            }
            getNodeStyle() {
                const style = super.getNodeStyle();
                style.fill = '#ffffff';
                style.stroke = SC.NODE_STROKE;
                style.strokeWidth = this.isSelected ? 2 : 1;
                return style;
            }
            getTextStyle() {
                const style = super.getTextStyle();
                style.color = '#1d2129';
                style.fontSize = 12;
                style.fontWeight = 500;
                return style;
            }
        };
    }

    SC.registerNodes = (lf) => {
        // UMD 包将具名导出直接挂载到 window（window.RectNode 等），LogicFlow 静态属性不含这些基类
        const RectNode       = window.RectNode       || LogicFlow.RectNode;
        const RectNodeModel  = window.RectNodeModel  || LogicFlow.RectNodeModel;
        const DiamondNode    = window.DiamondNode    || LogicFlow.DiamondNode;
        const DiamondNodeModel = window.DiamondNodeModel || LogicFlow.DiamondNodeModel;
        const RectModel = makeRectModel(withConnectRules(RectNodeModel), NODE_SIZE.step, {
            fillFor: (p) => (p.scType === 'fa_rework' ? SC.NODE_CUSTOM_FILL : '#ffffff'),
        });
        const PillModel = makeRectModel(withConnectRules(RectNodeModel), NODE_SIZE.pill);
        lf.register({ type: 'sc:rect', view: RectNode, model: RectModel });
        lf.register({ type: 'sc:pill', view: RectNode, model: PillModel });
        // 判定：菱形视图 + DiamondNodeModel（rx/ry 控尺寸）；基类缺失时退用矩形规格
        if (DiamondNode && DiamondNodeModel) {
            lf.register({ type: 'sc:diamond', view: DiamondNode, model: makeDiamondModel(withConnectRules(DiamondNodeModel)) });
        } else {
            lf.register({ type: 'sc:diamond', view: RectNode, model: makeRectModel(withConnectRules(RectNodeModel), NODE_SIZE.diamond) });
        }
    };
})(window.SC);
