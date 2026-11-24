<?php

namespace Tests;

use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Faker's safeEmail() addresses (example.com / .org / .net) count as
     * company addresses, so test people can join teams and receive mail.
     * Tests about the domain rule set their own list.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            GeneralSettings::fake([
                'mail_allowed_domains' => ['gursoygrup.com.tr', 'example.com', 'example.org', 'example.net'],
            ]);
        }
    }
}
