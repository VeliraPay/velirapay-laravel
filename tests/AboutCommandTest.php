<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;

final class AboutCommandTest extends TestCase
{
    public function test_the_about_command_shows_the_configuration(): void
    {
        $this->assertSame(0, Artisan::call('about', ['--only' => 'velirapay']));

        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/API key\W+Test/', $output);
        $this->assertMatchesRegularExpression('/Webhook secret\W+SET/', $output);
        $this->assertStringContainsString('http://localhost/velirapay/webhook', $output);
    }
}
