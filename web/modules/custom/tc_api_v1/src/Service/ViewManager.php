<?php

namespace Drupal\tc_api_v1\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Servicio para registrar y gestionar las visualizaciones de noticias.
 */
class ViewManager {

  use StringTranslationTrait;

  /**
   * Conexión a base de datos.
   */
  protected Connection $database;

  /**
   * Administrador de entidades.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Usuario actual.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Servicio de tiempo.
   */
  protected TimeInterface $time;

  /**
   * Constructor de ViewManager.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    TimeInterface $time
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->time = $time;
  }

  /**
   * Registra una visualización en tc_article_views y suma 1 al campo field_visualizaciones.
   *
   * @param int $nid
   *   ID del nodo de la noticia.
   * @param int $uid
   *   ID del usuario si está autenticado (0 si es anónimo).
   * @param string $ip
   *   Dirección IP del cliente.
   * @param string $user_agent
   *   User agent del cliente.
   * @param string $user_name
   *   Nombre del usuario o identificador de cliente/invitado.
   *
   * @return array
   *   Información del resultado con el nuevo total de visualizaciones.
   */
  public function recordView(
    int $nid,
    int $uid = 0,
    string $ip = '',
    string $user_agent = '',
    string $user_name = ''
  ): array {
    $timestamp = $this->time->getRequestTime();

    // 1. Guardar el registro en la tabla tc_article_views (repetible ilimitadamente)
    if ($this->database->schema()->tableExists('tc_article_views')) {
      $this->database->insert('tc_article_views')
        ->fields([
          'nid' => $nid,
          'uid' => $uid,
          'user_name' => substr($user_name, 0, 128),
          'ip_address' => substr($ip, 0, 45),
          'user_agent' => substr($user_agent, 0, 255),
          'created' => $timestamp,
        ])
        ->execute();
    }

    // 2. Cargar el nodo y sumar 1 a field_visualizaciones
    $new_views = 1;
    $node_storage = $this->entityTypeManager->getStorage('node');
    $node = $node_storage->load($nid);

    if ($node && $node->hasField('field_visualizaciones')) {
      $current_views = !$node->get('field_visualizaciones')->isEmpty()
        ? (int) $node->get('field_visualizaciones')->value
        : 0;
      $new_views = $current_views + 1;
      $node->set('field_visualizaciones', $new_views);
      $node->save();

      // Invalidar caché del nodo y listados
      Cache::invalidateTags([
        'node:' . $nid,
        'node_list',
        'node_list:news',
        'rendered',
      ]);
    }

    return [
      'status' => 'success',
      'nid' => $nid,
      'views' => $new_views,
      'message' => $this->t('Visualización registrada exitosamente.'),
    ];
  }

  /**
   * Obtiene el conteo total de visualizaciones de un artículo.
   */
  public function getTotalViews(int $nid): int {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $node = $node_storage->load($nid);
    if ($node && $node->hasField('field_visualizaciones') && !$node->get('field_visualizaciones')->isEmpty()) {
      return (int) $node->get('field_visualizaciones')->value;
    }

    if ($this->database->schema()->tableExists('tc_article_views')) {
      return (int) $this->database->select('tc_article_views', 'v')
        ->condition('v.nid', $nid)
        ->countQuery()
        ->execute()
        ->fetchField();
    }

    return 0;
  }

}
