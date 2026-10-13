<?php

namespace Tests\Feature\Reliability;

use App\Events\OutboxEventBroadcast;
use App\Jobs\PublishOutboxEvents;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Models\User;
use App\Reliability\EventRecorder;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    use RefreshDatabase;

    private EventRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([PublishOutboxEvents::class]);
        $this->recorder = app(EventRecorder::class);
    }

    public function test_each_stream_gets_its_own_gapless_sequence()
    {
        [$a, $b] = Project::factory()->count(2)->create();

        foreach (range(1, 3) as $_) {
            $this->recorder->record('test.event', [], $a);
        }
        $this->recorder->record('test.event', [], $b);

        $this->assertSame([1, 2, 3], OutboxEvent::where('project_id', $a->id)->orderBy('sequence')->pluck('sequence')->all());
        $this->assertSame([1], OutboxEvent::where('project_id', $b->id)->pluck('sequence')->all());
        $this->assertSame($a->organization_id, OutboxEvent::where('project_id', $a->id)->value('organization_id'));
    }

    public function test_a_rolled_back_state_change_leaves_no_event_and_no_sequence_gap()
    {
        $project = Project::factory()->create();

        try {
            DB::transaction(function () use ($project) {
                $this->recorder->record('test.event', [], $project);

                throw new RuntimeException('state change failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, OutboxEvent::count());

        $this->recorder->record('test.event', [], $project);
        $this->assertSame(1, OutboxEvent::sole()->sequence);
    }

    public function test_catch_up_api_replays_events_after_a_sequence_cursor()
    {
        $user = User::factory()->memberOf($organization = Organization::factory()->create())->create();
        $project = Project::factory()->for($organization)->create();

        foreach (range(1, 5) as $i) {
            $this->recorder->record('test.event', ['n' => $i], $project);
        }

        $this->actingAs($user)
            ->getJson("/api/projects/{$project->slug}/events?after=2&limit=2")
            ->assertOk()
            ->assertJsonPath('data.0.sequence', 3)
            ->assertJsonPath('data.1.sequence', 4)
            ->assertJsonPath('cursor', 4)
            ->assertJsonPath('has_more', true);

        $this->actingAs($user)
            ->getJson("/api/projects/{$project->slug}/events?after=4")
            ->assertJsonPath('data.0.payload.n', 5)
            ->assertJsonPath('has_more', false);

        $this->actingAs($user)
            ->getJson("/api/projects/{$project->slug}/events?after=5")
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('cursor', 5);
    }

    public function test_organization_feed_never_includes_other_tenants()
    {
        $user = User::factory()->memberOf($organization = Organization::factory()->create())->create();
        $this->recorder->record('mine', [], Project::factory()->for($organization)->create());
        $this->recorder->record('theirs', [], Project::factory()->create());

        $this->actingAs($user)
            ->getJson('/api/events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'mine');
    }

    public function test_publisher_broadcasts_in_order_and_marks_events_sent()
    {
        Event::fake([OutboxEventBroadcast::class]);
        $project = Project::factory()->create();
        $this->recorder->record('first', [], $project);
        $this->recorder->record('second', [], $project);

        (new PublishOutboxEvents)->handle();

        $this->assertSame(0, OutboxEvent::whereNull('published_at')->count());
        Event::assertDispatchedTimes(OutboxEventBroadcast::class, 2);
    }

    public function test_catch_up_polls_never_write_the_session()
    {
        $user = User::factory()->memberOf(Organization::factory()->create())->create();
        $handler = new class extends ArraySessionHandler
        {
            public int $writes = 0;

            public function __construct()
            {
                parent::__construct(120);
            }

            public function write($sessionId, $data): bool
            {
                $this->writes++;

                return parent::write($sessionId, $data);
            }
        };
        $this->app['session']->driver()->setHandler($handler);

        // A poll racing a form post must not save its stale session over the post's flash message.
        $this->actingAs($user)->getJson('/api/events')->assertOk();
        $this->assertSame(0, $handler->writes);
        $this->assertSame($handler, $this->app['session']->driver()->getHandler());

        $this->actingAs($user)->get('/command-center');
        $this->assertSame(1, $handler->writes);
    }

    public function test_publishing_runs_on_the_dedicated_realtime_queue()
    {
        $this->recorder->record('first', [], Project::factory()->create());

        Queue::assertPushedOn('realtime', PublishOutboxEvents::class);
    }

    public function test_publishing_never_rewrites_when_the_event_occurred()
    {
        Event::fake([OutboxEventBroadcast::class]);
        $this->travelTo(now()->subMinutes(5));
        $this->recorder->record('first', [], Project::factory()->create());
        $occurredAt = OutboxEvent::sole()->occurred_at->toIso8601String();
        $this->travelBack();

        (new PublishOutboxEvents)->handle();

        $event = OutboxEvent::sole();
        $this->assertSame($occurredAt, $event->occurred_at->toIso8601String());
        $this->assertTrue($event->published_at->greaterThan($event->occurred_at));
    }

    public function test_publisher_failure_keeps_events_for_the_next_run()
    {
        $this->app->bind(BroadcastFactory::class, fn () => throw new RuntimeException('Reverb down'));

        $project = Project::factory()->create();
        $this->recorder->record('first', [], $project);
        $this->recorder->record('second', [], $project);

        (new PublishOutboxEvents)->handle();

        $first = OutboxEvent::where('type', 'first')->sole();
        $this->assertNull($first->published_at);
        $this->assertSame(1, $first->publish_attempts);
        $this->assertStringContainsString('Reverb down', $first->last_publish_error);

        // Stops at the first failure so nothing is delivered out of order.
        $this->assertSame(0, OutboxEvent::where('type', 'second')->sole()->publish_attempts);
    }
}
