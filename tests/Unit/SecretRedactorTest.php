<?php

namespace Tests\Unit;

use App\Security\SecretRedactor;
use PHPUnit\Framework\TestCase;

class SecretRedactorTest extends TestCase
{
    public function test_credential_shaped_text_is_redacted()
    {
        $text = implode("\n", [
            'OPENAI_API_KEY=sk-proj-abcdefghijklmnopqrstuvwxyz123',
            "Stripe::setApiKey('sk_"."live_51Habcdefghijklmnop');", // split: a fake key, kept out of secret scanners
            'DB_PASSWORD=supersecret',
            'mysql://root:pa55word@localhost/db',
            '-----BEGIN PRIVATE KEY-----'."\nMIIabc\n".'-----END PRIVATE KEY-----',
            'APP_KEY=base64:'.str_repeat('A', 44),
            'jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.abcdefghijklmnopqrstuv',
        ]);

        $out = (new SecretRedactor)->redactText($text);

        foreach (['sk-proj-abc', 'sk_live_51H', 'supersecret', 'pa55word', 'MIIabc', 'AAAAAAAAAAAAAAAAAAAA', 'eyJzdWIi'] as $secret) {
            $this->assertStringNotContainsString($secret, $out, $secret);
        }
        $this->assertStringContainsString('DB_PASSWORD=[REDACTED]', $out);
    }

    public function test_env_shape_only_keeps_values_for_configuration_keys()
    {
        $shape = (new SecretRedactor)->envShape([
            'DB_CONNECTION' => 'mysql',
            'QUEUE_CONNECTION' => 'redis',
            'DB_PASSWORD' => 'hunter2',
            'PAYMONGO_SECRET' => '<set>',
            'MAIL_PASSWORD' => '',
            'APP_ENV' => 'local; rm -rf /',
            'bad key' => 'x',
        ]);

        $this->assertSame([
            'DB_CONNECTION' => 'mysql',
            'QUEUE_CONNECTION' => 'redis',
            'DB_PASSWORD' => '<set>',
            'PAYMONGO_SECRET' => '<set>',
            'MAIL_PASSWORD' => '<empty>',
            'APP_ENV' => '<set>',
        ], $shape);
    }
}
