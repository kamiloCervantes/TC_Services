<?php

namespace Drupal\tc_api_v1\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;
use Drupal\node\Entity\Node;

/**
 * Provides a resource to get sponsored posts.
 *
 * @RestResource(
 *   id = "tc_api_v1_sponsored",
 *   label = @Translation("Sponsored Posts API v1"),
 *   uri_paths = {
 *     "canonical" = "/api/v1/sponsored/{id}",
 *     "collection" = "/api/v1/sponsored"
 *   }
 * )
 */
class SponsoredResource extends ResourceBase {

  /**
   * Responds to GET requests.
   *
   * @param string|int|null $id
   *   The ID of the sponsored post, if provided.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Throws exception when sponsored post not found.
   */
  public function get($id = NULL) {
    if ($id) {
      // Si el id viene con prefijo 's', lo removemos.
      $numeric_id = ltrim($id, 's');
      return $this->getSponsoredDetail($numeric_id);
    }
    
    return $this->getAllSponsored();
  }

  /**
   * Retrieves all sponsored records ordered by position and creation date,
   * filtered by current active date range.
   *
   * @return \Drupal\rest\ResourceResponse
   *   Response containing all sponsored posts.
   */
  protected function getAllSponsored() {
    $entity_type_manager = \Drupal::entityTypeManager();
    $node_storage = $entity_type_manager->getStorage('node');
    
    $query = $node_storage->getQuery()
      ->condition('type', 'sponsored_posts')
      ->condition('status', 1)
      ->condition('field_visible', 1)
      ->accessCheck(TRUE);
      
    $nids = $query->execute();
    $nodes = $node_storage->loadMultiple($nids);
    
    $timezone_name = \Drupal::config('system.date')->get('timezone.default') ?: 'America/Bogota';
    $today = (new \DateTime('now', new \DateTimeZone($timezone_name)))->format('Y-m-d');

    $filtered_nodes = [];
    foreach ($nodes as $node) {
      // Excluir si la fecha actual no coincide con el rango entre field_fecha_inicio y field_fecha_fin.
      if (!$node->get('field_fecha_inicio')->isEmpty()) {
        $start_date = substr($node->get('field_fecha_inicio')->value, 0, 10);
        if ($start_date > $today) {
          continue;
        }
      }
      if (!$node->get('field_fecha_fin')->isEmpty()) {
        $end_date = substr($node->get('field_fecha_fin')->value, 0, 10);
        if ($end_date < $today) {
          continue;
        }
      }
      $filtered_nodes[] = $node;
    }

    // Ordenar de acuerdo al campo field_posicion (menor posición más arriba).
    // Para los que no tengan posición definida, ordenarlos por fecha de creación (más recientes primero)
    // después de los que sí tengan valor numérico en field_posicion.
    usort($filtered_nodes, function (Node $a, Node $b) {
      $has_pos_a = !$a->get('field_posicion')->isEmpty() && is_numeric($a->get('field_posicion')->value);
      $has_pos_b = !$b->get('field_posicion')->isEmpty() && is_numeric($b->get('field_posicion')->value);

      // Si uno tiene posición y el otro no, el que tiene posición va primero.
      if ($has_pos_a && !$has_pos_b) {
        return -1;
      }
      if (!$has_pos_a && $has_pos_b) {
        return 1;
      }

      // Si ambos tienen posición numérica, ordenar de menor a mayor (ASC).
      if ($has_pos_a && $has_pos_b) {
        $pos_a = (int) $a->get('field_posicion')->value;
        $pos_b = (int) $b->get('field_posicion')->value;
        if ($pos_a !== $pos_b) {
          return $pos_a <=> $pos_b;
        }
        // Desempate por fecha de creación (más reciente primero).
        return $b->getCreatedTime() <=> $a->getCreatedTime();
      }

      // Si ninguno tiene posición definida, ordenar por fecha de creación (más reciente primero).
      return $b->getCreatedTime() <=> $a->getCreatedTime();
    });

    $data = [];
    foreach ($filtered_nodes as $node) {
      $data[] = $this->formatSponsored($node);
    }

    if (\Drupal::hasService('page_cache_kill_switch')) {
      \Drupal::service('page_cache_kill_switch')->trigger();
    }
    $response = new ResourceResponse(array_values($data));
    $response->addCacheableDependency(['#cache' => ['max-age' => 0]]);
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    return $response;
  }

