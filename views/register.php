<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../controllers/AuthController.php';

$auth = new AuthController($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $auth->register($_POST);
}

$errors = $auth->errors();
$oldInput = $auth->oldInput();
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#09090b">
    <title>Create account | <?= SITE_BRAND ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased">
    <main class="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-12">
        <div aria-hidden="true" class="pointer-events-none absolute -left-40 -top-40 h-96 w-96 rounded-full bg-rose-600/20 blur-3xl"></div>
        <div aria-hidden="true" class="pointer-events-none absolute -bottom-48 -right-32 h-[32rem] w-[32rem] rounded-full bg-violet-700/20 blur-3xl"></div>

        <section class="relative w-full max-w-md rounded-3xl border border-white/10 bg-zinc-900/85 p-8 shadow-2xl shadow-black/40 backdrop-blur sm:p-10">
            <a href="index.php?page=home" class="inline-flex items-center gap-3" aria-label="<?= SITE_BRAND ?> home">
                <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-rose-500 text-lg font-black text-white">A</span>
                <span class="brand-logo text-xl font-bold tracking-tight"><?= SITE_BRAND ?></span>
            </a>

            <div class="mt-8">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-rose-400">Join <?= SITE_BRAND ?></p>
                <h1 class="mt-2 text-3xl font-bold tracking-tight">Create your account</h1>
                <p class="mt-2 text-sm leading-6 text-zinc-400">Sign up and discover stories in Kinyarwanda.</p>
            </div>

            <?php if ($errors !== []): ?>
                <div role="alert" class="mt-6 rounded-xl border border-rose-400/20 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">
                    <?php foreach ($errors as $error): ?>
                        <p><?= $escape($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="index.php?page=register" class="mt-6 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= $escape($auth->csrfToken()) ?>">

                <div>
                    <label for="username" class="mb-2 block text-sm font-medium text-zinc-200">Username</label>
                    <input id="username" name="username" type="text" required minlength="3" maxlength="50"
                           pattern="[A-Za-z0-9_]{3,50}" autocomplete="username"
                           value="<?= $escape($oldInput['username'] ?? '') ?>"
                           class="w-full rounded-xl border border-zinc-700 bg-zinc-950/70 px-4 py-3 text-zinc-100 outline-none transition placeholder:text-zinc-600 focus:border-rose-400 focus:ring-2 focus:ring-rose-400/20"
                           placeholder="Choose a username">
                    <p class="mt-1.5 text-xs text-zinc-500">3-50 letters, numbers, or underscores.</p>
                </div>

                <div>
                    <label for="email" class="mb-2 block text-sm font-medium text-zinc-200">Email address</label>
                    <input id="email" name="email" type="email" required maxlength="254" autocomplete="email"
                           value="<?= $escape($oldInput['email'] ?? '') ?>"
                           class="w-full rounded-xl border border-zinc-700 bg-zinc-950/70 px-4 py-3 text-zinc-100 outline-none transition placeholder:text-zinc-600 focus:border-rose-400 focus:ring-2 focus:ring-rose-400/20"
                           placeholder="you@example.com">
                </div>

                <div>
                    <label for="password" class="mb-2 block text-sm font-medium text-zinc-200">Password</label>
                    <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password"
                           class="w-full rounded-xl border border-zinc-700 bg-zinc-950/70 px-4 py-3 text-zinc-100 outline-none transition placeholder:text-zinc-600 focus:border-rose-400 focus:ring-2 focus:ring-rose-400/20"
                           placeholder="At least 8 characters">
                </div>

                <div>
                    <label for="password_confirmation" class="mb-2 block text-sm font-medium text-zinc-200">Confirm password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password"
                           class="w-full rounded-xl border border-zinc-700 bg-zinc-950/70 px-4 py-3 text-zinc-100 outline-none transition placeholder:text-zinc-600 focus:border-rose-400 focus:ring-2 focus:ring-rose-400/20"
                           placeholder="Enter your password again">
                </div>

                <button type="submit" class="w-full rounded-xl bg-rose-500 px-4 py-3 font-semibold text-white shadow-lg shadow-rose-950/30 transition hover:bg-rose-400 focus:outline-none focus:ring-2 focus:ring-rose-300 focus:ring-offset-2 focus:ring-offset-zinc-900">
                    Create account
                </button>
            </form>

            <p class="mt-6 text-center text-sm text-zinc-400">
                Already have an account?
                <a href="index.php?page=login" class="font-semibold text-rose-400 hover:text-rose-300">Sign in</a>
            </p>
        </section>
    </main>
    <script src="public/js/magic-experience.js" defer></script>
</body>
</html>
