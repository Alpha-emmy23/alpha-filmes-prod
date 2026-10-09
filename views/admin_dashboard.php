<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../controllers/AdminController.php';

$admin = new AdminController($pdo);
$admin->handleRequest($_POST, $_FILES);
$data = $admin->dashboardData();
$errors = $admin->errors();
$oldInput = $admin->oldInput();
$successMessage = $admin->successMessage();
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrfToken = $escape($admin->csrfToken());
$inputClass = 'mt-2 w-full rounded-xl border border-zinc-700 bg-zinc-950/70 px-4 py-3 text-zinc-100 outline-none transition placeholder:text-zinc-600 focus:border-rose-400 focus:ring-2 focus:ring-rose-400/20';
$buttonClass = 'rounded-xl bg-rose-500 px-5 py-3 font-semibold text-white transition hover:bg-rose-400 focus:outline-none focus:ring-2 focus:ring-rose-300 focus:ring-offset-2 focus:ring-offset-zinc-900';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#09090b">
    <title>Admin dashboard | <?= SITE_BRAND ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased">
    <div class="min-h-screen">
        <header class="border-b border-white/10 bg-zinc-900/70">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-5 sm:px-6 lg:px-8">
                <a href="index.php?page=home" class="inline-flex items-center gap-3" aria-label="<?= SITE_BRAND ?> home">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-500 text-lg font-black">A</span>
                    <span class="brand-logo text-lg font-bold tracking-tight"><?= SITE_BRAND ?></span><span class="font-medium text-zinc-500">/ Studio</span>
                </a>
                <span class="rounded-full border border-rose-400/20 bg-rose-400/10 px-3 py-1.5 text-xs font-semibold uppercase tracking-wider text-rose-300">Admin</span>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <div class="mb-8">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-rose-400">Content management</p>
                <h1 class="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">Admin dashboard</h1>
                <p class="mt-2 text-zinc-400">Manage your Kinyarwanda-dubbed movie and series library.</p>
            </div>

            <?php if ($successMessage !== null): ?>
                <div role="status" class="mb-6 rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">
                    <?= $escape($successMessage) ?>
                </div>
            <?php endif; ?>

            <?php if ($errors !== []): ?>
                <div role="alert" class="mb-6 rounded-xl border border-rose-400/20 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">
                    <?php foreach ($errors as $error): ?>
                        <p><?= $escape($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="rounded-2xl border border-white/10 bg-zinc-900/80 p-6 shadow-xl shadow-black/10">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-wider text-rose-400">Organize</p>
                        <h2 class="mt-1 text-xl font-bold">Categories &amp; translators</h2>
                    </div>

                    <form method="post" action="index.php?page=admin_dashboard" class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="add_category">
                        <div class="flex-1">
                            <label for="category_name" class="text-sm font-medium text-zinc-300">New category</label>
                            <input id="category_name" name="name" type="text" required maxlength="100"
                                   value="<?= $escape($oldInput['category_name'] ?? '') ?>"
                                   class="<?= $inputClass ?>" placeholder="e.g. Drama">
                        </div>
                        <button type="submit" class="<?= $buttonClass ?>">Add category</button>
                    </form>

                    <form method="post" action="index.php?page=admin_dashboard" class="mt-4 flex flex-col gap-3 border-t border-white/10 pt-4 sm:flex-row sm:items-end">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="add_translator">
                        <div class="flex-1">
                            <label for="translator_name" class="text-sm font-medium text-zinc-300">Translator (Abasobanuzi)</label>
                            <input id="translator_name" name="name" type="text" required maxlength="150"
                                   value="<?= $escape($oldInput['translator_name'] ?? '') ?>"
                                   class="<?= $inputClass ?>" placeholder="Translator or dubbing group">
                        </div>
                        <button type="submit" class="<?= $buttonClass ?>">Add translator</button>
                    </form>
                </section>

                <section class="rounded-2xl border border-white/10 bg-zinc-900/80 p-6 shadow-xl shadow-black/10">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-wider text-violet-300">Series setup</p>
                        <h2 class="mt-1 text-xl font-bold">Add a season</h2>
                    </div>
                    <form method="post" action="index.php?page=admin_dashboard" class="mt-5 space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="add_season">
                        <div>
                            <label for="season_movie_id" class="text-sm font-medium text-zinc-300">Series</label>
                            <select id="season_movie_id" name="movie_id" required class="<?= $inputClass ?>">
                                <option value="">Select a series</option>
                                <?php foreach ($data['series'] as $series): ?>
                                    <option value="<?= (int) $series['id'] ?>"><?= $escape($series['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="season_number" class="text-sm font-medium text-zinc-300">Season number</label>
                            <input id="season_number" name="season_number" type="number" min="1" max="2147483647" required class="<?= $inputClass ?>" placeholder="1">
                        </div>
                        <button type="submit" class="<?= $buttonClass ?>">Add season</button>
                    </form>

                    <form method="post" action="index.php?page=admin_dashboard" class="mt-6 space-y-4 border-t border-white/10 pt-5">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="add_episode">
                        <div>
                            <label for="episode_season_id" class="text-sm font-medium text-zinc-300">Season</label>
                            <select id="episode_season_id" name="season_id" required class="<?= $inputClass ?>">
                                <option value="">Select a season</option>
                                <?php foreach ($data['seasons'] as $season): ?>
                                    <option value="<?= (int) $season['id'] ?>">
                                        <?= $escape($season['movie_title']) ?> — Season <?= (int) $season['season_number'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="episode_number" class="text-sm font-medium text-zinc-300">Episode number</label>
                            <input id="episode_number" name="episode_number" type="number" min="1" max="2147483647" required class="<?= $inputClass ?>" placeholder="1">
                        </div>
                        <div>
                            <label for="video_url" class="text-sm font-medium text-zinc-300">Episode video URL</label>
                            <input id="video_url" name="video_url" type="url" maxlength="2048" required class="<?= $inputClass ?>" placeholder="https://…">
                        </div>
                        <button type="submit" class="<?= $buttonClass ?>">Add episode</button>
                    </form>
                </section>

                <section class="rounded-2xl border border-white/10 bg-zinc-900/80 p-6 shadow-xl shadow-black/10 lg:col-span-2">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-wider text-rose-400">Library</p>
                        <h2 class="mt-1 text-xl font-bold">Add a movie or series</h2>
                    </div>

                    <form method="post" action="index.php?page=admin_dashboard" enctype="multipart/form-data" class="mt-5 grid gap-5 sm:grid-cols-2">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="add_movie">
                        <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
                        <div>
                            <label for="title" class="text-sm font-medium text-zinc-300">Title</label>
                            <input id="title" name="title" type="text" required maxlength="255"
                                   value="<?= $escape($oldInput['title'] ?? '') ?>"
                                   class="<?= $inputClass ?>" placeholder="Movie or series title">
                        </div>
                        <div>
                            <label for="type" class="text-sm font-medium text-zinc-300">Type</label>
                            <select id="type" name="type" required class="<?= $inputClass ?>">
                                <option value="solo" <?= ($oldInput['type'] ?? 'solo') === 'solo' ? 'selected' : '' ?>>Solo movie</option>
                                <option value="series" <?= ($oldInput['type'] ?? '') === 'series' ? 'selected' : '' ?>>Series</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="description" class="text-sm font-medium text-zinc-300">Description</label>
                            <textarea id="description" name="description" rows="4" maxlength="65535"
                                      class="<?= $inputClass ?>" placeholder="A short synopsis..."><?= $escape($oldInput['description'] ?? '') ?></textarea>
                        </div>
                        <div>
                            <label for="category_id" class="text-sm font-medium text-zinc-300">Category</label>
                            <select id="category_id" name="category_id" class="<?= $inputClass ?>">
                                <option value="">No category</option>
                                <?php foreach ($data['categories'] as $category): ?>
                                    <option value="<?= (int) $category['id'] ?>" <?= (string) ($oldInput['category_id'] ?? '') === (string) $category['id'] ? 'selected' : '' ?>>
                                        <?= $escape($category['category_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="translator_id" class="text-sm font-medium text-zinc-300">Translator (Abasobanuzi)</label>
                            <select id="translator_id" name="translator_id" class="<?= $inputClass ?>">
                                <option value="">No translator</option>
                                <?php foreach ($data['translators'] as $translator): ?>
                                    <option value="<?= (int) $translator['id'] ?>" <?= (string) ($oldInput['translator_id'] ?? '') === (string) $translator['id'] ? 'selected' : '' ?>>
                                        <?= $escape($translator['translator_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="poster_image" class="text-sm font-medium text-zinc-300">Poster image</label>
                            <input id="poster_image" name="poster_image" type="file" accept="image/jpeg,image/png,image/webp" required
                                   class="mt-2 block w-full cursor-pointer rounded-xl border border-zinc-700 bg-zinc-950/70 text-sm text-zinc-400 file:mr-4 file:border-0 file:bg-zinc-800 file:px-4 file:py-3 file:font-medium file:text-zinc-200 hover:file:bg-zinc-700">
                            <p class="mt-2 text-xs text-zinc-500">JPEG, PNG, or WebP. Maximum 5 MB.</p>
                        </div>
                        <div>
                            <label for="video_source" class="text-sm font-medium text-zinc-300">Movie video URL (Streaming Link) <span class="text-zinc-500">(optional)</span></label>
                            <input id="video_source" name="video_source" type="url" maxlength="2048"
                                   value="<?= $escape($oldInput['video_source'] ?? '') ?>"
                                   class="<?= $inputClass ?>" placeholder="https://...">
                        </div>
                        <div>
                            <label for="download_url" class="text-sm font-medium text-zinc-300">Movie Download URL (MediaFire/Drive Link) <span class="text-zinc-500">(optional)</span></label>
                            <input id="download_url" name="download_url" type="url" maxlength="2048"
                                   value="<?= $escape($oldInput['download_url'] ?? '') ?>"
                                   class="<?= $inputClass ?>" placeholder="https://...">
                        </div>
                        <div class="sm:col-span-2">
                            <button type="submit" class="<?= $buttonClass ?>">Save movie / series</button>
                        </div>
                    </form>
                </section>
            </div>

            <section class="mt-8 rounded-2xl border border-white/10 bg-zinc-900/80 p-6 shadow-xl shadow-black/10">
                <div class="flex items-end justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-wider text-zinc-400">Recently added</p>
                        <h2 class="mt-1 text-xl font-bold">Library overview</h2>
                    </div>
                    <span class="text-sm text-zinc-500">All titles</span>
                </div>
                <?php if ($data['movies'] === []): ?>
                    <p class="mt-5 rounded-xl border border-dashed border-zinc-700 px-4 py-8 text-center text-sm text-zinc-500">No titles yet. Add your first movie or series above.</p>
                <?php else: ?>
                    <div class="mt-5 overflow-x-auto">
                        <table class="w-full min-w-[36rem] text-left text-sm">
                            <thead class="border-b border-white/10 text-xs uppercase tracking-wider text-zinc-500">
                                <tr>
                                    <th class="pb-3 pr-4 font-medium">Title</th>
                                    <th class="pb-3 pr-4 font-medium">Type</th>
                                    <th class="pb-3 pr-4 font-medium">Category</th>
                                    <th class="pb-3 font-medium">Translator</th>
                                    <th class="pb-3 pl-4 text-right font-medium">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <?php foreach ($data['movies'] as $movie): ?>
                                    <tr>
                                        <td class="py-3 pr-4 font-medium text-zinc-200"><?= $escape($movie['title']) ?></td>
                                        <td class="py-3 pr-4 capitalize text-zinc-400"><?= $escape($movie['type']) ?></td>
                                        <td class="py-3 pr-4 text-zinc-400"><?= $escape($movie['category_name'] ?? '—') ?></td>
                                        <td class="py-3 text-zinc-400"><?= $escape($movie['translator_name'] ?? '—') ?></td>
                                        <td class="py-3 pl-4 text-right">
                                            <form method="post" action="index.php?action=delete_movie&amp;id=<?= (int) $movie['id'] ?>" class="inline">
                                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                <button type="submit" onclick="return confirm('Are you sure you want to permanently delete this movie from ALPHAFILMES?')"
                                                        class="rounded-lg bg-red-600 px-3 py-1.5 text-sm text-white transition hover:bg-red-700">
                                                    Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <script src="public/js/magic-experience.js" defer></script>
</body>
</html>
