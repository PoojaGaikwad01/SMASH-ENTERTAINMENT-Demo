<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Branch;
use App\Models\DeviceToken;
use App\Models\Lead;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Phase6RemindersReportsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        config(['leads.reminder_after_minutes' => 60, 'leads.firebase.credentials_path' => null]);
        $this->branch = Branch::factory()->create(['name' => 'Andheri']);
        $this->admin = User::factory()->admin()->create();
        $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
        User::factory()->inactive()->create(['branch_id' => $this->branch->id]); // never reminded
    }

    private function pending(array $attrs = []): Lead
    {
        return Lead::factory()->create($attrs + ['branch_id' => $this->branch->id, 'status' => LeadStatus::New, 'assigned_at' => now()->subHours(2)]);
    }

    /** Throwaway RSA service account (Windows PHP needs an explicit openssl config to generate keys). */
    private function firebaseCredentials(): array
    {
        $cnf = tempnam(sys_get_temp_dir(), 'cnf');
        file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n");
        $key = openssl_pkey_new(['config' => $cnf, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem, null, ['config' => $cnf]);

        $path = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($path, json_encode(['project_id' => 'fm-test', 'client_email' => 'push@fm-test.iam.gserviceaccount.com',
            'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']));

        return [$path, openssl_pkey_get_details($key)['key']];
    }

    public function test_device_tokens_register_move_and_delete(): void
    {
        $this->postJson('/device-tokens', ['token' => 'tok-1'])->assertUnauthorized();

        $this->actingAs($this->manager)->postJson('/device-tokens', ['token' => 'tok-1', 'platform' => 'android'])->assertCreated();
        $this->actingAs($this->manager)->postJson('/device-tokens', ['token' => 'tok-1', 'platform' => 'android'])->assertOk();
        $this->actingAs($this->manager)->postJson('/device-tokens', ['token' => 'tok-2', 'platform' => 'fax'])->assertUnprocessable();

        $this->actingAs($this->admin)->postJson('/device-tokens', ['token' => 'tok-1', 'platform' => 'web'])->assertOk();
        $this->assertSame([$this->admin->id, 'web'], [DeviceToken::sole()->user_id, DeviceToken::sole()->platform]);

        $this->actingAs($this->admin)->deleteJson('/device-tokens', ['token' => 'tok-1'])->assertNoContent();
        $this->assertSame(0, DeviceToken::count());
    }

    public function test_admin_sends_reminder_to_active_branch_managers(): void
    {
        $lead = $this->pending(['name' => 'Kavya Iyer']);

        $this->actingAs($this->admin)->get("/inbox/{$lead->id}")->assertSee('Send reminder');
        $this->actingAs($this->admin)->post("/leads/{$lead->id}/remind")->assertSessionHas('status', 'Reminder sent to 1 staff member(s).');

        $this->assertSame($this->manager->id, Reminder::sole()->sent_to_user_id);
        $notification = $this->manager->notifications()->sole();
        $this->assertSame([$lead->id, 'Reply pending: Kavya Iyer'], [$notification->data['lead_id'], $notification->data['title']]);

        $replied = $this->pending(['status' => LeadStatus::Contacted]);
        $this->actingAs($this->admin)->post("/leads/{$replied->id}/remind")->assertSessionHasErrors('lead');

        $orphan = Lead::factory()->create(['branch_id' => Branch::factory()->create()->id, 'status' => LeadStatus::New]);
        $this->actingAs($this->admin)->post("/leads/{$orphan->id}/remind")->assertSessionHasErrors('lead');

        $this->actingAs($this->manager)->post("/leads/{$lead->id}/remind")->assertForbidden();
    }

    public function test_scheduled_command_reminds_only_overdue_pending_leads_once_an_hour(): void
    {
        $due = $this->pending();
        $this->pending(['assigned_at' => now()->subMinutes(10)]);                                  // too fresh
        $recent = $this->pending(['assigned_at' => now()->subHours(3)]);
        Reminder::create(['lead_id' => $recent->id, 'sent_to_user_id' => $this->manager->id, 'sent_at' => now()->subMinutes(30)]); // reminded <1h ago
        $stale = $this->pending(['assigned_at' => now()->subHours(5)]);
        Reminder::create(['lead_id' => $stale->id, 'sent_to_user_id' => $this->manager->id, 'sent_at' => now()->subHours(2)]);
        $this->pending(['status' => LeadStatus::Contacted]);                                         // already replied

        $this->artisan('leads:send-reminders')->expectsOutput('Reminders sent for 2 lead(s).')->assertSuccessful();
        $this->assertSame(1, $due->reminders()->count());
        $this->assertSame(2, $stale->reminders()->count());
        $this->assertSame(1, $recent->reminders()->count());

        $this->artisan('leads:send-reminders')->expectsOutput('Reminders sent for 0 lead(s).');

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'leads:send-reminders'));
        $this->assertSame('*/30 * * * *', $event->expression);
    }

    public function test_in_app_bell_only_when_no_firebase_credentials(): void
    {
        Http::fake();
        $this->manager->deviceTokens()->create(['token' => 'phone-1', 'platform' => 'android']);
        $lead = $this->pending(['name' => 'Manish Gupta']);

        $this->artisan('leads:send-reminders');
        Http::assertNothingSent();

        $this->actingAs($this->manager)->get('/inbox')->assertSee('Reply pending: Manish Gupta');
        $this->actingAs($this->manager)->getJson('/notifications/unread-count')->assertExactJson(['count' => 1]);

        $id = $this->manager->notifications()->value('id');
        $this->actingAs($this->manager)->get("/notifications/{$id}")->assertRedirect(route('inbox.show', $lead));
        $this->actingAs($this->manager)->getJson('/notifications/unread-count')->assertExactJson(['count' => 0]);

        $this->actingAs($this->admin)->get("/notifications/{$id}")->assertNotFound(); // someone else's notification
    }

    public function test_fcm_push_in_live_mode_signs_jwt_and_prunes_dead_tokens(): void
    {
        [$path, $publicKey] = $this->firebaseCredentials();
        config(['leads.mode' => 'live', 'leads.firebase.credentials_path' => $path]);
        Cache::forget('fcm.access_token');
        $this->manager->deviceTokens()->createMany([['token' => 'good-token'], ['token' => 'dead-token']]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => fn (HttpRequest $r) => $r['message']['token'] === 'dead-token'
                ? Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)
                : Http::response(['name' => 'projects/fm-test/messages/1']),
        ]);

        $this->actingAs($this->admin)->post("/leads/{$this->pending()->id}/remind")->assertSessionHasNoErrors();

        Http::assertSent(function (HttpRequest $r) use ($publicKey) {
            if (! str_contains($r->url(), 'oauth2.googleapis.com')) {
                return false;
            }
            [$h, $p, $s] = explode('.', $r['assertion']);
            $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);

            return $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && $claims['iss'] === 'push@fm-test.iam.gserviceaccount.com'
                && openssl_verify("{$h}.{$p}", base64_decode(strtr($s, '-_', '+/')), $publicKey, OPENSSL_ALGO_SHA256) === 1;
        });
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://fcm.googleapis.com/v1/projects/fm-test/messages:send'
            && $r->hasHeader('Authorization', 'Bearer ya29.test')
            && $r['message']['token'] === 'good-token'
            && str_starts_with($r['message']['notification']['title'], 'Reply pending'));

        $this->assertSame(['good-token'], $this->manager->deviceTokens()->pluck('token')->all());
        $this->assertSame(1, $this->manager->notifications()->count()); // bell still gets it
    }

    public function test_mock_mode_never_calls_fcm_even_with_credentials(): void
    {
        [$path] = $this->firebaseCredentials();
        config(['leads.firebase.credentials_path' => $path]);
        Http::fake();
        $this->manager->deviceTokens()->create(['token' => 'phone-1']);

        $this->actingAs($this->admin)->post("/leads/{$this->pending()->id}/remind")->assertSessionHasNoErrors();

        Http::assertNothingSent();
        $this->assertSame(1, $this->manager->notifications()->count());
    }

    private function reportData(): void
    {
        $other = Branch::factory()->create(['name' => 'Pune Camp']);
        Lead::factory()->create(['source' => LeadSource::FbLeadAd, 'branch_id' => $this->branch->id, 'status' => LeadStatus::Contacted,
            'assigned_at' => now()->subHours(3), 'replied_at' => now()->subHours(2)]);                                        // 60m
        Lead::factory()->create(['source' => LeadSource::WhatsappCustomer, 'branch_id' => $this->branch->id, 'status' => LeadStatus::Contacted,
            'assigned_at' => now()->subHours(5), 'replied_at' => now()->subHours(5)->addMinutes(30), 'name' => '=HYPERLINK("x")', 'phone' => '9812345603']); // 30m
        Lead::factory()->create(['source' => LeadSource::WhatsappCustomer, 'branch_id' => $other->id, 'status' => LeadStatus::New, 'assigned_at' => now()->subHour()]);
        Lead::factory()->create(['source' => LeadSource::Messenger]);
        Lead::factory()->create(['source' => LeadSource::Messenger, 'created_at' => now()->subDays(40)]);                 // outside default 30 days
    }

    public function test_reports_counts_by_source_branch_status_and_average_reply_time(): void
    {
        $this->reportData();

        $view = $this->actingAs($this->admin)->get('/reports')->assertOk()->assertSee('45m');
        $totals = $view->viewData('totals');
        $this->assertSame([4, 1, 2, 45.0], [$totals['total'], $totals['pending'], $totals['replied'], (float) $totals['avg_reply']]);
        $this->assertSame(2, $view->viewData('bySource')['WhatsApp']['total']);
        $this->assertSame(1, $view->viewData('bySource')['Facebook Lead Ad']['replied']);
        $this->assertEquals(45, $view->viewData('byBranch')['Andheri']['avg_reply']);
        $this->assertSame(1, $view->viewData('byBranch')['Unassigned']['total']);
        $this->assertSame(2, $view->viewData('byStatus')['Contacted']);

        $old = $this->actingAs($this->admin)->get('/reports?from='.now()->subDays(41)->toDateString().'&to='.now()->subDays(39)->toDateString());
        $this->assertSame(1, $old->viewData('totals')['total']);
        $this->assertSame(1, $this->actingAs($this->admin)->get('/reports?source=messenger')->viewData('totals')['total']);

        // Branch Manager: own branch only, no branch table.
        $mine = $this->actingAs($this->manager)->get('/reports?branch_id=999')->assertOk();
        $this->assertSame(2, $mine->viewData('totals')['total']);
        $this->assertTrue($mine->viewData('byBranch')->isEmpty());
    }

    public function test_csv_export_is_scoped_and_formula_safe(): void
    {
        $this->reportData();

        $response = $this->actingAs($this->admin)->get('/reports/export')->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFID,Received,Source,Status,Name,Phone", $csv);
        $this->assertSame(5, substr_count(trim($csv), "\n") + 1); // header + 4 leads in range
        $this->assertStringContainsString("\"'=HYPERLINK(\"\"x\"\")\",'+919812345603", $csv);
        $this->assertStringContainsString('Andheri', $csv);

        $mine = $this->actingAs($this->manager)->get('/reports/export')->streamedContent();
        $this->assertSame(3, substr_count(trim($mine), "\n") + 1); // header + 2 Andheri leads
        $this->assertStringNotContainsString('Pune Camp', $mine);
    }
}
