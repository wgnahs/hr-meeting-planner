<?php

require __DIR__.'/../vendor/autoload.php';

$app = new \Silex\Application();

$app->register(new Knp\Provider\ConsoleServiceProvider(), [
    'console.name'              => 'HR Meeting planner',
    'console.version'           => '1.0.0',
    'console.project_directory' => __DIR__.'/../'
]);

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/views',
));

$app->register(new Silex\Provider\SwiftmailerServiceProvider());

$app['swiftmailer.use_spool'] = false;
$app['swiftmailer.options'] = array(
    'host' => 'xxxxx',
    'port' => 465,
    'username' => 'xxxxx',
    'password' => 'xxxxx',
    'transport' => 'smtp',
    'encryption' => 'ssl',
    'auth_mode' => null
);

return $app;

?>