<?php

namespace Drupal\entity_update_notifier\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\entity_update_notifier\Service\EmailSender;

/**
 * Controller for Entity Update Notifier.
 */
class EntityUpdateNotifierController extends ControllerBase {

  /**
   * The email sender service.
   *
   * @var \Drupal\entity_update_notifier\Service\EmailSender
   */
  protected $emailSender;

  /**
   * EntityUpdateNotifierController constructor.
   *
   * @param \Drupal\entity_update_notifier\Service\EmailSender $email_sender
   *   The email sender service.
   */
  public function __construct(EmailSender $email_sender) {
    $this->emailSender = $email_sender;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_update_notifier.email_sender')
    );
  }

  /**
   * Sends update emails manually.
   *
   * @return array
   *   A render array for the page.
   */
  public function sendEmails() {
    $this->emailSender->sendUpdateEmails();
    $this->messenger()->addMessage($this->t('Update emails have been sent.'));
    return $this->redirect('entity_update_notifier.settings');
  }

}
