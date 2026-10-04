<?php

namespace Drupal\tc_api_v1\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\tc_api_v1\Service\CommentLikeManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controlador para la API REST de likes en comentarios de noticias.
 */
class CommentLikesController extends ControllerBase {

  /**
   * Servicio CommentLikeManager.
   */
  protected CommentLikeManager $commentLikeManager;

  /**
   * Conexión a la base de datos.
   */
  protected Connection $database;

  /**
   * Constructor de CommentLikesController.
   */
  public function __construct(CommentLikeManager $comment_like_manager, Connection $database) {
    $this->commentLikeManager = $comment_like_manager;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tc_api_v1.comment_like_manager'),
      $container->get('database')
    );
  }

  /**
   * Registra o conmuta un like para un comentario específico.
   *
   * POST /api/v1/comments/{commentId}/likes
   */
  public function toggleLike($commentId, Request $request): JsonResponse {
    $cid = (int) $commentId;
    if ($cid <= 0) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('ID de comentario inválido.'),
      ], Response::HTTP_BAD_REQUEST);
    }

    // Verificar que el comentario exista en tc_article_comments
    if ($this->database->schema()->tableExists('tc_article_comments')) {
      $exists = $this->database->select('tc_article_comments', 'c')
        ->fields('c', ['id'])
        ->condition('c.id', $cid)
        ->condition('c.status', 1)
        ->range(0, 1)
        ->execute()
        ->fetchField();
      if (!$exists) {
        return new JsonResponse([
          'status' => 'error',
          'message' => $this->t('El comentario no existe o no está publicado.'),
        ], Response::HTTP_NOT_FOUND);
      }
    }

    // Extraer datos del cuerpo o query
    $data = json_decode($request->getContent(), TRUE);
    if (empty($data)) {
      $data = $request->request->all();
    }

    $action = strtolower(trim($data['action'] ?? 'toggle'));
    if (!in_array($action, ['toggle', 'like', 'unlike'])) {
      $action = 'toggle';
    }

    // Determinar ID del usuario
    $uid = 0;
    $current_user = $this->currentUser();
    if ($current_user && $current_user->isAuthenticated()) {
      $uid = (int) $current_user->id();
    }
    elseif (!empty($data['uid']) || !empty($data['user_id'])) {
      $uid = (int) ($data['uid'] ?? $data['user_id']);
    }

    $xff = $request->headers->get('X-Forwarded-For');
    $ip = !empty($data['ip']) ? trim($data['ip']) : ($xff ? trim(explode(',', $xff)[0]) : ($request->getClientIp() ?: ''));
    $user_agent = $request->headers->get('User-Agent') ?: '';
    $user_name = trim($data['user_name'] ?? ($data['username'] ?? ($data['name'] ?? '')));
    if (empty($user_name) && $current_user && $current_user->isAuthenticated()) {
      $user_name = $current_user->getDisplayName();
    }

    try {
      $result = $this->commentLikeManager->processLike($cid, $uid, $ip, $user_agent, $action, $user_name);

      if (\Drupal::hasService('page_cache_kill_switch')) {
        \Drupal::service('page_cache_kill_switch')->trigger();
      }

      $response = new JsonResponse($result, Response::HTTP_OK);
      $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0');
      $response->headers->set('Pragma', 'no-cache');
      $response->headers->set('Expires', '0');
      return $response;
    }
    catch (\Throwable $e) {
      \Drupal::logger('tc_api_v1')->error('Error al procesar like para comentario @cid: @msg', [
        '@cid' => $cid,
        '@msg' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Ocurrió un error inesperado al procesar el like del comentario.'),
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Consulta el total de likes y si el usuario actual le ha dado like al comentario.
   *
   * GET /api/v1/comments/{commentId}/likes
   */
  public function getLikes($commentId, Request $request): JsonResponse {
    $cid = (int) $commentId;
    if ($cid <= 0) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('ID de comentario inválido.'),
      ], Response::HTTP_BAD_REQUEST);
    }

    $uid = 0;
    $current_user = $this->currentUser();
    if ($current_user && $current_user->isAuthenticated()) {
      $uid = (int) $current_user->id();
    }
    elseif ($request->query->has('uid')) {
      $uid = (int) $request->query->get('uid');
    }

    $xff = $request->headers->get('X-Forwarded-For');
    $ip = $request->query->get('ip') ?: ($xff ? trim(explode(',', $xff)[0]) : ($request->getClientIp() ?: ''));
    $user_name = trim($request->query->get('user_name') ?: ($request->query->get('username') ?: ''));
    if (empty($user_name) && $current_user && $current_user->isAuthenticated()) {
      $user_name = $current_user->getDisplayName();
    }

    $total = $this->commentLikeManager->getTotalLikes($cid);
    $liked = $this->commentLikeManager->hasUserLiked($cid, $uid, $ip, $user_name);

    if (\Drupal::hasService('page_cache_kill_switch')) {
      \Drupal::service('page_cache_kill_switch')->trigger();
    }

    $response = new JsonResponse([
      'status' => 'success',
      'cid' => $cid,
      'total_likes' => $total,
      'liked' => $liked,
    ], Response::HTTP_OK);

    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    return $response;
  }

  /**
   * Retorna los IDs de comentarios a los que el usuario o combinación (user_name + IP) ha dado like.
   *
   * GET /api/v1/user/comment-likes
   */
  public function getUserCommentLikes(Request $request): JsonResponse {
    $uid = 0;
    $current_user = $this->currentUser();
    if ($current_user && $current_user->isAuthenticated()) {
      $uid = (int) $current_user->id();
    }
    elseif ($request->query->has('uid')) {
      $uid = (int) $request->query->get('uid');
    }

    $xff = $request->headers->get('X-Forwarded-For');
    $ip = $request->query->get('ip') ?: ($xff ? trim(explode(',', $xff)[0]) : ($request->getClientIp() ?: ''));
    $user_name = trim($request->query->get('user_name') ?: ($request->query->get('username') ?: ''));
    if (empty($user_name) && $current_user && $current_user->isAuthenticated()) {
      $user_name = $current_user->getDisplayName();
    }

    $cids = $this->commentLikeManager->getAllUserLikedCommentIds($uid, $ip, $user_name);

    if (\Drupal::hasService('page_cache_kill_switch')) {
      \Drupal::service('page_cache_kill_switch')->trigger();
    }

    $response = new JsonResponse([
      'status' => 'success',
      'uid' => $uid,
      'ip' => $ip,
      'liked_cids' => $cids,
      'total' => count($cids),
    ], Response::HTTP_OK);

    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    return $response;
  }

}
