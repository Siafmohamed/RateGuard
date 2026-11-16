<?php

namespace App\Domain\Content\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Reduces WordPress post/page markup to a safe, allow-listed subset.
 *
 * The HTML is rendered by React as trusted markup, so it is treated as
 * XSS-sensitive: anything not explicitly allowed is dropped. A hand-rolled
 * allow-list over PHP's built-in DOM extension is enough here, so no third
 * party package is pulled in.
 *
 * Removed outright (with their contents): script, style, iframe and friends.
 * Removed from every element: on* event handlers, non-allow-listed attributes
 * and javascript:/vbscript: URLs. Unknown-but-harmless tags are unwrapped
 * (their text/children are kept) rather than dropped.
 */
final class ContentSanitizer
{
    /**
     * Tags kept in the output.
     *
     * @var list<string>
     */
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'span', 'div',
        'a', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'ins',
        'small', 'sub', 'sup', 'mark', 'abbr', 'cite', 'q', 'time', 'code',
        'pre', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'figure', 'figcaption', 'img', 'picture', 'source',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
        'colgroup', 'col',
    ];

    /**
     * Tags removed together with their subtree - never unwrapped.
     *
     * @var list<string>
     */
    private const DROP_TAGS = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed',
        'applet', 'form', 'input', 'button', 'select', 'option', 'textarea',
        'noscript', 'template', 'svg', 'math', 'link', 'meta', 'base',
        'title', 'head', 'html', 'body',
    ];

    /**
     * Attributes kept on the elements above.
     *
     * @var list<string>
     */
    private const ALLOWED_ATTRS = [
        'href', 'src', 'srcset', 'sizes', 'alt', 'title', 'width', 'height',
        'class', 'target', 'rel', 'loading', 'decoding', 'datetime', 'cite',
        'colspan', 'rowspan', 'start', 'id',
    ];

    /**
     * Attributes whose value must be a safe URL.
     *
     * @var list<string>
     */
    private const URL_ATTRS = ['href', 'src', 'srcset', 'action', 'formaction', 'poster'];

    private const ROOT_ID = 'rg-content-root';

    /**
     * Return the sanitized HTML fragment.
     */
    public static function sanitize(?string $html): string
    {
        $html = (string) $html;

        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        // The charset meta forces libxml to treat the input as UTF-8, and the
        // wrapper element gives us a stable node to read back from.
        $dom->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
            .'<div id="'.self::ROOT_ID.'">'.$html.'</div>',
            LIBXML_NONET | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = (new DOMXPath($dom))
            ->query('//div[@id="'.self::ROOT_ID.'"]')
            ?->item(0);

        if (! $root instanceof DOMElement) {
            return '';
        }

        self::clean($root);

        return self::innerHtml($root);
    }

    /**
     * Recursively remove/relax nodes under (and including) the given element.
     */
    private static function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                // Comments and processing instructions carry no value; drop them.
                if (! $child instanceof \DOMText) {
                    $node->removeChild($child);
                }

                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::DROP_TAGS, true)) {
                $node->removeChild($child);

                continue;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                // Unknown but harmless: keep the children, drop the wrapper.
                self::clean($child);

                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }

                $node->removeChild($child);

                continue;
            }

            self::scrubAttributes($child);
            self::clean($child);
        }
    }

    /**
     * Remove disallowed attributes and unsafe URLs from an element.
     */
    private static function scrubAttributes(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = (string) $attribute->nodeValue;

            $isEventHandler = str_starts_with($name, 'on');
            $isAllowed = in_array($name, self::ALLOWED_ATTRS, true);
            $isUnsafeUrl = in_array($name, self::URL_ATTRS, true)
                && self::hasUnsafeUrl($value);

            if ($isEventHandler || ! $isAllowed || $isUnsafeUrl) {
                $element->removeAttribute($attribute->nodeName);
            }
        }
    }

    /**
     * Detect javascript:/vbscript: URLs, tolerant of whitespace and control
     * characters embedded by an attacker (e.g. "java\tscript:").
     */
    private static function hasUnsafeUrl(string $value): bool
    {
        $normalized = (string) preg_replace('/[\x00-\x20\x7F]+/u', '', strtolower($value));

        return str_contains($normalized, 'javascript:')
            || str_contains($normalized, 'vbscript:');
    }

    /**
     * Serialize the children of an element back to an HTML fragment.
     */
    private static function innerHtml(DOMElement $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument->saveHTML($child);
        }

        return $html;
    }
}
