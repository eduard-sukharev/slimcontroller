<?php

/*
 * This file is part of SlimController.
 *
 * @author Ulrich Kautz <uk@fortrabbit.de>
 * @copyright 2012 Ulrich Kautz
 * @version 0.1.2
 * @package SlimController
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SlimController;

use Slim\Http\Response;

/**
 * Extended Slim base
 */
class Slim extends \Slim\Slim
{
    /**
     * @var array
     */
    protected static $ALLOWED_HTTP_METHODS = array('ANY', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD');

    /**
     * @var array
     */
    protected $routeNames = array();

    /**
     * @var bool Whether to skip notFound callback or not
     */
    private $haltWhenNotFound;

    /**
     * Add a route as per the parent method, additionally supporting the syntax
     * "{controller class name}:{action method name}" as the last argument which
     * will be converted to a closure that instantiates the controller (or gets
     * from container) and then calls the method on it.
     *
     * @inheritdoc
     *
     * @param   array (See notes above)
     * @return  \Slim\Route
     */
    public function mapRoute($args)
    {
        $callable = array_pop($args);
        if (is_string($callable) && substr_count($callable, ':', 1) == 1) {
            $callable = $this->createControllerClosure($callable);
        }
        $args[] = $callable;
        return parent::mapRoute($args);
    }

    /**
     * Create a closure that instantiates (or gets from container) and then calls
     * the action method.
     *
     * Also if the methods exist on the controller class, call setApp(), setRequest()
     * and setResponse() passing in the appropriate object.
     *
     * @param  string $name controller class name and action method name separated by a colon
     * @return callable
     */
    protected function createControllerClosure($name)
    {
        list($controllerName, $actionName) = $this->determineClassAndMethod($name);

        // Create a callable that will find or create the controller instance
        // and then execute the action
        $app = $this;
        $container = $this->container;
        $response = $this->response();
        $callable = function () use ($app, $response, $container, $controllerName, $actionName) {

            // Try to fetch the controller instance from DI container
            if (isset($container[$controllerName])) {
                $controller = $container[$controllerName];
            } else {
                // not in container, assume it can be directly instantiated
                $reflectionClass = new \ReflectionClass($controllerName);
                if ($reflectionClass->isSubclassOf('SlimController\SlimController')) {
                    $controller = new $controllerName($app);
                } else {
                    $controller = new $controllerName();
                }
            }

            $controllerArguments = func_get_args();
            $result = call_user_func_array(array($controller, $actionName), $controllerArguments);
            if ($result instanceof Response) {
                $container['response'] = $result;

                return true;
            }
            if (is_string($result)) {
                $container['response'] = new Response($result);

                return true;
            }

            return $result;
        };

        return $callable;
    }

    /**
     * Not Found Handler
     *
     * This method defines or invokes the application-wide Not Found handler.
     * There are two contexts in which this method may be invoked:
     *
     * 1. When declaring the handler:
     *
     * If the $callable parameter is not null and is callable, this
     * method will register the callable to be invoked when no
     * routes match the current HTTP request. It WILL NOT invoke the callable.
     *
     * 2. When invoking the handler:
     *
     * If the $callable parameter is null, Slim assumes you want
     * to invoke an already-registered handler. If the handler has been
     * registered and is callable, it is invoked and sends a 404 HTTP Response
     * whose body is the output of the Not Found handler.
     *
     * @param  mixed $callable Anything that returns true for is_callable()
     */
    public function notFound ($callable = null, $haltWhenNotFound = true)
    {
        if (is_callable($callable)) {
            $this->haltWhenNotFound = $haltWhenNotFound;
            $this->notFound = $callable;
        } else {
            ob_start();
            if (is_callable($this->notFound)) {
                call_user_func($this->notFound);
            } else {
                call_user_func(array($this, 'defaultNotFound'));
            }
            if ($this->haltWhenNotFound) {
                $this->halt(404, ob_get_clean());
            } else {
                ob_flush();
            }
        }
    }

    /**
     * @param string $classMethod
     *
     * @return array
     * @throws \InvalidArgumentException
     */
    protected function determineClassAndMethod($classMethod)
    {
        // determine method suffix or default to "Action"
        $methodNameSuffix = $this->config('controller.method_suffix');
        if (is_null($methodNameSuffix)) {
            $methodNameSuffix = 'Action';
        }
        if (!stripos($classMethod, ':')) {
            throw new \InvalidArgumentException(
                "Malformed class action for '$classMethod'. Use 'className:methodName' format."
            );
        }

        // having <className>:<methodName>
        list($shortClassName, $shortMethodName) = explode(':', $classMethod);

        return array($this->determineClass($shortClassName), $shortMethodName . $methodNameSuffix);
    }

    /**
     * @param string $shortClassName
     *
     * @return string
     * @throws \InvalidArgumentException
     */
    protected function determineClass($shortClassName)
    {
        // determine class prefix (eg "\Vendor\Bundle\Controller") and suffix (eg "Controller")
        $classNamePrefix = $this->config('controller.class_prefix');
        if ($classNamePrefix && substr($classNamePrefix, -strlen($classNamePrefix) !== '\\')) {
            $classNamePrefix .= '\\';
        }
        $classNameSuffix = $this->config('controller.class_suffix') ? : '';

        $realClassName  = $shortClassName . $classNameSuffix;
        if (strpos($realClassName, '\\') !== 0) {
            $realClassName = $classNamePrefix . $shortClassName . $classNameSuffix;
        }
        return $realClassName;

    }

    public function resource()
    {
        $args = func_get_args();
        $baseUrl = rtrim(array_shift($args), '/');
        $routeNamePrefix = array_pop($args);
        $classAlias = array_pop($args);
        $crudControllerClass = $this->determineClass($classAlias);
        if (!class_exists($crudControllerClass) || !is_subclass_of($crudControllerClass, '\SlimController\CrudApiControllerInterface')) {
            throw new \InvalidArgumentException("Controller class must implement interface \SlimController\CrudApiControllerInterface");
        }

        call_user_func_array(array($this, 'get'), $this->bakeRouteArgs($baseUrl, $args, $classAlias . ':read'))->name($routeNamePrefix . '.read');
        call_user_func_array(array($this, 'get'), $this->bakeRouteArgs($baseUrl . '/:id', $args, $classAlias . ':getOne'))->name($routeNamePrefix . '.get-one');
        call_user_func_array(array($this, 'post'), $this->bakeRouteArgs($baseUrl . '/create', $args, $classAlias . ':create'))->name($routeNamePrefix . '.create');
        call_user_func_array(array($this, 'post'), $this->bakeRouteArgs($baseUrl . '/:id', $args, $classAlias . ':updateOne'))->name($routeNamePrefix . '.update-one');
        call_user_func_array(array($this, 'post'), $this->bakeRouteArgs($baseUrl, $args, $classAlias . ':updateMultiple'))->name($routeNamePrefix . '.update-multiple');
        call_user_func_array(array($this, 'delete'), $this->bakeRouteArgs($baseUrl . '/:id', $args, $classAlias . ':delete'))->name($routeNamePrefix . '.delete');
    }

    protected function bakeRouteArgs($path, $args, $controller)
    {
        array_unshift($args, $path);
        $args[] = $controller;
        return $args;
    }
}
