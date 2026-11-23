<?php

namespace Tests\Feature;

use App\Console\Commands\ReplayMetaFixtures;
use App\Models\Lead;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaLiveTestingTest extends TestCase
{
    use RefreshDatabase;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = tempnam(sys_get_temp_dir(), 'meta').'.log';
        config(['logging.channels.meta.path' => $this->log, 'leads.meta.app_secret' => 'app-secret', 'leads.meta.verify_token' => 'verify-me']);
    }

    private function metaLog(): string
    {
        return is_file($this->log) ? file_get_contents($this->log) : '';
    }

    private function postSigned(string $fixture, ?string $signature = null)
    {
        $body = json_encode(ReplayMetaFixtures::load(base_path("tests/fixtures/meta/{$fixture}.json")));
        $signature ??= 'sha256='.hash_hmac('sha256', $body, 'app-secret');

        return $this->call('POST', '/webhooks/meta', [], [], [],
            $this->transformHeadersToServerVars(['X-Hub-Signature-256' => $signature, 'Content-Type' => 'application/json']), $body);
    }

    public function test_verify_returns_challenge_as_plain_text_and_logs(): void
    {
        $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=1158201444')
            ->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertContent('1158201444');
        $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=1')->assertForbidden();

        $this->assertStringContainsString('Webhook verified', $this->metaLog());
        $this->assertStringContainsString('Webhook verification failed', $this->metaLog());
    }

    public function test_receive_logs_ids_only_and_rejects_bad_signature(): void
    {
        $this->postSigned('whatsapp_text', 'sha256=forged')->assertForbidden();
        $this->assertSame(0, Lead::count());
        $this->assertStringContainsString('invalid X-Hub-Signature-256', $this->metaLog());

        $this->postSigned('whatsapp_text')->assertOk()->assertContent('EVENT_RECEIVED'); // queue is sync in tests

        $log = $this->metaLog();
        $this->assertStringContainsString('"object":"whatsapp_business_account"', $log);
        $this->assertStringContainsString('wamid.MOCK_CUSTOMER_TEXT_0001', $log);
        $this->assertStringContainsString('Webhook processed', $log);
        $this->assertStringNotContainsString('Is the play area open', $log); // no message content
        $this->assertStringNotContainsString('Amit Shah', $log);           // no names
        $this->assertSame(1, Lead::where('source', 'whatsapp_customer')->count());
    }

    public function test_status_events_are_ignored_safely(): void
    {
        $this->postSigned('whatsapp_status')->assertOk();

        $this->assertSame([0, 0], [Lead::count(), Message::count()]);
        $this->assertStringContainsString('"statuses":1', $this->metaLog());
    }

    public function test_send_test_command_calls_cloud_api_and_prints_response(): void
    {
        config(['leads.whatsapp.phone_number_id' => null]);
        $this->artisan('meta:send-test 919812345603')->expectsOutputToContain('Set WA_PHONE_NUMBER_ID')->assertFailed();

        config(['leads.whatsapp.phone_number_id' => 'PNID', 'leads.whatsapp.access_token' => 'WA_TOKEN', 'leads.meta.graph_version' => 'v23.0']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.SENT1']]])]);

        $this->artisan('meta:send-test', ['to' => '+91 98123 45603', 'message' => 'Hi there'])
            ->expectsOutputToContain('wamid.SENT1')->assertSuccessful();
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://graph.facebook.com/v23.0/PNID/messages'
            && $r->hasHeader('Authorization', 'Bearer WA_TOKEN')
            && $r['to'] === '919812345603' && $r['type'] === 'text' && $r['text']['body'] === 'Hi there');

        $this->artisan('meta:send-test 919812345603 --template=hello_world')->assertSuccessful();
        Http::assertSent(fn (HttpRequest $r) => ($r['type'] ?? null) === 'template' && $r['template']['name'] === 'hello_world' && $r['template']['language']['code'] === 'en_US');
    }

    public function test_send_test_command_fails_on_api_error(): void
    {
        config(['leads.whatsapp.phone_number_id' => 'PNID', 'leads.whatsapp.access_token' => 'WA_TOKEN']);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Recipient phone number not in allowed list', 'code' => 131030]], 400)]);

        $this->artisan('meta:send-test 919812345603')->expectsOutputToContain('131030')->assertFailed();
    }

    public function test_simulate_webhook_signs_the_exact_body(): void
    {
        Http::fake(['*' => Http::response('EVENT_RECEIVED')]);

        $this->artisan('meta:simulate-webhook whatsapp_text --url=https://example.test/webhooks/meta')->assertSuccessful();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://example.test/webhooks/meta'
            && $r->header('X-Hub-Signature-256')[0] === 'sha256='.hash_hmac('sha256', $r->body(), 'app-secret')
            && json_decode($r->body(), true)['object'] === 'whatsapp_business_account');

        $this->artisan('meta:simulate-webhook nope')->expectsOutputToContain('Fixture not found')->assertFailed();
    }
}
