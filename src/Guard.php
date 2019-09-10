<?php
/**
 * Slim Framework (https://slimframework.com)
 *
 * @license https://github.com/slimphp/Slim-Csrf/blob/master/LICENSE.md (MIT License)
 */

declare(strict_types=1);

namespace Slim\Csrf;

use ArrayAccess;
use Countable;
use Exception;
use Iterator;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

class Guard implements MiddlewareInterface
{
    /**
     * @var ResponseFactoryInterface
     */
    protected $responseFactory;

    /**
     * Prefix for CSRF parameters (omit trailing "_" underscore)
     *
     * @var string
     */
    protected $prefix;

    /**
     * CSRF storage
     *
     * Should be either an array or an object. If an object is used, then it must
     * implement ArrayAccess and should implement Countable and Iterator
     * if storage limit enforcement is required.
     *
     * @var array|ArrayAccess
     */
    protected $storage;

    /**
     * Number of elements to store in the storage array
     *
     * @var int
     */
    protected $storageLimit;

    /**
     * CSRF Strength
     *
     * @var int
     */
    protected $strength;

    /**
     * Callable to be executed if the CSRF validation fails
     * It must return a ResponseInterface
     *
     * @var callable
     */
    protected $failureHandler;

    /**
     * Determines whether or not we should persist the token throughout the duration of the user's session.
     *
     * For security, Slim-Csrf will *always* reset the token if there is a validation error.
     * @var bool True to use the same token throughout the session (unless there is a validation error),
     * false to get a new token with each request.
     */
    protected $persistentTokenMode;

    /**
     * Stores the latest key-pair generated by the class
     *
     * @var array|null
     */
    protected $keyPair;

    /**
     * @param ResponseFactoryInterface  $responseFactory
     * @param string                    $prefix
     * @param null|array|ArrayAccess    $storage
     * @param null|callable             $failureHandler
     * @param integer                   $storageLimit
     * @param integer                   $strength
     * @param boolean                   $persistentTokenMode
     * @throws RuntimeException if the session cannot be found
     */
    public function __construct(
        ResponseFactoryInterface $responseFactory,
        string $prefix = 'csrf',
        &$storage = null,
        ?callable $failureHandler = null,
        int $storageLimit = 200,
        int $strength = 16,
        bool $persistentTokenMode = false
    ) {
        $this->responseFactory = $responseFactory;
        $this->prefix = rtrim($prefix, '_');

        if ($strength < 16) {
            throw new RuntimeException('CSRF middleware instantiation failed. Minimum strength is 16.');
        }
        $this->strength = $strength;

        $this->setStorage($storage);
        $this->setFailureHandler($failureHandler);
        $this->setStorageLimit($storageLimit);
        $this->setPersistentTokenMode($persistentTokenMode);

        $this->keyPair = null;
    }

    /**
     * @param null|array|ArrayAccess $storage
     *
     * @return self
     *
     * @throws RuntimeException
     */
    public function setStorage(&$storage = null): self
    {
        if (is_array($storage) || ($storage instanceof ArrayAccess)) {
            $this->storage = &$storage;
            return $this;
        }

        if (!isset($_SESSION)) {
            throw new RuntimeException(
                'Invalid CSRF storage. ' .
                'Use session_start() before instantiating the Guard middleware or provide array storage.'
            );
        }

        if (!array_key_exists($this->prefix, $_SESSION) || !is_array($_SESSION[$this->prefix])) {
            $_SESSION[$this->prefix] = [];
        }

        $this->storage = &$_SESSION[$this->prefix];
        return $this;
    }

    /**
     * @param callable|null $failureHandler Value to set
     * @return self
     */
    public function setFailureHandler(?callable $failureHandler): self
    {
        $this->failureHandler = $failureHandler;
        return $this;
    }

    /**
     * @param bool $persistentTokenMode True to use the same token throughout the session
     * (unless there is a validation error), false to get a new token with each request.
     * @return self
     */
    public function setPersistentTokenMode(bool $persistentTokenMode): self
    {
        $this->persistentTokenMode = $persistentTokenMode;
        return $this;
    }

    /**
     * @param integer $storageLimit Value to set
     *
     * @return $this
     */
    public function setStorageLimit(int $storageLimit): self
    {
        $this->storageLimit = $storageLimit;
        return $this;
    }

    /**
     * @return string
     *
     * @throws Exception
     */
    protected function createToken(): string
    {
        return bin2hex(random_bytes($this->strength));
    }

    /**
     * @return array
     *
     * @throws Exception
     */
    public function generateToken(): array
    {
        // Generate new CSRF token
        $name = uniqid($this->prefix);
        $value = $this->createToken();
        $this->saveTokenToStorage($name, $value);


        $this->keyPair = [
            $this->getTokenNameKey() => $name,
            $this->getTokenValueKey() => $value
        ];

        return $this->keyPair;
    }

    /**
     * Validate CSRF token from current request against token value
     * stored in $_SESSION or user provided storage
     *
     * @param  string $name  CSRF name
     * @param  string $value CSRF token value
     *
     * @return bool
     */
    public function validateToken(string $name, string $value): bool
    {
        $valid = false;

        if (isset($this->storage[$name])) {
            $token = $this->storage[$name];

            if (function_exists('hash_equals')) {
                $valid = hash_equals($token, $value);
            } else {
                $valid = $token === $value;
            }
        }

        return $valid;
    }

