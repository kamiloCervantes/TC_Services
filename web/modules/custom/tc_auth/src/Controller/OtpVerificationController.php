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
 * Controller for OTP verification and resend endpoints.
 */
class OtpVerificationController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The OTP manager service.
   *
   * @var \Drupal\tc_auth\Service\OtpManager
   */
  protected OtpManager $otpManager;

  /**
   * Constructs an OtpVerificationController object.
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
   * Verifies OTP code and activates user account upon success.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function verifyOtp(Request $request): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE);
      if (empty($data)) {
        $data = $request->request->all();
      }

      $email = trim($data['email'] ?? ($data['mail'] ?? ''));
      $code = trim($data['code'] ?? ($data['otp'] ?? ''));
      $purpose = trim($data['purpose'] ?? 'register');

      if (empty($email) || empty($code)) {
        return new JsonResponse([
          'status' => 'error',
          'message' => $this->t('Debes proporcionar el correo y el código de verificación.'),
        ], Response::HTTP_BAD_REQUEST);
      }

      $result = $this->otpManager->verifyOtp($email, $code, $purpose);

      if (!$result['valid']) {
        return new JsonResponse([
          'status' => 'error',
          'message' => $result['message'],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
      }

      // If purpose is registration, activate user
      if ($purpose === 'register') {
        $user_storage = $this->entityTypeManager()->getStorage('user');
        $users = $user_storage->loadByProperties(['mail' => $email]);
        if (!empty($users)) {
          $user = reset($users);
          $user->activate();
          $user->save();
        }
      }

      return new JsonResponse([
        'status' => 'success',
        'message' => $this->t('Tu cuenta ha sido verificada y activada exitosamente. Ya puedes iniciar sesión.'),
      ], Response::HTTP_OK);
    }
    catch (\Throwable $t) {
      \Drupal::logger('tc_auth')->error('Error in verifyOtp endpoint: @msg', ['@msg' => $t->getMessage()]);
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Ocurrió un error al verificar el código.'),
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Resends a new OTP code to the requested email.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function resendOtp(Request $request): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE);
      if (empty($data)) {
        $data = $request->request->all();
      }

      $email = trim($data['email'] ?? ($data['mail'] ?? ''));
      $purpose = trim($data['purpose'] ?? 'register');

      if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return new JsonResponse([
          'status' => 'error',
          'message' => $this->t('Debes proporcionar un correo válido.'),
        ], Response::HTTP_BAD_REQUEST);
      }

      $user_storage = $this->entityTypeManager()->getStorage('user');
      $users = $user_storage->loadByProperties(['mail' => $email]);
      if (empty($users)) {
        return new JsonResponse([
          'status' => 'error',
          'message' => $this->t('No se encontró ningún usuario con el correo proporcionado.'),
        ], Response::HTTP_NOT_FOUND);
      }

      $user = reset($users);
      $uid = (int) $user->id();
      $name = $user->getDisplayName() ?: 'Usuario';

      $sent = $this->otpManager->sendOtp($email, $uid, $name, $purpose);

      if (!$sent) {
        return new JsonResponse([
          'status' => 'error',
          'message' => $this->t('No se pudo reenviar el código. Intenta de nuevo más tarde.'),
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      return new JsonResponse([
        'status' => 'success',
        'message' => $this->t('Hemos reenviado un nuevo código de verificación a tu correo.'),
        'expires_in' => OtpManager::OTP_EXPIRATION_SECONDS,
      ], Response::HTTP_OK);
    }
    catch (\Throwable $t) {
      \Drupal::logger('tc_auth')->error('Error in resendOtp endpoint: @msg', ['@msg' => $t->getMessage()]);
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Ocurrió un error al reenviar el código.'),
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

}