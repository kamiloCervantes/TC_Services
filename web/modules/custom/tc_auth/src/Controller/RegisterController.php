<?php

namespace Drupal\tc_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\tc_auth\Service\OtpManager;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for user registration with OTP.
 */
class RegisterController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The OTP manager service.
   *
   * @var \Drupal\tc_auth\Service\OtpManager
   */
  protected OtpManager $otpManager;

  /**
   * Constructs a RegisterController object.
   */
  public function __construct(OtpManager $otp_manager) {
    $this->otpManager = $otp_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tc_auth.otp_manager')
    );
  }

  /**
   * Handles user registration and triggers OTP email dispatch.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function register(Request $request): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE);

      if (empty($data)) {
        $data = $request->request->all();
      }

      $email = trim($data['email'] ?? ($data['mail'] ?? ''));
      $password = $data['password'] ?? ($data['pass'] ?? '');
      $name = trim($data['name'] ?? ($data['username'] ?? ''));

      // Basic validation
      if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return new JsonResponse([
          'status' => 'error',
          'message' => $this->t('Debes proporcionar un correo electrónico válido.'),
        ], Response::HTTP_BAD_REQUEST);
      }

      if (empty($password) || strlen($password) < 6) {
        return new JsonResponse([
          'status' => 'error',
          'message' => $this->t('La contraseña debe tener al menos 6 caracteres.'),
        ], Response::HTTP_BAD_REQUEST);
      }

      if (empty($name)) {
        $name = explode('@', $email)[0];
      }

      $user_storage = $this->entityTypeManager()->getStorage('user');
      $existing_users = $user_storage->loadByProperties(['mail' => $email]);
      $existing_user_by_mail = !empty($existing_users) ? reset($existing_users) : NULL;

      if ($existing_user_by_mail) {
        if ($existing_user_by_mail->isActive()) {
          return new JsonResponse([
            'status' => 'error',
            'message' => $this->t('Ya existe una cuenta activa registrada con este correo.'),
          ], Response::HTTP_CONFLICT);
        }

        $uid = (int) $existing_user_by_mail->id();
        $existing_user_by_mail->setPassword($password);
        $existing_user_by_mail->save();
      }
      else {
        // Ensure unique username
        $username_candidate = $name;
        $i = 1;
        while (!empty($user_storage->loadByProperties(['name' => $username_candidate]))) {
          $username_candidate = $name . '_' . $i;
          $i++;
        }

        // Create new inactive user
        $user = User::create([
          'name' => $username_candidate,
          'mail' => $email,
          'pass' => $password,
          'status' => 0, // Inactive until OTP is verified
        ]);
        $user->save();
        $uid = (int) $user->id();
      }

      // Generate and send OTP via MimeMail + SMTP
      $sent = $this->otpManager->sendOtp($email, $uid, $name, 'register');

      if (!$sent) {
        return new JsonResponse([
          'status' => 'error',
          'message' => $this->t('No se pudo enviar el correo de verificación. Por favor intenta más tarde o contacta al administrador.'),
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      return new JsonResponse([
        'status' => 'success',
        'message' => $this->t('Registro iniciado. Hemos enviado un código de verificación de 6 dígitos a tu correo electrónico.'),
        'email' => $email,
        'expires_in' => OtpManager::OTP_EXPIRATION_SECONDS,
      ], Response::HTTP_CREATED);
    }
    catch (\Throwable $t) {
      \Drupal::logger('tc_auth')->error('Error in register endpoint: @msg', ['@msg' => $t->getMessage()]);
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Ocurrió un error al procesar el registro.'),
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

}