    /**
     * @return string
     */
    public function getTokenName(): ?string
    {
        return $this->keyPair[$this->getTokenNameKey()] ?? null;
    }

    /**
     * @return string
     */
    public function getTokenValue(): ?string
    {
        return $this->keyPair[$this->getTokenValueKey()] ?? null;
    }

    /**
     * @return string
     */
    public function getTokenNameKey(): string
    {
        return $this->prefix . '_name';
    }

    /**
     * @return string
     */
    public function getTokenValueKey(): string
    {
        return $this->prefix . '_value';
    }

    /**
     * @return bool
     */
    public function getPersistentTokenMode(): bool
    {
        return $this->persistentTokenMode;
    }

    /**
     * @return string[]|null
     */
    protected function getLastKeyPair(): ?array
    {
        // Use count, since empty ArrayAccess objects can still return false for `empty`
        if (count($this->storage) < 1) {
            return null;
        }

        $name = null;
        $value = null;
        foreach ($this->storage as $k => $v) {
            $name = $k;
            $value = $v;
        }

        return $name !== null && $value !== null
            ? [
                $this->getTokenNameKey() => $name,
                $this->getTokenValueKey() => $value
            ]
            : null;
    }

    /**
     * Load the most recent key pair in storage.
     *
     * @return bool `true` if there was a key pair to load in storage, false otherwise.
     */
    protected function loadLastKeyPair(): bool
    {
        return !!($this->keyPair = $this->getLastKeyPair());
    }

    /**
     * @param  string $name  CSRF token name
     * @param  string $value CSRF token value
     *
     * @return void
     */
    protected function saveTokenToStorage(string $name, string $value): void
    {
        $this->storage[$name] = $value;
    }

    /**
     * Remove token from storage
     *
     * @param  string $name CSRF token name
     */
    protected function removeTokenFromStorage(string $name): void
    {
        $this->storage[$name] = '';
        unset($this->storage[$name]);
    }

    /**
     * Remove the oldest tokens from the storage array so that there
     * are never more than storageLimit tokens in the array.
     *
     * This is required as a token is generated every request and so
     * most will never be used.
     */
    protected function enforceStorageLimit(): void
    {
        if ($this->storageLimit > 0
            && (is_array($this->storage)
                || ($this->storage instanceof Countable && $this->storage instanceof Iterator)
            )
        ) {
            if (is_array($this->storage)) {
                while (count($this->storage) > $this->storageLimit) {
                    array_shift($this->storage);
                }
            } elseif ($this->storage instanceof Iterator) {
                while (count($this->storage) > $this->storageLimit) {
                    $this->storage->rewind();
                    unset($this->storage[$this->storage->key()]);
                }
            }
        }
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ServerRequestInterface
     *
     * @throws Exception
     */
    public function appendNewTokenToRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        $token = $this->generateToken();
        return $this->appendTokenToRequest($request, $token);
    }

    /**
     * @param ServerRequestInterface $request
     * @param array $pair
     *
     * @return ServerRequestInterface
     */
    protected function appendTokenToRequest(ServerRequestInterface $request, array $pair): ServerRequestInterface
    {
        $name = $this->getTokenNameKey();
        $value = $this->getTokenValueKey();
        return $request
            ->withAttribute($name, $pair[$name])
            ->withAttribute($value, $pair[$value]);
    }

    /**
     * @param ServerRequestInterface  $request
     * @param RequestHandlerInterface $handler
     *
     * @return ResponseInterface
     *
     * @throws Exception
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            $body = $request->getParsedBody();
            $name = null;
            $value = null;

            if (is_array($body)) {
                $name = $body[$this->getTokenNameKey()] ?? null;
                $value = $body[$this->getTokenValueKey()] ?? null;
            }

            if ($name === null
                || $value === null
                || !$this->validateToken((string) $name, (string) $value)
            ) {
                if (!$this->persistentTokenMode && is_string($name)) {
                    $this->removeTokenFromStorage($name);
                }

                $request = $this->appendNewTokenToRequest($request);
                return $this->handleFailure($request, $handler);
            }
        }

        if (!$this->persistentTokenMode || !$this->loadLastKeyPair()) {
            $request = $this->appendNewTokenToRequest($request);
        } elseif ($this->persistentTokenMode) {
            $pair = $this->loadLastKeyPair() ? $this->keyPair : $this->generateToken();
            $request = $this->appendTokenToRequest($request, $pair);
        }

        $this->enforceStorageLimit();

        return $handler->handle($request);
    }

    /**
     * @param ServerRequestInterface  $request
     * @param RequestHandlerInterface $handler
     *
     * @return ResponseInterface
     */
    public function handleFailure(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!is_callable($this->failureHandler)) {
            $response = $this->responseFactory->createResponse();
            $body = $response->getBody();
            $body->write('Failed CSRF check!');
            return $response
                ->withStatus(400)
                ->withHeader('Content-Type', 'text/plain')
                ->withBody($body);
        }

        return call_user_func($this->failureHandler, $request, $handler);
    }
}
