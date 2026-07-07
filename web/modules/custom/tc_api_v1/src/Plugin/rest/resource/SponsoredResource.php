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
   * Retrieves all sponsored records.
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
      ->sort('field_posicion', 'ASC')
      ->accessCheck(TRUE);
      
    // Aquí se podrían agregar condiciones por fecha actual entre field_fecha_inicio y field_fecha_fin
    
    $nids = $query->execute();
    $nodes = $node_storage->loadMultiple($nids);
    
    $data = [];
    foreach ($nodes as $node) {
      $data[] = $this->formatSponsored($node);
    }

    // El resultado esperado era un arreglo, así que reseteamos los keys.
    return new ResourceResponse(array_values($data));
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

    return new ResourceResponse($this->formatSponsored($node));
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
