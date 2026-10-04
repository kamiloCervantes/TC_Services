<?php

namespace Drupal\tc_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\tc_auth\Service\OtpManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for password reset / recovery endpoints.
 */
class PasswordResetController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The OTP manager service.
   *
   * @var \Drupal\tc_auth\Service\OtpManager
   */
  protected OtpManager $otpManager;

  /**
   * Constructs a PasswordResetController object.
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
   * Genera y envía un código OTP para restablecer contraseña.
   *
   * POST /api/v1/auth/forgot-password
   */
  public function forgotPassword(Request $request): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE);
      if (empty($data)) {
        $data = $request->request->all();
      }

      $email = trim($data['email'] ?? ($data['mail'] ?? ''));

      if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return new JsonResponse([
          'status' => 'error',
          'message' => $this->t('Debes proporcionar un correo electrónico válido.'),
        ], Response::HTTP_BAD_REQUEST);
      }

      $user_storage = $this->entityTypeManager()->getStorage('user');
      $users = $user_storage->loadByProperties(['mail' => $email]);

      if (empty($users)) {
        // Respuesta genérica para evitar enumeración de usuarios
        return new JsonResponse([
          'status' => 'success',
          'message' => $this->t('Si el correo está registrado en Tierra Caliente, recibirás un código de 6 dígitos.'),
        ], Response::HTTP_OK);
      }

      /** @var \Drupal\user\UserInterface $user */
      $user = reset($users);
      $uid = (int) $user->id();
      $name = $user->getDisplayName() ?: 'Usuario';

      $sent = $this->otpManager->sendOtp($email, $uid, $name, 'password_reset');

      if (!$sent) {
        return new JsonResponse([
          'status' => 'error',
          'message' => $this->t('No se pudo enviar el correo con el código de recuperación. Intenta más tarde.'),
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      return new JsonResponse([
        'status' => 'success',
        'message' => $this->t('Hemos enviado un código de 6 dígitos a tu correo electrónico para restablecer tu contraseña.'),
        'expires_in' => OtpManager::OTP_EXPIRATION_SECONDS,
      ], Response::HTTP_OK);
    }
    catch (\Throwable $t) {
      \Drupal::logger('tc_auth')->error('Error in forgotPassword: @msg', ['@msg' => $t->getMessage()]);
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Ocurrió un error al procesar la solicitud de recuperación.'),
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Valida el código OTP y actualiza la contraseña del usuario.
   *
   * POST /api/v1/auth/reset-password
   */
  public function resetPassword(Request $request): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE);
      if (empty($data)) {
        $data = $request->request->all();
      }

      $email = trim($data['email'] ?? ($data['mail'] ?? ''));
      $code = trim($data['code'] ?? ($data['otp'] ?? ''));
      $new_password = (string) ($data['new_password'] ?? ($data['password'] ?? ''));

      if (empty($email) || empty($code)) {
        return new JsonResponse([
          'status' => 'error',
          'message' => $this->t('Debes proporcionar el correo y el código de verificación.'),
        ], Response::HTTP_BAD_REQUEST);
      }

      if (strlen($new_password) < 6) {
        return new JsonResponse([
          'status' => 'error',
          'message' => $this->t('La nueva contraseña debe tener al menos 6 caracteres.'),
        ], Response::HTTP_BAD_REQUEST);
      }

      // Validar código OTP con propósito password_reset
      $result = $this->otpManager->verifyOtp($email, $code, 'password_reset');

      if (!$result['valid']) {
        return new JsonResponse([
          'status' => 'error',
          'message' => $result['message'],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
      }

      $user_storage = $this->entityTypeManager()->getStorage('user');
      $users = $user_storage->loadByProperties(['mail' => $email]);

      if (empty($users)) {
        return new JsonResponse([
          'status' => 'error',
          'message' => $this->t('Usuario no encontrado.'),
        ], Response::HTTP_NOT_FOUND);
      }

      /** @var \Drupal\user\UserInterface $user */
      $user = reset($users);
      $user->setPassword($new_password);
      // Aseguramos que la cuenta quede activa
      $user->activate();
      $user->save();

      return new JsonResponse([
        'status' => 'success',
        'message' => $this->t('Tu contraseña ha sido restablecida exitosamente. Ya puedes iniciar sesión con tu nueva contraseña.'),
      ], Response::HTTP_OK);
    }
    catch (\Throwable $t) {
      \Drupal::logger('tc_auth')->error('Error in resetPassword: @msg', ['@msg' => $t->getMessage()]);
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Ocurrió un error al restablecer la contraseña.'),
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

}