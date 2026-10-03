<?php

namespace Drupal\tc_auth\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\tc_auth\Service\LoginHistoryManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event Subscriber to audit logins via OAuth2 /oauth/token endpoint.
 */
class OAuthTokenSubscriber implements EventSubscriberInterface {

  /**
   * The login history manager.
   *
   * @var \Drupal\tc_auth\Service\LoginHistoryManager
   */
  protected LoginHistoryManager $historyManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs an OAuthTokenSubscriber object.
   */
  public function __construct(LoginHistoryManager $history_manager, EntityTypeManagerInterface $entity_type_manager) {
    $this->historyManager = $history_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Listens to kernel response events to capture successful OAuth2 token generation.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   */
  public function onKernelResponse(ResponseEvent $event) {
    try {
      $request = $event->getRequest();
      $path = $request->getPathInfo();

      if (rtrim($path, '/') === '/oauth/token') {
        $response = $event->getResponse();

        if ($response->getStatusCode() === 200) {
          $grant_type = $request->request->get('grant_type');
          $username = $request->request->get('username');

          if ($grant_type === 'password' && !empty($username)) {
            $user_storage = $this->entityTypeManager->getStorage('user');
            $users = $user_storage->loadByProperties(['mail' => $username]);
            if (empty($users)) {
              $users = $user_storage->loadByProperties(['name' => $username]);
            }

            if (!empty($users)) {
              $user = reset($users);
              $ip = $request->getClientIp();
              $user_agent = $request->headers->get('User-Agent') ?: 'Unknown';
              $this->historyManager->recordLogin((int) $user->id(), $ip, $user_agent, 'oauth2_password');
            }
          }
        }
      }
    }
    catch (\Throwable $t) {
      \Drupal::logger('tc_auth')->error('Error in OAuthTokenSubscriber: @msg', ['@msg' => $t->getMessage()]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::RESPONSE => ['onKernelResponse', -50],
    ];
  }

}