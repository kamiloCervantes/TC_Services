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

    // Verificamos si tiene el campo comentarios y no está vacío
    if ($node->hasField('field_comentarios') && !$node->get('field_comentarios')->isEmpty()) {
      // Obtenemos los párrafos referenciados (comentarios)
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
      // Format as "Hace X" (formato relativo según Drupal)
      $time_formatted = 'Hace ' . $date_formatter->formatTimeDiffSince($timestamp);
    }

    return [
      'id' => (int) $comment->id(),
      'author' => $author,
      'avatar' => '', // Se deja vacío ya que no hay campo definido para el avatar del invitado.
      'text' => strip_tags($text), // Limpiamos HTML si el campo es de tipo text long con wysiwyg
      'time' => $time_formatted,
      'likes' => $likes,
      'liked' => false,
    ];
  }
}
