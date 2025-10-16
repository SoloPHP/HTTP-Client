# Solo HTTP Client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/http-client.svg)](https://packagist.org/packages/solophp/http-client)
[![License](https://img.shields.io/packagist/l/solophp/http-client.svg)](https://github.com/solophp/http-client/blob/main/LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/solophp/http-client.svg)](https://packagist.org/packages/solophp/http-client)

A simple and elegant PSR-18 HTTP client built on top of Guzzle with fluent API.

## Features

- **PSR-18 Compliant**: Implements PSR-18 HTTP Client Interface
- **Fluent API**: Chain methods for easy configuration
- **Built-in Retry Logic**: Automatic retry with exponential backoff
- **Logging Support**: Built-in request/response logging
- **JSON Support**: Easy JSON request/response handling
- **Multipart/Form-Data**: File uploads and form submissions
- **Async Support**: Asynchronous request methods
- **Middleware Support**: Extensible with custom middleware

## Installation

```bash
composer require solophp/http-client
```

## Quick Start

```php
use Solo\HttpClient\HttpClient;

// Create client
$client = HttpClient::create('https://api.example.com')
    ->timeout(30)
    ->withHeaders(['Content-Type' => 'application/json'])
    ->withToken('your-api-token')
    ->retry(3, 1000);

// Make requests
$response = $client->get('/users');
$users = $response->json();

$response = $client->post('/users', [
    'json' => [
        'name' => 'John Doe',
        'email' => 'john@example.com'
    ]
]);
```

## Basic Usage

### Creating a Client

```php
// Simple client
$client = new HttpClient('https://api.example.com');

// With configuration
$client = new HttpClient('https://api.example.com', [
    'timeout' => 30,
    'headers' => ['User-Agent' => 'MyApp/1.0']
]);

// Using fluent API
$client = HttpClient::create('https://api.example.com')
    ->timeout(60)
    ->withHeaders(['X-Custom' => 'value']);
```

### Making Requests

```php
// GET request
$response = $client->get('/users');

// POST with JSON data
$response = $client->post('/users', [
    'json' => [
        'name' => 'John',
        'email' => 'john@example.com'
    ]
]);

// PUT request
$response = $client->put('/users/1', [
    'json' => [
        'name' => 'John Updated'
    ]
]);

// PATCH request
$response = $client->patch('/users/1', [
    'json' => [
        'email' => 'john.updated@example.com'
    ]
]);

// DELETE request
$response = $client->delete('/users/1');

// With custom headers and JSON data
$response = $client->post('/users', [
    'json' => ['name' => 'John'],
    'headers' => ['X-Custom' => 'header']
]);
```

### Multipart/Form-Data Requests

```php
// Simple form fields
$response = $client->postMultipart('/upload', [
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'age' => '30'
]);

// Upload file
$response = $client->postMultipart('/upload', [
    'name' => 'John Doe',
    'avatar' => [
        'path' => '/path/to/avatar.jpg'
    ]
]);

// Upload file with custom filename and MIME type
$response = $client->postMultipart('/upload', [
    'name' => 'John Doe',
    'document' => [
        'path' => '/path/to/document.pdf',
        'filename' => 'my-document.pdf',
        'mime_type' => 'application/pdf'
    ]
]);

// Multiple files and fields
$response = $client->postMultipart('/upload', [
    'title' => 'My Photos',
    'description' => 'Vacation photos',
    'photo1' => [
        'path' => '/path/to/photo1.jpg',
        'filename' => 'beach.jpg'
    ],
    'photo2' => [
        'path' => '/path/to/photo2.jpg',
        'filename' => 'sunset.jpg'
    ]
]);

// PUT and PATCH also available
$response = $client->putMultipart('/users/1', [
    'name' => 'Updated Name',
    'avatar' => ['path' => '/path/to/new-avatar.jpg']
]);

$response = $client->patchMultipart('/users/1', [
    'avatar' => ['path' => '/path/to/avatar.jpg']
]);

// Advanced: Using native Guzzle options
$response = $client->post('/upload', [
    'multipart' => [
        [
            'name' => 'field_name',
            'contents' => 'field_value'
        ],
        [
            'name' => 'file',
            'contents' => fopen('/path/to/file.jpg', 'r'),
            'filename' => 'photo.jpg',
            'headers' => ['Content-Type' => 'image/jpeg']
        ]
    ]
]);
```

### Working with Responses

```php
$response = $client->get('/users/1');

// Check status
if ($response->successful()) {
    $user = $response->json();
    echo $user['name'];
}

// Get specific JSON value
$name = $response->json('name');
$email = $response->json('profile.email');

// Get headers
$contentType = $response->header('Content-Type');
$allHeaders = $response->headers();

// Get raw body
$body = $response->body();
```

### Authentication

```php
// Bearer token
$client->withToken('your-api-token');

// Custom token type
$client->withToken('your-token', 'Custom');

// Basic auth
$client->withBasicAuth('username', 'password');
```

### Retry Logic

```php
$client->retry(3, 1000); // 3 retries, 1 second delay

// The client will automatically retry on:
// - Network exceptions
// - 429 (Too Many Requests)
// - 5xx server errors
```

### Logging

```php
// Default logging (to error_log)
$client->withLogging();

// Custom callable logger
$client->withLogging(function($message) {
    file_put_contents('http.log', $message . PHP_EOL, FILE_APPEND);
});

// PSR-3 Logger integration
use Psr\Log\LoggerInterface;

$logger = new YourPSR3Logger(); // Any PSR-3 compatible logger
$client->withLogging($logger); // Automatically uses info level
```

### Async Requests

```php
// Async GET
$promise = $client->getAsync('/users');
$response = $promise->wait();

// Async POST
$promise = $client->postAsync('/users', ['json' => ['name' => 'John']]);
$response = $promise->wait();
```

### Custom Requests

```php
// Custom method with options
$response = $client->request('POST', '/custom', [
    'json' => ['data' => 'value'],
    'headers' => ['X-Custom' => 'header'],
    'timeout' => 10
]);

// Async custom request
$promise = $client->requestAsync('PUT', '/custom', [
    'json' => ['data' => 'value']
]);
```

## Error Handling

```php
try {
    $response = $client->get('/users');
    
    if ($response->clientError()) {
        // Handle 4xx errors
        echo "Client error: " . $response->status();
    } elseif ($response->serverError()) {
        // Handle 5xx errors
        echo "Server error: " . $response->status();
    }
} catch (\RuntimeException $e) {
    // Handle network/connection errors
    echo "Request failed: " . $e->getMessage();
}
```

## Advanced Usage

### Accessing Guzzle Client

```php
$guzzleClient = $client->getClient();
// Use Guzzle client directly for advanced features
```

### Custom Configuration

```php
$client = new HttpClient('https://api.example.com', [
    'timeout' => 30,
    'connect_timeout' => 10,
    'http_errors' => false,
    'verify' => false, // Disable SSL verification (not recommended for production)
    'proxy' => 'http://proxy.example.com:8080'
]);
```

## Development

```bash
# Run code style check
composer cs

# Fix code style
composer cs-fix

# Run static analysis
composer phpstan
```

## Requirements

- PHP 8.1 or higher
- Guzzle HTTP 7.9 or higher
- PSR-3 Logger (optional, for logging functionality)

## License

MIT License - see LICENSE file for details.