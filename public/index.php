<?php

declare(strict_types=1);

use Building\Domain\Aggregate\Building;
use Building\Domain\Command;
use Building\Domain\DomainEvent;
use Building\Domain\Repository\BuildingRepositoryInterface;
use Building\Infrastructure\CommandHandler;
use Building\Infrastructure\CommandHandler\RegisterNewBuildingHandler;
use Building\Infrastructure\Repository\BuildingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDOSqlite\Driver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\SchemaException;
use Interop\Container\ContainerInterface;
use Prooph\Common\Event\ActionEvent;
use Prooph\Common\Event\ActionEventEmitter;
use Prooph\Common\Event\ActionEventListenerAggregate;
use Prooph\Common\Event\ProophActionEventEmitter;
use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\Common\Messaging\NoOpMessageConverter;
use Prooph\EventSourcing\EventStoreIntegration\AggregateTranslator;
use Prooph\EventStore\Adapter\Doctrine\DoctrineEventStoreAdapter;
use Prooph\EventStore\Adapter\Doctrine\Schema\EventStoreSchema;
use Prooph\EventStore\Adapter\PayloadSerializer\JsonPayloadSerializer;
use Prooph\EventStore\Aggregate\AggregateRepository;
use Prooph\EventStore\Aggregate\AggregateType;
use Prooph\EventStore\EventStore;
use Prooph\EventStoreBusBridge\EventPublisher;
use Prooph\ServiceBus\CommandBus;
use Prooph\ServiceBus\EventBus;
use Prooph\ServiceBus\MessageBus;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Rhumsaa\Uuid\Uuid;
use Zend\Expressive\AppFactory;
use Zend\ServiceManager\ServiceManager;

