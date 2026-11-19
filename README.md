# AI Provider Ollama

Connect Backdrop’s AI features to an Ollama server you run. A locally installed model can process requests on your own hardware. Use it for chat, text generation, and search embeddings. Some models also support image input, tool calling, or typed decisions.

## Requirements

Backdrop CMS 1.x, PHP 8.2 or later, and the AI module. A reachable Ollama server with at least one installed model is required.

## Installation

1. Install and enable this module using the official [Backdrop CMS instructions](https://backdropcms.org/user-guide/modules).
2. Open **AI settings** at `admin/config/ai/settings` and enable the provider.
3. Set **Ollama Server URL** to an address Backdrop can reach. The default is `http://localhost:11434`. Local Ollama requires no API key.

Read the [module wiki](https://github.com/backdrop-contrib/ai_provider_ollama/wiki) for setup details, supported operations, and troubleshooting.

## Issues

Bugs and feature requests should be reported in the [Issue Queue](https://github.com/backdrop-contrib/ai_provider_ollama/issues).

## Current Maintainer

[Justin Keiser](https://github.com/keiserjb)

## Credits

- Created for Backdrop CMS by [Justin Keiser](https://github.com/keiserjb).

- Developed with AI assistance.

## License

This project is GPL v2 software. See the LICENSE.txt file in this directory for complete text.
