<?php

class Auth
{
    public static function check(): bool
    {
        return !empty($_SESSION['auth_user']);
    }

    public static function user(): string
    {
        return (string) ($_SESSION['auth_user'] ?? '');
    }

    public static function attempt(string $username, string $password): bool
    {
        $config = require app_path('config/auth.php');
        $expectedUser = (string) ($config['username'] ?? 'admin');
        if (!hash_equals($expectedUser, $username)) {
            return false;
        }

        $hash = (string) ($config['password_hash'] ?? '');
        if ($hash !== '') {
            if (!password_verify($password, $hash)) {
                return false;
            }
            $_SESSION['auth_user'] = $expectedUser;
            return true;
        }

        $plain = (string) ($config['password'] ?? '');
        if ($plain !== '' && hash_equals($plain, $password)) {
            $_SESSION['auth_user'] = $expectedUser;
            return true;
        }

        return false;
    }

    public static function logout(): void
    {
        unset($_SESSION['auth_user']);
    }

    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }
        if (($_GET['action'] ?? '') === 'logout' || ($_POST['action'] ?? '') === 'login') {
            return;
        }
        // Allow only the login page to render when not authenticated.
    }
}
