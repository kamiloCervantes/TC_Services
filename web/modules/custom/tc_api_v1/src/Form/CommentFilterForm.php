<?php

namespace Drupal\tc_api_v1\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Formulario de filtros para la administración de comentarios.
 */
class CommentFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'tc_api_v1_comment_filter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $user_filter = $request->query->get('user', '');
    $news_filter = $request->query->get('news', '');
    $date_from = $request->query->get('date_from', '');
    $date_to = $request->query->get('date_to', '');

    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filtros de comentarios'),
      '#open' => TRUE,
    ];

    $form['filters']['grid'] = [
      '#type' => 'container',
      '#attributes' => [
        'style' => 'display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 10px;',
      ],
    ];

    $form['filters']['grid']['user'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Usuario / Autor'),
      '#placeholder' => $this->t('Nombre, correo o UID'),
      '#default_value' => $user_filter,
    ];

    $form['filters']['grid']['news'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Noticia'),
      '#placeholder' => $this->t('Título o ID (NID)'),
      '#default_value' => $news_filter,
    ];

    $form['filters']['grid']['date_from'] = [
      '#type' => 'date',
      '#title' => $this->t('Fecha desde'),
      '#default_value' => $date_from,
    ];

    $form['filters']['grid']['date_to'] = [
      '#type' => 'date',
      '#title' => $this->t('Fecha hasta'),
      '#default_value' => $date_to,
    ];

    $form['filters']['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['style' => 'margin-top: 10px;'],
    ];

    $form['filters']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Aplicar Filtros'),
      '#button_type' => 'primary',
    ];

    $form['filters']['actions']['reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Restablecer'),
      '#submit' => ['::resetForm'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $query = [];

    $user = trim($form_state->getValue('user') ?? '');
    $news = trim($form_state->getValue('news') ?? '');
    $date_from = trim($form_state->getValue('date_from') ?? '');
    $date_to = trim($form_state->getValue('date_to') ?? '');

    if ($user !== '') {
      $query['user'] = $user;
    }
    if ($news !== '') {
      $query['news'] = $news;
    }
    if ($date_from !== '') {
      $query['date_from'] = $date_from;
    }
    if ($date_to !== '') {
      $query['date_to'] = $date_to;
    }

    $form_state->setRedirectUrl(Url::fromRoute('tc_api_v1.admin_comments', [], ['query' => $query]));
  }

  /**
   * Limpia los filtros y recarga la vista.
   */
  public function resetForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirectUrl(Url::fromRoute('tc_api_v1.admin_comments'));
  }

}