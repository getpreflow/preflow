# preflow/ai

A small, provider-neutral client for text and JSON completions. Callers build an `AiRequest`
and get an `AiResponse` back from an `AiClient`; Claude and Gemini providers implement the
interface, and `Preflow\Ai\Testing\FakeAiClient` stands in for them in tests.

## Install

```
composer require preflow/ai
```

## Concepts

- `AiRequest`: a purpose label (for usage logs), the prompt, an optional `Tuning` (the stable
  system part, so providers can cache it across a batch), an optional JSON schema and limits.
- `AiResponse`: text, a `StopReason`, `TokenUsage`, provider and model. `json()` decodes
  the answer and throws `AiResponseInvalid` when it is not valid JSON.
- Errors: `AiUnavailable` means stop everything (no key, quota); `AiRequestFailed` affects
  only the one request.

## Testing

```php
$client = new FakeAiClient([FakeAiClient::json(['ok' => true])]);
```

The script is consumed in order and the last entry repeats. Entries may be responses,
throwables or callables receiving the request. `requests()` returns what was sent.
