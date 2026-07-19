<?php
/**
 * Signed Update Gate — refuses to install a plugin package that is not
 * cryptographically ours.
 *
 * Hooks WordPress's `upgrader_pre_download` filter. When WP is about to install
 * an update for the configured plugin, this downloads the package itself,
 * fetches the `<asset>.manifest.json` sidecar published beside it, and verifies
 * the sha256 + detached Ed25519 signature BEFORE handing WordPress a local file
 * to install. Any failure returns WP_Error and the install aborts — there is no
 * fallback to WordPress's unverified download.
 *
 * ENFORCEMENT MODEL (deliberately stricter than a plain host pin):
 *   - `hook_extra['plugin']` names a different plugin  -> skip (not ours).
 *   - `hook_extra['plugin']` names OUR plugin          -> ENFORCE. An untrusted
 *     host or a missing/invalid manifest is refused, never silently installed.
 *   - no `hook_extra` identity available               -> enforce only when the
 *     URL is on a trusted host (so we never break third-party plugin updates).
 *
 * That middle case is the point. A consumer with no updater of its own (e.g.
 * FormFlow Lite, whose packages are handed to it by the license-server
 * mu-plugin) has NO other control in the path: if this gate skipped on an
 * untrusted host, an attacker who influenced the update source would simply be
 * installed. Transport trust is not authenticity.
 *
 * @package Peanut\FormCore
 * @since 0.2.0
 */

namespace Peanut\FormCore\Update;

if (!defined('ABSPATH')) {
    exit;
}

final class SignedUpdateGate
{
    /** @var string Plugin basename, e.g. "formflow-lite/formflow-lite.php". */
    private string $pluginFile;

    /** @var array<string> Bare hostnames packages may be served from. */
    private array $trustedHosts;

    /** @var string Base64 Ed25519 public key. */
    private string $publicKey;

    /** @var string Text domain for translated failure messages. */
    private string $textDomain;

    /**
     * @param string        $pluginFile   Plugin basename (plugin_basename(__FILE__)).
     * @param array<string> $trustedHosts Bare hostnames, e.g. ['peanutgraphic.com','github.com'].
     * @param string        $publicKey    Base64 Ed25519 public key.
     * @param string        $textDomain   Text domain for messages.
     */
    public function __construct(string $pluginFile, array $trustedHosts, string $publicKey, string $textDomain = 'default')
    {
        $this->pluginFile   = $pluginFile;
        $this->trustedHosts = $trustedHosts;
        $this->publicKey    = $publicKey;
        $this->textDomain   = $textDomain;
    }

    /**
     * Register the gate with WordPress.
     */
    public function register(): void
    {
        add_filter('upgrader_pre_download', [$this, 'verifyPackageSignature'], 10, 4);
    }

    /**
     * `upgrader_pre_download` filter callback.
     *
     * @param mixed  $reply     Short-circuit value (false = let WP download).
     * @param string $package   Package URL WP is about to download.
     * @param mixed  $upgrader  WP_Upgrader instance (unused).
     * @param array  $hookExtra Contextual info; may carry 'plugin'.
     * @return mixed Local verified file path, WP_Error, or $reply to stand aside.
     */
    public function verifyPackageSignature($reply, $package, $upgrader = null, $hookExtra = [])
    {
        if (!is_string($package) || $package === '') {
            return $reply;
        }

        $named  = is_array($hookExtra) && !empty($hookExtra['plugin']) ? (string) $hookExtra['plugin'] : '';
        $isOurs = $named !== '' && $named === $this->pluginFile;

        // Explicitly some other plugin — stand aside.
        if ($named !== '' && !$isOurs) {
            return $reply;
        }

        $trusted = PackageVerifier::isTrustedPackageUrl($package, $this->trustedHosts);

        // Not identifiably ours and not on one of our hosts — stand aside rather
        // than interfere with unrelated updates.
        if (!$isOurs && !$trusted) {
            return $reply;
        }

        // Identified as ours but served from somewhere we don't trust: refuse.
        if (!$trusted) {
            return $this->error(__('Update package came from an untrusted host — refusing to install.', $this->textDomain));
        }

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return $this->error(__('Signature verification unavailable (libsodium) — refusing to install.', $this->textDomain));
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $zip = download_url($package);
        if (is_wp_error($zip)) {
            return $zip;
        }

        $manifest = $this->fetchManifest($package);
        if ($manifest === null) {
            @unlink($zip);
            return $this->error(__('Update signature manifest missing — refusing to install an unsigned package.', $this->textDomain));
        }

        $bytes = (string) file_get_contents($zip);

        if (!PackageVerifier::verifyBytes($bytes, $manifest, $this->publicKey)) {
            @unlink($zip);
            return $this->error(__('Update package failed signature verification — refusing to install.', $this->textDomain));
        }

        return $zip; // Verified — WordPress installs from this local file.
    }

    /**
     * Fetch and decode the manifest published beside the package.
     *
     * @param string $package
     * @return array|null Decoded manifest, or null when absent/unusable.
     */
    private function fetchManifest(string $package): ?array
    {
        $response = wp_remote_get($package . '.manifest.json', ['timeout' => 20]);
        $manifest = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($manifest) || empty($manifest['sha256']) || empty($manifest['signature'])) {
            return null;
        }

        return $manifest;
    }

    /**
     * @param string $message
     * @return \WP_Error
     */
    private function error(string $message)
    {
        return new \WP_Error('peanut_update_signature', $message);
    }
}
