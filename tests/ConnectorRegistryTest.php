<?php
namespace Peanut\FormCore\Tests;

use PHPUnit\Framework\TestCase;
use Peanut\FormCore\Api\ConnectorRegistry;
use Peanut\FormCore\Api\ApiConnectorInterface;
use Peanut\FormCore\Api\AccountValidationResult;
use Peanut\FormCore\Api\EnrollmentResult;
use Peanut\FormCore\Api\SchedulingResult;
use Peanut\FormCore\Api\BookingResult;

/**
 * Characterization test for the extracted connector registry + interface —
 * pins the register/resolve contract both FormFlow Pro and Lite relied on, so
 * the shared-core extraction is provably behaviour-preserving.
 */
final class ConnectorRegistryTest extends TestCase
{
    private function fakeConnector(string $id): ApiConnectorInterface
    {
        return new class($id) implements ApiConnectorInterface {
            public function __construct(private string $id) {}
            public function get_id(): string { return $this->id; }
            public function get_name(): string { return 'Fake ' . $this->id; }
            public function get_description(): string { return 'test connector'; }
            public function get_version(): string { return '1.0.0'; }
            public function get_config_fields(): array { return []; }
            public function validate_config(array $config): array { return ['valid' => true]; }
            public function test_connection(array $config): array { return ['success' => true]; }
            public function validate_account(array $data, array $config): AccountValidationResult { return new AccountValidationResult(['is_valid' => true]); }
            public function submit_enrollment(array $form_data, array $config): EnrollmentResult { return new EnrollmentResult(['success' => true]); }
            public function get_schedule_slots(array $data, array $config): SchedulingResult { return new SchedulingResult(['success' => true]); }
            public function book_appointment(array $data, array $config): BookingResult { return new BookingResult(['success' => true]); }
            public function map_fields(array $form_data, string $type = 'enrollment'): array { return $form_data; }
            public function get_supported_features(): array { return ['enrollment']; }
            public function supports(string $feature): bool { return $feature === 'enrollment'; }
            public function get_presets(): array { return []; }
        };
    }

    public function test_register_get_has_unregister(): void
    {
        $reg = ConnectorRegistry::instance();
        $id  = 'acme-' . uniqid();
        $c   = $this->fakeConnector($id);
        $this->assertTrue($reg->register($c), 'register() returns true');
        $this->assertTrue($reg->has($id));
        $this->assertSame($c, $reg->get($id));
        $this->assertArrayHasKey($id, $reg->get_all());
        $this->assertTrue($reg->unregister($id));
        $this->assertFalse($reg->has($id));
        $this->assertNull($reg->get($id));
    }

    public function test_get_missing_returns_null(): void
    {
        $this->assertNull(ConnectorRegistry::instance()->get('nope-' . uniqid()));
    }

    public function test_result_objects_construct_from_array(): void
    {
        $this->assertTrue((new AccountValidationResult(['is_valid' => true]))->is_valid());
        $this->assertTrue((new EnrollmentResult(['success' => true]))->is_successful());
    }
}
