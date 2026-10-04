<?php

namespace Drupal\tc_api_v1\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Servicio para gestionar los likes de artículos/noticias,
 * registrar el detalle en la base de datos y actualizar field_likes en el nodo.
 */
class LikeManager {

  use StringTranslationTrait;

  /**
   * Conexión a la base de datos.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Usuario actual.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Servicio de tiempo.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * Constructor.
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
   * Procesa la acción de dar/quitar like (por defecto toggle).
   *
   * @param int $nid
   *   ID de la noticia.
   * @param int $uid
   *   ID del usuario (0 si anónimo).
   * @param string $ip
   *   IP del cliente.
   * @param string $user_agent
   *   User Agent del cliente.
   * @param string $action
   *   'toggle', 'like' o 'unlike'.
   *
   * @return array
   *   Resultado con 'status', 'action', 'liked', 'total_likes', 'nid'.
   */
  public function processLike(
    int $nid,
    int $uid = 0,
    string $ip = '',
    string $user_agent = '',
    string $action = 'toggle'
  ): array {
    $existing = $this->findExistingLike($nid, $uid, $ip);

    if ($action === 'like') {
      if ($existing) {
        $total = $this->updateNodeLikes($nid);
        return [
          'status' => 'success',
          'action' => 'already_liked',
          'liked' => TRUE,
          'total_likes' => $total,
          'nid' => $nid,
          'message' => $this->t('Ya habías indicado que te gusta esta noticia.'),
        ];
      }
      return $this->addLike($nid, $uid, $ip, $user_agent);
    }

    if ($action === 'unlike') {
      if (!$existing) {
        $total = $this->updateNodeLikes($nid);
        return [
          'status' => 'success',
          'action' => 'not_liked',
          'liked' => FALSE,
          'total_likes' => $total,
          'nid' => $nid,
          'message' => $this->t('No tenías registrado un like para esta noticia.'),
        ];
      }
      return $this->removeLikeRecord((int) $existing->id, $nid);
    }

    // Default: toggle
    if ($existing) {
      return $this->removeLikeRecord((int) $existing->id, $nid);
    }

    return $this->addLike($nid, $uid, $ip, $user_agent);
  }

  /**
   * Registra un nuevo like en la tabla tc_article_likes y actualiza field_likes.
   */
  public function addLike(int $nid, int $uid = 0, string $ip = '', string $user_agent = ''): array {
    $timestamp = $this->time->getRequestTime();

    $like_id = $this->database->insert('tc_article_likes')
      ->fields([
        'nid' => $nid,
        'uid' => $uid,
        'ip_address' => substr($ip, 0, 45),
        'user_agent' => substr($user_agent, 0, 255),
        'created' => $timestamp,
      ])
      ->execute();

    $total = $this->updateNodeLikes($nid);

    return [
      'status' => 'success',
      'action' => 'liked',
      'liked' => TRUE,
      'like_id' => (int) $like_id,
      'total_likes' => $total,
      'nid' => $nid,
      'message' => $this->t('Like registrado exitosamente.'),
    ];
  }

  /**
   * Elimina un registro de like existente y actualiza field_likes.
   */
  public function removeLikeRecord(int $like_id, int $nid): array {
    $this->database->delete('tc_article_likes')
      ->condition('id', $like_id)
      ->execute();

    $total = $this->updateNodeLikes($nid);

    return [
      'status' => 'success',
      'action' => 'unliked',
      'liked' => FALSE,
      'total_likes' => $total,
      'nid' => $nid,
      'message' => $this->t('Like removido exitosamente.'),
    ];
  }

  /**
   * Verifica si un usuario o IP ya ha dado like a un artículo.
   */
  public function hasUserLiked(int $nid, int $uid = 0, string $ip = ''): bool {
    return $this->findExistingLike($nid, $uid, $ip) !== NULL;
  }

  /**
   * Busca un like existente para un artículo por usuario o IP.
   */
  public function findExistingLike(int $nid, int $uid = 0, string $ip = ''): ?object {
    $query = $this->database->select('tc_article_likes', 'l')
      ->fields('l', ['id', 'nid', 'uid', 'ip_address', 'created'])
      ->condition('l.nid', $nid);

    if ($uid > 0) {
      $query->condition('l.uid', $uid);
    }
    elseif (!empty($ip)) {
      $query->condition('l.uid', 0)
        ->condition('l.ip_address', $ip);
    }
    else {
      return NULL;
    }

    $result = $query->range(0, 1)->execute()->fetchObject();
    return $result ?: NULL;
  }

  /**
   * Obtiene el conteo total de likes de un artículo desde la tabla tc_article_likes.
   */
  public function getTotalLikes(int $nid): int {
    return (int) $this->database->select('tc_article_likes', 'l')
      ->condition('l.nid', $nid)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Obtiene el detalle de likes registrados para un artículo.
   */
  public function getLikesDetail(int $nid, int $limit = 50, int $offset = 0): array {
    $query = $this->database->select('tc_article_likes', 'l')
      ->fields('l', ['id', 'nid', 'uid', 'ip_address', 'user_agent', 'created'])
      ->condition('l.nid', $nid)
      ->orderBy('l.created', 'DESC')
      ->range($offset, $limit);

    $results = $query->execute()->fetchAll();

    $user_storage = $this->entityTypeManager->getStorage('user');
    $items = [];
    foreach ($results as $row) {
      $user_name = 'Anónimo';
      if ((int) $row->uid > 0) {
        $user = $user_storage->load((int) $row->uid);
        if ($user) {
          $user_name = $user->getDisplayName();
        }
      }

      $items[] = [
        'id' => (int) $row->id,
        'nid' => (int) $row->nid,
        'uid' => (int) $row->uid,
        'user_name' => $user_name,
        'ip_address' => $row->ip_address,
        'user_agent' => $row->user_agent,
        'created' => (int) $row->created,
        'created_iso' => date('c', (int) $row->created),
      ];
    }

    return $items;
  }

  /**
   * Recalcula y actualiza el campo field_likes en el nodo de la noticia,
   * e invalida las etiquetas de caché necesarias.
   *
   * @param int $nid
   *   ID del nodo.
   *
   * @return int
   *   Total de likes actualizado.
   */
  public function updateNodeLikes(int $nid): int {
    $total = $this->getTotalLikes($nid);

    $node_storage = $this->entityTypeManager->getStorage('node');
    $node = $node_storage->load($nid);

    if ($node && $node->hasField('field_likes')) {
      $node->set('field_likes', $total);
      $node->save();
    }

    // Invalidar etiquetas de caché de Drupal
    Cache::invalidateTags([
      'node:' . $nid,
      'node_list',
      'node_list:news',
      'rendered',
    ]);

    return $total;
  }

}
