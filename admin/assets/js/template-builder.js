/**
 * Clean Room CMS - Template Builder JS
 *
 * Block tree editor: click to add, click to select, configure, reorder, delete.
 */

document.addEventListener('DOMContentLoaded', () => {
    const tree = document.getElementById('block-tree');
    const jsonInput = document.getElementById('blocks_json');
    const configPanel = document.getElementById('block-config');
    if (!tree || !jsonInput) return;

    let blocks = [];
    try { blocks = JSON.parse(jsonInput.value || '[]'); } catch { blocks = []; }

    let selectedPath = null;

    renderTree();

    // Palette: click to add block
    document.querySelectorAll('.palette-block').forEach(btn => {
        btn.addEventListener('click', () => {
            const type = btn.dataset.type;
            const label = btn.dataset.label;
            const supportsChildren = btn.dataset.children === '1';
            let defaultConfig = {};
            try { defaultConfig = JSON.parse(btn.dataset.config || '{}'); } catch {}

            const newBlock = { type, config: { ...defaultConfig } };
            if (supportsChildren) newBlock.children = [];

            if (selectedPath !== null) {
                const parent = getBlockAtPath(blocks, selectedPath);
                if (parent && parent.children) {
                    parent.children.push(newBlock);
                } else {
                    blocks.push(newBlock);
                }
            } else {
                blocks.push(newBlock);
            }

            syncAndRender();
        });
    });

    function renderTree() {
        tree.innerHTML = '';
        if (blocks.length === 0) {
            tree.innerHTML = '<div class="tree-empty">No blocks yet. Click a block on the left to add it.</div>';
            return;
        }
        tree.appendChild(renderBlockList(blocks, []));
    }

    function renderBlockList(blockList, path) {
        const ul = document.createElement('div');
        ul.className = 'tree-list';

        blockList.forEach((block, i) => {
            const currentPath = [...path, i];
            const pathStr = currentPath.join('-');
            const bt = block.type || 'unknown';
            const label = getBlockLabel(block);

            const item = document.createElement('div');
            item.className = 'tree-item' + (selectedPath === pathStr ? ' selected' : '');
            item.dataset.path = pathStr;

            const header = document.createElement('div');
            header.className = 'tree-item-header';

            const nameSpan = document.createElement('span');
            nameSpan.className = 'tree-item-name';
            nameSpan.textContent = label;

            const typeSpan = document.createElement('span');
            typeSpan.className = 'tree-item-type';
            typeSpan.textContent = bt;

            const actions = document.createElement('span');
            actions.className = 'tree-item-actions';

            // Move up
            if (i > 0) {
                const upBtn = document.createElement('button');
                upBtn.type = 'button'; upBtn.textContent = '▲'; upBtn.title = 'Move up';
                upBtn.addEventListener('click', (e) => { e.stopPropagation(); moveBlock(blockList, i, -1); });
                actions.appendChild(upBtn);
            }
            // Move down
            if (i < blockList.length - 1) {
                const downBtn = document.createElement('button');
                downBtn.type = 'button'; downBtn.textContent = '▼'; downBtn.title = 'Move down';
                downBtn.addEventListener('click', (e) => { e.stopPropagation(); moveBlock(blockList, i, 1); });
                actions.appendChild(downBtn);
            }
            // Delete
            const delBtn = document.createElement('button');
            delBtn.type = 'button'; delBtn.textContent = '×'; delBtn.title = 'Remove';
            delBtn.className = 'tree-delete';
            delBtn.addEventListener('click', (e) => { e.stopPropagation(); blockList.splice(i, 1); selectedPath = null; syncAndRender(); renderConfig(null); });
            actions.appendChild(delBtn);

            header.appendChild(nameSpan);
            header.appendChild(typeSpan);
            header.appendChild(actions);

            header.addEventListener('click', () => {
                selectedPath = pathStr;
                renderTree();
                renderConfig(block);
            });

            item.appendChild(header);

            // Children
            if (block.children && block.children.length > 0) {
                item.appendChild(renderBlockList(block.children, currentPath));
            }

            ul.appendChild(item);
        });

        return ul;
    }

    function getBlockLabel(block) {
        const type = block.type || 'unknown';
        const labels = {
            'site-header': 'Header', 'site-footer': 'Footer', 'site-nav': 'Navigation',
            'post-title': 'Post Title', 'post-content': 'Post Content', 'post-excerpt': 'Excerpt',
            'post-meta': 'Post Meta', 'post-tags': 'Tags', 'post-loop': 'Post Loop',
            'container': 'Container', 'columns': 'Columns', 'column': 'Column',
            'section': 'Section', 'spacer': 'Spacer', 'html-wrapper': 'HTML Document',
            'search-form': 'Search', 'breadcrumb': 'Breadcrumb', 'pagination': 'Pagination',
            'recent-posts': 'Recent Posts', 'custom-html': 'Custom HTML',
            'conditional': 'Conditional', 'taxonomy-list': 'Taxonomy List',
            'post-thumbnail': 'Thumbnail', 'post-navigation': 'Navigation', 'post-card': 'Post Card',
        };
        return labels[type] || type.replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    }

    function moveBlock(list, index, direction) {
        const target = index + direction;
        if (target < 0 || target >= list.length) return;
        [list[index], list[target]] = [list[target], list[index]];
        syncAndRender();
    }

    function getBlockAtPath(blockList, pathStr) {
        const parts = pathStr.split('-').map(Number);
        let current = blockList;
        let block = null;
        for (const idx of parts) {
            if (!Array.isArray(current) || idx >= current.length) return null;
            block = current[idx];
            current = block.children || [];
        }
        return block;
    }

    function renderConfig(block) {
        if (!block || !configPanel) {
            configPanel.innerHTML = '<h3>Configuration</h3><p class="field-desc">Select a block to edit its settings.</p>';
            return;
        }

        const config = block.config || {};
        let html = '<h3>' + getBlockLabel(block) + '</h3><div class="config-fields">';

        // Render config fields based on current values
        for (const [key, value] of Object.entries(config)) {
            if (key.startsWith('_')) continue;
            const label = key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

            if (typeof value === 'boolean') {
                html += '<label class="checkbox-label"><input type="checkbox" data-key="' + key + '" ' + (value ? 'checked' : '') + ' class="config-input"> ' + label + '</label>';
            } else if (typeof value === 'number') {
                html += '<div class="form-group"><label>' + label + '</label><input type="number" data-key="' + key + '" value="' + value + '" class="input-full config-input"></div>';
            } else {
                html += '<div class="form-group"><label>' + label + '</label><input type="text" data-key="' + key + '" value="' + escAttr(String(value)) + '" class="input-full config-input"></div>';
            }
        }

        // Type-specific add fields
        if (Object.keys(config).length === 0) {
            html += '<p class="field-desc">This block has no configurable options.</p>';
        }

        html += '</div>';
        configPanel.innerHTML = html;

        // Bind config change handlers
        configPanel.querySelectorAll('.config-input').forEach(input => {
            input.addEventListener('change', () => {
                const key = input.dataset.key;
                if (input.type === 'checkbox') {
                    block.config[key] = input.checked;
                } else if (input.type === 'number') {
                    block.config[key] = Number(input.value);
                } else {
                    block.config[key] = input.value;
                }
                syncJSON();
            });
        });
    }

    function syncAndRender() {
        syncJSON();
        renderTree();
    }

    function syncJSON() {
        jsonInput.value = JSON.stringify(blocks);
    }

    function escAttr(s) {
        return s.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }
});
