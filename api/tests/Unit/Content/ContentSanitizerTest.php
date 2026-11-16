<?php

namespace Tests\Unit\Content;

use App\Domain\Content\Support\ContentSanitizer;
use Tests\TestCase;

/**
 * The sanitized HTML is rendered by React as trusted markup, so it is treated
 * as XSS-sensitive: dangerous tags, handlers and URLs are removed while normal
 * editorial markup survives.
 */
class ContentSanitizerTest extends TestCase
{
    public function test_it_removes_dangerous_markup_but_keeps_normal_markup(): void
    {
        $html = '<p onclick="steal()">Keep <strong>bold</strong></p>'
            .'<script>alert(1)</script>'
            .'<style>body{color:red}</style>'
            .'<iframe src="https://evil.test"></iframe>'
            .'<a href="javascript:alert(1)">bad</a>'
            .'<a href="https://example.com/x" title="ok">good</a>'
            .'<img src="https://example.com/a.png" onerror="alert(1)">';

        $clean = ContentSanitizer::sanitize($html);

        // Normal markup is preserved.
        $this->assertStringContainsString('<strong>bold</strong>', $clean);
        $this->assertStringContainsString('href="https://example.com/x"', $clean);
        $this->assertStringContainsString('<img src="https://example.com/a.png">', $clean);

        // Dangerous markup is gone.
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('<style', $clean);
        $this->assertStringNotContainsString('<iframe', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
    }

    public function test_it_keeps_the_text_of_a_disarmed_link(): void
    {
        $clean = ContentSanitizer::sanitize('<a href="javascript:alert(1)">bad link</a>');

        $this->assertStringContainsString('bad link', $clean);
        $this->assertStringNotContainsString('href', $clean);
    }

    public function test_it_unwraps_unknown_but_harmless_tags(): void
    {
        $clean = ContentSanitizer::sanitize('<article><p>Body</p></article>');

        $this->assertStringContainsString('<p>Body</p>', $clean);
        $this->assertStringNotContainsString('<article', $clean);
    }

    public function test_it_returns_an_empty_string_for_empty_input(): void
    {
        $this->assertSame('', ContentSanitizer::sanitize(null));
        $this->assertSame('', ContentSanitizer::sanitize('   '));
    }

    public function test_it_disarms_an_entity_obfuscated_javascript_url(): void
    {
        // &#09; decodes to a tab inside the attribute; the normaliser strips it
        // before matching, so the href is removed entirely.
        $clean = ContentSanitizer::sanitize('<a href="java&#09;script:alert(1)">x</a>');

        $this->assertStringContainsString('x', $clean);
        $this->assertStringNotContainsString('href', $clean);
    }
}
