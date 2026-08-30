/**
 * Shared UI primitives for the feature modules: an accessible dialog and an
 * anchored menu. Kept in one place so every panel traps focus, closes on
 * Escape, and restores focus the same way.
 */

import { el } from './dom.js';

export { el };

export function dialog(table, { title, body, footer = null, width = null, onClose = null }) {
    // A table shows at most one dialog. Without this, a second click — or a
    // click that lands while the feature module is still being imported —
    // stacks an identical dialog on top of the first.
    closeDialogs(table);

    const previous = document.activeElement;

    const close = () => {
        document.removeEventListener('keydown', onKeydown, true);
        overlay.remove();
        table._dialog = null;
        onClose?.();
        previous?.focus?.();
    };

    const onKeydown = (event) => {
        if (event.key === 'Escape') {
            event.stopPropagation();
            close();

            return;
        }

        if (event.key !== 'Tab') return;

        const focusable = [...box.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        )];

        if (!focusable.length) return;

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    };

    const heading = el('h2', { class: 'dt-modal-title', id: `dt-modal-${Math.random().toString(36).slice(2, 8)}`, text: title });

    /*
     * Modal or offcanvas is purely presentational: same markup, same focus
     * trap, same Escape handling, same contract for every caller. Only the
     * classes and the sizing differ, so a panel never has to know which one it
     * is being shown in.
     */
    const panels = table.boot.panels || { mode: 'modal', side: 'right', width: '30rem' };
    const drawer = panels.mode === 'offcanvas';

    const box = el('div', {
        class: [table.classes.modalBox, drawer ? 'dt-offcanvas-box' : null],
        role: 'dialog',
        'aria-modal': 'true',
        'aria-labelledby': heading.id,
        // A drawer takes its width from the config; a modal keeps the caller's
        // preferred max-width, since panels differ a lot in how much they need.
        style: drawer ? `width:${panels.width};max-width:100%` : (width ? `max-width:${width}` : null),
    }, [
        el('div', { class: 'dt-modal-head' }, [
            heading,
            el('button', {
                type: 'button',
                class: 'dt-modal-close',
                'aria-label': table.t('close'),
                text: '×',
                onclick: close,
            }),
        ]),
        el('div', { class: 'dt-modal-body' }, [body]),
        footer ? el('div', { class: 'dt-modal-foot' }, [footer]) : null,
    ]);

    const overlay = el('div', {
        class: [table.classes.modal, drawer ? `dt-offcanvas dt-offcanvas-${panels.side}` : null],
        dir: table.boot.direction,
        onclick: (event) => {
            if (event.target === overlay) close();
        },
    }, [box]);

    table.root.append(overlay);
    document.addEventListener('keydown', onKeydown, true);

    (box.querySelector('input, select, textarea, button:not(.dt-modal-close)') || box).focus?.();

    const instance = { overlay, box, close };
    table._dialog = instance;

    return instance;
}

/**
 * Close any dialog this table currently owns.
 *
 * The sweep is deliberately DOM-based rather than only bookkeeping: if a second
 * runtime ever ends up on the page, its `table` object would have its own
 * `_dialog` reference but share this root, and only clearing the DOM catches
 * that. One table shows one dialog, whatever the object graph looks like.
 */
export function closeDialogs(table) {
    table._dialog?.close();

    table.root.querySelectorAll('.dt-modal').forEach((node) => node.remove());
    table._dialog = null;
}

/**
 * An anchored menu.
 *
 * Items may be "-" for a separator, `{ heading }`, or an action with
 * `{ label, icon, badge, check, active, danger, onSelect }`. Pass
 * `{ search: 'placeholder' }` to get a filter box at the top, the way the
 * Dynamics view picker works when a user has more views than fit at a glance.
 */
