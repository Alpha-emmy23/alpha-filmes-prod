<?php

declare(strict_types=1);

const SITE_BRAND = 'ALPHAFILMES';
const APP_BASE_PATH = '/';

if (($_GET['action'] ?? null) === 'ajax_search') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['error' => 'Search requests must use GET.']);
        exit;
    }

    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/controllers/MovieController.php';
    try {
        $movieController = new MovieController($pdo);
        echo json_encode(
            $movieController->handleAjaxSearch(),
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        echo json_encode(['error' => $exception->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (RuntimeException $exception) {
        error_log('AJAX search endpoint failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Search is temporarily unavailable. Please try again.']);
    }
    exit;
}

if (($_GET['action'] ?? null) === 'delete_movie') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Movie deletion requires a POST request.');
    }

    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/controllers/AdminController.php';

    $admin = new AdminController($pdo);
    $movieId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $admin->deleteMovie($movieId === false ? 0 : $movieId);

    if ($admin->errors() !== []) {
        $_SESSION['admin_error'] = implode(' ', $admin->errors());
    }
    header('Location: ' . APP_BASE_PATH . 'index.php?page=admin_dashboard', true, 303);
    exit;
}

$routes = [
    'home' => __DIR__ . '/views/home.php',
    'watch' => __DIR__ . '/views/watch.php',
    'login' => __DIR__ . '/views/login.php',
    'register' => __DIR__ . '/views/register.php',
    'admin' => __DIR__ . '/views/admin_dashboard.php',
    'admin_dashboard' => __DIR__ . '/views/admin_dashboard.php',
];

$requestedPage = $_GET['page'] ?? null;
if (is_string($requestedPage) && $requestedPage !== '') {
    $page = $requestedPage;
} else {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $requestPath = is_string($requestPath) ? str_replace('\\', '/', $requestPath) : '/';
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    if ($basePath !== '' && str_starts_with($requestPath, $basePath . '/')) {
        $requestPath = substr($requestPath, strlen($basePath));
    } elseif ($requestPath === $basePath || $requestPath === $scriptName) {
        $requestPath = '/';
    }

    $route = trim($requestPath, '/');
    $page = $route === '' || $route === 'index.php' ? 'home' : basename($route);
}

if (!isset($routes[$page]) || !is_file($routes[$page])) {
    http_response_code(404);
    echo '404 - Page not found';
    exit;
}

if ($page === 'home') {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/controllers/MovieController.php';

    $movieController = new MovieController($pdo);
    $movieController->index();
    exit;
}

require $routes[$page];
