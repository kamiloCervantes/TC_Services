<?php

namespace Drupal\tc_api_v1\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\tc_api_v1\Service\LikeManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controlador de la API para registrar y consultar likes de noticias.
 */
class LikesController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * El servicio gestor de likes.
   *
   * @var \Drupal\tc_api_v1\Service\LikeManager
   */
  protected LikeManager $likeManager;

  /**
   * Constructor.
   */
  public function __construct(LikeManager $like_manager) {
    $this->likeManager = $like_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tc_api_v1.like_manager')
    );
  }

  /**
   * Registra, quita o conmuta (toggle) un like en una noticia.
   *
   * POST /api/v1/articles/{articleId}/likes
   * POST /api/v1/articles/{articleId}/like
   */
  public function toggleLike($articleId, Request $request): JsonResponse {
    $nid = (int) $articleId;
    if ($nid <= 0) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('ID de artículo inválido.'),
      ], Response::HTTP_BAD_REQUEST);
    }

    // Verificar existencia del nodo noticia
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $node = $node_storage->load($nid);
    if (!$node || !$node->isPublished()) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('La noticia no existe o no está publicada.'),
      ], Response::HTTP_NOT_FOUND);
    }

    // Extraer datos del cuerpo
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

    try {
      $result = $this->likeManager->processLike($nid, $uid, $ip, $user_agent, $action);

      // Desactivar caché HTTP para respuestas dinámicas
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
      \Drupal::logger('tc_api_v1')->error('Error al procesar like para nodo @nid: @msg', [
        '@nid' => $nid,
        '@msg' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Ocurrió un error al registrar el like.'),
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Consulta el conteo de likes y el estado del usuario/IP actual.
   *
   * GET /api/v1/articles/{articleId}/likes
   */
  public function getLikes($articleId, Request $request): JsonResponse {
    $nid = (int) $articleId;
    if ($nid <= 0) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('ID de artículo inválido.'),
      ], Response::HTTP_BAD_REQUEST);
    }

    $node_storage = $this->entityTypeManager()->getStorage('node');
    $node = $node_storage->load($nid);
    if (!$node || !$node->isPublished()) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('La noticia no existe o no está publicada.'),
      ], Response::HTTP_NOT_FOUND);
    }

    // Determinar ID del usuario
    $uid = 0;
    $current_user = $this->currentUser();
    if ($current_user && $current_user->isAuthenticated()) {
      $uid = (int) $current_user->id();
    }
    elseif ($request->query->has('uid')) {
      $uid = (int) $request->query->get('uid');
    }

    $ip = $request->getClientIp() ?: '';

    $total = $this->likeManager->getTotalLikes($nid);
    $liked = $this->likeManager->hasUserLiked($nid, $uid, $ip);

    $responseData = [
      'status' => 'success',
      'nid' => $nid,
      'total_likes' => $total,
      'liked' => $liked,
    ];

    // Si se solicita el detalle de likes (por ejemplo para administradores o analítica)
    if ($request->query->get('detail')) {
      $limit = min(100, max(1, (int) ($request->query->get('limit') ?: 50)));
      $offset = max(0, (int) ($request->query->get('offset') ?: 0));
      $responseData['detail'] = $this->likeManager->getLikesDetail($nid, $limit, $offset);
    }

    if (\Drupal::hasService('page_cache_kill_switch')) {
      \Drupal::service('page_cache_kill_switch')->trigger();
    }

    $response = new JsonResponse($responseData, Response::HTTP_OK);
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    return $response;
  }

  /**
   * Retorna los IDs de artículos a los que el usuario actual o IP ha dado like.
   *
   * GET /api/v1/user/likes
   */
  public function getUserLikes(Request $request): JsonResponse {
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

    $nids = $this->likeManager->getUserLikedNodeIds($uid, $ip);

    if (\Drupal::hasService('page_cache_kill_switch')) {
      \Drupal::service('page_cache_kill_switch')->trigger();
    }

    $response = new JsonResponse([
      'status' => 'success',
      'uid' => $uid,
      'ip' => $ip,
      'liked_nids' => $nids,
      'total' => count($nids),
    ], Response::HTTP_OK);

    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    return $response;
  }

}
