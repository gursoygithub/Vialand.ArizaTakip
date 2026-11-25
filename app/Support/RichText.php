<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Text written in the rich editor. Only plain formatting and links survive:
 * no scripts, styles, images or embedded content. Text written before the
 * editor existed is plain and is shown as typed.
 */
class RichText
{
    private const ELEMENTS = ['p', 'div', 'br', 'strong', 'b', 'em', 'i', 'u', 'del', 's', 'ul', 'ol', 'li', 'blockquote', 'h2', 'h3', 'pre', 'code'];

    public static function clean(?string $html): string
    {
        $config = (new HtmlSanitizerConfig)
            ->withMaxInputLength(100000)
            ->allowElement('a', ['href'])
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->forceAttribute('a', 'target', '_blank')
            ->forceAttribute('a', 'rel', 'noopener noreferrer');

        foreach (self::ELEMENTS as $element) {
            $config = $config->allowElement($element);
        }

        return trim((new HtmlSanitizer($config))->sanitize((string) $html));
    }

    /**
     * Readable text without markup, for length checks and previews.
     */
    public static function plain(?string $html): string
    {
        $text = preg_replace('~<(br|/p|/div|/li|/h[23])\b[^>]*>~i', "$0\n", (string) $html);

        return trim(str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /**
     * Safe to print: editor content is cleaned again, plain text is escaped.
     */
    public static function render(?string $text): HtmlString
    {
        $text = trim((string) $text);

        if (preg_match('~^<(p|div|ul|ol|h2|h3|blockquote|pre)(\s[^>]*)?>~i', $text)) {
            return new HtmlString(self::clean($text));
        }

        return new HtmlString('<div style="white-space:pre-line;">'.e($text).'</div>');
    }
}
