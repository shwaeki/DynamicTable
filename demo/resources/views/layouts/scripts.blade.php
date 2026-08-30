<script>
    // Sidebar search — filters the example list without a round trip.
    (() => {
        const search = document.querySelector('[data-example-search]');
        const items = [...document.querySelectorAll('[data-nav-item]')];
        const groups = [...document.querySelectorAll('[data-nav-group]')];
        const empty = document.querySelector('[data-nav-empty]');

        search?.addEventListener('input', () => {
            const term = search.value.trim().toLowerCase();
            let visible = 0;

            for (const item of items) {
                const match = !term || item.dataset.search.includes(term) || item.textContent.toLowerCase().includes(term);
                item.hidden = !match;
                if (match) visible++;
            }

            for (const group of groups) {
                group.hidden = ![...group.querySelectorAll('[data-nav-item]')].some((item) => !item.hidden);
            }

            if (empty) empty.hidden = visible > 0;
        });

        // Focus the search with "/" like every good docs site.
        document.addEventListener('keydown', (event) => {
            if (event.key === '/' && !/^(input|textarea|select)$/i.test(event.target.tagName)) {
                event.preventDefault();
                search?.focus();
            }
        });
    })();

    // Mobile navigation drawer.
    (() => {
        const toggle = document.querySelector('[data-nav-toggle]');
        const sidebar = document.querySelector('[data-sidebar]');

        toggle?.addEventListener('click', () => {
            const open = sidebar.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', String(open));
        });
    })();

    // Source tabs + copy.
    (() => {
        const tabs = [...document.querySelectorAll('[data-source-tab]')];
        const panes = [...document.querySelectorAll('[data-source-pane]')];

        for (const tab of tabs) {
            tab.addEventListener('click', () => {
                const target = tab.dataset.sourceTab;

                tabs.forEach((candidate) => {
                    const active = candidate === tab;
                    candidate.classList.toggle('is-active', active);
                    candidate.setAttribute('aria-selected', String(active));
                });

                panes.forEach((pane) => { pane.hidden = pane.dataset.sourcePane !== target; });
            });
        }

        document.querySelector('[data-copy]')?.addEventListener('click', async (event) => {
            const pane = document.querySelector('[data-source-pane]:not([hidden]) code');
            if (!pane) return;

            const button = event.currentTarget;

            await navigator.clipboard.writeText(pane.textContent);
            button.textContent = button.dataset.copiedLabel;
            setTimeout(() => { button.textContent = button.dataset.copyLabel; }, 1500);
        });
    })();

    // Colour scheme switch: light / dark / follow the OS.
    (() => {
        const root = document.documentElement;
        const buttons = [...document.querySelectorAll('[data-scheme-choice]')];

        const apply = (choice) => {
            const resolved = choice === 'auto'
                ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : choice;

            if (choice === 'auto') root.removeAttribute('data-scheme');
            else root.setAttribute('data-scheme', choice);

            // Bootstrap 5.3 recolours its own components from this attribute.
            root.setAttribute('data-bs-theme', resolved);

            // Force every table to the same scheme. Passing null (auto) lets
            // each table fall back to prefers-color-scheme on its own.
            for (const table of document.querySelectorAll('[data-dynamic-table]')) {
                if (choice === 'auto') table.removeAttribute('data-dt-scheme');
                else table.setAttribute('data-dt-scheme', choice);
            }

            root.dataset.schemeChoice = choice;
            localStorage.setItem('dt-demo-scheme', choice);

            for (const button of buttons) {
                const active = button.dataset.schemeChoice === choice;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', String(active));
            }
        };

        for (const button of buttons) {
            button.addEventListener('click', () => apply(button.dataset.schemeChoice));
        }

        apply(localStorage.getItem('dt-demo-scheme') || 'auto');

        // Keep "auto" honest when the OS setting changes while the page is open.
        matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if ((localStorage.getItem('dt-demo-scheme') || 'auto') === 'auto') apply('auto');
        });
    })();

    hljs.highlightAll();
</script>
