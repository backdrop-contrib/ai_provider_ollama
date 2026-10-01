# AI Provider Ollama

Ollama local AI models provider for the Backdrop CMS AI module.

## Installation

- Install this module using the official [Backdrop CMS instructions](https://backdropcms.org/user-guide/modules).

## Model capabilities

The provider lists models through `/v1/models`, then reads each model's native
`/api/show` capabilities to filter selectors: `completion` for chat/text,
`embedding` for embeddings, `vision` for vision, and `tools` for tool calling.
Model names are not used to guess capabilities. The base URL may include `/v1`.
Capability lookups also recognize `thinking`, `insert` (fill-in-the-middle), and
`audio` metadata. Vision, tool calling, thinking, and insertion additionally
require `completion`. Audio metadata does not imply dedicated TTS/STT endpoint
support. Native metadata does not enable currently unimplemented image, speech,
or moderation methods in this adapter.

Metadata is cached for six hours; failed or missing metadata is cached for five
minutes. The AI settings model-cache refresh clears this metadata too. Discovery
does not run inference. Servers or proxies must expose `/api/show` for automatic
classification. Models without metadata remain in the full catalog but are
excluded from capability-specific selectors. Existing manual overrides at
`admin/config/ai/settings/capabilities/ollama` and capability alter hooks still
apply. Unsupported image, speech, and moderation operations have no automatic
model selections.

Run offline regression checks with `php tests/capabilities.php` from this module.

## Issues

Bugs and feature requests should be reported in the [Issue Queue](https://github.com/backdrop-contrib/ai_provider_ollama/issues).

## Current Maintainer

[Justin Keiser](https://github.com/keiserjb)

## Credits

- Created for Backdrop CMS by [Justin Keiser](https://github.com/keiserjb).

- Developed with AI assistance.

## License

This project is GPL v2 software. See the LICENSE.txt file in this directory for complete text.
