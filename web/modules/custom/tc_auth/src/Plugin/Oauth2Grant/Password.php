<?php

namespace Drupal\tc_auth\Plugin\Oauth2Grant;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth\Plugin\Oauth2GrantBase;
use League\OAuth2\Server\Grant\GrantTypeInterface;
use League\OAuth2\Server\Grant\PasswordGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The password grant plugin.
 *
 * @Oauth2Grant(
 *   id = "password",
 *   label = @Translation("Password")
 * )
 */
class Password extends Oauth2GrantBase implements ContainerFactoryPluginInterface {

  /**
   * The user repository.
   *
   * @var \League\OAuth2\Server\Repositories\UserRepositoryInterface
   */
  protected UserRepositoryInterface $userRepository;

  /**
   * The refresh token repository.
   *
   * @var \League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface
   */
  protected RefreshTokenRepositoryInterface $refreshTokenRepository;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    UserRepositoryInterface $user_repository,
    RefreshTokenRepositoryInterface $refresh_token_repository
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->userRepository = $user_repository;
    $this->refreshTokenRepository = $refresh_token_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('tc_auth.repositories.user'),
      $container->get('simple_oauth.repositories.refresh_token')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getGrantType(Consumer $client): GrantTypeInterface {
    $grant_type = new PasswordGrant($this->userRepository, $this->refreshTokenRepository);
    $refresh_token_ttl = !$client->get('refresh_token_expiration')->isEmpty()
      ? (int) $client->get('refresh_token_expiration')->value
      : 1209600;
    $duration = new \DateInterval(sprintf('PT%dS', $refresh_token_ttl));
    $grant_type->setRefreshTokenTTL($duration);
    return $grant_type;
  }

}