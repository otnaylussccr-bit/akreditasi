<?php

namespace App\Support;

final class PasswordPolicy
{
    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        return [
            'required',
            'string',
            'confirmed',
            'min:8',
            'regex:/[A-Za-z]/',
            'regex:/[0-9]/',
        ];
    }
}
