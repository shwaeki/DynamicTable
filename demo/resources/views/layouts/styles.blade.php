<style>
    /* The demo chrome. The tables themselves are painted entirely by the
       package's own tokens — nothing here reaches inside .dynamic-table. Both
       are driven by the same scheme choice, so the page and the table never
       disagree. */

    :root {
        --ink: #0f172a;
        --muted: #64748b;
        --line: #e2e8f0;
        --surface: #ffffff;
        --ground: #f8fafc;
        --accent: #4f46e5;
        --accent-soft: #eef2ff;
        --code-bg: #0d1117;
        color-scheme: light;
    }

    @media (prefers-color-scheme: dark) {
        :root:not([data-scheme='light']) {
            --ink: #e2e8f0;
            --muted: #94a3b8;
            --line: #1e293b;
            --surface: #0f172a;
            --ground: #020617;
            --accent: #818cf8;
            --accent-soft: #1e1b4b;
            color-scheme: dark;
        }
    }

    :root[data-scheme='dark'] {
        --ink: #e2e8f0;
        --muted: #94a3b8;
        --line: #1e293b;
        --surface: #0f172a;
        --ground: #020617;
        --accent: #818cf8;
        --accent-soft: #1e1b4b;
        color-scheme: dark;
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        background: var(--ground);
        color: var(--ink);
        font: 15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    }

    a { color: inherit; }

    .demo-visually-hidden {
        position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0;
        overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
    }

    /* ---------------------------------------------------------- header */

    .demo-header {
        position: sticky; top: 0; z-index: 50;
        display: flex; align-items: center; gap: 1rem;
        padding: .75rem 1.25rem;
        background: var(--surface);
        border-bottom: 1px solid var(--line);
    }

    .demo-brand { display: flex; align-items: center; gap: .7rem; text-decoration: none; }
    .demo-brand small { display: block; color: var(--muted); font-size: .78rem; font-weight: 400; }
    .demo-brand strong { font-size: 1rem; }

    .demo-logo {
        display: grid; place-items: center;
        width: 2rem; height: 2rem; border-radius: 8px;
        background: var(--accent); color: var(--surface); font-size: 1rem;
    }

    .demo-header-actions { margin-inline-start: auto; display: flex; align-items: center; gap: .75rem; }

    .demo-switch { display: flex; gap: .15rem; background: var(--ground); padding: .15rem; border-radius: 8px; }

    .demo-switch > * {
        padding: .25rem .55rem; border-radius: 6px; border: 0; background: none;
        font: inherit; font-size: .78rem; font-weight: 600; text-decoration: none;
        color: var(--muted); cursor: pointer;
    }

    .demo-switch > .is-active {
        background: var(--surface); color: var(--accent);
        box-shadow: 0 1px 2px rgb(0 0 0 / .12);
    }

    /* ---------------------------------------------------------- shell */

    .demo-shell {
        display: grid;
        grid-template-columns: 16rem minmax(0, 1fr);
        align-items: start;
        gap: 1.5rem;
        max-width: 1500px;
        margin: 0 auto;
        padding: 1.5rem 1.25rem 4rem;
    }

    .demo-sidebar {
        position: sticky; top: 4.5rem;
        max-height: calc(100vh - 6rem);
        overflow: auto;
        padding-inline-end: .5rem;
    }

    .demo-nav-group { margin-top: 1.1rem; }
    .demo-nav-group[hidden] { display: none; }

    .demo-nav-heading {
        margin: 0 0 .35rem;
        font-size: .7rem; font-weight: 700; letter-spacing: .06em;
        text-transform: uppercase; color: var(--muted);
    }

    .demo-nav ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 1px; }

    .demo-nav-link {
        display: block; padding: .35rem .6rem; border-radius: 7px;
        text-decoration: none; font-size: .875rem; color: var(--ink);
    }

    .demo-nav-link:hover { background: var(--surface); }
    .demo-nav-link.is-active { background: var(--accent-soft); color: var(--accent); font-weight: 600; }
    .demo-nav-link[hidden] { display: none; }
    .demo-nav-empty { color: var(--muted); font-size: .85rem; padding: .5rem .6rem; }

    /* ---------------------------------------------------------- main */

    .demo-main { min-width: 0; display: grid; gap: 1.25rem; }

    .demo-title { margin: 0; font-size: 1.6rem; letter-spacing: -.02em; }
    .demo-eyebrow { color: var(--accent); font-size: .75rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; margin: 0; }
    .demo-lede { margin: .35rem 0 0; color: var(--muted); max-width: 62ch; }

    .demo-card {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 12px;
        box-shadow: 0 1px 2px rgb(15 23 42 / .04);
        overflow: hidden;
    }

    .demo-section-title {
        margin: 0 0 .5rem;
        font-size: .8rem; font-weight: 700; letter-spacing: .06em;
        text-transform: uppercase; color: var(--muted);
    }

    .demo-notes { margin: 0; padding: 0; list-style: none; display: grid; gap: .5rem; }

    .demo-notes li {
        position: relative;
        padding-inline-start: 1.4rem;
        color: var(--ink); font-size: .9rem;
    }

    .demo-notes li::before {
        content: '›';
        position: absolute; inset-inline-start: .45rem;
        color: var(--accent); font-weight: 700;
    }

    .demo-panel { padding: 1rem 1.15rem; }

    .demo-callout { border-inline-start: 3px solid var(--accent); }
    .demo-callout p { margin: 0 0 .6rem; }
    .demo-callout pre {
        margin: 0; padding: .6rem .8rem; border-radius: 8px;
        background: var(--code-bg); color: #e6edf3;
        font-size: .8rem; overflow-x: auto; direction: ltr; text-align: start;
    }

    /* ---------------------------------------------------------- source */

    .demo-tabs { display: flex; flex-wrap: wrap; gap: .25rem; padding: .6rem .6rem 0; border-bottom: 1px solid var(--line); }

    .demo-tab {
        border: 0; background: none; cursor: pointer;
        padding: .4rem .7rem; border-radius: 7px 7px 0 0;
        font: inherit; font-size: .82rem; color: var(--muted);
    }

    .demo-tab:hover { background: var(--ground); }
    .demo-tab.is-active { background: var(--code-bg); color: #e6edf3; font-weight: 600; }

    .demo-source pre { margin: 0; max-height: 34rem; overflow: auto; background: var(--code-bg); }
    .demo-source pre code { font-size: .8rem; line-height: 1.5; padding: 1rem 1.15rem; display: block; }
    .demo-source [hidden] { display: none; }
    .demo-source-path { padding: .4rem 1.15rem; background: var(--code-bg); color: #7d8590; font-size: .72rem; font-family: ui-monospace, monospace; }

    .demo-copy { margin-inline-start: auto; }

    /* ---------------------------------------------------------- buttons/inputs */

    .demo-btn {
        display: inline-flex; align-items: center; gap: .4rem;
        padding: .35rem .7rem; border-radius: 8px;
        border: 1px solid var(--line); background: var(--surface);
        font: inherit; font-size: .82rem; cursor: pointer; color: var(--ink);
    }

    .demo-btn:hover { background: var(--ground); }
    .demo-btn-primary { background: var(--accent); border-color: var(--accent); color: var(--surface); }
    .demo-btn-danger { background: #dc2626; border-color: #dc2626; color: #fff; }

    .demo-input {
        width: 100%;
        padding: .35rem .6rem;
        border: 1px solid var(--line); border-radius: 8px;
        background: var(--surface); color: var(--ink); font: inherit; font-size: .85rem;
    }

    .demo-input:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; border-color: var(--accent); }
    .demo-input-search { width: 14rem; }
    .demo-select { width: auto; }

    /* The custom-theme example's own look, also token-driven so it follows the
       scheme switch like everything else. */
    .demo-toolbar { padding: .75rem 1rem; border-bottom: 1px solid var(--line); }
    .demo-table { font-size: .875rem; }
    .demo-th { padding: .55rem .8rem; font-size: .7rem; letter-spacing: .06em; text-transform: uppercase; }
    .demo-cell { padding: .55rem .8rem; }
    .demo-footer { padding: .6rem 1rem; font-size: .85rem; }
    .demo-empty { padding: 3rem 1rem; }
    .demo-menu { border-radius: 10px; padding: .25rem; box-shadow: 0 10px 30px rgb(15 23 42 / .25); }
    .demo-menu-item { border: 0; background: none; font: inherit; font-size: .85rem; cursor: pointer; }
    .demo-modal { border-radius: 12px; }

    /* Stock badges used by the formatting example's render closure */
    .stock { display: inline-flex; padding: .05rem .45rem; border-radius: 999px; font-size: .75rem; font-weight: 600; }
    .stock-ok { background: #dcfce7; color: #166534; }
    .stock-low { background: #fef3c7; color: #92400e; }
    .stock-critical { background: #fee2e2; color: #991b1b; }

    /* ---------------------------------------------------------- docs */

    .demo-skip {
        position: absolute; inset-inline-start: -9999px; top: .5rem; z-index: 100;
        padding: .5rem .9rem; border-radius: 8px;
        background: var(--accent); color: #fff; text-decoration: none;
    }

    .demo-skip:focus { inset-inline-start: .75rem; }

    .demo-sections { display: flex; gap: .25rem; margin-inline-start: 1rem; }

    .demo-sections a {
        padding: .35rem .7rem; border-radius: 8px;
        font-size: .85rem; font-weight: 600; text-decoration: none; color: var(--muted);
    }

    .demo-sections a:hover { background: var(--ground); color: var(--ink); }
    .demo-sections a.is-active { background: var(--accent-soft); color: var(--accent); }

    .demo-doc { display: grid; gap: 1.25rem; max-width: 52rem; }
    .demo-doc-head { display: grid; gap: .1rem; }

    .demo-toc {
        border-inline-start: 2px solid var(--line);
        padding-inline-start: .9rem;
    }

    .demo-toc-title {
        margin: 0 0 .35rem;
        font-size: .7rem; font-weight: 700; letter-spacing: .06em;
        text-transform: uppercase; color: var(--muted);
    }

    .demo-toc ul { list-style: none; margin: 0; padding: 0; display: grid; gap: .15rem; }
    .demo-toc a { color: var(--muted); font-size: .85rem; text-decoration: none; }
    .demo-toc a:hover { color: var(--accent); }

    /* The rendered Markdown. Sized for reading rather than for density. */
    .demo-prose { font-size: .95rem; line-height: 1.7; }
    .demo-prose > *:first-child { margin-top: 0; }
    .demo-prose h2, .demo-prose h3, .demo-prose h4 { line-height: 1.3; scroll-margin-top: 5rem; }
    .demo-prose h2 { margin: 2.2rem 0 .6rem; font-size: 1.3rem; letter-spacing: -.01em; }
    .demo-prose h3 { margin: 1.8rem 0 .5rem; font-size: 1.05rem; }
    .demo-prose h4 { margin: 1.4rem 0 .4rem; font-size: .95rem; }
    .demo-prose p { margin: .8rem 0; }
    .demo-prose ul, .demo-prose ol { margin: .8rem 0; padding-inline-start: 1.3rem; }
    .demo-prose li { margin: .3rem 0; }
    .demo-prose a { color: var(--accent); text-decoration: underline; text-underline-offset: 2px; }
    .demo-prose strong { font-weight: 650; }
    .demo-prose hr { border: 0; border-top: 1px solid var(--line); margin: 2rem 0; }

    .demo-anchor {
        float: inline-start;
        margin-inline-start: -1rem;
        width: 1rem;
        color: var(--line);
        text-decoration: none;
        opacity: 0;
    }

    .demo-prose h2:hover .demo-anchor,
    .demo-prose h3:hover .demo-anchor { opacity: 1; color: var(--accent); }

    .demo-prose code {
        padding: .1rem .35rem;
        border-radius: 5px;
        background: var(--ground);
        border: 1px solid var(--line);
        font-size: .85em;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    }

    .demo-prose pre {
        margin: 1rem 0; padding: 0;
        border-radius: 10px;
        background: var(--code-bg);
        overflow-x: auto;
    }

    .demo-prose pre code {
        display: block; padding: .9rem 1.1rem;
        background: none; border: 0; border-radius: 0;
        color: #e6edf3; font-size: .82rem; line-height: 1.55;
    }

    .demo-prose blockquote {
        margin: 1rem 0; padding: .7rem 1rem;
        border-inline-start: 3px solid var(--accent);
        background: var(--accent-soft);
        border-radius: 0 8px 8px 0;
    }

    .demo-prose blockquote p { margin: .25rem 0; }

    .demo-prose table {
        width: 100%; margin: 1rem 0;
        border-collapse: collapse;
        font-size: .85rem;
        display: block; overflow-x: auto;
    }

    .demo-prose th, .demo-prose td {
        padding: .45rem .7rem;
        border: 1px solid var(--line);
        text-align: start;
        vertical-align: top;
    }

    .demo-prose th { background: var(--ground); font-weight: 650; }

    .demo-doc-foot {
        display: grid; grid-template-columns: 1fr 1fr; gap: .75rem;
        margin-top: 1rem; padding-top: 1.25rem;
        border-top: 1px solid var(--line);
    }

    .demo-doc-nav {
        display: grid; gap: .1rem;
        padding: .7rem .9rem;
        border: 1px solid var(--line); border-radius: 10px;
        text-decoration: none;
    }

    .demo-doc-nav:hover { border-color: var(--accent); }
    .demo-doc-nav span { color: var(--muted); font-size: .75rem; }
    .demo-doc-nav-next { text-align: end; }

    .demo-doc-source { color: var(--muted); font-size: .8rem; }

    /* ---------------------------------------------------------- builder */

    /* No sidebar means one column, not an empty one: without this the main
       content stays in the grid's 16rem sidebar track. */
    .demo-shell-wide {
        grid-template-columns: minmax(0, 1fr);
        max-width: 1800px;
    }

    .builder { display: grid; grid-template-columns: 19rem minmax(0, 1fr); gap: 1.5rem; align-items: start; }

    .builder-controls { position: sticky; top: 4.5rem; max-height: calc(100vh - 6rem); overflow: auto; display: grid; gap: 1.1rem; }

    .builder-group { border: 0; margin: 0; padding: 0; }
    .builder-group legend { padding: 0; }

    .builder-checks { display: grid; gap: .15rem; }

    .builder-check {
        display: flex; align-items: center; gap: .5rem;
        padding: .25rem .35rem; border-radius: 7px;
        font-size: .85rem; cursor: pointer;
    }

    .builder-check:hover { background: var(--ground); }
    .builder-check input { inline-size: 1rem; block-size: 1rem; accent-color: var(--accent); cursor: pointer; }
    .builder-check span:first-of-type { text-transform: capitalize; }

    .builder-tag {
        margin-inline-start: auto;
        font-size: .65rem; color: var(--muted);
        border: 1px solid var(--line); border-radius: 999px; padding: 0 .4rem;
    }

    .builder-fields { display: grid; gap: .6rem; }
    .builder-field { display: grid; gap: .25rem; font-size: .82rem; color: var(--muted); }
    .builder-field select { color: var(--ink); }
    .builder-note { margin: 0; font-size: .8rem; }

    .builder-output { display: grid; gap: 1.25rem; min-width: 0; }

    /*
     * The preview is the page. It gets the width the sidebar used to take, and
     * the card clips the table's corners so the header and the footer sit
     * inside the frame rather than against it.
     */
    .builder-preview {
        padding: 0;
        overflow: hidden;
        transition: opacity .15s ease;
        box-shadow: 0 1px 2px rgb(15 23 42 / .06), 0 8px 24px -18px rgb(15 23 42 / .5);
    }

    .builder-preview[aria-busy='true'] { opacity: .55; }

    /* A table inside the preview owns the card: no double border, no gap. */
    .builder-preview .dynamic-table { border: 0; border-radius: 0; }

    .builder-code pre { max-height: 24rem; }

    @media (max-width: 1100px) {
        .builder { grid-template-columns: minmax(0, 1fr); }
        .builder-controls { position: static; max-height: none; }
    }

    /* ---------------------------------------------------------- responsive */

    .demo-nav-toggle { display: none; }

    /*
     * The header is one flex row on a desktop and a wrapping stack below that.
     * Nothing is hidden that cannot be reached another way: the tagline goes
     * (it is decoration), the switches stay.
     */
    @media (max-width: 1100px) {
        .demo-header { flex-wrap: wrap; gap: .5rem 1rem; padding: .6rem 1rem; }
        .demo-brand small { display: none; }
        .demo-header-actions { gap: .5rem; }
    }

    @media (max-width: 900px) {
        .demo-shell {
            grid-template-columns: minmax(0, 1fr);
            gap: 1rem;
            padding: 1rem 1rem 3rem;
        }

        .demo-nav-toggle { display: inline-flex; }

        /*
         * In flow rather than fixed: a fixed drawer has to know how tall the
         * header is, and the header's height now depends on how much of it
         * wrapped. This cannot get that wrong.
         */
        .demo-sidebar {
            position: static;
            max-height: none;
            padding: .85rem 1rem;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 12px;
        }

        .demo-sidebar:not(.is-open) { display: none; }
        .demo-doc-foot { grid-template-columns: minmax(0, 1fr); }
        .demo-doc-nav-next { text-align: start; }
    }

    @media (max-width: 640px) {
        .demo-header {
            position: static;   /* a wrapped header is too tall to pin */
            align-items: flex-start;
            padding: .6rem .85rem;
        }

        .demo-brand strong { font-size: .95rem; }
        .demo-sections { margin-inline-start: 0; order: 3; width: 100%; }
        .demo-sections a { flex: 1; text-align: center; }

        .demo-header-actions {
            order: 2;
            width: auto;
            margin-inline-start: auto;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .demo-switch > * { padding: .25rem .45rem; font-size: .72rem; }

        .demo-title { font-size: 1.3rem; }
        .demo-panel { padding: .85rem .9rem; }
        .demo-input-search { width: 100%; }

        .demo-tabs { padding: .5rem .5rem 0; }
        .demo-tab { font-size: .78rem; padding: .35rem .55rem; }
        .demo-copy { margin-inline-start: 0; }

        .demo-source pre { max-height: 22rem; }
        .demo-source pre code { font-size: .75rem; padding: .75rem .85rem; }

        /* Wide tables in the rendered Markdown scroll on their own. */
        .demo-prose table { display: block; overflow-x: auto; max-width: 100%; }
        .demo-prose pre { overflow-x: auto; }
    }
</style>
