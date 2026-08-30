/**
 * Shared UI primitives for the feature modules: an accessible dialog and an
 * anchored menu. Kept in one place so every panel traps focus, closes on
 * Escape, and restores focus the same way.
 */

import { el, iconContent } from './dom.js';

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
 * The scheme the table is *currently* showing, not the one it booted with.
 *
 * A layer on <body> inherits nothing from the table, so it has to be told. The
 * boot payload is not enough: an application that forces a scheme does it by
 * setting data-dt-scheme on the element — the demo's light/dark switch is
 * exactly this — and that happens long after boot. Read from the DOM and a
 * menu matches the table it belongs to; read from the payload and it follows
 * the operating system instead, which is how you get a dark menu over a light
 * table.
 */
function schemeOf(table) {
    const owner = table.root.closest('[data-dt-scheme]');

    return owner?.getAttribute('data-dt-scheme') || table.boot.scheme || null;
}

/** Remove any anchored layer currently on the page, portal and all. */
function closeLayers() {
    document.querySelectorAll('.dt-portal, .dt-menu-open').forEach((node) => node.remove());
}

/**
 * Put an anchored layer on the page and place it under its trigger.
 *
 * It goes on <body>, not inside the table.
 *
 * A menu positioned inside the table is absolutely positioned against the
 * table's box, which breaks the moment anything between them clips or scrolls
 * — a card with overflow:hidden, the table's own scroll container, a preview
 * pane. Fixed coordinates against the viewport are the same everywhere.
 *
 * The layer keeps the .dt class (and the table's direction and scheme) so the
 * colour tokens and the `.dt .dt-menu` rules still apply, and uses
 * display:contents so it adds no box of its own.
 */
function place(table, trigger, node) {
    const layer = el('div', {
        class: 'dt dt-portal',
        dir: table.boot.direction,
        'data-dt-scheme': schemeOf(table),
    }, [node]);

    document.body.append(layer);

    position(table, trigger, node);

    /*
     * Measure again on the next frame.
     *
     * The first measurement happens the instant the layer is in the document,
     * which is right for the common case but can be a frame early: a web font
     * arriving, or a scrollbar appearing inside a long list, changes the size
     * after it was read. Correcting once is cheap and invisible; leaving it is
     * how a menu ends up in the right place only the *second* time it opens.
     */
    requestAnimationFrame(() => {
        if (node.isConnected) position(table, trigger, node);
    });

    /*
     * Stay with the trigger.
     *
     * The layer is fixed to the viewport while the trigger sits in a page — and
     * often in the table's own scroll container — so anything that scrolls
     * moves one and not the other. The listener removes itself once the layer
     * is gone, which needs no bookkeeping at the call sites.
     */
    const follow = () => {
        if (! node.isConnected) {
            window.removeEventListener('scroll', follow, true);
            window.removeEventListener('resize', follow);

            return;
        }

        position(table, trigger, node);
    };

    window.addEventListener('scroll', follow, true);
    window.addEventListener('resize', follow);

    return layer;
}

/** Put one layer under its trigger, inside the viewport. */
function position(table, trigger, node) {
    const bounds = trigger.getBoundingClientRect();
    const size = node.getBoundingClientRect();
    const margin = 8;

    node.style.position = 'fixed';

    // Flip above the trigger when there is no room below it — the last row of a
    // long table is near the bottom of the screen, which is exactly where a
    // header menu would otherwise open off-screen.
    const below = window.innerHeight - bounds.bottom;
    const above = bounds.top;
    const flip = below < size.height + margin && above > below;

    node.style.top = flip
        ? `${Math.max(margin, bounds.top - size.height - 4)}px`
        : `${bounds.bottom + 4}px`;

    node.style.maxHeight = `${Math.max(160, (flip ? above : below) - margin * 2)}px`;

    // Aligned to the trigger's leading edge, then pulled back inside the
    // viewport if that would push it off.
    const start = table.boot.direction === 'rtl'
        ? Math.min(window.innerWidth - margin, bounds.right) - size.width
        : bounds.left;

    node.style.left = `${Math.max(margin, Math.min(start, window.innerWidth - size.width - margin))}px`;
    node.style.right = 'auto';
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
    closeLayers();

    let layer = null;

    const close = () => {
        (layer ?? node).remove();
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
            item.icon ? el('span', { class: 'dt-menu-icon', 'aria-hidden': 'true', ...iconContent(item.icon) }) : null,
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
    layer = place(table, trigger, node);

    setTimeout(() => {
        document.addEventListener('click', onDocumentClick, true);
        document.addEventListener('keydown', onKeydown, true);
    }, 0);

    (node.querySelector('input') || node.querySelector('button'))?.focus({ preventScroll: true });

    return { node, close };
}

/**
 * A small card anchored to what opened it, rather than a modal.
 *
 * The header menu's "Filter by this column" belongs here and not in a dialog:
 * it is one control about one column, opened from that column, and a modal
 * would take over the screen to ask a question the size of a dropdown.
 *
 * It shares the menu's anchoring, single-instance rule and dismissal — click
 * outside or press Escape — so a popover and a menu can never be open at once.
 */
export function popover(table, trigger, { title = null, body = null, footer = null, width = '17rem' } = {}) {
    closeLayers();

    let layer = null;

    const close = () => {
        (layer ?? node).remove();
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
        }
    };

    const node = el('div', {
        class: [table.classes.menu, 'dt-menu-open', 'dt-popover'],
        dir: table.boot.direction,
        style: `width:${width}`,
    }, [
        title
            ? el('div', { class: 'dt-popover-head' }, [
                el('span', { class: 'dt-popover-title', text: title }),
                el('button', {
                    type: 'button',
                    class: 'dt-popover-close',
                    'aria-label': table.t('close'),
                    text: '✕',
                    onclick: () => close(),
                }),
            ])
            : null,
        el('div', { class: 'dt-popover-body' }, [body]),
        footer ? el('div', { class: 'dt-popover-foot' }, [footer]) : null,
    ]);

    trigger?.setAttribute?.('aria-expanded', 'true');
    layer = place(table, trigger, node);

    setTimeout(() => {
        document.addEventListener('click', onDocumentClick, true);
        document.addEventListener('keydown', onKeydown, true);
    }, 0);

    (node.querySelector('select') || node.querySelector('input') || node.querySelector('button'))?.focus({ preventScroll: true });

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
