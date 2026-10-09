<?php

declare(strict_types=1);

final class AuthController
{
    private PDO $pdo;
    private array $errors = [];
    private array $oldInput = [];

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

    public function errors(): array
    {
        return $this->errors;
    }

    public function oldInput(): array
    {
        return $this->oldInput;
    }

    public function register(array $input): void
    {
        $this->oldInput = [
            'username' => is_string($input['username'] ?? null) ? trim($input['username']) : '',
            'email' => is_string($input['email'] ?? null) ? trim($input['email']) : '',
        ];
        $username = $this->oldInput['username'];
        $email = strtolower($this->oldInput['email']);
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $passwordConfirmation = is_string($input['password_confirmation'] ?? null)
            ? $input['password_confirmation']
            : '';

        if (!$this->validCsrfToken($input['csrf_token'] ?? null)) {
            $this->errors['form'] = 'Your session expired. Please try again.';
            return;
        }

        if (!preg_match('/\A[A-Za-z0-9_]{3,50}\z/', $username)) {
            $this->errors['username'] = 'Use 3-50 characters: letters, numbers, or underscores.';
        }

        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors['email'] = 'Enter a valid email address.';
        }

        if (strlen($password) < 8) {
            $this->errors['password'] = 'Your password must be at least 8 characters.';
        } elseif ($password !== $passwordConfirmation) {
            $this->errors['password_confirmation'] = 'The passwords do not match.';
        }

        if ($this->errors !== []) {
            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO users (username, email, password) VALUES (:username, :email, :password)'
        );

        try {
            $statement->execute([
                'username' => $username,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000' && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                $this->errors['form'] = 'That username or email address is already registered.';
                return;
            }

            throw $exception;
        }

        $this->establishSession([
            'id' => (int) $this->pdo->lastInsertId(),
            'username' => $username,
            'email' => $email,
            'role' => 'user',
        ]);
        $this->redirect('home');
    }

    public function login(array $input): void
    {
        $identifier = is_string($input['identifier'] ?? null) ? trim($input['identifier']) : '';
        $this->oldInput['identifier'] = $identifier;

        if (!$this->validCsrfToken($input['csrf_token'] ?? null)) {
            $this->errors['form'] = 'Your session expired. Please try again.';
            return;
        }

        if ($identifier === '' || !is_string($input['password'] ?? null) || $input['password'] === '') {
            $this->errors['form'] = 'Enter your username or email and password.';
            return;
        }

        $column = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false ? 'email' : 'username';
        $statement = $this->pdo->prepare(
            "SELECT id, username, email, password, role FROM users WHERE {$column} = :identifier LIMIT 1"
        );
        $statement->execute(['identifier' => $identifier]);
        $user = $statement->fetch();

        if (!is_array($user) || !password_verify($input['password'], $user['password'])) {
            $this->errors['form'] = 'The username/email or password is incorrect.';
            return;
        }

        $this->establishSession([
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
        ]);
        $this->redirect('home');
    }

    private function validCsrfToken(mixed $token): bool
    {
        return is_string($token)
            && isset($_SESSION['csrf_token'])
            && is_string($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    private function establishSession(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        unset($_SESSION['csrf_token']);
    }

    private function redirect(string $page): never
    {
        header('Location: index.php?page=' . rawurlencode($page), true, 303);
        exit;
    }
}
