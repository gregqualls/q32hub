<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
@php
    $totalTime = $recipe->total_time_minutes ?? (($recipe->prep_time_minutes ?? 0) + ($recipe->cook_time_minutes ?? 0));
    $formatTime = function ($m) {
        if (! $m) return null;
        if ($m < 60) return $m.'m';
        $h = intdiv($m, 60);
        $r = $m % 60;
        return $r > 0 ? "{$h}h {$r}m" : "{$h}h";
    };
    $ogTitle = $recipe->title.' — Shared via Kinhold';
    $ogDescription = $recipe->description ?: 'A family recipe shared via Kinhold.';
    $ogImage = $images['primary']['url'] ?? null;
    if ($ogImage && ! str_starts_with($ogImage, 'http')) {
        $ogImage = url($ogImage);
    }

    // Inline SVG icons for the Big 9. Simple line-art silhouettes; inherit the
    // surrounding text color via stroke="currentColor". Custom family allergens
    // fall back to a generic warning glyph.
    $svgAttrs = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"';
    $allergenIcons = [
        'milk' => '<svg '.$svgAttrs.'><path d="M9 4h6l1 3 1 2v9a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2V9l1-2 1-3z"/><path d="M10 13h4"/></svg>',
        'eggs' => '<svg '.$svgAttrs.'><path d="M12 3c-4 0-5 6-5 10s2 7 5 7 5-3 5-7-1-10-5-10z"/></svg>',
        'fish' => '<svg '.$svgAttrs.'><path d="M15 12c0-3-4-5-9 0 5 5 9 3 9 0z"/><path d="M15 12l4-4v8z"/><circle cx="9" cy="11" r="0.6" fill="currentColor" stroke="none"/></svg>',
        'shellfish' => '<svg '.$svgAttrs.'><path d="M5 11c0-3 3-5 7-4s7 4 7 7-3 5-6 4-6-3-6-4"/><path d="M19 13l2-2"/><path d="M12 7l-1-2"/></svg>',
        'tree-nuts' => '<svg '.$svgAttrs.'><path d="M12 3c-4 0-6 5-6 10 0 4 3 8 6 8s6-4 6-8c0-5-2-10-6-10z"/><path d="M12 6v12"/></svg>',
        'peanuts' => '<svg '.$svgAttrs.'><circle cx="9.5" cy="9" r="4"/><circle cx="14.5" cy="15" r="4"/></svg>',
        'wheat' => '<svg '.$svgAttrs.'><path d="M12 21V8"/><path d="M12 13L8 10M12 13l4-3"/><path d="M12 17L8 14M12 17l4-3"/><path d="M12 8c-1-2-2-3-4-3M12 8c1-2 2-3 4-3"/></svg>',
        'soy' => '<svg '.$svgAttrs.'><path d="M5 12c0-4 4-7 9-7 4 0 6 2 6 5 0 4-4 7-9 7-4 0-6-2-6-5z"/><circle cx="9" cy="11" r="0.9" fill="currentColor" stroke="none"/><circle cx="13" cy="10" r="0.9" fill="currentColor" stroke="none"/><circle cx="16" cy="12" r="0.9" fill="currentColor" stroke="none"/></svg>',
        'sesame' => '<svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><ellipse cx="8" cy="10" rx="1.4" ry="2"/><ellipse cx="14" cy="9" rx="1.4" ry="2"/><ellipse cx="11" cy="14" rx="1.4" ry="2"/><ellipse cx="17" cy="13.5" rx="1.4" ry="2"/></svg>',
    ];
    $fallbackIcon = '<svg '.$svgAttrs.'><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h0"/></svg>';

    // Recipes read better with fractions than decimals. Common cooking fractions
    // get mapped back; mixed numbers get a whole + fraction format. Matches the
    // SPA's RecipeForm decimalToFraction helper so the public page and the
    // in-app view show the same shapes.
    $decimalToFraction = function ($value) {
        if ($value === null || $value === '') return '';
        if (! is_numeric($value)) return (string) $value;
        $num = (float) $value;
        if ($num == floor($num)) return (string) (int) $num;

        $whole = (int) floor($num);
        $dec = round(($num - $whole) * 1000) / 1000;
        $map = [
            ['d' => 0.125, 'f' => '1/8'],
            ['d' => 0.25,  'f' => '1/4'],
            ['d' => 0.333, 'f' => '1/3'],
            ['d' => 0.375, 'f' => '3/8'],
            ['d' => 0.5,   'f' => '1/2'],
            ['d' => 0.625, 'f' => '5/8'],
            ['d' => 0.667, 'f' => '2/3'],
            ['d' => 0.75,  'f' => '3/4'],
            ['d' => 0.875, 'f' => '7/8'],
        ];
        $frac = null;
        foreach ($map as $row) {
            if (abs($dec - $row['d']) < 0.005) { $frac = $row['f']; break; }
        }
        if (! $frac) return rtrim(rtrim((string) $num, '0'), '.');
        return $whole > 0 ? "{$whole} {$frac}" : $frac;
    };
