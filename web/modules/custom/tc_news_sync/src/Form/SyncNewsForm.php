<?php

namespace Drupal\tc_news_sync\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Formulario para la sincronización de noticias.
 */
class SyncNewsForm extends FormBase
{

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'tc_news_sync_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Haga clic en el botón para iniciar la sincronización de las noticias.') . '</p>',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sincronizar'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $batch = [
      'title' => $this->t('Sincronizando noticias...'),
      'operations' => [
        //['\Drupal\tc_news_sync\Form\SyncNewsForm::fetchMonteriaOperation', []],
        //['\Drupal\tc_news_sync\Form\SyncNewsForm::fetchUrraOperation', []],
        //['\Drupal\tc_news_sync\Form\SyncNewsForm::fetchUnicordobaOperation', []],
        //['\Drupal\tc_news_sync\Form\SyncNewsForm::fetchCordobaGobOperation', []],
        //['\Drupal\tc_news_sync\Form\SyncNewsForm::fetchEpmOperation', []],
        ['\Drupal\tc_news_sync\Form\SyncNewsForm::fetchCorantioquiaOperation', []],
      ],
      'finished' => '\Drupal\tc_news_sync\Form\SyncNewsForm::batchFinished',
    ];

    batch_set($batch);
  }

  /**
   * Batch operation: Fetches the list for Monteria and queues the processing.
   */
  public static function fetchMonteriaOperation(&$context)
  {
    if (empty($context['sandbox'])) {
      $context['message'] = 'Obteniendo listado de noticias desde el portal de Montería...';

      /** @var \Drupal\tc_news_sync\Service\SyncService $sync_service */
      $sync_service = \Drupal::service('tc_news_sync.sync_service');

      $items = $sync_service->getMonteriaNewsList();

      if (empty($items)) {
        $context['finished'] = 1;
        // Inicializamos los resultados en cero si no existen
        if (!isset($context['results']['created'])) {
          $context['results']['created'] = 0;
          $context['results']['exists'] = 0;
          $context['results']['errors'] = 0;
        }
        return;
      }

      $context['sandbox']['items'] = $items;
      $context['sandbox']['max'] = count($items);
      $context['sandbox']['progress'] = 0;

      if (!isset($context['results']['created'])) {
        $context['results']['created'] = 0;
        $context['results']['exists'] = 0;
        $context['results']['errors'] = 0;
      }
    }

    $items = &$context['sandbox']['items'];
    if (!empty($items)) {
      $item = array_shift($items);

      /** @var \Drupal\tc_news_sync\Service\SyncService $sync_service */
      $sync_service = \Drupal::service('tc_news_sync.sync_service');

      $context['message'] = 'Procesando noticia Montería: ' . $item['title'];
      $result = $sync_service->processMonteriaNewsItem($item);

      if ($result === TRUE) {
        $context['results']['created']++;
      } elseif ($result === 'exists') {
        $context['results']['exists']++;
      } else {
        $context['results']['errors']++;
      }

      $context['sandbox']['progress']++;
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Batch operation: Fetches the list for Urra and queues the processing.
   */
  public static function fetchUrraOperation(&$context)
  {
    if (empty($context['sandbox'])) {
      $context['message'] = 'Obteniendo listado de noticias desde el portal de URRÁ...';

      /** @var \Drupal\tc_news_sync\Service\SyncService $sync_service */
      $sync_service = \Drupal::service('tc_news_sync.sync_service');

      $items = $sync_service->getUrraNewsList();

      if (empty($items)) {
        $context['finished'] = 1;
        if (!isset($context['results']['created'])) {
          $context['results']['created'] = 0;
          $context['results']['exists'] = 0;
          $context['results']['errors'] = 0;
        }
        return;
      }

      $context['sandbox']['items'] = $items;
      $context['sandbox']['max'] = count($items);
      $context['sandbox']['progress'] = 0;

      if (!isset($context['results']['created'])) {
        $context['results']['created'] = 0;
        $context['results']['exists'] = 0;
        $context['results']['errors'] = 0;
      }
    }

    $items = &$context['sandbox']['items'];
    if (!empty($items)) {
      $item = array_shift($items);

      /** @var \Drupal\tc_news_sync\Service\SyncService $sync_service */
      $sync_service = \Drupal::service('tc_news_sync.sync_service');

      $context['message'] = 'Procesando noticia URRÁ: ' . $item['title'];
      $result = $sync_service->processUrraNewsItem($item);

      if ($result === TRUE) {
        $context['results']['created']++;
      } elseif ($result === 'exists') {
        $context['results']['exists']++;
      } else {
        $context['results']['errors']++;
      }

      $context['sandbox']['progress']++;
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Batch operation: Fetches the list for Unicordoba and queues the processing.
   */
  public static function fetchUnicordobaOperation(&$context)
  {
    if (empty($context['sandbox'])) {
      $context['message'] = 'Obteniendo listado de noticias desde el portal de Unicórdoba...';

      /** @var \Drupal\tc_news_sync\Service\SyncService $sync_service */
      $sync_service = \Drupal::service('tc_news_sync.sync_service');

      $items = $sync_service->getUnicordobaNewsList();

      if (empty($items)) {
        $context['finished'] = 1;
        if (!isset($context['results']['created'])) {
          $context['results']['created'] = 0;
          $context['results']['exists'] = 0;
          $context['results']['errors'] = 0;
        }
        return;
      }

      $context['sandbox']['items'] = $items;
      $context['sandbox']['max'] = count($items);
      $context['sandbox']['progress'] = 0;

      if (!isset($context['results']['created'])) {
        $context['results']['created'] = 0;
        $context['results']['exists'] = 0;
        $context['results']['errors'] = 0;
      }
    }

    $items = &$context['sandbox']['items'];
    if (!empty($items)) {
      $item = array_shift($items);

      /** @var \Drupal\tc_news_sync\Service\SyncService $sync_service */
      $sync_service = \Drupal::service('tc_news_sync.sync_service');

      $context['message'] = 'Procesando noticia Unicórdoba: ' . $item['title'];
      $result = $sync_service->processUnicordobaNewsItem($item);

      if ($result === TRUE) {
        $context['results']['created']++;
      } elseif ($result === 'exists') {
        $context['results']['exists']++;
      } else {
        $context['results']['errors']++;
      }

      $context['sandbox']['progress']++;
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Batch operation: Fetches the list for Gobernación de Córdoba and queues the processing.
   */
  public static function fetchCordobaGobOperation(&$context)
  {
    if (empty($context['sandbox'])) {
      $context['message'] = 'Obteniendo listado de noticias desde el portal de la Gobernación de Córdoba...';

      /** @var \Drupal\tc_news_sync\Service\SyncService $sync_service */
      $sync_service = \Drupal::service('tc_news_sync.sync_service');

      $items = $sync_service->getCordobaGobNewsList();

      if (empty($items)) {
        $context['finished'] = 1;
        if (!isset($context['results']['created'])) {
          $context['results']['created'] = 0;
          $context['results']['exists'] = 0;
          $context['results']['errors'] = 0;
        }
        return;
      }

      $context['sandbox']['items'] = $items;
      $context['sandbox']['max'] = count($items);
      $context['sandbox']['progress'] = 0;

      if (!isset($context['results']['created'])) {
        $context['results']['created'] = 0;
        $context['results']['exists'] = 0;
        $context['results']['errors'] = 0;
      }
    }

    $items = &$context['sandbox']['items'];
    if (!empty($items)) {
      $item = array_shift($items);

      /** @var \Drupal\tc_news_sync\Service\SyncService $sync_service */
      $sync_service = \Drupal::service('tc_news_sync.sync_service');

      $context['message'] = 'Procesando noticia Gobernación Córdoba: ' . $item['title'];
      $result = $sync_service->processCordobaGobNewsItem($item);

      if ($result === TRUE) {
        $context['results']['created']++;
      } elseif ($result === 'exists') {
        $context['results']['exists']++;
      } else {
        $context['results']['errors']++;
      }

      $context['sandbox']['progress']++;
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Batch operation: Fetches the list for EPM and queues the processing.
   */
  public static function fetchEpmOperation(&$context)
  {
    if (empty($context['sandbox'])) {
      $context['message'] = 'Obteniendo listado de noticias desde el portal de EPM...';

      /** @var \Drupal\tc_news_sync\Service\SyncService $sync_service */
      $sync_service = \Drupal::service('tc_news_sync.sync_service');

      $items = $sync_service->getEpmNewsList();

      if (empty($items)) {
        $context['finished'] = 1;
        if (!isset($context['results']['created'])) {
          $context['results']['created'] = 0;
          $context['results']['exists'] = 0;
          $context['results']['errors'] = 0;
        }
        return;
      }

      // Invertir el orden para procesar primero la noticia más antigua,
      // de modo que la más reciente quede con la fecha de creación más alta.
      $items = array_reverse($items);

      $context['sandbox']['items'] = $items;
      $context['sandbox']['max'] = count($items);
      $context['sandbox']['progress'] = 0;

      if (!isset($context['results']['created'])) {
        $context['results']['created'] = 0;
        $context['results']['exists'] = 0;
        $context['results']['errors'] = 0;
      }
    }

    $items = &$context['sandbox']['items'];
    if (!empty($items)) {
      $item = array_shift($items);

      /** @var \Drupal\tc_news_sync\Service\SyncService $sync_service */
      $sync_service = \Drupal::service('tc_news_sync.sync_service');

      $context['message'] = 'Procesando noticia EPM: ' . $item['title'];
      $result = $sync_service->processEpmNewsItem($item);

      if ($result === TRUE) {
        $context['results']['created']++;
      } elseif ($result === 'exists') {
        $context['results']['exists']++;
      } else {
        $context['results']['errors']++;
      }

      $context['sandbox']['progress']++;
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Batch operation: Fetches the list for Corantioquia and queues the processing.
   */
  public static function fetchCorantioquiaOperation(&$context)
  {
    if (empty($context['sandbox'])) {
      $context['message'] = 'Obteniendo listado de noticias desde el portal de Corantioquia...';

      /** @var \Drupal\tc_news_sync\Service\SyncService $sync_service */
      $sync_service = \Drupal::service('tc_news_sync.sync_service');

      $items = $sync_service->getCorantioquiaNewsList();

      if (!empty($items)) {
        $items = array_reverse($items);
      }

      if (empty($items)) {
        $context['finished'] = 1;
        if (!isset($context['results']['created'])) {
          $context['results']['created'] = 0;
          $context['results']['exists'] = 0;
          $context['results']['errors'] = 0;
        }
        return;
      }

      $context['sandbox']['items'] = $items;
      $context['sandbox']['max'] = count($items);
      $context['sandbox']['progress'] = 0;

      if (!isset($context['results']['created'])) {
        $context['results']['created'] = 0;
        $context['results']['exists'] = 0;
        $context['results']['errors'] = 0;
      }
    }

    $items = &$context['sandbox']['items'];
    if (!empty($items)) {
      $item = array_shift($items);

      /** @var \Drupal\tc_news_sync\Service\SyncService $sync_service */
      $sync_service = \Drupal::service('tc_news_sync.sync_service');

      $context['message'] = 'Procesando noticia Corantioquia: ' . $item['title'];
      $result = $sync_service->processCorantioquiaNewsItem($item);

      if ($result === TRUE) {
        $context['results']['created']++;
      } elseif ($result === 'exists') {
        $context['results']['exists']++;
      } else {
        $context['results']['errors']++;
      }

      $context['sandbox']['progress']++;
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, $results, $operations)
  {
    $messenger = \Drupal::messenger();
    if ($success) {
      $created = isset($results['created']) ? $results['created'] : 0;
      $exists = isset($results['exists']) ? $results['exists'] : 0;
      $errors = isset($results['errors']) ? $results['errors'] : 0;
      $message = "Sincronización completada. Nuevas: {$created}, Duplicadas omitidas: {$exists}, Errores: {$errors}.";
      $messenger->addMessage($message);
    } else {
      $messenger->addError('Ocurrió un error al procesar los lotes de noticias.');
    }
  }

}
