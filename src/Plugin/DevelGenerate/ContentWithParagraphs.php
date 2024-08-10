<?php

namespace Drupal\custom_generate_content\Plugin\DevelGenerate;

use Drupal\Component\Utility\Random;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\devel_generate\DevelGenerateBase;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a ContentWithParagraphs plugin.
 *
 * @DevelGenerate(
 *   id = "content_with_paragraphs",
 *   label = "Content With Paragraphs",
 *   description = "Generate content with all enabled paragraph types.",
 *   url = "content_with_paragraphs",
 *   permission = "administer nodes",
 *   settings = {
 *     "num" = 1,
 *     "prefix" = "[Custom Generated]",
 *     "all_paragraphs" = TRUE
 *   }
 * )
 */
class ContentWithParagraphs extends DevelGenerateBase implements ContainerFactoryPluginInterface {

  /**
   * The node type storage.
   */
  protected EntityStorageInterface $nodeTypeStorage;

  /**
   * The url generator service.
   */
  protected UrlGeneratorInterface $urlGenerator;

  /**
   * The entity field manager.
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $entity_type_manager = $container->get('entity_type.manager');
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->nodeTypeStorage = $entity_type_manager->getStorage('node_type');
    $instance->urlGenerator = $container->get('url_generator');
    $instance->entityFieldManager = $container->get('entity_field.manager');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $types = $this->nodeTypeStorage->loadMultiple();
    if (empty($types)) {
      $create_url = $this->urlGenerator->generateFromRoute('node.type_add');
      $this->setMessage($this->t('You do not have any content types that can be generated. <a href=":create-type">Go create a new content type</a>', [':create-type' => $create_url]), 'error');
      return [];
    }

    $options = [];

    foreach ($types as $type) {
      $options[$type->id()] = [
        'type' => ['#markup' => $type->label()],
      ];
    }

    $header = [
      'type' => $this->t('Content type'),
    ];

    $form['node_types'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $options,
    ];

    $form['num'] = [
      '#type' => 'textfield',
      '#title' => $this->t('How many pages would you like to generate per type?'),
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

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function generateElements(array $values): void
  {
    $node_types = array_filter($values['node_types']);
    $num = $values['num'];
    $prefix = $values['prefix'];
    $all_paragraphs = $values['all_paragraphs'];
    if ($prefix) {
      $prefix = $prefix . ' ';
    }

    foreach ($node_types as $node_type) {
      for ($i = 0; $i < $num; $i++) {
        $node = Node::create([
          'type' => $node_type,
          'title' => $prefix . $this->getRandom()->sentences(1, TRUE),
        ]);

        $fields_to_skip = [];


        if ($all_paragraphs) {

          $instances = $this->entityFieldManager->getFieldDefinitions($node->getEntityTypeId(), $node->bundle());
          foreach ($instances as $instance) {
            $field_storage = $instance->getFieldStorageDefinition();
            $field_name = $field_storage->getName();
            if ($field_storage->isBaseField()) {
              continue;
            }
            if ($field_storage->getType() === 'entity_reference_revisions') {
              if ($field_storage->getSetting('target_type') === 'paragraph') {
                if ($field_storage->getCardinality() === -1) {
                  $fields_to_skip[] = $field_storage->getName();
                }
              }
            }
          }
        }

        $this->populateFields($node, $fields_to_skip);

        if ($all_paragraphs) {
          foreach ($fields_to_skip as $field_name) {
            $definitions = $node->{$field_name}->getFieldDefinition();
            $field_definitions = $this->generateValues($definitions);
            $node->{$field_name}->setValue($field_definitions);
          }
        }
        $node->save();

        $this->setMessage($this->t('Created landing page node with ID @id', ['@id' => $node->id()]));
      }
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
