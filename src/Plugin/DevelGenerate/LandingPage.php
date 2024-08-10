<?php

namespace Drupal\custom_generate_content\Plugin\DevelGenerate;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\devel_generate\DevelGenerateBase;
use Drupal\node\Entity\Node;

/**
 * Provides a LandingPage plugin.
 *
 * @DevelGenerate(
 *   id = "landing_page",
 *   label = "Landing Page",
 *   description = "Generate landing pages with specific paragraphs.",
 *   url = "landing_page",
 *   permission = "administer nodes",
 *   settings = {
 *     "num" = 2,
 *     "prefix" = "[DevelGenerated]",
 *     "all_paragraphs" = TRUE,
 *     "kill" = FALSE
 *   }
 * )
 */
class LandingPage extends DevelGenerateBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $form['num'] = [
      '#type' => 'textfield',
      '#title' => $this->t('How many landing pages would you like to generate?'),
      '#default_value' => $this->getSetting('num'),
      '#size' => 10,
    ];

    $form['prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Set prefix to node title'),
      '#default_value' => $this->getSetting('prefix'),
      '#size' => 50,
    ];

    $form['all_paragraphs'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Should it add all possible paragraphs to field_components?'),
      '#default_value' => $this->getSetting('all_paragraphs'),
    ];

    $form['kill'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Delete all landing pages before generating new ones.'),
      '#default_value' => $this->getSetting('kill'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function generateElements(array $values): void {
    $num = $values['num'];
    $prefix = $values['prefix'];
    $all_paragraphs = $values['all_paragraphs'];
    if ($prefix) {
      $prefix = $prefix . ' ';
    }
    $kill = $values['kill'];

    if ($kill) {
      $this->deleteExistingLandingPages();
      $this->setMessage($this->t('Old landing pages have been deleted.'));
    }

    for ($i = 0; $i < $num; $i++) {
      $node = Node::create([
        'type' => 'landing_page',
        'title' => $prefix . $this->getRandom()->sentences(1, TRUE),
      ]);

      $fields_to_skip = [];
      if ($all_paragraphs) {
        $fields_to_skip[] = 'field_components';
      }

      $this->populateFields($node, $fields_to_skip);

      if ($all_paragraphs) {
        $definitions = $node->field_components->getFieldDefinition();
        $field_components_definition = $this->generateValues($definitions);
        $node->field_components->setValue($field_components_definition);
      }
      $node->save();

      $this->setMessage($this->t('Created landing page node with ID @id', ['@id' => $node->id()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateDrushParams(array $args, array $options = []): array {
    return [
      'num' => $options['num'] ?? 10,
      'kill' => $options['kill'] ?? FALSE,
    ];
  }

  /**
   * Delete existing landing pages if the kill option is selected.
   */
  protected function deleteExistingLandingPages() {
    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'landing_page')
      ->accessCheck(FALSE)
      ->execute();

    if ($nids) {
      $storage_handler = \Drupal::entityTypeManager()->getStorage('node');
      $entities = $storage_handler->loadMultiple($nids);
      $storage_handler->delete($entities);
    }
  }
  public static function generateValues(FieldDefinitionInterface $field_definition) {
    $selection_manager = \Drupal::service('plugin.manager.entity_reference_selection');
    $entity_manager = \Drupal::entityTypeManager();

    // ERR field values are never cross referenced so we need to generate new
    // target entities. First, find the target entity type.
    $target_type_id = $field_definition->getFieldStorageDefinition()->getSetting('target_type');
    $target_type = $entity_manager->getDefinition($target_type_id);
    $handler_settings = $field_definition->getSetting('handler_settings');

    // Determine referenceable bundles.
    $bundle_manager = \Drupal::service('entity_type.bundle.info');
    if (isset($handler_settings['target_bundles']) && is_array($handler_settings['target_bundles'])) {
      if (empty($handler_settings['negate'])) {
        $bundles = $handler_settings['target_bundles'];
      }
      else {
        $bundles = array_filter($bundle_manager->getBundleInfo($target_type_id), function ($bundle) use ($handler_settings) {
          return !in_array($bundle, $handler_settings['target_bundles'], TRUE);
        });
      }
    }
    else {
      $bundles = $bundle_manager->getBundleInfo($target_type_id);
    }

    $resp = [];

    foreach ($bundles as $bundle) {
      $label = NULL;
      if ($label_key = $target_type->getKey('label')) {
        $random = new Random();
        // @TODO set the length somehow less arbitrary.
        $label = $random->word(mt_rand(1, 10));
      }

      // Create entity stub.
      $entity = $selection_manager->getSelectionHandler($field_definition)->createNewEntity($target_type_id, $bundle, $label, 0);

      // Populate entity values and save.
      $instances = $entity_manager
        ->getStorage('field_config')
        ->loadByProperties([
          'entity_type' => $target_type_id,
          'bundle' => $bundle,
        ]);

      foreach ($instances as $instance) {
        $field_storage = $instance->getFieldStorageDefinition();
        $max = $cardinality = $field_storage->getCardinality();
        if ($cardinality == FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) {
          // Just an arbitrary number for 'unlimited'
          $max = rand(1, 5);
        }
        $field_name = $field_storage->getName();
        $entity->{$field_name}->generateSampleItems($max);
      }
      $entity->save();

      $resp[] = [
        'target_id' => $entity->id(),
        'target_revision_id' => $entity->getRevisionId(),
      ];
    }

    return $resp;
  }
}
