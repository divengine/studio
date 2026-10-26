<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_external\Functional;

use Drupal\divengine_artifact\Entity\Artifact;
use Drupal\divengine_external\Entity\ExternalReference;
use Drupal\divengine_process\Entity\Development;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Linking a requirement to GitHub work through the UI, and who sees it (#69).
 */
#[RunTestsInSeparateProcesses]
class ExternalReferenceHttpTest extends BrowserTestBase {

  protected static $modules = ['divengine_external', 'jsonapi'];

  protected $defaultTheme = 'stark';

  public function testLinkFromARequirementAndPrivacy(): void {
    $permissions = ['use unified process', 'view artifact', 'view trace links', 'create trace links', 'link external work'];
    $member = $this->drupalCreateUser($permissions);
    $outsider = $this->drupalCreateUser($permissions);
    $development = Development::create(['name' => 'Private Alpha', 'members' => [$member->id()]]);
    $development->save();
    $requirement = Artifact::create(['type' => 'requirement', 'title' => 'Legacy behaviour is preserved', 'development' => $development->id()]);
    $requirement->save();

    // The member links the requirement to a pull request from its Traces.
    $this->drupalLogin($member);
    $this->drupalGet($requirement->toUrl());
    $this->clickLink('Link external work');
    $this->submitForm([
      'url' => 'https://github.com/rafageist/ancora-goh/pull/98',
      'link_type' => 'verifies',
      'direction' => 'reference',
      'rationale' => 'Characterization tests',
    ], 'Link');
    $this->assertSession()->addressEquals($requirement->toUrl()->toString());
    $this->assertSession()->pageTextContains('Registered rafageist/ancora-goh#98.');
    $this->assertSession()->pageTextContains('Is verified by');
    $this->assertSession()->linkExists('rafageist/ancora-goh#98');

    // The same work, written differently, is reused.
    $this->drupalGet('/external/link/artifact/' . $requirement->id());
    $this->submitForm(['url' => 'https://github.com/RafaGeist/ancora-goh/pull/98/files', 'link_type' => 'verifies', 'direction' => 'reference'], 'Link');
    $this->assertSession()->pageTextContains('was already referenced in this Development; it was reused.');
    $this->assertSession()->pageTextContains('already existed');
    $this->assertCount(1, ExternalReference::loadMultiple());

    // Unsupported links are refused on the form.
    $this->drupalGet('/external/link/artifact/' . $requirement->id());
    $this->submitForm(['url' => 'https://github.com/rafageist/ancora-goh/wiki', 'link_type' => 'references', 'direction' => 'record'], 'Link');
    $this->assertSession()->pageTextContains('This GitHub link is not supported.');

    // The reference page opens the work and escapes what people typed.
    $reference = current(ExternalReference::loadMultiple());
    $reference->set('label', '<script>alert("x")</script> PR')->save();
    $this->drupalGet($reference->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains('<script>alert("x")</script>');
    $this->assertSession()->pageTextContains('<script>alert("x")</script> PR');
    $open = $this->assertSession()->elementExists('xpath', '//a[text()="Open"]');
    $this->assertSame('https://github.com/rafageist/ancora-goh/pull/98', $open->getAttribute('href'));
    $this->assertStringContainsString('noopener', $open->getAttribute('rel'));
    $this->assertSession()->pageTextContains('Legacy behaviour is preserved');

    // JSON:API returns it to the member only.
    $collection = '/jsonapi/external_reference/external_reference';
    $this->drupalGet($collection);
    $this->assertSession()->responseContains($reference->uuid());

    // Somebody outside the Development learns nothing.
    $this->drupalLogin($outsider);
    $this->drupalGet($collection);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains($reference->uuid());
    $this->assertSession()->responseNotContains('ancora-goh');
    $this->drupalGet($collection . '/' . $reference->uuid());
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->responseNotContains('ancora-goh');
    $this->drupalGet($reference->toUrl());
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/external/link/artifact/' . $requirement->id());
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/external');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('alert(');
    $this->assertSession()->pageTextContains('No external work referenced yet.');
  }

}
