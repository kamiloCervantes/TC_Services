<?php

namespace Drupal\tc_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for admin-only login history reports.
 */
class AdminHistoryController extends ControllerBase {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Constructs an AdminHistoryController object.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Renders the administrative login history report.
   */
  public function report(): array {
    $header = [
      ['data' => $this->t('ID'), 'field' => 'h.id', 'sort' => 'desc'],
      ['data' => $this->t('Usuario')],
      ['data' => $this->t('Correo Electrónico')],
      ['data' => $this->t('Fecha y Hora'), 'field' => 'h.timestamp'],
      ['data' => $this->t('Dirección IP')],
      ['data' => $this->t('Método')],
      ['data' => $this->t('Dispositivo / Cliente')],
    ];

    $query = $this->database->select('tc_auth_login_history', 'h')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender')
      ->extend('Drupal\Core\Database\Query\TableSortExtender')
      ->fields('h', ['id', 'uid', 'timestamp', 'ip_address', 'user_agent', 'auth_method'])
      ->orderByHeader($header)
      ->limit(25);

    $results = $query->execute()->fetchAll();
    $rows = [];

    $date_formatter = \Drupal::service('date.formatter');

    foreach ($results as $record) {
      $user = \Drupal\user\Entity\User::load($record->uid);
      if ($user) {
        $user_link = Link::fromTextAndUrl(
          $user->getDisplayName(),
          Url::fromRoute('entity.user.canonical', ['user' => $user->id()])
        )->toString();
        $user_mail = $user->getEmail() ?: '-';
      }
      else {
        $user_link = $this->t('UID: @uid (Eliminado)', ['@uid' => $record->uid]);
        $user_mail = '-';
      }

      $rows[] = [
        'id' => $record->id,
        'user' => ['data' => ['#markup' => $user_link]],
        'mail' => $user_mail,
        'date' => $date_formatter->format($record->timestamp, 'custom', 'd/m/Y - H:i:s T'),
        'ip' => $record->ip_address,
        'method' => $record->auth_method,
        'agent' => [
          'data' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#attributes' => ['title' => $record->user_agent],
            '#value' => substr($record->user_agent, 0, 45) . (strlen($record->user_agent) > 45 ? '...' : ''),
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No hay registros de inicio de sesión hasta el momento.'),
      '#attributes' => ['class' => ['responsive-enabled']],
    ];

    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

}