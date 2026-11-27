<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use JustinHoltWeb\Appleseed\Tests\TestCase;

/*
 | Every suite runs on a real Craft 6 install with Appleseed installed, on in-memory SQLite
 | (CraftCms\Cms\Plugin\Testing\PluginTestCase), so nothing touches a shared database. HTTP is
 | faked: stray requests fail the test.
 */
uses(TestCase::class)->in('Unit', 'Feature');

/**
 * A control panel response's text. Craft 6 CP screens are Inertia pages: the screen's HTML is a
 * JSON string inside the document, with `\/` and `\uXXXX` escapes.
 */
function cpPage(TestResponse $response): string
{
    $content = (string) $response->getContent();
    $content = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', fn (array $m) => mb_chr((int) hexdec($m[1]), 'UTF-8'), $content) ?? $content;

    return html_entity_decode(str_replace(['\\/', '\\"'], ['/', '"'], $content), ENT_QUOTES | ENT_HTML5);
}
