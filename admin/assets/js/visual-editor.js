/**
 * Clean Room CMS - Visual Content Editor
 *
 * Inline visual editor with block insertion, rich text formatting,
 * and Visual/Code toggle. Works directly in the DOM without iframes.
 */

document.addEventListener('DOMContentLoaded', () => {
    const editorContainer = document.getElementById('vanillabuilder-editor');
    if (!editorContainer) return;

    const textarea = document.getElementById('post_content');
    const cssInput = document.getElementById('post_css');
    const textareaGroup = textarea?.closest('.form-group');
    if (!textarea) return;

    const initialHtml = textarea.value || '';
    let currentMode = 'visual';

    // Build the editor UI
    buildToolbar(editorContainer);
    buildCanvas(editorContainer, initialHtml);
    buildBlockPalette(editorContainer);

    // Toggle buttons
    document.querySelectorAll('.editor-toggle .toggle-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const mode = btn.dataset.mode;
            if (mode === currentMode) return;

            document.querySelectorAll('.editor-toggle .toggle-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            if (mode === 'code') {
                syncCanvasToTextarea();
                editorContainer.style.display = 'none';
                if (textareaGroup) textareaGroup.classList.remove('editor-hidden');
                currentMode = 'code';
            } else {
                const canvas = editorContainer.querySelector('.ve-canvas');
                if (canvas) canvas.innerHTML = textarea.value || '<p>Start typing...</p>';
                if (textareaGroup) textareaGroup.classList.add('editor-hidden');
                editorContainer.style.display = '';
                currentMode = 'visual';
            }
        });
    });

    // Sync before form submit
    const form = textarea.closest('form');
    if (form) {
        form.addEventListener('submit', () => {
            if (currentMode === 'visual') syncCanvasToTextarea();
        });
    }

    function syncCanvasToTextarea() {
        const canvas = editorContainer.querySelector('.ve-canvas');
        if (canvas) {
            textarea.value = canvas.innerHTML;
            // Extract inline styles as CSS
            if (cssInput) cssInput.value = '';
        }
    }
});

function buildToolbar(container) {
    const toolbar = document.createElement('div');
    toolbar.className = 've-toolbar';

    const actions = [
        { cmd: 'bold', icon: 'B', title: 'Bold' },
        { cmd: 'italic', icon: 'I', title: 'Italic' },
        { cmd: 'underline', icon: 'U', title: 'Underline' },
        { cmd: 'strikeThrough', icon: 'S', title: 'Strikethrough' },
        { type: 'sep' },
        { cmd: 'formatBlock', val: 'h1', icon: 'H1', title: 'Heading 1' },
        { cmd: 'formatBlock', val: 'h2', icon: 'H2', title: 'Heading 2' },
        { cmd: 'formatBlock', val: 'h3', icon: 'H3', title: 'Heading 3' },
        { cmd: 'formatBlock', val: 'p', icon: 'P', title: 'Paragraph' },
        { type: 'sep' },
        { cmd: 'insertUnorderedList', icon: '&#8226;', title: 'Bullet List' },
        { cmd: 'insertOrderedList', icon: '1.', title: 'Numbered List' },
        { cmd: 'formatBlock', val: 'blockquote', icon: '&#8220;', title: 'Quote' },
        { cmd: 'formatBlock', val: 'pre', icon: '&lt;/&gt;', title: 'Code Block' },
        { type: 'sep' },
        { cmd: 'createLink', icon: '&#128279;', title: 'Insert Link', prompt: true },
        { cmd: 'unlink', icon: '&#10060;', title: 'Remove Link' },
        { cmd: 'insertHorizontalRule', icon: '&#8213;', title: 'Horizontal Rule' },
        { type: 'sep' },
        { cmd: 'undo', icon: '&#8617;', title: 'Undo' },
        { cmd: 'redo', icon: '&#8618;', title: 'Redo' },
    ];

    actions.forEach(a => {
        if (a.type === 'sep') {
            const sep = document.createElement('span');
            sep.className = 've-sep';
            toolbar.appendChild(sep);
            return;
        }

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 've-btn';
        btn.innerHTML = a.icon;
        btn.title = a.title;

        btn.addEventListener('mousedown', (e) => {
            e.preventDefault(); // Don't blur the canvas
            const canvas = container.querySelector('.ve-canvas');
            if (!canvas) return;

            if (a.prompt) {
                const url = prompt('Enter URL:', 'https://');
                if (url) document.execCommand(a.cmd, false, url);
            } else {
                document.execCommand(a.cmd, false, a.val || null);
            }
        });

        toolbar.appendChild(btn);
    });

    container.appendChild(toolbar);
}

function buildCanvas(container, initialHtml) {
    const canvas = document.createElement('div');
    canvas.className = 've-canvas';
    canvas.contentEditable = true;
    canvas.innerHTML = initialHtml || '<p>Start typing or click a block above to add content...</p>';

    // Clean paste
    canvas.addEventListener('paste', (e) => {
        e.preventDefault();
        const text = e.clipboardData.getData('text/html') || e.clipboardData.getData('text/plain');
        document.execCommand('insertHTML', false, text);
    });

    container.appendChild(canvas);
}

function buildBlockPalette(container) {
    const palette = document.createElement('div');
    palette.className = 've-blocks';

    const blocks = [
        { label: 'Text', html: '<p>Type your text here</p>' },
        { label: 'Heading', html: '<h2>Heading</h2>' },
        { label: 'Image', html: '<figure><img src="" alt="Image" style="max-width:100%;display:block"><figcaption>Caption</figcaption></figure>' },
        { label: 'List', html: '<ul><li>Item 1</li><li>Item 2</li><li>Item 3</li></ul>' },
        { label: 'Quote', html: '<blockquote><p>A meaningful quote</p><cite>— Author</cite></blockquote>' },
        { label: 'Code', html: '<pre><code>// Your code here</code></pre>' },
        { label: 'Divider', html: '<hr>' },
        { label: '2 Cols', html: '<div style="display:flex;gap:20px"><div style="flex:1"><p>Column 1</p></div><div style="flex:1"><p>Column 2</p></div></div>' },
        { label: '3 Cols', html: '<div style="display:flex;gap:20px"><div style="flex:1"><p>Col 1</p></div><div style="flex:1"><p>Col 2</p></div><div style="flex:1"><p>Col 3</p></div></div>' },
        { label: 'Section', html: '<section style="padding:40px 20px;background:#f5f5f5"><h2>Section Title</h2><p>Section content goes here.</p></section>' },
        { label: 'Button', html: '<a href="#" style="display:inline-block;padding:10px 24px;background:#2271b1;color:#fff;text-decoration:none;border-radius:4px">Click me</a>' },
        { label: 'Video', html: '<div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden"><iframe src="" style="position:absolute;top:0;left:0;width:100%;height:100%;border:none" allowfullscreen></iframe></div>' },
    ];

    blocks.forEach(b => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 've-block-btn';
        btn.textContent = b.label;
        btn.addEventListener('click', () => {
            const canvas = container.querySelector('.ve-canvas');
            if (!canvas) return;

            // Remove placeholder text if present
            if (canvas.innerHTML.includes('Start typing or click')) {
                canvas.innerHTML = '';
            }

            // Insert block
            canvas.focus();
            const temp = document.createElement('div');
            temp.innerHTML = b.html;
            while (temp.firstChild) {
                canvas.appendChild(temp.firstChild);
            }

            // Scroll to bottom
            canvas.scrollTop = canvas.scrollHeight;
        });
        palette.appendChild(btn);
    });

    // Insert palette at the top of the container
    container.insertBefore(palette, container.firstChild);
}
