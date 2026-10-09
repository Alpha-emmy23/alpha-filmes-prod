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
            $featuredStatement = $this->pdo->query(
                $baseSelect . " {$featuredWhere} ORDER BY movies.id DESC LIMIT 5"
            );
            $featuredMovies = $featuredStatement->fetchAll();

            if ($featuredMovies === [] && $featuredWhere !== '') {
                $featuredStatement = $this->pdo->query($baseSelect . ' ORDER BY movies.id DESC LIMIT 5');
                $featuredMovies = $featuredStatement->fetchAll();
            }

            $statement = $this->pdo->query($baseSelect . ' ORDER BY movies.id DESC LIMIT 200');
            $movies = $statement->fetchAll();
            $recommendations = $this->recommendations();
            $filterCategories = $this->pdo->query(
                'SELECT id, category_name FROM categories ORDER BY category_name'
            )->fetchAll();
            $filterTranslators = $this->pdo->query(
                'SELECT id, translator_name FROM translators ORDER BY translator_name'
            )->fetchAll();
        } catch (PDOException $exception) {
            error_log('Homepage movies query failed: ' . $exception->getMessage());
            throw new RuntimeException('Homepage movies could not be loaded.', 0, $exception);
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
            throw new InvalidArgumentException('Search query must be text.');
        }
        $query = trim($query);
        if ($this->characterLength($query) > 120) {
            throw new InvalidArgumentException('Search query must be 120 characters or fewer.');
        }

        $categoryId = $this->optionalSearchId($categoryId, 'category');
        $translatorId = $this->optionalSearchId($translatorId, 'translator');
        $conditions = [];
        $parameters = [];

        if ($query !== '') {
            $conditions[] = 'movies.title LIKE :query';
            $parameters['query'] = '%' . $query . '%';
        }
        if ($categoryId !== null) {
            $conditions[] = 'movies.category_id = :category_id';
            $parameters['category_id'] = $categoryId;
        }
        if ($translatorId !== null) {
            $conditions[] = 'movies.translator_id = :translator_id';
            $parameters['translator_id'] = $translatorId;
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        try {
            $statement = $this->pdo->prepare(
                $this->movieSelectSql() . $where . ' ORDER BY movies.id DESC LIMIT 48'
            );
            $statement->execute($parameters);

            return ['movies' => $statement->fetchAll()];
        } catch (PDOException $exception) {
            error_log('Combined movie search failed: ' . $exception->getMessage());
            throw new RuntimeException('Movie search is temporarily unavailable.', 0, $exception);
        }
    }

    private function optionalSearchId(mixed $value, string $label): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException("Choose a valid {$label}.");
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException("Choose a valid {$label}.");
        }

        return $id;
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
            || ($episodeId !== null
                && (int) ($data['selected_episode']['id'] ?? 0) !== $episodeId)) {
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
        $history = is_array($_SESSION['viewed_movies'] ?? null) ? $_SESSION['viewed_movies'] : [];
        $history = array_values(array_unique(array_filter(
            $history,
            static fn ($id): bool => is_int($id) && $id > 0
        )));
        if ($history === []) {
            return [];
        }

        $historyList = implode(', ', array_map('intval', $history));

        try {
            $sql = $this->movieSelectSql() . "
                WHERE (
                    movies.category_id IN (SELECT category_id FROM movies WHERE id IN ({$historyList}) AND category_id IS NOT NULL)
                    OR movies.translator_id IN (SELECT translator_id FROM movies WHERE id IN ({$historyList}) AND translator_id IS NOT NULL)
                )
                AND movies.id NOT IN ({$historyList})
                ORDER BY (
                    CASE WHEN movies.category_id IN (SELECT category_id FROM movies WHERE id IN ({$historyList}) AND category_id IS NOT NULL) THEN 1 ELSE 0 END
                    + CASE WHEN movies.translator_id IN (SELECT translator_id FROM movies WHERE id IN ({$historyList}) AND translator_id IS NOT NULL) THEN 1 ELSE 0 END
                ) DESC, movies.id DESC
                LIMIT 12";
            $statement = $this->pdo->query($sql);

            return $statement->fetchAll();
        } catch (PDOException $exception) {
            error_log('Movie recommendations query failed: ' . $exception->getMessage());
            throw new RuntimeException('Recommendations could not be loaded.', 0, $exception);
        }
    }

    private function movieSelectSql(string $extraSelect = ''): string
    {
        $columns = $this->getMovieColumns();
        $releaseYear = in_array('release_year', $columns, true) ? 'movies.release_year' : 'NULL';
        $backdrop = in_array('backdrop_image', $columns, true)
            ? 'movies.backdrop_image'
            : 'movies.poster_image';
        $runtime = in_array('runtime_minutes', $columns, true)
            ? 'movies.runtime_minutes'
            : (in_array('duration_minutes', $columns, true) ? 'movies.duration_minutes' : 'NULL');
        $featured = in_array('is_featured', $columns, true) ? 'movies.is_featured' : '0';
        $downloadUrl = in_array('download_url', $columns, true) ? 'movies.download_url' : 'NULL';

        $extraSelect = $extraSelect !== '' ? ', ' . $extraSelect : '';

        return "SELECT movies.id, movies.title, movies.description, movies.poster_image, movies.type,
                       movies.category_id, movies.translator_id,
                       {$releaseYear} AS release_year, {$runtime} AS runtime_minutes,
                       {$backdrop} AS backdrop_image, {$featured} AS is_featured,
                       {$downloadUrl} AS download_url, categories.category_name, translators.translator_name{$extraSelect}
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
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $statement = $this->pdo->query("PRAGMA table_info({$table})");
            return $statement === false
                ? []
                : array_column($statement->fetchAll(PDO::FETCH_ASSOC), 'name');
        }
        if ($driver === 'pgsql') {
            $statement = $this->pdo->prepare(
                'SELECT column_name
                 FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = :table_name'
            );
            if ($statement === false || !$statement->execute(['table_name' => $table])) {
                return [];
            }

            return array_column($statement->fetchAll(PDO::FETCH_ASSOC), 'column_name');
        }

        return array_column(
            $this->pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(),
            'Field'
        );
    }

    public function searchMovies(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        try {
            $releaseYear = in_array('release_year', $this->getMovieColumns(), true)
                ? 'movies.release_year'
                : 'NULL';
            $statement = $this->pdo->prepare(
                "SELECT movies.id, movies.title, movies.description, movies.poster_image, movies.type,
                        {$releaseYear} AS release_year,
                        categories.category_name, translators.translator_name
                 FROM movies
                 LEFT JOIN categories ON categories.id = movies.category_id
                 LEFT JOIN translators ON translators.id = movies.translator_id
                 WHERE movies.title LIKE :title OR movies.description LIKE :description
                       OR categories.category_name LIKE :category
                       OR translators.translator_name LIKE :translator
                 ORDER BY movies.id DESC
                 LIMIT 24"
            );
            $term = '%' . $query . '%';
            $statement->execute([
                'title' => $term,
                'description' => $term,
                'category' => $term,
                'translator' => $term,
            ]);

            return $statement->fetchAll();
        } catch (PDOException $exception) {
            error_log('Movie search query failed: ' . $exception->getMessage());
            throw new RuntimeException('Movie search is unavailable.', 0, $exception);
        }
    }

    public function streamData(int $movieId, ?int $episodeId = null, string $server = 'server_1'): ?array
    {
        try {
            $movieColumns = $this->getMovieColumns();
            $movieServers = [
                'server_1' => 'movies.video_source',
                'server_2' => in_array('server_2_url', $movieColumns, true) ? 'movies.server_2_url' : 'NULL',
                'vip_hd' => in_array('vip_url', $movieColumns, true) ? 'movies.vip_url' : 'NULL',
            ];
            $episodeColumns = $this->getEpisodeColumns();
            $episodeTitle = in_array('episode_title', $episodeColumns, true)
                ? 'episodes.episode_title'
                : (in_array('title', $episodeColumns, true)
                    ? 'episodes.title'
                    : "CONCAT('Episode ', episodes.episode_number, ': ', movies.title)");
            $episodeThumbnail = in_array('episode_thumbnail', $episodeColumns, true)
                ? 'episodes.episode_thumbnail'
                : 'movies.poster_image';
            $episodeServers = [
                'server_1' => 'episodes.video_url',
                'server_2' => in_array('video_url_server2', $episodeColumns, true) ? 'episodes.video_url_server2' : 'NULL',
                'vip_hd' => in_array('video_url_vip', $episodeColumns, true) ? 'episodes.video_url_vip' : 'NULL',
            ];
            $movieServerSelect = implode(', ', array_map(
                static fn (string $column, string $key): string => "{$column} AS {$key}",
                $movieServers,
                array_keys($movieServers)
            ));
            $statement = $this->pdo->prepare(
                $this->movieSelectSql('movies.video_source, ' . $movieServerSelect)
                . ' WHERE movies.id = :id LIMIT 1'
            );
            $statement->execute(['id' => $movieId]);
            $movie = $statement->fetch();

            if (!is_array($movie)) {
                return null;
            }

            $episodes = [];
            $selectedEpisode = null;
            if ($movie['type'] === 'series') {
                $episodeServerSelect = implode(', ', array_map(
                    static fn (string $column, string $key): string => "{$column} AS {$key}",
                    $episodeServers,
                    array_keys($episodeServers)
                ));
                $episodeQuery = $this->pdo->prepare(
                    "SELECT episodes.id, episodes.episode_number, episodes.video_url,
                            {$episodeTitle} AS episode_title, {$episodeThumbnail} AS thumbnail_image,
                            {$episodeServerSelect}, seasons.season_number
                     FROM episodes
                     INNER JOIN seasons ON seasons.id = episodes.season_id
                     INNER JOIN movies ON movies.id = seasons.movie_id
                     WHERE seasons.movie_id = :movie_id
                     ORDER BY seasons.season_number, episodes.episode_number"
                );
                $episodeQuery->execute(['movie_id' => $movieId]);
                $episodes = $episodeQuery->fetchAll();

                foreach ($episodes as $episode) {
                    if ($episodeId !== null && (int) $episode['id'] === $episodeId) {
                        $selectedEpisode = $episode;
                        break;
                    }
                }
                $selectedEpisode ??= $episodes[0] ?? null;
            }

            $sourceRow = $movie['type'] === 'series' ? $selectedEpisode : $movie;
            $serverSources = [];
            foreach (['server_1', 'server_2', 'vip_hd'] as $serverKey) {
                $source = is_array($sourceRow) ? ($sourceRow[$serverKey] ?? null) : null;
                if (is_string($source) && $source !== '') {
                    $parsedSource = $this->streamSource($source);
                    if ($parsedSource !== null) {
                        $serverSources[$serverKey] = $parsedSource;
                    }
                }
            }

            return [
                'movie' => $movie,
                'episodes' => $episodes,
                'selected_episode' => $selectedEpisode,
                'servers' => $serverSources,
                'stream' => $serverSources[$server] ?? ($serverSources['server_1'] ?? (reset($serverSources) ?: null)),
            ];
        } catch (PDOException $exception) {
            error_log('Movie streaming data query failed: ' . $exception->getMessage());
            throw new RuntimeException('Movie streaming details could not be loaded.', 0, $exception);
        }
    }

    public function commentsFor(int $movieId): array
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT comments.id, users.username, comments.comment_text AS body
                 FROM comments
                 INNER JOIN users ON users.id = comments.user_id
                 WHERE comments.movie_id = :movie_id
                 ORDER BY comments.id DESC
                 LIMIT 50'
            );
            $statement->execute(['movie_id' => $movieId]);

            return $statement->fetchAll();
        } catch (PDOException $exception) {
            error_log('Movie comments query failed: ' . $exception->getMessage());
            throw new RuntimeException('Comments could not be loaded.', 0, $exception);
        }
    }

    public function addComment(array $input): array
    {
        $user = $this->currentUser();
        if ($user === null || !is_scalar($user['id'] ?? null) || !is_string($user['username'] ?? null)) {
            return ['ok' => false, 'status' => 401, 'message' => 'Sign in to join the conversation.'];
        }

        if (!$this->validCsrfToken($input['csrf_token'] ?? null)) {
            return ['ok' => false, 'status' => 419, 'message' => 'Your session expired. Refresh and try again.'];
        }

        $movieId = filter_var($input['movie_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $body = is_string($input['comment'] ?? null) ? trim($input['comment']) : '';
        if ($movieId === false || $body === '' || $this->characterLength($body) > 2000) {
            return ['ok' => false, 'status' => 422, 'message' => 'Write a comment of 1 to 2,000 characters.'];
        }

        try {
            $movieCheck = $this->pdo->prepare('SELECT id FROM movies WHERE id = :id LIMIT 1');
            $movieCheck->execute(['id' => $movieId]);
            if ($movieCheck->fetchColumn() === false) {
                return ['ok' => false, 'status' => 404, 'message' => 'This movie could not be found.'];
            }

            $statement = $this->pdo->prepare(
                'INSERT INTO comments (movie_id, user_id, comment_text)
                 VALUES (:movie_id, :user_id, :comment_text)'
            );
            $statement->execute([
                'movie_id' => $movieId,
                'user_id' => (int) $user['id'],
                'comment_text' => $body,
            ]);

            return [
                'ok' => true,
                'comment' => [
                    'id' => (int) $this->pdo->lastInsertId(),
                    'username' => $user['username'],
                    'body' => $body,
                ],
            ];
        } catch (PDOException $exception) {
            error_log('Movie comment creation failed: ' . $exception->getMessage());
            throw new RuntimeException('Your comment could not be saved.', 0, $exception);
        }
    }

    private function streamSource(string $source): ?array
    {
        $parts = parse_url($source);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        $youtubeHosts = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'youtube-nocookie.com'];
        if (in_array($host, $youtubeHosts, true)) {
            parse_str($parts['query'] ?? '', $query);
            $videoId = is_string($query['v'] ?? null) ? $query['v'] : '';
            if ($host === 'youtu.be') {
                $videoId = trim($path, '/');
            } elseif (preg_match('~\A/(?:embed|shorts)/([A-Za-z0-9_-]{11})~', $path, $match)) {
                $videoId = $match[1];
            }

            if (preg_match('/\A[A-Za-z0-9_-]{11}\z/', $videoId) !== 1) {
                return null;
            }

            return [
                'type' => 'embed',
                'url' => 'https://www.youtube-nocookie.com/embed/' . rawurlencode($videoId),
            ];
        }

        if ($host === 'drive.google.com' || $host === 'docs.google.com') {
            $fileId = '';
            if (preg_match('~/file/d/([A-Za-z0-9_-]+)~', $path, $match)) {
                $fileId = $match[1];
            } else {
                parse_str($parts['query'] ?? '', $query);
                $fileId = is_string($query['id'] ?? null) ? $query['id'] : '';
            }

            if (preg_match('/\A[A-Za-z0-9_-]{10,}\z/', $fileId) !== 1) {
                return null;
            }

            return [
                'type' => 'embed',
                'url' => 'https://drive.google.com/file/d/' . rawurlencode($fileId) . '/preview',
            ];
        }

        return [
            'type' => preg_match('/\.m3u8(?:$|\?)/i', $source) === 1 ? 'hls' : 'video',
            'url' => $source,
        ];
    }

    private function validCsrfToken(mixed $token): bool
    {
        return is_string($token)
            && isset($_SESSION['csrf_token'])
            && is_string($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    private function characterLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        $matches = preg_match_all('/./us', $value, $characters);
        return $matches === false ? strlen($value) : $matches;
    }
}
