<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
@php
    $tags = $recipe->relationLoaded('tags') ? $recipe->tags->take(1) : collect();
    $primaryTag = $tags->first();
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
@endphp
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $recipe->title }} — Kinhold</title>
    <meta name="description" content="{{ $ogDescription }}">
    <meta name="theme-color" content="#1B3A4B">

    <!-- Open Graph -->
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="Kinhold">
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    @if ($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
    @endif
    <meta property="og:url" content="{{ url('/r/'.$recipe->share_token) }}">

    <!-- Twitter Card -->
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
</head>
<body class="font-sans antialiased bg-surface-base text-ink-primary">

    {{-- ─── Hero band ─── --}}
    <section class="relative">
        @if ($images['primary'])
            {{-- Blurred hero image as background; gradient overlay for legibility. --}}
            <div class="absolute inset-0 overflow-hidden">
                <img src="{{ $images['primary']['url'] }}" alt="" class="w-full h-full object-cover scale-110 blur-2xl opacity-60" />
                <div class="absolute inset-0 bg-gradient-to-b from-black/30 via-surface-base/40 to-surface-base"></div>
            </div>
        @else
            <div class="absolute inset-0 bg-gradient-to-b from-accent-lavender-soft via-surface-base to-surface-base"></div>
        @endif

        {{-- Top bar over the hero --}}
        <div class="relative z-10 px-4 md:px-8 pt-4 md:pt-6 flex items-center justify-between">
            <a href="/" class="inline-flex items-center gap-2 text-ink-primary">
                <span class="text-base md:text-lg font-bold tracking-tight font-heading">Kinhold</span>
            </a>
            <a href="/register" class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-semibold bg-ink-primary text-white hover:bg-ink-secondary transition-colors">
                Try Kinhold free →
            </a>
        </div>

        {{-- Hero content + main card --}}
        <div class="relative z-10 px-4 md:px-8 pt-8 md:pt-16 pb-6 md:pb-10 max-w-3xl mx-auto">
            @if ($primaryTag)
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-accent-mint-soft text-accent-mint-bold mb-4">
                    {{ strtoupper($primaryTag->name) }}
                </span>
            @else
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-accent-lavender-soft text-accent-lavender-bold mb-4">
                    RECIPE
                </span>
            @endif
            <h1 class="text-4xl md:text-5xl font-bold font-heading text-ink-primary tracking-tight leading-tight">
                {{ $recipe->title }}
            </h1>
            @if ($recipe->description)
                <p class="mt-4 text-base md:text-lg text-ink-secondary max-w-2xl">
                    {{ $recipe->description }}
                </p>
            @endif

            {{-- Meta-card row --}}
            <div class="mt-6 grid grid-cols-2 md:grid-cols-4 gap-3">
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

            {{-- Allergen banner --}}
            @if (count($allergens) > 0)
                <div class="mt-5 rounded-xl bg-status-failed/5 border border-status-failed/20 p-3 md:p-4">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-status-failed">Allergens</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($allergens as $a)
                            @php
                                $isContains = $a['presence'] === 'contains';
                                $label = $isContains ? $a['name'] : 'May contain '.strtolower($a['name']);
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border {{ $isContains
                                ? 'bg-status-failed/10 text-status-failed border-status-failed/30'
                                : 'bg-status-warning/10 text-status-warning border-status-warning/40 border-dashed' }}">
                                {{ $label }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Source link --}}
            @if ($recipe->source_url)
                <p class="mt-5 text-xs text-ink-tertiary">
                    Originally from
                    <a href="{{ $recipe->source_url }}" target="_blank" rel="noopener noreferrer nofollow" class="font-medium text-accent-lavender-bold hover:underline">
                        {{ parse_url($recipe->source_url, PHP_URL_HOST) }}
                    </a>
                </p>
            @endif
        </div>
    </section>

    {{-- ─── Gallery (if multiple images) ─── --}}
    @if (count($images['extras']) > 0)
        <section class="px-4 md:px-8 -mt-2 mb-8 max-w-3xl mx-auto">
            <div class="flex gap-2 overflow-x-auto pb-2">
                @foreach ($images['extras'] as $img)
                    <div class="shrink-0 w-24 h-24 md:w-32 md:h-32 rounded-lg overflow-hidden bg-surface-sunken border border-border-subtle">
                        <img src="{{ $img['url'] }}" alt="" class="w-full h-full object-cover" />
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ─── Body: ingredients + instructions ─── --}}
    <section class="px-4 md:px-8 py-8 md:py-12 max-w-3xl mx-auto">
        <div class="grid md:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)] gap-8 md:gap-12">
            {{-- Ingredients --}}
            <aside class="md:sticky md:top-6 md:self-start">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-tertiary">Ingredients</h2>
                <ul class="mt-3 space-y-2.5">
                    @forelse ($recipe->ingredients as $ing)
                        <li class="flex gap-2 text-[15px] leading-relaxed text-ink-primary">
                            <span class="text-ink-tertiary mt-1.5 shrink-0">•</span>
                            <span>
                                @if ($ing->quantity)
                                    <span class="font-semibold">{{ rtrim(rtrim((string) $ing->quantity, '0'), '.') }}</span>
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
            </aside>

            {{-- Instructions --}}
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
                                <span class="shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-full bg-accent-lavender-soft text-accent-lavender-bold text-sm font-bold">
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
    <section class="px-4 md:px-8 pb-10 max-w-3xl mx-auto">
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
