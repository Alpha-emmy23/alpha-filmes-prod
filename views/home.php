<?php

declare(strict_types=1);

$movies = $movieController;
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$assetUrl = static function (string $path): string {
    if ($path === '' || preg_match('~\A(?:[a-z][a-z\d+.-]*:|//|/)~i', $path) === 1) {
        return $path;
    }

    return APP_BASE_PATH . ltrim($path, '/');
};

if (($_GET['ajax'] ?? null) === 'search') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $query = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
        echo json_encode(
            ['movies' => $movies->searchMovies($query)],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );
    } catch (RuntimeException $exception) {
        error_log('Homepage search endpoint failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Search is temporarily unavailable. Please try again.']);
    }
    exit;
}

try {
    $content = $movies->homepageData();
} catch (RuntimeException $exception) {
    http_response_code(500);
    exit('The movie library is temporarily unavailable. Please try again later.');
}
$featuredSlides = $content['featured_movies'];
$featured = $featuredSlides[0] ?? null;
$user = $movies->currentUser();
$renderCard = static function (array $movie) use ($escape, $assetUrl): void {
    $watchUrl = APP_BASE_PATH . 'index.php?page=watch&id=' . (int) $movie['id'];
    $poster = $assetUrl(is_string($movie['poster_image'] ?? null) ? $movie['poster_image'] : '');
    $backdrop = $assetUrl(is_string($movie['backdrop_image'] ?? null) ? $movie['backdrop_image'] : '');
    $hoverImage = $backdrop !== '' ? $backdrop : $poster;
    ?>
    <a href="<?= $escape($watchUrl) ?>" data-hover-backdrop="<?= $escape($hoverImage) ?>" class="movie-card magic-accent group relative block min-w-0 rounded-xl border border-white/5 bg-neutral-900/40 p-1 backdrop-blur-sm transition-transform duration-500 hover:z-10 hover:scale-105 hover:border-[var(--ambient-color)]/60 hover:shadow-[0_0_30px_var(--ambient-color)]">
        <div class="poster-frame poster-shimmer relative aspect-[2/3] w-full overflow-hidden rounded-lg shadow-[0_4px_15px_rgba(0,0,0,0.5)]">
            <?php if ($poster !== ''): ?>
                <img src="<?= $escape($poster) ?>" alt="<?= $escape($movie['title']) ?> poster" loading="lazy" class="poster-image absolute inset-0 h-full w-full rounded-xl object-cover opacity-0 transition-all duration-500 group-hover:scale-105">
            <?php else: ?>
                <div class="poster-fallback absolute inset-0 flex items-end rounded-xl bg-gradient-to-br from-rose-950 via-zinc-900 to-zinc-950 p-4">
                    <span class="text-lg font-bold"><?= $escape($movie['title']) ?></span>
                </div>
            <?php endif; ?>
            <span class="play-badge absolute right-2 top-2 z-10 rounded-md border border-white/20 bg-black/70 px-2 py-1 text-[9px] font-extrabold uppercase tracking-wider text-white">Play</span>
        </div>
        <div class="px-1 pb-1 pt-3">
            <p class="movie-card-title line-clamp-1 min-h-6 font-semibold text-white transition-colors duration-300"><?= $escape($movie['title']) ?></p>
            <div class="mt-2 flex min-h-6 flex-wrap items-center gap-1.5">
                <?php if (!empty($movie['release_year'])): ?>
                    <span class="text-xs text-zinc-400"><?= $escape((string) $movie['release_year']) ?></span>
                <?php endif; ?>
                <?php if (($movie['category_name'] ?? '') !== ''): ?>
                    <span class="rounded-full border border-white/10 bg-white/[0.04] px-2 py-0.5 text-[10px] font-medium text-zinc-300"><?= $escape($movie['category_name']) ?></span>
                <?php endif; ?>
                <?php if (($movie['translator_name'] ?? '') !== ''): ?>
                    <span class="rounded-full border border-rose-400/20 bg-rose-400/10 px-2 py-0.5 text-[10px] font-semibold text-rose-200">Dubbed · <?= $escape($movie['translator_name']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </a>
    <?php
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#09090b">
    <title><?= SITE_BRAND ?> | Stories worth staying in for</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes posterShimmer {
            from { background-position: 100% 50%; }
            to { background-position: -100% 50%; }
        }
        @keyframes playBadgePulse {
            0%, 100% { box-shadow: 0 0 5px var(--ambient-color); }
            50% { box-shadow: 0 0 14px var(--ambient-color); }
        }
        .poster-shimmer {
            background: linear-gradient(100deg, #09090b 20%, #26262b 45%, #09090b 70%);
            background-size: 200% 100%;
            animation: posterShimmer 1.8s linear infinite;
        }
        .poster-shimmer.is-ready { animation: none; background: #18181b; }
        .poster-image.is-loaded { opacity: 1; }
        .poster-image.is-failed { display: none; }
        .movie-card.poster-ready { animation: cardReveal 500ms ease both; }
        @keyframes cardReveal {
            from { opacity: .72; }
            to { opacity: 1; }
        }
        @keyframes heroFadeInUp {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes cardAura {
            0%, 100% { box-shadow: 0 0 14px var(--ambient-color); }
            50% { box-shadow: 0 0 30px var(--ambient-color); }
        }
        @property --laser-angle {
            syntax: '<angle>';
            inherits: false;
            initial-value: 0deg;
        }
        @keyframes laserTrail {
            to { --laser-angle: 360deg; }
        }
        .hero-animate-in { animation: heroFadeInUp 650ms cubic-bezier(.22, 1, .36, 1) both; }
        .movie-card { transition: border-color 500ms ease, box-shadow 500ms ease, transform 500ms ease; }
        .movie-card:hover { animation: cardAura 2.8s ease-in-out infinite; }
        .movie-card.magic-accent.is-tilting:hover {
            z-index: 20;
            transform: perspective(1000px) rotateX(var(--tilt-x, 0deg)) rotateY(var(--tilt-y, 0deg)) scale(1.06);
            transform-style: preserve-3d;
            will-change: transform;
            transition: transform 80ms linear, border-color 500ms ease, box-shadow 500ms ease;
        }
        .movie-card-sheen {
            position: absolute;
            z-index: 5;
            inset: 0;
            border-radius: inherit;
            background: radial-gradient(circle at var(--sheen-x, 50%) var(--sheen-y, 50%), rgba(255,255,255,.3), rgba(255,255,255,.06) 16%, transparent 48%);
            opacity: 0;
            pointer-events: none;
            mix-blend-mode: screen;
            transition: opacity 180ms ease;
        }
        .movie-card.is-tilting .movie-card-sheen { opacity: .8; }
        .movie-card.is-tilting .poster-frame { transform: translateZ(18px); }
        .poster-frame { transition: transform 120ms ease; }
        .movie-card-sheen { transform: translateZ(24px); }
        .movie-card::before {
            position: absolute;
            z-index: 2;
            inset: 0;
            padding: 1px;
            border-radius: inherit;
            background: conic-gradient(from var(--laser-angle), transparent 0deg 278deg, var(--magic-logo-color) 300deg, #fff 310deg, transparent 326deg 360deg);
            content: '';
            opacity: 0;
            pointer-events: none;
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            mask-composite: exclude;
            transition: opacity 700ms ease-in-out;
        }
        .movie-card:hover::before {
            opacity: 1;
            animation: laserTrail 1.4s ease-in-out infinite;
        }
        .movie-card > * { position: relative; z-index: 1; }
        .hero-ambient-cloud {
            background: radial-gradient(circle, var(--ambient-color) 0%, transparent 70%);
            transition: background 3s cubic-bezier(.4, 0, .2, 1);
        }
        #hero-sparks {
            position: absolute;
            z-index: 15;
            inset-inline: 0;
            bottom: 0;
            width: 100%;
            height: 66.6667%;
            pointer-events: none;
        }
        .search-input {
            border-color: color-mix(in srgb, var(--ambient-color) 24%, rgba(255,255,255,.1));
            transition: border-color 500ms ease, box-shadow 500ms ease, background-color 500ms ease;
        }
        .search-input:focus {
            border-color: var(--ambient-color);
            box-shadow: 0 0 15px var(--ambient-color);
        }
        .play-badge { animation: playBadgePulse 2.4s ease-in-out infinite; }
        .movie-card:hover .movie-card-title { color: var(--magic-logo-color, #ff334f); }
        #movie-hover-backdrop {
            position: fixed;
            inset: -1.5%;
            z-index: -2;
            pointer-events: none;
            opacity: 0;
            transition: opacity 1s ease;
        }
        #movie-hover-backdrop.is-visible { opacity: .8; }
        #movie-hover-backdrop img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            filter: blur(12px) brightness(.3);
            transform: scale(1.03);
            opacity: 0;
            transition: opacity 1s ease;
        }
        #movie-hover-backdrop img.is-active { opacity: 1; }
        @media (prefers-reduced-motion: reduce) {
            .poster-shimmer, .play-badge, .hero-ambient-cloud { animation: none; }
            .movie-card.poster-ready, .hero-animate-in, .movie-card:hover, .movie-card:hover::before { animation: none; }
            .movie-card, .poster-frame, .movie-card-sheen { transition: none; }
            .movie-card.is-tilting, .movie-card.is-tilting .poster-frame { transform: none; }
            .movie-card-sheen { display: none; }
            .movie-card::before { transition: none; }
            #movie-hover-backdrop, #movie-hover-backdrop img { transition: none; }
        }
    </style>
</head>
<body class="cinematic-site-background min-h-screen text-zinc-100 antialiased selection:bg-rose-500/40">
    <header class="glass-nav-header top-0 z-40 border-b border-white/5 bg-black/30 backdrop-blur-xl">
        <nav class="mx-auto flex max-w-[1600px] items-center gap-5 px-5 py-4 sm:px-8 lg:px-12">
            <div class="nav-glow-cluster shrink-0 rounded-full">
                <a href="<?= APP_BASE_PATH ?>index.php?page=home" class="flex items-center gap-3" aria-label="<?= SITE_BRAND ?> home">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-500 text-lg font-black shadow-lg shadow-rose-950/40">A</span>
                    <span class="brand-logo text-xl font-extrabold tracking-tight"><?= SITE_BRAND ?></span>
                </a>
            </div>
            <div class="hidden items-center gap-6 pl-4 text-sm text-zinc-400 lg:flex">
                <a href="#browse" class="transition hover:text-white">Discover</a>
                <a href="#categories" class="transition hover:text-white">Categories</a>
                <a href="#translators" class="transition hover:text-white">Abasobanuzi</a>
            </div>
            <div class="nav-glow-cluster ml-auto grid w-full max-w-3xl grid-cols-1 gap-2 rounded-full px-1 py-1 sm:grid-cols-3">
            <label class="relative min-w-0">
                <span class="sr-only">Search movies</span>
                <input id="movie-search" type="search" autocomplete="off" placeholder="Shakisha filme..." class="search-input w-full rounded-full border border-white/10 bg-white/5 px-5 py-2 text-sm text-white backdrop-blur-md outline-none transition-all duration-500 placeholder:text-gray-400 focus:border-[var(--ambient-color)] focus:shadow-[0_0_15px_var(--ambient-color)]">
            </label>
            <label class="min-w-0">
                <span class="sr-only">Filter by category</span>
                <select id="movie-category" class="search-input w-full rounded-full border border-white/10 bg-black/40 px-4 py-2 text-sm text-white backdrop-blur-md outline-none transition-all duration-500 focus:border-[var(--ambient-color)] focus:shadow-[0_0_15px_var(--ambient-color)]">
                    <option value="" class="bg-zinc-900">Ibyiciro byose</option>
                    <?php foreach ($content['filter_categories'] as $category): ?>
                        <option value="<?= (int) $category['id'] ?>" class="bg-zinc-900"><?= $escape($category['category_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="min-w-0">
                <span class="sr-only">Filter by translator</span>
                <select id="movie-translator" class="search-input w-full rounded-full border border-white/10 bg-black/40 px-4 py-2 text-sm text-white backdrop-blur-md outline-none transition-all duration-500 focus:border-[var(--ambient-color)] focus:shadow-[0_0_15px_var(--ambient-color)]">
                    <option value="" class="bg-zinc-900">Abasobanuzi bose</option>
                    <?php foreach ($content['filter_translators'] as $translator): ?>
                        <option value="<?= (int) $translator['id'] ?>" class="bg-zinc-900"><?= $escape($translator['translator_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            </div>
            <?php if ($user !== null): ?>
                <span class="hidden text-sm text-zinc-400 sm:block">Hi, <span class="font-semibold text-white"><?= $escape((string) ($user['username'] ?? '')) ?></span></span>
                <?php if (($user['role'] ?? null) === 'admin'): ?>
                    <a href="<?= APP_BASE_PATH ?>index.php?page=admin_dashboard" class="hidden rounded-full border border-white/10 px-4 py-2 text-sm font-medium text-zinc-300 transition hover:border-white/25 hover:text-white sm:inline-flex">Studio</a>
                <?php endif; ?>
            <?php else: ?>
                <a href="<?= APP_BASE_PATH ?>index.php?page=login" class="hidden rounded-full border border-white/10 px-4 py-2 text-sm font-semibold text-zinc-200 transition hover:border-white/25 hover:bg-white/5 sm:inline-flex">Sign in</a>
            <?php endif; ?>
        </nav>
    </header>

    <main class="pb-20">
        <section class="relative h-[75vh] w-full select-none overflow-hidden bg-[#111]">
            <?php if (is_array($featured)): ?>
                <img id="hero-backdrop" src="<?= $escape($assetUrl((string) (($featured['backdrop_image'] ?? '') ?: ($featured['poster_image'] ?? '')))) ?>" alt="" class="absolute right-0 top-0 z-0 h-full w-2/3 object-cover object-center">
            <?php else: ?>
                <div id="hero-backdrop" class="absolute right-0 top-0 z-0 h-full w-2/3 bg-[radial-gradient(ellipse_at_70%_28%,rgba(190,24,93,.3),transparent_65%)]"></div>
            <?php endif; ?>
            <div class="absolute inset-0 z-10 bg-gradient-to-r from-black via-black/85 to-transparent"></div>
            <div aria-hidden="true" class="hero-ambient-cloud absolute -left-10 -top-10 z-[16] h-96 w-96 rounded-full opacity-20 mix-blend-screen blur-[80px] animate-pulse"></div>
            <canvas id="hero-sparks" aria-hidden="true"></canvas>

            <div id="hero-copy" class="absolute inset-0 z-20 flex flex-col justify-center items-start text-left px-12 md:px-20 max-w-3xl space-y-4 h-full transition duration-500">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-gray-300">Featured on <?= SITE_BRAND ?></p>
                <?php if (is_array($featured)): ?>
                    <h1 class="hero-animated hero-animate-in magic-accent line-clamp-2 bg-gradient-to-r from-white via-gray-200 to-gray-500 bg-clip-text text-4xl font-black leading-tight tracking-tight text-transparent drop-shadow-[0_0_24px_var(--magic-glow)] sm:text-6xl lg:text-7xl">
                        <span id="hero-title"><?= $escape($featured['title']) ?></span><span id="hero-year-title" class="text-2xl font-semibold sm:text-3xl lg:text-4xl"><?= !empty($featured['release_year']) ? ' (' . $escape((string) $featured['release_year']) . ')' : '' ?></span>
                    </h1>
                    <p id="hero-format" class="hero-animated hero-animate-in text-sm font-medium text-gray-400"><?= $featured['type'] === 'series' ? 'Series' : 'Movie' ?></p>
                    <div id="hero-metadata" class="hero-animated hero-animate-in flex flex-wrap items-center gap-2 text-xs font-semibold sm:text-sm">
                        <span class="rounded-full bg-red-600 px-3 py-1 text-white">NEW</span>
                        <span class="rounded-full border border-white/20 bg-white/10 px-3 py-1 text-amber-300">★ Featured</span>
                        <span id="hero-category" class="rounded-full border border-white/20 bg-white/10 px-3 py-1 text-white"><?= $escape(($featured['category_name'] ?? '') !== '' ? $featured['category_name'] : ucfirst($featured['type'])) ?></span>
                        <span class="rounded-full border border-white/20 bg-white/10 px-3 py-1 text-gray-200">Latest</span>
                    </div>
                    <div id="hero-translator" class="hero-animated hero-animate-in rounded-md border border-white/10 bg-black/40 px-4 py-2 text-sm text-gray-200">
                        Umusobanuzi: <?= $escape(($featured['translator_name'] ?? '') !== '' ? $featured['translator_name'] : SITE_BRAND . ' Original') ?>
                    </div>
                    <p id="hero-description" class="hero-animated hero-animate-in line-clamp-2 max-w-lg text-sm leading-relaxed tracking-wide text-gray-300 antialiased sm:text-base"><?= $escape((string) ($featured['description'] ?? 'Discover a new story, beautifully brought to life in Kinyarwanda.')) ?></p>
                    <a id="hero-watch-link" href="<?= APP_BASE_PATH ?>index.php?page=watch&amp;id=<?= (int) $featured['id'] ?>" class="flex items-center gap-2 rounded-lg bg-blue-600 px-6 py-3 font-medium text-white shadow-[inset_0_1px_0_rgba(255,255,255,.22),0_8px_30px_rgba(37,99,235,.28)] transition hover:scale-[1.02] hover:bg-blue-700 hover:shadow-[inset_0_1px_0_rgba(255,255,255,.3),0_10px_34px_rgba(37,99,235,.38)]">
                        <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.5a1 1 0 0 1 1.52-.85l9.12 5.5a1 1 0 0 1 0 1.7l-9.12 5.5A1 1 0 0 1 8 16.5z"></path></svg> Watch Now
                    </a>
                <?php else: ?>
                    <h1 id="hero-title" class="bg-gradient-to-r from-white via-gray-200 to-gray-500 bg-clip-text text-4xl font-black leading-tight tracking-tight text-transparent drop-shadow-[0_0_24px_var(--magic-glow)] sm:text-6xl">The next great story is yours to discover.</h1>
                    <p class="max-w-lg text-sm leading-relaxed tracking-wide text-gray-300 antialiased sm:text-base">Our library is getting ready for its close-up. Check back soon for films and series.</p>
                    <a href="<?= APP_BASE_PATH ?>index.php?page=register" class="flex items-center gap-2 rounded-lg bg-blue-600 px-6 py-3 font-medium text-white shadow-[inset_0_1px_0_rgba(255,255,255,.22),0_8px_30px_rgba(37,99,235,.28)] transition hover:scale-[1.02] hover:bg-blue-700">Join <?= SITE_BRAND ?></a>
                <?php endif; ?>
            </div>

            <?php if ($featuredSlides !== []): ?>
                <div id="hero-thumbnails" class="absolute bottom-6 right-12 z-30 flex max-w-xl flex-wrap items-center justify-end gap-4 overflow-visible">
                    <?php foreach ($featuredSlides as $index => $slide): ?>
                        <button type="button" data-hero-index="<?= (int) $index ?>" aria-label="Feature <?= $escape($slide['title']) ?>" class="hero-thumb magic-accent relative aspect-[2/3] w-20 cursor-pointer overflow-hidden rounded-lg border <?= $index === 0 ? 'border-white/80 opacity-100' : 'border-white/10 opacity-70' ?> shadow-md transition-all duration-300 hover:scale-110 md:w-24">
                            <?php if (!empty($slide['poster_image'])): ?>
                                <img src="<?= $escape($assetUrl((string) $slide['poster_image'])) ?>" alt="" class="h-full w-full object-cover">
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section id="search-results" class="mx-auto hidden max-w-[1600px] px-5 pt-12 sm:px-8 lg:px-12">
            <div class="mb-6 flex items-end justify-between">
                <div><p class="text-xs font-bold uppercase tracking-[0.22em] text-rose-400">Instant search</p><h2 id="search-heading" class="mt-2 text-2xl font-bold">Search results</h2></div>
                <p id="search-status" class="text-sm text-zinc-500"></p>
            </div>
            <div id="search-grid" class="filter-grid grid grid-cols-2 gap-6 opacity-100 transition-opacity duration-200 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6"></div>
        </section>

        <div id="browse" class="space-y-12">
            <?php if ($content['recommendations'] !== []): ?>
                <section class="mx-auto max-w-[1600px] px-5 pt-12 sm:px-8 lg:px-12">
                    <div class="mb-5"><p class="text-xs font-bold uppercase tracking-[0.22em] text-rose-400">Picked from your watch history</p><h2 class="mt-2 border-l-4 border-[var(--ambient-color)] pl-2 text-xl font-extrabold tracking-tight text-white md:text-2xl">Recommended for You</h2></div>
                    <div class="grid grid-cols-2 gap-6 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                        <?php foreach (array_slice($content['recommendations'], 0, 6) as $movie): ?><?php $renderCard($movie); ?><?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
            <?php if ($content['movies'] !== []): ?>
                <section class="mx-auto max-w-[1600px] px-5 pt-12 sm:px-8 lg:px-12">
                    <div class="mb-5 flex items-end justify-between">
                        <div><p class="text-xs font-bold uppercase tracking-[0.22em] text-rose-400">Fresh from the library</p><h2 class="mt-2 border-l-4 border-[var(--ambient-color)] pl-2 text-xl font-extrabold tracking-tight text-white md:text-2xl">Recently added</h2></div>
                        <span class="text-sm text-zinc-500"><?= count($content['movies']) ?> titles</span>
                    </div>
                    <div class="grid grid-cols-2 gap-6 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                        <?php foreach (array_slice($content['movies'], 0, 7) as $movie): ?><?php $renderCard($movie); ?><?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php foreach ($content['categories'] as $category => $items): ?>
                <section id="<?= $category === array_key_first($content['categories']) ? 'categories' : '' ?>" class="mx-auto max-w-[1600px] px-5 sm:px-8 lg:px-12">
                    <div class="mb-5"><p class="text-xs font-bold uppercase tracking-[0.22em] text-zinc-500">Explore by category</p><h2 class="mt-2 border-l-4 border-[var(--ambient-color)] pl-2 text-xl font-extrabold tracking-tight text-white md:text-2xl"><?= $escape($category) ?></h2></div>
                    <div class="grid grid-cols-2 gap-6 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                        <?php foreach ($items as $movie): ?><?php $renderCard($movie); ?><?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <?php foreach ($content['translators'] as $translator => $items): ?>
                <section id="<?= $translator === array_key_first($content['translators']) ? 'translators' : '' ?>" class="mx-auto max-w-[1600px] px-5 sm:px-8 lg:px-12">
                    <div class="mb-5"><p class="text-xs font-bold uppercase tracking-[0.22em] text-zinc-500">Brought to you by</p><h2 class="mt-2 border-l-4 border-[var(--ambient-color)] pl-2 text-xl font-extrabold tracking-tight text-white md:text-2xl"><?= $escape($translator) ?> <span class="text-base font-medium text-zinc-400">· Abasobanuzi</span></h2></div>
                    <div class="grid grid-cols-2 gap-6 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                        <?php foreach ($items as $movie): ?><?php $renderCard($movie); ?><?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </main>

    <footer class="border-t border-white/5 px-5 py-8 text-center text-sm text-zinc-600"><?= SITE_BRAND ?> <span class="px-1">·</span> Made for stories that bring us together.</footer>
    <script src="<?= APP_BASE_PATH ?>public/js/magic-experience.js" defer></script>
    <script src="<?= APP_BASE_PATH ?>public/js/cosmic-interactions.js" defer></script>
    <script>
        const appBasePath = <?= json_encode(APP_BASE_PATH, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const rootAssetUrl = (path) => path && !/^(?:[a-z][a-z\d+.-]*:|\/\/|\/)/i.test(path)
            ? `${appBasePath}${path.replace(/^\/+/, '')}`
            : path;
        const heroSlides = <?= json_encode(
            array_map(static fn (array $movie): array => [
                'id' => (int) $movie['id'],
                'title' => $movie['title'],
                'description' => $movie['description'] ?? '',
                'category' => $movie['category_name'] ?? '',
                'translator' => $movie['translator_name'] ?? '',
                'year' => $movie['release_year'] ?? '',
                'type' => $movie['type'],
                'backdrop' => $movie['backdrop_image'] ?? $movie['poster_image'] ?? '',
                'poster' => $movie['poster_image'] ?? '',
            ], $featuredSlides),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?>;
        const heroTitle = document.getElementById('hero-title');
        const heroYearTitle = document.getElementById('hero-year-title');
        const heroCopy = document.getElementById('hero-copy');
        const heroImage = document.getElementById('hero-backdrop');
        const heroAnimated = [...document.querySelectorAll('.hero-animated')];
        const heroThumbs = [...document.querySelectorAll('.hero-thumb')];
        let activeHero = 0;
        let heroTimer;

        function selectHero(index) {
            if (!heroSlides.length || !heroTitle) return;
            activeHero = (index + heroSlides.length) % heroSlides.length;
            const slide = heroSlides[activeHero];
            heroCopy.classList.add('opacity-0', 'translate-y-2');
            heroAnimated.forEach((element) => element.classList.remove('hero-animate-in'));
            window.setTimeout(() => {
                heroTitle.textContent = slide.title;
                document.getElementById('hero-category').textContent = slide.category || (slide.type === 'series' ? 'Series' : 'Film');
                document.getElementById('hero-translator').textContent = `Umusobanuzi: ${slide.translator || <?= json_encode(SITE_BRAND . ' Original', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>}`;
                heroYearTitle.textContent = slide.year ? ` (${slide.year})` : '';
                document.getElementById('hero-format').textContent = slide.type === 'series' ? 'Series' : 'Movie';
                document.getElementById('hero-description').textContent = slide.description || 'Discover a new story, beautifully brought to life in Kinyarwanda.';
                document.getElementById('hero-watch-link').href = `${appBasePath}index.php?page=watch&id=${encodeURIComponent(slide.id)}`;
                if (heroImage && heroImage.tagName === 'IMG') {
                    heroImage.src = rootAssetUrl(slide.backdrop || slide.poster);
                }
                heroThumbs.forEach((thumb, thumbIndex) => {
                    thumb.classList.toggle('border-white/80', thumbIndex === activeHero);
                    thumb.classList.toggle('border-white/10', thumbIndex !== activeHero);
                    thumb.classList.toggle('opacity-100', thumbIndex === activeHero);
                    thumb.classList.toggle('opacity-70', thumbIndex !== activeHero);
                });
                heroAnimated.forEach((element) => {
                    void element.offsetWidth;
                    element.classList.add('hero-animate-in');
                });
                heroCopy.classList.remove('opacity-0', 'translate-y-2');
            }, 220);
        }

        function restartHeroTimer() {
            window.clearInterval(heroTimer);
            if (heroSlides.length > 1) {
                heroTimer = window.setInterval(() => selectHero(activeHero + 1), 7000);
            }
        }

        heroThumbs.forEach((thumb) => thumb.addEventListener('click', () => {
            selectHero(Number(thumb.dataset.heroIndex));
            restartHeroTimer();
        }));
        restartHeroTimer();

        const sparkCanvas = document.getElementById('hero-sparks');
        const sparkContext = sparkCanvas?.getContext('2d', {alpha: true});
        if (sparkCanvas && sparkContext && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            const sparks = [];
            const maxSparks = 42;
            let sparkWidth = 0;
            let sparkHeight = 0;
            let previousFrame = 0;
            let lastEmission = 0;
            let sparkFrame = 0;

            function resizeSparkCanvas() {
                const bounds = sparkCanvas.getBoundingClientRect();
                const ratio = Math.min(window.devicePixelRatio || 1, 2);
                sparkWidth = bounds.width;
                sparkHeight = bounds.height;
                sparkCanvas.width = Math.round(sparkWidth * ratio);
                sparkCanvas.height = Math.round(sparkHeight * ratio);
                sparkContext.setTransform(ratio, 0, 0, ratio, 0, 0);
            }

            function emitSpark() {
                if (sparks.length >= maxSparks) return;
                sparks.push({
                    x: Math.random() * sparkWidth,
                    y: sparkHeight + 4,
                    drift: (Math.random() - .5) * .35,
                    phase: Math.random() * Math.PI * 2,
                    speed: 12 + Math.random() * 24,
                    size: .7 + Math.random() * 1.8,
                    life: 0,
                    lifetime: 5 + Math.random() * 4
                });
            }

            function drawSparks(timestamp) {
                if (document.hidden) {
                    sparkFrame = 0;
                    previousFrame = 0;
                    return;
                }
                const elapsed = previousFrame ? Math.min((timestamp - previousFrame) / 1000, .05) : 0;
                previousFrame = timestamp;
                if (timestamp - lastEmission > 125) {
                    emitSpark();
                    lastEmission = timestamp;
                }

                sparkContext.clearRect(0, 0, sparkWidth, sparkHeight);
                const glow = getComputedStyle(document.documentElement).getPropertyValue('--magic-logo-color').trim() || '#fb7185';
                for (let index = sparks.length - 1; index >= 0; index -= 1) {
                    const spark = sparks[index];
                    spark.life += elapsed;
                    spark.phase += elapsed * 1.4;
                    spark.y -= spark.speed * elapsed;
                    spark.x += (spark.drift + Math.sin(spark.phase) * 3) * elapsed;
                    const fadeByHeight = Math.max(0, 1 - (sparkHeight - spark.y) / sparkHeight);
                    const fadeByLife = Math.max(0, 1 - spark.life / spark.lifetime);
                    const alpha = Math.min(fadeByHeight, fadeByLife) * .55;
                    if (alpha <= .01 || spark.y < 0 || spark.life >= spark.lifetime) {
                        sparks.splice(index, 1);
                        continue;
                    }
                    sparkContext.globalAlpha = alpha;
                    sparkContext.fillStyle = glow;
                    sparkContext.shadowColor = glow;
                    sparkContext.shadowBlur = 9;
                    sparkContext.beginPath();
                    sparkContext.arc(spark.x, spark.y, spark.size, 0, Math.PI * 2);
                    sparkContext.fill();
                }
                sparkContext.globalAlpha = 1;
                sparkContext.shadowBlur = 0;
                sparkFrame = window.requestAnimationFrame(drawSparks);
            }

            resizeSparkCanvas();
            window.addEventListener('resize', resizeSparkCanvas, {passive: true});
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && !sparkFrame) sparkFrame = window.requestAnimationFrame(drawSparks);
            });
            sparkFrame = window.requestAnimationFrame(drawSparks);
        }

        const searchInput = document.getElementById('movie-search');
        const categoryFilter = document.getElementById('movie-category');
        const translatorFilter = document.getElementById('movie-translator');
        const searchSection = document.getElementById('search-results');
        const searchGrid = document.getElementById('search-grid');
        const searchHeading = document.getElementById('search-heading');
        const searchStatus = document.getElementById('search-status');
        const browseSection = document.getElementById('browse');
        const hoverBackdrop = document.createElement('div');
        hoverBackdrop.id = 'movie-hover-backdrop';
        hoverBackdrop.setAttribute('aria-hidden', 'true');
        const hoverBackdropImages = [document.createElement('img'), document.createElement('img')];
        hoverBackdropImages.forEach((image) => hoverBackdrop.append(image));
        document.body.prepend(hoverBackdrop);
        const boundMovieCards = new WeakSet();
        let activeBackdropIndex = -1;
        let searchTimer;
        let requestController;

        function initializePosterImage(image) {
            const frame = image.closest('.poster-shimmer');
            const settle = (loaded) => {
                if (loaded) image.classList.add('is-loaded');
                else image.classList.add('is-failed');
                if (frame) frame.classList.add('is-ready');
                image.closest('.movie-card')?.classList.add('poster-ready');
            };
            image.addEventListener('load', () => settle(true), {once: true});
            image.addEventListener('error', () => settle(false), {once: true});
            if (image.complete) settle(image.naturalWidth > 0);
        }

        function bindMovieCard(card) {
            if (boundMovieCards.has(card)) return;
            boundMovieCards.add(card);
            card.addEventListener('mouseenter', () => {
                const source = card.dataset.hoverBackdrop;
                if (!source) return;
                const nextIndex = activeBackdropIndex === 0 ? 1 : 0;
                const nextImage = hoverBackdropImages[nextIndex];
                hoverBackdropImages.forEach((image) => image.classList.remove('is-active'));
                nextImage.src = source;
                requestAnimationFrame(() => nextImage.classList.add('is-active'));
                activeBackdropIndex = nextIndex;
                hoverBackdrop.classList.add('is-visible');
            });
            card.addEventListener('mouseleave', () => {
                hoverBackdrop.classList.remove('is-visible');
                hoverBackdropImages.forEach((image) => image.classList.remove('is-active'));
            });
        }

        document.querySelectorAll('.movie-card').forEach((card) => {
            bindMovieCard(card);
            card.querySelectorAll('.poster-image').forEach(initializePosterImage);
        });

        function createMovieCard(movie) {
            const link = document.createElement('a');
            link.href = `${appBasePath}index.php?page=watch&id=${encodeURIComponent(movie.id)}`;
            link.dataset.hoverBackdrop = rootAssetUrl(movie.backdrop_image || movie.poster_image || '');
            link.className = 'movie-card magic-accent group relative block min-w-0 rounded-xl border border-white/5 bg-neutral-900/40 p-1 backdrop-blur-sm transition-transform duration-500 hover:z-10 hover:scale-105 hover:border-[var(--ambient-color)]/60 hover:shadow-[0_0_30px_var(--ambient-color)]';
            const posterFrame = document.createElement('div');
            posterFrame.className = 'poster-frame poster-shimmer relative aspect-[2/3] w-full overflow-hidden rounded-lg shadow-[0_4px_15px_rgba(0,0,0,0.5)]';
            let posterImage = null;
            if (movie.poster_image) {
                const image = document.createElement('img');
                image.src = rootAssetUrl(movie.poster_image);
                image.alt = `${movie.title} poster`;
                image.loading = 'lazy';
                image.className = 'poster-image absolute inset-0 h-full w-full rounded-xl object-cover opacity-0 transition-all duration-500 group-hover:scale-105';
                posterImage = image;
                posterFrame.append(image);
            } else {
                const fallback = document.createElement('div');
                fallback.className = 'poster-fallback absolute inset-0 flex items-end rounded-xl bg-gradient-to-br from-rose-950 via-zinc-900 to-zinc-950 p-4';
                const fallbackTitle = document.createElement('span');
                fallbackTitle.className = 'text-lg font-bold';
                fallbackTitle.textContent = movie.title;
                posterFrame.classList.add('is-ready');
                fallback.append(fallbackTitle);
                posterFrame.append(fallback);
                link.classList.add('poster-ready');
            }
            const badge = document.createElement('span');
            badge.className = 'play-badge absolute right-2 top-2 z-10 rounded-md border border-white/20 bg-black/70 px-2 py-1 text-[9px] font-extrabold uppercase tracking-wider text-white';
            badge.textContent = 'Play';
            posterFrame.append(badge);
            const details = document.createElement('div');
            details.className = 'px-1 pb-1 pt-3';
            const title = document.createElement('p');
            title.className = 'movie-card-title line-clamp-1 min-h-6 font-semibold text-white transition-colors duration-300';
            title.textContent = movie.title;
            const metadata = document.createElement('div');
            metadata.className = 'mt-2 flex min-h-6 flex-wrap items-center gap-1.5';
            if (movie.release_year) {
                const year = document.createElement('span');
                year.className = 'text-xs text-zinc-400';
                year.textContent = movie.release_year;
                metadata.append(year);
            }
            if (movie.category_name) {
                const category = document.createElement('span');
                category.className = 'rounded-full border border-white/10 bg-white/[0.04] px-2 py-0.5 text-[10px] font-medium text-zinc-300';
                category.textContent = movie.category_name;
                metadata.append(category);
            }
            if (movie.translator_name) {
                const dubbed = document.createElement('span');
                dubbed.className = 'rounded-full border border-rose-400/20 bg-rose-400/10 px-2 py-0.5 text-[10px] font-semibold text-rose-200';
                dubbed.textContent = `Dubbed · ${movie.translator_name}`;
                metadata.append(dubbed);
            }
            details.append(title, metadata);
            link.append(posterFrame, details);
            if (posterImage) initializePosterImage(posterImage);
            bindMovieCard(link);
            return link;
        }

        function scheduleSearch() {
            clearTimeout(searchTimer);
            const query = searchInput.value.trim();
            const category = categoryFilter.value;
            const translator = translatorFilter.value;
            if (requestController) requestController.abort();
            if (query === '' && category === '' && translator === '') {
                searchSection.classList.add('hidden');
                browseSection.classList.remove('hidden');
                return;
            }
            searchSection.classList.remove('hidden');
            browseSection.classList.add('hidden');
            searchHeading.textContent = query !== '' ? `Results for “${query}”` : 'Filtered titles';
            searchStatus.textContent = 'Searching…';
            searchGrid.classList.add('opacity-50');
            searchTimer = setTimeout(async () => {
                requestController = new AbortController();
                try {
                    const params = new URLSearchParams({action: 'ajax_search', query, category, translator});
                    const response = await fetch(`${appBasePath}index.php?${params}`, {headers: {'Accept': 'application/json'}, signal: requestController.signal});
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.error || 'Search is temporarily unavailable.');
                    searchGrid.replaceChildren(...result.movies.map(createMovieCard));
                    searchStatus.textContent = result.movies.length ? `${result.movies.length} found` : 'No titles found';
                    if (!result.movies.length) {
                        const empty = document.createElement('p');
                        empty.className = 'col-span-full rounded-2xl border border-white/10 bg-white/[0.03] p-8 text-center text-zinc-400';
                        empty.textContent = 'No matches yet. Try another title, category, or translator.';
                        searchGrid.append(empty);
                    }
                    requestAnimationFrame(() => searchGrid.classList.remove('opacity-50'));
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        searchStatus.textContent = error.message;
                        searchGrid.classList.remove('opacity-50');
                    }
                }
            }, 220);
        }

        searchInput.addEventListener('input', scheduleSearch);
        categoryFilter.addEventListener('change', scheduleSearch);
        translatorFilter.addEventListener('change', scheduleSearch);
    </script>
</body>
</html>
