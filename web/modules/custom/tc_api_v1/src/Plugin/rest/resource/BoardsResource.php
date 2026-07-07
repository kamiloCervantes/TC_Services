<?php

namespace Drupal\tc_api_v1\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;
use Drupal\taxonomy\Entity\Term;

/**
 * Provides a resource to get boards (news categories).
 *
 * @RestResource(
 *   id = "tc_api_v1_boards",
 *   label = @Translation("Boards API v1"),
 *   uri_paths = {
 *     "canonical" = "/api/v1/boards/{id}",
 *     "collection" = "/api/v1/boards"
 *   }
 * )
 */
class BoardsResource extends ResourceBase {

  /**
   * {@inheritdoc}
   */
  public function routes() {
    $collection = parent::routes();

    // Modificar la ruta GET canónica para que el parámetro {id} sea opcional.
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
   *   The ID of the board, if provided.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Throws exception when board not found.
   */
  public function get($id = NULL) {
    if ($id) {
      return $this->getBoardDetail($id);
    }
    
    // Fallback if no ID is provided, return all.
    return $this->getAllBoards();
  }

  /**
   * Retrieves all boards records.
   *
   * @return \Drupal\rest\ResourceResponse
   *   Response containing all boards.
   */
  protected function getAllBoards() {
    $entity_type_manager = \Drupal::entityTypeManager();
    $term_storage = $entity_type_manager->getStorage('taxonomy_term');
    
    $query = $term_storage->getQuery()
      ->condition('vid', 'categorias_noticias')
      ->accessCheck(TRUE);
      
    $tids = $query->execute();
    $terms = $term_storage->loadMultiple($tids);
    
    $data = [];
    foreach ($terms as $term) {
      $data[] = $this->formatBoard($term);
    }

    return new ResourceResponse(array_values($data));
  }

  /**
   * Retrieves the detail of a single board record.
   *
   * @param string|int $id
   *   The ID of the board.
   *
   * @return \Drupal\rest\ResourceResponse
   *   Response containing the single board.
   */
  protected function getBoardDetail($id) {
    $entity_type_manager = \Drupal::entityTypeManager();
    $term_storage = $entity_type_manager->getStorage('taxonomy_term');
    
    $term = $term_storage->load($id);
    
    if (!$term || $term->bundle() !== 'categorias_noticias') {
      throw new NotFoundHttpException('Board not found.');
    }

    return new ResourceResponse($this->formatBoard($term));
  }

  /**
   * Formats a taxonomy term entity into the requested JSON structure.
   *
   * @param \Drupal\taxonomy\Entity\Term $term
   *   The term entity to format.
   *
   * @return array
   *   Formatted array.
   */
  protected function formatBoard(Term $term) {
    $color = '';
    if ($term->hasField('field_color') && !$term->get('field_color')->isEmpty()) {
      $color = $term->get('field_color')->value;
    }

    $icon = '';
    if ($term->hasField('field_icono') && !$term->get('field_icono')->isEmpty()) {
      // Como no estoy seguro del tipo de campo, voy a comprobar si es de tipo imagen.
      // Si no es imagen, se toma como texto (Ejemplo: "P" para Política).
      $field_definition = $term->get('field_icono')->getFieldDefinition();
      if ($field_definition->getType() === 'image') {
        $file = $term->get('field_icono')->entity;
        if ($file) {
          $icon = $file->createFileUrl(FALSE);
        }
      } else {
        $icon = $term->get('field_icono')->value;
      }
    } else {
      // Si no hay icono seteado, usamos la primera letra del nombre como un fallback rápido
      $icon = mb_substr($term->getName(), 0, 1);
    }

    return [
      'id' => (int) $term->id(),
      'name' => $term->getName(),
      'color' => $color,
      'icon' => $icon,
    ];
  }
}
