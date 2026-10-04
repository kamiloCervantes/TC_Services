<?php

namespace Drupal\tc_api_v1\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Servicio para gestionar los likes en comentarios de noticias combinando IP y usuario.
 */
class CommentLikeManager {

  use StringTranslationTrait;

  /**
   * Conexión a la base de datos de Drupal.
   */
  protected Connection $database;

  /**
   * Administrador de tipos de entidad.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Usuario actual en la sesión o token.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Servicio de tiempo.
   */
  protected TimeInterface $time;

  /**
   * Constructor del servicio CommentLikeManager.
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
   * Procesa la acción de dar/quitar like a un comentario combinando user_name con IP.
   */
  public function processLike(
    int $cid,
    int $uid = 0,
    string $ip = '',
    string $user_agent = '',
    string $action = 'toggle',
    string $user_name = ''
  ): array {
    $existing = $this->findExistingLike($cid, $uid, $ip, $user_name);

    if ($action === 'like') {
      if ($existing) {
        $total = $this->updateCommentLikes($cid);
        return [
          'status' => 'success',
          'action' => 'already_liked',
          'liked' => TRUE,
          'total_likes' => $total,
          'cid' => $cid,
          'message' => $this->t('Ya habías indicado que te gusta este comentario.'),
        ];
      }
      return $this->addLike($cid, $uid, $ip, $user_agent, $user_name);
    }

    if ($action === 'unlike') {
      if ($existing) {
        return $this->removeLikeRecord((int) $existing->id, $cid);
      }

      if (!empty($user_name)) {
        $by_name = $this->database->select('tc_comment_likes', 'l')
          ->fields('l', ['id'])
          ->condition('l.cid', $cid)
          ->condition('l.user_name', $user_name)
          ->range(0, 1)
          ->execute()
          ->fetchField();
        if ($by_name) {
          return $this->removeLikeRecord((int) $by_name, $cid);
        }
      }

      $total = $this->updateCommentLikes($cid);
      return [
        'status' => 'success',
        'action' => 'unliked',
        'liked' => FALSE,
        'total_likes' => $total,
        'cid' => $cid,
        'message' => $this->t('Like de comentario removido exitosamente.'),
      ];
    }

    // Default: toggle
    if ($existing) {
      return $this->removeLikeRecord((int) $existing->id, $cid);
    }

    return $this->addLike($cid, $uid, $ip, $user_agent, $user_name);
  }

  /**
   * Registra un nuevo like de comentario guardando user_name e IP.
   */
  public function addLike(int $cid, int $uid = 0, string $ip = '', string $user_agent = '', string $user_name = ''): array {
    $timestamp = $this->time->getRequestTime();

    // Obtener nid asociado al comentario si existe
    $nid = 0;
    if ($this->database->schema()->tableExists('tc_article_comments')) {
      $nid = (int) $this->database->select('tc_article_comments', 'c')
        ->fields('c', ['nid'])
        ->condition('c.id', $cid)
        ->execute()
        ->fetchField();
    }

    $like_id = $this->database->insert('tc_comment_likes')
      ->fields([
        'cid' => $cid,
        'nid' => $nid,
        'uid' => $uid,
        'user_name' => substr($user_name, 0, 128),
        'ip_address' => substr($ip, 0, 45),
        'user_agent' => substr($user_agent, 0, 255),
        'created' => $timestamp,
      ])
      ->execute();

    $total = $this->updateCommentLikes($cid);

    return [
      'status' => 'success',
      'action' => 'liked',
      'liked' => TRUE,
      'like_id' => (int) $like_id,
      'total_likes' => $total,
      'cid' => $cid,
      'message' => $this->t('Like de comentario registrado exitosamente.'),
    ];
  }

  /**
   * Elimina un registro de like de comentario existente y actualiza conteo.
   */
  public function removeLikeRecord(int $like_id, int $cid): array {
    $this->database->delete('tc_comment_likes')
      ->condition('id', $like_id)
      ->execute();

    $total = $this->updateCommentLikes($cid);

    return [
      'status' => 'success',
      'action' => 'unliked',
      'liked' => FALSE,
      'total_likes' => $total,
      'cid' => $cid,
      'message' => $this->t('Like de comentario removido exitosamente.'),
    ];
  }

