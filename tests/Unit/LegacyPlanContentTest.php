<?php

namespace Tests\Unit;

use App\Support\LegacyPlanContent;
use League\CommonMark\CommonMarkConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyPlanContentTest extends TestCase
{
    #[DataProvider('unchangedContents')]
    public function testNonLegacyContentIsUnchanged(string $content): void
    {
        self::assertSame($content, LegacyPlanContent::forMarkdown($content));
    }

    public static function unchangedContents(): array
    {
        return [
            [''],
            ['普通套餐说明'],
            ["<!DOCTYPE html><html><head><style>.old{color:red}</style></head><body>旧独立网页</body></html>"],
            ["## 套餐\n\n- **100G** 流量\n- 不限设备"],
            ['[{"feature":"100G","support":true}]'],
            ["    <div>intentional indented code</div>"],
            ["```html\n    <div>example</div>\n```"],
            ["<style>.a{color:red}</style>\n\n```html\n    <div>example</div>\n```"],
            ["text before HTML\n\n<style>.a{color:red}</style>\n\n    <div>example</div>"],
        ];
    }

    #[DataProvider('templateCases')]
    public function testLegacyTagIndentationDoesNotBecomeCode(string $prefix, string $eol, string $indent): void
    {
        $input = $prefix . $eol . '<div class="plan">' . $eol . $eol .
            $indent . '<!-- old layout comment -->' . $eol . $eol .
            $indent . '<div class="tag">套餐权益</div>' . $eol .
            $indent . '<ul>' . $eol . $indent . '<li>100G，不限设备</li>' . $eol .
            $indent . '</ul>' . $eol . '</div>';
        $fixed = LegacyPlanContent::forMarkdown($input);
        self::assertStringContainsString($eol . '<div class="tag">套餐权益</div>', $fixed);
        self::assertStringContainsString('<li>100G，不限设备</li>', $fixed);
        self::assertSame($fixed, LegacyPlanContent::forMarkdown($fixed));
        $renderer = new CommonMarkConverter(['html_input' => 'allow']);
        $html = (string) $renderer->convert($fixed);
        self::assertStringNotContainsString('<pre><code>', $html);
        self::assertStringNotContainsString('&lt;div', $html);
        if ($eol === "\r\n") {
            self::assertSame(substr_count($input, "\r\n"), substr_count($fixed, "\r\n"));
        }
    }

    public static function templateCases(): array
    {
        return [
            ['<style>.plan { color: red; }</style>', "\n", '    '],
            ['<style>.plan { color: red; }</style>', "\r\n", "\t"],
            ['<STYLE>.plan { color: red; }</STYLE>', "\n", '       '],
            ['<style>\n.plan { color: red; }\n</style>', "\n", '  '],
        ];
    }

    public function testRawTextAndWhitespaceSensitiveElementsArePreserved(): void
    {
        foreach (['style', 'script', 'pre', 'textarea', 'code'] as $tag) {
            $block = '<' . $tag . ">\n    <span>keep indentation</span>\n\n        exact spaces\n</" . $tag . '>';
            $content = '<style>.plan{color:red}</style>' . "\n<div>\n\n    " . $block . "\n\n    <p>正常说明</p>\n</div>";
            $fixed = LegacyPlanContent::forMarkdown($content);
            self::assertStringContainsString($block, $fixed);
            self::assertStringContainsString("\n<p>正常说明</p>", $fixed);
        }
    }

    public function testNoPriceTrafficOrTemplateVariableChanges(): void
    {
        $content = '<style>.price{color:red}</style>' . "\n<div>\n\n    <p>¥19.9 / {{transfer}} GB / {{speed}} Mbps / {{devices}} 台</p>\n</div>";
        $fixed = LegacyPlanContent::forMarkdown($content);
        self::assertSame(preg_replace('/^[\t ]+(?=<)/m', '', $content), $fixed);
        self::assertStringContainsString('¥19.9 / {{transfer}} GB / {{speed}} Mbps / {{devices}} 台', $fixed);
    }
}
