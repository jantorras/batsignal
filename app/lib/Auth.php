<?php
namespace BatSignal;

class Auth
{
    public static function attempt(string $username, string $password): bool
    {
        $stmt = Database::get()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];

        // The user's saved language wins; first login stores whatever they're using now.
        if (I18n::isValid($user['lang'] ?? null)) {
            $_SESSION['lang'] = $user['lang'];
            I18n::set($user['lang']);
        } else {
            self::saveLanguage(I18n::get());
        }
        return true;
    }

    public static function saveLanguage(string $lang): void
    {
        $_SESSION['lang'] = $lang;
        if (self::check()) {
            Database::get()->prepare('UPDATE users SET lang = ? WHERE id = ?')->execute([$lang, $_SESSION['user_id']]);
        }
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function username(): ?string
    {
        return $_SESSION['username'] ?? null;
    }
}