  /**
   * Verifica si un usuario o combinación (user_name + IP) ya ha dado like a un comentario.
   */
  public function hasUserLiked(int $cid, int $uid = 0, string $ip = '', string $user_name = ''): bool {
    return $this->findExistingLike($cid, $uid, $ip, $user_name) !== NULL;
  }

  /**
   * Busca un like existente para un comentario combinando user_name con IP.
   */
  public function findExistingLike(int $cid, int $uid = 0, string $ip = '', string $user_name = ''): ?object {
    $query = $this->database->select('tc_comment_likes', 'l')
      ->fields('l', ['id', 'cid', 'nid', 'uid', 'user_name', 'ip_address', 'created'])
      ->condition('l.cid', $cid);

    // 1. Prioridad: combinación exacta de nombre de usuario con IP
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
    // 4. Por IP anónima (únicamente registros anónimos sin nombre de usuario)
    elseif (!empty($ip)) {
      $query->condition('l.uid', 0)
        ->condition('l.ip_address', $ip);
      $orGroup = $query->orConditionGroup()
        ->condition('l.user_name', '')
        ->isNull('l.user_name');
      $query->condition($orGroup);
    }
    else {
      return NULL;
    }

    $result = $query->range(0, 1)->execute()->fetchObject();
    return $result ?: NULL;
  }

  /**
   * Obtiene los IDs de comentarios a los que el usuario o combinación (user_name + IP) ha dado like de entre una lista dada.
   */
  public function getUserLikedCommentIds(array $cids, int $uid = 0, string $ip = '', string $user_name = ''): array {
    if (empty($cids)) {
      return [];
    }

    $query = $this->database->select('tc_comment_likes', 'l')
      ->fields('l', ['cid'])
      ->condition('l.cid', $cids, 'IN')
      ->distinct();

    // 1. Coincidencia por user_name e IP
    if (!empty($user_name) && !empty($ip)) {
      $query->condition('l.user_name', $user_name)
        ->condition('l.ip_address', $ip);
    }
    // 2. Por user_name
    elseif (!empty($user_name)) {
      $query->condition('l.user_name', $user_name);
    }
    // 3. Por usuario autenticado
    elseif ($uid > 0) {
      $query->condition('l.uid', $uid);
    }
    // 4. Por IP anónima (únicamente registros anónimos sin nombre de usuario)
    elseif (!empty($ip)) {
      $query->condition('l.uid', 0)
        ->condition('l.ip_address', $ip);
      $orGroup = $query->orConditionGroup()
        ->condition('l.user_name', '')
        ->isNull('l.user_name');
      $query->condition($orGroup);
    }
    else {
      return [];
    }

    $liked_cids = $query->execute()->fetchCol();
    return array_map('intval', $liked_cids);
  }

  /**
   * Obtiene todos los IDs de comentarios a los que este usuario o combinación ha dado like.
   */
  public function getAllUserLikedCommentIds(int $uid = 0, string $ip = '', string $user_name = ''): array {
    $query = $this->database->select('tc_comment_likes', 'l')
      ->fields('l', ['cid'])
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
      $orGroup = $query->orConditionGroup()
        ->condition('l.user_name', '')
        ->isNull('l.user_name');
      $query->condition($orGroup);
    }
    else {
      return [];
    }

    $liked_cids = $query->execute()->fetchCol();
    return array_map('intval', $liked_cids);
  }

  /**
   * Obtiene el conteo total de likes de un comentario.
   */
  public function getTotalLikes(int $cid): int {
    if (!$this->database->schema()->tableExists('tc_comment_likes')) {
      return 0;
    }
    return (int) $this->database->select('tc_comment_likes', 'l')
      ->condition('l.cid', $cid)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Recalcula y actualiza la columna likes en tc_article_comments.
   */
  public function updateCommentLikes(int $cid): int {
    $total = $this->getTotalLikes($cid);

    if ($this->database->schema()->tableExists('tc_article_comments')) {
      $this->database->update('tc_article_comments')
        ->fields(['likes' => $total])
        ->condition('id', $cid)
        ->execute();
    }

    // Invalidar etiquetas de caché de la respuesta
    Cache::invalidateTags([
      'tc_comment:' . $cid,
      'rendered',
    ]);

    return $total;
  }

}
