<?php

namespace Drupal\tc_api_v1\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controlador de la vista administrativa para gestionar comentarios.
 */
class AdminCommentsController extends ControllerBase {

  /**
   * Conexión a la base de datos.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Servicio de formato de fechas.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Constructor.
   */
  public function __construct(Connection $database, DateFormatterInterface $date_formatter) {
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('date.formatter')
    );
  }

  /**
   * Renderiza el listado administrativo de comentarios con filtros y paginación.
   */
  public function listComments(Request $request): array {
    $build = [];

    // 1. Formulario de filtros
    $build['filter_form'] = $this->formBuilder()->getForm('Drupal\tc_api_v1\Form\CommentFilterForm');

    // 2. Parámetros de búsqueda GET
    $user_filter = trim($request->query->get('user') ?? '');
    $news_filter = trim($request->query->get('news') ?? '');
    $date_from = trim($request->query->get('date_from') ?? '');
    $date_to = trim($request->query->get('date_to') ?? '');

    // 3. Definición de encabezado de tabla con ordenación
    $header = [
      ['data' => $this->t('ID'), 'field' => 'c.id', 'sort' => 'desc'],
      ['data' => $this->t('Noticia'), 'field' => 'n.title'],
      ['data' => $this->t('Autor / Usuario'), 'field' => 'u.name'],
      ['data' => $this->t('Comentario')],
      ['data' => $this->t('Fecha y Hora'), 'field' => 'c.created'],
      ['data' => $this->t('IP')],
      ['data' => $this->t('Estado'), 'field' => 'c.status'],
      ['data' => $this->t('Acciones')],
    ];

    // 4. Consulta a la base de datos con paginador y ordenación
    $query = $this->database->select('tc_article_comments', 'c')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender')
      ->extend('Drupal\Core\Database\Query\TableSortExtender');

    $query->leftJoin('users_field_data', 'u', 'c.uid = u.uid');
    $query->leftJoin('node_field_data', 'n', 'c.nid = n.nid');

    $query->fields('c', ['id', 'nid', 'uid', 'message', 'created', 'ip_address', 'status']);
    $query->addField('u', 'name', 'author_name');
    $query->addField('u', 'mail', 'author_mail');
    $query->addField('n', 'title', 'news_title');

    // Aplicar filtro por usuario
    if ($user_filter !== '') {
      if (is_numeric($user_filter)) {
        $query->condition('c.uid', (int) $user_filter);
      }
      else {
        $or_user = $query->orConditionGroup()
          ->condition('u.name', '%' . $this->database->escapeLike($user_filter) . '%', 'LIKE')
          ->condition('u.mail', '%' . $this->database->escapeLike($user_filter) . '%', 'LIKE');
        $query->condition($or_user);
      }
    }

    // Aplicar filtro por noticia
    if ($news_filter !== '') {
      if (is_numeric($news_filter)) {
        $query->condition('c.nid', (int) $news_filter);
      }
      else {
        $query->condition('n.title', '%' . $this->database->escapeLike($news_filter) . '%', 'LIKE');
      }
    }

    // Aplicar filtro por fecha desde
    if ($date_from !== '') {
      $from_ts = strtotime($date_from . ' 00:00:00');
      if ($from_ts) {
        $query->condition('c.created', $from_ts, '>=');
      }
    }

    // Aplicar filtro por fecha hasta
    if ($date_to !== '') {
      $to_ts = strtotime($date_to . ' 23:59:59');
      if ($to_ts) {
        $query->condition('c.created', $to_ts, '<=');
      }
    }

    $query->orderByHeader($header)->limit(25);
    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $record) {
      // Enlace a la noticia
      if (!empty($record->news_title)) {
        $news_link = Link::fromTextAndUrl(
          $record->news_title,
          Url::fromRoute('entity.node.canonical', ['node' => $record->nid])
        )->toString();
      }
      else {
        $news_link = $this->t('Noticia #@nid (Eliminada)', ['@nid' => $record->nid]);
      }

      // Enlace al usuario
      if (!empty($record->author_name)) {
        $user_link = Link::fromTextAndUrl(
          $record->author_name,
          Url::fromRoute('entity.user.canonical', ['user' => $record->uid])
        )->toString();
      }
      else {
        $user_link = $this->t('Usuario #@uid (Desconocido)', ['@uid' => $record->uid]);
      }

      // Estado con badge
      if ((int) $record->status === 1) {
        $status_markup = '<span style="display:inline-block; padding: 2px 8px; border-radius: 12px; background: #ecfdf5; color: #047857; font-weight: 600; font-size: 12px;">' . $this->t('Publicado') . '</span>';
      }
      else {
        $status_markup = '<span style="display:inline-block; padding: 2px 8px; border-radius: 12px; background: #fef2f2; color: #b91c1c; font-weight: 600; font-size: 12px;">' . $this->t('Oculto') . '</span>';
      }

      // Enlaces de acciones administrativas
      $edit_url = Url::fromRoute('tc_api_v1.admin_comment_edit', ['id' => $record->id]);
      $delete_url = Url::fromRoute('tc_api_v1.admin_comment_delete', ['id' => $record->id]);

      $actions = [
        '#type' => 'operations',
        '#links' => [
          'edit' => [
            'title' => $this->t('Editar'),
            'url' => $edit_url,
          ],
          'delete' => [
            'title' => $this->t('Eliminar'),
            'url' => $delete_url,
          ],
        ],
      ];

      // Formato del mensaje
      $msg_short = htmlspecialchars(substr($record->message, 0, 70));
      if (strlen($record->message) > 70) {
        $msg_short .= '...';
      }

      $rows[] = [
        'id' => $record->id,
        'news' => ['data' => ['#markup' => $news_link]],
        'user' => ['data' => ['#markup' => $user_link]],
        'message' => [
          'data' => [
            '#markup' => '<span title="' . htmlspecialchars($record->message) . '">' . $msg_short . '</span>',
          ],
        ],
        'date' => $this->dateFormatter->format($record->created, 'custom', 'd/m/Y - H:i'),
        'ip' => $record->ip_address ?: '-',
        'status' => ['data' => ['#markup' => $status_markup]],
        'actions' => ['data' => $actions],
      ];
    }

    // 5. Tabla de resultados
    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No se encontraron comentarios que coincidan con los criterios de búsqueda.'),
      '#attributes' => ['class' => ['responsive-enabled']],
    ];

    // 6. Paginador
    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

}