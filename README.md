# PHP HTTP Client With Retry

A flexible HTTP client wrapper with automatic retry functionality for handling rate limits and server errors.

## Installation

```bash
composer require solophp/http-client
```

## Features

- Automatic retry on rate limits (429) and server errors (5xx)
- Configurable maximum retry attempts
- Respects Retry-After headers
- Exponential backoff strategy
- JSON response handling
- Based on Guzzle HTTP client
- Fluent interface

## Usage

### Basic Usage

```php
use App\YourApiClient;

$client = new YourApiClient();
$response = $client->get('endpoint');
```

### With Custom Retries

```php
$client = new YourApiClient();
$client->setMaxRetries(5);
$response = $client->get('endpoint');
```

### Creating Your API Client

```php
use Solo\AbstractHttpClient;

class YourApiClient extends AbstractHttpClient
{
    public function __construct()
    {
        $this->setClient([
            'base_uri' => $this->getBaseUrl(),
            'headers' => [
                'Accept' => 'application/json'
            ]
        ]);
    }

    protected function getBaseUrl(): string 
    {
        return 'https://api.example.com';
    }
}
```

### Available HTTP Methods

All standard HTTP methods are supported:

- GET: `$client->get($uri, $options)`
- POST: `$client->post($uri, $options)`
- PUT: `$client->put($uri, $options)`
- PATCH: `$client->patch($uri, $options)`
- DELETE: `$client->delete($uri, $options)`
- HEAD: `$client->head($uri, $options)`
- OPTIONS: `$client->options($uri, $options)`

Each method also has an async version:

- `$client->getAsync($uri, $options)`
- `$client->postAsync($uri, $options)`
- etc.

## Configuration

### Retry Settings

By default, the client will:
- Retry up to 3 times
- Use exponential backoff (1s, 2s, 4s, ...)
- Respect Retry-After headers if present
- Retry on HTTP 429 (Too Many Requests) and 5xx errors

You can customize the number of retries:

```php
$client->setMaxRetries(5); // Set maximum 5 retries
```

### Guzzle Options

You can pass any Guzzle client options when initializing your client:

```php
class YourApiClient extends AbstractHttpClient
{
    public function __construct()
    {
        $this->setClient([
            'base_uri' => $this->getBaseUrl(),
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer your-token'
            ]
        ]);
    }
}
```

## Error Handling

The client will throw exceptions in these cases:

- `RuntimeException`: When an HTTP request fails
- `BadMethodCallException`: When trying to call a non-existent method

Example:

```php
try {
    $response = $client->get('endpoint');
} catch (\RuntimeException $e) {
    // Handle request error
    echo $e->getMessage();
}
```

## Requirements

- PHP 8.1 or higher
- Guzzle 7.8 or higher

## License
MIT