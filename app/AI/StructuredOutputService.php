<?php

namespace App\AI;

use App\AI\Data\ProviderRequest;
use App\AI\Exceptions\InvalidStructuredOutputException;
use App\Models\AgentRun;

class StructuredOutputService
{
    public function __construct(private readonly ProviderExecutor $executor, private readonly StructuredOutputValidator $validator) {}

    /** @return array<string, mixed> */
    public function generate(AgentRun $run, ProviderRequest $request): array
    {
        $response = $this->executor->execute($run, $request);
        $errors = $this->validator->validate($response->output, $request->schema);
        if ($errors === []) {
            return $response->output;
        }

        $repair = $request->withInput(
            $request->input."\n\nThe previous output failed local schema validation. Return a corrected object only. Errors:\n- ".implode("\n- ", $errors)."\nPrevious output:\n".json_encode($response->output, JSON_UNESCAPED_SLASHES),
        );
        $fixed = $this->executor->execute($run, $repair, repair: true);
        $errors = $this->validator->validate($fixed->output, $request->schema);
        if ($errors !== []) {
            throw new InvalidStructuredOutputException($errors);
        }

        return $fixed->output;
    }
}
