<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../controllers/MovieController.php';

$movies = new MovieController($pdo);
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$assetUrl = static function (string $path): string {
    if ($path === '' || preg_match('~\A(?:[a-z][a-z\d+.-]*:|//|/)~i', $path) === 1) {
        return $path;
    }

    return APP_BASE_PATH . ltrim($path, '/');
};
$user = $movies->currentUser();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_GET['action'] ?? null) === 'comment') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $result = $movies->addComment($_POST);
        http_response_code($result['status'] ?? ($result['ok'] ? 201 : 422));
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (RuntimeException $exception) {
        error_log('Comment endpoint failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Comments are temporarily unavailable. Please try again.']);
    }
    exit;
}

if (($_GET['ajax'] ?? null) === 'episode') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $movieId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $episodeId = isset($_GET['episode'])
        ? filter_var($_GET['episode'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : null;
    $server = is_string($_GET['server'] ?? null) ? $_GET['server'] : 'server_1';
    if ($movieId === false
        || $episodeId === false
        || !in_array($server, ['server_1', 'server_2', 'vip_hd'], true)) {
        http_response_code(422);
        echo json_encode(['error' => 'Choose a valid episode and streaming server.']);
        exit;
    }

    try {
        $streamData = $movies->episodeResponse($movieId, $episodeId, $server);
        if ($streamData === null) {
            http_response_code(404);
            echo json_encode(['error' => 'That title or episode could not be found.']);
        } else {
            echo json_encode($streamData, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        }
    } catch (RuntimeException $exception) {
        error_log('Episode AJAX request failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'This stream is temporarily unavailable.']);
    }
    exit;
}

$movieId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$episodeId = filter_var($_GET['episode'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($movieId === false) {
    http_response_code(404);
    exit('404 - Movie not found');
}

try {
    $watchData = $movies->streamData($movieId, $episodeId === false ? null : $episodeId);
} catch (RuntimeException $exception) {
    http_response_code(500);
    exit('This title is temporarily unavailable. Please try again later.');
}
if ($watchData === null) {
    http_response_code(404);
    exit('404 - Movie not found');
}

$movies->recordView((int) $movieId);
$movie = $watchData['movie'];
$downloadUrl = is_string($movie['download_url'] ?? null) ? trim($movie['download_url']) : '';
$downloadUrlParts = $downloadUrl !== '' ? parse_url($downloadUrl) : false;
if (!is_array($downloadUrlParts)
    || filter_var($downloadUrl, FILTER_VALIDATE_URL) === false
    || !in_array(strtolower((string) ($downloadUrlParts['scheme'] ?? '')), ['http', 'https'], true)
    || empty($downloadUrlParts['host'])) {
    $downloadUrl = '';
}
$selectedEpisode = $watchData['selected_episode'];
$commentsError = null;
try {
    $comments = $movies->commentsFor((int) $movie['id']);
} catch (RuntimeException $exception) {
    error_log('Watch page comments unavailable: ' . $exception->getMessage());
    $comments = [];
    $commentsError = 'Comments are temporarily unavailable. Please try again later.';
}
$csrfToken = $movies->csrfToken();
$playerUrl = is_array($watchData['stream']) ? $watchData['stream']['url'] : '';
$playerType = is_array($watchData['stream']) ? $watchData['stream']['type'] : '';
$activeServer = is_array($watchData['stream'])
    ? (string) (array_search($watchData['stream'], $watchData['servers'], true) ?: 'server_1')
    : 'server_1';
$serverLabels = ['server_1' => 'Server 1', 'server_2' => 'Server 2', 'vip_hd' => 'HD Server'];
$seasons = [];
foreach ($watchData['episodes'] as $episode) {
    $seasons[(int) $episode['season_number']] = true;
}
$seasons = array_keys($seasons);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#09090b">
    <title><?= $escape($movie['title']) ?> | <?= SITE_BRAND ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/plyr@3.7.8/dist/plyr.css">
    <script src="https://cdn.jsdelivr.net/npm/plyr@3.7.8/dist/plyr.min.js"></script>
    <style>
        :root { --plyr-color-main: var(--ambient-color); }
        .plyr {
            height: 100%;
            --plyr-color-main: var(--ambient-color);
            --plyr-video-control-color: #fff;
            --plyr-video-control-color-hover: #fff;
            --plyr-video-control-background-hover: color-mix(in srgb, var(--ambient-color) 70%, transparent);
            --plyr-menu-background: rgba(9, 9, 11, .96);
            --plyr-menu-color: #f4f4f5;
            --plyr-menu-border-color: rgba(255, 255, 255, .1);
            --plyr-tooltip-background: rgba(9, 9, 11, .96);
            --plyr-tooltip-color: #fff;
            --plyr-control-radius: 8px;
            --plyr-control-spacing: 10px;
        }
        .plyr__video-wrapper { height: 100%; }
        .plyr--video .plyr__controls {
            background: linear-gradient(transparent, rgba(0, 0, 0, .88));
            padding: 32px 12px 10px;
        }
        .plyr__control--overlaid {
            background: var(--ambient-color);
            box-shadow: 0 0 28px color-mix(in srgb, var(--ambient-color) 48%, transparent);
        }
        .plyr__control--overlaid:hover { background: var(--ambient-color); }
        .plyr__progress input[type="range"],
        .plyr__volume input[type="range"] { color: var(--ambient-color); }
        .plyr__progress__buffer { color: rgba(255, 255, 255, .25); }
        @keyframes episodeShimmer {
            from { background-position: 100% 50%; }
            to { background-position: -100% 50%; }
        }
        @keyframes episodeBadgePulse {
            0%, 100% { box-shadow: 0 0 4px var(--ambient-color); }
            50% { box-shadow: 0 0 10px var(--ambient-color); }
        }
        @property --episode-laser-angle {
            syntax: '<angle>';
            inherits: false;
            initial-value: 0deg;
        }
        @keyframes episodeLaserTrail {
            to { --episode-laser-angle: 360deg; }
        }
        .episode-thumbnail {
            background: linear-gradient(100deg, #09090b 20%, #26262b 45%, #09090b 70%);
            background-size: 200% 100%;
            animation: episodeShimmer 1.8s linear infinite;
        }
        .episode-thumbnail.is-ready { animation: none; background: #18181b; }
        .episode-thumbnail-image { opacity: 0; transition: opacity 500ms ease; }
        .episode-thumbnail-image.is-loaded { opacity: 1; }
        .episode-thumbnail-image.is-failed { display: none; }
        .episode-badge { animation: episodeBadgePulse 2.4s ease-in-out infinite; }
        .episode-card {
            position: relative;
            isolation: isolate;
            transition: border-color 500ms ease, box-shadow 500ms ease, background-color 300ms ease;
        }
        .episode-card::before {
            position: absolute;
            z-index: -1;
            inset: -1px;
            padding: 1px;
            border-radius: inherit;
            background: conic-gradient(from var(--episode-laser-angle), transparent 0deg 278deg, var(--magic-logo-color, #fb7185) 300deg, #fff 310deg, transparent 326deg 360deg);
            content: '';
            opacity: 0;
            pointer-events: none;
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            mask-composite: exclude;
            transition: opacity 700ms ease-in-out;
        }
        .episode-card:hover {
            border-color: var(--ambient-color);
            box-shadow: 0 0 18px var(--ambient-color);
        }
        .episode-card:hover::before {
            opacity: 1;
            animation: episodeLaserTrail 1.4s ease-in-out infinite;
        }
        .download-action {
            border: 1px solid color-mix(in srgb, var(--ambient-color) 60%, transparent);
            background-color: color-mix(in srgb, var(--ambient-color) 20%, transparent);
            box-shadow: 0 0 15px var(--ambient-color);
            transition: background-color 300ms ease, box-shadow 300ms ease, border-color 3s cubic-bezier(.4, 0, .2, 1), transform 300ms ease;
        }
        .download-action:hover {
            background-color: color-mix(in srgb, var(--ambient-color) 40%, transparent);
            box-shadow: 0 0 22px var(--ambient-color);
        }
        .download-action[aria-disabled="true"] {
            cursor: not-allowed;
            opacity: .6;
        }
        .player-ambient-glow {
            position: absolute;
            z-index: 0;
            inset: 1.5rem 2%;
            border-radius: 2rem;
            background: var(--ambient-color);
            filter: blur(55px);
            opacity: .3;
            transition: background 3s cubic-bezier(.4, 0, .2, 1), opacity 3s ease, filter 3s ease;
            pointer-events: none;
        }
        #cinema-overlay {
            position: fixed;
            z-index: 40;
            inset: 0;
            background: rgba(0, 0, 0, .92);
            opacity: 0;
            pointer-events: none;
            transition: opacity 400ms ease;
        }
        body.cinema-mode #cinema-overlay { opacity: 1; pointer-events: auto; }
        .cinema-focus { position: relative; z-index: 50; }
        #player-utilities { position: relative; z-index: 50; }
        @media (prefers-reduced-motion: reduce) {
            .episode-thumbnail, .episode-badge, .episode-card:hover::before { animation: none; }
            .episode-card { transition: none; }
            .episode-card::before { transition: none; }
            #cinema-overlay, .player-ambient-glow { transition: none; }
        }
    </style>
</head>
<body class="cinematic-site-background min-h-screen text-zinc-100 antialiased">
    <header class="glass-nav-header top-0 z-40 border-b border-white/5 bg-black/30 backdrop-blur-xl">
        <nav class="mx-auto flex max-w-[1600px] items-center justify-between px-5 py-4 sm:px-8 lg:px-12">
            <div class="nav-glow-cluster rounded-full">
                <a href="<?= APP_BASE_PATH ?>index.php?page=home" class="flex items-center gap-3" aria-label="Back to <?= SITE_BRAND ?> home">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-500 text-lg font-black">A</span>
                    <span class="brand-logo text-xl font-extrabold tracking-tight"><?= SITE_BRAND ?></span>
                </a>
            </div>
            <a href="<?= APP_BASE_PATH ?>index.php?page=home" class="inline-flex items-center gap-2 text-sm font-medium text-zinc-400 transition hover:text-white">
                <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"></path></svg> Back to browse
            </a>
        </nav>
    </header>
    <div id="cinema-overlay" aria-hidden="true"></div>

    <main class="mx-auto max-w-[1600px] px-4 pb-20 pt-7 sm:px-8 lg:px-12 lg:pt-10">
        <div class="grid items-start gap-8 xl:grid-cols-[minmax(0,7fr)_minmax(320px,3fr)]">
            <div class="min-w-0">
                <div class="relative">
                    <div class="player-ambient-glow" aria-hidden="true"></div>
                    <section id="player-wrapper" class="cinema-focus relative aspect-video w-full overflow-hidden rounded-2xl border border-white/5 bg-black shadow-[0_10px_50px_rgba(0,0,0,0.8)]">
                        <div id="player-frame" class="relative h-full w-full bg-[radial-gradient(ellipse_at_center,rgba(190,24,93,.12),transparent_55%),#030304]" data-type="<?= $escape($playerType) ?>" data-url="<?= $escape($playerUrl) ?>">
                            <div id="player-content" class="absolute inset-0">
                                <?php if ($playerType === 'embed'): ?>
                                    <iframe src="<?= $escape($playerUrl) ?>" title="<?= $escape($movie['title']) ?> video player" class="h-full w-full" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                                <?php elseif ($playerType === 'video' || $playerType === 'hls'): ?>
                                    <video class="h-full w-full bg-black" controls playsinline preload="metadata" poster="<?= $escape($assetUrl((string) ($movie['poster_image'] ?? ''))) ?>">
                                        <?php if ($playerType === 'video'): ?><source src="<?= $escape($playerUrl) ?>"><?php endif; ?>
                                        Your browser does not support HTML5 video.
                                    </video>
                                <?php else: ?>
                                    <div class="flex h-full flex-col items-center justify-center px-6 text-center">
                                        <span class="flex h-16 w-16 items-center justify-center rounded-full border border-white/10 bg-white/5 text-rose-300">
                                            <svg aria-hidden="true" class="h-7 w-7" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.5a1 1 0 0 1 1.52-.85l9.12 5.5a1 1 0 0 1 0 1.7l-9.12 5.5A1 1 0 0 1 8 16.5z"></path></svg>
                                        </span>
                                        <p class="mt-5 text-lg font-semibold"><?= $movie['type'] === 'series' ? 'Episode stream unavailable' : 'Stream coming soon' ?></p>
                                        <p class="mt-2 max-w-md text-sm leading-6 text-zinc-400">Choose an available streaming server or check back when a source is added.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>
                </div>

                <div id="player-utilities" class="cinema-focus mt-3 flex flex-wrap items-center gap-2">
                    <button id="cinema-toggle" type="button" aria-pressed="false" class="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/[0.04] px-3 py-2 text-xs font-semibold text-zinc-200 transition hover:border-[var(--ambient-color)]/60 hover:bg-white/[0.08]">
                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7V4h3M21 7V4h-3M3 17v3h3m15-3v3h-3M7 12h10"></path></svg>
                        Cinema mode
                    </button>
                    <button id="report-stream" type="button" class="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/[0.04] px-3 py-2 text-xs font-semibold text-zinc-300 transition hover:border-amber-300/40 hover:text-amber-100">Report stream / Fix server</button>
                    <p id="stream-report-feedback" role="status" class="text-xs text-zinc-400"></p>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2" aria-label="Streaming servers">
                    <span class="mr-2 text-xs font-semibold uppercase tracking-wider text-zinc-500">Watch on</span>
                    <?php foreach ($serverLabels as $serverKey => $serverLabel): ?>
                        <?php $serverAvailable = isset($watchData['servers'][$serverKey]); ?>
                        <button type="button" data-server="<?= $escape($serverKey) ?>" <?= $serverAvailable ? '' : 'disabled' ?>
                                class="server-button rounded-lg border px-4 py-2 text-xs font-bold transition <?= $serverKey === $activeServer && $serverAvailable ? 'border-rose-400/40 bg-rose-400/10 text-rose-200' : 'border-white/10 bg-white/[0.03] text-zinc-300 hover:border-white/20' ?> disabled:cursor-not-allowed disabled:opacity-35">
                            <?= $escape($serverLabel) ?>
                        </button>
                    <?php endforeach; ?>
                    <p id="stream-feedback" role="status" class="ml-auto text-xs text-zinc-500"></p>
                </div>

                <section class="mt-7">
                    <h1 id="watch-title" class="magic-accent mb-2 text-3xl font-extrabold tracking-tight text-white md:text-4xl"><?= $escape($movie['title']) ?></h1>
                    <p id="watch-episode-title" class="mb-3 text-sm font-semibold text-zinc-400">
                        <?php if ($selectedEpisode !== null): ?>
                            Season <?= (int) $selectedEpisode['season_number'] ?> · Episode <?= (int) $selectedEpisode['episode_number'] ?><?= !empty($selectedEpisode['episode_title']) ? ' — ' . $escape($selectedEpisode['episode_title']) : '' ?>
                        <?php endif; ?>
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <span id="watch-category" class="rounded-full border border-white/10 bg-white/[0.05] px-3 py-1 text-xs font-semibold text-zinc-200"><?= $escape(($movie['category_name'] ?? '') !== '' ? $movie['category_name'] : ($movie['type'] === 'series' ? 'Series' : 'Film')) ?></span>
                        <?php if (!empty($movie['release_year'])): ?>
                            <span class="rounded-full border border-white/10 bg-white/[0.05] px-3 py-1 text-xs text-zinc-300"><?= $escape((string) $movie['release_year']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($movie['runtime_minutes']) && (int) $movie['runtime_minutes'] > 0): ?>
                            <span class="rounded-full border border-white/10 bg-white/[0.05] px-3 py-1 text-xs text-zinc-300"><?= (int) $movie['runtime_minutes'] ?> min</span>
                        <?php endif; ?>
                        <span id="watch-stream-type" class="rounded-full border border-white/10 bg-white/[0.05] px-3 py-1 text-xs font-semibold text-zinc-300"><?= $escape(match ($playerType) { 'hls' => 'HLS stream', 'video' => 'Video stream', 'embed' => 'Embedded stream', default => 'Stream pending' }) ?></span>
                        <?php if (($movie['translator_name'] ?? '') !== ''): ?>
                            <span class="magic-accent rounded-full border border-[var(--ambient-color)]/40 bg-[var(--ambient-color)]/10 px-3 py-1 text-xs font-semibold text-white">Umusobanuzi: <?= $escape($movie['translator_name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div id="watch-description" class="mt-4 rounded-2xl border border-white/5 bg-white/[0.03] p-5 text-base leading-relaxed text-gray-300 backdrop-blur-md antialiased"><?= $escape((string) ($movie['description'] ?? '')) ?></div>
                </section>

                <div class="mt-5 w-full select-none">
                    <a href="<?= $escape($downloadUrl !== '' ? $downloadUrl : '#download-unavailable') ?>"
                       data-download-url="<?= $escape($downloadUrl) ?>"
                       <?= $downloadUrl !== '' ? 'target="_blank" rel="noopener noreferrer"' : 'aria-disabled="true" tabindex="-1" title="Download link is not configured for this title"' ?>
                       class="download-action inline-flex w-auto max-w-xs cursor-pointer select-none items-center justify-center space-x-3 rounded-xl border border-[var(--ambient-color)]/60 bg-[var(--ambient-color)]/20 px-6 py-3 text-white shadow-[0_0_15px_var(--ambient-color)] transition-all duration-300 hover:scale-105 hover:bg-[var(--ambient-color)]/40 focus:outline-none focus:ring-2 focus:ring-[var(--ambient-color)] focus:ring-offset-2 focus:ring-offset-zinc-950">
                        <svg class="h-5 w-5 shrink-0" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                        </svg>
                        <span class="whitespace-nowrap tracking-wide">Shitsa Filime</span>
                    </a>
                </div>

                <section class="mt-10 border-t border-white/10 pt-8">
                    <div class="flex items-center justify-between border-b border-white/10 pb-5">
                        <div><p class="text-xs font-bold uppercase tracking-[0.2em] text-rose-400">The conversation</p><h2 class="mt-2 text-2xl font-bold">Comments <span class="text-zinc-500">(<?= count($comments) ?>)</span></h2></div>
                        <span class="hidden text-sm text-zinc-500 sm:inline">Keep it kind. Keep it about the film.</span>
                    </div>
                    <?php if ($commentsError !== null): ?>
                        <p role="status" class="mt-5 rounded-xl border border-amber-400/20 bg-amber-400/5 px-4 py-3 text-sm text-amber-200"><?= $escape($commentsError) ?></p>
                    <?php endif; ?>
                    <?php if ($user !== null): ?>
                        <form id="comment-form" class="mt-6">
                            <input type="hidden" name="movie_id" value="<?= (int) $movie['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                            <label for="comment" class="mb-2 block text-sm font-medium text-zinc-200">Add to the conversation as <?= $escape((string) ($user['username'] ?? '')) ?></label>
                            <textarea id="comment" name="comment" required maxlength="2000" rows="3" placeholder="What did you think?" class="w-full resize-y rounded-2xl border border-white/10 bg-zinc-900/70 px-4 py-3 text-sm text-white outline-none transition placeholder:text-zinc-600 focus:border-rose-400/60"></textarea>
                            <div class="mt-3 flex items-center justify-between gap-4">
                                <p id="comment-feedback" role="status" class="text-sm text-zinc-500"></p>
                                <button type="submit" class="rounded-full bg-rose-500 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-rose-400 disabled:cursor-not-allowed disabled:opacity-60">Post comment</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="mt-6 flex flex-col justify-between gap-4 rounded-2xl border border-white/10 bg-white/[0.03] p-5 sm:flex-row sm:items-center">
                            <p class="text-sm text-zinc-300">Sign in to share what you thought about this story.</p>
                            <a href="<?= APP_BASE_PATH ?>index.php?page=login" class="shrink-0 rounded-full border border-white/15 px-4 py-2 text-sm font-semibold transition hover:bg-white/5">Sign in</a>
                        </div>
                    <?php endif; ?>
                    <div id="comment-list" class="mt-8 divide-y divide-white/5">
                        <?php if ($comments === []): ?>
                            <p id="empty-comments" class="py-8 text-sm text-zinc-500">No comments yet. Be the first to share your thoughts.</p>
                        <?php else: ?>
                            <?php foreach ($comments as $comment): ?>
                                <article class="flex gap-4 py-5">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-rose-500/30 to-violet-500/20 text-sm font-bold text-rose-200"><?= $escape(strtoupper(substr($comment['username'], 0, 1))) ?></div>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="text-sm font-semibold text-white"><?= $escape($comment['username']) ?></h3>
                                        <p class="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-zinc-300"><?= $escape($comment['body']) ?></p>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <aside class="overflow-hidden rounded-2xl border border-white/10 bg-zinc-900/60 xl:sticky xl:top-6">
                <div class="border-b border-white/10 p-5">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-rose-400">Now showing</p>
                    <h2 class="mt-2 text-lg font-bold"><?= $escape($movie['title']) ?></h2>
                    <?php if ($movie['type'] === 'series' && $seasons !== []): ?>
                        <label class="mt-5 block">
                            <span class="mb-2 block text-xs font-semibold uppercase tracking-wider text-zinc-500">Select season</span>
                            <select id="season-select" class="w-full rounded-xl border border-white/10 bg-zinc-950 px-4 py-3 text-sm text-white outline-none focus:border-rose-400/60">
                                <?php foreach ($seasons as $seasonNumber): ?>
                                    <option value="<?= (int) $seasonNumber ?>" <?= $selectedEpisode !== null && (int) $selectedEpisode['season_number'] === $seasonNumber ? 'selected' : '' ?>>Season <?= (int) $seasonNumber ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                </div>
                <div id="episode-list" class="max-h-[75vh] space-y-2 overflow-y-auto p-3">
                    <?php if ($movie['type'] === 'series' && $watchData['episodes'] !== []): ?>
                        <?php foreach ($watchData['episodes'] as $episode): ?>
                            <button type="button" data-episode-id="<?= (int) $episode['id'] ?>" data-season="<?= (int) $episode['season_number'] ?>" data-episode-number="<?= (int) $episode['episode_number'] ?>" data-episode-title="<?= $escape($episode['episode_title']) ?>" class="episode-card magic-accent group flex w-full items-center space-x-4 rounded-xl border border-white/5 bg-white/5 p-2 text-left transition hover:bg-white/10 <?= $selectedEpisode !== null && (int) $selectedEpisode['id'] === (int) $episode['id'] ? 'border-rose-400/40 bg-rose-400/[0.07]' : 'hover:border-white/10' ?>">
                                <span class="episode-thumbnail relative h-[4.4rem] w-28 shrink-0 overflow-hidden rounded-lg">
                                    <img src="<?= $escape($assetUrl((string) ($episode['thumbnail_image'] ?: $movie['poster_image'] ?? ''))) ?>" alt="" loading="lazy" class="episode-thumbnail-image absolute inset-0 h-full w-full object-cover transition duration-500 group-hover:scale-110">
                                    <span class="absolute inset-0 flex items-center justify-center bg-black/20 opacity-0 transition group-hover:opacity-100"><svg aria-hidden="true" class="h-7 w-7 text-white" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.5a1 1 0 0 1 1.52-.85l9.12 5.5a1 1 0 0 1 0 1.7l-9.12 5.5A1 1 0 0 1 8 16.5z"></path></svg></span>
                                </span>
                                <span class="min-w-0 self-center">
                                    <span class="episode-badge inline-flex rounded-full border border-white/10 bg-white/5 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-zinc-400">S<?= (int) $episode['season_number'] ?> · E<?= (int) $episode['episode_number'] ?></span>
                                    <span class="mt-1 block line-clamp-2 text-sm font-semibold leading-5 text-zinc-200"><?= $escape($episode['episode_title']) ?></span>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="px-3 py-5 text-sm leading-6 text-zinc-500"><?= $movie['type'] === 'series' ? 'No episodes are available yet.' : 'This title has no episode list.' ?></p>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </main>

    <script src="<?= APP_BASE_PATH ?>public/js/magic-experience.js" defer></script>
    <script src="<?= APP_BASE_PATH ?>public/js/cosmic-interactions.js" defer></script>
    <script>
        const appBasePath = <?= json_encode(APP_BASE_PATH, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const rootAssetUrl = (path) => path && !/^(?:[a-z][a-z\d+.-]*:|\/\/|\/)/i.test(path)
            ? `${appBasePath}${path.replace(/^\/+/, '')}`
            : path;
        const movieId = <?= (int) $movie['id'] ?>;
        const playerFrame = document.getElementById('player-frame');
        const playerContent = document.getElementById('player-content');
        const downloadAction = document.querySelector('.download-action');
        if (downloadAction) {
            downloadAction.addEventListener('click', (event) => {
                if (downloadAction.dataset.downloadUrl) return;
                event.preventDefault();
            });
        }
        const episodeCards = [...document.querySelectorAll('.episode-card')];
        document.querySelectorAll('.episode-thumbnail-image').forEach((image) => {
            const frame = image.closest('.episode-thumbnail');
            const settle = (loaded) => {
                image.classList.add(loaded ? 'is-loaded' : 'is-failed');
                frame?.classList.add('is-ready');
            };
            image.addEventListener('load', () => settle(true), {once: true});
            image.addEventListener('error', () => settle(false), {once: true});
            if (image.complete) settle(image.naturalWidth > 0);
        });
        const serverButtons = [...document.querySelectorAll('.server-button')];
        const seasonSelect = document.getElementById('season-select');
        const streamFeedback = document.getElementById('stream-feedback');
        const cinemaToggle = document.getElementById('cinema-toggle');
        const cinemaOverlay = document.getElementById('cinema-overlay');
        const reportStreamButton = document.getElementById('report-stream');
        const reportStreamFeedback = document.getElementById('stream-report-feedback');
        let selectedEpisodeId = <?= $selectedEpisode !== null ? (int) $selectedEpisode['id'] : 'null' ?>;
        let activeServer = <?= json_encode($activeServer, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        let hlsInstance = null;
        let plyrInstance = null;
        let streamRequest = null;

        function setCinemaMode(enabled) {
            document.body.classList.toggle('cinema-mode', enabled);
            cinemaToggle.setAttribute('aria-pressed', String(enabled));
            cinemaToggle.textContent = enabled ? 'Exit cinema mode' : 'Cinema mode';
        }

        cinemaToggle.addEventListener('click', () => {
            setCinemaMode(!document.body.classList.contains('cinema-mode'));
        });
        cinemaOverlay.addEventListener('click', () => setCinemaMode(false));
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && document.body.classList.contains('cinema-mode')) {
                setCinemaMode(false);
            }
        });
        reportStreamButton.addEventListener('click', () => {
            const availableServers = serverButtons.filter((button) => !button.disabled);
            if (availableServers.length < 2) {
                reportStreamFeedback.textContent = 'No alternate server is available for this title.';
                return;
            }

            const currentIndex = availableServers.findIndex((button) => button.dataset.server === activeServer);
            const nextServer = availableServers[(currentIndex + 1) % availableServers.length];
            reportStreamFeedback.textContent = `Trying ${nextServer.textContent.trim()}. Stream reporting is not connected yet.`;
            nextServer.click();
        });

        function youtubeVideoId(url) {
            try {
                const parsedUrl = new URL(url);
                const host = parsedUrl.hostname.toLowerCase();
                if (host === 'youtu.be') return parsedUrl.pathname.slice(1);
                if (['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'].includes(host)) {
                    return parsedUrl.pathname.match(/^\/(?:embed|shorts)\/([A-Za-z0-9_-]{11})/)?.[1]
                        || parsedUrl.searchParams.get('v');
                }
            } catch {
                return null;
            }
            return null;
        }

        function initializePlyr(element, options = {}) {
            if (window.Plyr) {
                plyrInstance = new Plyr(element, {
                    controls: [
                        'play-large', 'play', 'progress', 'current-time', 'duration',
                        'mute', 'volume', 'settings', 'pip', 'fullscreen'
                    ],
                    settings: ['quality', 'speed'],
                    tooltips: {controls: true, seek: true},
                    ...options
                });
            } else if (element instanceof HTMLVideoElement) {
                element.controls = true;
            }
        }

        function updatePlayer(stream, title, poster) {
            if (plyrInstance) {
                plyrInstance.destroy();
                plyrInstance = null;
            }
            if (hlsInstance) {
                hlsInstance.destroy();
                hlsInstance = null;
            }
            playerContent.replaceChildren();
            if (!stream) {
                const message = document.createElement('p');
                message.className = 'flex h-full items-center justify-center px-5 text-center text-sm text-zinc-400';
                message.textContent = 'This episode has no playable source on the selected server.';
                playerContent.append(message);
                playerFrame.dataset.type = '';
                playerFrame.dataset.url = '';
                return;
            }
            playerFrame.dataset.type = stream.type;
            playerFrame.dataset.url = stream.url;
            if (stream.type === 'embed') {
                const videoId = youtubeVideoId(stream.url);
                if (videoId) {
                    const youtubePlayer = document.createElement('div');
                    youtubePlayer.dataset.plyrProvider = 'youtube';
                    youtubePlayer.dataset.plyrEmbedId = videoId;
                    youtubePlayer.className = 'h-full w-full';
                    playerContent.append(youtubePlayer);
                    initializePlyr(youtubePlayer, {
                        youtube: {noCookie: true, rel: 0, modestbranding: 1}
                    });
                    return;
                }

                const frame = document.createElement('iframe');
                frame.src = stream.url;
                frame.title = `${title} video player`;
                frame.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
                frame.referrerPolicy = 'strict-origin-when-cross-origin';
                frame.allowFullscreen = true;
                frame.className = 'h-full w-full';
                playerContent.append(frame);
            } else {
                const video = document.createElement('video');
                video.className = 'h-full w-full bg-black';
                video.controls = true;
                video.playsInline = true;
                video.preload = 'metadata';
                if (poster) video.poster = rootAssetUrl(poster);
                playerContent.append(video);
                if (stream.type === 'hls') {
                    if (video.canPlayType('application/vnd.apple.mpegurl')) {
                        video.src = stream.url;
                        initializePlyr(video);
                    } else if (window.Hls && Hls.isSupported()) {
                        hlsInstance = new Hls();
                        hlsInstance.loadSource(stream.url);
                        hlsInstance.attachMedia(video);
                        initializePlyr(video);
                    } else {
                        const message = document.createElement('p');
                        message.className = 'absolute inset-x-4 bottom-4 rounded-xl bg-black/80 p-3 text-sm text-white';
                        message.textContent = 'This browser cannot play HLS streams.';
                        playerContent.append(message);
                    }
                } else {
                    video.src = stream.url;
                    initializePlyr(video);
                }
            }
        }

        const initialStreamType = playerFrame.dataset.type;
        if (initialStreamType) {
            updatePlayer(
                {type: initialStreamType, url: playerFrame.dataset.url},
                <?= json_encode((string) $movie['title'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                <?= json_encode($assetUrl((string) ($movie['poster_image'] ?? '')), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
            );
        }

        async function loadStream(episodeId, server) {
            if (streamRequest) streamRequest.abort();
            streamRequest = new AbortController();
            const params = new URLSearchParams({page: 'watch', ajax: 'episode', id: String(movieId), server});
            if (episodeId) params.set('episode', String(episodeId));
            streamFeedback.textContent = 'Loading stream…';
            try {
                const response = await fetch(`${appBasePath}index.php?${params}`, {
                    headers: {'Accept': 'application/json'},
                    signal: streamRequest.signal
                });
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'This stream could not be loaded.');
                selectedEpisodeId = data.episode ? data.episode.id : null;
                activeServer = server;
                updatePlayer(data.stream, data.movie.title, data.movie.poster_image);
                document.getElementById('watch-title').textContent = data.movie.title;
                document.getElementById('watch-description').textContent = data.movie.description || '';
                document.getElementById('watch-stream-type').textContent = ({
                    hls: 'HLS stream',
                    video: 'Video stream',
                    embed: 'Embedded stream'
                })[data.stream?.type] || 'Stream pending';
                const episodeTitle = document.getElementById('watch-episode-title');
                episodeTitle.textContent = data.episode
                    ? `Season ${data.episode.season_number} · Episode ${data.episode.episode_number}${data.episode.episode_title ? ` — ${data.episode.episode_title}` : ''}`
                    : '';
                episodeCards.forEach((card) => {
                    const active = Number(card.dataset.episodeId) === Number(selectedEpisodeId);
                    card.classList.toggle('border-rose-400/40', active);
                    card.classList.toggle('bg-rose-400/[0.07]', active);
                    card.classList.toggle('border-white/5', !active);
                    card.classList.toggle('bg-white/5', !active);
                });
                serverButtons.forEach((button) => {
                    const available = Object.hasOwn(data.servers, button.dataset.server);
                    button.disabled = !available;
                    button.classList.toggle('border-rose-400/40', button.dataset.server === server && available);
                    button.classList.toggle('bg-rose-400/10', button.dataset.server === server && available);
                    button.classList.toggle('text-rose-200', button.dataset.server === server && available);
                });
                if (!data.servers[server]) {
                    const firstAvailable = Object.keys(data.servers)[0];
                    if (firstAvailable) {
                        activeServer = firstAvailable;
                        serverButtons.find((button) => button.dataset.server === firstAvailable)?.click();
                        return;
                    }
                }
                streamFeedback.textContent = '';
            } catch (error) {
                if (error.name !== 'AbortError') streamFeedback.textContent = error.message;
            }
        }

        episodeCards.forEach((card) => card.addEventListener('click', () => {
            const episodeId = Number(card.dataset.episodeId);
            if (seasonSelect) seasonSelect.value = card.dataset.season;
            loadStream(episodeId, activeServer);
        }));
        serverButtons.forEach((button) => button.addEventListener('click', () => {
            if (!button.disabled) loadStream(selectedEpisodeId, button.dataset.server);
        }));
        if (seasonSelect) {
            seasonSelect.addEventListener('change', () => {
                const visibleCards = episodeCards.filter((card) => card.dataset.season === seasonSelect.value);
                episodeCards.forEach((card) => card.classList.toggle('hidden', card.dataset.season !== seasonSelect.value));
                if (visibleCards.length && visibleCards.every((card) => Number(card.dataset.episodeId) !== Number(selectedEpisodeId))) {
                    const firstEpisode = visibleCards[0];
                    loadStream(Number(firstEpisode.dataset.episodeId), activeServer);
                }
            });
            episodeCards.forEach((card) => card.classList.toggle('hidden', card.dataset.season !== seasonSelect.value));
        }

        const commentForm = document.getElementById('comment-form');
        if (commentForm) {
            commentForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                const button = commentForm.querySelector('button[type="submit"]');
                const feedback = document.getElementById('comment-feedback');
                button.disabled = true;
                feedback.textContent = 'Posting…';
                try {
                    const response = await fetch(`${appBasePath}index.php?page=watch&action=comment`, {
                        method: 'POST',
                        body: new FormData(commentForm),
                        headers: {'Accept': 'application/json'}
                    });
                    const result = await response.json();
                    if (!response.ok || !result.ok) throw new Error(result.message || 'Your comment could not be posted.');
                    const emptyState = document.getElementById('empty-comments');
                    if (emptyState) emptyState.remove();
                    const comment = document.createElement('article');
                    comment.className = 'flex gap-4 py-5';
                    const avatar = document.createElement('div');
                    avatar.className = 'flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-rose-500/30 to-violet-500/20 text-sm font-bold text-rose-200';
                    avatar.textContent = result.comment.username.slice(0, 1).toUpperCase();
                    const content = document.createElement('div');
                    content.className = 'min-w-0 flex-1';
                    const name = document.createElement('h3');
                    name.className = 'text-sm font-semibold text-white';
                    name.textContent = result.comment.username;
                    const body = document.createElement('p');
                    body.className = 'mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-zinc-300';
                    body.textContent = result.comment.body;
                    content.append(name, body);
                    comment.append(avatar, content);
                    document.getElementById('comment-list').prepend(comment);
                    commentForm.reset();
                    feedback.textContent = 'Your comment is live.';
                    window.location.reload();
                } catch (error) {
                    feedback.textContent = error.message;
                } finally {
                    button.disabled = false;
                }
            });
        }
    </script>
</body>
</html>
