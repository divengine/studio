<?php

declare(strict_types=1);

namespace Drupal\Tests\divengine_process\Functional;

use Drupal\divengine_process\Entity\Development;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * In the browser, members take part in a Development without managing it.
 */
#[RunTestsInSeparateProcesses]
class DevelopmentPermissionsHttpTest extends BrowserTestBase {

  protected static $modules = ['divengine_process'];

  protected $defaultTheme = 'stark';

  public function testMembersDoNotManage(): void {
    $creator = $this->drupalCreateUser(['use unified process', 'create developments']);
    $member = $this->drupalCreateUser(['use unified process']);
    $manager = $this->drupalCreateUser(['administer unified process']);
    $this->setCurrentUser($creator);
    $development = Development::create(['name' => 'Billing', 'members' => [$member->id()]]);
    $development->save();
    $edit = $development->toUrl('edit-form');

    // A member sees the Development, not its edit form, and cannot start
    // another one.
    $this->drupalLogin($member);
    $this->drupalGet($development->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkByHrefNotExists($edit->toString());
    $this->drupalGet($edit);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/development/add');
    $this->assertSession()->statusCodeEquals(403);

    // Its creator manages it, members included.
    $this->drupalLogin($creator);
    $this->drupalGet($edit);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('members[target_id]');
    $this->drupalGet('/development/add');
    $this->assertSession()->statusCodeEquals(200);

    // So does a Process Manager.
    $this->drupalLogin($manager);
    $this->drupalGet($edit);
    $this->assertSession()->statusCodeEquals(200);
  }

}
