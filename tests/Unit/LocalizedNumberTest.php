<?php

namespace Tests\Unit;

use App\Support\LocalizedNumber;
use Tests\TestCase;

class LocalizedNumberTest extends TestCase
{
    public function test_numbers_use_the_active_locale_separators(): void
    {
        app()->setLocale('en');
        $this->assertSame('1,234.5', LocalizedNumber::format(1234.5, 2, true));

        app()->setLocale('id');
        $this->assertSame('1.234,5', LocalizedNumber::format(1234.5, 2, true));
    }
}
