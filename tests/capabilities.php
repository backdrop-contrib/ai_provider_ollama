<?php

/**
 * @file
 * Offline regression checks. Run: php tests/capabilities.php
 */

define('REQUEST_TIME', time());
define('WATCHDOG_WARNING', 4);
define('WATCHDOG_ERROR', 3);
$test_config = ['ollama' => ['ollama_base_url' => 'http://localhost:11434/v1/']];
$test_cache = [];
$test_logs = [];
$alter_called = FALSE;

function config_get($name, $key) {
  return $name === 'ai.settings' && $key === 'providers' ? $GLOBALS['test_config'] : NULL;
}

function &backdrop_static($name, $default = NULL) {
  static $values = [];
  if (!array_key_exists($name, $values)) {
    $values[$name] = $default;
  }
  return $values[$name];
}

function cache_get($key, $bin) {
  return isset($GLOBALS['test_cache'][$key]) ? (object) ['data' => $GLOBALS['test_cache'][$key]] : FALSE;
}

function cache_set($key, $data, $bin, $expire) {
  $GLOBALS['test_cache'][$key] = $data;
}

function watchdog($type, $message, $variables, $severity) {
  $GLOBALS['test_logs'][] = $message;
}

function backdrop_alter($type, &$data, ...$context) {
  $GLOBALS['alter_called'] = TRUE;
}

$ai_path = dirname(__DIR__, 2) . '/ai';
require_once $ai_path . '/ai.module';
require_once $ai_path . '/includes/AIProviderClient.php';
require_once $ai_path . '/includes/AIAdapterBase.php';
require_once $ai_path . '/includes/AICompatibleTrait.php';
require_once dirname(__DIR__) . '/includes/AIOllamaAdapter.php';

class OllamaCapabilityFixture extends AIOllamaAdapter {
  public $showCalls = 0;
  public $catalogCalls = 0;
  public $responses = [
    'arbitrary-chat' => ['capabilities' => ['completion', 'tools', 'thinking', 'insert']],
    'arbitrary-vector' => ['capabilities' => ['embedding', 'tools', 'vision', 'thinking']],
    'arbitrary-vision' => ['capabilities' => ['completion', 'vision', 'audio']],
    'both' => ['capabilities' => ['completion', 'embedding']],
    'legacy' => ['model_info' => ['general.embedding_length' => 4096]],
    'broken' => NULL,
    'empty' => ['capabilities' => []],
    'malformed' => ['capabilities' => 'embedding'],
  ];

  protected function makeRequest(string $url, array $body = [], array $extra_headers = [], string $method = 'POST', int $timeout = 30): array {
    if ($method === 'GET') {
      $this->catalogCalls++;
      check_result('http://localhost:11434/v1/models', $url, 'Compatible URL normalized');
      return ['data' => array_map(function ($id) { return ['id' => $id]; }, array_keys($this->responses))];
    }
    check_result('http://localhost:11434/api/show', $url, 'Native URL');
    $this->showCalls++;
    $response = $this->responses[$body['model']];
    if ($response === NULL) {
      throw new Exception('Fixture endpoint unavailable');
    }
    return $response;
  }
}

function check_result($expected, $actual, $label) {
  if ($expected !== $actual) {
    throw new RuntimeException($label . ': ' . var_export($actual, TRUE));
  }
}

$adapter = new OllamaCapabilityFixture('');
check_result(['arbitrary-chat', 'arbitrary-vision', 'both'], array_keys($adapter->getChatModels()), 'Chat excludes embedding-only and unknown models');
check_result(['arbitrary-vector', 'both'], array_keys($adapter->getEmbeddingModels()), 'Embeddings exclude chat-only models');
check_result(['arbitrary-vision'], array_keys($adapter->getVisionModels()), 'Vision metadata');
check_result(['arbitrary-chat'], array_keys($adapter->getModelsByCapability('tool_calling')), 'Tool metadata');
check_result(['arbitrary-chat'], array_keys($adapter->getModelsByCapability('thinking')), 'Thinking requires completion support');
check_result(['arbitrary-chat'], array_keys($adapter->getModelsByCapability('insert')), 'Insertion metadata');
check_result(['arbitrary-vision'], array_keys($adapter->getModelsByCapability('audio')), 'Audio metadata is independent of STT/TTS');
check_result([], $adapter->getImageModels(), 'Unsupported image generation');
check_result([], $adapter->getModerationModels(), 'Unsupported moderation');
check_result([], $adapter->getSpeechToTextModels(), 'Unsupported speech recognition');
check_result([], $adapter->getModelsByCapability('tts'), 'Unsupported speech generation');
check_result(8, $adapter->showCalls, 'Metadata cached across capability lookups');
check_result(1, $adapter->catalogCalls, 'Catalog reused across capability lookups');
check_result(3, count($test_logs), 'Missing, malformed and failed metadata logged');
check_result(8, count($adapter->getModels()), 'Unknown models retained in full catalog');
check_result(TRUE, $alter_called, 'Alter hook retained');
$test_config['ollama']['manual_capability_models'] = ['embeddings' => ['legacy']];
check_result(['legacy'], array_keys($adapter->getEmbeddingModels()), 'Manual override wins');
ai_manual_capability_bypass(TRUE);
check_result(['arbitrary-vector', 'both'], array_keys($adapter->getEmbeddingModels()), 'Admin auto-detection bypass');
ai_manual_capability_bypass(FALSE);
$test_cache = [];
unset($test_config['ollama']['manual_capability_models']);
$adapter->responses['broken'] = ['capabilities' => ['embedding']];
check_result(['arbitrary-vector', 'both', 'broken'], array_keys($adapter->getModelsByCapability('embedding')), 'Refresh recovers previously unavailable model; alias works');
echo "Ollama capability regression checks passed.\n";
