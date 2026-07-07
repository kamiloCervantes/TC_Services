<?php

namespace Drupal\tc_api_v1\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Provides a resource to get timelines.
 *
 * @RestResource(
 *   id = "tc_api_v1_timelines",
 *   label = @Translation("Timelines API v1"),
 *   uri_paths = {
 *     "canonical" = "/api/v1/timelines/{id}",
 *     "collection" = "/api/v1/timelines"
 *   }
 * )
 */
class TimelinesResource extends ResourceBase {

  /**
   * Responds to GET requests.
   *
   * @param string|int|null $id
   *   The ID of the timeline, if provided.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Throws exception when timeline not found.
   */
  public function get($id = NULL) {
    if ($id) {
      // Remove any prefix if needed, or assume it's the node ID directly.
      // If the frontend sends 'timeline-1', we can strip it.
      $numeric_id = str_replace('timeline-', '', $id);
      return $this->getTimelineDetail($numeric_id);
    }
    
    return $this->getAllTimelines();
  }

  /**
   * Retrieves all timelines records.
   *
   * @return \Drupal\rest\ResourceResponse
   *   Response containing all timelines.
   */
  protected function getAllTimelines() {
    $entity_type_manager = \Drupal::entityTypeManager();
    $node_storage = $entity_type_manager->getStorage('node');
    
    $query = $node_storage->getQuery()
      ->condition('type', 'timelines')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->accessCheck(TRUE);
      
    $nids = $query->execute();
    $nodes = $node_storage->loadMultiple($nids);
    
    $data = [];
    foreach ($nodes as $node) {
      $data[] = $this->formatTimeline($node);
    }

    return new ResourceResponse(array_values($data));
  }

  /**
   * Retrieves the detail of a single timeline record.
   *
   * @param string|int $id
   *   The ID of the timeline.
   *
   * @return \Drupal\rest\ResourceResponse
   *   Response containing the single timeline.
   */
  protected function getTimelineDetail($id) {
    $entity_type_manager = \Drupal::entityTypeManager();
    $node_storage = $entity_type_manager->getStorage('node');
    
    $node = $node_storage->load($id);
    
    if (!$node || $node->bundle() !== 'timelines' || !$node->isPublished()) {
      throw new NotFoundHttpException('Timeline not found.');
    }

    return new ResourceResponse($this->formatTimeline($node));
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
  protected function formatTimeline(Node $node) {
    $events = [];

    // Parse events (paragraphs)
    if ($node->hasField('field_eventos') && !$node->get('field_eventos')->isEmpty()) {
      $paragraphs = $node->get('field_eventos')->referencedEntities();
      foreach ($paragraphs as $paragraph) {
        $event = [];

        // Date
        if ($paragraph->hasField('field_fecha') && !$paragraph->get('field_fecha')->isEmpty()) {
          $event['date'] = $paragraph->get('field_fecha')->value;
        }

        // Title
        if ($paragraph->hasField('field_titulo') && !$paragraph->get('field_titulo')->isEmpty()) {
          $event['title'] = $paragraph->get('field_titulo')->value;
        }

        // Description
        if ($paragraph->hasField('field_descripcion') && !$paragraph->get('field_descripcion')->isEmpty()) {
          $event['desc'] = strip_tags($paragraph->get('field_descripcion')->value);
        }

        // Optional Extra Content
        if ($paragraph->hasField('field_complemento') && !$paragraph->get('field_complemento')->isEmpty()) {
          $event['extraContent'] = strip_tags($paragraph->get('field_complemento')->value);
        }

        // Optional Article ID
        if ($paragraph->hasField('field_noticia') && !$paragraph->get('field_noticia')->isEmpty()) {
          $article_ref = $paragraph->get('field_noticia')->target_id;
          if ($article_ref) {
            $event['articleId'] = (int) $article_ref;
          }
        }

        $events[] = $event;
      }
    }

    return [
      'id' => 'timeline-' . $node->id(), // Prefixed for uniqueness in frontend based on user JSON example 'agua'
      'name' => $node->getTitle(),
      'period' => !$node->get('field_periodo')->isEmpty() ? $node->get('field_periodo')->value : '',
      'color' => !$node->get('field_color')->isEmpty() ? $node->get('field_color')->value : '',
      'badgeBg' => !$node->get('field_color_fondo_badge')->isEmpty() ? $node->get('field_color_fondo_badge')->value : '',
      'badgeIcon' => !$node->get('field_icono_badge')->isEmpty() ? $node->get('field_icono_badge')->value : '',
      'events' => $events,
    ];
  }
}
