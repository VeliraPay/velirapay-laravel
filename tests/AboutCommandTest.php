<?php

declare(strict_types=1);

namespace VeliraPay\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use VeliraPay\Laravel\VeliraPayServiceProvider;

final class AboutCommandTest extends TestCase
{
    public function test_the_about_command_shows_the_configuration(): void
    {
        config(['velirapay.base_url' => 'https://api.velirapay.test']);

        $this->assertSame(0, Artisan::call('about', ['--only' => 'velirapay']));

        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/API key\W+Test/', $output);
        $this->assertMatchesRegularExpression('/Base URL\W+https:\/\/api\.velirapay\.test/', $output);
        $this->assertMatchesRegularExpression('/Webhook secret\W+SET/', $output);
        $this->assertStringContainsString('http://localhost/velirapay/webhook', $output);
    }

    public function test_the_version_is_the_newest_release_in_the_changelog(): void
    {
        preg_match('/^## (\d+\.\d+\.\d+) - \d{4}-\d{2}-\d{2}\s*$/m', (string) file_get_contents(__DIR__.'/../CHANGELOG.md'), $matches);

        $this->assertSame(VeliraPayServiceProvider::VERSION, $matches[1] ?? null);
    }
}
