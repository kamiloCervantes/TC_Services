<?php

namespace Drupal\tc_api_v1\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;
use Drupal\node\Entity\Node;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides a resource to get articles (news).
 *
 * @RestResource(
 *   id = "tc_api_v1_articles",
 *   label = @Translation("Articles API v1"),
 *   uri_paths = {
 *     "canonical" = "/api/v1/articles",
 *     "collection" = "/api/v1/articles"
 *   }
 * )
 */
class ArticlesResource extends ResourceBase {

  /**
   * {@inheritdoc}
   */
  public function routes() {
    $collection = parent::routes();

    // Modificar la ruta GET canónica para que el parámetro {id} sea opcional.
    // De esta manera, /api/v1/articles/{id} y /api/v1/articles usarán la misma ruta
    // y compartirán la configuración de REST UI (formatos y autenticación).
    $canonical_route = $collection->get("rest.{$this->pluginId}.GET");
    if ($canonical_route) {
      $canonical_route->setDefault('id', NULL);
    }

    return $collection;
  }

  /**
   * Responds to GET requests.
   *
   * @param string|int|null $id
   *   The ID of the article, if provided.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Throws exception when article not found.
   */
  public function get($id = NULL) {
    if ($id) {
      return $this->getArticleDetail($id);
    }
    
    // Fallback if no ID is provided, return all.
    return $this->getAllArticles();
  }

  /**
   * Retrieves all news records.
   *
   * @return \Drupal\rest\ResourceResponse
   *   Response containing all articles.
   */
  protected function getAllArticles() {
    $entity_type_manager = \Drupal::entityTypeManager();
    $node_storage = $entity_type_manager->getStorage('node');
    
    $query = $node_storage->getQuery()
      ->condition('type', 'news')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->accessCheck(TRUE);
      
    $nids = $query->execute();
    $nodes = $node_storage->loadMultiple($nids);
    
    $data = [];
    foreach ($nodes as $node) {
      $data[] = $this->formatArticle($node);
    }

    return new ResourceResponse($data);
  }

  /**
   * Retrieves the detail of a single news record.
   *
   * @param string|int $id
   *   The ID of the article.
   *
   * @return \Drupal\rest\ResourceResponse
   *   Response containing the single article.
   */
  protected function getArticleDetail($id) {
    $entity_type_manager = \Drupal::entityTypeManager();
    $node_storage = $entity_type_manager->getStorage('node');
    
    $node = $node_storage->load($id);
    
    if (!$node || $node->bundle() !== 'news' || !$node->isPublished()) {
      throw new NotFoundHttpException('News article not found.');
    }

    return new ResourceResponse($this->formatArticle($node));
  }

  /**
   * Formats a node entity into the requested JSON structure.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node entity to format.
   *
   * @return array
   *   Formatted array.
   */
  protected function formatArticle(Node $node) {
    // Author data
    $author = $node->getOwner();
    $author_name = 'Desconocido';
    $author_avatar = '';

    if ($author) {
      // Get the name from field_nombre_completo if available
      if ($author->hasField('field_nombre_completo') && !$author->get('field_nombre_completo')->isEmpty()) {
        $author_name = $author->get('field_nombre_completo')->value;
      } else {
        $author_name = $author->getDisplayName();
      }

      // Get the avatar from user_picture
      if ($author->hasField('user_picture') && !$author->get('user_picture')->isEmpty()) {
        $file = $author->get('user_picture')->entity;
        if ($file) {
          $author_avatar = $file->createFileUrl(FALSE);
        }
      }
    }

    // Image data
    $image_url = '';
    $imgH = 200;
    if (!$node->get('field_imagen_destacada')->isEmpty()) {
      $file = $node->get('field_imagen_destacada')->entity;
      if ($file) {
        $image_url = $file->createFileUrl(FALSE);
        // If image metadata is needed for height, it can be extracted, but here we default.
        // $imgH = $file->get('image_height')->value ?: 200;
      }
    }

    // Category data
    $category = '';
    if (!$node->get('field_categoria')->isEmpty()) {
      $term = $node->get('field_categoria')->entity;
      if ($term) {
        $category = $term->getName();
      }
    }

    // Date formatter for timestamp "Hace X horas"
    $date_formatter = \Drupal::service('date.formatter');
    $timestamp_formatted = $date_formatter->formatTimeDiffSince($node->getCreatedTime()) . ' atrás';

    return [
      'id' => (int) $node->id(),
      'title' => $node->getTitle(),
      'excerpt' => !$node->get('field_resumen')->isEmpty() ? $node->get('field_resumen')->value : '',
      'content' => !$node->get('field_contenido')->isEmpty() ? $node->get('field_contenido')->value : '',
      'author' => [
        'name' => $author_name,
        'avatar' => $author_avatar,
      ],
      'image' => $image_url,
      'imgH' => $imgH,
      'timestamp' => $timestamp_formatted,
      'likes' => !$node->get('field_likes')->isEmpty() ? (int) $node->get('field_likes')->value : 0,
      'comments' => $node->get('field_comentarios')->count(),
      'views' => !$node->get('field_visualizaciones')->isEmpty() ? (int) $node->get('field_visualizaciones')->value : 0,
      'category' => $category,
      'boards' => [1], // Static for now as it's not defined in fields
      'liked' => false,
    ];
  }
}
