@php
    $frameworks = config('dynamic-table.print.stylesheets') ?? [
        'bootstrap' => ['https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'],
        'tailwind' => [],
    ];

    $frameworkScripts = $theme === 'tailwind' && config('dynamic-table.print.stylesheets') === null
        ? ['https://cdn.tailwindcss.com']
        : [];

    $sheets = $table->printStylesheets() ?: ($frameworks[$theme] ?? []);
    $paper = config('dynamic-table.print.paper', 'A4');
    $orientation = count($columns) > 7 ? 'landscape' : 'portrait';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>

    @foreach ($sheets as $href)
        <link rel="stylesheet" href="{{ $href }}">
    @endforeach

    @foreach ($frameworkScripts as $src)
        <script src="{{ $src }}"></script>
    @endforeach

    <style>
        @page {
            size: {{ $paper }} {{ $orientation }};
            margin: 14mm 12mm 16mm;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            background: #fff;
            color: #111;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 9.5pt;
            line-height: 1.35;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .dynamic-table-print { padding: 0; }

        /* -------------------------------------------------- masthead */

        .dynamic-table-print-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12mm;
            padding-bottom: 3mm;
            margin-bottom: 4mm;
            border-bottom: 1.5pt solid #111;
        }

        .dynamic-table-print-title { margin: 0; font-size: 15pt; font-weight: 700; letter-spacing: -0.01em; }
        .dynamic-table-print-meta { margin: 1.5mm 0 0; padding: 0; list-style: none; color: #444; font-size: 8pt; }
        .dynamic-table-print-meta li { margin-top: 0.6mm; }
        .dynamic-table-print-stamp { text-align: end; color: #444; font-size: 8pt; white-space: nowrap; }
        .dynamic-table-print-stamp strong { display: block; color: #111; font-size: 9pt; }

        /* -------------------------------------------------- the table */

        table.dynamic-table-print-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
        }

        .dynamic-table-print-table th,
        .dynamic-table-print-table td {
            padding: 1.6mm 2mm;
            border-bottom: 0.5pt solid #ccc;
            vertical-align: top;
            text-align: start;
        }

        .dynamic-table-print-table thead th {
            border-bottom: 1pt solid #111;
            font-size: 7.5pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #000;
        }

        /*
         * The header repeats on every sheet, and a row is never split across
         * two — the two things that separate a printed table from a screenshot
         * of one.
         */
        .dynamic-table-print-table thead { display: table-header-group; }
        .dynamic-table-print-table tfoot { display: table-footer-group; }
        .dynamic-table-print-table tr { page-break-inside: avoid; break-inside: avoid; }

        .dynamic-table-print-table tbody tr:nth-child(even) { background: #f6f7f9; }

        .dynamic-table-align-end { text-align: end; }
        .dynamic-table-align-center { text-align: center; }

        .dynamic-table-print-table tfoot td {
            border-top: 1pt solid #111;
            border-bottom: 0;
            font-weight: 700;
        }

        .dynamic-table-print-summary-label {
            display: block;
            font-size: 7pt;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #555;
        }

        /* -------------------------------------------------- cells */

        .dynamic-table-null { color: #999; }
        .dynamic-table-badge { border: 0.5pt solid #999; border-radius: 2mm; padding: 0.2mm 1.4mm; font-size: 8pt; }
        .dynamic-table-chips { display: inline; }
        .dynamic-table-chip { border: 0.5pt solid #999; border-radius: 2mm; padding: 0.2mm 1.2mm; margin-inline-end: 1mm; font-size: 8pt; }
        .dynamic-table-avatar, .dynamic-table-thumb { width: 8mm; height: 8mm; object-fit: cover; border-radius: 50%; }
        .dynamic-table-progress { display: flex; align-items: center; gap: 2mm; }
        .dynamic-table-progress-track { flex: 1; height: 1.2mm; background: #ddd; border-radius: 1mm; overflow: hidden; }
        .dynamic-table-progress-bar { display: block; height: 100%; background: #555; }
        .dynamic-table-sparkline svg { width: 18mm; height: 5mm; }
        .dynamic-table-sparkline polyline { fill: none; stroke: #555; stroke-width: 1.2; }
        .dynamic-table-rating-stars { letter-spacing: 0.05em; }
        .dynamic-table-visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }

        /* -------------------------------------------------- notices */

        .dynamic-table-print-note {
            margin-top: 4mm;
            padding: 2mm 3mm;
            border: 0.5pt solid #999;
            border-radius: 1mm;
            font-size: 8pt;
            color: #444;
        }

        .dynamic-table-print-footer {
            margin-top: 5mm;
            padding-top: 2mm;
            border-top: 0.5pt solid #ccc;
            color: #666;
            font-size: 7.5pt;
            display: flex;
            justify-content: space-between;
        }

        /* -------------------------------------------------- on screen */

        /* The page opens in a browser tab first. This makes that preview look
           like the sheet it is about to become, and hides the toolbar when it
           actually prints. */
        @media screen {
            body { background: #eef0f3; padding: 8mm; }

            .dynamic-table-print {
                max-width: 297mm;
                margin: 0 auto;
                padding: 14mm 12mm;
                background: #fff;
                box-shadow: 0 1px 3px rgb(0 0 0 / 0.15);
            }

            .dynamic-table-print-toolbar {
                max-width: 297mm;
                margin: 0 auto 6mm;
                display: flex;
                gap: 8px;
                justify-content: flex-end;
            }

            .dynamic-table-print-button {
                font: inherit;
                font-size: 10pt;
                padding: 6px 14px;
                border: 1px solid #c7ccd3;
                border-radius: 6px;
                background: #fff;
                cursor: pointer;
            }

            .dynamic-table-print-button-primary { background: #111; border-color: #111; color: #fff; }
        }

        @media print {
            .dynamic-table-print-toolbar { display: none !important; }
        }
    </style>
</head>
<body>
    @if ($auto)
        <script>
            (() => {
                const print = () => window.setTimeout(() => window.print(), 60);

                window.addEventListener('afterprint', () => window.close());

                if (document.readyState === 'complete') print();
                else window.addEventListener('load', print);
            })();
        </script>
    @endif
    <main class="dynamic-table-print">
        <header class="dynamic-table-print-head">
            <div>
                <h1 class="dynamic-table-print-title">{{ $title }}</h1>

                @if ($meta !== [])
                    <ul class="dynamic-table-print-meta">
                        @foreach ($meta as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="dynamic-table-print-stamp">
                <strong>{{ __('dynamic-table::table.print.scopes.'.$scope) }}</strong>
                {{ $printedAt->isoFormat('LLL') }}<br>
                {{ trans_choice('dynamic-table::table.print.rows', count($rows), ['count' => number_format(count($rows))]) }}
            </div>
        </header>

        <table class="dynamic-table-print-table">
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th class="dynamic-table-align-{{ $column['align'] ?? 'start' }}">{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>

            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($columns as $column)
                            <td class="dynamic-table-align-{{ $column['align'] ?? 'start' }}">
                                @include('dynamic-table::partials.cell', [
                                    'column' => $column,
                                    'value' => $row['c'][$column['key']] ?? null,
                                    'classes' => $classes,
                                    'html' => isset($row['h'][$column['key']]),
                                ])
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) }}">{{ __('dynamic-table::table.empty') }}</td>
                    </tr>
                @endforelse
            </tbody>

            @if ($summaries !== [])
                <tfoot>
                    <tr>
                        @foreach ($columns as $column)
                            <td class="dynamic-table-align-{{ $column['align'] ?? 'start' }}">
                                @if (isset($summaries[$column['key']]))
                                    <span class="dynamic-table-print-summary-label">{{ __('dynamic-table::table.summary.'.$column['summary']) }}</span>
                                    {{ $summaries[$column['key']] }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>

        @if ($truncated)
            <p class="dynamic-table-print-note">
                {{ __('dynamic-table::table.print.truncated', ['limit' => number_format($limit)]) }}
            </p>
        @endif

        <footer class="dynamic-table-print-footer">
            <span>{{ $title }}</span>
            <span>{{ $printedAt->isoFormat('LL') }}</span>
        </footer>
    </main>
</body>
</html>
