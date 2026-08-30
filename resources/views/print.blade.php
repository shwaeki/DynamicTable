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

        .dt-print { padding: 0; }

        /* -------------------------------------------------- masthead */

        .dt-print-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12mm;
            padding-bottom: 3mm;
            margin-bottom: 4mm;
            border-bottom: 1.5pt solid #111;
        }

        .dt-print-title { margin: 0; font-size: 15pt; font-weight: 700; letter-spacing: -0.01em; }
        .dt-print-meta { margin: 1.5mm 0 0; padding: 0; list-style: none; color: #444; font-size: 8pt; }
        .dt-print-meta li { margin-top: 0.6mm; }
        .dt-print-stamp { text-align: end; color: #444; font-size: 8pt; white-space: nowrap; }
        .dt-print-stamp strong { display: block; color: #111; font-size: 9pt; }

        /* -------------------------------------------------- the table */

        table.dt-print-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
        }

        .dt-print-table th,
        .dt-print-table td {
            padding: 1.6mm 2mm;
            border-bottom: 0.5pt solid #ccc;
            vertical-align: top;
            text-align: start;
        }

        .dt-print-table thead th {
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
        .dt-print-table thead { display: table-header-group; }
        .dt-print-table tfoot { display: table-footer-group; }
        .dt-print-table tr { page-break-inside: avoid; break-inside: avoid; }

        .dt-print-table tbody tr:nth-child(even) { background: #f6f7f9; }

        .dt-align-end { text-align: end; }
        .dt-align-center { text-align: center; }

        .dt-print-table tfoot td {
            border-top: 1pt solid #111;
            border-bottom: 0;
            font-weight: 700;
        }

        .dt-print-summary-label {
            display: block;
            font-size: 7pt;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #555;
        }

        /* -------------------------------------------------- cells */

        .dt-null { color: #999; }
        .dt-badge { border: 0.5pt solid #999; border-radius: 2mm; padding: 0.2mm 1.4mm; font-size: 8pt; }
        .dt-chips { display: inline; }
        .dt-chip { border: 0.5pt solid #999; border-radius: 2mm; padding: 0.2mm 1.2mm; margin-inline-end: 1mm; font-size: 8pt; }
        .dt-avatar, .dt-thumb { width: 8mm; height: 8mm; object-fit: cover; border-radius: 50%; }
        .dt-progress { display: flex; align-items: center; gap: 2mm; }
        .dt-progress-track { flex: 1; height: 1.2mm; background: #ddd; border-radius: 1mm; overflow: hidden; }
        .dt-progress-bar { display: block; height: 100%; background: #555; }
        .dt-sparkline svg { width: 18mm; height: 5mm; }
        .dt-sparkline polyline { fill: none; stroke: #555; stroke-width: 1.2; }
        .dt-rating-stars { letter-spacing: 0.05em; }
        .dt-visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }

        /* -------------------------------------------------- notices */

        .dt-print-note {
            margin-top: 4mm;
            padding: 2mm 3mm;
            border: 0.5pt solid #999;
            border-radius: 1mm;
            font-size: 8pt;
            color: #444;
        }

        .dt-print-footer {
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

            .dt-print {
                max-width: 297mm;
                margin: 0 auto;
                padding: 14mm 12mm;
                background: #fff;
                box-shadow: 0 1px 3px rgb(0 0 0 / 0.15);
            }

            .dt-print-toolbar {
                max-width: 297mm;
                margin: 0 auto 6mm;
                display: flex;
                gap: 8px;
                justify-content: flex-end;
            }

            .dt-print-button {
                font: inherit;
                font-size: 10pt;
                padding: 6px 14px;
                border: 1px solid #c7ccd3;
                border-radius: 6px;
                background: #fff;
                cursor: pointer;
            }

            .dt-print-button-primary { background: #111; border-color: #111; color: #fff; }
        }

        @media print {
            .dt-print-toolbar { display: none !important; }
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
    <main class="dt-print">
        <header class="dt-print-head">
            <div>
                <h1 class="dt-print-title">{{ $title }}</h1>

                @if ($meta !== [])
                    <ul class="dt-print-meta">
                        @foreach ($meta as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="dt-print-stamp">
                <strong>{{ __('dynamic-table::table.print.scopes.'.$scope) }}</strong>
                {{ $printedAt->isoFormat('LLL') }}<br>
                {{ trans_choice('dynamic-table::table.print.rows', count($rows), ['count' => number_format(count($rows))]) }}
            </div>
        </header>

        <table class="dt-print-table">
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th class="dt-align-{{ $column['align'] ?? 'start' }}">{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>

            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($columns as $column)
                            <td class="dt-align-{{ $column['align'] ?? 'start' }}">
                                @include('dynamic-table::partials.cell', [
                                    'column' => $column,
                                    'value' => $row['c'][$column['key']] ?? null,
                                    'classes' => $classes,
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
                            <td class="dt-align-{{ $column['align'] ?? 'start' }}">
                                @if (isset($summaries[$column['key']]))
                                    <span class="dt-print-summary-label">{{ __('dynamic-table::table.summary.'.$column['summary']) }}</span>
                                    {{ $summaries[$column['key']] }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>

        @if ($truncated)
            <p class="dt-print-note">
                {{ __('dynamic-table::table.print.truncated', ['limit' => number_format($limit)]) }}
            </p>
        @endif

        <footer class="dt-print-footer">
            <span>{{ $title }}</span>
            <span>{{ $printedAt->isoFormat('LL') }}</span>
        </footer>
    </main>
</body>
</html>
