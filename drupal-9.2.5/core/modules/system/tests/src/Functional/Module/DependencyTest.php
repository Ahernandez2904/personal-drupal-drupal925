<?php

namespace Drupal\Tests\system\Functional\Module;

use Drupal\Component\Utility\Unicode;

/**
 * Enable module without dependency enabled.
 *
 * @group Module
 */
class DependencyTest extends ModuleTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Checks functionality of project namespaces for dependencies.
   */
  public function testProjectNamespaceForDependencies() {
    $edit = [
      'modules[filter][enable]' => TRUE,
    ];
    $this->drupalGet('admin/modules');
    $this->submitForm($edit, 'Install');
    // Enable module with project namespace to ensure nothing breaks.
    $edit = [
      'modules[system_project_namespace_test][enable]' => TRUE,
    ];
    $this->drupalGet('admin/modules');
    $this->submitForm($edit, 'Install');
    $this->assertModules(['system_project_namespace_test'], TRUE);
  }

  /**
   * Attempts to enable the Content Translation module without Language enabled.
   */
  public function testEnableWithoutDependency() {
    // Attempt to enable Content Translation without Language enabled.
    $edit = [];
    $edit['modules[content_translation][enable]'] = 'content_translation';
    $this->drupalGet('admin/modules');
    $this->submitForm($edit, 'Install');
    $this->assertSession()->pageTextContains('Some required modules must be enabled');

    $this->assertModules(['content_translation', 'language'], FALSE);

    // Assert that the language module config was not installed.
    $this->assertNoModuleConfig('language');

    $this->submitForm([], 'Continue');
    $this->assertSession()->pageTextContains('2 modules have been enabled: Content Translation, Language.');
    $this->assertModules(['content_translation', 'language'], TRUE);

    // Assert that the language YAML files were created.
    $storage = $this->container->get('config.storage');
    $this->assertNotEmpty($storage->listAll('language.entity.'), 'Language config entity files exist.');
  }

  /**
   * Attempts to enable a module with a missing dependency.
   */
  public function testMissingModules() {
    // Test that the system_dependencies_test module is marked
    // as missing a dependency.
    $this->drupalGet('admin/modules');
    $this->assertSession()->pageTextContains(Unicode::ucfirst('_missing_dependency') . ' (missing)');
    $this->assertSession()->elementTextEquals('xpath', '//tr[@data-drupal-selector="edit-modules-system-dependencies-test"]//span[@class="admin-missing"]', 'missing');
    $this->assertSession()->checkboxNotChecked('modules[system_dependencies_test][enable]');
  }

  /**
   * Tests enabling a module that depends on an incompatible version of a
   * module.
   */
  public function testIncompatibleModuleVersionDependency() {
    // Test that the system_incompatible_module_version_dependencies_test is
    // marked as having an incompatible dependency.
    $this->drupalGet('admin/modules');
    $this->assertSession()->pageTextContains('System incompatible module version test (>2.0) (incompatible with version 1.0)');
    $this->assertSession()->elementTextEquals('xpath', '//tr[@data-drupal-selector="edit-modules-system-incompatible-module-version-dependencies-test"]//span[@class="admin-missing"]', 'incompatible with');
    $this->assertSession()->fieldDisabled('modules[system_incompatible_module_version_dependencies_test][enable]');
  }

  /**
   * Tests enabling a module that depends on a module with an incompatible core version.
   */
  public function testIncompatibleCoreVersionDependency() {
    // Test that the system_incompatible_core_version_dependencies_test is
    // marked as having an incompatible dependency.
    $this->drupalGet('admin/modules');
    $this->assertSession()->pageTextContains('System core incompatible semver test (incompatible with this version of Drupal core)');
    $this->assertSession()->elementTextEquals('xpath', '//tr[@data-drupal-selector="edit-modules-system-incompatible-core-version-dependencies-test"]//span[@class="admin-missing"]', 'incompatible with');
    $this->assertSession()->fieldDisabled('modules[system_incompatible_core_version_dependencies_test][enable]');
  }

  /**
   * Tests failing PHP version requirements.
   */
  public function testIncompatiblePhpVersionDependency() {
    $this->drupalGet('admin/modules');
    $this->assertRaw('This module requires PHP version 6502.* and is incompatible with PHP version ' . phpversion() . '.');
    $this->assertSession()->fieldDisabled('modules[system_incompatible_php_version_test][enable]');
  }

  /**
   * Tests enabling modules with different core version specifications.
   */
  public function testCoreCompatibility() {
    $assert_session = $this->assertSession();

    // Test incompatible 'core_version_requirement'.
    $this->drupalGet('admin/modules');
    $assert_session->fieldDisabled('modules[system_core_incompatible_semver_test][enable]');

    // Test compatible 'core_version_requirement' and compatible 'core'.
    $this->drupalGet('admin/modules');
    $assert_session->fieldEnabled('modules[common_test][enable]');
    $assert_session->fieldEnabled('modules[system_core_semver_test][enable]');

    // Ensure the modules can actually be installed.
    $edit['modules[common_test][enable]'] = 'common_test';
    $edit['modules[system_core_semver_test][enable]'] = 'system_core_semver_test';
    $this->drupalGet('admin/modules');
    $this->submitForm($edit, 'Install');
    $this->assertModules(['common_test', 'system_core_semver_test'], TRUE);
  }

  /**
   * Tests enabling a module that depends on a module which fails hook_requirements().
   */
  public function testEnableRequirementsFailureDependency() {
    \Drupal::service('module_installer')->install(['comment']);

    $this->assertModules(['requirements1_test'], FALSE);
    $this->assertModules(['requirements2_test'], FALSE);

    // Attempt to install both modules at the same time.
    $edit = [];
    $edit['modules[requirements1_test][enable]'] = 'requirements1_test';
    $edit['modules[requirements2_test][enable]'] = 'requirements2_test';
    $this->drupalGet('admin/modules');
    $this->submitForm($edit, 'Install');

    // Makes sure the modules were NOT installed.
    $this->assertSession()->pageTextContains('Requirements 1 Test failed requirements');
    $this->assertModules(['requirements1_test'], FALSE);
    $this->assertModules(['requirements2_test'], FALSE);

    // Makes sure that already enabled modules the failing modules depend on
    // were not disabled.
    $this->assertModules(['comment'], TRUE);
  }

  /**
   * Tests that module dependencies are enabled in the correct order in the UI.
   *
   * Dependencies should be enabled before their dependents.
   */
  public function testModuleEnableOrder() {
    \Drupal::service('module_installer')->install(['module_test'], FALSE);
    $this->resetAll();
    $this->assertModules(['module_test'], TRUE);
    \Drupal::state()->set('module_test.dependency', 'dependency');
    // module_test creates a dependency chain:
    // - color depends on config
    // - config depends on help
    $expected_order = ['help', 'config', 'color'];

    // Enable the modules through the UI, verifying that the dependency chain
    // is correct.
    $edit = [];
    $edit['modules[color][enable]'] = 'color';
    $this->drupalGet('admin/modules');
    $this->submitForm($edit, 'Install');
    $this->assertModules(['color'], FALSE);
    // Note that dependencies are sorted alphabetically in the confirmation
    // message.
    $this->assertSession()->pageTextContains('You must enable the Configuration Manager, Help modules to install Color.');

    $edit['modules[config][enable]'] = 'config';
    $edit['modules[help][enable]'] = 'help';
    $this->drupalGet('admin/modules');
    $this->submitForm($edit, 'Install');
    $this->assertModules(['color', 'config', 'help'], TRUE);

    // Check the actual order which is saved by module_test_modules_enabled().
    $module_order = \Drupal::state()->get('module_test.install_order', []);
    $this->assertSame($expected_order, $module_order);
  }

  /**
   * Tests attempting to uninstall a module that has installed dependents.
   */
  public function testUninstallDependents() {
    // Enable the forum module.
    $edit = ['modules[forum][enable]' => 'forum'];
    $this->drupalGet('admin/modules');
    $this->submitForm($edit, 'Install');
    $this->submitForm([], 'Continue');
    $this->assertModules(['forum'], TRUE);

    // Check that the comment module cannot be uninstalled.
    $this->drupalGet('admin/modules/uninstall');
    $this->assertSession()->fieldDisabled('uninstall[comment]');

    // Delete any forum terms.
    $vid = $this->config('forum.settings')->get('vocabulary');
    // Ensure taxonomy has been loaded into the test-runner after forum was
    // enabled.
    \Drupal::moduleHandler()->load('taxonomy');
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties(['vid' => $vid]);
    $storage->delete($terms);

    // Uninstall the forum module, and check that taxonomy now can also be
    // uninstalled.
    $edit = ['uninstall[forum]' => 'forum'];
    $this->drupalGet('admin/modules/uninstall');
    $this->submitForm($edit, 'Uninstall');
    $this->submitForm([], 'Uninstall');
    $this->assertSession()->pageTextContains('The selected modules have been uninstalled.');

    // Uninstall comment module.
    $edit = ['uninstall[comment]' => 'comment'];
    $this->drupalGet('admin/modules/uninstall');
    $this->submitForm($edit, 'Uninstall');
    $this->submitForm([], 'Uninstall');
    $this->assertSession()->pageTextContains('The selected modules have been uninstalled.');
  }

}
