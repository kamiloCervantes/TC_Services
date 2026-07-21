<?php

namespace Drupal\tc_api_v1\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a unified feed combining articles and sponsored posts.
 *
 * The feed interleaves sponsored posts into the article list at a ratio of
 * 1 sponsored post per every 4 articles.
 *
 * Supports pagination via query parameters:
 *   - page:  Page number (1-indexed, default 1).
 *   - limit: Number of items per page (default 10, max 50).
 *
 * Response shape:
 * {
 *   "data": [...],
 *   "pagination": {
 *     "current_page": 1,
 *     "next_page": 2,
 *     "has_more": true,
 *     "total_items": 42
 *   }
 * }
 *
 * @RestResource(
 *   id = "tc_api_v1_feed",
 *   label = @Translation("Feed API v1"),
 *   uri_paths = {
 *     "canonical" = "/api/v1/feed",
 *     "collection" = "/api/v1/feed"
 *   }
 * )
 */
class FeedResource extends ResourceBase {

  /**
   * Default number of items per page.
   */
  const DEFAULT_LIMIT = 10;

  /**
   * Maximum allowed items per page.
   */
  const MAX_LIMIT = 50;

  /**
   * Responds to GET requests.
   *
   * Accepts optional query parameters: ?page=1&limit=10
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object.
   */
  public function get() {
    $request = \Drupal::request();

    // Parse and sanitize pagination params.
    $page  = max(1, (int) $request->query->get('page', 1));
    $limit = min(self::MAX_LIMIT, max(1, (int) $request->query->get('limit', self::DEFAULT_LIMIT)));

    // Build full merged feed (articles + sponsored interleaved).
    $articles  = $this->getAllArticles();
    $sponsored = $this->getAllSponsored();
    $full_feed = $this->buildFeed($articles, $sponsored);

    $total_items = count($full_feed);

    // Slice the page out of the full feed.
    $offset = ($page - 1) * $limit;
    $page_items = array_slice($full_feed, $offset, $limit);

    $has_more  = ($offset + $limit) < $total_items;
    $next_page = $has_more ? $page + 1 : null;

    $payload = [
      'data' => $page_items,
      'pagination' => [
        'current_page' => $page,
        'next_page'    => $next_page,
        'has_more'     => $has_more,
        'total_items'  => $total_items,
      ],
    ];

    $response = new ResourceResponse($payload);
    $response->addCacheableDependency(['#cache' => ['max-age' => 0]]);
    return $response;
  }

  /**
   * Builds the merged feed interleaving sponsored posts.
   *
   * Layout: [article, article, article, article, sponsored, article, ...]
   * Sponsored posts rotate cyclically if there are fewer sponsored than needed.
   *
   * @param array $articles
   *   Formatted article items.
   * @param array $sponsored
   *   Formatted sponsored items.
   *
   * @return array
   *   The final merged feed array.
   */
  protected function buildFeed(array $articles, array $sponsored): array {
    // If no sponsored posts exist, return articles only.
    if (empty($sponsored)) {
      return $articles;
    }

    $feed = [];
    $sponsored_count = count($sponsored);
    $sponsored_index = 0;
    $article_counter = 0;

    foreach ($articles as $article) {
      $feed[] = $article;
      $article_counter++;

      // After every 4 articles, inject one sponsored post (rotating cyclically).
      if ($article_counter % 4 === 0) {
        $feed[] = $sponsored[$sponsored_index % $sponsored_count];
        $sponsored_index++;
      }
    }

    return $feed;
  }

  /**
   * Retrieves all published news articles ordered by creation date (newest first).
   *
   * @return array
   *   Array of formatted article data.
   */
  protected function getAllArticles(): array {
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');

    $nids = $node_storage->getQuery()
      ->condition('type', 'news')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->accessCheck(TRUE)
      ->execute();

    $nodes = $node_storage->loadMultiple($nids);

    $data = [];
    foreach ($nodes as $node) {
      $data[] = $this->formatArticle($node);
    }

    return $data;
  }

  /**
   * Retrieves all active sponsored posts ordered by position.
   *
   * @return array
   *   Array of formatted sponsored post data.
   */
  protected function getAllSponsored(): array {
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');

    $nids = $node_storage->getQuery()
      ->condition('type', 'sponsored_posts')
      ->condition('status', 1)
      ->condition('field_visible', 1)
      ->sort('field_posicion', 'ASC')
      ->accessCheck(TRUE)
      ->execute();

    $nodes = $node_storage->loadMultiple($nids);

    $data = [];
    foreach ($nodes as $node) {
      $data[] = $this->formatSponsored($node);
    }

    return array_values($data);
  }

