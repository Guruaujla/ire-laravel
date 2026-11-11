<?php

declare(strict_types=1);

namespace Tests\Example\BulkOnboarding;

use App\Models\AccountingLink;
use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Laravel\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Psr\Http\Message\RequestInterface;

// Clean-room tests of the example's large-list onboarding routes (routes/accounting.php),
// driven over HTTP through the testbench app, checked against the runtime HTTP contract
// in openapi/integration-runtime.openapi.yaml. Runs from sdks/laravel.
foreach (['app/Models/AccountingLink.php', 'app/Models/AccountingSync.php', 'app/Models/AccountingDocument.php', 'app/Services/AccountingSyncService.php', 'app/Http/Controllers/AccountingController.php'] as $f) {
    require_once __DIR__ . '/../' . $f;
}

final class IndependentBulkOnboardingTest extends TestCase
{
    private const C = '/v1/accounting/connections/c%201/records/';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    protected function defineRoutes($router): void
    {
        require __DIR__ . '/../routes/accounting.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        (require __DIR__ . '/../database/migrations/2026_10_29_000000_create_accounting_tables.php')->up();
        AccountingLink::create(['workspace_id' => 'w1', 'tenant_id' => 't1', 'connection_id' => 'c 1']);
        $this->actingAs(new GenericUser(['id' => 1, 'current_team_id' => 'w1']));
    }

    /** @return array<string, mixed> */
    private static function snapshot(string $status = 'running'): array
    {
        return ['snapshot_id' => 'snap_1', 'entity' => 'customer', 'status' => $status, 'job_id' => 'job_9', 'record_count' => 0, 'provider_calls' => 1, 'waiting_on' => null, 'as_of' => null, 'expires_at' => null, 'created_at' => '2026-11-11T00:00:00Z', 'error_code' => null];
    }

    /** @return array<string, mixed> */
    private static function bindJob(string $status = 'pending'): array
    {
        return ['job_id' => 'job_7', 'entity' => 'item', 'status' => $status, 'row_count' => 1, 'bound_count' => 0, 'refused_count' => 0, 'not_attempted_count' => 1, 'provider_calls' => 0, 'waiting_on' => null, 'error_code' => null, 'created_at' => '2026-11-11T00:00:00Z', 'ended_at' => null];
    }

    /** @return array<string, mixed> */
    private static function matchBody(): array
    {
        return ['results' => [], 'unsupported_keys' => [], 'source' => 'live', 'as_of' => null, 'snapshot_id' => null];
    }

    private static function target(RequestInterface $r): string
    {
        $q = $r->getUri()->getQuery();

        return $r->getUri()->getPath() . ($q === '' ? '' : '?' . $q);
    }

    /** @return array<string, string> */
    private static function queryOf(RequestInterface $r): array
    {
        parse_str($r->getUri()->getQuery(), $out);

        return $out;
    }

