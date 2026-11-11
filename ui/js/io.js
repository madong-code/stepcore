/* io.js — postMessage 通信桥（iframe ↔ 父页）+ 自动推送 */
window.SC = window.SC || {};
(function (SC) {
    SC.initIO = () => {
        SC.targetOrigin = window.DESIGNER_TARGET_ORIGIN || '*';

        SC.postSave = () => {
            const graph = SC.exportV2(SC.lf);
            parent.postMessage({ type: 'flow.save', payload: { graph } }, SC.targetOrigin);
        };
        SC.postReady = () => parent.postMessage({ type: 'flow.ready' }, SC.targetOrigin);

        window.addEventListener('message', (e) => {
            const data = e.data;
            if (!data || typeof data !== 'object') return;
            if (data.type === 'flow.load' && data.payload && data.payload.graph) {
                SC.loadV2(data.payload.graph);
            }
        });
    };
})(window.SC);
