<?php

namespace Drupal\tc_api_v1\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\tc_api_v1\Controller\CommentsController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Formulario de confirmación para eliminar un comentario por el administrador.
 */
class CommentDeleteForm extends ConfirmFormBase {

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
   * El registro del comentario a eliminar.
   *
   * @var object|null
   */
  protected $comment = NULL;

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
    return 'tc_api_v1_comment_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('¿Estás seguro de que deseas eliminar el comentario #@id?', [
      '@id' => $this->comment ? $this->comment->id : '',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Esta acción no se puede deshacer. El comentario será borrado definitivamente y se actualizará automáticamente el total de comentarios en la noticia correspondiente.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('tc_api_v1.admin_comments');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Eliminar Comentario');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $id = NULL): array {
    $cid = (int) $id;
    if ($cid <= 0) {
      throw new NotFoundHttpException('ID de comentario inválido.');
    }

    $this->comment = $this->database->select('tc_article_comments', 'c')
      ->fields('c', ['id', 'nid', 'uid', 'message', 'created', 'ip_address', 'status'])
      ->condition('c.id', $cid)
      ->execute()
      ->fetchObject();

    if (!$this->comment) {
      throw new NotFoundHttpException('El comentario no existe.');
    }

    $form_state->set('comment', $this->comment);

    $form['comment_preview'] = [
      '#type' => 'item',
      '#title' => $this->t('Mensaje a eliminar:'),
      '#markup' => '<blockquote style="border-left: 4px solid #e11d48; padding: 10px 15px; background: #fff1f2; margin: 15px 0;">' . nl2br(htmlspecialchars($this->comment->message)) . '</blockquote>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $comment = $form_state->get('comment') ?: $this->comment;
    if (!$comment) {
      return;
    }

    // Eliminar de la base de datos
    $this->database->delete('tc_article_comments')
      ->condition('id', $comment->id)
      ->execute();

    // Recalcular y actualizar el contador en el nodo
    CommentsController::updateNodeCommentCount((int) $comment->nid, $this->database, $this->entityTypeManager);

    $this->messenger()->addStatus($this->t('El comentario #@id ha sido eliminado exitosamente y el total de la noticia ha sido actualizado.', ['@id' => $comment->id]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}