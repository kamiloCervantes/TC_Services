<?php

namespace Drupal\tc_auth\Repositories;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\simple_oauth\Entities\UserEntity;
use Drupal\user\UserAuthInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;

/**
 * User repository for OAuth2 Password Grant supporting username or email.
 */
class UserRepository implements UserRepositoryInterface {

  /**
   * The user auth service.
   *
   * @var \Drupal\user\UserAuthInterface
   */
  protected UserAuthInterface $userAuth;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a UserRepository object.
   */
  public function __construct(UserAuthInterface $user_auth, EntityTypeManagerInterface $entity_type_manager) {
    $this->userAuth = $user_auth;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getUserEntityByUserCredentials(
    $username,
    $password,
    $grantType,
    ClientEntityInterface $clientEntity
  ): ?UserEntityInterface {
    $uid = $this->userAuth->authenticate($username, $password);

    // If direct authentication fails, check if the username is an email
    if (!$uid && filter_var($username, FILTER_VALIDATE_EMAIL)) {
      $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $username]);
      $user = reset($users);
      if ($user) {
        $uid = $this->userAuth->authenticate($user->getAccountName(), $password);
      }
    }

    if ($uid) {
      $account = $this->entityTypeManager->getStorage('user')->load($uid);
      if ($account && $account->isActive()) {
        $userEntity = new UserEntity();
        $userEntity->setIdentifier((string) $uid);
        return $userEntity;
      }
    }

    return NULL;
  }

}