call_user_func(function () {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');

    require_once __DIR__ . '/../vendor/autoload.php';

    $sm = new ServiceManager([
        'factories' => [
            Connection::class => function () {
                $connection = DriverManager::getConnection([
                    'driverClass' => Driver::class,
                    'path'        => __DIR__ . '/../data/db.sqlite3',
                ]);

                try {
                    $schema = $connection->getSchemaManager()->createSchema();

                    EventStoreSchema::createSingleStream($schema, 'event_stream', true);

                    foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
                        $connection->exec($sql);
                    }
                } catch (SchemaException $ignored) {
                }

                return $connection;
            },

            EventStore::class                  => function (ContainerInterface $container) {
                $eventBus   = new EventBus();
                $eventStore = new EventStore(
                    new DoctrineEventStoreAdapter(
                        $container->get(Connection::class),
                        new FQCNMessageFactory(),
                        new NoOpMessageConverter(),
                        new JsonPayloadSerializer()
                    ),
                    new ProophActionEventEmitter()
                );

                $eventBus->utilize(new class ($container, $container) implements ActionEventListenerAggregate
                {
                    /**
                     * @var ContainerInterface
                     */
                    private $eventHandlers;

                    /**
                     * @var ContainerInterface
                     */
                    private $projectors;

                    public function __construct(
                        ContainerInterface $eventHandlers,
                        ContainerInterface $projectors
                    ) {
                        $this->eventHandlers = $eventHandlers;
                        $this->projectors    = $projectors;
                    }

                    public function attach(ActionEventEmitter $dispatcher)
                    {
                        $dispatcher->attachListener(MessageBus::EVENT_ROUTE, [$this, 'onRoute']);
                    }

                    public function detach(ActionEventEmitter $dispatcher)
                    {
                        throw new \BadMethodCallException('Not implemented');
                    }

                    public function onRoute(ActionEvent $actionEvent)
                    {
                        $messageName = (string) $actionEvent->getParam(MessageBus::EVENT_PARAM_MESSAGE_NAME);

                        $handlers = [];

                        $listeners  = $messageName . '-listeners';
                        $projectors = $messageName . '-projectors';

                        if ($this->projectors->has($projectors)) {
                            $handlers = array_merge($handlers, $this->eventHandlers->get($projectors));
                        }

                        if ($this->eventHandlers->has($listeners)) {
                            $handlers = array_merge($handlers, $this->eventHandlers->get($listeners));
                        }

                        if ($handlers) {
                            $actionEvent->setParam(EventBus::EVENT_PARAM_EVENT_LISTENERS, $handlers);
                        }
                    }
                });

                (new EventPublisher($eventBus))->setUp($eventStore);

                return $eventStore;
            },

            CommandBus::class                  => function (ContainerInterface $container) : CommandBus {
                $commandBus = new CommandBus();

                $commandBus->utilize(new \Prooph\ServiceBus\Plugin\ServiceLocatorPlugin($container));
                $commandBus->utilize(new class implements ActionEventListenerAggregate {
                    public function attach(ActionEventEmitter $dispatcher)
                    {
                        $dispatcher->attachListener(MessageBus::EVENT_ROUTE, [$this, 'onRoute']);
                    }

                    public function detach(ActionEventEmitter $dispatcher)
                    {
                        throw new \BadMethodCallException('Not implemented');
                    }

                    public function onRoute(ActionEvent $actionEvent)
                    {
                        $actionEvent->setParam(
                            MessageBus::EVENT_PARAM_MESSAGE_HANDLER,
                            (string) $actionEvent->getParam(MessageBus::EVENT_PARAM_MESSAGE_NAME)
                        );
                    }
                });

                $transactionManager = new \Prooph\EventStoreBusBridge\TransactionManager();
                $transactionManager->setUp($container->get(EventStore::class));

                $commandBus->utilize($transactionManager);

                return $commandBus;
            },

            // Command -> CommandHandlerFactory
            Command\RegisterNewBuilding::class => function (ContainerInterface $container) : RegisterNewBuildingHandler {
                return new RegisterNewBuildingHandler($container->get(BuildingRepositoryInterface::class));
            },
            // Command -> Check user into Building
            Command\CheckUserIntoBuilding::class => function (ContainerInterface $container) : callable {
                $repo = $container->get(BuildingRepositoryInterface::class);
                
                return function (Command\CheckUserIntoBuilding $checkIn) use ($repo) {
                    $building = $repo->get(Uuid::fromString($checkIn->buildingId()));
                    $building->checkInUser($checkIn->username());

                    $repo->add($building);
                };
            },

            // Command -> Check user out of Building
            Command\CheckUserOutOfBuilding::class => function (ContainerInterface $container) : callable {
                  $repo = $container->get(BuildingRepositoryInterface::class);
    
                  return function (Command\CheckUserOutOfBuilding $checkOut) use ($repo) {
                      $building = $repo->get(Uuid::fromString($checkOut->buildingId()));
                      $building->checkOutUser($checkOut->username());

                      $repo->add($building);
                  };
          },



          BuildingRepositoryInterface::class => function (ContainerInterface $container) : BuildingRepositoryInterface {
                return new BuildingRepository(
                    new AggregateRepository(
                        $container->get(EventStore::class),
                        AggregateType::fromAggregateRootClass(Building::class),
                        new AggregateTranslator()
                    )
                );
            },


            DomainEvent\UserCheckedIntoBuilding::class . '-projectors' => function(ContainerInterface $container) {
                return [
                    function (DomainEvent\UserCheckedIntoBuilding $event) {
                        $path = __DIR__ . '/' . $event->aggregateId() . '.json';

                        if(file_exists($path)) {
                            $existingUsers = json_decode(file_get_contents($path), true);
                        }

                        $existingUsers[] = $event->username();

                        file_put_contents($path, json_encode(array_values($existingUsers)));
                    }
                ];
            }

        ],
    ]);

    $app = AppFactory::create($sm);

    $app->get('/', function (Request $request, Response $response) {
        ob_start();
        require __DIR__ . '/../template/index.php';
        $content = ob_get_clean();

        return $response->getBody()->write($content);
    });

    $app->post('/register-new-building', function (Request $request, Response $response) use ($sm) {
        $commandBus = $sm->get(CommandBus::class);
        $commandBus->dispatch(Command\RegisterNewBuilding::fromName($request->getParsedBody()['name']));

        return $response->withAddedHeader('Location', '/');
    });

    $app->get('/building/{buildingId}', function (Request $request, Response $response) {
        $buildingId = Uuid::fromString($request->getAttribute('buildingId'));

        ob_start();
        require __DIR__ . '/../template/building.php';
        $content = ob_get_clean();

        return $response->getBody()->write($content);
    });

    $app->post('/checkin/{buildingId}', function (Request $request, Response $response) use ($sm) {
        $commandBus = $sm->get(CommandBus::class);
        $commandBus->dispatch(Command\CheckUserIntoBuilding::fromUsernameAndBuildingId(
          $request->getParsedBody()['username'],
          $request->getAttribute('buildingId')
        ));

        return $response->withAddedHeader('Location', '/building/' . $request->getAttribute('buildingId'));
    });

    $app->post('/checkout/{buildingId}', function (Request $request, Response $response) use ($sm) {
        $commandBus = $sm->get(CommandBus::class);
        $commandBus->dispatch(Command\CheckUserOutOfBuilding::fromUsernameAndBuildingId(
          $request->getParsedBody()['username'],
          $request->getAttribute('buildingId')
        ));

        return $response->withAddedHeader('Location', '/building/' . $request->getAttribute('buildingId'));
    });

    $app->run();
});