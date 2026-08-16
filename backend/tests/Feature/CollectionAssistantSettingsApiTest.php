<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CollectionAssistantSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sonotheque.collection_assistant.ollama_url', 'http://ollama.test:11434');
        config()->set('sonotheque.collection_assistant.recommended_model', 'qwen3:8b');
    }

    public function test_assistant_is_disabled_by_default_without_contacting_ollama(): void
    {
        Http::fake();

        $this->getJson('/api/settings/collection-assistant')
            ->assertOk()
            ->assertExactJson([
                'enabled' => false,
                'provider' => 'ollama',
                'model' => null,
                'endpoint' => 'http://ollama.test:11434',
                'recommendedModel' => 'qwen3:8b',
            ]);

        Http::assertNothingSent();
    }

    public function test_assistant_settings_require_a_model_when_enabled(): void
    {
        $this->patchJson('/api/settings/collection-assistant', [
            'enabled' => true,
            'model' => null,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['model']);

        $this->patchJson('/api/settings/collection-assistant', [
            'enabled' => true,
            'model' => ' qwen3:8b ',
        ])->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('model', 'qwen3:8b');

        $settings = ApplicationSetting::current();
        $this->assertTrue($settings->collection_assistant_enabled);
        $this->assertSame('qwen3:8b', $settings->collection_assistant_model);
    }

    public function test_installed_ollama_models_are_discovered_only_on_request(): void
    {
        Http::fake([
            'http://ollama.test:11434/api/tags' => Http::response([
                'models' => [[
                    'name' => 'qwen3:8b',
                    'size' => 5_200_000_000,
                    'details' => [
                        'family' => 'qwen3',
                        'parameter_size' => '8.2B',
                        'quantization_level' => 'Q4_K_M',
                    ],
                ]],
            ]),
        ]);

        $this->postJson('/api/settings/collection-assistant/models')
            ->assertOk()
            ->assertJsonPath('status', 'available')
            ->assertJsonPath('models.0.name', 'qwen3:8b')
            ->assertJsonPath('models.0.parameterSize', '8.2B')
            ->assertJsonPath('models.0.quantization', 'Q4_K_M')
            ->assertJsonPath('models.0.family', 'qwen3');
    }

    public function test_model_check_requires_a_real_tool_call(): void
    {
        Http::fake([
            'http://ollama.test:11434/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'function' => [
                            'name' => 'collection_status',
                            'arguments' => ['scope' => 'all'],
                        ],
                    ]],
                ],
                'done' => true,
            ]),
        ]);

        $this->postJson('/api/settings/collection-assistant/test', [
            'model' => 'qwen3:8b',
        ])->assertOk()
            ->assertExactJson([
                'status' => 'available',
                'model' => 'qwen3:8b',
                'toolCalling' => true,
                'errorCode' => null,
            ]);

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'http://ollama.test:11434/api/chat'
            && $request['model'] === 'qwen3:8b'
            && $request['stream'] === false
            && $request['think'] === false
            && data_get($request->data(), 'tools.0.function.name') === 'collection_status');
    }

    public function test_plain_text_response_does_not_pass_the_tool_check(): void
    {
        Http::fake([
            'http://ollama.test:11434/api/chat' => Http::response([
                'message' => ['role' => 'assistant', 'content' => 'The collection is ready.'],
                'done' => true,
            ]),
        ]);

        $this->postJson('/api/settings/collection-assistant/test', [
            'model' => 'incompatible-model',
        ])->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('toolCalling', false)
            ->assertJsonPath('errorCode', 'tool_call_failed');
    }
}
