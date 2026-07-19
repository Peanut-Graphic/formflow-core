<?php
/**
 * Encryption-key configuration status + the admin notice that surfaces it.
 *
 * Shared because it was a TIER INVERSION: FormFlow Lite (free) warned admins
 * when the encryption key was missing or too short and offered a generated key
 * to paste into wp-config.php — and FormFlow Pro (paid) did not. Paying users
 * got LESS safety guidance about their own data-at-rest key than free users,
 * and could sit silently on the wp_salt fallback forever.
 *
 * Pro must be a superset of Lite. Putting this in the shared core fixes the
 * inversion and removes the duplicate in one move, rather than copying Lite's
 * version into Pro and creating a third fork to keep in sync.
 *
 * @package Peanut\FormCore
 * @since 0.4.0
 */

namespace Peanut\FormCore\Crypto;

if (!defined('ABSPATH')) {
    exit;
}

final class EncryptionKeyNotice
{
    /** Minimum key length for AES-256. */
    public const MIN_KEY_LENGTH = 32;

    private string $keyConstant;
    private string $brand;
    private string $textDomain;
    /** @var array<string> Screen ids the notice may appear on. */
    private array $screens;
    private string $screenNeedle;

    /**
     * @param string        $keyConstant  e.g. 'ISF_ENCRYPTION_KEY'.
     * @param string        $brand        Human label, e.g. 'FormFlow'.
     * @param string        $textDomain   Text domain for translations.
     * @param array<string> $screens      Exact admin screen ids to show on.
     * @param string        $screenNeedle Substring match for plugin screens.
     */
    public function __construct(
        string $keyConstant,
        string $brand,
        string $textDomain,
        array $screens = ['plugins'],
        string $screenNeedle = ''
    ) {
        $this->keyConstant  = $keyConstant;
        $this->brand        = $brand;
        $this->textDomain   = $textDomain;
        $this->screens      = $screens;
        $this->screenNeedle = $screenNeedle;
    }

    /**
     * Status of the configured key: ok | warning | error.
     *
     * Kept static + constant-name-driven so it is testable without WordPress.
     *
     * @param string $keyConstant
     * @param string $textDomain
     * @return array{status:string,message:string,code:string}
     */
    public static function keyStatus(string $keyConstant, string $textDomain = 'default'): array
    {
        if (!defined($keyConstant)) {
            return [
                'status'  => 'warning',
                'message' => sprintf(
                    /* translators: %s: PHP constant name */
                    __('%s is not defined. Using WordPress auth salt as fallback. For better security, add a custom encryption key to wp-config.php.', $textDomain),
                    $keyConstant
                ),
                'code'    => 'key_not_defined',
            ];
        }

        if (strlen((string) constant($keyConstant)) < self::MIN_KEY_LENGTH) {
            return [
                'status'  => 'error',
                'message' => sprintf(
                    /* translators: 1: PHP constant name, 2: minimum length */
                    __('%1$s is too short. It must be at least %2$d characters for AES-256 encryption.', $textDomain),
                    $keyConstant,
                    self::MIN_KEY_LENGTH
                ),
                'code'    => 'key_too_short',
            ];
        }

        return [
            'status'  => 'ok',
            'message' => __('Custom encryption key is properly configured.', $textDomain),
            'code'    => 'key_ok',
        ];
    }

    /**
     * Generate a key suitable for the encryption constant.
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Hook the notice into the admin.
     */
    public function register(): void
    {
        add_action('admin_notices', [$this, 'display']);
    }

    /**
     * Render the notice when the key is missing or too weak.
     */
    public function display(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!$this->onRelevantScreen()) {
            return;
        }

        $status = self::keyStatus($this->keyConstant, $this->textDomain);
        if ($status['status'] === 'ok') {
            return;
        }

        $class = $status['status'] === 'error' ? 'notice-error' : 'notice-warning';
        $sample = self::generateKey();

        printf(
            '<div class="notice %1$s is-dismissible"><p><strong>%2$s</strong> %3$s</p><p>%4$s</p><p><code>%5$s</code></p><p><em>%6$s</em></p></div>',
            esc_attr($class),
            esc_html(sprintf(__('%s Security Notice:', $this->textDomain), $this->brand)),
            esc_html($status['message']),
            esc_html__('Add the following line to your wp-config.php file:', $this->textDomain),
            esc_html(sprintf("define('%s', '%s');", $this->keyConstant, $sample)),
            esc_html__('Note: once set, do not change this key or encrypted data will become unreadable.', $this->textDomain)
        );
    }

    /**
     * Only surface on the plugins screen or this plugin's own screens.
     */
    private function onRelevantScreen(): bool
    {
        if (!function_exists('get_current_screen')) {
            return true;
        }
        $screen = get_current_screen();
        if (!$screen || empty($screen->id)) {
            return true;
        }
        if (in_array($screen->id, $this->screens, true)) {
            return true;
        }
        return $this->screenNeedle !== '' && strpos($screen->id, $this->screenNeedle) !== false;
    }
}
