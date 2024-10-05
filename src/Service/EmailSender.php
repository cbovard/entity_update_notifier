<?php

namespace Drupal\entity_update_notifier\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Url;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;

/**
 * Email sender service for Entity Update Notifier.
 */
class EmailSender {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * Constructs a new EmailSender object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    LanguageManagerInterface $language_manager,
    MailManagerInterface $mail_manager
  ) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->languageManager = $language_manager;
    $this->mailManager = $mail_manager;
  }

  /**
   * Send update emails for all configured entities.
   *
   * @param bool $bypass_days_setting
   *   Whether to bypass the days setting when sending emails.
   */
  public function sendUpdateEmails($bypass_days_setting = false) {
    // Get the configuration for content types and vocabularies.
    $config = $this->configFactory->get('entity_update_notifier.settings');
    $content_types = $config->get('content_types');
    $vocabularies = $config->get('vocabularies');

    // Send update emails for each configured content type.
    foreach ($content_types as $content_type) {
      $this->sendContentTypeUpdateEmail($content_type, $bypass_days_setting);
    }

    // Send update emails for each configured vocabulary.
    foreach ($vocabularies as $vocabulary) {
      $this->sendVocabularyUpdateEmail($vocabulary, $bypass_days_setting);
    }
  }

  /**
   * Send update email for a content type.
   *
   * @param string $content_type
   *   The content type ID.
   * @param bool $bypass_days_setting
   *   Whether to bypass the days setting when sending emails.
   */
  protected function sendContentTypeUpdateEmail($content_type, $bypass_days_setting) {
    // Get configuration values for this content type.
    $config = $this->configFactory->get('entity_update_notifier.settings');
    $days = $config->get('days_' . $content_type);
    $sort_order = $config->get('sort_order_' . $content_type);
    $email_addresses = explode(',', $config->get('email_' . $content_type));
    $email_text = $config->get('email_text_' . $content_type);

    // Query the module node table for the most recently updated node of this content type.
    // There will only be one record per content type as this will be used below to get the next node to send an email for.
    $updated_node_query = $this->database->select('entity_update_notifier_node', 'eunn')
      ->fields('eunn', ['nid', 'updated_time'])
      ->condition('eunn.type', $content_type)
      ->range(0, 1);

    $updated_node_result = $updated_node_query->execute()->fetchAssoc();

    // If there is a Node record of the last updated node of this content type we need to get the next node to send an email for.
    if ($updated_node_result) {

      $updated_nid = $updated_node_result['nid'];
      $last_updated = $updated_node_result['updated_time'];

      // We need to get the next node to send an update email for based on the sort order.
      // If the sort order is ASC, we need to get the next node with a nid greater than the updated nid.
      // If the sort order is DESC, we need to get the next node with a nid less than the updated nid.
      $next_node_query = $this->database->select('node_field_data', 'n')
        ->fields('n', ['nid'])
        ->condition('n.type', $content_type)
        ->condition('n.status', 1)
        ->condition('n.nid', $updated_nid, $sort_order == 'ASC' ? '>' : '<')
        ->orderBy('n.nid', $sort_order)
        ->range(0, 1);

      $next_node_result = $next_node_query->execute()->fetchAssoc();
      $next_nid = $next_node_result['nid'];

      // Load the next node.
      $node = $this->entityTypeManager->getStorage('node')->load($next_nid);

      // Check if the node exists, matches the content type, and is due for an update.
      if ($node && $node->bundle() == $content_type && ($bypass_days_setting || (time() - $last_updated) >= ($days * 86400))) {

        // Send an email to the configured addresses to alert them that the node needs an update
        $this->sendEmail($node, $email_addresses, $email_text);

        // Update the node id and last updated time for this content type to the next node.
        $this->database->update('entity_update_notifier_node')
          ->fields([
            'nid' => $node->id(),
            'updated_time' => time(),
          ])
          ->condition('type', $content_type)
          ->execute();
      }
    }
    else {
      // If no record exists query for the first (or last) published node of this content type.
      // If the sort order is ASC, get the first published node.
      // If the sort order is DESC, get the last published node.
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $content_type)
        ->condition('status', 1)
        ->sort('nid', $sort_order)
        ->range(0, 1);

      // Gets an array of nids but there is only one nid.
      $nids = $query->execute();
      $nid = reset($nids);

      // If the nid is not empty, load the node and send an email for it.
      if (!empty($nid)) {
        $node = $this->entityTypeManager->getStorage('node')->load($nid);

        // Send an email for the newly found node.
        $this->sendEmail($node, $email_addresses, $email_text);

        // Insert a new update record for this node.
        $this->database->insert('entity_update_notifier_node')
          ->fields([
            'nid' => $nid,
            'type' => $content_type,
            'updated_time' => time(),
          ])
          ->execute();
      } else {
        // There might be a content type but no nodes of that type are published.
        \Drupal::logger('Entity Update Notifier')->error('No node found for content type: ' . $content_type . ' and no update email sent.');
      }
    }
  }

  /**
   * Send update email for a vocabulary.
   *
   * @param string $vocabulary
   *   The vocabulary ID.
   * @param bool $bypass_days_setting
   *   Whether to bypass the days setting when sending emails.
   */
  protected function sendVocabularyUpdateEmail($vocabulary, $bypass_days_setting) {
    // Get configuration values for this vocabulary.
    $config = $this->configFactory->get('entity_update_notifier.settings');
    $days = $config->get('days_' . $vocabulary);
    $sort_order = $config->get('sort_order_' . $vocabulary);
    $email_addresses = explode(',', $config->get('email_' . $vocabulary));
    $email_text = $config->get('email_text_' . $vocabulary);

    // Query the database for the most recently updated term of this vocabulary.
    $query = $this->database->select('entity_update_notifier', 'eu')
      ->fields('eu', ['vid', 'updated_time'])
      ->condition('eu.vid', 0, '>')
      ->orderBy('eu.updated_time', $sort_order)
      ->range(0, 1);

    $result = $query->execute()->fetchAssoc();

    if ($result) {
      $vid = $result['vid'];
      $last_updated = $result['updated_time'];
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
        'vid' => $vocabulary,
      ]);
      $term = reset($term);

      // Check if the term exists and is due for an update.
      if ($term && ($bypass_days_setting || (time() - $last_updated) >= ($days * 86400))) {
        $this->sendEmail($term, $email_addresses, $email_text);

        // Update the last updated time for this term.
        $this->database->update('entity_update_notifier')
          ->fields(['updated_time' => time()])
          ->condition('vid', $vid)
          ->execute();
      }
    } else {
      // If no record exists, find the first term of this vocabulary.
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
        'vid' => $vocabulary,
      ]);
      $term = reset($term);

      if ($term) {
        // Send an email for the newly found term.
        $this->sendEmail($term, $email_addresses, $email_text);

        // Insert a new record for this term.
        $this->database->insert('entity_update_notifier')
          ->fields([
            'nid' => 0,
            'vid' => $term->id(),
            'updated_time' => time(),
          ])
          ->execute();
      }
    }
  }

  /**
   * Send an email for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to send an email for.
   * @param array $email_addresses
   *   An array of email addresses to send to.
   * @param string $email_text
   *   The email text template.
   */
  protected function sendEmail($entity, array $email_addresses, $email_text) {
    // Prepare tokens for email text replacement.
    $tokens = [
      '[entity-title]' => $entity->label(),
      '[entity-url]' => $entity->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ];

    // Replace tokens in the email text.
    $body = strtr($email_text, $tokens);

    // All system mails need to specify the module and template key.
    $module = 'entity_update_notifier';
    $key = 'entity_update_notification';

    $language_code = $this->languageManager->getDefaultLanguage()->getId();

    // Send an email to each configured address.
    foreach ($email_addresses as $to) {

      $params = [
        'subject' => t('Entity Update Notification: @title', ['@title' => $entity->label()]),
        'body' => $body,
      ];

      // Send the email.
      $result = $this->mailManager->mail($module, $key, $to, $language_code, $params, NULL, TRUE);

      if ($result['result'] == TRUE) {
        \Drupal::logger('Entity Update Notifier')->notice('Email has been sent for entity: ' . $entity->label());
      }
      else {
        \Drupal::logger('Entity Update Notifier')->error('There was a problem sending your message and it was not sent.');
      }
    }
  }
}
