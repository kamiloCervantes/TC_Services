<?php

namespace Drupal\tc_api_v1\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\tc_api_v1\Controller\CommentsController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Formulario para que el administrador edite un comentario.
 */
class CommentEditForm extends FormBase {

  /**
   * Conexión a la base de datos.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Gestor de entidades.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructor.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'tc_api_v1_comment_edit_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $id = NULL): array {
    $cid = (int) $id;
    if ($cid <= 0) {
      throw new NotFoundHttpException('ID de comentario inválido.');
    }

    $comment = $this->database->select('tc_article_comments', 'c')
      ->fields('c', ['id', 'nid', 'uid', 'message', 'created', 'ip_address', 'status'])
      ->condition('c.id', $cid)
      ->execute()
      ->fetchObject();

    if (!$comment) {
      throw new NotFoundHttpException('El comentario no existe.');
    }

    $form_state->set('comment', $comment);

    // Obtener información de la noticia y del usuario para contexto
    $node = $this->entityTypeManager->getStorage('node')->load($comment->nid);
    $node_link = $node ? Link::fromTextAndUrl($node->getTitle() . ' (NID: ' . $node->id() . ')', $node->toUrl())->toString() : 'NID: ' . $comment->nid;

    $user = $this->entityTypeManager->getStorage('user')->load($comment->uid);
    $user_link = $user ? Link::fromTextAndUrl($user->getDisplayName() . ' (UID: ' . $user->id() . ')', $user->toUrl())->toString() : 'UID: ' . $comment->uid;

    $date_formatter = \Drupal::service('date.formatter');
    $created_formatted = $date_formatter->format($comment->created, 'custom', 'd/m/Y - H:i:s T');

    $form['info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Información del Comentario #@id', ['@id' => $comment->id]),
    ];

    $form['info']['details'] = [
      '#theme' => 'item_list',
      '#items' => [
        ['#markup' => '<strong>' . $this->t('Noticia:') . '</strong> ' . $node_link],
        ['#markup' => '<strong>' . $this->t('Autor:') . '</strong> ' . $user_link],
        ['#markup' => '<strong>' . $this->t('Fecha de creación:') . '</strong> ' . $created_formatted],
        ['#markup' => '<strong>' . $this->t('Dirección IP:') . '</strong> ' . ($comment->ip_address ?: '-')],
      ],
    ];

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Mensaje del comentario'),
      '#default_value' => $comment->message,
      '#rows' => 5,
      '#required' => TRUE,
    ];

    $form['status'] = [
      '#type' => 'radios',
      '#title' => $this->t('Estado del comentario'),
      '#options' => [
        1 => $this->t('Publicado (Visible y contabilizado)'),
        0 => $this->t('Oculto / Moderado (No visible al público)'),
      ],
      '#default_value' => (int) $comment->status,
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Guardar Cambios'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancelar'),
      '#url' => Url::fromRoute('tc_api_v1.admin_comments'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $comment = $form_state->get('comment');
    if (!$comment) {
      return;
    }

    $message = trim($form_state->getValue('message'));
    $status = (int) $form_state->getValue('status');

    $this->database->update('tc_article_comments')
      ->fields([
        'message' => strip_tags($message),
        'status' => $status,
      ])
      ->condition('id', $comment->id)
      ->execute();

    // Recalcular el total de comentarios en la noticia
    CommentsController::updateNodeCommentCount((int) $comment->nid, $this->database, $this->entityTypeManager);

    $this->messenger()->addStatus($this->t('El comentario #@id ha sido actualizado exitosamente.', ['@id' => $comment->id]));
    $form_state->setRedirectUrl(Url::fromRoute('tc_api_v1.admin_comments'));
  }

}