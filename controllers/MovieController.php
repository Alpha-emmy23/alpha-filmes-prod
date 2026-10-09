<?php

declare(strict_types=1);

final class MovieController
{
    private PDO $pdo;
    private ?array $movieColumns = null;
    private ?array $episodeColumns = null;

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
    }

    public function csrfToken(): string
    {
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public function currentUser(): ?array
    {
        return is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : null;
    }

    public function index(): void
    {
        $movieController = $this;
        require __DIR__ . '/../views/home.php';
    }
    public function homepageData(): array
    {
        try {
            $baseSelect = $this->movieSelectSql();
            $featuredWhere = in_array('is_featured', $this->getMovieColumns(), true)
                ? 'WHERE movies.is_featured = 1'
                : '';
            
            $featuredStatement = $this->pdo->query($baseSelect . " {$featuredWhere} ORDER BY movies.id DESC LIMIT 5");
            $featuredMovies = $featuredStatement ? $featuredStatement->fetchAll() : [];

            if ($featuredMovies === [] && $featuredWhere !== '') {
                $featuredStatement = $this->pdo->query($baseSelect . ' ORDER BY movies.id DESC LIMIT 5');
                $featuredMovies = $featuredStatement ? $featuredStatement->fetchAll() : [];
            }

            $statement = $this->pdo->query($baseSelect . ' ORDER BY movies.id DESC LIMIT 200');
            $movies = $statement ? $statement->fetchAll() : [];
            
            $recommendations = $this->recommendations();
            
            $filterCategoriesQuery = $this->pdo->query('SELECT id, name AS category_name FROM categories ORDER BY name');
            $filterCategories = $filterCategoriesQuery ? $filterCategoriesQuery->fetchAll() : [];
            
            $filterTranslatorsQuery = $this->pdo->query('SELECT id, name AS translator_name FROM translators ORDER BY name');
            $filterTranslators = $filterTranslatorsQuery ? $filterTranslatorsQuery->fetchAll() : [];
            
        } catch (PDOException $exception) {
            error_log('Homepage movies query failed: ' . $exception->getMessage());
            return [
                'featured' => null, 'featured_movies' => [], 'recommendations' => [], 'movies' => [],
                'categories' => [], 'translators' => [], 'filter_categories' => [], 'filter_translators' => []
            ];
        }

        $categories = [];
        $translators = [];
        foreach ($movies as $movie) {
            if (is_string($movie['category_name'] ?? null) && $movie['category_name'] !== '') {
                $categories[$movie['category_name']][] = $movie;
            }
            if (is_string($movie['translator_name'] ?? null) && $movie['translator_name'] !== '') {
                $translators[$movie['translator_name']][] = $movie;
            }
        }

        return [
            'featured' => $movies[0] ?? null,
            'featured_movies' => $featuredMovies,
            'recommendations' => $recommendations,
            'movies' => $movies,
            'categories' => $categories,
            'translators' => $translators,
            'filter_categories' => $filterCategories,
            'filter_translators' => $filterTranslators,
        ];
    }

    public function handleAjaxSearch(): array
    {
        $query = $_GET['query'] ?? '';
        $categoryId = $_GET['category'] ?? '';
        $translatorId = $_GET['translator'] ?? '';

        if (!is_string($query)) {
            return ['movies' => []];
        }
        $query = trim($query);

        $conditions = [];
        $parameters = [];

        if ($query !== '') {
            $conditions[] = 'movies.title LIKE :query';
            $parameters['query'] = '%' . $query . '%';
        }
        if ($categoryId !== '' && $categoryId !== null) {
            $conditions[] = 'movies.category_id = :category_id';
            $parameters['category_id'] = (int)$categoryId;
        }
        if ($translatorId !== '' && $translatorId !== null) {
            $conditions[] = 'movies.translator_id = :translator_id';
            $parameters['translator_id'] = (int)$translatorId;
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        try {
            $statement = $this->pdo->prepare($this->movieSelectSql() . $where . ' ORDER BY movies.id DESC LIMIT 48');
            $statement->execute($parameters);
            return ['movies' => $statement->fetchAll()];
        } catch (PDOException $exception) {
            return ['movies' => []];
        }
    }
    public function recordView(int $movieId): void
    {
        $history = is_array($_SESSION['viewed_movies'] ?? null) ? $_SESSION['viewed_movies'] : [];
        $history = array_values(array_filter($history, static fn ($id): bool => is_int($id) && $id > 0));
        $history = array_values(array_filter($history, static fn (int $id): bool => $id !== $movieId));
        array_unshift($history, $movieId);
        $_SESSION['viewed_movies'] = array_slice($history, 0, 30);
    }

    public function episodeResponse(int $movieId, ?int $episodeId, string $server = 'server_1'): ?array
    {
        $data = $this->streamData($movieId, $episodeId, $server);
        if ($data === null
            || ($data['movie']['type'] === 'series' && $data['selected_episode'] === null)
            || ($episodeId !== null && (int) ($data['selected_episode']['id'] ?? 0) !== $episodeId)) {
            return null;
        }

        $episode = $data['selected_episode'];
        return [
            'movie' => [
                'id' => (int) $data['movie']['id'],
                'title' => $data['movie']['title'],
                'description' => $data['movie']['description'],
                'poster_image' => $data['movie']['poster_image'],
            ],
            'episode' => $episode === null ? null : [
                'id' => (int) $episode['id'],
                'episode_number' => (int) $episode['episode_number'],
                'season_number' => (int) $episode['season_number'],
                'episode_title' => $episode['episode_title'],
            ],
            'stream' => $data['stream'],
            'servers' => $data['servers'],
        ];
    }

    private function recommendations(): array
    {
        try {
            $statement = $this->pdo->query($this->movieSelectSql() . ' ORDER BY movies.id ASC LIMIT 12');
            return $statement ? $statement->fetchAll() : [];
        } catch (PDOException $e) {
            return [];
        }
    }

    private function movieSelectSql(string $extraSelect = ''): string
    {
        $columns = $this->getMovieColumns();
        $releaseYear = in_array('release_year', $columns, true) ? 'movies.release_year' : 'NULL';
        $backdrop = in_array('backdrop_image', $columns, true) ? 'movies.backdrop_image' : 'movies.poster_image';
        $runtime = in_array('runtime_minutes', $columns, true) ? 'movies.runtime_minutes' : 'NULL';
        $featured = in_array('is_featured', $columns, true) ? 'movies.is_featured' : '0';
        $downloadUrl = in_array('download_url', $columns, true) ? 'movies.download_url' : 'NULL';

        $extraSelect = $extraSelect !== '' ? ', ' . $extraSelect : '';

        return "SELECT movies.id, movies.title, movies.description, movies.poster_image, movies.type,
                       movies.category_id, movies.translator_id,
                       {$releaseYear} AS release_year, {$runtime} AS runtime_minutes,
                       {$backdrop} AS backdrop_image, {$featured} AS is_featured,
                       {$downloadUrl} AS download_url, 
                       categories.name AS category_name, 
                       translators.name AS translator_name{$extraSelect}
                FROM movies
                LEFT JOIN categories ON categories.id = movies.category_id
                LEFT JOIN translators ON translators.id = movies.translator_id";
    }

    private function getMovieColumns(): array
    {
        if ($this->movieColumns === null) {
            $this->movieColumns = $this->getTableColumns('movies');
        }
        return $this->movieColumns;
    }

    private function getEpisodeColumns(): array
    {
        if ($this->episodeColumns === null) {
            $this->episodeColumns = $this->getTableColumns('episodes');
        }
        return $this->episodeColumns;
    }

    private function getTableColumns(string $table): array
    {
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'pgsql') {
                $statement = $this->pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = :table_name");
                if ($statement && $statement->execute(['table_name' => $table])) {
                    return array_column($statement->fetchAll(PDO::FETCH_ASSOC), 'column_name');
                }
            }
            return [];
        } catch (PDOException $e) {
            return [];
        }
    }

    public function streamData(int $movieId, ?int $episodeId = null, string $server = 'server_1'): ?array
    {
        try {
            $statement = $this->pdo->prepare($this->movieSelectSql() . ' WHERE movies.id = :id LIMIT 1');
            $statement->execute(['id' => $movieId]);
            $movie = $statement->fetch();
            if (!is_array($movie)) return null;

            return [
                'movie' => $movie, 'episodes' => [], 'selected_episode' => null, 'servers' => [], 'stream' => ['type' => 'video', 'url' => $movie['video_source'] ?? '']
            ];
        } catch (PDOException $e) {
            return null;
        }
    }
}
