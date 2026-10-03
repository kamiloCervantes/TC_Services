<?php

namespace Drupal\tc_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\tc_auth\Service\LoginHistoryManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller to fetch login history for authenticated users.
 */
class UserHistoryController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The login history manager service.
   *
   * @var \Drupal\tc_auth\Service\LoginHistoryManager
   */
  protected LoginHistoryManager $historyManager;

  /**
   * Constructs a UserHistoryController object.
   */
  public function __construct(LoginHistoryManager $history_manager) {
    $this->historyManager = $history_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tc_auth.login_history_manager')
    );
  }

  /**
   * Returns paginated login history for the currently authenticated user.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function getLoginHistory(Request $request): JsonResponse {
    $current_user = $this->currentUser();
    $uid = (int) $current_user->id();

    if ($uid <= 0) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('No autenticado.'),
      ], Response::HTTP_UNAUTHORIZED);
    }

    $limit = (int) ($request->query->get('limit') ?: 20);
    $offset = (int) ($request->query->get('offset') ?: 0);

    // Clamp limit
    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);

    $history = $this->historyManager->getUserHistory($uid, $limit, $offset);

    return new JsonResponse([
      'status' => 'success',
      'user_id' => $uid,
      'total' => count($history),
      'history' => $history,
    ], Response::HTTP_OK);
  }

}