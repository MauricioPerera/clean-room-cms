/**
 * Clean Room CMS - Admin JavaScript
 *
 * Handles:
 * 1. Conditional logic: show/hide fields based on other field values
 * 2. Repeater fields: add/remove rows dynamically
 * 3. Collapsible field groups
 */

document.addEventListener('DOMContentLoaded', () => {
    initConditionalLogic();
    initRepeaters();
    initCollapsibleGroups();
});

// =============================================
// Conditional Logic
// =============================================

function initConditionalLogic() {
    const dataEl = document.querySelector('script[data-cr-conditions]');
    if (!dataEl) return;

    let conditions;
    try { conditions = JSON.parse(dataEl.textContent); } catch { return; }

    const fieldWrappers = document.querySelectorAll('.field-wrapper[data-conditions]');

    function evaluateAll() {
        fieldWrappers.forEach(wrapper => {
            let cond;
            try { cond = JSON.parse(wrapper.dataset.conditions); } catch { return; }

            const rules = cond.rules || [];
            const relation = (cond.relation || 'and').toLowerCase();
            const results = rules.map(rule => evaluateRule(rule));

            const visible = relation === 'or'
                ? results.some(r => r)
                : results.every(r => r);

            wrapper.style.display = visible ? '' : 'none';
            // Disable hidden inputs so they don't submit
            wrapper.querySelectorAll('input, select, textarea').forEach(el => {
                el.disabled = !visible;
            });
        });
    }

    function evaluateRule(rule) {
        const fieldEl = getFieldInput(rule.field);
        if (!fieldEl) return true;

        let actual = '';
        if (fieldEl.type === 'checkbox') {
            actual = fieldEl.checked ? '1' : '0';
        } else if (fieldEl.type === 'radio') {
            const checked = document.querySelector(`[name="${fieldEl.name}"]:checked`);
            actual = checked ? checked.value : '';
        } else {
            actual = fieldEl.value;
        }

        const expected = rule.value || '';
        const op = rule.operator || '==';

        switch (op) {
            case '==': return actual === expected;
            case '!=': return actual !== expected;
            case '>': return parseFloat(actual) > parseFloat(expected);
            case '<': return parseFloat(actual) < parseFloat(expected);
            case '>=': return parseFloat(actual) >= parseFloat(expected);
            case '<=': return parseFloat(actual) <= parseFloat(expected);
            case 'contains': return actual.includes(expected);
            case 'empty': return !actual;
            case 'not_empty': return !!actual;
            default: return true;
        }
    }

    function getFieldInput(fieldName) {
        return document.querySelector(
            `[name="meta_${fieldName}"], [name="meta_${fieldName}[]"]`
        );
    }

    // Listen to all form inputs for real-time evaluation
    document.querySelectorAll('.meta-fields-section input, .meta-fields-section select, .meta-fields-section textarea').forEach(el => {
        el.addEventListener('change', evaluateAll);
        el.addEventListener('input', evaluateAll);
    });

    // Evaluate on page load
    evaluateAll();
}

// =============================================
// Repeater Fields
// =============================================

function initRepeaters() {
    // Add row buttons
    document.querySelectorAll('.repeater-add').forEach(btn => {
        btn.addEventListener('click', () => {
            const repeaterName = btn.dataset.repeater;
            const container = document.getElementById('repeater_' + repeaterName);
            const template = document.getElementById('tmpl_' + repeaterName);
            const maxRows = parseInt(btn.closest('.repeater-field')?.dataset.maxRows || '50');

            if (!container || !template) return;

            const currentRows = container.querySelectorAll('.repeater-row').length;
            if (currentRows >= maxRows) {
                alert('Maximum rows reached (' + maxRows + ')');
                return;
            }

            const newIndex = currentRows;
            const html = template.innerHTML
                .replace(/__INDEX__/g, newIndex)
                .replace(/\bname="([^"]*)\[__INDEX__\]/g, `name="$1[${newIndex}]`);

            const div = document.createElement('div');
            div.innerHTML = html;
            const row = div.firstElementChild;

            // Update row number
            const numEl = row.querySelector('.repeater-row-number');
            if (numEl) numEl.textContent = newIndex + 1;

            container.appendChild(row);
            attachRemoveHandler(row);
        });
    });

    // Attach remove handlers to existing rows
    document.querySelectorAll('.repeater-row').forEach(attachRemoveHandler);
}

function attachRemoveHandler(row) {
    const removeBtn = row.querySelector('.repeater-remove');
    if (!removeBtn) return;

    removeBtn.addEventListener('click', () => {
        row.remove();
        // Renumber remaining rows
        const container = row.closest('.repeater-rows');
        if (container) {
            container.querySelectorAll('.repeater-row').forEach((r, i) => {
                const num = r.querySelector('.repeater-row-number');
                if (num) num.textContent = i + 1;
            });
        }
    });
}

// =============================================
// Collapsible Groups
// =============================================

function initCollapsibleGroups() {
    document.querySelectorAll('.meta-group-title.collapsible').forEach(title => {
        title.style.cursor = 'pointer';
        title.addEventListener('click', () => {
            const fields = title.nextElementSibling?.classList.contains('group-desc')
                ? title.nextElementSibling.nextElementSibling
                : title.nextElementSibling;

            if (fields && fields.classList.contains('group-fields')) {
                const isHidden = fields.style.display === 'none';
                fields.style.display = isHidden ? '' : 'none';
                title.classList.toggle('collapsed', !isHidden);
            }
        });
    });
}
