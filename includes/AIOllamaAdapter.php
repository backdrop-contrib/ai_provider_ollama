<?php

/**
 * @file
 * Ollama adapter for accessing locally-hosted AI models.
 *
 * Ollama exposes an OpenAI-compatible REST API; no external SDK is required.
 *
 * @see https://docs.ollama.com/api/ai-compatibility
 */

class AIOllamaAdapter extends AIAdapterBase {

  use AICompatibleTrait;

  /** @var string Base URL including /v1 suffix. */
  protected $baseUrl;

  /** @var array|null Model catalog reused across capability lookups. */
  protected $models;

  /**
   * Constructor.
   *
   * @param string $api_key
   *   Not used for Ollama, but required by interface. Pass any string.
   * @param AIApi|null $api
   *   Optional parent API wrapper.
   */
  public function __construct($api_key, ?AIApi $api = NULL) {
    parent::__construct($api_key, $api);

    $base = ai_get_provider_setting('ollama', 'ollama_base_url')
      ?: config_get('ai_provider_ollama.settings', 'base_url')
      ?: 'http://localhost:11434';

    $this->baseUrl = preg_replace('#/v1$#', '', rtrim($base, '/')) . '/v1';
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultHeaders(): array {
    // Ollama does not require authentication.
    return [];
  }

  /** ------------------------ Models ------------------------ */

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    if ($this->models !== NULL) {
      return $this->models;
    }
    $models = [];
    try {
      $data = $this->makeRequest($this->baseUrl . '/models', [], [], 'GET', 10);
      foreach ($data['data'] ?? [] as $model) {
        $id = $model['id'] ?? '';
        if ($id) {
          $models[$id] = $id;
        }
      }
      if (!empty($models)) {
        asort($models);
      }
      $this->models = $models;
    }
    catch (\Exception $e) {
      watchdog('ai_provider_ollama', 'Failed to fetch models: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
    }
    return $models;
  }

  /**
   * {@inheritdoc}
   *
   * Use native /api/show metadata; the compatible /v1/models list is untyped.
   * Unknown models remain available through getModels() and manual overrides.
   */
  public function getModelsByCapability($capability): array {
    $models = $this->getModels();
    $filtered = [];
    $manual_applied = FALSE;

    if (function_exists('ai_filter_models_by_manual_capability')) {
      $filtered = ai_filter_models_by_manual_capability($models, 'ollama', $capability, $manual_applied);
    }

    if (!$manual_applied) {
      $filtered = [];
      $native_capabilities = [
        'text' => 'completion',
        'embeddings' => 'embedding',
        'vision' => 'vision',
        'tool_calling' => 'tools',
        'tools' => 'tools',
        'thinking' => 'thinking',
        'insert' => 'insert',
        'audio' => 'audio',
        'decision' => 'decision',
      ];
      $canonical = ai_normalize_capability_name($capability);
      if (isset($native_capabilities[$canonical])) {
        foreach ($models as $model => $label) {
          $detected = $this->getModelCapabilities($model);
          // Vision and tools use chat endpoints. Templates can advertise tools
          // even on embedding-only models, which cannot perform chat calls.
          $needs_chat = in_array($canonical, ['vision', 'tool_calling', 'tools', 'thinking', 'insert'], TRUE);
          if ($needs_chat && !in_array('completion', $detected, TRUE)) {
            continue;
          }
          if (in_array($native_capabilities[$canonical], $detected, TRUE)) {
            $filtered[$model] = $label;
          }
        }
      }
    }

    backdrop_alter('ai_model_capabilities', $filtered, $capability, $this);
    return $filtered;
  }

  /**
   * Fetch native capabilities without running inference or loading a model.
   */
  protected function getModelCapabilities(string $model): array {
    // Include the server URL so changing servers cannot reuse old metadata.
    // The prefix lets the AI settings model-refresh action clear this cache.
    $cache_key = ai_models_cache_key('ollama', 'native_capabilities', [$this->baseUrl, $model]);
    if ($cached = cache_get($cache_key, 'cache')) {
      return $cached->data;
    }

    $capabilities = [];
    $ttl = 300;
    try {
      $url = substr($this->baseUrl, 0, -3) . '/api/show';
      $data = $this->makeRequest($url, ['model' => $model], [], 'POST', 10);
      if (isset($data['capabilities']) && is_array($data['capabilities'])) {
        $capabilities = array_values(array_filter($data['capabilities'], 'is_string'));
        $ttl = 21600;
      }
      else {
        watchdog('ai_provider_ollama', 'No capability metadata for @model. Update Ollama or configure manual model capabilities.', ['@model' => $model], WATCHDOG_WARNING);
      }
    }
    catch (\Exception $e) {
      watchdog('ai_provider_ollama', 'Could not discover capabilities for @model: @error. Configure manual model capabilities if the native API is unavailable.', [
        '@model' => $model,
        '@error' => $e->getMessage(),
      ], WATCHDOG_WARNING);
    }

    cache_set($cache_key, $capabilities, 'cache', REQUEST_TIME + $ttl);
    return $capabilities;
  }

  /** ------------------------ Text / Chat ------------------------ */

  /**
   * {@inheritdoc}
   */
  public function completions(string $model, string $prompt, $temperature, $max_tokens = 512, bool $stream_response = FALSE) {
    $payload = [
      'model'       => $model,
      'prompt'      => $prompt,
      'temperature' => (float) $temperature,
      'max_tokens'  => (int) $max_tokens,
    ];

    if ($stream_response) {
      $payload['stream'] = TRUE;
      $options = $this->buildPostOptions($payload, 300);
      return $this->buildStreamingResponse($this->baseUrl . '/completions', $options, function ($data) {
        return $data['choices'][0]['text'] ?? NULL;
      });
    }

    $result = $this->makeRequest($this->baseUrl . '/completions', $payload, [], 'POST', 60);
    return $result['choices'][0]['text'] ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function chat(string $model, array $messages, $temperature, $max_tokens = 1024, bool $stream_response = FALSE, array $context_extra = []) {
    if (function_exists('backdrop_alter') && empty($context_extra['skip_ai_message_alter'])) {
      $context = [
        'operation' => 'chat',
        'model'     => $model,
        'provider'  => 'ollama',
      ];
      backdrop_alter('ai_chat_messages', $messages, $context);
    }

    $payload = [
      'model'       => $model,
      'messages'    => $messages,
      'temperature' => (float) $temperature,
      'max_tokens'  => (int) $max_tokens,
    ];

    if (!empty($context_extra['response_format'])) {
      $payload['response_format'] = $context_extra['response_format'];
    }
    elseif (!empty($context_extra['json_schema'])) {
      $payload['response_format'] = [
        'type' => 'json_schema',
        'json_schema' => [
          'name' => $context_extra['json_schema_name'] ?? 'response',
          'strict' => TRUE,
          'schema' => $context_extra['json_schema'],
        ],
      ];
    }
    elseif (!empty($context_extra['json_mode'])) {
      $payload['response_format'] = ['type' => 'json_object'];
    }

    if ($stream_response) {
      $payload['stream'] = TRUE;
      $options = $this->buildPostOptions($payload, 300);
      return $this->buildStreamingResponse($this->baseUrl . '/chat/completions', $options, function ($data) {
        return $data['choices'][0]['delta']['content'] ?? NULL;
      });
    }

    $result = $this->makeRequest($this->baseUrl . '/chat/completions', $payload, [], 'POST', 60);
    return $result['choices'][0]['message']['content'] ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function images(string $model, string $prompt, string $size, string $response_format, string $quality = 'standard', string $style = 'natural', ?string $output_format = NULL) {
    watchdog('ai_provider_ollama', 'Image generation is not supported by Ollama.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Image generation is not supported by Ollama.');
  }

  /**
   * {@inheritdoc}
   */
  public function textToSpeech(string $model, string $input, string $voice, string $response_format) {
    watchdog('ai_provider_ollama', 'Text-to-speech is not supported by Ollama.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Text-to-speech is not supported by Ollama.');
  }

  /**
   * {@inheritdoc}
   */
  public function speechToText(string $model, string $file, string $task = 'transcribe', $temperature = 0.4, string $response_format = 'verbose_json') {
    watchdog('ai_provider_ollama', 'Speech-to-text is not supported by Ollama.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Speech-to-text is not supported by Ollama.');
  }

  /**
   * {@inheritdoc}
   */
  public function moderation(string $input, string $model = 'omni-moderation-latest'): array {
    watchdog('ai_provider_ollama', 'Moderation is not supported by Ollama.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Moderation is not supported by Ollama.');
  }

  /**
   * {@inheritdoc}
   *
   * Models with Ollama's "decision" capability answer through the native
   * /v1/systemone endpoint; other models use the emulated fallback.
   */
  public function decide(string $input, array $questions, string $model = '', array $context_extra = []): array {
    if (empty($questions)) {
      return [];
    }
    if ($model === '') {
      $model = (string) array_key_first($this->getModelsByCapability('decision'));
    }
    if ($model === '' || !in_array('decision', $this->getModelCapabilities($model), TRUE)) {
      return parent::decide($input, $questions, $model, $context_extra);
    }

    [$question_map, $meta] = AIDecisionHelper::buildQuestions($questions);
    $response = $this->makeRequest($this->baseUrl . '/systemone', [
      'model' => $model,
      'state' => $input,
      'questions' => $question_map,
    ], [], 'POST', 120);
    $this->captureProviderUsage($response);
    return AIDecisionHelper::parseAnswers($response, $meta);
  }

  /**
   * {@inheritdoc}
   */
  public function embedding(string $input, string $model, bool $log = TRUE): array {
    $start_time = microtime(TRUE);
    try {
      $response = $this->makeRequest($this->baseUrl . '/embeddings', [
        'model' => $model,
        'input' => $input,
      ]);
      $result = $response['data'][0]['embedding'] ?? [];
      if (isset($this->api) && method_exists($this->api, 'recordLog')) {
        $duration = microtime(TRUE) - $start_time;
        $this->api->recordLog('embedding', $model, ['input' => $input], $response, TRUE, $duration, NULL, !$log);
      }
      return $result;
    }
    catch (\Exception $e) {
      if (isset($this->api) && method_exists($this->api, 'recordLog')) {
        $duration = microtime(TRUE) - $start_time;
        $this->api->recordLog('embedding', $model, ['input' => $input], NULL, FALSE, $duration, $e->getMessage(), !$log);
      }
      ai_log_embedding_error('ai_provider_ollama', $e->getMessage(), $log);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function chatWithTools(string $model, array $messages, array $tools, $temperature, $max_tokens = 1024, string $tool_choice = 'auto', array $context_extra = []): array {
    try {
      $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'tools'       => $tools,
        'tool_choice' => $tool_choice,
        'temperature' => (float) $temperature,
      ];
      if ((int) $max_tokens > 0) {
        $payload['max_tokens'] = (int) $max_tokens;
      }
      if (!empty($context_extra['response_format'])) {
        $payload['response_format'] = $context_extra['response_format'];
      }
      elseif (!empty($context_extra['json_schema'])) {
        $payload['response_format'] = [
          'type' => 'json_schema',
          'json_schema' => [
            'name' => $context_extra['json_schema_name'] ?? 'response',
            'strict' => TRUE,
            'schema' => $context_extra['json_schema'],
          ],
        ];
      }
      elseif (!empty($context_extra['json_mode'])) {
        $payload['response_format'] = ['type' => 'json_object'];
      }
      $result = $this->makeRequest($this->baseUrl . '/chat/completions', $payload, [], 'POST', 60);
      return $this->normalizeToolResponse($result);
    }
    catch (\Exception $e) {
      watchdog('ai_provider_ollama', 'chatWithTools error: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * Build request options array for a JSON POST (used by streaming paths).
   */
  protected function buildPostOptions(array $body, int $timeout = 60): array {
    return [
      'method'  => 'POST',
      'headers' => array_merge(
        ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
        $this->getDefaultHeaders()
      ),
      'data'    => json_encode($body),
      'timeout' => $timeout,
    ];
  }

}
