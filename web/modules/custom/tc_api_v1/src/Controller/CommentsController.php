<?php

namespace Drupal\tc_api_v1\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for retrieving and creating news article comments.
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
      $query->fields('c', ['id', 'nid', 'uid', 'message', 'created', 'ip_address', 'status']);
      $query->addField('u', 'name', 'author_name');
      $query->condition('c.nid', $nid);
      $query->condition('c.status', 1);
      $query->orderBy('c.created', 'DESC');
      $query->orderBy('c.id', 'DESC');

      $results = $query->execute()->fetchAll();
      $comments = [];

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
          'likes' => 0,
          'liked' => false,
        ];
      }

      return new JsonResponse($comments, Response::HTTP_OK);
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

}