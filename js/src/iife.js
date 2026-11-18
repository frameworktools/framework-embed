// Script-tag build: window.FrameworkEmbed, the <framework-artifact> element, and charts drawn on load.
// Set window.FrameworkEmbedConfig = {hosts, vegaScripts, vegaOptions} before this script to configure it.
import * as FrameworkEmbed from './index.js';

if (typeof window !== 'undefined') {
    window.FrameworkEmbed = FrameworkEmbed;
    if (window.FrameworkEmbedConfig) FrameworkEmbed.configure(window.FrameworkEmbedConfig);
    FrameworkEmbed.defineElement();
    FrameworkEmbed.expandDetailsForPrint();
    const start = () => { FrameworkEmbed.enhanceCharts(document); FrameworkEmbed.enhanceTips(document); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
}
