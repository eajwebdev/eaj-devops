<?php

namespace App\Http\Controllers\Api\Runner;

use App\Http\Controllers\Controller;
use App\Models\LocalRunner;
use App\Models\RunnerCommand;
use App\Reliability\SystemHalt;
use App\Runner\Exceptions\InvalidPairingCodeException;
use App\Runner\RunnerGateway;
use App\Runner\RunnerPairing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The EAJ Local Runner API (/api/runner/v1). The runner always calls out;
 * the Studio never connects to a developer machine (Plan §89.2).
 */
class RunnerApiController extends Controller
{
    public function __construct(private readonly RunnerGateway $gateway) {}

    public function pair(Request $request, RunnerPairing $pairing): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
            'name' => ['required', 'string', 'max:120'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'os' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'in:win32,darwin,linux,freebsd,openbsd,sunos,aix'],
            'version' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            ['runner' => $runner, 'token' => $token, 'signing_secret' => $secret] = $pairing->redeem($data['code'], $data, $request->ip());
        } catch (InvalidPairingCodeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'runner_id' => $runner->id,
            'token' => $token,
            'signing_secret' => $secret,
            'organization' => $runner->organization->name,
        ], 201);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version' => ['nullable', 'string', 'max:20'],
            'allowed_roots' => ['nullable', 'array', 'max:50'],
            'allowed_roots.*' => ['string', 'max:1024'],
            'workspaces_root' => ['nullable', 'string', 'max:1024'],
            'capabilities' => ['nullable', 'array', 'max:50'],
            'capabilities.*' => ['string', 'max:64'],
        ]);

        $state = $this->gateway->heartbeat($this->runner($request), $data, $request->ip());

        return response()->json([...$state, 'server_time' => now()->toIso8601String()]);
    }

    public function claim(Request $request): JsonResponse
    {
        $runner = $this->runner($request);
        $command = $this->gateway->claim($runner);

        return response()->json([
            'command' => $command,
            'halted' => $command === null && app(SystemHalt::class)->isHalted($runner->organization_id),
        ]);
    }

    public function progress(Request $request, string $command): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:500']]);
        $cancel = $this->gateway->progress($this->command($request, $command), $data['message']);

        return response()->json(['ok' => true, 'cancel' => $cancel]);
    }

    public function indexChunk(Request $request, string $command): JsonResponse
    {
        $data = $request->validate([
            'files' => ['required', 'array', 'max:1000'],
            'files.*.path' => ['required', 'string', 'max:1024'],
            'files.*.language' => ['required', 'string', 'max:32'],
            'files.*.size' => ['required', 'integer', 'min:0'],
            'files.*.sha1' => ['nullable', 'string', 'max:40'],
            'files.*.symbols' => ['present', 'array', 'max:400'],
            'files.*.symbols.*.kind' => ['required', 'string', 'in:class,interface,trait,enum,model,function,test,route,table,view,component,hook,type,dependency'],
            'files.*.symbols.*.name' => ['required', 'string', 'max:1000'],
            'files.*.symbols.*.line' => ['required', 'integer', 'min:0'],
            'files.*.symbols.*.detail' => ['nullable', 'string', 'max:1000'],
        ]);

        $stored = $this->gateway->storeIndexChunk($this->command($request, $command), $data['files']);

        return response()->json(['stored' => $stored]);
    }

    public function complete(Request $request, string $command): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:succeeded,failed'],
            'result' => ['nullable', 'array'],
            'error' => ['nullable', 'array'],
            'error.code' => ['nullable', 'string', 'max:64'],
            'error.message' => ['nullable', 'string', 'max:4000'],
        ]);

        $this->gateway->complete(
            $this->command($request, $command),
            $data['status'] === 'succeeded',
            $data['result'] ?? null,
            $data['error']['code'] ?? null,
            $data['error']['message'] ?? null,
        );

        return response()->json(['ok' => true]);
    }

    private function runner(Request $request): LocalRunner
    {
        return $request->attributes->get('runner');
    }

    /**
     * A runner can only ever see or touch its own commands.
     */
    private function command(Request $request, string $id): RunnerCommand
    {
        return RunnerCommand::query()
            ->whereKey($id)
            ->where('local_runner_id', $this->runner($request)->id)
            ->firstOrFail();
    }
}
