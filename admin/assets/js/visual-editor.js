/**
 * Clean Room CMS - Visual Editor Integration
 *
 * Bridges VanillaBuilder with the CMS post editor.
 * Handles: initialization, Visual/Code toggle, form submit sync.
 */

document.addEventListener('DOMContentLoaded', () => {
    const editorContainer = document.getElementById('vanillabuilder-editor');
    if (!editorContainer) return;

    const textarea = document.getElementById('post_content');
    const cssInput = document.getElementById('post_css');
    const textareaGroup = textarea?.closest('.form-group');
    if (!textarea) return;

    // Read initial content
    const initialHtml = textarea.value || '';
    const initialCss = editorContainer.dataset.postCss || '';

    let editor = null;
    let currentMode = 'visual';

    // Suppress non-critical VanillaBuilder init warnings (Devices/Pages event race)
    const origConsoleError = console.error;
    console.error = (...args) => {
        const msg = String(args[0] || '');
        if (msg.includes('Error initializing module')) return; // Known init-order race
        origConsoleError.apply(console, args);
    };

    // Initialize VanillaBuilder
    try {
        editor = vanillabuilder.init({
            container: '#vanillabuilder-editor',
            components: initialHtml,
            style: initialCss,
            height: '600px',
            width: '100%',
            storageManager: { type: false },
            showToolbar: true,
            multipleSelection: true,
        });
    } catch (e) {
        console.error = origConsoleError;
        console.error('VanillaBuilder init failed:', e);
        // Fallback: show textarea
        editorContainer.style.display = 'none';
        if (textareaGroup) textareaGroup.classList.remove('editor-hidden');
        const toggle = document.querySelector('.editor-toggle');
        if (toggle) toggle.style.display = 'none';
        return;
    }

    // Restore console.error
    console.error = origConsoleError;

    // Toggle buttons
    const toggleBtns = document.querySelectorAll('.editor-toggle .toggle-btn');

    toggleBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const mode = btn.dataset.mode;
            if (mode === currentMode) return;

            // Update active button
            toggleBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            if (mode === 'code') {
                // Visual → Code: sync editor content to textarea
                syncEditorToFields();
                editorContainer.style.display = 'none';
                if (textareaGroup) textareaGroup.classList.remove('editor-hidden');
                currentMode = 'code';
            } else {
                // Code → Visual: load textarea content into editor
                try {
                    editor.setComponents(textarea.value || '');
                    if (cssInput?.value) {
                        editor.setStyle(cssInput.value);
                    }
                } catch (e) {
                    console.error('Failed to load content into editor:', e);
                }
                if (textareaGroup) textareaGroup.classList.add('editor-hidden');
                editorContainer.style.display = '';
                currentMode = 'visual';
            }
        });
    });

    // Sync editor content to hidden fields
    function syncEditorToFields() {
        if (!editor) return;
        try {
            textarea.value = editor.getHtml() || '';
            if (cssInput) {
                cssInput.value = editor.getCss() || '';
            }
        } catch (e) {
            console.error('Failed to sync editor content:', e);
        }
    }

    // Sync before form submit
    const form = textarea.closest('form');
    if (form) {
        form.addEventListener('submit', () => {
            if (currentMode === 'visual') {
                syncEditorToFields();
            }
        });
    }
});
