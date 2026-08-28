/**
 * Rich text editor (Jodit, bundled via Vite) for admin content fields.
 *
 * Attaches to #body and every .rich-text-editor element. Content is raw
 * admin-authored HTML stored in the textarea and submitted via normal form
 * POST; Jodit keeps the textarea value in sync while editing.
 */
import Alpine from '@alpinejs/csp';
import { Jodit } from 'jodit';
import 'jodit/es2021/jodit.min.css';

const EDITOR_TARGETS = '#body, .rich-text-editor';

function buildConfig(dark) {
    return {
        theme: dark ? 'dark' : 'default',
        height: 500,
        // The default source-view engine (ace) and HTML beautifier are fetched
        // from a CDN at runtime, which the strict CSP blocks; the plain
        // textarea engine is bundled.
        sourceEditor: 'area',
        beautifyHTML: false,
        // Stored content is raw HTML that must round-trip losslessly (the
        // previous editor passed everything through), so every cleaner that
        // rewrites or strips markup stays off. Admin-only input; trust model
        // unchanged.
        nl2brInPlainText: false,
        askBeforePasteHTML: false,
        askBeforePasteFromWord: false,
        cleanHTML: {
            removeEmptyElements: false,
            fillEmptyParagraph: false,
            replaceNBSP: false,
            replaceOldTags: false,
            denyTags: false,
            removeOnError: false,
            removeEventAttributes: false,
            safeJavaScriptLink: false,
            safeLinksTarget: false,
            sandboxIframesInContent: false,
            convertUnsafeEmbeds: false,
        },
        // wrapNodes rewrites stored markup by wrapping root text nodes in <p>.
        disablePlugins: ['wrapNodes'],
        uploader: { insertImageAsBase64URI: true },
        hidePoweredByJodit: true,
        toolbarAdaptive: false,
        buttons: [
            'source', '|',
            'undo', 'redo', '|',
            'paragraph', 'font', 'fontsize', '|',
            'bold', 'italic', 'underline', 'strikethrough', 'brush', '|',
            'ul', 'ol', 'outdent', 'indent', '|',
            'align', '|',
            'link', 'image', 'video', 'table', 'hr', 'symbols', '|',
            'eraser', 'find', '|',
            'fullsize',
        ],
    };
}

// Editor instances live in the factory closure, not on the component object:
// Alpine wraps component state in a reactive proxy, which a whole Jodit
// instance must not pass through.
Alpine.data('richTextEditor', () => {
    const states = [];
    let observer = null;

    /**
     * Final submit-time sync. Parsing into the editor DOM may normalize
     * quoting/entities, so an edit-free save restores the untouched original
     * value byte for byte instead of the normalized serialization.
     */
    const syncValue = state => {
        state.el.value = state.editor.value === state.initialNormalized
            ? state.initialRaw
            : state.editor.value;
    };

    const makeEditor = (el, dark) => {
        const state = { el, initialRaw: el.value, editor: null, initialNormalized: null };
        state.editor = Jodit.make(el, buildConfig(dark));
        state.initialNormalized = state.editor.value;
        if (el.form) {
            el.form.addEventListener('submit', () => syncValue(state));
        }
        return state;
    };

    /**
     * Destroy and re-create an editor (theme switch), preserving content.
     * When the content is still untouched, the normalized baseline is
     * recomputed so the byte-exact restore in syncValue keeps applying even
     * if the new instance serializes slightly differently.
     */
    const rebuildEditor = (state, dark) => {
        const value = state.editor.value;
        const untouched = value === state.initialNormalized;
        state.editor.destruct();
        state.editor = Jodit.make(state.el, buildConfig(dark));
        state.editor.value = value;
        if (untouched) {
            state.initialNormalized = state.editor.value;
        }
    };

    const watchDarkMode = () => {
        let wasDark = document.documentElement.classList.contains('dark');
        observer = new MutationObserver(() => {
            const dark = document.documentElement.classList.contains('dark');
            if (dark === wasDark) return;
            wasDark = dark;
            states.forEach(state => rebuildEditor(state, dark));
        });
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    };

    return {
        init() {
            const targets = document.querySelectorAll(EDITOR_TARGETS);
            if (!targets.length) return;
            const dark = document.documentElement.classList.contains('dark');
            targets.forEach(el => states.push(makeEditor(el, dark)));
            watchDarkMode();
        },

        destroy() {
            if (observer) observer.disconnect();
            states.forEach(state => state.editor.destruct());
            states.length = 0;
        },
    };
});
