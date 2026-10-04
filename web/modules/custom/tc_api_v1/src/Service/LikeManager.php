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
/**
   * Procesa la acción de dar/quitar like combinando nombre de usuario con IP.
   */
  public function processLike(
    int $nid,
    int $uid = 0,
    string $ip = '',
    string $user_agent = '',
    string $action = 'toggle',
    string $user_name = ''
  ): array {
    $existing = $this->findExistingLike($nid, $uid, $ip, $user_name);

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
      return $this->addLike($nid, $uid, $ip, $user_agent, $user_name);
    }

    if ($action === 'unlike') {
      if ($existing) {
        return $this->removeLikeRecord((int) $existing->id, $nid);
      }

      // Si no se encontró registro exacto por (user_name + IP), intentar buscar por user_name o IP
      if (!empty($user_name)) {
        $by_name = $this->database->select('tc_article_likes', 'l')
          ->fields('l', ['id'])
          ->condition('l.nid', $nid)
          ->condition('l.user_name', $user_name)
          ->range(0, 1)
          ->execute()
          ->fetchField();
        if ($by_name) {
          return $this->removeLikeRecord((int) $by_name, $nid);
        }
      }

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

    // Default: toggle
    if ($existing) {
      return $this->removeLikeRecord((int) $existing->id, $nid);
    }

    return $this->addLike($nid, $uid, $ip, $user_agent, $user_name);
  }

  /**
   * Registra un nuevo like guardando user_name e IP.
   */
  public function addLike(int $nid, int $uid = 0, string $ip = '', string $user_agent = '', string $user_name = ''): array {
    $timestamp = $this->time->getRequestTime();

    $like_id = $this->database->insert('tc_article_likes')
      ->fields([
        'nid' => $nid,
        'uid' => $uid,
        'user_name' => substr($user_name, 0, 128),
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
   * Verifica si un usuario o combinación (user_name + IP) ya ha dado like a un artículo.
   */
  public function hasUserLiked(int $nid, int $uid = 0, string $ip = '', string $user_name = ''): bool {
    return $this->findExistingLike($nid, $uid, $ip, $user_name) !== NULL;
  }

  /**
   * Busca un like existente para un artículo combinando user_name con IP.
   */
  public function findExistingLike(int $nid, int $uid = 0, string $ip = '', string $user_name = ''): ?object {
    $query = $this->database->select('tc_article_likes', 'l')
      ->fields('l', ['id', 'nid', 'uid', 'user_name', 'ip_address', 'created'])
      ->condition('l.nid', $nid);

    // 1. Prioridad: combinación de nombre de usuario con IP
    if (!empty($user_name) && !empty($ip)) {
      $query->condition('l.user_name', $user_name)
        ->condition('l.ip_address', $ip);
    }
    // 2. Por nombre de usuario
    elseif (!empty($user_name)) {
      $query->condition('l.user_name', $user_name);
    }
    // 3. Por ID de usuario autenticado
    elseif ($uid > 0) {
      $query->condition('l.uid', $uid);
    }
    // 4. Por IP anónima
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
   * Obtiene todos los IDs de noticias que el usuario o combinación (user_name + IP) ha marcado con like.
   */
  public function getUserLikedNodeIds(int $uid = 0, string $ip = '', string $user_name = ''): array {
    $query = $this->database->select('tc_article_likes', 'l')
      ->fields('l', ['nid'])
      ->distinct();

    if (!empty($user_name) && !empty($ip)) {
      $query->condition('l.user_name', $user_name)
        ->condition('l.ip_address', $ip);
    }
    elseif (!empty($user_name)) {
      $query->condition('l.user_name', $user_name);
    }
    elseif ($uid > 0) {
      $query->condition('l.uid', $uid);
    }
    elseif (!empty($ip)) {
      $query->condition('l.uid', 0)
        ->condition('l.ip_address', $ip);
    }
    else {
      return [];
    }

    $nids = $query->execute()->fetchCol();
    return array_map('intval', $nids);
  }

  /**
   * Obtiene el detalle de likes registrados para un artículo.
   */
  public function getLikesDetail(int $nid, int $limit = 50, int $offset = 0): array {
    $query = $this->database->select('tc_article_likes', 'l')
      ->fields('l', ['id', 'nid', 'uid', 'user_name', 'ip_address', 'user_agent', 'created'])
      ->condition('l.nid', $nid)
      ->orderBy('l.created', 'DESC')
      ->range($offset, $limit);

    $results = $query->execute()->fetchAll();

    $user_storage = $this->entityTypeManager->getStorage('user');
    $items = [];
    foreach ($results as $row) {
      $display_name = !empty($row->user_name) ? $row->user_name : 'Anónimo';
      if ((int) $row->uid > 0) {
        $user = $user_storage->load((int) $row->uid);
        if ($user) {
          $display_name = $user->getDisplayName();
        }
      }

      $items[] = [
        'id' => (int) $row->id,
        'nid' => (int) $row->nid,
        'uid' => (int) $row->uid,
        'user_name' => $display_name,
        'ip_address' => $row->ip_address,
        'user_agent' => $row->user_agent,
        'created' => (int) $row->created,
        'created_iso' => date('c', (int) $row->created),
      ];
    }

    return $items;
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
