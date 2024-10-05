<?php

namespace Drupal\entity_update_notifier\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Database;

/**
 * Configure Entity Update Notifier settings for this site.
 */
class EntityUpdateNotifierSettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new EntityUpdateNotifierSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($config_factory);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['entity_update_notifier.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'entity_update_notifier_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('entity_update_notifier.settings');

    $form_description = $this->t('This module sends email notifications to update specific entities. You can choose when you want to be notified below after you select the entity checkboxes and save.');
    $form['description'] = [
      '#type' => 'item',
      '#markup' => $form_description,
    ];

    $form['content_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content Type Entities'),
      '#options' => $this->getContentTypeOptions(),
      '#default_value' => $config->get('content_types') ?: [],
    ];

    $form['vocabularies'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Taxonomy Vocabulary Entities'),
      '#options' => $this->getVocabularyOptions(),
      '#default_value' => $config->get('vocabularies') ?: [],
    ];

    $form['entity_settings'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Entity Settings'),
    ];

    $entities = array_merge(
      array_filter($config->get('content_types') ?: []),
      array_filter($config->get('vocabularies') ?: [])
    );

    foreach ($entities as $entity_id) {
      $form[$entity_id] = [
        '#type' => 'details',
        '#title' => $this->t('Settings for @entity', ['@entity' => $entity_id]),
        '#group' => 'entity_settings',
      ];

      $form[$entity_id]['email_' . $entity_id] = [
        '#type' => 'textfield',
        '#title' => $this->t('Email addresses'),
        '#description' => $this->t('Enter email addresses separated by commas.'),
        '#default_value' => $config->get('email_' . $entity_id),
      ];

      $form[$entity_id]['days_' . $entity_id] = [
        '#type' => 'number',
        '#title' => $this->t('Days between updates'),
        '#description' => $this->t('Enter the number of days between update email notifications.'),
        '#default_value' => $config->get('days_' . $entity_id) ?: 1,
        '#min' => 1,
      ];

      $form[$entity_id]['email_text_' . $entity_id] = [
        '#type' => 'textarea',
        '#title' => $this->t('Email text'),
        '#description' => $this->t('Enter the email text. You can use basic HTML and the following tokens: [entity-title], [entity-url].'),
        '#default_value' => $config->get('email_text_' . $entity_id),
      ];

      $form[$entity_id]['sort_order_' . $entity_id] = [
        '#type' => 'select',
        '#title' => $this->t('Sort order'),
        '#options' => [
          'ASC' => $this->t('Ascending'),
          'DESC' => $this->t('Descending'),
        ],
        '#description' => $this->t('Ascending order is the oldest entity first. Descending order is the newest entity first.'),
        '#default_value' => $config->get('sort_order_' . $entity_id) ?: 'ASC',
      ];
    }

    $form['cron_time'] = [
      '#type' => 'details',
      '#title' => $this->t('Cron run time'),
      '#description' => $this->t('Enter the time when the cron job should run (in site time zone).'),
    ];

    $hours = range(0, 23);
    $hours = array_combine($hours, array_map(function($hour) {
      return sprintf('%02d', $hour);
    }, $hours));

    $minutes = range(0, 59, 5);
    $minutes = array_combine($minutes, array_map(function($minute) {
      return sprintf('%02d', $minute);
    }, $minutes));

    $default_time = $config->get('cron_time') ?: '00:00';
    list($default_hour, $default_minute) = explode(':', $default_time);

    $form['cron_time']['cron_time_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];

    $form['cron_time']['cron_time_wrapper']['cron_time_hour'] = [
      '#type' => 'select',
      '#title' => $this->t('Hour'),
      '#options' => $hours,
      '#default_value' => $default_hour,
      '#chosen' => FALSE,
    ];

    $form['cron_time']['cron_time_wrapper']['cron_time_minute'] = [
      '#type' => 'select',
      '#title' => $this->t('Minute'),
      '#options' => $minutes,
      '#default_value' => $default_minute,
      '#chosen' => FALSE,
    ];

    $form['manual_email_actions'] = [
      '#type' => 'details',
      '#title' => $this->t('Bypass the days setting and CRON.'),
      '#description' => '<p>' . $this->t('Use these buttons to manually trigger email sending. Be cautious when using "Send Update Emails Now" as it bypasses the configured day intervals.') . '</p>',
    ];

    $form['manual_email_actions']['send_emails_now'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send Update Emails Now bypassing the days setting and CRON.'),
      '#submit' => ['::sendUpdateEmailsNow'],
    ];

    $form['manual_email_actions']['send_emails'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send Update Emails bypassing the CRON but not the days setting.'),
      '#submit' => ['::sendUpdateEmails'],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    // Get the config object for our module
    $config = $this->config('entity_update_notifier.settings');

    // Retrieve the previously saved content types and vocabularies.
    $old_content_types = $config->get('content_types') ?: [];
    $old_vocabularies = $config->get('vocabularies') ?: [];

    // Get the newly selected content types and vocabularies from the form.
    $new_content_types = array_filter($form_state->getValue('content_types'));
    $new_vocabularies = array_filter($form_state->getValue('vocabularies'));

    // Update the config with the new selections.
    $config->set('content_types', $new_content_types);
    $config->set('vocabularies', $new_vocabularies);

    // Determine which content types and vocabularies were unchecked.
    $unchecked_content_types = array_diff($old_content_types, $new_content_types);
    $unchecked_vocabularies = array_diff($old_vocabularies, $new_vocabularies);

    // Delete database entries for unchecked content types.
    if (!empty($unchecked_content_types)) {
      \Drupal::database()->delete('entity_update_notifier_node')
        ->condition('type', $unchecked_content_types, 'IN')
        ->execute();
    }

    // Delete database entries for unchecked vocabularies.
    if (!empty($unchecked_vocabularies)) {
      \Drupal::database()->delete('entity_update_notifier_taxonomy_term')
        ->condition('vid', $unchecked_vocabularies, 'IN')
        ->execute();
    }

    // Combine all selected entities (content types and vocabularies).
    $entities = array_merge($new_content_types, $new_vocabularies);

    // Update config for each selected entity.
    foreach ($entities as $entity_id) {
      $config->set('email_' . $entity_id, $form_state->getValue('email_' . $entity_id));
      $config->set('days_' . $entity_id, $form_state->getValue('days_' . $entity_id));
      $config->set('email_text_' . $entity_id, $form_state->getValue('email_text_' . $entity_id));
      $config->set('sort_order_' . $entity_id, $form_state->getValue('sort_order_' . $entity_id));
    }

    // Set the cron time.
    $hour = $form_state->getValue(['cron_time_hour']);
    $minute = $form_state->getValue(['cron_time_minute']);
    $cron_time = sprintf('%02d:%02d', $hour, $minute);
    $config->set('cron_time', $cron_time);

    // Save all config changes.
    $config->save();
  }

  /**
   * Submit handler for sending update emails manually.
   */
  public function sendUpdateEmails(array &$form, FormStateInterface $form_state) {
    $this->sendEmails(false);
  }

  /**
   * Submit handler for sending update emails immediately, bypassing days setting.
   */
  public function sendUpdateEmailsNow(array &$form, FormStateInterface $form_state) {
    $this->sendEmails(true);
  }

  /**
   * Helper method to send emails with a bypass option.
   */
  private function sendEmails($bypass_days_setting) {
    \Drupal::service('entity_update_notifier.email_sender')->sendUpdateEmails($bypass_days_setting);
    $this->messenger()->addMessage($this->t('Update emails have been sent.'));
  }

  /**
   * Get content type options.
   *
   * @return array
   *   An array of content type options.
   */
  protected function getContentTypeOptions() {
    $options = [];
    $content_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    foreach ($content_types as $content_type) {
      $options[$content_type->id()] = $content_type->label();
    }
    return $options;
  }

  /**
   * Get vocabulary options.
   *
   * @return array
   *   An array of vocabulary options.
   */
  protected function getVocabularyOptions() {
    $options = [];
    $vocabularies = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple();
    foreach ($vocabularies as $vocabulary) {
      $options[$vocabulary->id()] = $vocabulary->label();
    }
    return $options;
  }

}