@endphp
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $recipe->title }} — Kinhold</title>
    <meta name="description" content="{{ $ogDescription }}">
    <meta name="theme-color" content="#1B3A4B">

    {{-- Open Graph --}}
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="Kinhold">
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    @if ($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
    @endif
    <meta property="og:url" content="{{ url('/r/'.$recipe->share_token) }}">

    {{-- Twitter Card --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $ogTitle }}">
    <meta name="twitter:description" content="{{ $ogDescription }}">
    @if ($ogImage)
        <meta name="twitter:image" content="{{ $ogImage }}">
    @endif

    <link rel="canonical" href="{{ url('/r/'.$recipe->share_token) }}">

    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Plus+Jakarta+Sans:wght@600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css'])

    {{-- Print stylesheet: strip decorative chrome, force ink-friendly colors,
         avoid page breaks inside list items, constrain the hero image height. --}}
    <style>
        @media print {
            html, body { background: #fff !important; color: #000 !important; }
            .no-print { display: none !important; }
            /* Hide the hero gradient overlay so the image prints cleanly */
            [data-hero-gradient] { display: none !important; }
            /* Bring the title out of overlay positioning so it flows under the image */
            [data-hero-title] {
                position: static !important;
                padding: 0.75rem 0 0 0 !important;
            }
            [data-hero-title] h1 { font-size: 1.5rem !important; }
            [data-hero-title] p { font-size: 0.9rem !important; }
            /* Constrain hero image */
            [data-hero-image] {
                aspect-ratio: auto !important;
                max-height: 220px !important;
                margin-top: 0 !important;
            }
            [data-hero-image] img { max-height: 220px !important; }
            /* Meta cards compact and inline */
            [data-meta] { gap: 0.5rem !important; }
            [data-meta] > div { padding: 0.35rem 0.5rem !important; border-color: #ccc !important; background: #f8f8f8 !important; color: #000 !important; }
            /* Section paddings tightened */
            section { padding-top: 0.5rem !important; padding-bottom: 0.5rem !important; }
            /* Avoid awkward breaks */
            li, ol > li { break-inside: avoid; page-break-inside: avoid; }
            h1, h2, h3 { break-after: avoid; page-break-after: avoid; }
            /* Numbered method circle: render as filled dark so it prints visible */
            [data-step] { background: #000 !important; color: #fff !important; }
            /* Hyperlinks: keep underline so paper readers can transcribe */
            a { color: #000 !important; text-decoration: underline !important; }
            /* Hide gallery thumbnails on paper (saves ink) */
            [data-gallery] { display: none !important; }
        }
    </style>
</head>
<body class="font-sans antialiased bg-surface-app text-ink-primary">

    {{-- ─── Top bar ─── --}}
    <header class="no-print px-4 md:px-8 py-4 md:py-5 flex items-center justify-between max-w-5xl mx-auto">
        <a href="/" class="inline-flex items-center gap-2 text-ink-primary">
            <span class="text-base md:text-lg font-bold tracking-tight font-heading">Kinhold</span>
        </a>
        <div class="flex items-center gap-2">
            <button
                type="button"
                onclick="window.print()"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border border-border-subtle text-ink-secondary hover:text-ink-primary hover:border-ink-primary transition-colors"
                aria-label="Print this recipe"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5">
                    <path d="M6 9V3h12v6"/>
                    <rect x="4" y="9" width="16" height="9" rx="1"/>
                    <rect x="7" y="14" width="10" height="6"/>
                </svg>
                Print
            </button>
            <a href="/register" class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-semibold bg-ink-primary text-white hover:bg-ink-secondary transition-colors">
                Try Kinhold free →
            </a>
        </div>
    </header>

    {{-- ─── Hero: full-bleed image + bottom gradient with title sitting in it ─── --}}
    @if ($images['primary'])
        {{-- Section gets bg-surface-app so any sub-pixel seam between the image
             and the page bg is filled by the same color. Image wrapper has no
             overflow-hidden so the gradient can extend past the image bottom. --}}
        <section class="relative w-full bg-surface-app -mt-2">
            {{-- Image, clipped --}}
            <div data-hero-image class="w-full aspect-[16/10] md:aspect-[21/9] max-h-[80vh] overflow-hidden bg-surface-sunken">
                <img src="{{ $images['primary']['url'] }}" alt="{{ $recipe->title }}" class="w-full h-full object-cover" />
            </div>
            {{-- Gradient overlay covers the bottom of the image AND extends a few
                 pixels past it to hide any anti-alias seam. --}}
            <div
                data-hero-gradient
                class="pointer-events-none absolute inset-x-0"
                style="bottom: -1px; height: 60%; background-image: linear-gradient(to top,
                    rgb(var(--surface-app) / 1) 0%,
                    rgb(var(--surface-app) / 1) 35%,
                    rgb(var(--surface-app) / 0.85) 55%,
                    rgb(var(--surface-app) / 0.45) 75%,
                    rgb(var(--surface-app) / 0) 100%);"
            ></div>
            {{-- Title overlays the gradient zone. Description is clamped to 3 lines
                 so long copy stays visually anchored to the image. --}}
            <div data-hero-title class="absolute inset-x-0 bottom-0 px-4 md:px-8 pb-4 md:pb-8">
                <div class="max-w-3xl mx-auto">
                    <h1 class="text-3xl md:text-5xl font-bold font-heading text-ink-primary tracking-tight leading-tight line-clamp-2">
                        {{ $recipe->title }}
                    </h1>
                    @if ($recipe->description)
                        <p class="mt-3 md:mt-4 text-base md:text-lg text-ink-secondary leading-relaxed max-w-2xl line-clamp-3">
                            {{ $recipe->description }}
                        </p>
                    @endif
                    @if ($recipe->source_url)
                        <p class="mt-3 text-xs text-ink-tertiary">
                            From
                            <a href="{{ $recipe->source_url }}" target="_blank" rel="noopener noreferrer nofollow" class="font-medium text-accent-lavender-bold hover:underline">
                                {{ parse_url($recipe->source_url, PHP_URL_HOST) }}
                            </a>
                        </p>
                    @endif
                </div>
            </div>
        </section>
    @else
        {{-- No-image fallback: title in normal flow --}}
        <section class="px-4 md:px-8 pt-6 md:pt-10 max-w-3xl mx-auto">
            <h1 class="text-3xl md:text-5xl font-bold font-heading text-ink-primary tracking-tight leading-tight">
                {{ $recipe->title }}
            </h1>
            @if ($recipe->description)
                <p class="mt-3 md:mt-4 text-base md:text-lg text-ink-secondary leading-relaxed">
                    {{ $recipe->description }}
                </p>
            @endif
            @if ($recipe->source_url)
                <p class="mt-3 text-xs text-ink-tertiary">
                    From
                    <a href="{{ $recipe->source_url }}" target="_blank" rel="noopener noreferrer nofollow" class="font-medium text-accent-lavender-bold hover:underline">
                        {{ parse_url($recipe->source_url, PHP_URL_HOST) }}
                    </a>
                </p>
            @endif
        </section>
    @endif

    {{-- ─── Meta row ─── --}}
    @if ($recipe->prep_time_minutes || $recipe->cook_time_minutes || $totalTime || $recipe->servings)
        <section class="px-4 md:px-8 mt-6 md:mt-8 max-w-3xl mx-auto">
            <div data-meta class="grid grid-cols-2 md:grid-cols-4 gap-3">
                @if ($recipe->prep_time_minutes)
                    <div class="rounded-xl bg-surface-raised border border-border-subtle p-3">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-ink-tertiary">Prep</p>
                        <p class="text-base font-semibold text-ink-primary mt-1">{{ $formatTime($recipe->prep_time_minutes) }}</p>
                    </div>
                @endif
                @if ($recipe->cook_time_minutes)
                    <div class="rounded-xl bg-surface-raised border border-border-subtle p-3">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-ink-tertiary">Cook</p>
                        <p class="text-base font-semibold text-ink-primary mt-1">{{ $formatTime($recipe->cook_time_minutes) }}</p>
                    </div>
                @endif
                @if ($totalTime)
                    <div class="rounded-xl bg-accent-lavender-soft border border-accent-lavender-bold/30 p-3">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-accent-lavender-bold">Total</p>
                        <p class="text-base font-semibold text-accent-lavender-bold mt-1">{{ $formatTime($totalTime) }}</p>
                    </div>
                @endif
                @if ($recipe->servings)
                    <div class="rounded-xl bg-surface-raised border border-border-subtle p-3">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-ink-tertiary">Serves</p>
                        <p class="text-base font-semibold text-ink-primary mt-1">{{ $recipe->servings }}</p>
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- ─── Extras gallery (if multi-image) ─── --}}
    @if (count($images['extras']) > 0)
        <section data-gallery class="px-4 md:px-8 mt-6 max-w-3xl mx-auto">
            <div class="flex gap-2 overflow-x-auto pb-2">
                @foreach ($images['extras'] as $img)
                    <div class="shrink-0 w-24 h-24 md:w-28 md:h-28 rounded-lg overflow-hidden bg-surface-sunken border border-border-subtle">
                        <img src="{{ $img['url'] }}" alt="" class="w-full h-full object-cover" loading="lazy" />
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ─── Body: ingredients + method ─── --}}
    <section class="px-4 md:px-8 py-8 md:py-12 max-w-3xl mx-auto">
        <div class="grid md:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)] gap-8 md:gap-12">
            {{-- Ingredients column --}}
            <aside class="md:sticky md:top-6 md:self-start">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-tertiary">Ingredients</h2>
                <ul class="mt-3 space-y-2.5">
                    @forelse ($recipe->ingredients as $ing)
                        <li class="flex gap-2 text-[15px] leading-relaxed text-ink-primary">
                            <span class="text-ink-tertiary mt-1.5 shrink-0">•</span>
                            <span>
                                @if ($ing->quantity)
                                    <span class="font-semibold">{{ $decimalToFraction($ing->quantity) }}</span>
                                @endif
                                @if ($ing->unit)
                                    <span class="text-ink-secondary">{{ $ing->unit }}</span>
                                @endif
                                {{ $ing->name }}@if ($ing->preparation)<span class="text-ink-tertiary">, {{ $ing->preparation }}</span>@endif
                            </span>
                        </li>
                    @empty
                        <li class="text-sm text-ink-tertiary italic">No ingredients listed.</li>
                    @endforelse
                </ul>

                {{-- Allergens — line-art icon row beneath ingredients --}}
                @if (count($allergens) > 0)
                    <div class="mt-6 pt-4 border-t border-border-subtle">
                        <h3 class="text-[11px] font-semibold uppercase tracking-wide text-ink-tertiary">Contains</h3>
                        <ul class="mt-2.5 space-y-2">
                            @foreach ($allergens as $a)
                                @php
                                    $isContains = $a['presence'] === 'contains';
                                    $icon = $allergenIcons[$a['slug']] ?? $fallbackIcon;
                                @endphp
                                <li class="flex items-center gap-2.5 text-sm">
                                    <span class="shrink-0 inline-flex items-center justify-center w-5 h-5 text-ink-primary" aria-hidden="true">
                                        {!! $icon !!}
                                    </span>
                                    <span class="{{ $isContains ? 'text-ink-primary' : 'text-ink-secondary italic' }}">
                                        {{ $a['name'] }}@if (! $isContains) <span class="text-ink-tertiary">(may contain)</span>@endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </aside>

            {{-- Method column --}}
            <article>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-tertiary">Method</h2>
                @php $instructions = is_array($recipe->instructions) ? $recipe->instructions : []; @endphp
                @if (count($instructions) === 0)
                    <p class="mt-3 text-sm text-ink-tertiary italic">No instructions yet.</p>
                @else
                    <ol class="mt-3 space-y-5">
                        @foreach ($instructions as $idx => $step)
                            @php $text = is_array($step) ? ($step['text'] ?? '') : (is_string($step) ? $step : ''); @endphp
                            <li class="flex gap-4 items-start">
                                <span data-step class="shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-full bg-accent-lavender-soft text-accent-lavender-bold text-sm font-bold">
                                    {{ $idx + 1 }}
                                </span>
                                <p class="text-[15px] leading-relaxed text-ink-primary pt-0.5">{{ $text }}</p>
                            </li>
                        @endforeach
                    </ol>
                @endif

                @if ($recipe->notes)
                    <div class="mt-8 p-4 rounded-xl bg-accent-sun-soft border border-accent-sun-bold/20">
                        <p class="text-xs font-semibold uppercase tracking-wide text-accent-sun-bold">Notes</p>
                        <p class="mt-1.5 text-sm text-ink-primary leading-relaxed">{{ $recipe->notes }}</p>
                    </div>
                @endif
            </article>
        </div>
    </section>

    {{-- ─── Attribution + CTA footer ─── --}}
    <section class="no-print px-4 md:px-8 pb-10 max-w-3xl mx-auto">
        <div class="rounded-2xl bg-surface-raised border border-border-subtle p-5 md:p-6 flex flex-col md:flex-row gap-4 md:items-center md:justify-between">
            <div>
                @if ($attribution['visible'] && $attribution['family_name'])
                    <p class="text-sm text-ink-secondary">Shared by</p>
                    <p class="text-base font-semibold text-ink-primary">{{ $attribution['family_name'] }}</p>
                @else
                    <p class="text-sm text-ink-secondary">Shared via</p>
                    <p class="text-base font-semibold text-ink-primary">Kinhold</p>
                @endif
                <p class="mt-1 text-xs text-ink-tertiary">A family hub for recipes, meals, calendars, and more.</p>
            </div>
            <a href="/register" class="inline-flex items-center justify-center px-4 py-2.5 rounded-full text-sm font-semibold bg-ink-primary text-white hover:bg-ink-secondary transition-colors">
                Try Kinhold free
            </a>
        </div>

        <p class="mt-6 text-center text-[11px] text-ink-tertiary">
            <a href="/" class="hover:underline">kinhold.app</a>
            &nbsp;·&nbsp;
            <a href="/privacy" class="hover:underline">Privacy</a>
            &nbsp;·&nbsp;
            <a href="/terms" class="hover:underline">Terms</a>
        </p>
    </section>
</body>
</html>
