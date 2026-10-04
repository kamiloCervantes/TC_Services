<?php

namespace Drupal\tc_api_v1\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for retrieving, creating, and deleting news article comments.
 */
class CommentsController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * Constructs a CommentsController object.
   */
  public function __construct(Connection $database, DateFormatterInterface $date_formatter, TimeInterface $time) {
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('datetime.time')
    );
  }

  /**
   * Carga los comentarios de una noticia determinada.
   *
   * GET /api/v1/articles/{articleId}/comments
   *
   * @param int|string $articleId
   *   El ID del nodo de la noticia.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   La solicitud HTTP.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function getComments($articleId, Request $request): JsonResponse {
    $nid = (int) $articleId;
    if ($nid <= 0) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('ID de noticia inválido.'),
      ], Response::HTTP_BAD_REQUEST);
    }

    // Verificar que el nodo exista y esté publicado
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $node = $node_storage->load($nid);
    if (!$node || !$node->isPublished()) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('La noticia no existe o no está publicada.'),
      ], Response::HTTP_NOT_FOUND);
    }

    try {
      // Consultar los comentarios en la tabla personalizada tc_article_comments
      $query = $this->database->select('tc_article_comments', 'c');
      $query->leftJoin('users_field_data', 'u', 'c.uid = u.uid');
      $fields_to_select = ['id', 'nid', 'uid', 'message', 'created', 'ip_address', 'status'];
      if ($this->database->schema()->fieldExists('tc_article_comments', 'likes')) {
        $fields_to_select[] = 'likes';
      }
      $query->fields('c', $fields_to_select);
      $query->addField('u', 'name', 'author_name');
      $query->condition('c.nid', $nid);
      $query->condition('c.status', 1);
      $query->orderBy('c.created', 'DESC');
      $query->orderBy('c.id', 'DESC');

      $results = $query->execute()->fetchAll();
      $comments = [];

      // Extraer datos de identidad para consultar likes del usuario en estos comentarios
      $current_user = $this->currentUser();
      $uid = $current_user && $current_user->isAuthenticated() ? (int) $current_user->id() : 0;
      if ($uid === 0 && $request->query->has('uid')) {
        $uid = (int) $request->query->get('uid');
      }
      $xff = $request->headers->get('X-Forwarded-For');
      $ip = $request->query->get('ip') ?: ($xff ? trim(explode(',', $xff)[0]) : ($request->getClientIp() ?: ''));
      $user_name = trim($request->query->get('user_name') ?: ($request->query->get('username') ?: ''));
      if (empty($user_name) && $current_user && $current_user->isAuthenticated()) {
        $user_name = $current_user->getDisplayName();
      }

      $cids = array_map(function($r) { return (int) $r->id; }, $results);
      $liked_cids = [];
      if (\Drupal::hasService('tc_api_v1.comment_like_manager') && !empty($cids)) {
        /** @var \Drupal\tc_api_v1\Service\CommentLikeManager $comment_like_mgr */
        $comment_like_mgr = \Drupal::service('tc_api_v1.comment_like_manager');
        $liked_cids = $comment_like_mgr->getUserLikedCommentIds($cids, $uid, $ip, $user_name);
      }

      foreach ($results as $row) {
        $comments[] = [
          'id' => (int) $row->id,
          'nid' => (int) $row->nid,
          'uid' => (int) $row->uid,
          'author' => !empty($row->author_name) ? $row->author_name : 'Usuario ' . $row->uid,
          'avatar' => 'user-default-ud1',
          'text' => $row->message,
          'time' => 'Hace ' . $this->dateFormatter->formatTimeDiffSince($row->created),
          'created' => (int) $row->created,
          'created_iso' => date('c', $row->created),
          'likes' => isset($row->likes) ? (int) $row->likes : 0,
          'liked' => in_array((int) $row->id, $liked_cids, TRUE),
        ];
      }

      if (\Drupal::hasService('page_cache_kill_switch')) {
        \Drupal::service('page_cache_kill_switch')->trigger();
      }
      $response = new JsonResponse($comments, Response::HTTP_OK);
      $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0');
      $response->headers->set('Pragma', 'no-cache');
      $response->headers->set('Expires', '0');
      return $response;
    }
    catch (\Throwable $e) {
      \Drupal::logger('tc_api_v1')->error('Error al obtener comentarios de noticia @nid: @msg', [
        '@nid' => $nid,
        '@msg' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Error al cargar los comentarios de la noticia.'),
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Publica un nuevo comentario en una noticia determinada.
   *
   * POST /api/v1/articles/{articleId}/comments
   *
   * @param int|string $articleId
   *   El ID del nodo de la noticia.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   La solicitud HTTP con el cuerpo JSON.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function postComment($articleId, Request $request): JsonResponse {
    $nid = (int) $articleId;
    if ($nid <= 0) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('ID de noticia inválido.'),
      ], Response::HTTP_BAD_REQUEST);
    }

    // Verificar que el nodo exista y esté publicado
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $node = $node_storage->load($nid);
    if (!$node || !$node->isPublished()) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('La noticia no existe o no está publicada.'),
      ], Response::HTTP_NOT_FOUND);
    }

    // Parsear el cuerpo de la petición (JSON o Form)
    $data = json_decode($request->getContent(), TRUE);
    if (empty($data)) {
      $data = $request->request->all();
    }

    // Validar mensaje
    $message = trim($data['message'] ?? ($data['text'] ?? ($data['comentario'] ?? '')));
    if (empty($message)) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('El mensaje del comentario es requerido y no puede estar vacío.'),
      ], Response::HTTP_BAD_REQUEST);
    }

    // Validar ID de usuario (no permitir que quede vacío)
    $uid = 0;
    $current_user = $this->currentUser();
    if ($current_user && $current_user->isAuthenticated()) {
      $uid = (int) $current_user->id();
    }
    elseif (!empty($data['uid']) || !empty($data['user_id'])) {
      $uid = (int) ($data['uid'] ?? $data['user_id']);
    }

    if ($uid <= 0) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('El id de usuario (uid) es obligatorio para publicar un comentario.'),
      ], Response::HTTP_BAD_REQUEST);
    }

    // Verificar existencia del usuario en la base de datos
    $user = User::load($uid);
    if (!$user) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('El usuario especificado no existe.'),
      ], Response::HTTP_BAD_REQUEST);
    }

    // Obtener IP del cliente
    $ip = $request->getClientIp() ?: '';
    $timestamp = $this->time->getRequestTime();

    try {
      // 1. Guardar el nuevo comentario en la tabla tc_article_comments
      $comment_id = $this->database->insert('tc_article_comments')
        ->fields([
          'nid' => $nid,
          'uid' => $uid,
          'message' => strip_tags($message),
          'created' => $timestamp,
          'ip_address' => $ip,
          'status' => 1,
        ])
        ->execute();

      // 2. Recalcular y actualizar el contador en el nodo de la noticia
      $total_comments = self::updateNodeCommentCount($nid, $this->database, $this->entityTypeManager());

      $author_name = $user->getDisplayName();

      $formatted_comment = [
        'id' => (int) $comment_id,
        'nid' => $nid,
        'uid' => $uid,
        'author' => $author_name,
        'avatar' => 'user-default-ud1',
        'text' => strip_tags($message),
        'time' => 'Ahora',
        'created' => $timestamp,
        'created_iso' => date('c', $timestamp),
        'ip_address' => $ip,
        'likes' => 0,
        'liked' => false,
      ];

      return new JsonResponse([
        'status' => 'success',
        'message' => $this->t('Comentario publicado exitosamente.'),
        'comment' => $formatted_comment,
        'total_comments' => $total_comments,
        'id' => (int) $comment_id,
        'author' => $author_name,
        'avatar' => 'user-default-ud1',
        'text' => strip_tags($message),
        'time' => 'Ahora',
        'likes' => 0,
        'liked' => false,
      ], Response::HTTP_CREATED);
    }
    catch (\Throwable $e) {
      \Drupal::logger('tc_api_v1')->error('Error al insertar comentario en noticia @nid: @msg', [
        '@nid' => $nid,
        '@msg' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Ocurrió un error al guardar el comentario en la base de datos.'),
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Elimina un comentario de una noticia y actualiza el contador en el nodo.
   *
   * DELETE /api/v1/comments/{commentId}
   *
   * @param int|string $commentId
   *   El ID del comentario.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   La solicitud HTTP.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function deleteComment($commentId, Request $request): JsonResponse {
    $cid = (int) $commentId;
    if ($cid <= 0) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('ID de comentario inválido.'),
      ], Response::HTTP_BAD_REQUEST);
    }

    // Buscar el comentario existente
    $comment = $this->database->select('tc_article_comments', 'c')
      ->fields('c', ['id', 'nid', 'uid', 'status'])
      ->condition('c.id', $cid)
      ->execute()
      ->fetchObject();

    if (!$comment) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('El comentario no existe.'),
      ], Response::HTTP_NOT_FOUND);
    }

    // Validar permisos: usuario autenticado propietario o administrador
    $current_user = $this->currentUser();
    $is_admin = $current_user && $current_user->hasPermission('access administration pages');
    $is_owner = $current_user && ((int) $current_user->id() === (int) $comment->uid);

    $request_uid = (int) ($request->query->get('uid') ?: $request->request->get('uid'));
    if (!$is_admin && !$is_owner) {
      if ($request_uid > 0 && $request_uid === (int) $comment->uid) {
        $is_owner = TRUE;
      }
    }

    if (!$is_admin && !$is_owner) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('No tienes permisos para eliminar este comentario.'),
      ], Response::HTTP_FORBIDDEN);
    }

    try {
      // Eliminar el comentario de la base de datos
      $this->database->delete('tc_article_comments')
        ->condition('id', $cid)
        ->execute();

      // Recalcular y actualizar el contador en el nodo de la noticia
      $total_comments = self::updateNodeCommentCount((int) $comment->nid, $this->database, $this->entityTypeManager());

      return new JsonResponse([
        'status' => 'success',
        'message' => $this->t('Comentario eliminado exitosamente.'),
        'comment_id' => $cid,
        'nid' => (int) $comment->nid,
        'total_comments' => $total_comments,
      ], Response::HTTP_OK);
    }
    catch (\Throwable $e) {
      \Drupal::logger('tc_api_v1')->error('Error al eliminar comentario @cid: @msg', [
        '@cid' => $cid,
        '@msg' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Ocurrió un error al eliminar el comentario.'),
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Recalcula y actualiza el campo field_total_comentarios en el nodo de la noticia.
   *
   * @param int $nid
   *   El ID del nodo.
   * @param \Drupal\Core\Database\Connection $database
   *   La conexión a la base de datos.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   El gestor de entidades.
   *
   * @return int
   *   El total de comentarios aprobados.
   */
  public static function updateNodeCommentCount(int $nid, Connection $database, $entity_type_manager): int {
    $total_comments = (int) $database->select('tc_article_comments', 'c')
      ->condition('c.nid', $nid)
      ->condition('c.status', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    $node_storage = $entity_type_manager->getStorage('node');
    $node_to_update = $node_storage->load($nid);
    if ($node_to_update) {
      $updated = FALSE;
      foreach (['field_total_comentarios', 'field_total_comments', 'field_comentarios_total'] as $field_name) {
        if ($node_to_update->hasField($field_name)) {
          $node_to_update->set($field_name, $total_comments);
          $updated = TRUE;
          break;
        }
      }
      if ($updated) {
        $node_to_update->save();
      }
    }

    \Drupal\Core\Cache\Cache::invalidateTags([
      'node:' . $nid,
      'node_list',
      'node_list:news',
      'rendered',
    ]);

    return $total_comments;
  }

}