  /**
   * Formats a news node into the article structure.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The news node entity.
   *
   * @return array
   *   Formatted article array with a 'type' key set to 'article'.
   */
  protected function formatArticle(Node $node): array {
    // Author data.
    $author = $node->getOwner();
    $author_name = 'Desconocido';
    $author_avatar = '';

    if ($author) {
      if ($author->hasField('field_nombre_completo') && !$author->get('field_nombre_completo')->isEmpty()) {
        $author_name = $author->get('field_nombre_completo')->value;
      } else {
        $author_name = $author->getDisplayName();
      }

      if ($author->hasField('user_picture') && !$author->get('user_picture')->isEmpty()) {
        $file = $author->get('user_picture')->entity;
        if ($file) {
          $author_avatar = $file->createFileUrl(FALSE);
        }
      }
    }

    // Image data.
    $image_url = '';
    $imgH = 200;
    if (!$node->get('field_imagen_destacada')->isEmpty()) {
      $file = $node->get('field_imagen_destacada')->entity;
      if ($file) {
        $image_url = $file->createFileUrl(FALSE);
      }
    }

    // Category data.
    $category = '';
    if (!$node->get('field_categoria')->isEmpty()) {
      $term = $node->get('field_categoria')->entity;
      if ($term) {
        $category = $term->getName();
      }
    }

    // Relative timestamp.
    $date_formatter = \Drupal::service('date.formatter');
    $timestamp_formatted = $date_formatter->formatTimeDiffSince($node->getCreatedTime()) . ' atrás';

    return [
      'type'      => 'article',
      'id'        => (int) $node->id(),
      'title'     => $node->getTitle(),
      'excerpt'   => !$node->get('field_resumen')->isEmpty() ? $node->get('field_resumen')->value : '',
      'content'   => !$node->get('field_contenido')->isEmpty() ? $node->get('field_contenido')->value : '',
      'author'    => [
        'name'   => $author_name,
        'avatar' => $author_avatar,
      ],
      'image'     => $image_url,
      'imgH'      => $imgH,
      'timestamp' => $timestamp_formatted,
      'likes'     => !$node->get('field_likes')->isEmpty() ? (int) $node->get('field_likes')->value : 0,
      'comments'  => $node->get('field_comentarios')->count(),
      'views'     => !$node->get('field_visualizaciones')->isEmpty() ? (int) $node->get('field_visualizaciones')->value : 0,
      'category'  => $category,
      'boards'    => [1],
      'liked'     => false,
    ];
  }

  /**
   * Formats a sponsored_posts node into the sponsored structure.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The sponsored post node entity.
   *
   * @return array
   *   Formatted sponsored array with a 'type' key set to 'sponsored'.
   */
  protected function formatSponsored(Node $node): array {
    // Variant from taxonomy.
    $variant = '';
    if (!$node->get('field_variante')->isEmpty()) {
      $term = $node->get('field_variante')->entity;
      if ($term) {
        $variant = strtolower($term->getName());
      }
    }

    $formatted_id = 's' . $node->id();

    // Image.
    $image_url = '';
    if (!$node->get('field_imagen')->isEmpty()) {
      $file = $node->get('field_imagen')->entity;
      if ($file) {
        $image_url = $file->createFileUrl(FALSE);
      }
    }

    $imgH = !$node->get('field_altura')->isEmpty() ? (int) $node->get('field_altura')->value : 0;

    if ($variant === 'banner') {
      $clickUrl = !$node->get('field_url_cta')->isEmpty() ? $node->get('field_url_cta')->value : $image_url;

      return [
        'type'     => 'sponsored',
        'id'       => $formatted_id,
        'variant'  => $variant,
        'image'    => $image_url,
        'imgH'     => $imgH,
        'clickUrl' => $clickUrl,
      ];
    }

    // Standard variant.
    $sponsor_avatar = '';
    if (!$node->get('field_avatar_sponsor')->isEmpty()) {
      $file_avatar = $node->get('field_avatar_sponsor')->entity;
      if ($file_avatar) {
        $sponsor_avatar = $file_avatar->createFileUrl(FALSE);
      }
    }

    return [
      'type'          => 'sponsored',
      'id'            => $formatted_id,
      'variant'       => $variant ?: 'standard',
      'title'         => $node->getTitle(),
      'excerpt'       => !$node->get('field_resumen')->isEmpty() ? $node->get('field_resumen')->value : '',
      'image'         => $image_url,
      'imgH'          => $imgH,
      'sponsorName'   => !$node->get('field_nombre_sponsor')->isEmpty() ? $node->get('field_nombre_sponsor')->value : '',
      'sponsorAvatar' => $sponsor_avatar,
      'ctaText'       => !$node->get('field_texto_cta')->isEmpty() ? $node->get('field_texto_cta')->value : '',
      'ctaUrl'        => !$node->get('field_url_cta')->isEmpty() ? $node->get('field_url_cta')->value : '',
    ];
  }

}
