<?php

namespace Drupal\tc_news_sync\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Service for synchronizing news from external sources.
 */
class SyncService
{

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a new SyncService object.
   */
  public function __construct(ClientInterface $http_client, EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory, FileSystemInterface $file_system)
  {
    $this->httpClient = $http_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('tc_news_sync');
    $this->fileSystem = $file_system;
  }

  /**
   * Fetches the list of news from Monteria's website.
   *
   * @return array
   *   An array of news data containing title, image url, excerpt and detail url.
   */
  public function getMonteriaNewsList()
  {
    $url = 'https://www.monteria.gov.co/publicaciones/noticias/';
    $items = [];

    try {
      $response = $this->httpClient->request('GET', $url);
      $html = (string) $response->getBody();

      // Using DOMDocument and DOMXPath
      $dom = new \DOMDocument();
      @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
      $xpath = new \DOMXPath($dom);

      // XPath específico usando la clase '.contentPubTema' provista por el usuario.
      $nodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " contentPubTema ")]');

      // Fallback por si la estructura cambia levemente
      if ($nodes->length === 0) {
        $nodes = $xpath->query('//div[contains(@class, "card")]');
      }

      foreach ($nodes as $node) {
        // Encontrar enlace de detalle y título
        $linkNode = $xpath->query('.//h2[contains(@class, "title")]/a', $node)->item(0);

        // Fallback si no tiene el h2.title, buscar cualquier a con /publicaciones/
        if (!$linkNode) {
          $linkNode = $xpath->query('.//a[contains(@href, "/publicaciones/")]', $node)->item(0);
        }

        if (!$linkNode) {
          continue;
        }

        $detail_url = $linkNode->getAttribute('href');
        // Ensure absolute URL
        if (strpos($detail_url, 'http') !== 0) {
          $detail_url = 'https://www.monteria.gov.co' . $detail_url;
        }

        // Título
        $title = trim($linkNode->textContent);

        // Imagen
        $imgNode = $xpath->query('.//div[contains(@class, "contentImage")]//img', $node)->item(0);
        if (!$imgNode) {
          $imgNode = $xpath->query('.//img', $node)->item(0);
        }

        if (!$imgNode) {
          // Si no tiene imagen, la regla de negocio dice ignorar
          continue;
        }
        $img_url = $imgNode->getAttribute('src');
        if (strpos($img_url, 'http') !== 0) {
          $img_url = 'https://www.monteria.gov.co' . $img_url;
        }

        // Resumen
        $excerptNode = $xpath->query('.//div[contains(@class, "post-content")]//p', $node)->item(0);
        if (!$excerptNode) {
          $excerptNode = $xpath->query('.//p', $node)->item(0);
        }
        $excerpt = $excerptNode ? trim($excerptNode->textContent) : '';

        if (!empty($title) && !empty($detail_url) && !empty($img_url)) {
          $items[] = [
            'title' => $title,
            'image' => $img_url,
            'excerpt' => $excerpt,
            'url' => $detail_url,
          ];
        }
      }

    } catch (\Exception $e) {
      $this->logger->error('Error fetching Monteria news list: @message', ['@message' => $e->getMessage()]);
    }

    return $items;
  }

  /**
   * Processes a single news item (fetches detail, downloads image, creates node).
   *
   * @param array $item
   *   The news item data.
   *
   * @return bool|string
   *   True if created, False if error, String 'exists' if it was duplicate.
   */
  public function processMonteriaNewsItem(array $item)
  {
    // 1. Validar que la nota no haya sido ingresada previamente buscándola con el título
    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->condition('type', 'news')
      ->condition('title', $item['title'])
      ->accessCheck(FALSE);
    $existing = $query->execute();

    if (!empty($existing)) {
      return 'exists';
    }

    try {
      // 2. Acceder al detalle de la noticia para obtener el contenido completo
      $response = $this->httpClient->request('GET', $item['url']);
      $html = (string) $response->getBody();

      $dom = new \DOMDocument();
      @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
      $xpath = new \DOMXPath($dom);

      // Usar la clase '.pgel' para aislar el contenido principal de la noticia
      $contentNodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " pgel ")]');

      $content_html = '';
      $radicado_en = '';

      if ($contentNodes->length > 0) {
        $contentNode = $contentNodes->item(0);

        // Buscar el elemento strong que tiene la ciudad y fecha
        $strongNodes = $xpath->query('.//strong', $contentNode);
        foreach ($strongNodes as $strongNode) {
          $text = trim($strongNode->textContent);
          if (strpos($text, ',') !== false && strlen($text) < 150) {
            $parts = explode(',', $text);
            $radicado_en = trim($parts[0]); // Captura la ciudad antes de la coma
            // Eliminar el nodo strong del DOM para que no salga en field_contenido
            $strongNode->parentNode->removeChild($strongNode);
            break; // Detener después de procesar el primero
          }
        }

        $content_html = $dom->saveHTML($contentNode);
      } else {
        // Fallback genérico si no encuentra un contenedor claro
        $fallbackNodes = $xpath->query('//div[contains(@class, "col-sm-12")]//p');
        foreach ($fallbackNodes as $p) {
          $content_html .= $dom->saveHTML($p);
        }
      }

      if (empty($content_html)) {
        $content_html = '<p>' . $item['excerpt'] . '</p>'; // Si falla la extracción, usamos el resumen
      }

      // 3. Descargar y guardar la imagen
      $image_data = (string) $this->httpClient->request('GET', $item['image'])->getBody();
      $filename = basename(parse_url($item['image'], PHP_URL_PATH));

      // Limpiar filename
      $filename = preg_replace('/[^a-zA-Z0-9-_\.]/', '', $filename);
      $directory = 'public://news_images';

      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

      $file_uri = $directory . '/' . $filename;
      $file_uri = $this->fileSystem->saveData($image_data, $file_uri, FileSystemInterface::EXISTS_RENAME);

      $file = File::create([
        'uri' => $file_uri,
        'status' => 1,
      ]);
      $file->save();

      // 4. Crear el nodo de tipo news
      $node_data = [
        'type' => 'news',
        'title' => $item['title'],
        'field_resumen' => [
          'value' => $item['excerpt'],
          'format' => 'basic_html',
        ],
        'field_contenido' => [
          'value' => $content_html,
          'format' => 'full_html',
        ],
        'field_imagen_destacada' => [
          'target_id' => $file->id(),
          'alt' => 'Foto Alcaldía de Montería',
        ],
        'field_credito_imagen_destacada' => [
          'value' => 'Alcaldía de Montería',
        ],
        'status' => 1,
      ];

      if (!empty($radicado_en)) {
        $node_data['field_radicado_en'] = [
          'value' => $radicado_en,
        ];
      }

      $term_id = $this->getTaxonomyTermIdByName('Alcaldía de Montería');
      if ($term_id) {
        $node_data['field_fuente_de_informacion'] = ['target_id' => $term_id];
      }

      $node = $node_storage->create($node_data);
      $node->save();

      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('Error processing news item @title: @message', [
        '@title' => $item['title'],
        '@message' => $e->getMessage()
      ]);
      return FALSE;
    }
  }

  /**
   * Fetches the list of news from Gobernación de Córdoba's website.
   * Uses the same CMS structure as Montería.
   *
   * @return array
   *   An array of news data containing title, image url, excerpt and detail url.
   */
  public function getCordobaGobNewsList()
  {
    $url = 'https://www.cordoba.gov.co/publicaciones/noticias/?tema=5';
    $base_url = 'https://www.cordoba.gov.co';
    $items = [];

    try {
      $response = $this->httpClient->request('GET', $url);
      $html = (string) $response->getBody();

      $dom = new \DOMDocument();
      @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
      $xpath = new \DOMXPath($dom);

      // Mismo CMS que Montería: selector .contentPubTema
      $nodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " contentPubTema ")]');

      // Fallback
      if ($nodes->length === 0) {
        $nodes = $xpath->query('//div[contains(@class, "card")]');
      }

      foreach ($nodes as $node) {
        // Título y enlace
        $linkNode = $xpath->query('.//h2[contains(@class, "title")]/a', $node)->item(0);
        if (!$linkNode) {
          $linkNode = $xpath->query('.//a[contains(@href, "/publicaciones/")]', $node)->item(0);
        }

        if (!$linkNode) {
          continue;
        }

        $detail_url = $linkNode->getAttribute('href');
        if (strpos($detail_url, 'http') !== 0) {
          $detail_url = $base_url . $detail_url;
        }

        $title = trim($linkNode->textContent);

        // Imagen
        $imgNode = $xpath->query('.//div[contains(@class, "contentImage")]//img', $node)->item(0);
        if (!$imgNode) {
          $imgNode = $xpath->query('.//img', $node)->item(0);
        }
        if (!$imgNode) {
          continue;
        }

        $img_url = $imgNode->getAttribute('src');
        if (strpos($img_url, 'http') !== 0) {
          $img_url = $base_url . $img_url;
        }

        // Resumen
        $excerptNode = $xpath->query('.//div[contains(@class, "post-content")]//p', $node)->item(0);
        if (!$excerptNode) {
          $excerptNode = $xpath->query('.//p', $node)->item(0);
        }
        $excerpt = $excerptNode ? trim($excerptNode->textContent) : '';

        if (!empty($title) && !empty($detail_url) && !empty($img_url)) {
          $items[] = [
            'title' => $title,
            'image' => $img_url,
            'excerpt' => $excerpt,
            'url' => $detail_url,
          ];
        }
      }
    } catch (\Exception $e) {
      $this->logger->error('Error fetching Gobernación Córdoba news list: @message', ['@message' => $e->getMessage()]);
    }

    return $items;
  }

  /**
   * Processes a single news item from Gobernación de Córdoba.
   * Uses the same CMS structure (.pgel, .contentPubTema) as Montería.
   *
   * @param array $item
   *   The news item data.
   *
   * @return bool|string
   *   True if created, False if error, 'exists' if duplicate.
   */
  public function processCordobaGobNewsItem(array $item)
  {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->condition('type', 'news')
      ->condition('title', $item['title'])
      ->accessCheck(FALSE);
    $existing = $query->execute();

    if (!empty($existing)) {
      return 'exists';
    }

    try {
      $response = $this->httpClient->request('GET', $item['url']);
      $html = (string) $response->getBody();

      $dom = new \DOMDocument();
      @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
      $xpath = new \DOMXPath($dom);

      // Mismo CMS que Montería: .pgel para el cuerpo
      $contentNodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " pgel ")]');

      $content_html = '';
      $radicado_en = '';

      if ($contentNodes->length > 0) {
        $contentNode = $contentNodes->item(0);

        // Extraer ciudad y fecha del primer nodo de texto útil
        // Formato esperado: "Montería, 3 de julio de 2026 —"
        $textNodes = $xpath->query('.//text()', $contentNode);
        foreach ($textNodes as $textNode) {
          $text = $textNode->nodeValue;
          $trimmed = trim(str_replace("\xc2\xa0", ' ', $text));
          if (!empty($trimmed)) {
            if (preg_match('/^([^,]+),\s+\d{1,2}\s+de\s+[a-zA-Z]+\s+de\s+\d{4}[\s—\-\–\.]*(.*)/su', $trimmed, $matches)) {
              $radicado_en = trim($matches[1]);
              $textNode->nodeValue = $matches[2];
            }
            break;
          }
        }

        $content_html = $dom->saveHTML($contentNode);
      } else {
        $fallbackNodes = $xpath->query('//div[contains(@class, "col-sm-12")]//p');
        foreach ($fallbackNodes as $p) {
          $content_html .= $dom->saveHTML($p);
        }
      }

      if (empty($content_html)) {
        $content_html = '<p>' . $item['excerpt'] . '</p>';
      }

      // Descargar y guardar la imagen
      $image_data = (string) $this->httpClient->request('GET', $item['image'])->getBody();
      $filename = basename(parse_url($item['image'], PHP_URL_PATH));
      $filename = preg_replace('/[^a-zA-Z0-9-_\.]/', '', $filename);
      $directory = 'public://news_images';

      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

      $file_uri = $directory . '/' . $filename;
      $file_uri = $this->fileSystem->saveData($image_data, $file_uri, FileSystemInterface::EXISTS_RENAME);

      $file = File::create([
        'uri' => $file_uri,
        'status' => 1,
      ]);
      $file->save();

      // Crear el nodo de tipo news
      $node_data = [
        'type' => 'news',
        'title' => $item['title'],
        'field_resumen' => [
          'value' => $item['excerpt'],
          'format' => 'basic_html',
        ],
        'field_contenido' => [
          'value' => $content_html,
          'format' => 'full_html',
        ],
        'field_imagen_destacada' => [
          'target_id' => $file->id(),
          'alt' => 'Foto Gobernación de Córdoba',
        ],
        'field_credito_imagen_destacada' => [
          'value' => 'Gobernación de Córdoba',
        ],
        'status' => 1,
      ];

      if (!empty($radicado_en)) {
        $node_data['field_radicado_en'] = ['value' => $radicado_en];
      }

      $term_id = $this->getTaxonomyTermIdByName('Gobernación de Córdoba');
      if ($term_id) {
        $node_data['field_fuente_de_informacion'] = ['target_id' => $term_id];
      }

      $node = $node_storage->create($node_data);
      $node->save();

      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('Error processing Gobernación Córdoba news item @title: @message', [
        '@title' => $item['title'],
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Fetches the list of news from Urra's website.

   *
   * @return array
   *   An array of news data containing title, image url, excerpt and detail url.
   */
  public function getUrraNewsList()
  {
    $url = 'https://urra.com.co/';
    $items = [];

    try {
      $response = $this->httpClient->request('GET', $url);
      $html = (string) $response->getBody();

      $dom = new \DOMDocument();
      @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
      $xpath = new \DOMXPath($dom);

      $nodes = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " stm_news ")]//ul/li');

      foreach ($nodes as $node) {
        $linkNode = $xpath->query('.//div[contains(@class, "stm_news_unit-block")]//h5/a', $node)->item(0);
        if (!$linkNode) {
          continue;
        }

        $detail_url = $linkNode->getAttribute('href');
        $title = trim($linkNode->textContent);

        $imgNode = $xpath->query('.//div[contains(@class, "image")]//img', $node)->item(0);
        if (!$imgNode) {
          continue;
        }
        $img_url = $imgNode->getAttribute('src');
        if (strpos($img_url, 'http') !== 0) {
          $img_url = 'https://urra.com.co' . $img_url;
        }

        $excerptNode = $xpath->query('.//div[contains(@class, "stm_the_excerpt")]//p', $node)->item(0);
        $excerpt = $excerptNode ? trim($excerptNode->textContent) : '';

        if (!empty($title) && !empty($detail_url) && !empty($img_url)) {
          $items[] = [
            'title' => $title,
            'image' => $img_url,
            'excerpt' => $excerpt,
            'url' => $detail_url,
          ];
        }
      }
    } catch (\Exception $e) {
      $this->logger->error('Error fetching Urra news list: @message', ['@message' => $e->getMessage()]);
    }

    return $items;
  }

  /**
   * Processes a single news item from Urra (fetches detail, downloads image, creates node).
   *
   * @param array $item
   *   The news item data.
   *
   * @return bool|string
   *   True if created, False if error, String 'exists' if it was duplicate.
   */
  public function processUrraNewsItem(array $item)
  {
    // 1. Validar que la nota no haya sido ingresada previamente
    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->condition('type', 'news')
      ->condition('title', $item['title'])
      ->accessCheck(FALSE);
    $existing = $query->execute();

    if (!empty($existing)) {
      return 'exists';
    }

    try {
      // 2. Acceder al detalle de la noticia
      $response = $this->httpClient->request('GET', $item['url']);
      $html = (string) $response->getBody();

      $dom = new \DOMDocument();
      @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
      $xpath = new \DOMXPath($dom);

      // Extraer solo el texto del elemento con clase .wpb_text_column
      $contentNodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " wpb_text_column ")]');

      $content_html = '';
      $radicado_en = '';

      if ($contentNodes->length > 0) {
        foreach ($contentNodes as $contentNode) {
          // Buscar el elemento strong que tiene la ciudad y fecha
          $strongNodes = $xpath->query('.//strong', $contentNode);
          foreach ($strongNodes as $strongNode) {
            $text = trim($strongNode->textContent);
            // Si tiene coma y es lo suficientemente corto para ser ciudad, fecha
            if (empty($radicado_en) && strpos($text, ',') !== false && strlen($text) < 150) {
              $parts = explode(',', $text);
              $radicado_en = trim($parts[0]); // Captura "Tierralta" o similar

              // Eliminar el nodo strong para que no aparezca
              $strongNode->parentNode->removeChild($strongNode);
            }
          }

          $content_html .= $dom->saveHTML($contentNode);
        }
      } else {
        // Fallback genérico
        $fallbackNodes = $xpath->query('//article//p');
        foreach ($fallbackNodes as $p) {
          $content_html .= $dom->saveHTML($p);
        }
      }

      if (empty($content_html)) {
        $content_html = '<p>' . $item['excerpt'] . '</p>';
      }

      // Limpiar el resumen: eliminar el patrón "Ciudad, fecha. " del inicio
      $excerpt = $item['excerpt'];
      // Normalizar espacios no separables (&nbsp;) a espacios normales para facilitar el match
      $excerpt_normalized = str_replace("\xc2\xa0", ' ', $excerpt);
      // Elimina texto tipo: "Tierralta, 2 de julio de 2026. " al inicio
      if (preg_match('/^([^,]+),\s+[^.]+\.\s+(.*)/su', $excerpt_normalized, $matches)) {
        // Si radicado_en aún no fue capturado desde el contenido detalle, tomarlo del excerpt
        if (empty($radicado_en)) {
          $radicado_en = trim($matches[1]);
        }
        $excerpt = trim($matches[2]);
      }

      // 3. Descargar y guardar la imagen
      $image_data = (string) $this->httpClient->request('GET', $item['image'])->getBody();
      $filename = basename(parse_url($item['image'], PHP_URL_PATH));
      $filename = preg_replace('/[^a-zA-Z0-9-_\.]/', '', $filename);
      $directory = 'public://news_images';

      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

      $file_uri = $directory . '/' . $filename;
      $file_uri = $this->fileSystem->saveData($image_data, $file_uri, FileSystemInterface::EXISTS_RENAME);

      $file = File::create([
        'uri' => $file_uri,
        'status' => 1,
      ]);
      $file->save();

      // 4. Crear el nodo de tipo news
      $node_data = [
        'type' => 'news',
        'title' => $item['title'],
        'field_resumen' => [
          'value' => $excerpt,
          'format' => 'basic_html',
        ],
        'field_contenido' => [
          'value' => $content_html,
          'format' => 'full_html',
        ],
        'field_imagen_destacada' => [
          'target_id' => $file->id(),
          'alt' => 'Foto URRÁ',
        ],
        'field_credito_imagen_destacada' => [
          'value' => 'URRÁ S.A. E.S.P.',
        ],
        'status' => 1,
      ];

      if (!empty($radicado_en)) {
        $node_data['field_radicado_en'] = ['value' => $radicado_en];
      }

      $term_id = $this->getTaxonomyTermIdByName('Urrá');
      if ($term_id) {
        $node_data['field_fuente_de_informacion'] = ['target_id' => $term_id];
      }

      $node = $node_storage->create($node_data);
      $node->save();

      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('Error processing Urra news item @title: @message', [
        '@title' => $item['title'],
        '@message' => $e->getMessage()
      ]);
      return FALSE;
    }
  }

  /**
   * Fetches the list of news from Unicordoba's website.
   *
   * @return array
   *   An array of news data containing title, image url, and detail url.
   */
  public function getUnicordobaNewsList()
  {
    $url = 'https://unicordoba.edu.co/noticias-historial/';
    $items = [];

    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 (compatible; DrupalBot/1.0)',
        ],
      ]);
      $html = (string) $response->getBody();

      $dom = new \DOMDocument();
      @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
      $xpath = new \DOMXPath($dom);

      // Avada Post Cards: cada tarjeta es un artículo con clase awb-post-card
      $nodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " awb-post-card ")]');

      // Fallback: artículos dentro del contenido principal (WordPress genérico)
      if ($nodes->length === 0) {
        $nodes = $xpath->query('//article');
      }

      foreach ($nodes as $node) {
        // Título y URL de detalle
        $titleNode = $xpath->query('.//*[contains(@class, "awb-post-card__title")]//a', $node)->item(0);
        if (!$titleNode) {
          $titleNode = $xpath->query('.//h2//a | .//h3//a', $node)->item(0);
        }

        if (!$titleNode) {
          continue;
        }

        $detail_url = $titleNode->getAttribute('href');
        $title = trim($titleNode->textContent);

        if (strpos($detail_url, 'http') !== 0) {
          $detail_url = 'https://unicordoba.edu.co' . $detail_url;
        }

        // Imagen
        $imgNode = $xpath->query('.//*[contains(@class, "awb-post-card__image")]//img | .//img', $node)->item(0);
        if (!$imgNode) {
          continue; // Solo noticias con imagen
        }

        // Priorizar data-src (lazy load) sobre src
        $img_url = $imgNode->getAttribute('data-src');
        if (empty($img_url)) {
          $img_url = $imgNode->getAttribute('src');
        }

        if (empty($img_url) || strpos($img_url, 'data:image') === 0) {
          continue; // Imagen placeholder SVG, omitir
        }

        if (strpos($img_url, 'http') !== 0) {
          $img_url = 'https://unicordoba.edu.co' . $img_url;
        }

        // Resumen / excerpt
        $excerptNode = $xpath->query('.//*[contains(@class, "awb-post-card__excerpt")]//p | .//p', $node)->item(0);
        $excerpt = $excerptNode ? trim($excerptNode->textContent) : '';

        if (!empty($title) && !empty($detail_url)) {
          $items[] = [
            'title' => $title,
            'image' => $img_url,
            'excerpt' => $excerpt,
            'url' => $detail_url,
          ];
        }
      }
    } catch (\Exception $e) {
      $this->logger->error('Error fetching Unicordoba news list: @message', ['@message' => $e->getMessage()]);
    }

    return $items;
  }

  /**
   * Processes a single news item from Unicordoba.
   *
   * @param array $item
   *   The news item data.
   *
   * @return bool|string
   *   True if created, 'exists' if duplicate, False on error.
   */
  public function processUnicordobaNewsItem(array $item)
  {
    // 1. Validar que la nota no haya sido ingresada previamente
    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->condition('type', 'news')
      ->condition('title', $item['title'])
      ->accessCheck(FALSE);
    $existing = $query->execute();

    if (!empty($existing)) {
      return 'exists';
    }

    try {
      // 2. Acceder al detalle de la noticia
      $response = $this->httpClient->request('GET', $item['url'], [
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 (compatible; DrupalBot/1.0)',
        ],
      ]);
      $html = (string) $response->getBody();

      $dom = new \DOMDocument();
      @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
      $xpath = new \DOMXPath($dom);

      // Unicordoba (Avada): el contenido está en .fusion-content-tb.fusion-content-tb-1
      $contentNodes = $xpath->query(
        '//*[contains(concat(" ", normalize-space(@class), " "), " fusion-content-tb ") and ' .
        'contains(concat(" ", normalize-space(@class), " "), " fusion-content-tb-1 ")]'
      );

      $content_html = '';
      $radicado_en = '';

      if ($contentNodes->length > 0) {
        $contentNode = $contentNodes->item(0);

        // Buscar el elemento strong con ciudad y fecha al inicio
        $strongNodes = $xpath->query('.//strong | .//b', $contentNode);
        foreach ($strongNodes as $strongNode) {
          $text = trim(str_replace("\xc2\xa0", ' ', $strongNode->textContent));
          if (empty($radicado_en) && strpos($text, ',') !== false && strlen($text) < 150) {
            $parts = explode(',', $text);
            $radicado_en = trim($parts[0]);
            $strongNode->parentNode->removeChild($strongNode);
            break;
          }
        }

        $content_html = $dom->saveHTML($contentNode);
      } else {
        // Fallback a clases genéricas de WordPress
        $fallbackNodes = $xpath->query(
          '//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")] | ' .
          '//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]'
        );
        if ($fallbackNodes->length > 0) {
          $content_html = $dom->saveHTML($fallbackNodes->item(0));
        }
      }

      if (empty($content_html)) {
        $content_html = '<p>' . $item['excerpt'] . '</p>';
      }

      // Limpiar etiquetas HTML del contenido, conservar solo etiquetas <p>
      $content_html = strip_tags($content_html, '<p>');
      // Normalizar espacios y &nbsp;
      $content_html = str_replace("\xc2\xa0", ' ', $content_html);
      $content_html = preg_replace('/\s{2,}/', ' ', trim($content_html));

      // Derivar el resumen desde el primer párrafo del contenido del detalle
      // ya limpio (sin el strong de ciudad/fecha), en lugar del listado
      $excerpt = '';
      if (!empty($content_html)) {
        $excerptDom = new \DOMDocument();
        @$excerptDom->loadHTML(mb_convert_encoding($content_html, 'HTML-ENTITIES', 'UTF-8'));
        $excerptXpath = new \DOMXPath($excerptDom);
        $firstP = $excerptXpath->query('//p')->item(0);
        if ($firstP) {
          $rawText = str_replace("\xc2\xa0", ' ', trim($firstP->textContent));
          // Si aún empieza con "Ciudad, fecha." limpiarlo
          if (preg_match('/^([^,]+),\s+[^.]+\.\s+(.*)/su', $rawText, $matches)) {
            if (empty($radicado_en)) {
              $radicado_en = trim($matches[1]);
            }
            $excerpt = trim($matches[2]);
          } else {
            $excerpt = $rawText;
          }
          // Truncar a ~300 caracteres para el resumen
          if (mb_strlen($excerpt) > 300) {
            $excerpt = mb_substr($excerpt, 0, 297) . '...';
          }
        }
      }

      // Si después de todo el excerpt sigue vacío, usar el del listado como fallback
      if (empty($excerpt) && !empty($item['excerpt'])) {
        $excerpt = str_replace("\xc2\xa0", ' ', $item['excerpt']);
        if (preg_match('/^([^,]+),\s+[^.]+\.\s+(.*)/su', $excerpt, $matches)) {
          if (empty($radicado_en)) {
            $radicado_en = trim($matches[1]);
          }
          $excerpt = trim($matches[2]);
        }
      }

      // 3. Descargar y guardar la imagen
      $image_data = (string) $this->httpClient->request('GET', $item['image'])->getBody();
      $filename = basename(parse_url($item['image'], PHP_URL_PATH));
      $filename = preg_replace('/[^a-zA-Z0-9-_\.]/', '', $filename);
      $directory = 'public://news_images';

      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

      $file_uri = $directory . '/' . $filename;
      $file_uri = $this->fileSystem->saveData($image_data, $file_uri, FileSystemInterface::EXISTS_RENAME);

      $file = File::create([
        'uri' => $file_uri,
        'status' => 1,
      ]);
      $file->save();

      // 4. Crear el nodo de tipo news
      $node_data = [
        'type' => 'news',
        'title' => $item['title'],
        'field_resumen' => [
          'value' => $excerpt,
          'format' => 'basic_html',
        ],
        'field_contenido' => [
          'value' => $content_html,
          'format' => 'full_html',
        ],
        'field_imagen_destacada' => [
          'target_id' => $file->id(),
          'alt' => 'Foto Unicórdoba',
        ],
        'field_credito_imagen_destacada' => [
          'value' => 'Universidad de Córdoba',
        ],
        'status' => 1,
      ];

      if (!empty($radicado_en)) {
        $node_data['field_radicado_en'] = ['value' => $radicado_en];
      }

      $term_id = $this->getTaxonomyTermIdByName('Unicordoba');
      if ($term_id) {
        $node_data['field_fuente_de_informacion'] = ['target_id' => $term_id];
      }

      $node = $node_storage->create($node_data);
      $node->save();

      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('Error processing Unicordoba news item @title: @message', [
        '@title' => $item['title'],
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Fetches the list of news from EPM's website.
   *
   * @return array
   *   An array of news data containing title and detail url.
   */
  public function getEpmNewsList()
  {
    $url = 'https://www.epm.com.co/institucional/sala-de-prensa/noticias-y-novedades/';
    $items = [];

    try {
      $this->logger->info('EPM Sync: Iniciando carga de lista desde @url', ['@url' => $url]);
      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 (compatible; DrupalBot/1.0)',
        ],
      ]);
      $html = (string) $response->getBody();

      if (!empty($html)) {
        // Limitamos el log a un extracto para no saturar los registros.
        $this->logger->info('EPM Sync: HTML obtenido exitosamente. Extracto: @html', [
          '@html' => mb_substr($html, 0, 1000) . '...'
        ]);
      } else {
        $this->logger->warning('EPM Sync: El HTML obtenido está vacío o es nulo.');
      }

      $dom = new \DOMDocument();
      @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
      $xpath = new \DOMXPath($dom);

      // Buscar artículos EPM en la página de listado.
      // El contenedor puede estar dentro de un wrapper de listado o directamente
      // como articles con el modelo de noticias.
      $nodes = $xpath->query('//article[@data-cmp-contentfragment-model="epm/models/noticias"]');

      // Fallback: buscar dentro del contenedor principal de noticias
      if ($nodes->length === 0) {
        $nodes = $xpath->query('//*[@id="news-institucional"]//article[@data-cmp-contentfragment-model="epm/models/noticias"]');
      }

      $this->logger->info('EPM Sync: Nodos de noticias encontrados: @count', ['@count' => $nodes->length]);

      $limit = 18; // Páginas 1, 2 y 3 (a 6 ítems por página)
      $count = 0;

      foreach ($nodes as $node) {
        if ($count >= $limit) {
          break;
        }

        // Título: h3.cmp-contentfragment__title dentro del article
        $titleNode = $xpath->query('.//h3[contains(@class, "cmp-contentfragment__title")]', $node)->item(0);

        // URL de detalle: atributo data-cmp-contentfragment-path del article (ruta interna)
        // o en el enlace de redireccionamiento si existe
        $detail_url = '';
        $linkNode = $xpath->query('.//a[contains(@class, "cmp-contentfragment-pageredirect")]', $node)->item(0);
        if ($linkNode) {
          $detail_url = $linkNode->getAttribute('href');
        } else {
          // Intentar construir URL desde el atributo data-cmp-contentfragment-path
          // Ejemplo: /content/dam/epm/.../slug => URL pública no mapeada directamente,
          // por lo que se busca cualquier <a> dentro del article
          $anyLink = $xpath->query('.//a[@href]', $node)->item(0);
          if ($anyLink) {
            $detail_url = $anyLink->getAttribute('href');
          }
        }

        if (!$titleNode) {
          continue;
        }

        $title = trim($titleNode->textContent);

        if (strpos($detail_url, 'http') !== 0 && !empty($detail_url)) {
          $detail_url = 'https://www.epm.com.co' . $detail_url;
        }

        if (!empty($title) && !empty($detail_url)) {
          $this->logger->info('EPM Sync: Ítem extraído: @title (@url)', ['@title' => $title, '@url' => $detail_url]);
          $items[] = [
            'title' => $title,
            'url' => $detail_url,
          ];
          $count++;
        }
      }
    } catch (\Exception $e) {
      $this->logger->error('Error fetching EPM news list: @message', ['@message' => $e->getMessage()]);
    }

    return $items;
  }

  /**
   * Processes a single news item from EPM.
   *
   * @param array $item
   *   The news item data.
   *
   * @return bool|string
   *   True if created, 'exists' if duplicate, False on error.
   */
  public function processEpmNewsItem(array $item)
  {
    $this->logger->info('EPM Sync: Procesando detalle de: @title', ['@title' => $item['title']]);

    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->condition('type', 'news')
      ->condition('title', $item['title'])
      ->accessCheck(FALSE);
    $existing = $query->execute();

    if (!empty($existing)) {
      $this->logger->info('EPM Sync: La noticia "@title" ya existe. Omitiendo.', ['@title' => $item['title']]);
      return 'exists';
    }

    try {
      $response = $this->httpClient->request('GET', $item['url'], [
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 (compatible; DrupalBot/1.0)',
        ],
      ]);
      $html = (string) $response->getBody();

      $dom = new \DOMDocument();
      @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
      $xpath = new \DOMXPath($dom);

      // El contenido de la noticia EPM reside en el article con el modelo AEM.
      // Estrategia primaria: leer el atributo data-cmp-data-layer del article,
      // que contiene JSON con el HTML completo y no sufre truncamiento por
      // renderizado parcial del CMS.
      $articleNode = $xpath->query('//article[@data-cmp-contentfragment-model="epm/models/noticias"]')->item(0);

      $content_html = '';
      $alt_imagen = 'Foto EPM';
      $img_url = '';
      $radicado_en = '';

      if ($articleNode) {
        $this->logger->info('EPM Sync: Article de noticia encontrado en la página de detalle.');

        $dataLayer = $articleNode->getAttribute('data-cmp-data-layer');
        $extractedFromJson = false;

        if (!empty($dataLayer)) {
          // El atributo está HTML-encoded; html_entity_decode lo convierte a JSON válido.
          $dataLayerJson = html_entity_decode($dataLayer, ENT_QUOTES | ENT_HTML5, 'UTF-8');
          $dataLayerObj = json_decode($dataLayerJson, TRUE);

          if (json_last_error() === JSON_ERROR_NONE && is_array($dataLayerObj)) {
            // La clave raíz es dinámica (ID del componente), tomamos el primer valor.
            $fragmentData = reset($dataLayerObj);
            $elements = $fragmentData['elements'] ?? [];

            $rawContentHtml = '';
            $rawAltImagen = '';

            foreach ($elements as $element) {
              $title = $element['xdm:title'] ?? '';
              $text  = $element['xdm:text']  ?? '';
              if ($title === 'Contenido') {
                $rawContentHtml = $text;
              } elseif ($title === 'Alt imagen') {
                $rawAltImagen = $text;
              }
            }

            if (!empty($rawContentHtml)) {
              $this->logger->info('EPM Sync: Contenido extraído desde data-cmp-data-layer (JSON completo).');

              if (!empty($rawAltImagen)) {
                $alt_imagen = trim($rawAltImagen);
              }

              // Parsear el HTML extraído del JSON para limpiar nodos no deseados.
              $contentDom = new \DOMDocument();
              @$contentDom->loadHTML(
                '<meta charset="UTF-8">' .
                mb_convert_encoding($rawContentHtml, 'HTML-ENTITIES', 'UTF-8')
              );
              $contentXpath = new \DOMXPath($contentDom);

              // Seleccionar el body como contexto de trabajo.
              $bodyNode = $contentXpath->query('//body')->item(0);
              if (!$bodyNode) {
                $bodyNode = $contentDom->documentElement;
              }

              // 1. Eliminar el primer <p> con imagen de portada.
              $firstImgP = $contentXpath->query('.//p[.//img]', $bodyNode)->item(0);
              if ($firstImgP) {
                // Guardar src de la imagen antes de eliminarla.
                $imgTag = $contentXpath->query('.//img', $firstImgP)->item(0);
                if ($imgTag) {
                  $img_url = $imgTag->getAttribute('src');
                }
                $firstImgP->parentNode->removeChild($firstImgP);
              }

              // 2. Extraer ciudad y limpiar el prefijo de cabecera EPM del primer <p> que lo contenga.
              //    Formato: "Medellín, viernes 17 de julio de 2026 (@EPMestamosahi) | Texto real..."
              //    Se conserva el texto que viene después del "|".
              $pNodes = $contentXpath->query('.//p', $bodyNode);
              foreach ($pNodes as $pNode) {
                $text = trim(str_replace("\xc2\xa0", ' ', $pNode->textContent));
                if (preg_match('/@EPM\w*/i', $text) && strpos($text, '|') !== false) {
                  // Capturar ciudad (antes de la primera coma).
                  $commaPos = strpos($text, ',');
                  if ($commaPos !== false) {
                    $radicado_en = trim(substr($text, 0, $commaPos));
                  }
                  // Conservar el texto después del "|".
                  $pipePos = strpos($text, '|');
                  $afterPipe = trim(substr($text, $pipePos + 1));
                  // Vaciar los hijos del <p> y reemplazar con el texto restante.
                  while ($pNode->firstChild) {
                    $pNode->removeChild($pNode->firstChild);
                  }
                  if (!empty($afterPipe)) {
                    $pNode->appendChild($contentDom->createTextNode($afterPipe));
                  } else {
                    // Si no queda texto, eliminar el párrafo completo.
                    $pNode->parentNode->removeChild($pNode);
                  }
                  break;
                }
              }

              // 3. Eliminar todos los elementos <ul> del contenido.
              $ulNodes = $contentXpath->query('.//ul', $bodyNode);
              foreach ($ulNodes as $ulNode) {
                $ulNode->parentNode->removeChild($ulNode);
              }

              // Serializar el innerHTML limpio del body.
              $innerHtml = '';
              foreach ($bodyNode->childNodes as $child) {
                $innerHtml .= $contentDom->saveHTML($child);
              }
              $content_html = $innerHtml;
              $extractedFromJson = true;
            }
          }

          if (!$extractedFromJson) {
            $this->logger->warning('EPM Sync: No se pudo parsear data-cmp-data-layer, usando DOM como fallback.');
          }
        }

        // --- Fallback DOM si el JSON no entregó contenido ---
        if (!$extractedFromJson) {
          $contentDd = $xpath->query(
            './/div[contains(@class, "cmp-contentfragment__element--content")]//dd[contains(@class, "cmp-contentfragment__element-value")]',
            $articleNode
          )->item(0);

          if ($contentDd) {
            // 1. Eliminar el primer <p> con imagen de portada.
            $firstImgP = $xpath->query('./p[.//img]', $contentDd)->item(0);
            if ($firstImgP) {
              $imgTag = $xpath->query('.//img', $firstImgP)->item(0);
              if ($imgTag) {
                $img_url = $imgTag->getAttribute('src');
              }
              $firstImgP->parentNode->removeChild($firstImgP);
            }

            // 2. Extraer ciudad y limpiar el prefijo de cabecera EPM del primer <p> que lo contenga.
            //    Se conserva el texto que viene después del "|".
            $pNodes = $xpath->query('./p | .//p', $contentDd);
            foreach ($pNodes as $pNode) {
              $text = trim(str_replace("\xc2\xa0", ' ', $pNode->textContent));
              if (preg_match('/@EPM\w*/i', $text) && strpos($text, '|') !== false) {
                $commaPos = strpos($text, ',');
                if ($commaPos !== false) {
                  $radicado_en = trim(substr($text, 0, $commaPos));
                }
                // Conservar el texto después del "|".
                $pipePos = strpos($text, '|');
                $afterPipe = trim(substr($text, $pipePos + 1));
                while ($pNode->firstChild) {
                  $pNode->removeChild($pNode->firstChild);
                }
                if (!empty($afterPipe)) {
                  $pNode->appendChild($dom->createTextNode($afterPipe));
                } else {
                  $pNode->parentNode->removeChild($pNode);
                }
                break;
              }
            }

            // 3. Eliminar todos los elementos <ul> del contenido.
            $ulNodes = $xpath->query('.//ul', $contentDd);
            foreach ($ulNodes as $ulNode) {
              $ulNode->parentNode->removeChild($ulNode);
            }

            $innerHtml = '';
            foreach ($contentDd->childNodes as $child) {
              $innerHtml .= $dom->saveHTML($child);
            }
            $content_html = $innerHtml;
            $this->logger->info('EPM Sync: Contenido extraído desde DOM (fallback).');
          } else {
            // Fallback genérico.
            $fallbackNodes = $xpath->query('.//dd[contains(@class, "cmp-contentfragment__element-value")]', $articleNode);
            foreach ($fallbackNodes as $dd) {
              foreach ($dd->childNodes as $child) {
                $content_html .= $dom->saveHTML($child);
              }
            }
          }

          // Imagen desde DOM si no fue capturada por JSON.
          if (empty($img_url)) {
            $imgNodeInContent = $xpath->query(
              './/div[contains(@class, "cmp-contentfragment__element--content")]//img',
              $articleNode
            )->item(0);
            if ($imgNodeInContent) {
              $img_url = $imgNodeInContent->getAttribute('src');
            } else {
              $imgNodeFallback = $xpath->query('.//img', $articleNode)->item(0);
              if ($imgNodeFallback) {
                $img_url = $imgNodeFallback->getAttribute('src');
              }
            }
          }

          // Alt imagen desde DOM.
          $altDd = $xpath->query(
            './/div[contains(@class, "cmp-contentfragment__element--altImagen")]//dd[contains(@class, "cmp-contentfragment__element-value")]',
            $articleNode
          )->item(0);
          if ($altDd) {
            $altText = trim($altDd->textContent);
            if (!empty($altText)) {
              $alt_imagen = $altText;
            }
          }
        }
      } else {
        // Fallback si no se encuentra el article con el modelo EPM
        $this->logger->info('EPM Sync: Article de EPM no encontrado, usando fallback genérico.');
        $fallbackNodes = $xpath->query('//main//p | //article//p | //div[contains(@class, "content")]//p');
        foreach ($fallbackNodes as $p) {
          $content_html .= $dom->saveHTML($p);
        }
        $imgNodeFallback = $xpath->query('//main//img | //article//img')->item(0);
        if ($imgNodeFallback) {
          $img_url = $imgNodeFallback->getAttribute('src');
        }
      }

      // Construir URL absoluta de la imagen
      if (!empty($img_url) && strpos($img_url, 'http') !== 0) {
        $img_url = 'https://www.epm.com.co' . $img_url;
      }

      // Extraer resumen desde el primer párrafo del contenido
      $excerpt = '';
      if (!empty($content_html)) {
        $excerptDom = new \DOMDocument();
        @$excerptDom->loadHTML(mb_convert_encoding($content_html, 'HTML-ENTITIES', 'UTF-8'));
        $excerptXpath = new \DOMXPath($excerptDom);
        // Buscar el primer párrafo que tenga texto real (ignorar párrafos solo con imágenes)
        $paragraphs = $excerptXpath->query('//p');
        foreach ($paragraphs as $p) {
          $text = trim($p->textContent);
          if (!empty($text)) {
            $excerpt = $text;
            break;
          }
        }
        if (mb_strlen($excerpt) > 300) {
          $excerpt = mb_substr($excerpt, 0, 297) . '...';
        }
      }

      // Descargar y guardar la imagen si existe
      $file = null;
      if (!empty($img_url)) {
        try {
          $image_data = (string) $this->httpClient->request('GET', $img_url)->getBody();
          $filename = basename(parse_url($img_url, PHP_URL_PATH));
          $filename = preg_replace('/[^a-zA-Z0-9-_\.]/', '', $filename);
          if (!empty($filename)) {
            $directory = 'public://news_images';
            $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
            $file_uri = $directory . '/' . $filename;
            $file_uri = $this->fileSystem->saveData($image_data, $file_uri, FileSystemInterface::EXISTS_RENAME);

            $file = File::create([
              'uri' => $file_uri,
              'status' => 1,
            ]);
            $file->save();
            $this->logger->info('EPM Sync: Imagen guardada: @uri', ['@uri' => $file_uri]);
          }
        } catch (\Exception $imgEx) {
          $this->logger->warning('EPM Sync: No se pudo descargar la imagen @url: @msg', [
            '@url' => $img_url,
            '@msg' => $imgEx->getMessage(),
          ]);
        }
      }

      $node_data = [
        'type' => 'news',
        'title' => $item['title'],
        'field_resumen' => [
          'value' => $excerpt,
          'format' => 'basic_html',
        ],
        'field_contenido' => [
          'value' => $content_html,
          'format' => 'full_html',
        ],
        'status' => 0,
      ];

      if ($file) {
        $node_data['field_imagen_destacada'] = [
          'target_id' => $file->id(),
          'alt' => $alt_imagen,
        ];
        $node_data['field_credito_imagen_destacada'] = [
          'value' => 'EPM',
        ];
      }

      if (!empty($radicado_en)) {
        $node_data['field_radicado_en'] = ['value' => $radicado_en];
      }

      $term_id = $this->getTaxonomyTermIdByName('EPM');
      if ($term_id) {
        $node_data['field_fuente_de_informacion'] = ['target_id' => $term_id];
      }

      $node = $node_storage->create($node_data);
      $node->save();

      $this->logger->info('EPM Sync: Nodo de noticia "@title" creado exitosamente (borrador).', ['@title' => $item['title']]);

      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('Error processing EPM news item @title: @message', [
        '@title' => $item['title'],
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Fetches the list of news from Corantioquia's website.
   *
   * @return array
   *   An array of news data containing title, image url, excerpt and detail url.
   */
  public function getCorantioquiaNewsList()
  {
    $url = 'https://www.corantioquia.gov.co/?s=caucasia,%20bajo%20cauca';
    $items = [];

    try {
      $this->logger->info('Corantioquia Sync: Iniciando carga de lista desde @url', ['@url' => $url]);
      
      $response = $this->httpClient->request('GET', $url, [
        'verify' => false,
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 (compatible; DrupalBot/1.0)',
        ],
      ]);
      $html = (string) $response->getBody();
      
      $this->logger->info('Corantioquia Sync: HTML obtenido exitosamente. Longitud: @length', ['@length' => strlen($html)]);

      $dom = new \DOMDocument();
      @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
      $xpath = new \DOMXPath($dom);

      // Buscar artículos en los resultados de búsqueda (estructura específica de Corantioquia)
      $nodes = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " page-content ")]//article[contains(concat(" ", normalize-space(@class), " "), " post ")]');

      // Fallback
      if ($nodes->length === 0) {
        $this->logger->warning('Corantioquia Sync: No se encontraron artículos con el selector principal. Usando fallback.');
        $nodes = $xpath->query('//article | //*[contains(concat(" ", normalize-space(@class), " "), " elementor-post ")]');
      }

      $this->logger->info('Corantioquia Sync: Nodos de artículos encontrados: @count', ['@count' => $nodes->length]);

      foreach ($nodes as $index => $node) {
        $titleNode = $xpath->query('.//h2//a | .//h3//a | .//*[contains(@class, "entry-title")]//a', $node)->item(0);
        if (!$titleNode) {
           $this->logger->warning('Corantioquia Sync: Artículo índice @index no tiene title node.', ['@index' => $index]);
           continue;
        }

        $detail_url = $titleNode->getAttribute('href');
        $title = trim($titleNode->textContent);

        $imgNode = $xpath->query('.//img', $node)->item(0);
        if (!$imgNode) {
           $this->logger->warning('Corantioquia Sync: Artículo "@title" no tiene imagen.', ['@title' => $title]);
           continue;
        }
        $img_url = $imgNode->getAttribute('src');

        $excerptNode = $xpath->query('.//p | .//*[contains(@class, "entry-summary")]', $node)->item(0);
        $excerpt = $excerptNode ? trim($excerptNode->textContent) : '';

        if (!empty($title) && !empty($detail_url)) {
          $this->logger->info('Corantioquia Sync: Item extraído correctamente: @title', ['@title' => $title]);
          $items[] = [
            'title' => $title,
            'image' => $img_url,
            'excerpt' => $excerpt,
            'url' => $detail_url,
          ];
        }
      }
    } catch (\Exception $e) {
      $this->logger->error('Error fetching Corantioquia news list: @message', ['@message' => $e->getMessage()]);
    }

    return $items;
  }

  /**
   * Processes a single news item from Corantioquia.
   *
   * @param array $item
   *   The news item data.
   *
   * @return bool|string
   *   True if created, 'exists' if duplicate, False on error.
   */
  public function processCorantioquiaNewsItem(array $item)
  {
    $this->logger->info('Corantioquia Sync: Validando si la noticia "@title" ya existe.', ['@title' => $item['title']]);

    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->condition('type', 'news')
      ->condition('title', $item['title'])
      ->accessCheck(FALSE);
    $existing = $query->execute();

    if (!empty($existing)) {
      $this->logger->info('Corantioquia Sync: La noticia "@title" ya existe. Omitiendo.', ['@title' => $item['title']]);
      return 'exists';
    }

    try {
      $this->logger->info('Corantioquia Sync: Obteniendo detalle desde @url', ['@url' => $item['url']]);
      $response = $this->httpClient->request('GET', $item['url'], [
        'verify' => false,
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 (compatible; DrupalBot/1.0)',
        ],
      ]);
      $html = (string) $response->getBody();

      $dom = new \DOMDocument();
      @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
      $xpath = new \DOMXPath($dom);

      // Elementor Single Post, o <main> o <div class="entry-content">
      $contentNodes = $xpath->query('//div[contains(@data-elementor-type, "single")] | //main | //div[contains(@class, "entry-content")]');

      $content_html = '';
      $radicado_en = '';

      if ($contentNodes->length > 0) {
        $this->logger->info('Corantioquia Sync: Encontrado nodo principal de contenido.');
        $contentNode = $contentNodes->item(0);
        
        // Buscar ciudad y fecha en el texto (ej. "Caucasia, 20 de agosto...")
        $paragraphs = $xpath->query('.//p', $contentNode);
        foreach ($paragraphs as $pNode) {
          $text = trim(str_replace("\xc2\xa0", ' ', $pNode->textContent));
          if (preg_match('/^([a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+),\s+\d{1,2}\s+de\s+[a-zA-Z]+\s+de\s+\d{4}/su', $text, $matches)) {
            $radicado_en = trim($matches[1]);
            $this->logger->info('Corantioquia Sync: Ciudad extraída: @ciudad', ['@ciudad' => $radicado_en]);
            break;
          }
        }

        $content_html = $dom->saveHTML($contentNode);
      } else {
        $this->logger->warning('Corantioquia Sync: Nodo principal no encontrado, usando fallback para contenido.');
        $fallbackNodes = $xpath->query('//article//p | //main//p');
        foreach ($fallbackNodes as $p) {
          $content_html .= $dom->saveHTML($p);
        }
      }

      if (empty($content_html)) {
        $this->logger->warning('Corantioquia Sync: Contenido no encontrado, usando excerpt.');
        $content_html = '<p>' . $item['excerpt'] . '</p>';
      }

      // Descargar y guardar la imagen si existe
      $file = null;
      if (!empty($item['image'])) {
        try {
          $this->logger->info('Corantioquia Sync: Descargando imagen desde @url', ['@url' => $item['image']]);
          $image_data = (string) $this->httpClient->request('GET', $item['image'], [
            'verify' => false,
            'headers' => [
              'User-Agent' => 'Mozilla/5.0 (compatible; DrupalBot/1.0)',
            ],
          ])->getBody();
          $filename = basename(parse_url($item['image'], PHP_URL_PATH));
          $filename = preg_replace('/[^a-zA-Z0-9-_\.]/', '', $filename);
          if (!empty($filename)) {
            $directory = 'public://news_images';
            $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
            $file_uri = $directory . '/' . $filename;
            $file_uri = $this->fileSystem->saveData($image_data, $file_uri, FileSystemInterface::EXISTS_RENAME);

            $file = File::create([
              'uri' => $file_uri,
              'status' => 1,
            ]);
            $file->save();
            $this->logger->info('Corantioquia Sync: Imagen guardada exitosamente.');
          }
        } catch (\Exception $imgEx) {
          $this->logger->warning('Corantioquia Sync: No se pudo descargar la imagen @url: @msg', [
            '@url' => $item['image'],
            '@msg' => $imgEx->getMessage(),
          ]);
        }
      }

      $node_data = [
        'type' => 'news',
        'title' => $item['title'],
        'field_resumen' => [
          'value' => $item['excerpt'],
          'format' => 'basic_html',
        ],
        'field_contenido' => [
          'value' => $content_html,
          'format' => 'full_html',
        ],
        'status' => 0, // Borrador
      ];

      if ($file) {
        $node_data['field_imagen_destacada'] = [
          'target_id' => $file->id(),
          'alt' => 'Foto Corantioquia',
        ];
        $node_data['field_credito_imagen_destacada'] = [
          'value' => 'Corantioquia',
        ];
      }

      if (!empty($radicado_en)) {
        $node_data['field_radicado_en'] = ['value' => $radicado_en];
      }

      $term_id = $this->getTaxonomyTermIdByName('Corantioquia');
      if ($term_id) {
        $node_data['field_fuente_de_informacion'] = ['target_id' => $term_id];
      }

      $node = $node_storage->create($node_data);
      $node->save();

      $this->logger->info('Corantioquia Sync: Nodo de noticia "@title" creado exitosamente.', ['@title' => $item['title']]);

      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('Error processing Corantioquia news item @title: @message', [
        '@title' => $item['title'],
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Obtiene el ID del término de taxonomía a partir de su nombre.
   *
   * @param string $name
   *   El nombre del término.
   *
   * @return int|null
   *   El ID del término o null si no se encuentra.
   */
  protected function getTaxonomyTermIdByName($name) {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $term_storage->loadByProperties(['name' => $name]);
    if (!empty($terms)) {
      $term = reset($terms);
      return $term->id();
    }
    return NULL;
  }

}