  /**
   * Retrieves the detail of a single sponsored record.
   *
   * @param string|int $id
   *   The ID of the sponsored post.
   *
   * @return \Drupal\rest\ResourceResponse
   *   Response containing the single sponsored post.
   */
  protected function getSponsoredDetail($id) {
    $entity_type_manager = \Drupal::entityTypeManager();
    $node_storage = $entity_type_manager->getStorage('node');
    
    $node = $node_storage->load($id);
    
    if (!$node || $node->bundle() !== 'sponsored_posts' || !$node->isPublished()) {
      throw new NotFoundHttpException('Sponsored post not found.');
    }

    if (\Drupal::hasService('page_cache_kill_switch')) {
      \Drupal::service('page_cache_kill_switch')->trigger();
    }
    $response = new ResourceResponse($this->formatSponsored($node));
    $response->addCacheableDependency(['#cache' => ['max-age' => 0]]);
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    return $response;
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
  protected function formatSponsored(Node $node) {
    // Variate from taxonomy
    $variant = '';
    if (!$node->get('field_variante')->isEmpty()) {
      $term = $node->get('field_variante')->entity;
      if ($term) {
        // Asumimos que los nombres son "banner" o "standard"
        $variant = strtolower($term->getName());
      }
    }

    // ID Formateado
    $formatted_id = 's' . $node->id();

    // Imagen
    $image_url = '';
    if (!$node->get('field_imagen')->isEmpty()) {
      $file = $node->get('field_imagen')->entity;
      if ($file) {
        $image_url = $file->createFileUrl(FALSE);
      }
    }
    
    // Altura (imgH)
    $imgH = !$node->get('field_altura')->isEmpty() ? (int) $node->get('field_altura')->value : 0;

    // Dependiendo de la variante, construimos un arreglo u otro.
    if ($variant === 'banner') {
      $clickUrl = '';
      if (!$node->get('field_url_cta')->isEmpty()) {
        $clickUrl = $node->get('field_url_cta')->value;
      } else {
        // En tu ejemplo clickUrl es la misma imagen
        $clickUrl = $image_url;
      }

      return [
        'id' => $formatted_id,
        'variant' => $variant,
        'image' => $image_url,
        'imgH' => $imgH,
        'clickUrl' => $clickUrl,
      ];
    }
    else {
      // Asumimos que si no es banner, es la estructura estándar ("standard")
      
      // Avatar Sponsor
      $sponsor_avatar = '';
      if (!$node->get('field_avatar_sponsor')->isEmpty()) {
        $file_avatar = $node->get('field_avatar_sponsor')->entity;
        if ($file_avatar) {
          $sponsor_avatar = $file_avatar->createFileUrl(FALSE);
        }
      }

      return [
        'id' => $formatted_id,
        'variant' => $variant ? $variant : 'standard',
        'title' => $node->getTitle(),
        'excerpt' => !$node->get('field_resumen')->isEmpty() ? $node->get('field_resumen')->value : '',
        'image' => $image_url,
        'imgH' => $imgH,
        'sponsorName' => !$node->get('field_nombre_sponsor')->isEmpty() ? $node->get('field_nombre_sponsor')->value : '',
        'sponsorAvatar' => $sponsor_avatar,
        'ctaText' => !$node->get('field_texto_cta')->isEmpty() ? $node->get('field_texto_cta')->value : '',
        'ctaUrl' => !$node->get('field_url_cta')->isEmpty() ? $node->get('field_url_cta')->value : '',
      ];
    }
  }
}
