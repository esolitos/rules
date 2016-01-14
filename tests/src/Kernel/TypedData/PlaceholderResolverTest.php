<?php

/**
 * @file
 * Contains Drupal\Tests\rules\Kernel\TypedData\PlaceholderResolverTest.
 */

namespace Drupal\Tests\rules\Kernel\TypedData;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the placeholder resolver.
 *
 * @group rules
 *
 * @cover \Drupal\rules\TypedData\PlaceholderResolver
 */
class PlaceholderResolverTest extends KernelTestBase {

  /**
   * The typed data manager.
   *
   * @var \Drupal\rules\TypedData\TypedDataManagerInterface
   */
  protected $typedDataManager;

  /**
   * A node used for testing.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $node;

  /**
   * An entity type manager used for testing.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * The placeholder resolver instanced tested.
   *
   * @var \Drupal\rules\TypedData\PlaceholderResolver
   */
  protected $placeholderResolver;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['rules', 'system', 'node', 'field', 'text', 'user'];

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    $this->typedDataManager = $this->container->get('typed_data_manager');
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->placeholderResolver = $this->container->get('typed_data.placeholder_resolver');

    $this->entityTypeManager->getStorage('node_type')
      ->create(['type' => 'page'])
      ->save();

    // Create a multi-value integer field for testing.
    FieldStorageConfig::create([
      'field_name' => 'field_integer',
      'type' => 'integer',
      'entity_type' => 'node',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_integer',
      'entity_type' => 'node',
      'bundle' => 'page',
    ])->save();

    $this->installSchema('system', ['sequences']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');

    $this->node = $this->entityTypeManager->getStorage('node')
      ->create([
        'title' => 'test',
        'type' => 'page',
      ]);
  }

  /**
   * @cover scan
   */
  public function testScanningForPlaceholders() {
    $text = 'token [example:foo] and [example:foo:bar]';
    $placeholders = $this->placeholderResolver->scan($text);
    $this->assertEquals([
      'example' => [
        'foo' => '[example:foo]',
        'foo:bar' => '[example:foo:bar]',
      ],
    ], $placeholders);
  }

  /**
   * @cover resolvePlaceholders
   */
  public function testResolvingPlaceholders() {
    $text = 'test [node:title] and [node:title:value]';
    $result = $this->placeholderResolver->resolvePlaceholders($text, ['node' => $this->node->getTypedData()]);
    $expected = [
      '[node:title]' => 'test',
      '[node:title:value]' => 'test',
    ];
    $this->assertEquals($expected, $result);
  }

  /**
   * @cover replacePlaceHolders
   */
  public function testReplacePlaceholders() {
    $text = 'test [node:title] and [node:title:value]';
    $result = $this->placeholderResolver->replacePlaceHolders($text, ['node' => $this->node->getTypedData()]);
    $this->assertEquals('test test and test', $result);
  }

  /**
   * @cover replacePlaceHolders
   */
  public function testPlaceholdersAcrossReferences() {
    $user = $this->entityTypeManager->getStorage('user')
      ->create([
        'name' => 'test',
        'type' => 'user',
      ]);
    $this->node->uid->entity = $user;
    $text = 'test [node:title] and [node:uid:entity:name]';
    $result = $this->placeholderResolver->replacePlaceHolders($text, ['node' => $this->node->getTypedData()]);
    $this->assertEquals('test test and test', $result);
  }

  /**
   * @cover replacePlaceHolders
   */
  public function testPlaceholdersWithMissingData() {
    $text = 'test [node:title:1:value]';
    $result = $this->placeholderResolver->replacePlaceHolders($text, ['node' => $this->node->getTypedData()], []);
    $this->assertEquals('test [node:title:1:value]', $result);
    $result = $this->placeholderResolver->replacePlaceHolders($text, ['node' => $this->node->getTypedData()], ['clear' => FALSE]);
    $this->assertEquals('test [node:title:1:value]', $result);
    $result = $this->placeholderResolver->replacePlaceHolders($text, ['node' => $this->node->getTypedData()], ['clear' => TRUE]);
    $this->assertEquals('test ', $result);
  }

}
