<?php

declare(strict_types=1);

final class AdminController
{
    private PDO $pdo;
    private array $errors = [];
    private array $oldInput = [];
    private ?string $successMessage = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;

        if (session_status() === PHP_SESSION_NONE) {
            $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            session_set_cookie_params([
                'httponly' => true,
                'secure' => $isSecure,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        if (($_SESSION['user']['role'] ?? null) !== 'admin') {
            if (!isset($_SESSION['user'])) {
                header('Location: index.php?page=login', true, 303);
                exit;
            }

            http_response_code(403);
            exit('403 - Administrator access required');
        }

        if (isset($_SESSION['admin_flash']) && is_string($_SESSION['admin_flash'])) {
            $this->successMessage = $_SESSION['admin_flash'];
            unset($_SESSION['admin_flash']);
        }
        if (isset($_SESSION['admin_error']) && is_string($_SESSION['admin_error'])) {
            $this->errors[] = $_SESSION['admin_error'];
            unset($_SESSION['admin_error']);
        }
    }

    public function csrfToken(): string
    {
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function oldInput(): array
    {
        return $this->oldInput;
    }

    public function successMessage(): ?string
    {
        return $this->successMessage;
    }

    public function deleteMovie(int $id): bool
    {
        if (!$this->validCsrfToken($_POST['csrf_token'] ?? null)) {
            $this->errors[] = 'Your session expired. Refresh the dashboard and try again.';
            return false;
        }
        if ($id < 1) {
            $this->errors[] = 'Choose a valid movie to delete.';
            return false;
        }

        $posterImage = null;
        try {
            $this->pdo->beginTransaction();
            $lookup = $this->pdo->prepare('SELECT poster_image FROM movies WHERE id = :id FOR UPDATE');
            $lookup->execute(['id' => $id]);
            $movie = $lookup->fetch();
            if (!is_array($movie)) {
                $this->pdo->rollBack();
                $this->errors[] = 'That movie could not be found.';
                return false;
            }
            $posterImage = is_string($movie['poster_image'] ?? null) ? $movie['poster_image'] : null;

            $delete = $this->pdo->prepare('DELETE FROM movies WHERE id = :id');
            $delete->execute(['id' => $id]);
            if ($delete->rowCount() !== 1) {
                $this->pdo->rollBack();
                $this->errors[] = 'That movie could not be deleted.';
                return false;
            }
            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('Admin movie deletion failed: ' . $exception->getMessage());
            $this->errors[] = 'Movie could not be deleted. Please try again.';
            return false;
        }

        if ($posterImage !== null) {
            $this->deleteMoviePosterFile($posterImage);
        }
        $this->setSuccess('Movie deleted successfully.');

        return true;
    }

    public function handleRequest(array $input, array $files): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        if (!$this->validCsrfToken($input['csrf_token'] ?? null)) {
            $this->errors[] = 'Your session expired. Refresh the page and try again.';
            return;
        }

        $action = $input['action'] ?? '';

        if (!is_string($action)) {
            $this->errors[] = 'Choose a valid dashboard action.';
            return;
        }

        switch ($action) {
            case 'add_category':
                $this->addNamedEntry('categories', 'category_name', 'Category', $input);
                break;
            case 'add_translator':
                $this->addNamedEntry('translators', 'translator_name', 'Translator', $input);
                break;
            case 'add_movie':
                $this->addMovie($input, $files['poster_image'] ?? null);
                break;
            case 'add_season':
                $this->addSeason($input);
                break;
            case 'add_episode':
                $this->addEpisode($input);
                break;
            default:
                $this->errors[] = 'Choose a valid dashboard action.';
                return;
        }

        if ($this->errors === []) {
            header('Location: index.php?page=admin_dashboard', true, 303);
            exit;
        }
    }

    public function dashboardData(): array
    {
        try {
            $categories = $this->pdo->query(
                'SELECT id, category_name FROM categories ORDER BY category_name'
            )->fetchAll();
            $translators = $this->pdo->query(
                'SELECT id, translator_name FROM translators ORDER BY translator_name'
            )->fetchAll();
            $series = $this->pdo->query(
                "SELECT id, title FROM movies WHERE type = 'series' ORDER BY title"
            )->fetchAll();
            $seasons = $this->pdo->query(
                'SELECT seasons.id, seasons.season_number, movies.title AS movie_title
                 FROM seasons
                 INNER JOIN movies ON movies.id = seasons.movie_id
                 ORDER BY movies.title, seasons.season_number'
            )->fetchAll();
            $movies = $this->pdo->query(
                'SELECT movies.id, movies.title, movies.type, movies.poster_image,
                        categories.category_name, translators.translator_name
                 FROM movies
                 LEFT JOIN categories ON categories.id = movies.category_id
                 LEFT JOIN translators ON translators.id = movies.translator_id
                 ORDER BY movies.id DESC'
            )->fetchAll();
        } catch (PDOException $exception) {
            error_log('Admin dashboard query failed: ' . $exception->getMessage());
            $this->errors[] = 'Dashboard data could not be loaded. Check the database and try again.';

            return [
                'categories' => [],
                'translators' => [],
                'series' => [],
                'seasons' => [],
                'movies' => [],
            ];
        }

        return compact('categories', 'translators', 'series', 'seasons', 'movies');
    }

    private function addNamedEntry(string $table, string $column, string $label, array $input): void
    {
        $value = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        $this->oldInput[$label === 'Category' ? 'category_name' : 'translator_name'] = $value;
        $maxLength = $label === 'Category' ? 100 : 150;

        if ($value === '' || $this->characterLength($value) > $maxLength) {
            $this->errors[] = "{$label} name is required and must be no longer than {$maxLength} characters.";
            return;
        }

        try {
            $statement = $this->pdo->prepare("INSERT INTO {$table} ({$column}) VALUES (:name)");
            $statement->execute(['name' => $value]);
        } catch (PDOException $exception) {
            if ($this->isDuplicateKey($exception)) {
                $this->errors[] = 'That ' . strtolower($label) . ' already exists.';
                return;
            }

            error_log("Admin {$label} creation failed: " . $exception->getMessage());
            $this->errors[] = "{$label} could not be saved. Please try again.";
            return;
        }

        $this->setSuccess("{$label} added successfully.");
    }

    private function addMovie(array $input, mixed $posterUpload): void
    {
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        $description = is_string($input['description'] ?? null) ? trim($input['description']) : '';
        $type = $input['type'] ?? null;
        $categoryId = $this->optionalId($input['category_id'] ?? null);
        $translatorId = $this->optionalId($input['translator_id'] ?? null);
        $videoSource = is_string($input['video_source'] ?? null) ? trim($input['video_source']) : '';
        $downloadUrl = is_string($input['download_url'] ?? null) ? trim($input['download_url']) : '';

        $this->oldInput = [
            'title' => $title,
            'description' => $description,
            'type' => is_string($type) ? $type : 'solo',
            'category_id' => is_scalar($input['category_id'] ?? null) ? (string) $input['category_id'] : '',
            'translator_id' => is_scalar($input['translator_id'] ?? null) ? (string) $input['translator_id'] : '',
            'video_source' => $videoSource,
            'download_url' => $downloadUrl,
        ];

        if ($title === '' || $this->characterLength($title) > 255) {
            $this->errors[] = 'Movie title is required and must be no longer than 255 characters.';
        }
        if (!in_array($type, ['solo', 'series'], true)) {
            $this->errors[] = 'Choose a valid movie type.';
        }
        if (strlen($description) > 65535) {
            $this->errors[] = 'Description is too long.';
        }
        if ($categoryId === false || $translatorId === false) {
            $this->errors[] = 'Choose a valid category and translator.';
        }
        if ($videoSource !== '' && !$this->validHttpUrl($videoSource)) {
            $this->errors[] = 'Video source must be a valid HTTP or HTTPS URL.';
        }
        if ($downloadUrl !== '' && !$this->validHttpUrl($downloadUrl)) {
            $this->errors[] = 'Download URL must be a valid HTTP or HTTPS URL no longer than 2048 characters.';
        }
        if ($this->errors !== []) {
            return;
        }

        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                $columnQuery = $this->pdo->query(
                    "SELECT column_name FROM information_schema.columns
                     WHERE table_schema = current_schema() AND table_name = 'movies'"
                );
                if ($columnQuery === false) {
                    throw new RuntimeException('Could not inspect movie columns.');
                }
                $movieColumns = array_column($columnQuery->fetchAll(PDO::FETCH_ASSOC), 'column_name');
            } else {
                $movieColumns = array_column(
                    $this->pdo->query('SHOW COLUMNS FROM movies')->fetchAll(),
                    'Field'
                );
            }
        } catch (PDOException | RuntimeException $exception) {
            error_log('Could not verify movie download schema: ' . $exception->getMessage());
            $this->errors[] = 'Movie schema could not be checked. Please try again.';
            return;
        }
        if (!in_array('download_url', $movieColumns, true)) {
            $this->errors[] = 'Download links cannot be saved until the database update has added movies.download_url. Run update_db.php, then retry.';
            return;
        }

        try {
            $posterPath = $this->storePosterUpload($posterUpload);
        } catch (RuntimeException $exception) {
            $this->errors[] = $exception->getMessage();
            return;
        }

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO movies (title, description, poster_image, video_source, download_url, type, category_id, translator_id)
                 VALUES (:title, :description, :poster_image, :video_source, :download_url, :type, :category_id, :translator_id)'
            );
            $statement->execute([
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'poster_image' => $posterPath,
                'video_source' => $videoSource !== '' ? $videoSource : null,
                'download_url' => $downloadUrl !== '' ? $downloadUrl : null,
                'type' => $type,
                'category_id' => $categoryId,
                'translator_id' => $translatorId,
            ]);
        } catch (PDOException $exception) {
            $this->deletePoster($posterPath);
            error_log('Admin movie creation failed: ' . $exception->getMessage());
            $this->errors[] = 'Movie could not be saved. Check the selected category and translator, then try again.';
            return;
        }

        $this->setSuccess('Movie added successfully.');
    }

    private function addSeason(array $input): void
    {
        $movieId = $this->requiredPositiveInt($input['movie_id'] ?? null);
        $seasonNumber = $this->requiredPositiveInt($input['season_number'] ?? null);

        if ($movieId === null || $seasonNumber === null) {
            $this->errors[] = 'Choose a series and enter a valid season number.';
            return;
        }

        try {
            $statement = $this->pdo->prepare(
                "INSERT INTO seasons (movie_id, season_number)
                 SELECT id, :season_number FROM movies WHERE id = :movie_id AND type = 'series'"
            );
            $statement->execute([
                'season_number' => $seasonNumber,
                'movie_id' => $movieId,
            ]);

            if ($statement->rowCount() !== 1) {
                $this->errors[] = 'The selected series could not be found.';
                return;
            }
        } catch (PDOException $exception) {
            if ($this->isDuplicateKey($exception)) {
                $this->errors[] = 'That season number already exists for the selected series.';
                return;
            }

            error_log('Admin season creation failed: ' . $exception->getMessage());
            $this->errors[] = 'Season could not be saved. Please try again.';
            return;
        }

        $this->setSuccess('Season added successfully.');
    }

    private function addEpisode(array $input): void
    {
        $seasonId = $this->requiredPositiveInt($input['season_id'] ?? null);
        $episodeNumber = $this->requiredPositiveInt($input['episode_number'] ?? null);
        $videoUrl = is_string($input['video_url'] ?? null) ? trim($input['video_url']) : '';

        if ($seasonId === null || $episodeNumber === null || !$this->validHttpUrl($videoUrl)) {
            $this->errors[] = 'Choose a season, enter a positive episode number, and provide an HTTP or HTTPS video URL.';
            return;
        }

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO episodes (season_id, episode_number, video_url)
                 VALUES (:season_id, :episode_number, :video_url)'
            );
            $statement->execute([
                'season_id' => $seasonId,
                'episode_number' => $episodeNumber,
                'video_url' => $videoUrl,
            ]);
        } catch (PDOException $exception) {
            if ($this->isDuplicateKey($exception)) {
                $this->errors[] = 'That episode number already exists for the selected season.';
                return;
            }

            if ($exception->getCode() === '23000') {
                $this->errors[] = 'The selected season could not be found.';
                return;
            }

            error_log('Admin episode creation failed: ' . $exception->getMessage());
            $this->errors[] = 'Episode could not be saved. Please try again.';
            return;
        }

        $this->setSuccess('Episode added successfully.');
    }

    private function storePosterUpload(mixed $upload): string
    {
        if (!is_array($upload) || !isset($upload['error']) || !is_int($upload['error'])) {
            throw new RuntimeException('Choose a poster image to upload.');
        }

        if ($upload['error'] !== UPLOAD_ERR_OK) {
            $message = match ($upload['error']) {
                UPLOAD_ERR_NO_FILE => 'Choose a poster image to upload.',
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Poster image must be no larger than 5 MB.',
                default => 'Poster image upload failed. Please try again.',
            };
            throw new RuntimeException($message);
        }

        if (!isset($upload['tmp_name'], $upload['size'])
            || !is_string($upload['tmp_name'])
            || !is_int($upload['size'])
            || !is_uploaded_file($upload['tmp_name'])
            || $upload['size'] < 1
            || $upload['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('Poster image must be a valid image no larger than 5 MB.');
        }

        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file($upload['tmp_name']);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (!is_string($mimeType) || !isset($extensions[$mimeType])) {
            throw new RuntimeException('Use a JPEG, PNG, or WebP poster image.');
        }

        $imageInfo = @getimagesize($upload['tmp_name']);
        if ($imageInfo === false
            || ($imageInfo['mime'] ?? null) !== $mimeType
            || $imageInfo[0] < 1
            || $imageInfo[1] < 1
            || $imageInfo[0] * $imageInfo[1] > 40000000) {
            throw new RuntimeException('Poster image is invalid or its dimensions are too large.');
        }

        $directory = dirname(__DIR__) . '/public/uploads/posters';
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            error_log('Could not create poster upload directory: ' . $directory);
            throw new RuntimeException('Poster storage is unavailable. Please contact the site administrator.');
        }

        $filename = bin2hex(random_bytes(24)) . '.' . $extensions[$mimeType];
        $destination = $directory . '/' . $filename;
        if (!@move_uploaded_file($upload['tmp_name'], $destination)) {
            error_log('Could not move uploaded poster into storage.');
            throw new RuntimeException('Poster image could not be saved. Please try again.');
        }

        return 'public/uploads/posters/' . $filename;
    }

    private function validCsrfToken(mixed $token): bool
    {
        return is_string($token)
            && isset($_SESSION['csrf_token'])
            && is_string($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    private function optionalId(mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? false : $id;
    }

    private function requiredPositiveInt(mixed $value): ?int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $number === false ? null : $number;
    }

    private function validHttpUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }

    private function characterLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        $matches = preg_match_all('/./us', $value, $characters);
        return $matches === false ? strlen($value) : $matches;
    }

    private function isDuplicateKey(PDOException $exception): bool
    {
        return $exception->getCode() === '23505'
            || ($exception->getCode() === '23000' && (int) ($exception->errorInfo[1] ?? 0) === 1062);
    }

    private function setSuccess(string $message): void
    {
        $_SESSION['admin_flash'] = $message;
    }

    private function deletePoster(string $path): void
    {
        $filename = basename($path);
        $posterPath = dirname(__DIR__) . '/public/uploads/posters/' . $filename;

        if (is_file($posterPath) && !@unlink($posterPath)) {
            error_log('Could not remove unreferenced poster file: ' . $posterPath);
        }
    }

    private function deleteMoviePosterFile(string $path): void
    {
        $prefix = 'public/uploads/';
        if (!str_starts_with($path, $prefix)) {
            return;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return;
        }

        $uploadsDirectory = realpath(dirname(__DIR__) . '/public/uploads');
        $posterPath = realpath(dirname(__DIR__) . '/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
        if ($uploadsDirectory === false || $posterPath === false) {
            return;
        }

        $uploadsPrefix = rtrim($uploadsDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncasecmp($posterPath, $uploadsPrefix, strlen($uploadsPrefix)) !== 0 || !is_file($posterPath)) {
            return;
        }

        if (!@unlink($posterPath)) {
            error_log('Could not remove deleted movie poster file: ' . $posterPath);
        }
    }
}
