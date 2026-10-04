<?php

namespace Drupal\tc_api_v1\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Provides a resource to get comments for a specific article.
 *
 * @RestResource(
 *   id = "tc_api_v1_comments",
 *   label = @Translation("Comments API v1"),
 *   uri_paths = {
 *     "canonical" = "/api/v1/articles/{articleId}/comments"
 *   }
 * )
 */
class CommentsResource extends ResourceBase {

  /**
   * Responds to GET requests.
   *
   * @param string|int|null $articleId
   *   The ID of the article.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Throws exception when article not found.
   */
  public function get($articleId = NULL) {
    if (!$articleId) {
      throw new NotFoundHttpException('Article ID is required.');
    }

    $entity_type_manager = \Drupal::entityTypeManager();
    $node_storage = $entity_type_manager->getStorage('node');
    
    // Cargamos el nodo "news" usando el ID de la url
    $node = $node_storage->load($articleId);
    
    if (!$node || $node->bundle() !== 'news' || !$node->isPublished()) {
      throw new NotFoundHttpException('News article not found.');
    }

    $data = [];
    $database = \Drupal::database();

    // Consultamos los comentarios en la tabla personalizada tc_article_comments
    if ($database->schema()->tableExists('tc_article_comments')) {
      $query = $database->select('tc_article_comments', 'c');
      $query->leftJoin('users_field_data', 'u', 'c.uid = u.uid');
      $query->fields('c', ['id', 'nid', 'uid', 'message', 'created', 'ip_address', 'status']);
      $query->addField('u', 'name', 'author_name');
      $query->condition('c.nid', (int) $articleId);
      $query->condition('c.status', 1);
      $query->orderBy('c.created', 'ASC');
      $results = $query->execute()->fetchAll();

      $date_formatter = \Drupal::service('date.formatter');
      foreach ($results as $row) {
        $data[] = [
          'id' => (int) $row->id,
          'author' => !empty($row->author_name) ? $row->author_name : 'Usuario ' . $row->uid,
          'avatar' => 'user-default-ud1',
          'text' => $row->message,
          'time' => 'Hace ' . $date_formatter->formatTimeDiffSince($row->created),
          'created' => (int) $row->created,
          'likes' => 0,
          'liked' => false,
        ];
      }
    }

    // Si no hay comentarios en la tabla personalizada, verificamos el campo de párrafos existente
    if (empty($data) && $node->hasField('field_comentarios') && !$node->get('field_comentarios')->isEmpty()) {
      $comments = $node->get('field_comentarios')->referencedEntities();
      foreach ($comments as $comment) {
        $data[] = $this->formatComment($comment);
      }
    }

    return new ResourceResponse($data);
  }

  /**
   * Formats a paragraph entity into the requested JSON structure.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $comment
   *   The paragraph entity to format.
   *
   * @return array
   *   Formatted array.
   */
  protected function formatComment(Paragraph $comment) {
    // Nombre del autor
    $author = '';
    if ($comment->hasField('field_nombre_completo') && !$comment->get('field_nombre_completo')->isEmpty()) {
      $author = $comment->get('field_nombre_completo')->value;
    }

    // Texto del comentario
    $text = '';
    if ($comment->hasField('field_comentario') && !$comment->get('field_comentario')->isEmpty()) {
      $text = $comment->get('field_comentario')->value;
    }

    // Likes
    $likes = 0;
    if ($comment->hasField('field_likes') && !$comment->get('field_likes')->isEmpty()) {
      $likes = (int) $comment->get('field_likes')->value;
    }

    // Fecha "Hace X tiempo"
    $time_formatted = '';
    if ($comment->hasField('field_fecha_creado') && !$comment->get('field_fecha_creado')->isEmpty()) {
      $timestamp = $comment->get('field_fecha_creado')->value;
      $date_formatter = \Drupal::service('date.formatter');
      $time_formatted = 'Hace ' . $date_formatter->formatTimeDiffSince($timestamp);
    }

    return [
      'id' => (int) $comment->id(),
      'author' => $author,
      'avatar' => 'user-default-ud1',
      'text' => strip_tags($text),
      'time' => $time_formatted,
      'likes' => $likes,
      'liked' => false,
    ];
  }
}