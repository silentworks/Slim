<?php
/**
 * Slim Framework (http://slimframework.com)
 *
 * @link      https://github.com/codeguy/Slim
 * @copyright Copyright (c) 2011-2015 Josh Lockhart
 * @license   https://github.com/codeguy/Slim/blob/master/LICENSE (MIT License)
 */
namespace Slim;

use Exception;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Handlers\Strategies\RequestResponse;
use Slim\Interfaces\RouteInterface;
use Slim\Interfaces\ServiceConfigInterface;

/**
 * Route
 */
class Route extends Routable implements RouteInterface
{
    use MiddlewareAwareTrait {
        add as addMiddleware;
    }

    /**
     * HTTP methods supported by this route
     *
     * @var string[]
     */
    protected $methods = [];

    /**
     * Route name
     *
     * @var null|string
     */
    protected $name;

    /**
     * Parent route groups
     *
     * @var RouteGroup[]
     */
    protected $groups;

    /**
     * Output buffering mode
     *
     * One of: false, 'prepend' or 'append'
     *
     * @var boolean|string
     */
    protected $outputBuffering = 'append';

    /**
     * Route parameters
     *
     * @var array
     */
    protected $arguments = [];

    /**
     * @var \Slim\Interfaces\ServiceConfigInterface
     */
    protected $service;

    /**
     * Create new route
     *
     * @param \Slim\Interfaces\ServiceConfigInterface $service
     * @param string[] $methods The route HTTP methods
     * @param string $pattern The route pattern
     * @param callable $callable The route callable
     * @param RouteGroup[] $groups The parent route groups
     */
    public function __construct(ServiceConfigInterface $service, $methods, $pattern, $callable, $groups = [])
    {
        $this->methods  = $methods;
        $this->pattern  = $pattern;
        $this->callable = $callable;
        $this->groups   = $groups;
        $this->service = $service;
    }

    /**
     * Add middleware
     *
     * This method prepends new middleware to the route's middleware stack.
     *
     * @param mixed $callable The callback routine
     *
     * @return RouteInterface
     */
    public function add($callable)
    {
        $callable = $this->resolveCallable($callable);
        $this->middleware[] = $callable;
        return $this;
    }

    /**
     * Finalize the route in preparation for dispatching
     */
    public function finalize()
    {
        foreach ($this->getGroups() as $group) {
            foreach ($group->getMiddleware() as $middleware) {
                array_unshift($this->middleware, $middleware);
            }
        }
        foreach ($this->getMiddleware() as $middleware) {
            $this->addMiddleware($middleware);
        }
    }

    /**
     * Get route callable
     *
     * @return callable
     */
    public function getCallable()
    {
        return $this->callable;
    }

    /**
     * Get route methods
     *
     * @return string[]
     */
    public function getMethods()
    {
        return $this->methods;
    }

    /**
     * Get parent route groups
     *
     * @return RouteGroup[]
     */
    public function getGroups()
    {
        return $this->groups;
    }

    /**
     * Get route name
     *
     * @return null|string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get output buffering mode
     *
     * @return boolean|string
     */
    public function getOutputBuffering()
    {
        return $this->outputBuffering;
    }

    /**
     * Set output buffering mode
     *
     * One of: false, 'prepend' or 'append'
     *
     * @param boolean|string $mode
     *
     * @throws InvalidArgumentException If an unknown buffering mode is specified
     */
    public function setOutputBuffering($mode)
    {
        if (!in_array($mode, [false, 'prepend', 'append'], true)) {
            throw new InvalidArgumentException('Unknown output buffering mode');
        }
        $this->outputBuffering = $mode;
    }

    /**
     * Set route name
     *
     * @param string $name
     *
     * @return self
     *
     * @throws InvalidArgumentException if the route name is not a string
     */
    public function setName($name)
    {
        if (!is_string($name)) {
            throw new InvalidArgumentException('Route name must be a string');
        }
        $this->name = $name;
        return $this;
    }

    /**
     * Set a route argument
     *
     * @param string $name
     * @param string $value
     *
     * @return self
     */
    public function setArgument($name, $value)
    {
        $this->arguments[$name] = $value;
        return $this;
    }

    /**
     * Replace route arguments
     *
     * @param array $arguments
     *
     * @return self
     */
    public function setArguments(array $arguments)
    {
        $this->arguments = $arguments;
        return $this;
    }

    /**
     * Retrieve route arguments
     *
     * @return array
     */
    public function getArguments()
    {
        return $this->arguments;
    }

    /**
     * Retrieve a specific route argument
     *
     * @param string $name
     * @param mixed $default
     *
     * @return mixed
     */
    public function getArgument($name, $default = null)
    {
        if (array_key_exists($name, $this->arguments)) {
            return $this->arguments[$name];
        }
        return $default;
    }

    /********************************************************************************
     * Route Runner
     *******************************************************************************/

    /**
     * Prepare the route for use
     *
     * @param ServerRequestInterface $request
     * @param array $arguments
     */
    public function prepare(ServerRequestInterface $request, array $arguments)
    {
        // Add the arguments
        foreach ($arguments as $k => $v) {
            $this->setArgument($k, $v);
        }
    }

    /**
     * Run route
     *
     * This method traverses the middleware stack, including the route's callable
     * and captures the resultant HTTP response object. It then sends the response
     * back to the Application.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface      $response
     *
     * @return ResponseInterface
     */
    public function run(ServerRequestInterface $request, ResponseInterface $response)
    {
        // Traverse middleware stack and fetch updated response
        return $this->callMiddlewareStack($request, $response);
    }

    /**
     * Dispatch route callable against current Request and Response objects
     *
     * This method invokes the route object's callable. If middleware is
     * registered for the route, each callable middleware is invoked in
     * the order specified.
     *
     * @param ServerRequestInterface $request  The current Request object
     * @param ResponseInterface      $response The current Response object
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \Exception  if the route callable throws an exception
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response)
    {
        $this->callable = $this->resolveCallable($this->callable);

        $handler = isset($this->service) ? $this->service->getFoundHandler() : new RequestResponse();

        // invoke route callable
        if ($this->outputBuffering === false) {
            $newResponse = $handler($this->callable, $request, $response, $this->arguments);
        } else {
            try {
                ob_start();
                $newResponse = $handler($this->callable, $request, $response, $this->arguments);
                $output = ob_get_clean();
            } catch (Exception $e) {
                ob_end_clean();
                throw $e;
            }
        }

        if ($newResponse instanceof ResponseInterface) {
            // if route callback returns a ResponseInterface, then use it
            $response = $newResponse;
        } elseif (is_string($newResponse)) {
            // if route callback returns a string, then append it to the response
            $response->getBody()->write($newResponse);
        }

        if (!empty($output)) {
            if ($this->outputBuffering === 'prepend') {
                // prepend output buffer content
                $body = new Http\Body(fopen('php://temp', 'r+'));
                $body->write($output . $response->getBody());
                $response = $response->withBody($body);
            } elseif ($this->outputBuffering === 'append') {
                // append output buffer content
                $response->getBody()->write($output);
            }
        }

        return $response;
    }
}
