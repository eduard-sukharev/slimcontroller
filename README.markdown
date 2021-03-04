# SlimController

More convenience over ` fortrabbit/slimcontroller`:

 - Return rendered templates or `Response` objects directly from controller - no need for ugly `$this->app->response->setBody()`
 - Render JsonSerializable objects into valid JSON response via `jsonResponse($myDataObj);`
 - Use `notFound($legacyHandler, false)` method to pass-thru undefined routes handling to your legacy application
 - Pass an implementation of `CrudApiControllerInterface` to a `resource()` method to get a set of CRUD API routes (see below for details)

# Install via composer

Create a `composer.json` file

    {
        "require": {
            "slimcontroller/slimcontroller": "0.5.0"
        },
        "autoload": {
            "psr-0": {
                "MyApp": "src/"
            }
        },
        "repositories": [
            {
                "type": "github",
                "url": "https://github.com/eduard-sukharev/slimcontroller"
            }
        ]
    }

Run installation

    composer.phar install --dev

# Mini HowTo

If you know how [Slim works](http://docs.slimframework.com/), using SlimController shouldn't be a big deal.

## Example Structure

Setup a structure for your controller and templates (just a suggestion, do as you like):

    mkdir -p src/MyApp/Controller templates/home

## Controller

Create your first controller in `src/MyApp/Controller/Home.php`

    <?php

    namespace MyApp\Controller;

    class Home extends \SlimController\SlimController
    {

        public function indexAction()
        {
            $this->render('home/index', array(
                'someVar' => date('c')
            ));
        }

        public function helloAction($name)
        {
            $this->render('home/hello', array(
                'name' => $name
            ));
        }
    }

## Templates

Here are the two corresponding demo templates:

`templates/home/index.php`

    This is the SlimController extension @ <?= $someVar ?>

`templates/home/hello.php`

    Hello <?= $name ?>

## Boostrap index.php

Minimal bootstrap file for this example

    <?php

    // define a working directory
    define('APP_PATH', dirname(__DIR__)); // PHP v5.3+

    // load
    require APP_PATH . '/vendor/autoload.php';

    // init app
    $app = New \SlimController\Slim(array(
        'templates.path'             => APP_PATH . '/templates',
        'controller.class_prefix'    => '\\MyApp\\Controller',
        'controller.method_suffix'   => 'Action',
        'controller.template_suffix' => 'php',
    ));

    $app->addRoutes(array(
        '/'            => 'Home:index',
        '/hello/:name' => 'Home:hello',
    ));

    $app->run();

## Run

    php -S localhost:8080


# Controller

## Configuration

### controller.class_prefix

Optional class prefix for controller classes. Will be prepended to routes.

Using `\\MyApp\\Controller` as prefix with given routes:

    $app->addRoutes(array(
        '/'            => 'Home:index',
        '/hello/:name' => 'Home:hello',
    ));

Translates to

    $app->addRoutes(array(
        '/'            => '\\MyApp\\Controller\\Home:index',
        '/hello/:name' => '\\MyApp\\Controller\\Home:hello',
    ));

### controller.class_suffix

Optional class suffix for controller classes. Will be appended to routes.

Using `Controller` as suffix with given routes:

    $app->addRoutes(array(
        '/'            => 'Home:index',
        '/hello/:name' => 'Home:hello',
    ));

Translates to

    $app->addRoutes(array(
        '/'            => 'HomeController:index',
        '/hello/:name' => 'HomeController:hello',
    ));

### controller.method_suffix

Optional method suffix. Appended to routes.

Using `Action` as suffix with given routes:

    $app->addRoutes(array(
        '/'            => 'Home:index',
        '/hello/:name' => 'Home:hello',
    ));

Translates to

    $app->addRoutes(array(
        '/'            => 'Home:indexAction',
        '/hello/:name' => 'Home:helloAction',
    ));

### controller.template_suffix

Defaults to `twig`. Will be appended to template name given in `render()` method.

## Extended Examples

### Routes

    // how to integrate the Slim middleware
    $app->addRoutes(array(
        '/' => array('Home:index', function() {
                error_log("MIDDLEWARE FOR SINGLE ROUTE");
            },
            function() {
                error_log("ADDITIONAL MIDDLEWARE FOR SINGLE ROUTE");
            }
        ),
        '/hello/:name' => array('post' => array('Home:hello', function() {
                error_log("THIS ROUTE IS ONLY POST");
            }
        ))
    ), function() {
        error_log("APPENDED MIDDLEWARE FOR ALL ROUTES");
    });

### Controller

    <?php

    namespace MyApp\Controller;

    class Sample extends \SlimController\SlimController
    {

        public function indexAction()
        {

            /**
             * Access \SlimController\Slim $app
             */

            $this->app->response()->status(404);


            /**
             * Params
             */

            // reads "?data[foo]=some+value"
            $foo = $this->param('foo');

            // reads "data[bar][name]=some+value" only if POST!
            $bar = $this->param('bar.name', 'post');

            // all params of bar ("object attributes")
            //  "?data[bar][name]=me&data[bar][mood]=happy" only if POST!
            $bar = $this->param('bar');
            //error_log($bar['name']. ' is '. $bar['mood']);

            // reads multiple params in array
            $params = $this->params(array('foo', 'bar.name1', 'bar.name1'));
            //error_log($params['bar.name1']);

            // reads multiple params only if they are POST
            $params = $this->params(array('foo', 'bar.name1', 'bar.name1'), 'post');

            // reads multiple params only if they are POST and all are given!
            $params = $this->params(array('foo', 'bar.name1', 'bar.name1'), 'post', true);
            if (!$params) {
                error_log("Not all params given.. maybe some. Don't care");
            }

            // reads multiple params only if they are POST and replaces non given with defaults!
            $params = $this->params(array('foo', 'bar.name1', 'bar.name1'), 'post', array(
                'foo' => 'Some Default'
            ));


            /**
             * Redirect shortcut
             */

            if (false) {
                $this->redirect('/somewhere');
            }


            /**
             * Rendering
             */

            $this->render('folder/file', array(
                'foo' => 'bar'
            ));

        }
    }

## CRUD API Example

Define CRUD resource with `resource()` method. First argument is always a base path. Last argument is always a route
name prefix (for using with `urlFor()` method). Second to last argument is FQCN of your 
`\SlimController\CrudApiControllerInterface` implementation. The rest is passed as route middlewares.
Generated CRUD API routes pass managed entity id to controller actions by the name `$id`, so be careful with your
implementation.

### Controller

Sample controller implementation using Predis package for accesing Redis store:

```php
    namespace MyApp\Controller;

    class UsersController extends \SlimController\SlimController implements \SlimController\CrudApiControllerInterface
    {

        public function readAction()
        {
            $client = new Predis\Client();
            return $this->jsonResponse($client->get('users:*'));
        }
    
        /**
         * @param string|int $id This parameter name is important!
         */
        public function getOneAction($id)
        {
            $client = new Predis\Client();
            return $this->jsonResponse($client->get('users:'.$id));
        }
    
        public function createAction()
        {
            $user = json_decode($this->app->request->getBody(), true);
            $user['id'] = uuid4();
            $client = new Predis\Client();
            $client->set('users:'.$user['id'], $user);
            
            return $this->jsonResponse($user, 201);
        }
    
        /**
         * @param string|int $id This parameter name is important!
         */
        public function updateOneAction($id)
        {
            $user = json_decode($this->app->request->getBody(), true);
            $client = new Predis\Client();
            $client->set('users:'.$user['id'], $user);
            
            return $this->jsonResponse($user, 200);
        }
    
        public function updateMultipleAction()
        {
            $users = json_decode($this->app->request->getBody(), true);
            $client = new Predis\Client();
            foreach ($users as $user) {
                $client->set('users:'.$user['id'], $user);
            }
            
            return $this->jsonResponse($users, 200);
        }
    
        /**
         * @param string|int $id This parameter name is important!
         */
        public function deleteAction($id)
        {
            $client = new Predis\Client();
            $user = $client->get('users:'.$id);
            $client->del('users:'.$id);
            
            return $this->jsonResponse($user, 200);
        }
    }
```

### Routes
When defining routing add single line:
```php
    $app->resource('/api/users', new AuthRequiredMiddleware(), '\MyApp\Controller\UsersController', 'api.users');
```

What you actually get is a following set of routes available:

 - `GET /api/users` - request all users, route name 'api.users.read'
 - `GET /api/users/8f3f1e68-0fff-4e2c-8884-df7a60f9bd09` - create a user, route name 'api.users.get-one'
 - `POST /api/users/create` - create a user, route name 'api.users.create'
 - `POST /api/users/8f3f1e68-0fff-4e2c-8884-df7a60f9bd09` - update a user, route name 'api.users.update-one'
 - `POST /api/users` - update a user, route name 'api.users.update-multiple'
 - `DELETE /api/users/8f3f1e68-0fff-4e2c-8884-df7a60f9bd09` - delete a user, route name 'api.users.delete'
