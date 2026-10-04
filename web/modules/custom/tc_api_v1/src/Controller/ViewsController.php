<?php

namespace Drupal\tc_api_v1\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\tc_api_v1\Service\ViewManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controlador REST para el registro y consulta de visualizaciones de noticias.
 */
class ViewsController extends ControllerBase {

  /**
   * Servicio ViewManager.
   */
  protected ViewManager $viewManager;

  /**
   * Conexión a base de datos.
   */
  protected Connection $database;

  /**
   * Constructor de ViewsController.
   */
  public function __construct(ViewManager $view_manager, Connection $database) {
    $this->viewManager = $view_manager;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tc_api_v1.view_manager'),
      $container->get('database')
    );
  }

  /**
   * Registra una nueva visualización para un artículo sumando 1 a field_visualizaciones.
   *
   * POST /api/v1/articles/{articleId}/views
   */
  public function recordView($articleId, Request $request): JsonResponse {
    $nid = (int) $articleId;
    if ($nid <= 0) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('ID de noticia inválido.'),
      ], Response::HTTP_BAD_REQUEST);
    }

    // Verificar existencia del nodo
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $node = $node_storage->load($nid);
    if (!$node || !$node->isPublished()) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('La noticia no existe o no está publicada.'),
      ], Response::HTTP_NOT_FOUND);
    }

    // Extraer datos
    $data = json_decode($request->getContent(), TRUE);
    if (empty($data)) {
      $data = $request->request->all();
    }

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
      $result = $this->viewManager->recordView($nid, $uid, $ip, $user_agent, $user_name);

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
      \Drupal::logger('tc_api_v1')->error('Error al registrar visualización para noticia @nid: @msg', [
        '@nid' => $nid,
        '@msg' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Ocurrió un error inesperado al registrar la visualización.'),
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Consulta el total de visualizaciones de un artículo.
   *
   * GET /api/v1/articles/{articleId}/views
   */
  public function getViews($articleId, Request $request): JsonResponse {
    $nid = (int) $articleId;
    if ($nid <= 0) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('ID de noticia inválido.'),
      ], Response::HTTP_BAD_REQUEST);
    }

    $total = $this->viewManager->getTotalViews($nid);

    if (\Drupal::hasService('page_cache_kill_switch')) {
      \Drupal::service('page_cache_kill_switch')->trigger();
    }

    $response = new JsonResponse([
      'status' => 'success',
      'nid' => $nid,
      'views' => $total,
    ], Response::HTTP_OK);

    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    return $response;
  }

}
