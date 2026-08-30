/**
 * Tiny DOM helpers shared by the core and every feature module.
 *
 * These live here rather than in core.js for a specific reason: core.js has
 * top-level side effects (it registers listeners and boots the tables on the
 * page). The core is loaded from a versioned URL — core.js?v=abc123 — while a
 * module importing "./core.js" would ask for a *different* URL, so the browser
 * would evaluate the core a second time, giving the page two runtimes bound to
 * the same DOM and making every click happen twice.
 *
 * Nothing may import core.js. Import from here instead.
 */

export function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);

    for (const [key, value] of Object.entries(attrs)) {
        if (value === null || value === undefined || value === false) continue;

        if (key === 'class') {
            node.className = Array.isArray(value) ? value.filter(Boolean).join(' ') : value;
        } else if (key === 'text') {
            node.textContent = value;
        } else if (key === 'html') {
            node.innerHTML = value;
        } else if (key.startsWith('on') && typeof value === 'function') {
            node.addEventListener(key.slice(2).toLowerCase(), value);
        } else if (value === true) {
            node.setAttribute(key, '');
        } else {
            node.setAttribute(key, value);
        }
    }

    for (const child of [].concat(children)) {
        if (child === null || child === undefined || child === false) continue;
        node.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }

    return node;
}

export function debounce(fn, wait) {
    let timer = null;

    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), wait);
    };
}

export function get(object, path, fallback = null) {
    return path
        .split('.')
        .reduce((carry, key) => (carry && carry[key] !== undefined ? carry[key] : undefined), object) ?? fallback;
}

export function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}
