# AI Provider Ollama

This module integrates [Ollama](https://ollama.ai/) with the AI module, allowing you to run AI models locally on your own hardware without requiring external API keys or sending data to third-party services.

## What is Ollama?

Ollama is a tool that lets you run large language models (LLMs) locally. It provides an shared-chat-compatible API, making integration seamless. With Ollama, you can run models like:

- **Llama 3.2** - Meta's latest open model
- **Mistral** - High-performance models from Mistral AI
- **Phi-3** - Microsoft's efficient small models
- **CodeLlama** - Specialized for code generation
- **Gemma 2** - Google's open models
- And many more from the [Ollama Library](https://ollama.ai/library)

## Features

- 🏠 **Run AI models locally** - No data leaves your server
- 🔒 **Privacy-focused** - Complete control over your data
- 🆓 **No API costs** - Free to use, only requires local compute
- 🔌 **shared-chat-compatible** - Drop-in replacement for many use cases
- ⚡ **Fast** - Low latency with local inference
- 📦 **Easy setup** - Simple installation and configuration

## Requirements

- AI module (parent module)
- Ollama installed on your server or local machine
- PHP 7.4+ with appropriate extensions

## Installation

### 1. Install Ollama

Visit [ollama.ai](https://ollama.ai/) and follow the installation instructions for your platform:

**Linux/macOS:**
```bash
curl -fsSL https://ollama.ai/install.sh | sh
```

**Windows:**
Download the installer from the Ollama website.

### 2. Pull a Model

After installing Ollama, pull a model to use:

```bash
# Llama 3.2 (3B - fast and efficient)
ollama pull llama3.2

# Or Mistral
ollama pull mistral

# Or Phi-3 (small and fast)
ollama pull phi3
```

View all available models at: https://ollama.ai/library

### 3. Verify Ollama is Running

Ollama automatically starts an API server on `http://localhost:11434`. Verify it's running:

```bash
curl http://localhost:11434/api/tags
```

### 4. Enable the Module

```bash
bee en ai_provider_ollama
bee cc all
```

Or enable via the UI at `/admin/modules`.

### 5. Configure

1. Go to `/admin/config/ai/settings`
2. Select **"Ollama (Local Models)"** as your AI Provider
3. Select any API key (it won't be used, but the field is required)
4. Save configuration

5. (Optional) Go to `/admin/config/ai/ollama` to configure the Ollama server URL if it's not running on localhost

## Usage

Once configured, all AI module functionality that depends on chat/completion will work with your local Ollama models:

- Content generation
- Chat interfaces
- Text processing
- Any custom implementations using the AI module

### Model Selection

When using submodules or features that allow model selection, you'll see your locally-installed Ollama models in the dropdown.

**Example models you might see:**
- `llama3.2:latest`
- `mistral:latest`
- `phi3:latest`
- `codellama:latest`

## Configuration

### Base URL Setting

By default, the module connects to `http://localhost:11434`. If your Ollama server is running elsewhere:

1. Go to `/admin/config/ai/ollama`
2. Update the **Base URL** to your Ollama server location
3. Save configuration

**Examples:**
- Remote server: `http://192.168.1.100:11434`
- Custom port: `http://localhost:8080`
- Docker container: `http://ollama:11434`

## Supported Features

✅ **Supported:**
- Chat completions (primary use case)
- Text completions
- Model listing
- Embeddings
- Streaming responses

❌ **Not Supported:**
- Image generation
- Text-to-speech
- Speech-to-text
- Moderation

These features are not available through Ollama's shared-chat-compatible API.

## Performance Considerations

### Hardware Requirements

Model performance depends on your hardware:

- **CPU-only**: Works but slower, good for small models (Phi-3, Gemma 7B)
- **With GPU**: Much faster, can handle larger models
  - NVIDIA GPUs: Best performance with CUDA
  - Apple Silicon (M1/M2/M3): Excellent performance
  - AMD GPUs: Supported via ROCm

### Recommended Models by Hardware

**Low-end (CPU only):**
- `phi3:mini` (3.8B parameters)
- `llama3.2:3b`

**Mid-range (8-16GB RAM):**
- `llama3.2:7b`
- `mistral:7b`
- `gemma2:9b`

**High-end (32GB+ RAM, GPU):**
- `llama3.1:70b`
- `mixtral:8x7b`

## Troubleshooting

### Models Not Showing Up

1. Verify Ollama is running: `curl http://localhost:11434/api/tags`
2. Check you've pulled models: `ollama list`
3. Clear Backdrop caches: `bee cc all`
4. Verify the base URL in `/admin/config/ai/ollama`

### Connection Refused

- Ensure Ollama is running: `ollama serve`
- Check the base URL matches your Ollama server
- If using Docker, ensure network connectivity

### Slow Performance

- Pull a smaller model (e.g., `phi3` instead of `llama3.1:70b`)
- Ensure Ollama is using your GPU (check with `nvidia-smi` on Linux)
- Close other applications using GPU resources

### Out of Memory

- Pull a smaller quantized model
- Reduce concurrent requests
- Increase system RAM or use swap

## Docker Setup

If running Backdrop and Ollama in Docker:

**docker-compose.yml example:**
```yaml
services:
  backdrop:
    # Your Backdrop container config
    depends_on:
      - ollama
    environment:
      OLLAMA_URL: http://ollama:11434

  ollama:
    image: ollama/ollama:latest
    ports:
      - "11434:11434"
    volumes:
      - ollama-data:/root/.ollama
    # For GPU support:
    deploy:
      resources:
        reservations:
          devices:
            - driver: nvidia
              count: 1
              capabilities: [gpu]

volumes:
  ollama-data:
```

Then configure the module to use `http://ollama:11434` as the base URL.

## Security Notes

- Ollama runs locally and doesn't send data externally
- No API keys are transmitted (the module uses a dummy key internally)
- Ensure Ollama's port (11434) is not exposed to the internet unless needed
- If exposing Ollama remotely, consider using a reverse proxy with authentication

## Resources

- [Ollama Documentation](https://github.com/ollama/ollama/blob/main/docs/README.md)
- [Ollama Model Library](https://ollama.ai/library)
- [AI-Compatible API Docs](https://github.com/ollama/ollama/blob/main/docs/ai.md)
- [AI Module Documentation](../README.md)
- [Creating Custom Providers](../CREATING_PROVIDERS.md)

## Support

For issues specific to this module, open an issue in the Backdrop CMS issue queue.

For Ollama-specific issues, visit: https://github.com/ollama/ollama/issues

## License

This module is licensed under GPL-2.0+, consistent with Backdrop CMS.
