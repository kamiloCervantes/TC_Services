<?php

namespace Drupal\tc_auth\Service;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Database\Connection;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\Entity\User;

/**
 * Service to manage OTP creation, email delivery, and validation.
 */
class OtpManager {

  use StringTranslationTrait;

  const OTP_EXPIRATION_SECONDS = 900; // 15 minutes
  const MAX_ATTEMPTS = 5;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected MailManagerInterface $mailManager;

  /**
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected FloodInterface $flood;

  /**
   * Constructs an OtpManager object.
   */
  public function __construct(Connection $database, MailManagerInterface $mail_manager, FloodInterface $flood) {
    $this->database = $database;
    $this->mailManager = $mail_manager;
    $this->flood = $flood;
  }

  /**
   * Generates a cryptographically secure 6-digit numeric OTP.
   *
   * @return string
   */
  public function generateOtpCode(): string {
    return str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
  }

  /**
   * Creates, persists, and emails an OTP code to a user.
   *
   * @param string $email
   * @param int $uid
   * @param string $name
   * @param string $purpose
   *
   * @return bool
   */
  public function sendOtp(string $email, int $uid = 0, string $name = 'Usuario', string $purpose = 'register'): bool {
    $code = $this->generateOtpCode();
    $otp_hash = Crypt::hashBase64($code);
    $now = \Drupal::time()->getRequestTime();
    $expires = $now + self::OTP_EXPIRATION_SECONDS;

    // Invalidate existing active OTPs for this email and purpose
    $this->database->delete('tc_auth_otp')
      ->condition('email', $email)
      ->condition('purpose', $purpose)
      ->execute();

    // Insert new OTP record
    $this->database->insert('tc_auth_otp')
      ->fields([
        'email' => $email,
        'uid' => $uid,
        'otp_hash' => $otp_hash,
        'purpose' => $purpose,
        'expires' => $expires,
        'attempts' => 0,
        'created' => $now,
      ])
      ->execute();

    // Send email using Mime Mail and SMTP configured through Mail System
    $params = [
      'name' => $name,
      'otp_code' => $code,
      'expires_minutes' => (int) (self::OTP_EXPIRATION_SECONDS / 60),
    ];

    $result = $this->mailManager->mail(
      'tc_auth',
      'otp_verification',
      $email,
      'es',
      $params,
      NULL,
      TRUE
    );

    return !empty($result['result']);
  }

  /**
   * Verifies an OTP code against stored hash with flood protection.
   *
   * @param string $email
   * @param string $code
   * @param string $purpose
   *
   * @return array
   *   Array with 'valid' (bool), 'message' (string), and 'uid' (int|null).
   */
  public function verifyOtp(string $email, string $code, string $purpose = 'register'): array {
    $flood_event = 'tc_auth_otp_' . $email;
    if (!$this->flood->isAllowed($flood_event, self::MAX_ATTEMPTS, 900)) {
      return [
        'valid' => FALSE,
        'message' => $this->t('Demasiados intentos fallidos. Por favor espera 15 minutos e intenta nuevamente.'),
        'uid' => NULL,
      ];
    }

    $now = \Drupal::time()->getRequestTime();
    $record = $this->database->select('tc_auth_otp', 'o')
      ->fields('o')
      ->condition('email', $email)
      ->condition('purpose', $purpose)
      ->condition('expires', $now, '>')
      ->execute()
      ->fetchObject();

    if (!$record) {
      $this->flood->register($flood_event, 900);
      return [
        'valid' => FALSE,
        'message' => $this->t('El código ingresado es inválido o ha expirado.'),
        'uid' => NULL,
      ];
    }

    $provided_hash = Crypt::hashBase64(trim($code));
    if (!hash_equals($record->otp_hash, $provided_hash)) {
      $this->flood->register($flood_event, 900);
      $this->database->update('tc_auth_otp')
        ->expression('attempts', 'attempts + 1')
        ->condition('id', $record->id)
        ->execute();

      return [
        'valid' => FALSE,
        'message' => $this->t('Código de verificación incorrecto.'),
        'uid' => NULL,
      ];
    }

    // OTP is valid: clear flood and remove used OTP
    $this->flood->clear($flood_event);
    $this->database->delete('tc_auth_otp')
      ->condition('id', $record->id)
      ->execute();

    return [
      'valid' => TRUE,
      'message' => $this->t('Código verificado exitosamente.'),
      'uid' => (int) $record->uid,
    ];
  }

}