    /** @return array<mixed> */
    private static function body(RequestInterface $r): array
    {
        return json_decode((string) $r->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    // ------------------------------------------------------------ snapshots

    public function test_start_snapshot_forwards_without_refresh(): void
    {
        $rt = $this->fakeRuntime(self::runtimeJson(202, self::snapshot()));
        $this->postJson('/accounting/records/customer/snapshots')->assertOk()->assertJson(self::snapshot());

        self::assertCount(1, $rt->requests);
        self::assertSame('POST', $rt->requests[0]->getMethod());
        self::assertSame(self::C . 'customer/snapshots', self::target($rt->requests[0]));
        self::assertSame(['tenant_id' => 't1'], self::body($rt->requests[0]));
    }

    public function test_start_snapshot_forwards_refresh_flag(): void
    {
        $rt = $this->fakeRuntime(self::runtimeJson(202, self::snapshot()), self::runtimeJson(202, self::snapshot()));
        $this->postJson('/accounting/records/item/snapshots', ['refresh' => true])->assertOk();
        $this->postJson('/accounting/records/item/snapshots?refresh=1')->assertOk();

        self::assertSame(self::C . 'item/snapshots', self::target($rt->requests[0]));
        self::assertSame(['tenant_id' => 't1', 'refresh' => true], self::body($rt->requests[0]));
        self::assertSame(['tenant_id' => 't1', 'refresh' => true], self::body($rt->requests[1]));
    }

    public function test_latest_snapshot_forwards_get(): void
    {
        $rt = $this->fakeRuntime(self::runtimeJson(200, self::snapshot('ready')));
        $this->getJson('/accounting/records/item/snapshots/latest')->assertOk()->assertJson(self::snapshot('ready'));

        self::assertSame('GET', $rt->requests[0]->getMethod());
        self::assertSame(self::C . 'item/snapshots/latest?tenant_id=t1', self::target($rt->requests[0]));
    }

    // --------------------------------------------------------------- search

    public function test_search_forwards_q_to_the_records_list(): void
    {
        $page = ['records' => [['provider_ref' => '58', 'name' => 'Acme', 'active' => true]], 'next_cursor' => null];
        $rt = $this->fakeRuntime(self::runtimeJson(200, $page), self::runtimeJson(200, $page));
        $this->getJson('/accounting/records/customer/search?q=acme')->assertOk()->assertJson($page);
        $this->getJson('/accounting/records/item/search?q=' . rawurlencode('a@b.example') . '&cursor=n2')->assertOk();

        self::assertSame('GET', $rt->requests[0]->getMethod());
        self::assertSame('/v1/accounting/connections/c%201/records/customer', $rt->requests[0]->getUri()->getPath());
        // No cursor, limit or source unless given: the runtime defaults to the snapshot when q is set.
        self::assertSame(['tenant_id' => 't1', 'q' => 'acme'], self::queryOf($rt->requests[0]));
        self::assertSame('/v1/accounting/connections/c%201/records/item', $rt->requests[1]->getUri()->getPath());
        self::assertSame(['tenant_id' => 't1', 'cursor' => 'n2', 'q' => 'a@b.example'], self::queryOf($rt->requests[1]));
    }

    public function test_search_rejects_bad_input_with_422_and_no_runtime_call(): void
    {
        $rt = $this->fakeRuntime();
        foreach ([
            '/accounting/records/customer/search',
            '/accounting/records/customer/search?q=ab',
            '/accounting/records/customer/search?q=' . str_repeat('a', 255),
            '/accounting/records/customer/search?q=acme&cursor[]=x',
            '/accounting/records/customer/search?q[]=acme',
            '/accounting/records/customer/search?q=acme&cursor=' . str_repeat('c', 513),
        ] as $uri) {
            $this->getJson($uri)->assertStatus(422);
        }
        self::assertSame([], $rt->requests);
    }

    public function test_search_accepts_boundary_lengths(): void
    {
        $page = ['records' => [], 'next_cursor' => null];
        $rt = $this->fakeRuntime(self::runtimeJson(200, $page), self::runtimeJson(200, $page));
        $this->getJson('/accounting/records/customer/search?q=abc')->assertOk();
        $this->getJson('/accounting/records/customer/search?q=' . str_repeat('a', 254))->assertOk();
        self::assertCount(2, $rt->requests);
    }

    // ------------------------------------------------------------ bind jobs

    public function test_start_bind_job_forwards_bindings(): void
    {
        $bindings = [['erp_id' => 'I1', 'provider_ref' => '58']];
        $rt = $this->fakeRuntime(self::runtimeJson(202, self::bindJob()));
        $this->postJson('/accounting/records/item/bind-jobs', ['bindings' => $bindings])->assertOk()->assertJson(self::bindJob());

        self::assertSame('POST', $rt->requests[0]->getMethod());
        self::assertSame(self::C . 'item/bind-jobs', self::target($rt->requests[0]));
        self::assertSame(['tenant_id' => 't1', 'bindings' => $bindings], self::body($rt->requests[0]));
    }

    public function test_start_bind_job_accepts_5000_rows(): void
    {
        $bindings = array_map(static fn(int $i) => ['erp_id' => 'C' . $i, 'provider_ref' => (string) $i], range(1, 5000));
        $rt = $this->fakeRuntime(self::runtimeJson(202, self::bindJob()));
        $this->postJson('/accounting/records/customer/bind-jobs', ['bindings' => $bindings])->assertOk();
        self::assertCount(5000, self::body($rt->requests[0])['bindings']);
    }

    public function test_start_bind_job_rejects_bad_input_with_422(): void
    {
        $rt = $this->fakeRuntime();
        $tooMany = array_map(static fn(int $i) => ['erp_id' => 'C' . $i, 'provider_ref' => (string) $i], range(1, 5001));
        $this->postJson('/accounting/records/customer/bind-jobs', ['bindings' => $tooMany])->assertStatus(422);
        $this->postJson('/accounting/records/customer/bind-jobs', [])->assertStatus(422);
        $this->postJson('/accounting/records/customer/bind-jobs', ['bindings' => 'x'])->assertStatus(422);
        self::assertSame([], $rt->requests);
    }

    public function test_bind_job_in_progress_surfaces_as_a_runtime_problem(): void
    {
        $this->fakeRuntime(self::runtimeJson(409, ['code' => 'accounting.bind_job_in_progress', 'request_id' => 'r', 'job_id' => 'job_1']));
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/accounting/records/customer/bind-jobs', ['bindings' => [['erp_id' => 'C1', 'provider_ref' => '1']]]);
            self::fail('expected a problem');
        } catch (RuntimeProblemException $e) {
            self::assertSame(409, $e->httpStatus);
            self::assertSame('accounting.bind_job_in_progress', $e->problemCode);
        }
    }

    public function test_bind_job_results_forwards_job_and_cursor(): void
    {
        $page = self::bindJob('completed') + ['results' => [], 'next_cursor' => null];
        $rt = $this->fakeRuntime(self::runtimeJson(200, $page), self::runtimeJson(200, $page));
        $this->getJson('/accounting/records/item/bind-jobs/job_7/results')->assertOk()->assertJson($page);
        $this->getJson('/accounting/records/item/bind-jobs/job%207/results?cursor=n2')->assertOk();

        self::assertSame('GET', $rt->requests[0]->getMethod());
        self::assertSame(self::C . 'item/bind-jobs/job_7/results?tenant_id=t1', self::target($rt->requests[0]));
        self::assertSame(self::C . 'item/bind-jobs/job%207/results?tenant_id=t1&cursor=n2', self::target($rt->requests[1]));
    }

    public function test_bind_job_results_rejects_array_or_long_cursor(): void
    {
        $rt = $this->fakeRuntime();
        $this->getJson('/accounting/records/item/bind-jobs/job_7/results?cursor[]=x')->assertStatus(422);
        $this->getJson('/accounting/records/item/bind-jobs/job_7/results?cursor=' . str_repeat('c', 257))->assertStatus(422);
        self::assertSame([], $rt->requests);
    }

    // -------------------------------------------------- entity constraints

    public function test_new_routes_cover_customer_and_item_only(): void
    {
        $rt = $this->fakeRuntime();
        foreach (['tax_code', 'vendor'] as $entity) {
            $this->postJson("/accounting/records/{$entity}/snapshots")->assertNotFound();
            $this->getJson("/accounting/records/{$entity}/snapshots/latest")->assertNotFound();
            $this->getJson("/accounting/records/{$entity}/search?q=acme")->assertNotFound();
            $this->postJson("/accounting/records/{$entity}/bind-jobs", ['bindings' => [['erp_id' => 'X', 'provider_ref' => '1']]])->assertNotFound();
            $this->getJson("/accounting/records/{$entity}/bind-jobs/job_7/results")->assertNotFound();
        }
        self::assertSame([], $rt->requests);
    }

    public function test_routes_require_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $rt = $this->fakeRuntime();
        $this->getJson('/accounting/records/customer/snapshots/latest')->assertUnauthorized();
        self::assertSame([], $rt->requests);
    }

