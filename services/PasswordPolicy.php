<?php

namespace app\services;

class PasswordPolicy
{
    public static function validate(string $password): ?string
    {
        // Единая политика паролей повторяет Go-сервис: длина важнее искусственных правил состава.
        if (strlen($password) < 12) {
            return 'password must contain at least 12 characters';
        }

        if (strlen($password) > 128) {
            return 'password must not exceed 128 characters';
        }

        return null;
    }
}
