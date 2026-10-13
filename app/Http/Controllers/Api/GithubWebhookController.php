<?php

namespace App\Http\Controllers\Api;

use App\Github\GithubRepositoryService;
use App\Http\Controllers\Controller;
use App\Models\GithubPullRequest;
use App\Models\GithubRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GithubWebhookController extends Controller
{
    public function __invoke(Request $request, GithubRepositoryService $repositories): JsonResponse
    {
        $secret = (string) config('services.github.webhook_secret');
        $signature = (string) $request->header('X-Hub-Signature-256');
        if ($secret === '' || ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            abort(401, 'Invalid GitHub webhook signature.');
        }

        $delivery = (string) $request->header('X-GitHub-Delivery');
        if (! preg_match('/^[A-Za-z0-9-]{1,100}$/', $delivery)) {
            abort(422, 'Missing GitHub delivery id.');
        }
        if (DB::table('github_webhook_deliveries')->where('delivery_id', $delivery)->exists()) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $event = (string) $request->header('X-GitHub-Event');
        $payload = $request->json()->all();
        $fullName = (string) data_get($payload, 'repository.full_name');
        $number = (int) data_get($payload, 'pull_request.number', 0);
        $repository = GithubRepository::where('full_name', $fullName)->first();

        DB::transaction(function () use ($delivery, $event, $payload, $fullName, $number, $repository, $repositories) {
            if ($repository && in_array($event, ['pull_request', 'pull_request_review'], true) && $number > 0) {
                $pull = $repositories->syncPullRequest($repository, (array) $payload['pull_request'], GithubPullRequest::where('github_repository_id', $repository->id)->where('github_number', $number)->first()?->task);
                if ($event === 'pull_request_review' && isset($payload['review'])) {
                    $repositories->syncReviews($pull, [(array) $payload['review']]);
                }
            }

            DB::table('github_webhook_deliveries')->insert([
                'delivery_id' => $delivery,
                'event' => $event,
                'action' => $payload['action'] ?? null,
                'repository_full_name' => $fullName ?: null,
                'pull_request_number' => $number ?: null,
                'processed_at' => now(),
            ]);
        });

        return response()->json(['ok' => true]);
    }
}
