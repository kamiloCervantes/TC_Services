<?php

namespace Drupal\tc_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller to fetch current authenticated user details.
 */
class UserMeController extends ControllerBase {

  /**
   * Returns current user information.
   */
  public function me(Request $request): JsonResponse {
    $current_user = $this->currentUser();
    $uid = (int) $current_user->id();

    if ($uid <= 0) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('No autenticado.'),
      ], Response::HTTP_UNAUTHORIZED);
    }

    $account = \Drupal\user\Entity\User::load($uid);

    return new JsonResponse([
      'status' => 'success',
      'user' => [
        'id' => $uid,
        'name' => $account ? $account->getDisplayName() : $current_user->getDisplayName(),
        'email' => $account ? $account->getEmail() : $current_user->getEmail(),
        'roles' => $current_user->getRoles(),
      ],
    ]);
  }

}