export function menu(table, trigger, items, options = {}) {
    // Same single-instance rule as dialogs, across every table on the page.
    document.querySelectorAll('.dt-menu-open').forEach((node) => node.remove());

    const close = () => {
        node.remove();
        document.removeEventListener('click', onDocumentClick, true);
        document.removeEventListener('keydown', onKeydown, true);
        trigger?.setAttribute?.('aria-expanded', 'false');
    };

    const onDocumentClick = (event) => {
        if (!node.contains(event.target) && event.target !== trigger && !trigger?.contains?.(event.target)) close();
    };

    const onKeydown = (event) => {
        if (event.key === 'Escape') {
            close();
            trigger?.focus?.();

            return;
        }

        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;

        const focusable = [...node.querySelectorAll('button:not([disabled]), input')];
        const at = focusable.indexOf(document.activeElement);

        if (at === -1) return;

        event.preventDefault();
        focusable[(at + (event.key === 'ArrowDown' ? 1 : -1) + focusable.length) % focusable.length]?.focus();
    };

    const renderItem = (item) => {
        if (item === '-') return el('div', { class: 'dt-menu-separator', role: 'separator' });

        if (item.heading) return el('div', { class: 'dt-menu-heading', text: item.heading });

        return el('button', {
            type: 'button',
            class: [
                table.classes.menuItem,
                'dt-menu-row',
                item.active ? 'dt-menu-item-active' : null,
                item.danger ? 'dt-menu-item-danger' : null,
            ],
            role: 'menuitem',
            disabled: item.disabled,
            'data-search': (item.label || '').toLowerCase(),
            onclick: () => {
                if (item.keepOpen !== true) close();
                item.onSelect?.();
            },
        }, [
            el('span', { class: 'dt-menu-check', 'aria-hidden': 'true', text: item.active ? '✓' : '' }),
            item.icon ? el('span', { class: 'dt-menu-icon', 'aria-hidden': 'true', text: item.icon }) : null,
            el('span', { class: 'dt-menu-label', text: item.label }),
            item.badge ? el('span', { class: 'dt-menu-badge', text: item.badge }) : null,
        ]);
    };

    // Drop separators that ended up leading, trailing or doubled. Whether a
    // group has any items depends on which features are on, so building the
    // list conditionally would otherwise leave a rule floating under nothing.
    const cleaned = items.filter((item, index, all) => {
        if (item !== '-') return true;

        const previous = all.slice(0, index).findLast((candidate) => candidate !== '-');
        const next = all.slice(index + 1).find((candidate) => candidate !== '-');

        return previous !== undefined && next !== undefined && all[index - 1] !== '-';
    });

    const list = el('div', { class: 'dt-menu-list' }, cleaned.map(renderItem));

    const search = options.search
        ? el('div', { class: 'dt-menu-search' }, [
            el('input', {
                type: 'search',
                class: table.classes.input,
                placeholder: options.search,
                'aria-label': options.search,
                oninput: (event) => {
                    const term = event.target.value.trim().toLowerCase();

                    for (const row of list.querySelectorAll('[data-search]')) {
                        row.hidden = term !== '' && !row.dataset.search.includes(term);
                    }

                    // Hide a heading whose whole group filtered out.
                    for (const heading of list.querySelectorAll('.dt-menu-heading')) {
                        let sibling = heading.nextElementSibling;
                        let visible = false;

                        while (sibling && !sibling.classList.contains('dt-menu-heading')) {
                            if (sibling.dataset.search !== undefined && !sibling.hidden) visible = true;
                            sibling = sibling.nextElementSibling;
                        }

                        heading.hidden = !visible;
                    }
                },
            }),
        ])
        : null;

    const node = el('div', {
        class: [table.classes.menu, 'dt-menu-open'],
        role: 'menu',
        dir: table.boot.direction,
    }, [search, list]);

    trigger?.setAttribute?.('aria-expanded', 'true');
    table.root.append(node);

    const bounds = trigger.getBoundingClientRect();
    const host = table.root.getBoundingClientRect();

    node.style.position = 'absolute';
    node.style.top = `${bounds.bottom - host.top + 4}px`;

    if (table.boot.direction === 'rtl') {
        node.style.right = `${host.right - bounds.right}px`;
    } else {
        node.style.left = `${bounds.left - host.left}px`;
    }

    setTimeout(() => {
        document.addEventListener('click', onDocumentClick, true);
        document.addEventListener('keydown', onKeydown, true);
    }, 0);

    (node.querySelector('input') || node.querySelector('button'))?.focus();

    return { node, close };
}

export function field(table, label, control) {
    return el('label', { class: 'dt-field' }, [
        el('span', { class: 'dt-field-label', text: label }),
        control,
    ]);
}

export function select(table, options, value, onChange, attrs = {}) {
    const node = el('select', { class: table.classes.select, ...attrs, onchange: (event) => onChange(event.target.value) });

    for (const option of options) {
        node.append(el('option', {
            value: option.value,
            text: option.label,
            selected: String(option.value) === String(value),
        }));
    }

    return node;
}
