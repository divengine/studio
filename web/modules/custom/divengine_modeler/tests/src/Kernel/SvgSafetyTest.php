<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_modeler\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the SVG preview safety check.
 */
#[RunTestsInSeparateProcesses]
class SvgSafetyTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'workflows', 'content_moderation', 'views', 'path_alias', 'divengine_core', 'divengine_modeler'];

  protected function wrap(string $body, string $attributes = ''): string {
    return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"' . $attributes . '>' . $body . '</svg>';
  }

  public static function safeProvider(): array {
    return [
      'shapes and text' => ['<g class="djs-element" data-element-id="Task_1"><rect width="1" height="1" style="fill: red"/><text>Pay</text></g>'],
      'marker by fragment' => ['<defs><marker id="m"/></defs><path d="M0 0" style="marker-end: url(#m)"/>'],
      'fragment use' => ['<use href="#m"/>'],
      'web link' => ['<a href="https://example.com/x"><text>x</text></a>'],
      'entity link' => ['<a href="entity:node/4"><text>x</text></a>'],
      'site path link' => ['<a xlink:href="/node/4"><text>x</text></a>'],
      'embedded png' => ['<image href="data:image/png;base64,iVBORw0KGgo="/>'],
      'plantuml source' => ['<g/><?plantuml-src abc?>'],
      'style sheet' => ['<style>.a { fill: #fff; }</style>'],
    ];
  }

  #[DataProvider('safeProvider')]
  public function testSafe(string $body): void {
    $this->assertSame([], $this->container->get('divengine_modeler.svg_safety')->check($this->wrap($body)));
  }

  public static function unsafeProvider(): array {
    return [
      'script' => ['<script>alert(1)</script>'],
      'foreign object' => ['<foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>'],
      'event handler' => ['<rect onload="alert(1)"/>'],
      'javascript link' => ['<a href="javascript:alert(1)"><text>x</text></a>'],
      'obfuscated javascript link' => ['<a href=" java&#10;script:alert(1)"><text>x</text></a>'],
      'remote image' => ['<image href="https://evil.example/x.png"/>'],
      'remote use' => ['<use xlink:href="https://evil.example/s.svg#x"/>'],
      'protocol relative link' => ['<a href="//evil.example"><text>x</text></a>'],
      'svg data uri' => ['<image href="data:image/svg+xml;base64,PHN2Zy8+"/>'],
      'css import' => ['<style>@import url(https://evil.example/x.css);</style>'],
      'css remote url' => ['<rect style="fill: url(https://evil.example/x)"/>'],
      'animation' => ['<set attributeName="href" to="javascript:alert(1)"/>'],
      'iframe' => ['<iframe xmlns="http://www.w3.org/1999/xhtml" src="x"/>'],
    ];
  }

  #[DataProvider('unsafeProvider')]
  public function testUnsafe(string $body): void {
    $this->assertNotSame([], $this->container->get('divengine_modeler.svg_safety')->check($this->wrap($body)));
  }

  public function testDocumentLevelRejections(): void {
    $safety = $this->container->get('divengine_modeler.svg_safety');
    $this->assertNotSame([], $safety->check('<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>'));
    $this->assertNotSame([], $safety->check('<html xmlns="http://www.w3.org/1999/xhtml"/>'));
    $this->assertNotSame([], $safety->check('<svg>no namespace</svg>'));
    $this->assertNotSame([], $safety->check('<svg xmlns="http://www.w3.org/2000/svg" xmlns="http://www.w3.org/2000/svg"/>'));
    $this->assertNotSame([], $safety->check('not xml'));
    $this->assertNotSame([], $safety->check($this->wrap(str_repeat('<g/>', 1_300_000))));
  }

}