    // ---------------------------------------------------------------- match

    public function test_match_without_source_sends_no_source_field(): void
    {
        $records = [['erp_id' => 'C1', 'name' => 'Acme']];
        $rt = $this->fakeRuntime(self::runtimeJson(200, self::matchBody()));
        $this->postJson('/accounting/records/customer/match', ['records' => $records])->assertOk()->assertJson(self::matchBody());

        self::assertSame('POST', $rt->requests[0]->getMethod());
        self::assertSame(self::C . 'customer/match', self::target($rt->requests[0]));
        self::assertSame(['tenant_id' => 't1', 'records' => $records], self::body($rt->requests[0]));
    }

    public function test_match_forwards_each_source(): void
    {
        $rt = $this->fakeRuntime(self::runtimeJson(200, self::matchBody()), self::runtimeJson(200, self::matchBody()), self::runtimeJson(200, self::matchBody()));
        foreach (['auto', 'snapshot', 'live'] as $source) {
            $this->postJson('/accounting/records/item/match', ['records' => [['erp_id' => 'I1', 'name' => 'X']], 'source' => $source])->assertOk();
        }
        self::assertSame(['auto', 'snapshot', 'live'], array_map(static fn($r) => self::body($r)['source'] ?? null, $rt->requests));
    }

    public function test_match_null_source_is_omitted(): void
    {
        $rt = $this->fakeRuntime(self::runtimeJson(200, self::matchBody()));
        $this->postJson('/accounting/records/customer/match', ['records' => [['erp_id' => 'C1', 'name' => 'A']], 'source' => null])->assertOk();
        self::assertArrayNotHasKey('source', self::body($rt->requests[0]));
    }

    public function test_match_accepts_1000_records_and_rejects_more(): void
    {
        $make = static fn(int $n) => array_map(static fn(int $i) => ['erp_id' => 'C' . $i, 'name' => 'N' . $i], range(1, $n));
        $rt = $this->fakeRuntime(self::runtimeJson(200, self::matchBody()));
        $this->postJson('/accounting/records/customer/match', ['records' => $make(1000), 'source' => 'snapshot'])->assertOk();
        $this->postJson('/accounting/records/customer/match', ['records' => $make(1001)])->assertStatus(422);
        $this->postJson('/accounting/records/customer/match', ['records' => $make(1), 'source' => 'bogus'])->assertStatus(422);
        $this->postJson('/accounting/records/customer/match', [])->assertStatus(422);
        self::assertCount(1, $rt->requests);
        self::assertCount(1000, self::body($rt->requests[0])['records']);
    }

    public function test_match_still_covers_tax_code(): void
    {
        $rt = $this->fakeRuntime(self::runtimeJson(200, self::matchBody()));
        $this->postJson('/accounting/records/tax_code/match', ['records' => [['erp_id' => 'HST', 'name' => 'HST ON']]])->assertOk();
        self::assertSame(self::C . 'tax_code/match', self::target($rt->requests[0]));
    }
}
