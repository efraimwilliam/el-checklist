<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['attendance.url' => 'https://attendance.test', 'attendance.token' => 'token-uji']);
        // Tidak boleh ada panggilan ke server sungguhan.
        Http::preventStrayRequests();
    }
}
